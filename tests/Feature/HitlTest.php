<?php

declare(strict_types=1);

use FancyChat\Backend\NullBackend;
use FancyChat\Backend\TurnInput;
use FancyChat\ChatException;
use FancyChat\Contract\Projection;
use FancyChat\StagedWrite;

/** A staged write that counts its applies. */
final class CountingWrite implements StagedWrite
{
    /** @var list<array{approval: array<string, mixed>, decision: string, edited: mixed}> */
    public array $applied = [];

    public function __construct(private bool $succeed = true) {}

    public function preview(array $proposal): array
    {
        return [['id' => 'pv', 'kind' => 'text', 'format' => 'plain', 'text' => 'Send to '.($proposal['to'] ?? '?')]];
    }

    public function apply(array $approval, string $decision, mixed $edited = null): array
    {
        $this->applied[] = ['approval' => $approval, 'decision' => $decision, 'edited' => $edited];

        return $this->succeed ? ['ok' => true] : ['ok' => false, 'error' => 'smtp.down'];
    }
}

/**
 * A backend that asks for approval and parks, then finishes on resume. It
 * records every input it was started with.
 */
function approvalBackend(array &$inputs, string $stagedWrite = 'send-email'): NullBackend
{
    return new NullBackend(function (TurnInput $input) use (&$inputs, $stagedWrite): iterable {
        $inputs[] = $input;
        if ($input->kind === 'resume') {
            yield ['type' => 'message.created', 'data' => ['message' => ['id' => 'done_'.$input->turnId, 'role' => 'assistant', 'parts' => [['id' => 'p', 'kind' => 'text', 'format' => 'plain', 'text' => 'Done.']]]]];

            return;
        }
        yield ['type' => 'approval.requested', 'data' => ['approval' => [
            'approvalId' => 'ap_'.$input->turnId,
            'title' => 'Send the email?',
            'summary' => 'To 3 recipients',
            'stagedWrite' => $stagedWrite,
            'proposal' => ['to' => 'team'],
            'options' => [['id' => 'yes', 'kind' => 'allow-once', 'label' => 'Send'], ['id' => 'no', 'kind' => 'reject-once', 'label' => 'Cancel'], ['id' => 'edit', 'kind' => 'edit', 'label' => 'Edit']],
        ]]];
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }, ['steering' => false, 'cancel' => true, 'approvals' => true]);
}

/** Start a parked turn and return [approvalId, turnId]. */
function parkedApproval(array &$inputs, ?CountingWrite $write = null): array
{
    bindHost()->backend(approvalBackend($inputs))->stagedWrite('send-email', $write ?? new CountingWrite);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('email the team'), 'human:u_1');
    $state = chat()->snapshot('t_1');
    expect($state['turns'][0]['state'])->toBe('awaiting-human');

    return [$state['approvals'][0]['approvalId'], $state['turns'][0]['turnId']];
}

it('parks on an approval, records a human decision, applies the staged write once, then resumes', function (string $store): void {
    chatApp(['fancy-chat.store' => $store]);
    $inputs = [];
    $write = new CountingWrite;
    [$approvalId, $turnId] = parkedApproval($inputs, $write);

    $state = chat()->snapshot('t_1');
    expect($state['approvals'][0]['preview'])->toBe([['id' => 'pv', 'kind' => 'text', 'format' => 'plain', 'text' => 'Send to team']])
        ->and($state['approvals'][0]['turnId'])->toBe($turnId);

    $r = http('POST', "/fancy-chat/approvals/{$approvalId}/decision", ['optionId' => 'yes', 'note' => 'go'], 'human:u_1');
    expect($r['status'])->toBe(200)->and($r['body']['approval']['state'])->toBe('applied');
    expect($write->applied)->toHaveCount(1)->and($write->applied[0]['decision'])->toBe('approve');

    $state = chat()->snapshot('t_1');
    expect($state['approvals'][0]['decidedBy'])->toBe(['kind' => 'human', 'id' => 'u_1'])
        ->and($state['approvals'][0]['note'])->toBe('go')
        ->and($state['turns'][0]['state'])->toBe('settled')
        ->and($state['turns'][0]['outcome'])->toBe('completed');
    expect($inputs)->toHaveCount(2)
        ->and($inputs[1]->kind)->toBe('resume')
        ->and($inputs[1]->approval['approvalId'])->toBe($approvalId)
        ->and($inputs[1]->approval['state'])->toBe('applied');
    $types = array_column(chat()->store()->after('t_1', 0, 1000), 'type');
    $decided = array_search('approval.decided', $types, true);
    expect($types[$decided + 1])->toBe('approval.applied');
})->with('stores');

it('refuses a decision from an agent actor, and never lets the body choose the actor', function (): void {
    chatApp();
    $inputs = [];
    $write = new CountingWrite;
    [$approvalId] = parkedApproval($inputs, $write);

    $agent = http('POST', "/fancy-chat/approvals/{$approvalId}/decision", ['optionId' => 'yes'], 'agent:compass');
    expect($agent['status'])->toBe(403)->and($agent['body'])->toBe(['error' => 'chat.actor.human']);
    $system = http('POST', "/fancy-chat/approvals/{$approvalId}/decision", ['optionId' => 'yes'], 'system:server');
    expect($system['status'])->toBe(403);
    expect(chat()->snapshot('t_1')['approvals'][0]['state'])->toBe('pending')->and($write->applied)->toBe([]);

    $forged = http('POST', "/fancy-chat/approvals/{$approvalId}/decision", ['optionId' => 'yes', 'actor' => ['kind' => 'human', 'id' => 'boss'], 'decidedBy' => ['kind' => 'human', 'id' => 'boss']], 'human:u_2');
    expect($forged['status'])->toBe(200);
    expect(chat()->snapshot('t_1')['approvals'][0]['decidedBy'])->toBe(['kind' => 'human', 'id' => 'u_2']);
});

it('rejects without applying, and takes the decision from the option kind not its label', function (): void {
    chatApp();
    $inputs = [];
    $write = new CountingWrite;
    [$approvalId] = parkedApproval($inputs, $write);
    $r = http('POST', "/fancy-chat/approvals/{$approvalId}/decision", ['optionId' => 'no'], 'human:u_1');
    expect($r['body']['approval']['state'])->toBe('rejected')->and($write->applied)->toBe([]);
    expect(array_column(chat()->store()->after('t_1', 0, 1000), 'type'))->not->toContain('approval.applied');
});

it('passes an edit to the staged write and records apply failures', function (): void {
    chatApp();
    $inputs = [];
    $write = new CountingWrite(false);
    [$approvalId] = parkedApproval($inputs, $write);
    $r = http('POST', "/fancy-chat/approvals/{$approvalId}/decision", ['optionId' => 'edit', 'edited' => ['to' => 'me']], 'human:u_1');
    expect($r['body']['approval']['state'])->toBe('apply-failed')
        ->and($r['body']['approval']['error'])->toBe('smtp.down')
        ->and($write->applied[0]['edited'])->toBe(['to' => 'me']);
});

it('refuses an unknown option, an unknown approval and a second decision', function (): void {
    chatApp();
    $inputs = [];
    [$approvalId] = parkedApproval($inputs);
    expect(http('POST', "/fancy-chat/approvals/{$approvalId}/decision", ['optionId' => 'maybe'], 'human:u_1')['body'])->toBe(['error' => 'approval.option']);
    expect(http('POST', '/fancy-chat/approvals/ap_nope/decision', ['optionId' => 'yes'], 'human:u_1')['status'])->toBe(404);
    expect(http('POST', "/fancy-chat/approvals/{$approvalId}/decision", ['optionId' => 'no'], 'human:u_1')['status'])->toBe(200);
    $again = http('POST', "/fancy-chat/approvals/{$approvalId}/decision", ['optionId' => 'yes'], 'human:u_1');
    expect($again['status'])->toBe(409)->and($again['body'])->toBe(['error' => 'approval.state']);
});

it('holds an in-flight lock so a concurrent decision cannot land twice', function (string $store): void {
    chatApp(['fancy-chat.store' => $store]);
    $inputs = [];
    [$approvalId] = parkedApproval($inputs);
    $threadId = chat()->store()->locate($approvalId);
    expect(chat()->store()->claim($approvalId, 'pending', 'deciding'))->toBeTrue()
        ->and(chat()->store()->claim($approvalId, 'pending', 'deciding'))->toBeFalse();
    $r = http('POST', "/fancy-chat/approvals/{$approvalId}/decision", ['optionId' => 'yes'], 'human:u_1');
    expect($r['status'])->toBe(409)->and($r['body'])->toBe(['error' => 'approval.in-flight'])->and($threadId)->toBe('t_1');
})->with('stores');

it('never runs apply without a human decision record', function (): void {
    chatApp();
    $inputs = [];
    $write = new CountingWrite;
    [$approvalId] = parkedApproval($inputs, $write);
    expect(fn () => chat()->applyStagedWrite('t_1', $approvalId))->toThrow(ChatException::class, 'approval.undecided');
    chat()->append('t_1', ['type' => 'approval.decided', 'actor' => ['kind' => 'agent', 'id' => 'compass'], 'data' => ['approvalId' => $approvalId, 'decision' => 'approve', 'decidedBy' => ['kind' => 'agent', 'id' => 'compass']]]);
    expect(fn () => chat()->applyStagedWrite('t_1', $approvalId))->toThrow(ChatException::class, 'approval.undecided');
    expect($write->applied)->toBe([]);
});

it('withdraws a parked approval when the turn is stopped', function (): void {
    chatApp();
    $inputs = [];
    [$approvalId, $turnId] = parkedApproval($inputs);
    $r = http('POST', '/fancy-chat/threads/t_1/cancel', ['mode' => 'stop'], 'human:u_1');
    expect($r['body'])->toBe(['outcome' => 'ok']);
    $state = chat()->snapshot('t_1');
    expect($state['approvals'][0]['state'])->toBe('withdrawn')
        ->and($state['turns'][0])->toBe(['turnId' => $turnId, 'state' => 'settled', 'outcome' => 'cancelled', 'reason' => null]);
    expect(http('POST', "/fancy-chat/approvals/{$approvalId}/decision", ['optionId' => 'yes'], 'human:u_1')['status'])->toBe(409);
});

it('answers a question only from a human with a complete answer, then resumes', function (): void {
    chatApp();
    $inputs = [];
    bindHost()->backend(new NullBackend(function (TurnInput $input) use (&$inputs): iterable {
        $inputs[] = $input;
        if ($input->kind === 'resume') {
            return;
        }
        yield ['type' => 'question.asked', 'data' => ['question' => ['questionId' => 'q_1', 'prompt' => 'When?', 'fields' => [['id' => 'when', 'type' => 'single', 'options' => [['id' => 'now', 'label' => 'Now']], 'required' => true]], 'allowNote' => false]]];
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('ship it'), 'human:u_1');

    expect(http('POST', '/fancy-chat/questions/q_1/answer', ['values' => ['when' => 'now']], 'agent:compass')['status'])->toBe(403);
    expect(http('POST', '/fancy-chat/questions/q_1/answer', ['values' => []], 'human:u_1')['body'])->toBe(['error' => 'question.required']);
    expect(http('POST', '/fancy-chat/questions/q_1/answer', ['values' => ['when' => 'later']], 'human:u_1')['body'])->toBe(['error' => 'question.option']);
    $ok = http('POST', '/fancy-chat/questions/q_1/answer', ['values' => ['when' => 'now'], 'answeredBy' => ['kind' => 'human', 'id' => 'boss']], 'human:u_1');
    expect($ok['status'])->toBe(200);

    $state = chat()->snapshot('t_1');
    expect($state['questions'][0]['state'])->toBe('answered')
        ->and($state['questions'][0]['answeredBy'])->toBe(['kind' => 'human', 'id' => 'u_1'])
        ->and($state['turns'][0]['state'])->toBe('settled');
    expect($inputs[1]->kind)->toBe('resume')->and($inputs[1]->question['questionId'])->toBe('q_1');
    expect(http('POST', '/fancy-chat/questions/q_1/answer', ['values' => ['when' => 'now']], 'human:u_1')['status'])->toBe(409);

    $raw = http('GET', '/fancy-chat/threads/t_1/snapshot', null, 'human:u_1')['raw'];
    expect($raw)->toContain('"notes":{}');
});

it('queues a message sent mid-turn, delivers it when the turn settles, and lets its author unsend it', function (): void {
    chatApp();
    $inputs = [];
    [$approvalId] = parkedApproval($inputs);

    $queued = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('also cc me'), 'human:u_1');
    expect($queued['status'])->toBe(201)->and($queued['body']['delivery'])->toBe('queued');
    $other = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('and me'), 'human:u_1');

    expect(http('DELETE', "/fancy-chat/threads/t_1/queue/{$other['body']['messageId']}", null, 'human:u_2')['body'])->toBe(['error' => 'message.author']);
    $unsent = http('DELETE', "/fancy-chat/threads/t_1/queue/{$other['body']['messageId']}", null, 'human:u_1');
    expect($unsent['status'])->toBe(200)->and($unsent['body']['delivery'])->toBe('unsent');

    http('POST', "/fancy-chat/approvals/{$approvalId}/decision", ['optionId' => 'yes'], 'human:u_1');
    $state = chat()->snapshot('t_1');
    $deliveries = array_column($state['messages'], 'delivery', 'id');
    expect($deliveries[$queued['body']['messageId']])->toBe('delivered')
        ->and($deliveries[$other['body']['messageId']])->toBe('unsent')
        ->and($state['turns'])->toHaveCount(2);

    $again = http('DELETE', "/fancy-chat/threads/t_1/queue/{$queued['body']['messageId']}", null, 'human:u_1');
    expect($again['status'])->toBe(409)->and($again['body'])->toBe(['error' => 'delivery.illegal']);
    expect(http('DELETE', '/fancy-chat/threads/t_1/queue/m_nope', null, 'human:u_1')['status'])->toBe(404);
});

it('caps the queue at the configured length', function (): void {
    chatApp(['fancy-chat.limits.queue_length' => 1]);
    $inputs = [];
    parkedApproval($inputs);
    expect(http('POST', '/fancy-chat/threads/t_1/messages', textMessage('one'), 'human:u_1')['status'])->toBe(201);
    $full = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('two'), 'human:u_1');
    expect($full['status'])->toBe(429)->and($full['body'])->toBe(['error' => 'queue.full']);
});

it('reports every cancel outcome', function (): void {
    chatApp();
    $backend = new NullBackend(fn (): iterable => [], ['steering' => false, 'cancel' => true, 'approvals' => false], 'uncertain');
    bindHost()->backend($backend);
    expect(http('POST', '/fancy-chat/threads/t_1/cancel', ['mode' => 'stop'], 'human:u_1')['body'])->toBe(['outcome' => 'no-turn']);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');
    expect(http('POST', '/fancy-chat/threads/t_1/cancel', ['mode' => 'stop'], 'human:u_1')['body'])->toBe(['outcome' => 'not-running']);
    expect(http('POST', '/fancy-chat/threads/t_1/cancel', ['mode' => 'sideways'], 'human:u_1')['status'])->toBe(422);

    chatApp();
    bindHost()->backend(new NullBackend(function () {
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }, ['steering' => false, 'cancel' => true, 'approvals' => false], 'uncertain'));
    http('POST', '/fancy-chat/threads/t_2/messages', textMessage('hi'), 'human:u_1');
    expect(http('POST', '/fancy-chat/threads/t_2/cancel', ['mode' => 'interrupt'], 'human:u_1')['body'])->toBe(['outcome' => 'uncertain']);
    expect(chat()->snapshot('t_2')['turns'][0]['state'])->toBe('cancelling');

    chatApp();
    bindHost()->backend(new NullBackend(function () {
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }, ['steering' => false, 'cancel' => false, 'approvals' => false]));
    http('POST', '/fancy-chat/threads/t_3/messages', textMessage('hi'), 'human:u_1');
    expect(http('POST', '/fancy-chat/threads/t_3/cancel', ['mode' => 'stop'], 'human:u_1')['body'])->toBe(['outcome' => 'unsupported']);

    chatApp();
    bindHost()->backend(new NullBackend(function () {
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }, ['steering' => false, 'cancel' => true, 'approvals' => false], 'ok'));
    http('POST', '/fancy-chat/threads/t_4/messages', textMessage('hi'), 'human:u_1');
    expect(http('POST', '/fancy-chat/threads/t_4/cancel', ['mode' => 'interrupt'], 'human:u_1')['body'])->toBe(['outcome' => 'ok']);
    expect(chat()->snapshot('t_4')['turns'][0]['outcome'])->toBe('interrupted');
    expect(http('POST', '/fancy-chat/threads/t_4/cancel', ['mode' => 'stop'], 'agent:compass')['status'])->toBe(403);
});

it('passes the configured confirm wait to the backend', function (): void {
    chatApp(['fancy-chat.cancel.confirm_wait_ms' => 750]);
    $backend = new NullBackend(function () {
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }, ['steering' => false, 'cancel' => true, 'approvals' => false], 'refused');
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');
    expect(http('POST', '/fancy-chat/threads/t_1/cancel', ['mode' => 'stop'], 'human:u_1')['body'])->toBe(['outcome' => 'refused']);
    expect($backend->cancels[0]['confirmWaitMs'])->toBe(750)->and($backend->cancels[0]['mode'])->toBe('stop');
});

it('projects a decision the same as the contract would', function (): void {
    chatApp();
    $inputs = [];
    [$approvalId] = parkedApproval($inputs);
    http('POST', "/fancy-chat/approvals/{$approvalId}/decision", ['optionId' => 'yes'], 'human:u_1');
    $events = chat()->store()->after('t_1', 0, 1000);
    expect(Projection::project($events)['illegal'])->toBe(0);
});
