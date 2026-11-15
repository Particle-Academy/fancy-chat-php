<?php

declare(strict_types=1);

use FancyChat\Backend\NullBackend;
use FancyChat\Backend\TurnInput;
use FancyChat\ChatException;
use FancyChat\Contract\TypedApproval;
use FancyChat\Undoable;

/** Records every commit and revert, and can run a callback in the middle of one. */
final class RecordingUndoable implements Undoable
{
    /** @var list<string> */
    public array $commits = [];

    /** @var list<string> */
    public array $reverts = [];

    public ?Closure $duringRevert = null;

    public ?Closure $duringCommit = null;

    public function commit(string $actionId, string $threadId): void
    {
        $this->commits[] = $actionId;
        if ($this->duringCommit !== null) {
            ($this->duringCommit)();
        }
    }

    public function revert(string $actionId, string $threadId): void
    {
        $this->reverts[] = $actionId;
        if ($this->duringRevert !== null) {
            ($this->duringRevert)();
        }
    }
}

function englishTypedApproval(): array
{
    return ['fancy-chat.typed_approval' => ['enabled' => true, 'locale' => 'en', 'phrases' => ['en' => TypedApproval::ENGLISH]]];
}

// ------------------------------------------------------------- typed approval

it('treats typed approval text as an ordinary message unless the app enables it', function (): void {
    chatApp();
    $inputs = [];
    $write = new CountingWrite;
    [$approvalId] = parkedApproval($inputs, $write);

    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('approve'), 'human:u_1');
    expect($r['status'])->toBe(201)->and($r['body']['delivery'])->toBe('queued')->and($r['body'])->not->toHaveKey('typedDecision');
    expect(chat()->snapshot('t_1')['approvals'][0]['state'])->toBe('pending')->and($write->applied)->toBe([]);
});

it('takes a typed phrase as the human decision, through the same decide path, and never delivers it', function (string $store): void {
    chatApp(array_merge(englishTypedApproval(), ['fancy-chat.store' => $store]));
    $inputs = [];
    $write = new CountingWrite;
    [$approvalId, $turnId] = parkedApproval($inputs, $write);

    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('  Yes, approve! '), 'human:u_1');
    expect($r['status'])->toBe(201)
        ->and($r['body']['delivery'])->toBe('sent')
        ->and($r['body']['typedDecision'])->toBe(['approvalId' => $approvalId, 'optionId' => 'yes', 'decision' => 'approve', 'state' => 'applied']);

    $state = chat()->snapshot('t_1');
    $typed = collect($state['messages'])->firstWhere('id', $r['body']['messageId']);
    expect($typed['delivery'])->toBe('sent')
        ->and($state['approvals'][0]['state'])->toBe('applied')
        ->and($state['approvals'][0]['decidedBy'])->toBe(['kind' => 'human', 'id' => 'u_1'])
        ->and($write->applied)->toHaveCount(1);
    // The parked turn resumed; the typed text started no turn of its own and was never handed to the agent.
    expect($inputs)->toHaveCount(2)->and($inputs[1]->kind)->toBe('resume')
        ->and(collect($inputs)->pluck('messageId')->filter()->all())->not->toContain($r['body']['messageId']);
    $linked = collect(pollAll('t_1'))->firstWhere('type', 'x-fancy-chat.typed-decision');
    expect($linked['data'])->toBe(['messageId' => $r['body']['messageId'], 'approvalId' => $approvalId, 'optionId' => 'yes', 'decision' => 'approve']);
})->with('stores');

it('a typed reject rejects, and never applies the staged write', function (): void {
    chatApp(englishTypedApproval());
    $inputs = [];
    $write = new CountingWrite;
    parkedApproval($inputs, $write);
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('no, reject'), 'human:u_1');
    expect($r['body']['typedDecision']['decision'])->toBe('reject')
        ->and(chat()->snapshot('t_1')['approvals'][0]['state'])->toBe('rejected')
        ->and($write->applied)->toBe([]);
});

it('a phrase inside a sentence is a message, not a decision', function (): void {
    chatApp(englishTypedApproval());
    $inputs = [];
    parkedApproval($inputs);
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('please approve it'), 'human:u_1');
    expect($r['body']['delivery'])->toBe('queued')->and(chat()->snapshot('t_1')['approvals'][0]['state'])->toBe('pending');
});

it('a typed decision while another decision is in flight is refused and its message dropped', function (): void {
    chatApp(englishTypedApproval());
    $inputs = [];
    [$approvalId] = parkedApproval($inputs);
    expect(chat()->store()->claim($approvalId, 'pending', 'deciding'))->toBeTrue();
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('approve'), 'human:u_1');
    expect($r['status'])->toBe(409)->and($r['body']['error'])->toBe('approval.in-flight');
    expect(collect(chat()->snapshot('t_1')['messages'])->where('delivery', 'dropped')->count())->toBe(1);
});

it('a typed decision goes through the same access check as the decision endpoint', function (): void {
    chatApp(englishTypedApproval());
    $inputs = [];
    parkedApproval($inputs);
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('approve'), 'human:u_1', ['X-Deny' => 'decide']);
    expect($r['status'])->toBe(403)->and(chat()->snapshot('t_1')['approvals'][0]['state'])->toBe('pending');
});

// ------------------------------------------------------------------ undo

it('opens an undo window with the host-chosen duration per action', function (int $windowMs): void {
    chatApp();
    bindHost()->backend(replyBackend())->undoable('send-email', new RecordingUndoable);
    $id = chat()->openUndo('t_1', 'send-email', 'Email to 3 people', $windowMs);
    $entry = collect(chat()->snapshot('t_1')['undos'])->firstWhere('actionId', $id);
    expect($entry['state'])->toBe('open')
        ->and($entry['label'])->toBe('Email to 3 people')
        ->and(\FancyChat\Contract\Undo::toMs($entry['deadline']))->toBe(TestClock::$now + $windowMs);
})->with([30000, 60000, 180000]);

it('refuses an action kind the host never registered, and clamps the window to the configured maximum', function (): void {
    chatApp(['fancy-chat.undo.max_ms' => 60000]);
    bindHost()->undoable('send-email', new RecordingUndoable);
    expect(fn () => chat()->openUndo('t_1', 'wire-money', 'Pay', 30000))->toThrow(ChatException::class, 'undo.kind');
    $id = chat()->openUndo('t_1', 'send-email', 'Email', 3_600_000);
    expect(\FancyChat\Contract\Undo::toMs(chat()->snapshot('t_1')['undos'][0]['deadline']))->toBe(TestClock::$now + 60000);
});

it('a human reverts inside the window, exactly once', function (string $store): void {
    chatApp(['fancy-chat.store' => $store]);
    $undo = new RecordingUndoable;
    bindHost()->undoable('send-email', $undo);
    $id = chat()->openUndo('t_1', 'send-email', 'Email', 30000);
    tick(10000);

    $r = http('POST', "/fancy-chat/undo/{$id}/revert", [], 'human:u_1');
    expect($r['status'])->toBe(200)->and($r['body']['undo']['state'])->toBe('reverted');
    expect($undo->reverts)->toBe([$id]);
    $event = collect(pollAll('t_1'))->firstWhere('type', 'undo.reverted');
    expect($event['data']['revertedBy'])->toBe(['kind' => 'human', 'id' => 'u_1'])->and($event['actor'])->toBe(['kind' => 'human', 'id' => 'u_1']);

    expect(http('POST', "/fancy-chat/undo/{$id}/revert", [], 'human:u_1')['status'])->toBe(409);
    tick(60000);
    expect(chat()->commitExpiredUndos())->toBe(0)->and($undo->commits)->toBe([])->and($undo->reverts)->toBe([$id]);
})->with('stores');

it('an agent can never revert', function (): void {
    chatApp();
    $undo = new RecordingUndoable;
    bindHost()->undoable('send-email', $undo);
    $id = chat()->openUndo('t_1', 'send-email', 'Email', 30000);
    $r = http('POST', "/fancy-chat/undo/{$id}/revert", [], 'agent:compass');
    expect($r['status'])->toBe(403)->and($r['body']['error'])->toBe('chat.actor.human')->and($undo->reverts)->toBe([]);
});

it('at the deadline the window is shut: revert is refused and the commit runs once', function (): void {
    chatApp();
    $undo = new RecordingUndoable;
    bindHost()->undoable('send-email', $undo);
    $id = chat()->openUndo('t_1', 'send-email', 'Email', 30000);

    tick(29999);
    expect(chat()->commitExpiredUndos())->toBe(0);
    tick(1);
    $r = http('POST', "/fancy-chat/undo/{$id}/revert", [], 'human:u_1');
    expect($r['status'])->toBe(409)->and($r['body']['error'])->toBe('undo.expired')->and($undo->reverts)->toBe([]);

    expect(chat()->commitExpiredUndos())->toBe(1)->and(chat()->commitExpiredUndos())->toBe(0);
    expect($undo->commits)->toBe([$id])
        ->and(collect(chat()->snapshot('t_1')['undos'])->firstWhere('actionId', $id)['state'])->toBe('committed');
});

it('a revert and the deadline racing: each side runs at most once, and only one of them', function (string $store): void {
    chatApp(['fancy-chat.store' => $store]);
    $undo = new RecordingUndoable;
    bindHost()->undoable('send-email', $undo);
    $id = chat()->openUndo('t_1', 'send-email', 'Email', 30000);
    tick(29000);
    // The deadline passes WHILE the revert is running, and the committer fires.
    $undo->duringRevert = function () use (&$committed): void {
        tick(5000);
        $committed = chat()->commitExpiredUndos();
    };
    $committed = null;
    expect(http('POST', "/fancy-chat/undo/{$id}/revert", [], 'human:u_1')['status'])->toBe(200);
    expect($committed)->toBe(0)->and($undo->commits)->toBe([])->and($undo->reverts)->toBe([$id]);

    // And the other way round: a revert arriving while the commit runs.
    $id2 = chat()->openUndo('t_1', 'send-email', 'Post', 30000);
    $undo->duringRevert = null;
    $undo->duringCommit = function () use ($id2, &$late): void {
        $late = http('POST', "/fancy-chat/undo/{$id2}/revert", [], 'human:u_1')['status'];
    };
    $late = null;
    tick(30000);
    expect(chat()->commitExpiredUndos())->toBe(1);
    expect($late)->toBe(409)->and($undo->commits)->toBe([$id2])->and($undo->reverts)->toBe([$id]);
})->with('stores');

it('two committers running at once commit a window once', function (string $store): void {
    chatApp(['fancy-chat.store' => $store]);
    $undo = new RecordingUndoable;
    bindHost()->undoable('send-email', $undo);
    $id = chat()->openUndo('t_1', 'send-email', 'Email', 1000);
    tick(1000);
    $nested = null;
    $undo->duringCommit = function () use (&$nested): void {
        $nested = chat()->commitExpiredUndos();
    };
    expect(chat()->commitExpiredUndos())->toBe(1);
    expect($nested)->toBe(0)->and($undo->commits)->toBe([$id]);
    expect(collect(pollAll('t_1'))->where('type', 'undo.committed')->count())->toBe(1);
})->with('stores');

it('the reaper and the command commit expired windows', function (): void {
    chatApp();
    $undo = new RecordingUndoable;
    bindHost()->undoable('send-email', $undo);
    $a = chat()->openUndo('t_1', 'send-email', 'A', 1000);
    tick(1);
    $b = chat()->openUndo('t_2', 'send-email', 'B', 1000);
    tick(1000);
    chat()->reap();
    expect($undo->commits)->toBe([$a, $b]);
    $c = chat()->openUndo('t_1', 'send-email', 'C', 1000);
    tick(1000);
    $command = app(FancyChat\Console\CommitUndoCommand::class);
    $command->setLaravel(app());
    $output = new Symfony\Component\Console\Output\BufferedOutput;
    expect($command->run(new Symfony\Component\Console\Input\ArrayInput([]), $output))->toBe(0)
        ->and($output->fetch())->toContain('Committed 1 undo window');
    expect($undo->commits)->toBe([$a, $b, $c]);
});

// --------------------------------------------------------- edit and resend

function editResendThread(): array
{
    bindHost()->backend(replyBackend('reply'));
    $first = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('one'), 'human:u_1');

    return [$first['body']['messageId'], chat()->snapshot('t_1')];
}

it('edit and resend keeps the superseded branch by default', function (): void {
    chatApp();
    [$first] = editResendThread();
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('one, edited') + ['supersedes' => $first], 'human:u_1');
    expect($r['status'])->toBe(201);
    $state = chat()->snapshot('t_1');
    expect($state['branches'])->toHaveCount(1)
        ->and($state['branches'][0]['supersededId'])->toBe($first)
        ->and($state['branches'][0]['byId'])->toBe($r['body']['messageId'])
        ->and($state['branches'][0]['rowIds'])->not->toBe([]);
    expect(collect($state['messages'])->where('retracted', true)->count())->toBe(0);
});

it('truncate retracts every message in the superseded branch and withdraws its pending approvals', function (): void {
    chatApp(['fancy-chat.edit_resend.policy' => 'truncate']);
    $inputs = [];
    bindHost()->backend(approvalBackend($inputs))->stagedWrite('send-email', new CountingWrite);
    $first = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('email the team'), 'human:u_1')['body']['messageId'];
    $approvalId = chat()->snapshot('t_1')['approvals'][0]['approvalId'];

    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('email only me') + ['supersedes' => $first], 'human:u_1');
    expect($r['status'])->toBe(201);
    $state = chat()->snapshot('t_1');
    $branch = $state['branches'][0]['rowIds'];
    foreach ($state['messages'] as $m) {
        if (in_array($m['id'], $branch, true)) {
            expect($m['retracted'])->toBeTrue();
        }
    }
    expect(collect($state['approvals'])->firstWhere('approvalId', $approvalId)['state'])->toBe('withdrawn')
        ->and(collect($state['messages'])->firstWhere('id', $r['body']['messageId'])['retracted'])->toBeFalse()
        ->and(collect($state['messages'])->firstWhere('id', $first)['retracted'])->toBeFalse();
});

it('truncate between turns retracts the superseded reply and starts the new turn', function (): void {
    chatApp(['fancy-chat.edit_resend.policy' => 'truncate']);
    [$first, $state] = editResendThread();
    $reply = collect($state['messages'])->firstWhere('role', 'assistant')['id'];
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('one, edited') + ['supersedes' => $first], 'human:u_1');
    expect($r['body']['delivery'])->toBe('sent');
    $state = chat()->snapshot('t_1');
    expect(collect($state['messages'])->firstWhere('id', $reply)['retracted'])->toBeTrue()
        ->and($state['turns'])->toHaveCount(2);
});

it('only the author can edit and resend their own human message', function (string $who, string $target, string $error): void {
    chatApp();
    [$first, $state] = editResendThread();
    $assistant = collect($state['messages'])->firstWhere('role', 'assistant')['id'];
    $id = ['first' => $first, 'assistant' => $assistant, 'ghost' => 'm_404'][$target];
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('x') + ['supersedes' => $id], $who);
    expect($r['status'])->toBe(422)->and($r['body']['error'])->toBe($error);
})->with([
    'someone else\'s message' => ['human:u_2', 'first', 'message.supersedes'],
    'an assistant message' => ['human:u_1', 'assistant', 'message.supersedes'],
    'a message that does not exist' => ['human:u_1', 'ghost', 'message.supersedes'],
]);

it('a human-authored message that is not a user message cannot be superseded', function (): void {
    chatApp();
    editResendThread();
    chat()->append('t_1', ['type' => 'message.created', 'actor' => ['kind' => 'human', 'id' => 'u_1'], 'data' => ['message' => [
        'id' => 'm_note', 'role' => 'system', 'parts' => [['id' => 'p', 'kind' => 'text', 'format' => 'plain', 'text' => 'Ticket moved.']],
    ]]]);
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('x') + ['supersedes' => 'm_note'], 'human:u_1');
    expect($r['status'])->toBe(422)->and($r['body']['error'])->toBe('message.supersedes');
});

it('refuses an edit-and-resend policy outside the set', function (): void {
    chatApp(['fancy-chat.edit_resend.policy' => 'rewrite-history']);
    [$first] = editResendThread();
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('x') + ['supersedes' => $first], 'human:u_1');
    expect($r['status'])->toBe(500)->and($r['body']['error'])->toBe('config.edit_resend.policy');
});

// --------------------------------------------------------- plan-step HITL

it('refuses a backend releasing a held step before its approval, before appending it', function (): void {
    chatApp();
    bindHost()->backend(new NullBackend(function (TurnInput $input): iterable {
        yield ['type' => 'plan.updated', 'runId' => 'r_1', 'data' => ['steps' => [['id' => 's1', 'title' => 'Pay', 'status' => 'held', 'owner' => 'human', 'approvalId' => 'ap_x']]]];
        yield ['type' => 'plan.updated', 'runId' => 'r_1', 'data' => ['steps' => [['id' => 's1', 'title' => 'Pay', 'status' => 'done', 'owner' => 'human', 'approvalId' => 'ap_x']]]];
    }));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('pay'), 'human:u_1');
    $events = pollAll('t_1');
    expect(collect($events)->where('type', 'plan.updated')->count())->toBe(1);
    $refused = collect($events)->firstWhere('type', 'x-fancy-chat.refused');
    expect($refused['data'])->toBe(['type' => 'plan.updated', 'error' => 'plan.held']);
    $state = chat()->snapshot('t_1');
    expect($state['plans'][0]['steps'][0]['status'])->toBe('held')->and($state['illegal'])->toBe(0);
});
