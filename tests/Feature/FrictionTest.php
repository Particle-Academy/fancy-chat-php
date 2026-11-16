<?php

declare(strict_types=1);

use FancyChat\Tests\Support\ScriptedBackend;
use FancyChat\Backend\TurnInput;
use FancyChat\ChatException;
use FancyChat\Jobs\RunTurn;
use Illuminate\Http\Request;

/*
 * The Overseer dogfood friction list (CC-fancy-0042), server side.
 */

it('keeps the actor label the host resolver returns', function (): void {
    chatApp();
    bindHost()->backend(replyBackend())->actor(fn (Request $r): array => ['kind' => 'human', 'id' => 'u_1', 'label' => 'Ada Lovelace']);
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');
    expect($r['status'])->toBe(201);
    $first = chat()->store()->after('t_1', 0, 1)[0];
    expect($first['actor'])->toBe(['kind' => 'human', 'id' => 'u_1', 'label' => 'Ada Lovelace']);
    expect(chat()->snapshot('t_1')['messages'][0]['author'])->toBe(['kind' => 'human', 'id' => 'u_1', 'label' => 'Ada Lovelace']);
});

it('drops a label that is not a string, and an empty one', function (mixed $label): void {
    chatApp();
    bindHost()->backend(replyBackend())->actor(fn (Request $r): array => ['kind' => 'human', 'id' => 'u_1', 'label' => $label]);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');
    expect(chat()->store()->after('t_1', 0, 1)[0]['actor'])->toBe(['kind' => 'human', 'id' => 'u_1']);
})->with([[42], [''], [['x']]]);

it('a label never changes who decided: the decision matches kind and id', function (): void {
    chatApp();
    $inputs = [];
    [$approvalId] = parkedApproval($inputs);
    chat()->actor(fn (Request $r): array => ['kind' => 'human', 'id' => 'u_1', 'label' => 'Ada']);
    $r = http('POST', "/fancy-chat/approvals/{$approvalId}/decision", ['optionId' => 'yes'], 'human:u_1');
    expect($r['status'])->toBe(200);
    expect(chat()->snapshot('t_1')['approvals'][0]['state'])->toBe('applied');
});

it('send answers 201 with messageId, seq and delivery; snapshot answers {snapshot, seq, capabilities}', function (): void {
    chatApp();
    bindHost()->backend(replyBackend());
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');
    expect($r['status'])->toBe(201)->and(array_keys($r['body']))->toContain('messageId', 'seq', 'delivery');
    $s = http('GET', '/fancy-chat/threads/t_1/snapshot', null, 'human:u_1');
    expect($s['status'])->toBe(200)
        ->and($s['body']['seq'])->toBe($s['body']['snapshot']['lastSeq'])
        ->and($s['body']['capabilities'])->toBe(['steering' => false, 'cancel' => true, 'approvals' => true]);
});

it('GET threads/{id}/capabilities reports the backend\'s, so a UI can hide Stop', function (): void {
    chatApp();
    bindHost()->backend(new ScriptedBackend(fn (): iterable => [], ['steering' => false, 'cancel' => false, 'approvals' => true]));
    $r = http('GET', '/fancy-chat/threads/t_1/capabilities', null, 'human:u_1');
    expect($r['status'])->toBe(200)->and($r['body'])->toBe(['capabilities' => ['steering' => false, 'cancel' => false, 'approvals' => true]]);
});

it('capabilities without a backend are all false, and the read is access-checked', function (): void {
    chatApp();
    bindHost();
    expect(http('GET', '/fancy-chat/threads/t_1/capabilities', null, 'human:u_1')['body'])
        ->toBe(['capabilities' => ['steering' => false, 'cancel' => false, 'approvals' => false]]);
    expect(http('GET', '/fancy-chat/threads/t_1/capabilities', null, 'human:u_1', ['X-Deny' => 'read'])['status'])->toBe(403);
    expect(http('GET', '/fancy-chat/threads/t_1/capabilities', null, null)['status'])->toBe(401);
});

it('capabilities report booleans only: a backend returning anything else reads as false', function (): void {
    chatApp();
    bindHost()->backend(new ScriptedBackend(fn (): iterable => [], ['steering' => 'yes', 'cancel' => 1, 'approvals' => true]));
    expect(http('GET', '/fancy-chat/threads/t_1/capabilities', null, 'human:u_1')['body']['capabilities'])
        ->toBe(['steering' => false, 'cancel' => false, 'approvals' => true]);
});

it('a resumed turn carries ALL of its decided approvals, not only the last', function (): void {
    chatApp();
    $inputs = [];
    bindHost()->backend(new ScriptedBackend(function (TurnInput $input) use (&$inputs): iterable {
        $inputs[] = $input;
        if ($input->kind === 'resume') {
            return;
        }
        foreach (['a', 'b'] as $k) {
            yield ['type' => 'approval.requested', 'data' => ['approval' => [
                'approvalId' => "ap_{$k}", 'title' => "Do {$k}?",
                'options' => [['id' => 'yes', 'kind' => 'allow-once', 'label' => 'Yes'], ['id' => 'no', 'kind' => 'reject-once', 'label' => 'No']],
            ]]];
        }
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('do both'), 'human:u_1');
    expect(http('POST', '/fancy-chat/approvals/ap_a/decision', ['optionId' => 'yes'], 'human:u_1')['status'])->toBe(200);
    expect($inputs)->toHaveCount(1);
    expect(http('POST', '/fancy-chat/approvals/ap_b/decision', ['optionId' => 'no'], 'human:u_1')['status'])->toBe(200);
    expect($inputs)->toHaveCount(2);
    $resume = $inputs[1];
    expect($resume->kind)->toBe('resume')
        ->and($resume->approval['approvalId'])->toBe('ap_b')
        ->and(array_column($resume->approvals, 'approvalId'))->toBe(['ap_a', 'ap_b'])
        ->and(array_column($resume->approvals, 'state'))->toBe(['approved', 'rejected']);
    // It survives the queue: RunTurn carries the input as an array.
    expect(TurnInput::fromArray($resume->toArray())->approvals)->toBe($resume->approvals);
});

it('a resume never carries another turn\'s approvals', function (): void {
    chatApp();
    $inputs = [];
    [$first] = parkedApproval($inputs);
    http('POST', "/fancy-chat/approvals/{$first}/decision", ['optionId' => 'no'], 'human:u_1');
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('again'), 'human:u_1');
    $second = chat()->snapshot('t_1')['approvals'][1]['approvalId'];
    http('POST', "/fancy-chat/approvals/{$second}/decision", ['optionId' => 'no'], 'human:u_1');
    $last = end($inputs);
    expect(array_column($last->approvals, 'approvalId'))->toBe([$second]);
});

it('the host\'s own append runs the approval checks: an invalid record is refused', function (): void {
    chatApp();
    bindHost();
    expect(fn () => chat()->append('t_1', ['type' => 'approval.requested', 'turnId' => 'u_1', 'data' => ['approval' => ['approvalId' => 'ap_1', 'title' => 'x', 'options' => []]]]))
        ->toThrow(ChatException::class, 'approval.record');
});

it('the host\'s own append stamps the turn on the record', function (): void {
    chatApp();
    bindHost();
    chat()->append('t_1', ['type' => 'turn.started', 'data' => ['turnId' => 'u_9']]);
    chat()->append('t_1', ['type' => 'approval.requested', 'turnId' => 'u_9', 'data' => ['approval' => [
        'approvalId' => 'ap_1', 'title' => 'x', 'options' => [['id' => 'yes', 'kind' => 'allow-once', 'label' => 'Yes']],
    ]]]);
    $stored = collect(chat()->store()->after('t_1', 0, 10))->firstWhere('type', 'approval.requested');
    expect($stored['data']['approval']['turnId'])->toBe('u_9')
        ->and($stored['data']['approval']['state'])->toBe('pending')
        ->and(chat()->snapshot('t_1')['approvals'][0]['turnId'])->toBe('u_9');
});

it('refuses an approval id already used in ANOTHER thread, so a decision can never be routed to the wrong one', function (): void {
    chatApp();
    bindHost();
    $record = ['approvalId' => 'ap_same', 'title' => 'x', 'options' => [['id' => 'yes', 'kind' => 'allow-once', 'label' => 'Yes']]];
    chat()->append('t_1', ['type' => 'approval.requested', 'turnId' => 'u_1', 'data' => ['approval' => $record]]);
    expect(fn () => chat()->append('t_2', ['type' => 'approval.requested', 'turnId' => 'u_2', 'data' => ['approval' => $record]]))
        ->toThrow(ChatException::class, 'approval.duplicate');
    expect(chat()->store()->locate('ap_same'))->toBe('t_1')
        ->and(chat()->store()->after('t_2', 0, 10))->toBe([]);
});

it('refuses a question id already used in another thread', function (): void {
    chatApp();
    bindHost();
    $q = ['questionId' => 'q_same', 'prompt' => 'Which?', 'fields' => [['id' => 'f', 'type' => 'text', 'required' => true]]];
    chat()->append('t_1', ['type' => 'question.asked', 'turnId' => 'u_1', 'data' => ['question' => $q]]);
    expect(fn () => chat()->append('t_2', ['type' => 'question.asked', 'turnId' => 'u_2', 'data' => ['question' => $q]]))
        ->toThrow(ChatException::class, 'question.duplicate');
    expect(chat()->store()->locate('q_same'))->toBe('t_1');
});

it('RunTurn has an explicit timeout and tries, from config', function (): void {
    chatApp();
    $job = new RunTurn(['threadId' => 't_1', 'turnId' => 'u_1']);
    expect($job->timeout)->toBe(600)->and($job->tries)->toBe(1);
    chatApp(['fancy-chat.queue.timeout' => 90, 'fancy-chat.queue.tries' => 3]);
    $job = new RunTurn(['threadId' => 't_1', 'turnId' => 'u_1']);
    expect($job->timeout)->toBe(90)->and($job->tries)->toBe(3);
});

it('a host append without a turnId takes the thread\'s active turn, on the event and the record', function (): void {
    chatApp();
    bindHost();
    chat()->append('t_1', ['type' => 'turn.started', 'data' => ['turnId' => 'u_9']]);
    chat()->append('t_1', ['type' => 'approval.requested', 'data' => ['approval' => [
        'approvalId' => 'ap_1', 'title' => 'x', 'options' => [['id' => 'yes', 'kind' => 'allow-once', 'label' => 'Yes']],
    ]]]);
    $stored = collect(chat()->store()->after('t_1', 0, 10))->firstWhere('type', 'approval.requested');
    expect($stored['turnId'] ?? null)->toBe('u_9')->and($stored['data']['approval']['turnId'])->toBe('u_9');
});

it('a backend\'s approval preview is still checked as AGENT parts: it cannot place an approval part', function (): void {
    chatApp();
    bindHost()->backend(new ScriptedBackend(function (TurnInput $input): iterable {
        yield ['type' => 'approval.requested', 'data' => ['approval' => [
            'approvalId' => 'ap_x', 'title' => 'x',
            'preview' => [['id' => 'pv', 'kind' => 'approval', 'approvalId' => 'ap_other']],
            'options' => [['id' => 'yes', 'kind' => 'allow-once', 'label' => 'Yes']],
        ]]];
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('x'), 'human:u_1');
    $refused = collect(chat()->store()->after('t_1', 0, 50))->firstWhere('type', 'x-fancy-chat.refused');
    expect($refused['data'])->toBe(['type' => 'approval.requested', 'error' => 'part.hitl.origin'])
        ->and(chat()->snapshot('t_1')['approvals'])->toBe([]);
});
