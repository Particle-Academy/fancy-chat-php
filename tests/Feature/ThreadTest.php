<?php

declare(strict_types=1);

use FancyChat\Tests\Support\ScriptedBackend;
use FancyChat\Contract\Projection;

it('sends a message, runs the turn and replays it gaplessly', function (string $store): void {
    chatApp(['fancy-chat.store' => $store]);
    bindHost()->backend(replyBackend());

    $sent = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('Find Acme'), 'human:u_1');
    expect($sent['status'])->toBe(201)
        ->and($sent['body']['delivery'])->toBe('sent')
        ->and($sent['body']['seq'])->toBe(1)
        ->and($sent['body']['messageId'])->toBeString();

    $events = pollAll('t_1');
    expect(array_column($events, 'seq'))->toBe(range(1, count($events)));
    $p = Projection::project($events);
    expect($p['illegal'])->toBe(0)
        ->and($p['turns'][0]['state'])->toBe('settled')
        ->and($p['turns'][0]['outcome'])->toBe('completed');
    $messages = array_values(array_filter($p['rows'], fn (array $r): bool => $r['kind'] === 'message'));
    expect($messages[0]['delivery'])->toBe('delivered')
        ->and($messages[0]['author'])->toBe(['kind' => 'human', 'id' => 'u_1'])
        ->and($messages[1]['parts'][0]['text'])->toBe('Acme is won.')
        ->and($messages[1]['author']['kind'])->toBe('agent');
})->with('stores');

it('serves only the events after the cursor, and says where to resume', function (): void {
    chatApp();
    bindHost()->backend(replyBackend());
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');
    $all = pollAll('t_1');

    $page = http('GET', '/fancy-chat/threads/t_1/events/poll?after=3&wait=0', null, 'human:u_1');
    expect(array_column($page['body']['events'], 'seq'))->toBe(range(4, count($all)))
        ->and($page['body']['next'])->toBe(count($all));

    $empty = http('GET', '/fancy-chat/threads/t_1/events/poll?after='.count($all).'&wait=0', null, 'human:u_1');
    expect($empty['body'])->toBe(['events' => [], 'next' => count($all)]);
});

it('caps a poll page at the configured limit', function (): void {
    chatApp(['fancy-chat.poll.limit' => 3]);
    bindHost()->backend(replyBackend());
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');
    $page = http('GET', '/fancy-chat/threads/t_1/events/poll?after=0&wait=0', null, 'human:u_1');
    expect(array_column($page['body']['events'], 'seq'))->toBe([1, 2, 3])->and($page['body']['next'])->toBe(3);
});

it('long-polls until an event arrives or the clamped wait runs out', function (): void {
    chatApp(['fancy-chat.poll.max_wait_ms' => 1000, 'fancy-chat.poll.interval_ms' => 100]);
    $slept = [];
    bindHost()->backend(replyBackend())->sleeper(function (int $ms) use (&$slept): void {
        $slept[] = $ms;
        tick($ms);
    });
    $page = http('GET', '/fancy-chat/threads/t_1/events/poll?after=0&wait=60000', null, 'human:u_1');
    expect($page['body']['events'])->toBe([])
        ->and(array_sum($slept))->toBe(1000);
});

it('streams SSE: replay from Last-Event-ID, with ids, then returns', function (string $store): void {
    chatApp(['fancy-chat.store' => $store, 'fancy-chat.sse.max_duration_ms' => 0]);
    bindHost()->backend(replyBackend());
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');
    $total = count(pollAll('t_1'));

    $sse = http('GET', '/fancy-chat/threads/t_1/events', null, 'human:u_1', ['Last-Event-ID' => '2']);
    expect($sse['status'])->toBe(200)
        ->and($sse['headers']->get('Content-Type'))->toStartWith('text/event-stream')
        ->and($sse['headers']->get('Cache-Control'))->toContain('no-cache');
    preg_match_all('/^id: (\d+)$/m', $sse['raw'], $ids);
    expect(array_map('intval', $ids[1]))->toBe(range(3, $total));
    preg_match_all('/^data: (.+)$/m', $sse['raw'], $data);
    expect(json_decode($data[1][0], true)['seq'])->toBe(3);
    expect($sse['raw'])->toStartWith('retry: ');

    $query = http('GET', '/fancy-chat/threads/t_1/events?after=4', null, 'human:u_1');
    preg_match_all('/^id: (\d+)$/m', $query['raw'], $ids2);
    expect((int) $ids2[1][0])->toBe(5);
})->with('stores');

it('sends heartbeat comments while idle and stops at the max duration', function (): void {
    chatApp(['fancy-chat.sse.max_duration_ms' => 1000, 'fancy-chat.sse.heartbeat_ms' => 300, 'fancy-chat.sse.interval_ms' => 100]);
    bindHost()->backend(replyBackend())->sleeper(fn (int $ms) => tick($ms));
    $sse = http('GET', '/fancy-chat/threads/t_1/events', null, 'human:u_1');
    expect(substr_count($sse['raw'], ": heartbeat\n\n"))->toBe(3);
});

it('streams events appended while the connection is open', function (): void {
    chatApp(['fancy-chat.sse.max_duration_ms' => 500, 'fancy-chat.sse.interval_ms' => 100, 'fancy-chat.sse.heartbeat_ms' => 100000]);
    $appended = false;
    bindHost()->sleeper(function (int $ms) use (&$appended): void {
        tick($ms);
        if (! $appended) {
            $appended = true;
            chat()->append('t_1', ['type' => 'usage', 'data' => ['tokens' => 5]]);
        }
    });
    $sse = http('GET', '/fancy-chat/threads/t_1/events', null, 'human:u_1');
    preg_match_all('/^id: (\d+)$/m', $sse['raw'], $ids);
    expect($ids[1])->toBe(['1']);
});

it('replays a thread older than retention as thread.reset plus the rest, to the same projection', function (string $store): void {
    chatApp(['fancy-chat.store' => $store, 'fancy-chat.retention.events' => 4]);
    bindHost()->backend(replyBackend());
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('one'), 'human:u_1');
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('two'), 'human:u_1');

    $events = pollAll('t_1');
    expect($events[0]['type'])->toBe('thread.reset');
    $resetSeq = $events[0]['seq'];
    expect(array_column(array_slice($events, 1), 'seq'))->toBe(range($resetSeq + 1, $resetSeq + count($events) - 1));

    $client = Projection::project($events);
    $server = http('GET', '/fancy-chat/threads/t_1/snapshot', null, 'human:u_1');
    expect($server['status'])->toBe(200);
    expect(canon(Projection::rows($client['state'])))->toBe(canon(Projection::rows($server['body']['snapshot'])))
        ->and($client['lastSeq'])->toBe($server['body']['seq']);

    $old = http('GET', '/fancy-chat/threads/t_1/snapshot?at=1', null, 'human:u_1');
    expect($old['status'])->toBe(410)->and($old['body'])->toBe(['error' => 'snapshot.gone']);
})->with('stores');

it('serves a snapshot at a seq', function (): void {
    chatApp();
    bindHost()->backend(replyBackend());
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');
    $at = http('GET', '/fancy-chat/threads/t_1/snapshot?at=2', null, 'human:u_1');
    expect($at['body']['seq'])->toBe(2)->and($at['body']['snapshot']['lastSeq'])->toBe(2)
        ->and($at['body']['snapshot']['turns'][0]['state'])->toBe('pending');
    expect(http('GET', '/fancy-chat/threads/t_1/snapshot?at=999', null, 'human:u_1')['status'])->toBe(422);
});

it('encodes every empty map as a JSON object on the wire', function (): void {
    chatApp();
    bindHost()->backend(replyBackend());
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');
    chat()->append('t_1', ['type' => 'usage', 'data' => []]);

    $raw = http('GET', '/fancy-chat/threads/t_1/events/poll?after=0&wait=0', null, 'human:u_1')['raw'];
    expect($raw)->toContain('"args":{}')
        ->and($raw)->toContain('"data":{}')
        ->and($raw)->not->toContain('"args":[]')
        ->and($raw)->not->toContain('"data":[]');

    $snap = http('GET', '/fancy-chat/threads/t_1/snapshot', null, 'human:u_1')['raw'];
    expect($snap)->toContain('"args":{}')->and($snap)->toContain('"order":[');
});

it('validates human parts as untrusted: no HITL parts, no raw charts', function (): void {
    chatApp();
    bindHost()->backend(replyBackend());
    $approval = http('POST', '/fancy-chat/threads/t_1/messages', ['parts' => [['id' => 'p', 'kind' => 'approval', 'approvalId' => 'ap_1']]], 'human:u_1');
    expect($approval['status'])->toBe(422)->and($approval['body'])->toBe(['error' => 'part.hitl.origin']);
    $chart = http('POST', '/fancy-chat/threads/t_1/messages', ['parts' => [['id' => 'p', 'kind' => 'chart', 'echarts' => ['series' => []]]]], 'human:u_1');
    expect($chart['body'])->toBe(['error' => 'part.chart.raw']);
    expect(http('POST', '/fancy-chat/threads/t_1/messages', ['parts' => 'nope'], 'human:u_1')['body'])->toBe(['error' => 'message.parts']);
    expect(pollAll('t_1'))->toBe([]);
});

it('honours the configured part limits', function (): void {
    chatApp(['fancy-chat.limits.parts_per_message' => 1, 'fancy-chat.limits.part_bytes' => 200]);
    bindHost()->backend(replyBackend());
    $two = ['parts' => [['id' => 'a', 'kind' => 'text', 'format' => 'plain', 'text' => 'x'], ['id' => 'b', 'kind' => 'text', 'format' => 'plain', 'text' => 'y']]];
    expect(http('POST', '/fancy-chat/threads/t_1/messages', $two, 'human:u_1')['body'])->toBe(['error' => 'message.parts.count']);
    expect(http('POST', '/fancy-chat/threads/t_1/messages', textMessage(str_repeat('x', 300)), 'human:u_1')['body'])->toBe(['error' => 'part.size']);
});

it('refuses an event larger than the event cap', function (): void {
    chatApp(['fancy-chat.limits.event_bytes' => 512]);
    bindHost();
    expect(fn () => chat()->append('t_1', ['type' => 'usage', 'data' => ['x' => str_repeat('y', 600)]]))
        ->toThrow(FancyChat\ChatException::class, 'event.size');
    expect(chat()->store()->lastSeq('t_1'))->toBe(0);
});

it('marks an edit-and-resend as superseding, and refuses an unknown target', function (): void {
    chatApp();
    bindHost()->backend(replyBackend());
    $first = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('draft'), 'human:u_1');
    $second = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('fixed') + ['supersedes' => $first['body']['messageId']], 'human:u_1');
    expect($second['status'])->toBe(201);
    $rows = Projection::project(pollAll('t_1'))['rows'];
    $byId = array_column(array_filter($rows, fn ($r) => $r['kind'] === 'message'), null, 'id');
    expect($byId[$first['body']['messageId']]['superseded'])->toBeTrue()
        ->and($byId[$second['body']['messageId']]['supersedes'])->toBe($first['body']['messageId']);
    expect(http('POST', '/fancy-chat/threads/t_1/messages', textMessage('x') + ['supersedes' => 'm_nope'], 'human:u_1')['body'])
        ->toBe(['error' => 'message.supersedes']);
});

it('refuses events a backend may not emit, and records the refusal', function (): void {
    chatApp();
    bindHost()->backend(new ScriptedBackend(function (): iterable {
        yield ['type' => 'approval.decided', 'actor' => ['kind' => 'human', 'id' => 'u_1'], 'data' => ['approvalId' => 'x', 'decision' => 'approve', 'decidedBy' => ['kind' => 'human', 'id' => 'u_1']]];
        yield ['type' => 'message.created', 'data' => ['message' => ['id' => 'a', 'role' => 'assistant', 'parts' => [['id' => 'p', 'kind' => 'chart', 'echarts' => []]]]]];
        yield ['type' => 'message.created', 'actor' => ['kind' => 'human', 'id' => 'u_1'], 'data' => ['message' => ['id' => 'b', 'role' => 'user', 'parts' => []]]];
        yield ['type' => 'nonsense.type'];
    }));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');
    $events = pollAll('t_1');
    $refused = array_values(array_filter($events, fn ($e) => $e['type'] === 'x-fancy-chat.refused'));
    expect(array_column(array_column($refused, 'data'), 'error'))->toBe(['backend.type', 'part.chart.raw', 'backend.actor', 'backend.type']);
    expect(array_filter($events, fn ($e) => in_array($e['type'], ['approval.decided', 'message.created'], true) && ($e['data']['message']['id'] ?? '') !== ''))
        ->toHaveCount(1);
});

it('settles a turn as failed when the backend throws', function (): void {
    chatApp();
    bindHost()->backend(new ScriptedBackend(function (): iterable {
        yield ['type' => 'usage', 'data' => []];
        throw new RuntimeException('provider down with a secret token sk-123');
    }));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');
    $events = pollAll('t_1');
    $turn = Projection::project($events)['turns'][0];
    expect($turn)->toMatchArray(['turnId' => $turn['turnId'], 'state' => 'settled', 'outcome' => 'failed', 'reason' => 'backend.error']);
    expect(json_encode($events))->not->toContain('sk-123');
});
