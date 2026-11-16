<?php

declare(strict_types=1);

use FancyChat\Backend\NullBackend;
use FancyChat\Backend\TurnInput;

/*
 * Retry a failed turn with its ORIGINAL input: POST threads/{id}/turns/{turnId}/retry
 * re-sends that turn's message parts as a new turn. The server finds them through
 * the projection's turn-to-message link, so it works after a refresh, with no
 * client state.
 */

function flakyBackend(array &$inputs, int $failFirst = 1): NullBackend
{
    $n = 0;

    return new NullBackend(function (TurnInput $input) use (&$inputs, &$n, $failFirst): iterable {
        $inputs[] = $input;
        if ($n++ < $failFirst) {
            yield ['type' => 'turn.settled', 'data' => ['action' => 'fail', 'reason' => 'output_limit']];

            return;
        }
        yield ['type' => 'message.created', 'data' => ['message' => ['id' => 'a_'.$input->turnId, 'role' => 'assistant', 'parts' => [['id' => 'p', 'kind' => 'text', 'format' => 'plain', 'text' => 'ok']]]]];
    });
}

it('links each turn to the message that started it, both ways', function (): void {
    chatApp();
    bindHost()->backend(replyBackend());
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');
    $state = chat()->snapshot('t_1');
    expect($state['turns'][0]['messageId'])->toBe($r['body']['messageId'])
        ->and($state['messages'][0]['turnId'])->toBe($state['turns'][0]['turnId']);
});

it('retries a failed turn with its original parts, as a new turn', function (): void {
    chatApp();
    $inputs = [];
    bindHost()->backend(flakyBackend($inputs));
    $parts = [['id' => 'p1', 'kind' => 'text', 'format' => 'plain', 'text' => 'write the essay']];
    http('POST', '/fancy-chat/threads/t_1/messages', ['parts' => $parts], 'human:u_1');
    $failed = chat()->snapshot('t_1')['turns'][0];
    expect($failed['outcome'])->toBe('failed');

    $r = http('POST', "/fancy-chat/threads/t_1/turns/{$failed['turnId']}/retry", null, 'human:u_1');
    expect($r['status'])->toBe(201)->and($r['body']['delivery'])->toBe('sent');
    expect($inputs)->toHaveCount(2)->and($inputs[1]->parts)->toBe($parts)->and($inputs[1]->turnId)->not->toBe($failed['turnId']);
    $state = chat()->snapshot('t_1');
    expect($state['turns'])->toHaveCount(2)
        ->and($state['turns'][1]['outcome'])->toBe('completed')
        ->and($state['turns'][1]['messageId'])->toBe($r['body']['messageId'])
        ->and($state['turns'][0]['outcome'])->toBe('failed');
});

it('a retry queues behind a running turn, in order, then runs', function (): void {
    chatApp();
    $inputs = [];
    $hold = true;
    bindHost()->backend(new NullBackend(function (TurnInput $input) use (&$inputs, &$hold): iterable {
        $inputs[] = $input;
        $text = $input->parts[0]['text'] ?? '';
        if ($text === 'first') {
            yield ['type' => 'turn.settled', 'data' => ['action' => 'fail', 'reason' => 'model_error']];

            return;
        }
        if ($text === 'second') {
            // While this turn runs, the human retries the failed first turn and queues a third message.
            $first = chat()->snapshot($input->threadId)['turns'][0]['turnId'];
            expect(http('POST', "/fancy-chat/threads/{$input->threadId}/turns/{$first}/retry", null, 'human:u_1')['body']['delivery'])->toBe('queued');
            expect(http('POST', "/fancy-chat/threads/{$input->threadId}/messages", textMessage('third'), 'human:u_1')['body']['delivery'])->toBe('queued');
        }
        yield ['type' => 'message.created', 'data' => ['message' => ['id' => 'a_'.$input->turnId, 'role' => 'assistant', 'parts' => []]]];
    }));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('first'), 'human:u_1');
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('second'), 'human:u_1');
    expect(array_map(fn (TurnInput $i): string => $i->parts[0]['text'], $inputs))->toBe(['first', 'second', 'first', 'third']);
});

it('refuses a turn that did not fail, an unknown turn, and a non-human', function (): void {
    chatApp();
    bindHost()->backend(replyBackend());
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');
    $ok = chat()->snapshot('t_1')['turns'][0]['turnId'];
    expect(http('POST', "/fancy-chat/threads/t_1/turns/{$ok}/retry", null, 'human:u_1')['status'])->toBe(409)
        ->and(http('POST', '/fancy-chat/threads/t_1/turns/u_nope/retry', null, 'human:u_1')['status'])->toBe(404)
        ->and(http('POST', "/fancy-chat/threads/t_1/turns/{$ok}/retry", null, 'agent:compass')['status'])->toBe(403);
});

it('refuses a retry the host denies write access to', function (): void {
    chatApp();
    $inputs = [];
    bindHost()->backend(flakyBackend($inputs));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('x'), 'human:u_1');
    $failed = chat()->snapshot('t_1')['turns'][0]['turnId'];
    expect(http('POST', "/fancy-chat/threads/t_1/turns/{$failed}/retry", null, 'human:u_1', ['X-Deny' => 'write'])['status'])->toBe(403);
    expect($inputs)->toHaveCount(1);
});

it('retries an interrupted or cancelled turn too; a turn no message started cannot be retried', function (): void {
    chatApp();
    bindHost()->backend(replyBackend());
    chat()->append('t_1', ['type' => 'turn.started', 'data' => ['turnId' => 'u_orphan']]);
    chat()->append('t_1', ['type' => 'turn.transition', 'data' => ['turnId' => 'u_orphan', 'action' => 'start']]);
    chat()->append('t_1', ['type' => 'turn.settled', 'data' => ['turnId' => 'u_orphan', 'action' => 'fail', 'reason' => 'x']]);
    expect(http('POST', '/fancy-chat/threads/t_1/turns/u_orphan/retry', null, 'human:u_1')['status'])->toBe(422);
});
