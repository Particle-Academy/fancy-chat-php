<?php

declare(strict_types=1);

use FancyChat\Tests\Support\ScriptedBackend;
use FancyChat\Backend\TurnInput;
use FancyChat\Events\ChatTurnParking;
use FancyChat\Events\ChatTurnSettled;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * An approval (or question) a backend raises is NOT actionable until its turn has
 * parked: the server buffers it and publishes it, with the park, once the backend's
 * stream is exhausted (for Harness: after the run, and the host's recorders, have
 * persisted it). A host listener on ChatTurnParking runs first, so evidence it
 * records exists before any human can see the approval. Reported by impactium:
 * the card was decidable before TurnRecorder had saved the quote it cites.
 *
 * A decision that lands while its turn still runs (an approval the HOST appended
 * itself) is recorded, and the turn resumes on its own the moment it parks: queued,
 * never lost.
 */

function approvalRecordFor(string $id): array
{
    return ['approvalId' => $id, 'title' => 'Store the fact?', 'options' => [['id' => 'yes', 'kind' => 'allow-once', 'label' => 'Yes'], ['id' => 'no', 'kind' => 'reject-once', 'label' => 'No']]];
}

it('buffers a backend\'s approval until the turn parks: nobody can see or decide it while the turn runs', function (string $store): void {
    chatApp(['fancy-chat.store' => $store]);
    $seen = [];
    bindHost()->backend(new ScriptedBackend(function (TurnInput $input) use (&$seen): iterable {
        if ($input->kind === 'resume') {
            return;
        }
        yield ['type' => 'approval.requested', 'data' => ['approval' => approvalRecordFor('ap_1')]];
        // Still inside the run: the approval must not be published yet.
        $seen['located'] = chat()->store()->locate('ap_1');
        $seen['visible'] = count(chat()->snapshot($input->threadId)['approvals']);
        $seen['decide'] = http('POST', '/fancy-chat/approvals/ap_1/decision', ['optionId' => 'yes'], 'human:u_1')['status'];
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('learn it'), 'human:u_1');

    expect($seen)->toBe(['located' => null, 'visible' => 0, 'decide' => 404]);
    $state = chat()->snapshot('t_1');
    expect($state['turns'][0]['state'])->toBe('awaiting-human')
        ->and($state['approvals'][0]['approvalId'])->toBe('ap_1')
        ->and($state['approvals'][0]['state'])->toBe('pending');
    // Published, then parked: in that order in the log.
    $log = chat()->store()->after('t_1', 0, 100);
    $at = fn (callable $match): int => (int) array_key_first(array_filter($log, $match));
    expect($at(fn (array $e): bool => $e['type'] === 'approval.requested'))
        ->toBeLessThan($at(fn (array $e): bool => $e['type'] === 'turn.transition' && ($e['data']['action'] ?? null) === 'park'));
})->with('stores');

it('ChatTurnParking runs BEFORE the approval is indexed, so evidence a host records exists first (impactium\'s DB::listen check)', function (): void {
    chatApp(['fancy-chat.store' => 'database']);
    $evidence = false;
    $evidenceAtInsert = null;
    Event::listen(ChatTurnParking::class, function (ChatTurnParking $e) use (&$evidence): void {
        expect($e->threadId)->toBe('t_1')->and($e->hitlIds)->toBe(['ap_1']);
        $evidence = true;
    });
    DB::listen(function ($query) use (&$evidence, &$evidenceAtInsert): void {
        if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'fancy_chat_hitl')) {
            $evidenceAtInsert ??= $evidence;
        }
    });
    bindHost()->backend(new ScriptedBackend(function (TurnInput $input): iterable {
        yield ['type' => 'approval.requested', 'data' => ['approval' => approvalRecordFor('ap_1')]];
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('learn it'), 'human:u_1');
    expect($evidenceAtInsert)->toBeTrue();
});

it('a question is buffered the same way', function (): void {
    chatApp();
    $located = 'unset';
    bindHost()->backend(new ScriptedBackend(function (TurnInput $input) use (&$located): iterable {
        yield ['type' => 'question.asked', 'data' => ['question' => ['questionId' => 'q_1', 'prompt' => 'Which?', 'fields' => [['id' => 'f', 'type' => 'text', 'required' => true]]]]];
        $located = chat()->store()->locate('q_1');
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('ask me'), 'human:u_1');
    expect($located)->toBeNull()->and(chat()->snapshot('t_1')['questions'][0]['state'])->toBe('open');
});

it('a turn that settles without parking never publishes its buffered approval', function (): void {
    chatApp();
    bindHost()->backend(new ScriptedBackend(function (TurnInput $input): iterable {
        yield ['type' => 'approval.requested', 'data' => ['approval' => approvalRecordFor('ap_1')]];
    }));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('x'), 'human:u_1');
    $state = chat()->snapshot('t_1');
    expect($state['approvals'])->toBe([])
        ->and(chat()->store()->locate('ap_1'))->toBeNull()
        ->and($state['turns'][0]['outcome'])->toBe('completed');
    $refused = collect(chat()->store()->after('t_1', 0, 100))->firstWhere('type', 'x-fancy-chat.refused');
    expect($refused['data'])->toBe(['type' => 'approval.requested', 'error' => 'approval.unparked']);
});

it('a decision recorded while its turn still runs is queued: the turn resumes by itself once it parks', function (): void {
    chatApp();
    $inputs = [];
    bindHost()->backend(new ScriptedBackend(function (TurnInput $input) use (&$inputs): iterable {
        $inputs[] = $input;
        if ($input->kind === 'resume') {
            yield ['type' => 'message.created', 'data' => ['message' => ['id' => 'done', 'role' => 'assistant', 'parts' => [['id' => 'p', 'kind' => 'text', 'format' => 'plain', 'text' => 'Done.']]]]];

            return;
        }
        // The HOST raises an approval on the running turn, and a human decides it at once.
        chat()->append($input->threadId, ['type' => 'approval.requested', 'turnId' => $input->turnId, 'data' => ['approval' => approvalRecordFor('ap_h')]]);
        expect(http('POST', '/fancy-chat/approvals/ap_h/decision', ['optionId' => 'yes'], 'human:u_1')['status'])->toBe(200);
        expect(chat()->snapshot($input->threadId)['turns'][0]['state'])->toBe('running');
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('go'), 'human:u_1');

    expect($inputs)->toHaveCount(2)
        ->and($inputs[1]->kind)->toBe('resume')
        ->and($inputs[1]->approval['approvalId'])->toBe('ap_h')
        ->and(array_column($inputs[1]->approvals, 'approvalId'))->toBe(['ap_h']);
    expect(chat()->snapshot('t_1')['turns'][0])->toMatchArray(['state' => 'settled', 'outcome' => 'completed']);
});

it('parking with an approval still pending does not resume', function (): void {
    chatApp();
    $inputs = [];
    [$approvalId] = parkedApproval($inputs);
    expect($inputs)->toHaveCount(1)->and(chat()->snapshot('t_1')['turns'][0]['state'])->toBe('awaiting-human');
});

it('ChatTurnSettled fires once per settled turn, with its outcome and reason, after commit', function (): void {
    chatApp();
    $seen = [];
    Event::listen(ChatTurnSettled::class, function (ChatTurnSettled $e) use (&$seen): void {
        $seen[] = [$e->threadId, $e->outcome, $e->reason, $e->succeeded()];
    });
    bindHost()->backend(replyBackend());
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('one'), 'human:u_1');
    expect($seen)->toHaveCount(1)->and($seen[0])->toBe(['t_1', 'completed', null, true]);

    bindHost()->backend(new ScriptedBackend(fn (TurnInput $i): iterable => [['type' => 'turn.settled', 'data' => ['action' => 'fail', 'reason' => 'output_limit']]]));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('two'), 'human:u_1');
    expect($seen)->toHaveCount(2)->and($seen[1])->toBe(['t_1', 'failed', 'output_limit', false]);
    expect(new ChatTurnSettled('t', 'u', 'completed', null))->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});

it('ChatTurnSettled is not fired for a park', function (): void {
    chatApp();
    $count = 0;
    Event::listen(ChatTurnSettled::class, function () use (&$count): void {
        $count++;
    });
    $inputs = [];
    parkedApproval($inputs);
    expect($count)->toBe(0);
});

it('a backend that parks again after a resume, with nothing new decided, waits: no resume loop', function (): void {
    chatApp();
    $starts = 0;
    bindHost()->backend(new ScriptedBackend(function (TurnInput $input) use (&$starts): iterable {
        if (++$starts > 5) {
            throw new RuntimeException('resume loop');
        }
        if ($input->kind === 'message') {
            yield ['type' => 'approval.requested', 'data' => ['approval' => approvalRecordFor('ap_1')]];
        }
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('go'), 'human:u_1');
    expect(http('POST', '/fancy-chat/approvals/ap_1/decision', ['optionId' => 'yes'], 'human:u_1')['status'])->toBe(200);
    // Resumed once by the decision; it parked again with nothing new, so it waits.
    expect($starts)->toBe(2)->and(chat()->snapshot('t_1')['turns'][0]['state'])->toBe('awaiting-human');
});

it('ChatTurnSettled fires once per turn even if a settle is appended twice', function (): void {
    chatApp();
    $seen = [];
    Event::listen(ChatTurnSettled::class, function (ChatTurnSettled $e) use (&$seen): void {
        $seen[] = $e->outcome;
    });
    bindHost();
    chat()->append('t_1', ['type' => 'turn.started', 'data' => ['turnId' => 'u_1']]);
    chat()->append('t_1', ['type' => 'turn.transition', 'data' => ['turnId' => 'u_1', 'action' => 'start']]);
    chat()->append('t_1', ['type' => 'turn.settled', 'data' => ['turnId' => 'u_1', 'action' => 'finish']]);
    chat()->append('t_1', ['type' => 'turn.settled', 'data' => ['turnId' => 'u_1', 'action' => 'fail', 'reason' => 'late']]);
    expect($seen)->toBe(['completed']);
});
