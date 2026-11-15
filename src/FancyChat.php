<?php

declare(strict_types=1);

namespace FancyChat;

use Closure;
use FancyChat\Backend\AgentBackend;
use FancyChat\Backend\CancelOutcome;
use FancyChat\Backend\TurnInput;
use FancyChat\Contract\Envelope;
use FancyChat\Contract\Hitl;
use FancyChat\Contract\Json;
use FancyChat\Contract\Machines;
use FancyChat\Contract\Parts;
use FancyChat\Contract\Projection;
use FancyChat\Jobs\RunTurn;
use FancyChat\Store\ThreadEventStore;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Throwable;

/**
 * The chat server: one thread log per conversation, replayed to any client
 * from a cursor, with turns driven by a host-bound AgentBackend.
 *
 * Nothing works until the host binds `actor()` and `access()` (default deny),
 * and no user model is assumed: an actor is `{kind, id}` from the host's own
 * resolver. Decisions and answers accept only a human actor, and the body of
 * a request can never choose the actor.
 */
final class FancyChat
{
    private const string THREAD_ID = '/^[A-Za-z0-9._:-]{1,128}$/';

    /** Event types a backend may never emit: they are the server's or the human's. */
    private const array BACKEND_FORBIDDEN = [
        'thread.snapshot', 'thread.reset', 'turn.started', 'delivery.changed',
        'approval.decided', 'approval.applied', 'approval.withdrawn', 'question.answered',
    ];

    private ?Closure $actorResolver = null;

    private ?Closure $accessResolver = null;

    private ?AgentBackend $backend = null;

    private ?Closure $clock = null;

    private ?Closure $sleeper = null;

    /** @var array<string, StagedWrite> */
    private array $stagedWrites = [];

    public function __construct(private Container $app, private ThreadEventStore $store, private Repository $config) {}

    // ---------------------------------------------------------------- binding

    /** @param  callable(Request): (array{kind: string, id: string}|null)  $resolver */
    public function actor(callable $resolver): self
    {
        $this->actorResolver = Closure::fromCallable($resolver);

        return $this;
    }

    /** @param  callable(Request, string $threadId, string $ability): bool  $resolver  abilities: read, write, cancel, decide */
    public function access(callable $resolver): self
    {
        $this->accessResolver = Closure::fromCallable($resolver);

        return $this;
    }

    public function backend(AgentBackend $backend): self
    {
        $this->backend = $backend;

        return $this;
    }

    public function stagedWrite(string $name, StagedWrite $write): self
    {
        $this->stagedWrites[$name] = $write;

        return $this;
    }

    /** @param  callable(): int  $clock  milliseconds since the epoch */
    public function clock(callable $clock): self
    {
        $this->clock = Closure::fromCallable($clock);

        return $this;
    }

    /** @param  callable(int $ms): void  $sleeper */
    public function sleeper(callable $sleeper): self
    {
        $this->sleeper = Closure::fromCallable($sleeper);

        return $this;
    }

    public function store(): ThreadEventStore
    {
        return $this->store;
    }

    public function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : (int) floor(microtime(true) * 1000);
    }

    public function sleep(int $ms): void
    {
        if ($ms <= 0) {
            return;
        }
        if ($this->sleeper !== null) {
            ($this->sleeper)($ms);
        } else {
            usleep($ms * 1000);
        }
    }

    /** Logs through the host's exception handler when there is one; never into the thread. */
    private function report(Throwable $e): void
    {
        if ($this->app->bound(\Illuminate\Contracts\Debug\ExceptionHandler::class)) {
            $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->report($e);
        }
    }

    private function cfg(string $key, mixed $default = null): mixed
    {
        return $this->config->get('fancy-chat.'.$key, $default);
    }

    private function iso(int $ms): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)).sprintf('.%03dZ', $ms % 1000);
    }

    // ------------------------------------------------------------- the log

    /**
     * Appends one event. The store assigns `seq`; `v` and `threadId` are
     * always the server's, whatever the caller passed.
     *
     * @param  array<string, mixed>  $partial  type, data, actor?, runId?, turnId?, ...
     * @return array<string, mixed>
     */
    public function append(string $threadId, array $partial): array
    {
        $this->assertThreadId($threadId);
        $event = [
            'v' => 1,
            'seq' => 1,
            'threadId' => $threadId,
            'at' => $this->iso($this->now()),
            'type' => $partial['type'] ?? null,
            'actor' => $partial['actor'] ?? ['kind' => 'system', 'id' => 'fancy-chat'],
            'data' => $partial['data'] ?? [],
        ];
        foreach (['runId', 'turnId', 'parentRunId', 'rootRunId'] as $key) {
            if (array_key_exists($key, $partial)) {
                $event[$key] = $partial[$key];
            }
        }
        $valid = Envelope::validate($event);
        if (! $valid['ok']) {
            throw new ChatException($valid['error'], 422);
        }
        if (Json::bytes($event) > (int) $this->cfg('limits.event_bytes', 262144)) {
            throw new ChatException('event.size', 413);
        }

        $stored = $this->store->append($threadId, $event, $this->now());

        if ($stored['type'] === 'approval.requested') {
            $this->store->index($stored['data']['approval']['approvalId'], $threadId, 'approval', 'pending');
        } elseif ($stored['type'] === 'question.asked') {
            $this->store->index($stored['data']['question']['questionId'], $threadId, 'question', 'open');
        }

        $keep = (int) $this->cfg('retention.events', 10000);
        if ($keep > 0 && $stored['seq'] > $keep + max(1, intdiv($keep, 10))) {
            $checkpoint = $this->store->checkpoint($threadId);
            if ($stored['seq'] - $checkpoint['seq'] > $keep + max(1, intdiv($keep, 10))) {
                $this->store->prune($threadId, $keep);
            }
        }

        $this->nudge($threadId, $stored['seq']);

        return $stored;
    }

    private function nudge(string $threadId, int $seq): void
    {
        if (! $this->cfg('nudge.enabled', false)) {
            return;
        }
        try {
            $channel = str_replace('{threadId}', $threadId, (string) $this->cfg('nudge.channel', 'private-fancy-chat.{threadId}'));
            $this->app->make('Illuminate\Broadcasting\BroadcastManager')
                ->connection($this->cfg('nudge.connection'))
                ->broadcast([$channel], (string) $this->cfg('nudge.event', 'fancy-chat.seq'), ['threadId' => $threadId, 'seq' => $seq]);
        } catch (Throwable) {
            // A nudge is a hint. A missed one costs nothing: clients resume from their cursor.
        }
    }

    /**
     * The projected thread at `$at` (default: now), from the checkpoint plus
     * the retained events.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $threadId, ?int $at = null): array
    {
        $checkpoint = $this->store->checkpoint($threadId);
        $last = $this->store->lastSeq($threadId);
        if ($at !== null && $at < $checkpoint['seq']) {
            throw new ChatException('snapshot.gone', 410);
        }
        if ($at !== null && $at > $last) {
            throw new ChatException('snapshot.at', 422);
        }
        $upTo = $at ?? $last;
        $state = $checkpoint['state'];
        $cursor = $checkpoint['seq'];
        while ($cursor < $upTo) {
            $page = $this->store->after($threadId, $cursor, 1000);
            if ($page === []) {
                break;
            }
            foreach ($page as $event) {
                if ($event['seq'] > $upTo) {
                    break 2;
                }
                Projection::apply($state, $event);
                $cursor = $event['seq'];
            }
        }

        return $state;
    }

    /**
     * Events after a cursor. A cursor older than retention gets a synthesized
     * `thread.reset` carrying the checkpoint, then the retained events.
     *
     * @return list<array<string, mixed>>
     */
    public function eventsAfter(string $threadId, int $after, int $limit): array
    {
        $checkpoint = $this->store->checkpoint($threadId);
        if ($checkpoint['seq'] > 0 && $after < $checkpoint['seq']) {
            $reset = [
                'v' => 1,
                'seq' => $checkpoint['seq'],
                'threadId' => $threadId,
                'at' => $this->iso($this->now()),
                'type' => 'thread.reset',
                'actor' => ['kind' => 'system', 'id' => 'fancy-chat'],
                'data' => ['snapshot' => $checkpoint['state']],
            ];

            return [$reset, ...$this->store->after($threadId, $checkpoint['seq'], max(0, $limit - 1))];
        }

        return $this->store->after($threadId, $after, $limit);
    }

    /**
     * Long-poll: waits up to `$waitMs` (clamped to poll.max_wait_ms) for events after the cursor.
     *
     * @return array{events: list<array<string, mixed>>, next: int}
     */
    public function poll(string $threadId, int $after, int $waitMs): array
    {
        $limit = max(1, (int) $this->cfg('poll.limit', 500));
        $wait = max(0, min($waitMs, (int) $this->cfg('poll.max_wait_ms', 30000)));
        $interval = max(1, (int) $this->cfg('poll.interval_ms', 250));
        $start = $this->now();
        while (true) {
            $events = $this->eventsAfter($threadId, $after, $limit);
            $elapsed = $this->now() - $start;
            if ($events !== [] || $elapsed >= $wait) {
                break;
            }
            $this->sleep(min($interval, $wait - $elapsed));
        }

        return ['events' => $events, 'next' => $events === [] ? $after : (int) end($events)['seq']];
    }

    /**
     * Writes the SSE stream: replay from the cursor, then live until
     * sse.max_duration_ms, with heartbeat comments while idle. Always returns.
     */
    public function stream(string $threadId, int $after): void
    {
        $level = ob_get_level();
        $write = function (string $chunk) use ($level): void {
            echo $chunk;
            if (ob_get_level() > $level) {
                ob_flush();
            }
            flush();
        };
        $limit = max(1, (int) $this->cfg('poll.limit', 500));
        $max = max(0, (int) $this->cfg('sse.max_duration_ms', 55000));
        $interval = max(1, (int) $this->cfg('sse.interval_ms', 250));
        $heartbeat = max(1, (int) $this->cfg('sse.heartbeat_ms', 15000));

        $emit = function () use (&$after, $threadId, $limit, $write): bool {
            $sent = false;
            while (true) {
                $events = $this->eventsAfter($threadId, $after, $limit);
                if ($events === []) {
                    return $sent;
                }
                foreach ($events as $event) {
                    $write('id: '.$event['seq']."\ndata: ".Json::encode($event)."\n\n");
                    $after = (int) $event['seq'];
                }
                $sent = true;
            }
        };

        $write('retry: '.(int) $this->cfg('sse.retry_ms', 2000)."\n\n");
        $start = $this->now();
        $lastWrite = $start;
        if ($emit()) {
            $lastWrite = $this->now();
        }
        while (! connection_aborted()) {
            $elapsed = $this->now() - $start;
            if ($elapsed >= $max) {
                break;
            }
            $this->sleep(min($interval, $max - $elapsed));
            if ($emit()) {
                $lastWrite = $this->now();
            } elseif ($this->now() - $lastWrite >= $heartbeat) {
                $write(": heartbeat\n\n");
                $lastWrite = $this->now();
            }
        }
    }

    // ------------------------------------------------------------ the host

    /** @return array{kind: string, id: string} */
    public function resolveActor(Request $request): array
    {
        if ($this->actorResolver === null || $this->accessResolver === null) {
            throw new ChatException('chat.unconfigured', 403);
        }
        $actor = ($this->actorResolver)($request);
        if (! is_array($actor) || ! is_string($actor['kind'] ?? null) || ! is_string($actor['id'] ?? null) || $actor['id'] === '') {
            throw new ChatException('chat.actor', 401);
        }

        return ['kind' => $actor['kind'], 'id' => $actor['id']];
    }

    public function authorize(Request $request, string $threadId, string $ability): void
    {
        $this->assertThreadId($threadId);
        if ($this->accessResolver === null || ($this->accessResolver)($request, $threadId, $ability) !== true) {
            throw new ChatException('chat.access', 403);
        }
    }

    /** @param  array{kind: string, id: string}  $actor */
    private function requireHuman(array $actor): void
    {
        if (! Hitl::canDecide($actor)) {
            throw new ChatException('chat.actor.human', 403);
        }
    }

    public function assertThreadId(string $threadId): void
    {
        if (preg_match(self::THREAD_ID, $threadId) !== 1) {
            throw new ChatException('thread.id', 422);
        }
    }

    /** @return array{maxPartBytes: int, maxDepth: int, maxParts: int} */
    private function partLimits(): array
    {
        return [
            'maxPartBytes' => (int) $this->cfg('limits.part_bytes', 65536),
            'maxDepth' => (int) $this->cfg('limits.part_depth', 3),
            'maxParts' => (int) $this->cfg('limits.parts_per_message', 64),
        ];
    }

    // --------------------------------------------------------- the requests

    /**
     * A human sends a message. Parts are validated as untrusted (`agent`
     * origin). Mid-turn it queues; otherwise it starts a turn.
     *
     * @param  array<string, mixed>  $body  parts, supersedes?
     * @return array{messageId: string, seq: int, delivery: string}
     */
    public function send(Request $request, string $threadId, array $body): array
    {
        $actor = $this->resolveActor($request);
        $this->authorize($request, $threadId, 'write');
        $this->requireHuman($actor);
        if ($this->backend === null) {
            throw new ChatException('chat.backend', 503);
        }
        $parts = $body['parts'] ?? null;
        $valid = Parts::validateList($parts, 'agent', $this->partLimits());
        if (! $valid['ok']) {
            throw new ChatException($valid['error'], 422);
        }
        $state = $this->snapshot($threadId);
        $supersedes = $body['supersedes'] ?? null;
        if ($supersedes !== null && (! is_string($supersedes) || ! in_array($supersedes, array_column($state['messages'], 'id'), true))) {
            throw new ChatException('message.supersedes', 422);
        }

        $messageId = 'm_'.bin2hex(random_bytes(8));
        $message = ['id' => $messageId, 'role' => 'user', 'parts' => $parts, 'delivery' => 'sent'];
        if ($supersedes !== null) {
            $message['supersedes'] = $supersedes;
        }

        if ($this->activeTurn($state) !== null) {
            $queued = count(array_filter($state['messages'], fn (array $m): bool => $m['delivery'] === 'queued'));
            if ($queued >= (int) $this->cfg('limits.queue_length', 20)) {
                throw new ChatException('queue.full', 429);
            }
            $created = $this->append($threadId, ['type' => 'message.created', 'actor' => $actor, 'data' => ['message' => $message]]);
            $this->append($threadId, ['type' => 'delivery.changed', 'data' => ['messageId' => $messageId, 'action' => 'queue']]);

            return ['messageId' => $messageId, 'seq' => $created['seq'], 'delivery' => 'queued'];
        }

        $created = $this->append($threadId, ['type' => 'message.created', 'actor' => $actor, 'data' => ['message' => $message]]);
        $this->startTurn($threadId, $messageId, $parts);

        return ['messageId' => $messageId, 'seq' => $created['seq'], 'delivery' => 'sent'];
    }

    /** @param  array<string, mixed>  $state */
    private function activeTurn(array $state): ?array
    {
        foreach (array_reverse($state['turns']) as $turn) {
            if ($turn['state'] !== 'settled') {
                return $turn;
            }
        }

        return null;
    }

    /** @param  list<array<string, mixed>>  $parts */
    private function startTurn(string $threadId, string $messageId, array $parts): string
    {
        $turnId = 'u_'.bin2hex(random_bytes(8));
        $this->append($threadId, ['type' => 'turn.started', 'turnId' => $turnId, 'data' => ['turnId' => $turnId]]);
        $this->dispatch(new TurnInput($threadId, $turnId, 'message', $messageId, $parts));

        return $turnId;
    }

    private function dispatch(TurnInput $input): void
    {
        $job = new RunTurn($input->toArray());
        if ($this->cfg('queue.connection') !== null) {
            $job->onConnection($this->cfg('queue.connection'));
        }
        if ($this->cfg('queue.name') !== null) {
            $job->onQueue($this->cfg('queue.name'));
        }
        $this->app->make(Dispatcher::class)->dispatch($job);
    }

    /**
     * Runs one turn on the backend, appending each event it yields after the
     * contract's checks. Called by the RunTurn job.
     */
    public function runTurn(TurnInput $input): void
    {
        $threadId = $input->threadId;
        $turnId = $input->turnId;
        $state = $this->snapshot($threadId);
        $turn = null;
        foreach ($state['turns'] as $t) {
            if ($t['turnId'] === $turnId) {
                $turn = $t;
            }
        }
        if ($turn === null || $this->backend === null) {
            return;
        }
        if ($input->kind === 'message') {
            if ($turn['state'] !== 'pending') {
                return;
            }
            $this->append($threadId, ['type' => 'turn.transition', 'turnId' => $turnId, 'data' => ['turnId' => $turnId, 'action' => 'start']]);
            if ($input->messageId !== null) {
                $this->append($threadId, ['type' => 'delivery.changed', 'turnId' => $turnId, 'data' => ['messageId' => $input->messageId, 'action' => 'deliver']]);
            }
        } elseif ($turn['state'] !== 'running') {
            return;
        }

        $current = 'running';
        try {
            foreach ($this->backend->start($input) as $raw) {
                $this->appendFromBackend($threadId, $turnId, $raw, $current);
                if ($current === 'settled') {
                    break;
                }
            }
            if ($current === 'running') {
                $this->append($threadId, ['type' => 'turn.settled', 'turnId' => $turnId, 'data' => ['turnId' => $turnId, 'action' => 'finish']]);
                $current = 'settled';
            }
        } catch (Throwable $e) {
            // The exception text may carry anything the provider said; it is logged, never stored.
            $this->report($e);
            if ($current === 'running') {
                $this->append($threadId, ['type' => 'turn.settled', 'turnId' => $turnId, 'data' => ['turnId' => $turnId, 'action' => 'fail', 'reason' => 'backend.error']]);
                $current = 'settled';
            }
        }

        if ($current === 'settled') {
            $this->dequeueNext($threadId);
        }
    }

    /** @param  mixed  $raw */
    private function appendFromBackend(string $threadId, string $turnId, mixed $raw, string &$current): void
    {
        $refuse = function (string $error, mixed $type = null) use ($threadId, $turnId): void {
            $this->append($threadId, ['type' => 'x-fancy-chat.refused', 'turnId' => $turnId, 'data' => ['type' => is_string($type) ? $type : null, 'error' => $error]]);
        };
        if (! is_array($raw)) {
            $refuse('backend.shape');

            return;
        }
        $type = $raw['type'] ?? null;
        if (! Envelope::isEventType($type) || in_array($type, self::BACKEND_FORBIDDEN, true)) {
            $refuse('backend.type', $type);

            return;
        }
        $actor = $raw['actor'] ?? ['kind' => 'agent', 'id' => (string) $this->cfg('agent.id', 'agent')];
        if (! is_array($actor) || ($actor['kind'] ?? null) !== 'agent' || ! is_string($actor['id'] ?? null)) {
            $refuse('backend.actor', $type);

            return;
        }
        $data = Json::isObject($raw['data'] ?? []) ? ($raw['data'] ?? []) : null;
        if ($data === null) {
            $refuse('event.data', $type);

            return;
        }

        $error = null;
        switch ($type) {
            case 'turn.transition':
            case 'turn.settled':
                $action = is_string($data['action'] ?? null) ? $data['action'] : '';
                $next = Machines::isSettlingTurnAction($action) === ($type === 'turn.settled') ? Machines::turn($current, $action) : ['ok' => false];
                if (! $next['ok']) {
                    $error = 'turn.illegal';
                    break;
                }
                $data['turnId'] = $turnId;
                break;
            case 'message.created':
                $message = $data['message'] ?? null;
                $v = Json::isObject($message) ? Parts::validateList($message['parts'] ?? [], 'agent', $this->partLimits()) : ['ok' => false, 'error' => 'message.shape'];
                $error = $v['ok'] ? null : $v['error'];
                break;
            case 'message.updated':
                $v = Parts::validateList($data['parts'] ?? null, 'agent', $this->partLimits());
                $error = $v['ok'] ? null : $v['error'];
                break;
            case 'part.started':
                $v = Parts::validate($data['part'] ?? null, 'agent', $this->partLimits());
                $error = $v['ok'] ? null : $v['error'];
                break;
            case 'part.delta':
                if (array_key_exists('part', $data)) {
                    $v = Parts::validate($data['part'], 'agent', $this->partLimits());
                    $error = $v['ok'] ? null : $v['error'];
                }
                break;
            case 'approval.requested':
                [$record, $error] = $this->approvalRecord($data['approval'] ?? null, $turnId, $raw['runId'] ?? null);
                $data['approval'] = $record;
                break;
            case 'question.asked':
                [$record, $error] = $this->questionRecord($data['question'] ?? null, $turnId);
                $data['question'] = $record;
                break;
        }
        if ($error !== null) {
            $refuse($error, $type);

            return;
        }

        $event = ['type' => $type, 'actor' => ['kind' => 'agent', 'id' => $actor['id']], 'turnId' => $turnId, 'data' => $data];
        foreach (['runId', 'parentRunId', 'rootRunId'] as $key) {
            if (array_key_exists($key, $raw)) {
                $event[$key] = $raw[$key];
            }
        }
        try {
            $this->append($threadId, $event);
        } catch (ChatException $e) {
            $refuse($e->error, $type);

            return;
        }
        if ($type === 'turn.transition' || $type === 'turn.settled') {
            $current = Machines::turn($current, $data['action'])['to'] ?? $current;
        }
    }

    /** @return array{0: ?array<string, mixed>, 1: ?string} */
    private function approvalRecord(mixed $a, string $turnId, mixed $runId): array
    {
        if (! Json::isObject($a) || ! is_string($a['approvalId'] ?? null) || $a['approvalId'] === '' || ! is_string($a['title'] ?? null) || ! Json::isList($a['options'] ?? null) || $a['options'] === []) {
            return [null, 'approval.record'];
        }
        foreach ($a['options'] as $option) {
            if (! Json::isObject($option) || ! is_string($option['id'] ?? null) || ! is_string($option['label'] ?? null) || Machines::optionTone((string) ($option['kind'] ?? ''))['decision'] === null) {
                return [null, 'approval.record'];
            }
        }
        if ($this->store->locate($a['approvalId']) !== null) {
            return [null, 'approval.duplicate'];
        }
        $write = is_string($a['stagedWrite'] ?? null) ? ($this->stagedWrites[$a['stagedWrite']] ?? null) : null;
        if ($write !== null) {
            $preview = $write->preview(Json::isObject($a['proposal'] ?? null) ? $a['proposal'] : []);
            $v = Parts::validateList($preview, 'server', $this->partLimits());
        } else {
            $preview = $a['preview'] ?? [];
            $v = Parts::validateList($preview, 'agent', $this->partLimits());
        }
        if (! $v['ok']) {
            return [null, $v['error']];
        }

        return [array_merge($a, [
            'summary' => is_string($a['summary'] ?? null) ? $a['summary'] : '',
            'preview' => $preview,
            'turnId' => $turnId,
            'runId' => is_string($runId) ? $runId : ($a['runId'] ?? null),
            'state' => 'pending',
        ]), null];
    }

    /** @return array{0: ?array<string, mixed>, 1: ?string} */
    private function questionRecord(mixed $q, string $turnId): array
    {
        if (! Json::isObject($q) || ! is_string($q['questionId'] ?? null) || $q['questionId'] === '' || ! is_string($q['prompt'] ?? null) || ! Json::isList($q['fields'] ?? null)) {
            return [null, 'question.record'];
        }
        foreach ($q['fields'] as $field) {
            if (! Json::isObject($field) || ! is_string($field['id'] ?? null) || ! in_array($field['type'] ?? null, ['single', 'multi', 'text'], true) || ! is_bool($field['required'] ?? null)) {
                return [null, 'question.record'];
            }
        }
        if ($this->store->locate($q['questionId']) !== null) {
            return [null, 'question.duplicate'];
        }

        return [array_merge($q, ['allowNote' => ($q['allowNote'] ?? false) === true, 'turnId' => $turnId, 'state' => 'open']), null];
    }

    private function dequeueNext(string $threadId): void
    {
        $state = $this->snapshot($threadId);
        if ($this->activeTurn($state) !== null) {
            return;
        }
        foreach ($state['messages'] as $message) {
            if ($message['delivery'] === 'queued') {
                $this->startTurn($threadId, $message['id'], $message['parts']);

                return;
            }
        }
    }

    /**
     * Records a human decision on an approval, applies its staged write (only
     * for approve or edit, and only once the decision record exists), then
     * resumes the parked turn when nothing else in it is waiting.
     *
     * @param  array<string, mixed>  $body  optionId, note?, edited?
     * @return array{approval: array<string, mixed>, seq: int}
     */
    public function decide(Request $request, string $approvalId, array $body): array
    {
        $actor = $this->resolveActor($request);
        $this->requireHuman($actor);
        $threadId = $this->store->locate($approvalId) ?? throw new ChatException('approval.unknown', 404);
        $this->authorize($request, $threadId, 'decide');
        $record = $this->findRecord($this->snapshot($threadId), 'approvals', 'approvalId', $approvalId) ?? throw new ChatException('approval.unknown', 404);
        if ($record['state'] !== 'pending') {
            throw new ChatException('approval.state', 409);
        }
        $option = null;
        foreach ($record['options'] as $o) {
            if (($o['id'] ?? null) === ($body['optionId'] ?? null)) {
                $option = $o;
            }
        }
        $decision = $option === null ? null : Machines::optionTone((string) $option['kind'])['decision'];
        if ($decision === null) {
            throw new ChatException('approval.option', 422);
        }
        if (! $this->store->claim($approvalId, 'pending', 'deciding')) {
            throw new ChatException('approval.in-flight', 409);
        }

        try {
            $this->append($threadId, ['type' => 'approval.decided', 'actor' => $actor, 'turnId' => $record['turnId'] ?? null, 'data' => [
                'approvalId' => $approvalId,
                'decision' => $decision,
                'decidedBy' => $actor,
                'optionId' => $option['id'],
                'note' => is_string($body['note'] ?? null) ? $body['note'] : null,
            ]]);
        } catch (Throwable $e) {
            $this->store->setHitlState($approvalId, 'pending');

            throw $e;
        }
        $writeName = $record['stagedWrite'] ?? null;
        if ($decision !== 'reject' && is_string($writeName) && isset($this->stagedWrites[$writeName])) {
            $this->applyStagedWrite($threadId, $approvalId, $body['edited'] ?? null);
        }
        $final = $this->findRecord($this->snapshot($threadId), 'approvals', 'approvalId', $approvalId);
        $this->store->setHitlState($approvalId, (string) $final['state']);
        $this->resumeIfReady($threadId, $record['turnId'] ?? null, $final, null);

        return ['approval' => $final, 'seq' => $this->store->lastSeq($threadId)];
    }

    /**
     * Runs an approval's staged write. Refuses unless the projection holds a
     * human decision (approved or edited) for it: `apply` never runs on a
     * proposal alone.
     */
    public function applyStagedWrite(string $threadId, string $approvalId, mixed $edited = null): void
    {
        $record = $this->findRecord($this->snapshot($threadId), 'approvals', 'approvalId', $approvalId);
        if ($record === null || ! in_array($record['state'], ['approved', 'edited'], true) || ! Hitl::canDecide($record['decidedBy'] ?? null)) {
            throw new ChatException('approval.undecided', 409);
        }
        $write = $this->stagedWrites[$record['stagedWrite'] ?? ''] ?? throw new ChatException('approval.staged-write', 409);
        try {
            $result = $write->apply($record, (string) $record['decision'], $edited);
        } catch (Throwable $e) {
            $this->report($e);
            $result = ['ok' => false, 'error' => 'apply.exception'];
        }
        $data = ['approvalId' => $approvalId, 'ok' => ($result['ok'] ?? false) === true];
        if (! $data['ok']) {
            $data['error'] = is_string($result['error'] ?? null) ? $result['error'] : 'apply.failed';
        }
        $this->append($threadId, ['type' => 'approval.applied', 'turnId' => $record['turnId'] ?? null, 'data' => $data]);
    }

    /**
     * A human answers a question. The answer must be complete; the actor comes
     * only from the host resolver.
     *
     * @param  array<string, mixed>  $body  values, notes?
     * @return array{question: array<string, mixed>, seq: int}
     */
    public function answer(Request $request, string $questionId, array $body): array
    {
        $actor = $this->resolveActor($request);
        $this->requireHuman($actor);
        $threadId = $this->store->locate($questionId) ?? throw new ChatException('question.unknown', 404);
        $this->authorize($request, $threadId, 'decide');
        $record = $this->findRecord($this->snapshot($threadId), 'questions', 'questionId', $questionId) ?? throw new ChatException('question.unknown', 404);
        if ($record['state'] !== 'open') {
            throw new ChatException('question.state', 409);
        }
        $draft = ['values' => Json::isObject($body['values'] ?? null) ? $body['values'] : [], 'notes' => Json::isObject($body['notes'] ?? null) ? $body['notes'] : []];
        $check = Hitl::canSubmitAnswer($record, $draft);
        if (! $check['ok']) {
            throw new ChatException($check['error'], 422);
        }
        if (! $this->store->claim($questionId, 'open', 'answering')) {
            throw new ChatException('question.in-flight', 409);
        }
        try {
            $this->append($threadId, ['type' => 'question.answered', 'actor' => $actor, 'turnId' => $record['turnId'] ?? null, 'data' => [
                'questionId' => $questionId,
                'answeredBy' => $actor,
                'values' => $draft['values'],
                'notes' => $draft['notes'],
            ]]);
        } catch (Throwable $e) {
            $this->store->setHitlState($questionId, 'open');

            throw $e;
        }
        $this->store->setHitlState($questionId, 'answered');
        $final = $this->findRecord($this->snapshot($threadId), 'questions', 'questionId', $questionId);
        $this->resumeIfReady($threadId, $record['turnId'] ?? null, null, $final);

        return ['question' => $final, 'seq' => $this->store->lastSeq($threadId)];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private function findRecord(array $state, string $list, string $key, string $id): ?array
    {
        foreach ($state[$list] as $record) {
            if (($record[$key] ?? null) === $id) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $approval
     * @param  array<string, mixed>|null  $question
     */
    private function resumeIfReady(string $threadId, ?string $turnId, ?array $approval, ?array $question): void
    {
        if ($turnId === null) {
            return;
        }
        $state = $this->snapshot($threadId);
        $turn = null;
        foreach ($state['turns'] as $t) {
            if ($t['turnId'] === $turnId) {
                $turn = $t;
            }
        }
        if ($turn === null || $turn['state'] !== 'awaiting-human') {
            return;
        }
        foreach ($state['approvals'] as $a) {
            if (($a['turnId'] ?? null) === $turnId && in_array($a['state'], ['pending', 'deciding'], true)) {
                return;
            }
        }
        foreach ($state['questions'] as $q) {
            if (($q['turnId'] ?? null) === $turnId && $q['state'] === 'open') {
                return;
            }
        }
        $this->append($threadId, ['type' => 'turn.transition', 'turnId' => $turnId, 'data' => ['turnId' => $turnId, 'action' => 'resume']]);
        $this->dispatch(new TurnInput($threadId, $turnId, 'resume', null, [], $approval, $question));
    }

    /**
     * Stops or interrupts the thread's turn. A stop withdraws every approval
     * the turn has parked on.
     *
     * @param  array<string, mixed>  $body  mode: stop | interrupt
     */
    public function cancel(Request $request, string $threadId, array $body): string
    {
        $actor = $this->resolveActor($request);
        $this->authorize($request, $threadId, 'cancel');
        $this->requireHuman($actor);
        $mode = $body['mode'] ?? 'stop';
        if (! in_array($mode, ['stop', 'interrupt'], true)) {
            throw new ChatException('cancel.mode', 422);
        }
        $state = $this->snapshot($threadId);
        if ($state['turns'] === []) {
            return CancelOutcome::NO_TURN;
        }
        $turn = $this->activeTurn($state);
        if ($turn === null) {
            return CancelOutcome::NOT_RUNNING;
        }
        if ($this->backend === null || ($this->backend->capabilities()['cancel'] ?? false) !== true) {
            return CancelOutcome::UNSUPPORTED;
        }
        $turnId = $turn['turnId'];
        if ($turn['state'] !== 'cancelling') {
            $this->append($threadId, ['type' => 'turn.transition', 'turnId' => $turnId, 'actor' => $actor, 'data' => ['turnId' => $turnId, 'action' => 'cancel']]);
        }
        foreach ($state['approvals'] as $a) {
            if (($a['turnId'] ?? null) === $turnId && $a['state'] === 'pending' && $this->store->claim($a['approvalId'], 'pending', 'withdrawn')) {
                $this->append($threadId, ['type' => 'approval.withdrawn', 'turnId' => $turnId, 'data' => ['approvalId' => $a['approvalId']]]);
            }
        }
        $outcome = $this->backend->cancel($threadId, $turnId, $mode, (int) $this->cfg('cancel.confirm_wait_ms', 2000));
        if (! in_array($outcome, CancelOutcome::ALL, true)) {
            $outcome = CancelOutcome::UNCERTAIN;
        }
        if ($outcome === CancelOutcome::OK) {
            $this->append($threadId, ['type' => 'turn.settled', 'turnId' => $turnId, 'data' => ['turnId' => $turnId, 'action' => $mode === 'stop' ? 'cancelled' : 'interrupted']]);
            $this->dequeueNext($threadId);
        }

        return $outcome;
    }

    /**
     * The author takes a queued message back.
     *
     * @return array{messageId: string, delivery: string, seq: int}
     */
    public function unsend(Request $request, string $threadId, string $messageId): array
    {
        $actor = $this->resolveActor($request);
        $this->authorize($request, $threadId, 'write');
        $this->requireHuman($actor);
        $message = $this->findRecord($this->snapshot($threadId), 'messages', 'id', $messageId) ?? throw new ChatException('message.unknown', 404);
        if (($message['author']['kind'] ?? null) !== $actor['kind'] || ($message['author']['id'] ?? null) !== $actor['id']) {
            throw new ChatException('message.author', 403);
        }
        if ($message['delivery'] !== 'queued') {
            throw new ChatException('delivery.illegal', 409);
        }
        $event = $this->append($threadId, ['type' => 'delivery.changed', 'actor' => $actor, 'data' => ['messageId' => $messageId, 'action' => 'unsend']]);

        return ['messageId' => $messageId, 'delivery' => 'unsent', 'seq' => $event['seq']];
    }

    /**
     * Settles every turn whose thread has been silent for reaper.timeout_ms.
     * Parked turns are left alone unless reaper.include_parked is on.
     */
    public function reap(): int
    {
        $timeout = max(0, (int) $this->cfg('reaper.timeout_ms', 600000));
        $includeParked = (bool) $this->cfg('reaper.include_parked', false);
        $count = 0;
        foreach ($this->store->staleThreads($this->now() - $timeout) as $threadId) {
            foreach ($this->snapshot($threadId)['turns'] as $turn) {
                if ($turn['state'] === 'settled' || ($turn['state'] === 'awaiting-human' && ! $includeParked)) {
                    continue;
                }
                $this->append($threadId, ['type' => 'turn.settled', 'turnId' => $turn['turnId'], 'data' => ['turnId' => $turn['turnId'], 'action' => 'reap']]);
                $count++;
            }
        }

        return $count;
    }
}
