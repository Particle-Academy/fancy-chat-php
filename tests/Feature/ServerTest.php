<?php

declare(strict_types=1);

use FancyChat\Tests\Support\ScriptedBackend;
use FancyChat\Tests\Support\CaptureBroadcaster;
use Illuminate\Http\Request;

dataset('routes', [
    ['POST', '/fancy-chat/threads/t_1/messages'],
    ['GET', '/fancy-chat/threads/t_1/events'],
    ['GET', '/fancy-chat/threads/t_1/events/poll?after=0&wait=0'],
    ['GET', '/fancy-chat/threads/t_1/snapshot'],
    ['POST', '/fancy-chat/threads/t_1/cancel'],
    ['DELETE', '/fancy-chat/threads/t_1/queue/m_1'],
    ['POST', '/fancy-chat/approvals/ap_1/decision'],
    ['POST', '/fancy-chat/questions/q_1/answer'],
]);

it('refuses every route until the host binds its resolvers', function (string $method, string $uri): void {
    chatApp(['fancy-chat.sse.max_duration_ms' => 0]);
    $r = http($method, $uri, ['parts' => []], 'human:u_1');
    expect($r['status'])->toBe(403)->and($r['body'])->toBe(['error' => 'chat.unconfigured']);

    chat()->actor(fn (): array => ['kind' => 'human', 'id' => 'u_1']);
    $r = http($method, $uri, ['parts' => []], 'human:u_1');
    expect($r['status'])->toBe(403)->and($r['body'])->toBe(['error' => 'chat.unconfigured']);
})->with('routes');

it('answers 401 when the resolver finds no actor, and 403 when access is refused', function (string $method, string $uri): void {
    chatApp(['fancy-chat.sse.max_duration_ms' => 0]);
    bindHost();
    $anon = http($method, $uri, ['parts' => []]);
    expect($anon['status'])->toBe(401)->and($anon['body'])->toBe(['error' => 'chat.actor']);
})->with('routes');

it('asks the host for the right ability on each route', function (): void {
    chatApp(['fancy-chat.sse.max_duration_ms' => 0]);
    $asked = [];
    bindHost()->backend(new ScriptedBackend(fn (): iterable => []))
        ->access(function (Request $request, string $thread, string $ability) use (&$asked): bool {
            $asked[] = $thread.':'.$ability;

            return false;
        });
    foreach ([['POST', '/fancy-chat/threads/t_1/messages'], ['GET', '/fancy-chat/threads/t_1/events/poll?after=0&wait=0'], ['GET', '/fancy-chat/threads/t_1/events'], ['GET', '/fancy-chat/threads/t_1/snapshot'], ['POST', '/fancy-chat/threads/t_1/cancel'], ['DELETE', '/fancy-chat/threads/t_1/queue/m_1']] as [$m, $u]) {
        expect(http($m, $u, ['parts' => []], 'human:u_1')['body'])->toBe(['error' => 'chat.access']);
    }
    expect($asked)->toBe(['t_1:write', 't_1:read', 't_1:read', 't_1:read', 't_1:cancel', 't_1:write']);
});

it('grants access only on a literal true, never a truthy value', function (mixed $answer): void {
    chatApp();
    bindHost()->access(fn (): mixed => $answer);
    expect(http('GET', '/fancy-chat/threads/t_1/snapshot', null, 'human:u_1')['body'])->toBe(['error' => 'chat.access']);
})->with([[1], ['yes'], [['allow' => true]], [null]]);

it('only lets a human send a message', function (): void {
    chatApp();
    bindHost()->backend(new ScriptedBackend(fn (): iterable => []));
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'agent:compass');
    expect($r['status'])->toBe(403)->and($r['body'])->toBe(['error' => 'chat.actor.human']);
});

it('refuses a message when no backend is bound, without writing anything', function (): void {
    chatApp();
    bindHost();
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');
    expect($r['status'])->toBe(503)->and($r['body'])->toBe(['error' => 'chat.backend']);
    expect(chat()->store()->lastSeq('t_1'))->toBe(0);
});

it('refuses a malformed thread id', function (): void {
    chatApp();
    bindHost();
    expect(http('GET', '/fancy-chat/threads/'.str_repeat('a', 200).'/snapshot', null, 'human:u_1')['status'])->toBe(422);
});

it('mounts the routes under the configured prefix, or not at all', function (): void {
    chatApp(['fancy-chat.prefix' => 'api/agent-chat']);
    bindHost();
    expect(http('GET', '/api/agent-chat/threads/t_1/snapshot', null, 'human:u_1')['status'])->toBe(200);

    chatApp(['fancy-chat.routes' => false]);
    bindHost();
    expect(fn () => http('GET', '/fancy-chat/threads/t_1/snapshot', null, 'human:u_1'))
        ->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

it('reaps a stalled turn after the configured timeout, leaving parked turns alone by default', function (): void {
    chatApp(['fancy-chat.reaper.timeout_ms' => 60000]);
    bindHost();
    chat()->append('t_run', ['type' => 'turn.started', 'data' => ['turnId' => 'u_1']]);
    chat()->append('t_run', ['type' => 'turn.transition', 'data' => ['turnId' => 'u_1', 'action' => 'start']]);
    chat()->append('t_park', ['type' => 'turn.started', 'data' => ['turnId' => 'u_2']]);
    chat()->append('t_park', ['type' => 'turn.transition', 'data' => ['turnId' => 'u_2', 'action' => 'start']]);
    chat()->append('t_park', ['type' => 'turn.transition', 'data' => ['turnId' => 'u_2', 'action' => 'park']]);

    tick(59999);
    expect(chat()->reap())->toBe(0);
    tick(1);
    expect(chat()->reap())->toBe(1);
    expect(chat()->snapshot('t_run')['turns'][0])->toBe(['turnId' => 'u_1', 'state' => 'settled', 'outcome' => 'failed', 'reason' => 'stalled', 'messageId' => null])
        ->and(chat()->snapshot('t_park')['turns'][0]['state'])->toBe('awaiting-human');

    config(['fancy-chat.reaper.include_parked' => true]);
    expect(chat()->reap())->toBe(1);
    expect(chat()->snapshot('t_park')['turns'][0]['reason'])->toBe('stalled');
});

it('runs the reaper as an artisan command', function (): void {
    chatApp(['fancy-chat.reaper.timeout_ms' => 0]);
    bindHost();
    chat()->append('t_run', ['type' => 'turn.started', 'data' => ['turnId' => 'u_1']]);
    tick(1);
    $command = app(FancyChat\Console\ReapCommand::class);
    $command->setLaravel(app());
    $output = new Symfony\Component\Console\Output\BufferedOutput;
    $code = $command->run(new Symfony\Component\Console\Input\ArrayInput([]), $output);
    expect($code)->toBe(0)
        ->and($output->fetch())->toContain('Reaped 1 stalled turn')
        ->and(chat()->snapshot('t_run')['turns'][0]['state'])->toBe('settled');
});

it('sends no nudge unless the host turns it on', function (): void {
    chatApp();
    bindHost();
    chat()->append('t_1', ['type' => 'usage', 'data' => ['secret' => 'payload']]);
    expect(CaptureBroadcaster::$sent)->toBe([]);
});

it('nudges with only the thread id and seq, never the payload', function (): void {
    chatApp(['fancy-chat.nudge.enabled' => true, 'fancy-chat.nudge.connection' => 'capture']);
    bindHost();
    chat()->append('t_1', ['type' => 'usage', 'data' => ['secret' => 'payload']]);
    chat()->append('t_1', ['type' => 'usage', 'data' => ['secret' => 'payload']]);
    expect(CaptureBroadcaster::$sent)->toBe([
        ['channels' => ['private-fancy-chat.t_1'], 'event' => 'fancy-chat.seq', 'payload' => ['threadId' => 't_1', 'seq' => 1]],
        ['channels' => ['private-fancy-chat.t_1'], 'event' => 'fancy-chat.seq', 'payload' => ['threadId' => 't_1', 'seq' => 2]],
    ]);
});

it('keeps a nudge failure from failing the append', function (): void {
    chatApp(['fancy-chat.nudge.enabled' => true, 'fancy-chat.nudge.connection' => 'missing-connection']);
    bindHost();
    $event = chat()->append('t_1', ['type' => 'usage', 'data' => []]);
    expect($event['seq'])->toBe(1);
});

it('publishes every limit in its config', function (): void {
    chatApp();
    expect(array_keys(config('fancy-chat')))->toContain('retention', 'poll', 'sse', 'limits', 'reaper', 'cancel', 'nudge', 'hitl');
    expect(config('fancy-chat.limits'))->toHaveKeys(['event_bytes', 'part_bytes', 'part_depth', 'parts_per_message', 'queue_length']);
});
