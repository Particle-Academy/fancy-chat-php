<?php

declare(strict_types=1);

/*
 * Smoke: the e2e router boots under `php -S` and serves the real routes
 * against a SQLite file, across separate requests.
 */

function e2ePort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($server, false);
    fclose($server);

    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

/** @return array{status: int, body: mixed} */
function e2eRequest(int $port, string $method, string $path, ?array $body = null, ?string $user = 'u_1'): array
{
    $headers = "Content-Type: application/json\r\nAccept: application/json\r\n".($user !== null ? "X-E2E-User: {$user}\r\n" : '');
    $context = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'content' => $body === null ? '' : json_encode($body), 'ignore_errors' => true, 'timeout' => 10]]);
    $raw = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m) === 1) {
            $status = (int) $m[1];
        }
    }

    return ['status' => $status, 'body' => is_string($raw) ? json_decode($raw, true) : null];
}

it('boots the e2e router under php -S and serves the real routes from a SQLite file', function (): void {
    $db = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-chat-e2e-smoke-'.bin2hex(random_bytes(4)).'.sqlite';
    $port = e2ePort();
    $router = dirname(__DIR__).DIRECTORY_SEPARATOR.'e2e'.DIRECTORY_SEPARATOR.'serve.php';
    $env = array_merge(getenv(), ['FANCY_CHAT_E2E_DB' => $db, 'FANCY_CHAT_E2E_POLL_MS' => '200']);
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-chat-e2e-smoke.log', 'a'], 2 => ['file', sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-chat-e2e-smoke.log', 'a']], $pipes, null, $env);
    expect($proc)->not->toBeFalse();
    try {
        $up = false;
        // A refused connect while the server starts is expected; it is not a warning.
        set_error_handler(fn (): bool => true);
        try {
            for ($i = 0; $i < 100 && ! $up; $i++) {
                $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    $up = true;
                } else {
                    usleep(50_000);
                }
            }
        } finally {
            restore_error_handler();
        }
        expect($up)->toBeTrue();

        expect(e2eRequest($port, 'GET', '/fancy-chat/threads/t_e2e/events/poll?after=0&wait=0', null, null)['status'])->toBe(401);

        $sent = e2eRequest($port, 'POST', '/fancy-chat/threads/t_e2e/messages', ['parts' => [['id' => 'p1', 'kind' => 'text', 'format' => 'plain', 'text' => 'email the team']]]);
        expect($sent['status'])->toBe(201)->and($sent['body']['delivery'])->toBe('sent');

        // A separate request reads what the first one wrote: the state lives in the file.
        $poll = e2eRequest($port, 'GET', '/fancy-chat/threads/t_e2e/events/poll?after=0&wait=0');
        expect($poll['status'])->toBe(200);
        $types = array_column($poll['body']['events'], 'type');
        expect($types)->toContain('approval.requested')->and($types)->toContain('part.delta');
        $approvalId = collect($poll['body']['events'])->firstWhere('type', 'approval.requested')['data']['approval']['approvalId'];

        $decided = e2eRequest($port, 'POST', "/fancy-chat/approvals/{$approvalId}/decision", ['optionId' => 'yes']);
        expect($decided['status'])->toBe(200)->and($decided['body']['approval']['state'])->toBe('applied');

        $after = e2eRequest($port, 'GET', '/fancy-chat/threads/t_e2e/events/poll?after='.$poll['body']['next'].'&wait=0');
        $types = array_column($after['body']['events'], 'type');
        expect($types)->toContain('undo.opened')->and($types)->toContain('turn.settled');
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        foreach ([$db, $db.'-wal', $db.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});
