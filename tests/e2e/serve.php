<?php

declare(strict_types=1);

/*
 * An end-to-end server for driving a real client (fancy-chat-ui's transport)
 * against the real routes. NOT for production: every request is a human named
 * by a header, and every thread is allowed.
 *
 *     FANCY_CHAT_E2E_DB=/tmp/fancy-chat-e2e.sqlite php -S 127.0.0.1:8765 tests/e2e/serve.php
 *
 * Environment:
 *   FANCY_CHAT_E2E_DB        SQLite FILE (required). Created and migrated on first use, so state
 *                            survives across requests.
 *   FANCY_CHAT_E2E_SSE_MS    SSE max duration per connection (default 1500). Keep it short:
 *                            `php -S` serves one request at a time, and Windows has no
 *                            PHP_CLI_SERVER_WORKERS.
 *   FANCY_CHAT_E2E_POLL_MS   long-poll max wait (default 1500).
 *   FANCY_CHAT_E2E_TYPED     "1" turns typed approval on, with the English phrases.
 *   FANCY_CHAT_E2E_UNDO_MS   undo window opened when the approval's write applies (default 30000).
 *
 * Header: X-E2E-User: <id>  -> the actor {kind: human, id}. Without it, 401 chat.actor.
 *
 * Each human message starts a sync turn that streams: thinking, one tool call
 * and result, an assistant text part in three deltas, then an approval
 * (`ap_<turnId>`, options yes/allow-once, no/reject-once, staged write
 * `e2e-send`) and parks. Approving applies the write, which opens an undo
 * window (kind `e2e-send`); the turn then resumes and says "Sent."
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use FancyChat\Backend\NullBackend;
use FancyChat\Backend\TurnInput;
use FancyChat\Contract\TypedApproval;
use FancyChat\FancyChat;
use FancyChat\StagedWrite;
use FancyChat\Tests\Support\TestApp;
use FancyChat\Undoable;
use Illuminate\Http\Request;

$db = getenv('FANCY_CHAT_E2E_DB');
if (! is_string($db) || $db === '') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":"e2e.db"}';

    return true;
}
if (! is_file($db)) {
    touch($db);
}
$int = fn (string $name, int $default): int => is_numeric(getenv($name)) ? (int) getenv($name) : $default;

$app = TestApp::boot([
    'fancy-chat.sse.max_duration_ms' => $int('FANCY_CHAT_E2E_SSE_MS', 1500),
    'fancy-chat.sse.heartbeat_ms' => 500,
    'fancy-chat.sse.interval_ms' => 50,
    'fancy-chat.poll.max_wait_ms' => $int('FANCY_CHAT_E2E_POLL_MS', 1500),
    'fancy-chat.poll.interval_ms' => 50,
    'fancy-chat.typed_approval' => getenv('FANCY_CHAT_E2E_TYPED') === '1'
        ? ['enabled' => true, 'locale' => 'en', 'phrases' => ['en' => TypedApproval::ENGLISH]]
        : ['enabled' => false, 'locale' => 'en', 'phrases' => []],
], $db);

/** @var FancyChat $chat */
$chat = $app->make(FancyChat::class);
$undoMs = $int('FANCY_CHAT_E2E_UNDO_MS', 30000);

$chat->actor(function (Request $request): ?array {
    $who = $request->header('X-E2E-User');

    return is_string($who) && $who !== '' ? ['kind' => 'human', 'id' => $who] : null;
})
    ->access(fn (): bool => true)
    ->undoable('e2e-send', new class implements Undoable
    {
        public function commit(string $actionId, string $threadId): void {}

        public function revert(string $actionId, string $threadId): void {}
    })
    ->stagedWrite('e2e-send', new class($chat, $undoMs) implements StagedWrite
    {
        public function __construct(private FancyChat $chat, private int $undoMs) {}

        public function preview(array $proposal): array
        {
            return [['id' => 'pv', 'kind' => 'text', 'format' => 'plain', 'text' => 'Send to '.($proposal['to'] ?? 'the team')]];
        }

        public function apply(array $approval, string $decision, mixed $edited = null): array
        {
            $threadId = $this->chat->store()->locate($approval['approvalId']);
            if ($threadId !== null) {
                $this->chat->openUndo($threadId, 'e2e-send', 'Email sent', $this->undoMs);
            }

            return ['ok' => true];
        }
    })
    ->backend(new NullBackend(function (TurnInput $input): iterable {
        $run = 'r_'.$input->turnId;
        if ($input->kind === 'resume') {
            $state = $input->approval['state'] ?? null;
            yield ['type' => 'message.created', 'runId' => $run, 'data' => ['message' => ['id' => 'done_'.$input->turnId, 'role' => 'assistant', 'parts' => [
                ['id' => 'p', 'kind' => 'text', 'format' => 'plain', 'text' => $state === 'applied' ? 'Sent.' : 'Not sent.'],
            ]]]];

            return;
        }
        yield ['type' => 'thinking.started', 'runId' => $run];
        yield ['type' => 'thinking.delta', 'runId' => $run, 'data' => ['text' => 'Find the list.']];
        yield ['type' => 'thinking.completed', 'runId' => $run];
        yield ['type' => 'tool.called', 'runId' => $run, 'data' => ['toolCallId' => 'tc_'.$input->turnId, 'name' => 'search', 'args' => ['q' => 'team']]];
        yield ['type' => 'tool.result', 'runId' => $run, 'data' => ['toolCallId' => 'tc_'.$input->turnId, 'outcome' => 'ok', 'durationMs' => 7]];
        yield ['type' => 'message.created', 'runId' => $run, 'data' => ['message' => ['id' => 'a_'.$input->turnId, 'role' => 'assistant', 'parts' => []]]];
        yield ['type' => 'part.started', 'runId' => $run, 'data' => ['messageId' => 'a_'.$input->turnId, 'part' => ['id' => 'p', 'kind' => 'text', 'format' => 'markdown', 'text' => 'Ready ']]];
        yield ['type' => 'part.delta', 'runId' => $run, 'data' => ['messageId' => 'a_'.$input->turnId, 'partId' => 'p', 'text' => 'to **send**']];
        yield ['type' => 'part.delta', 'runId' => $run, 'data' => ['messageId' => 'a_'.$input->turnId, 'partId' => 'p', 'text' => '.']];
        yield ['type' => 'part.completed', 'runId' => $run, 'data' => ['messageId' => 'a_'.$input->turnId, 'partId' => 'p']];
        yield ['type' => 'approval.requested', 'runId' => $run, 'data' => ['approval' => [
            'approvalId' => 'ap_'.$input->turnId,
            'title' => 'Send the email?',
            'summary' => 'To the team',
            'stagedWrite' => 'e2e-send',
            'proposal' => ['to' => 'the team'],
            'options' => [['id' => 'yes', 'kind' => 'allow-once', 'label' => 'Send'], ['id' => 'no', 'kind' => 'reject-once', 'label' => 'Cancel']],
        ]]];
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }));

$request = Request::capture();
$app->instance('request', $request);
$response = $app['router']->dispatch($request);
$response->send();

return true;
