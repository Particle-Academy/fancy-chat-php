<?php

declare(strict_types=1);

use FancyChat\ChatException;

it('assigns a gapless per-thread seq and validates the envelope', function (string $store): void {
    chatApp(['fancy-chat.store' => $store]);
    bindHost();
    foreach (range(1, 5) as $n) {
        chat()->append('t_a', ['type' => 'usage', 'data' => ['n' => $n]]);
        chat()->append('t_b', ['type' => 'usage', 'data' => ['n' => $n]]);
    }
    expect(array_column(chat()->store()->after('t_a', 0, 100), 'seq'))->toBe([1, 2, 3, 4, 5])
        ->and(array_column(chat()->store()->after('t_b', 2, 100), 'seq'))->toBe([3, 4, 5])
        ->and(array_column(chat()->store()->after('t_b', 0, 2), 'seq'))->toBe([1, 2]);
    $first = chat()->store()->after('t_a', 0, 1)[0];
    expect($first['v'])->toBe(1)->and($first['threadId'])->toBe('t_a')->and($first['at'])->toBe('2025-11-15T09:46:40.000Z')
        ->and($first['actor'])->toBe(['kind' => 'system', 'id' => 'fancy-chat']);
    expect(fn () => chat()->append('t_a', ['type' => 'not.a.type', 'data' => []]))->toThrow(ChatException::class, 'event.type');
    expect(chat()->store()->lastSeq('t_a'))->toBe(5);
})->with('stores');

it('never lets a caller choose the seq or the thread', function (string $store): void {
    chatApp(['fancy-chat.store' => $store]);
    bindHost();
    $e = chat()->append('t_a', ['type' => 'usage', 'seq' => 99, 'threadId' => 't_other', 'v' => 7, 'data' => []]);
    expect($e['seq'])->toBe(1)->and($e['threadId'])->toBe('t_a')->and($e['v'])->toBe(1);
    expect(chat()->store()->lastSeq('t_other'))->toBe(0);
})->with('stores');

it('folds pruned events into a checkpoint the snapshot still sees', function (string $store): void {
    chatApp(['fancy-chat.store' => $store, 'fancy-chat.retention.events' => 3]);
    bindHost();
    chat()->append('t_a', ['type' => 'turn.started', 'data' => ['turnId' => 'u_1']]);
    foreach (range(1, 9) as $n) {
        chat()->append('t_a', ['type' => 'usage', 'data' => ['n' => $n]]);
    }
    $checkpoint = chat()->store()->checkpoint('t_a');
    expect($checkpoint['seq'])->toBeGreaterThan(0)
        ->and($checkpoint['state']['turns'][0]['turnId'])->toBe('u_1')
        ->and(chat()->store()->after('t_a', 0, 100)[0]['seq'])->toBe($checkpoint['seq'] + 1)
        ->and(count(chat()->store()->after('t_a', 0, 100)))->toBeLessThanOrEqual(4);
    expect(chat()->snapshot('t_a')['turns'][0]['state'])->toBe('pending')->and(chat()->snapshot('t_a')['lastSeq'])->toBe(10);
})->with('stores');

it('keeps the seq gapless under concurrent appends from separate processes', function (): void {
    $db = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-chat-concurrency-'.getmypid().'.sqlite';
    foreach ([$db, $db.'-wal', $db.'-shm'] as $f) {
        if (file_exists($f)) {
            unlink($f);
        }
    }
    touch($db);
    chatApp(['fancy-chat.store' => 'database'], $db);

    $worker = dirname(__DIR__).'/Support/append-worker.php';
    $procs = [];
    foreach (range(1, 4) as $n) {
        $procs[] = proc_open([PHP_BINARY, $worker, $db, 't_race', '50'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $outputs[] = $pipes;
    }
    $codes = [];
    foreach ($procs as $i => $proc) {
        $out = stream_get_contents($outputs[$i][1]).stream_get_contents($outputs[$i][2]);
        $codes[] = proc_close($proc);
        expect($out)->not->toContain('Error');
    }
    expect($codes)->toBe([0, 0, 0, 0]);

    $seqs = array_column(chat()->store()->after('t_race', 0, 1000), 'seq');
    expect($seqs)->toBe(range(1, 200))->and(chat()->store()->lastSeq('t_race'))->toBe(200);
    app('db')->disconnect();
    foreach ([$db, $db.'-wal', $db.'-shm'] as $f) {
        if (file_exists($f)) {
            unlink($f);
        }
    }
});
