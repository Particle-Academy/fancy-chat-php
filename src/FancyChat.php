<?php

declare(strict_types=1);

namespace FancyChat;

use Closure;
use FancyChat\Backend\AgentBackend;
use FancyChat\Backend\CancelOutcome;
use FancyChat\Backend\SuggestionRequest;
use FancyChat\Backend\SuggestsReplies;
use FancyChat\Backend\TurnInput;
use FancyChat\Contract\Envelope;
use FancyChat\Contract\Hitl;
use FancyChat\Contract\Json;
use FancyChat\Contract\Machines;
use FancyChat\Contract\Parts;
use FancyChat\Contract\Plan;
use FancyChat\Contract\Projection;
use FancyChat\Contract\Suggestions;
use FancyChat\Contract\TypedApproval;
use FancyChat\Contract\Undo;
use FancyChat\Jobs\GenerateSuggestions;
use FancyChat\Jobs\RunTurn;
use FancyChat\Store\ThreadEventStore;
use FancyChat\Events\ChatTurnParking;
use FancyChat\Events\ChatTurnSettled;
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
    /** Approval states a human decision leads to (machines.json): what a resume carries. */
    private const array DECIDED_APPROVAL = ['approved', 'rejected', 'edited', 'applied', 'apply-failed'];

    private const string THREAD_ID = '/^[A-Za-z0-9._:-]{1,128}$/';

    /** Event types a backend may never emit: they are the server's or the human's. */
    private const array BACKEND_FORBIDDEN = [
        'thread.snapshot', 'thread.reset', 'turn.started', 'delivery.changed',
        'approval.decided', 'approval.applied', 'approval.withdrawn', 'question.answered',
        // Quick replies come only from the server's generator, after its checks.
        'suggestions.updated',
    ];

    private ?Closure $actorResolver = null;

    private ?Closure $accessResolver = null;

    private ?AgentBackend $backend = null;

    /**
     * Approvals and questions a backend raised in a running turn, held until the
     * turn parks (keyed by turnId). Publishing them mid-run would let a human act
     * before the run, and whatever the host records about it, is durable.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $heldHitl = [];

    private ?Closure $clock = null;

    private ?Closure $sleeper = null;

    /** @var array<string, StagedWrite> */
    private array $stagedWrites = [];

    /** @var array<string, Undoable> */
    private array $undoables = [];

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

    /** Registers the host's commit/revert for one undoable action kind. */
    public function undoable(string $kind, Undoable $undoable): self
    {
        $this->undoables[$kind] = $undoable;

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
        return $this->appendAs($threadId, $partial, 'server');
    }

    /**
     * Every append goes through here, the host's own and a backend's alike, so an
     * approval or question record is checked (shape, option kinds, preview parts,
     * an id unused in ANY thread) and stamped with its turn on every path.
     *
     * @param  array<string, mixed>  $partial
     * @param  'server'|'agent'  $origin  whose parts an approval preview is
     * @return array<string, mixed>
     */
    private function appendAs(string $threadId, array $partial, string $origin): array
    {
        $this->assertThreadId($threadId);
        $hitlType = $partial['type'] ?? null;
        if ($hitlType === 'approval.requested' || $hitlType === 'question.asked') {
            $data = Json::isObject($partial['data'] ?? null) ? ($partial['data'] ?? []) : [];
            $turnId = is_string($partial['turnId'] ?? null) ? $partial['turnId'] : ($this->activeTurn($this->snapshot($threadId))['turnId'] ?? null);
            if ($hitlType === 'approval.requested') {
                [$record, $error] = $this->approvalRecord($data['approval'] ?? null, $turnId, $partial['runId'] ?? null, $origin);
                $data['approval'] = $record;
            } else {
                [$record, $error] = $this->questionRecord($data['question'] ?? null, $turnId);
                $data['question'] = $record;
            }
            if ($error !== null) {
                throw new ChatException($error, str_ends_with($error, '.duplicate') ? 409 : 422);
            }
            $partial['data'] = $data;
            if ($turnId !== null) {
                $partial['turnId'] = $turnId;
            }
        }
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

        // A settle that changes nothing (a duplicate, or one the machine refuses) must
        // not announce again: one ChatTurnSettled per turn.
        $settledBefore = $event['type'] === 'turn.settled' && $this->turnIsSettled($threadId, $event['data']['turnId'] ?? null);
        $stored = $this->store->append($threadId, $event, $this->now());
        if ($stored['type'] === 'turn.settled' && ! $settledBefore) {
            $this->announceSettled($threadId, $stored);
        }

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

    private function turnIsSettled(string $threadId, mixed $turnId): bool
    {
        if (! is_string($turnId)) {
            return false;
        }

        return ($this->findRecord($this->snapshot($threadId), 'turns', 'turnId', $turnId)['state'] ?? null) === 'settled';
    }

    /** @param  array<string, mixed>  $stored */
    private function announceSettled(string $threadId, array $stored): void
    {
        $turnId = $stored['data']['turnId'] ?? null;
        if (! is_string($turnId)) {
            return;
        }
        $turn = $this->findRecord($this->snapshot($threadId), 'turns', 'turnId', $turnId);
        if ($turn === null || $turn['state'] !== 'settled' || ! is_string($turn['outcome'])) {
            return;
        }
        $this->app->make('events')->dispatch(new ChatTurnSettled($threadId, $turnId, $turn['outcome'], is_string($turn['reason'] ?? null) ? $turn['reason'] : null));
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
        // A checkpoint written before a field existed reads it as its empty value (null rather than absent).
        $state = $checkpoint['state'] + ['suggestions' => null];
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

        $out = ['kind' => $actor['kind'], 'id' => $actor['id']];
        // A display name the host resolved (never from the request body). It is shown,
        // never compared: decisions match on kind and id alone.
        if (is_string($actor['label'] ?? null) && $actor['label'] !== '') {
            $out['label'] = $actor['label'];
        }

        return $out;
    }

    /**
     * What the bound backend can do, as booleans; all false without a backend.
     *
     * @return array{steering: bool, cancel: bool, approvals: bool}
     */
    public function backendCapabilities(): array
    {
        $c = $this->backend?->capabilities() ?? [];

        return [
            'steering' => ($c['steering'] ?? null) === true,
            'cancel' => ($c['cancel'] ?? null) === true,
            'approvals' => ($c['approvals'] ?? null) === true,
        ];
    }

    /** @return array{steering: bool, cancel: bool, approvals: bool} */
    public function capabilities(Request $request, string $threadId): array
    {
        $this->resolveActor($request);
        $this->authorize($request, $threadId, 'read');

        return $this->backendCapabilities();
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
    /**
     * Re-sends a failed, interrupted or cancelled turn's ORIGINAL message parts as a
     * new turn (queued behind a running one, like any message). The parts come from
     * the log through the turn's messageId, so it needs no client state.
     *
     * @return array{messageId: string, seq: int, delivery: string}
     */
    public function retry(Request $request, string $threadId, string $turnId): array
    {
        $actor = $this->resolveActor($request);
        $this->authorize($request, $threadId, 'write');
        $this->requireHuman($actor);
        if ($this->backend === null) {
            throw new ChatException('chat.backend', 503);
        }
        $state = $this->snapshot($threadId);
        $turn = $this->findRecord($state, 'turns', 'turnId', $turnId) ?? throw new ChatException('turn.unknown', 404);
        if ($turn['state'] !== 'settled' || ! in_array($turn['outcome'], ['failed', 'interrupted', 'cancelled'], true)) {
            throw new ChatException('turn.state', 409);
        }
        $origin = is_string($turn['messageId'] ?? null) ? $this->findRecord($state, 'messages', 'id', $turn['messageId']) : null;
        if ($origin === null || $origin['parts'] === []) {
            throw new ChatException('turn.no-message', 422);
        }

        $messageId = 'm_'.bin2hex(random_bytes(8));
        $message = ['id' => $messageId, 'role' => 'user', 'parts' => $origin['parts'], 'delivery' => 'sent'];
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
        $this->startTurn($threadId, $messageId, $origin['parts']);

        return ['messageId' => $messageId, 'seq' => $created['seq'], 'delivery' => 'sent'];
    }

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
        if ($supersedes !== null) {
            $policy = $this->editResendPolicy();
            // Edit and resend: only the author's own human message can be superseded.
            $target = is_string($supersedes) ? $this->findRecord($state, 'messages', 'id', $supersedes) : null;
            if ($target === null || ($target['role'] ?? null) !== 'user'
                || ($target['author']['kind'] ?? null) !== $actor['kind'] || ($target['author']['id'] ?? null) !== $actor['id']) {
                throw new ChatException('message.supersedes', 422);
            }
        }

        if ($supersedes === null) {
            $typed = $this->typedDecision($request, $threadId, $state, $actor, $parts);
            if ($typed !== null) {
                return $typed;
            }
        }

        $messageId = 'm_'.bin2hex(random_bytes(8));
        $message = ['id' => $messageId, 'role' => 'user', 'parts' => $parts, 'delivery' => 'sent'];
        if ($supersedes !== null) {
            $message['supersedes'] = $supersedes;
        }

        if ($supersedes !== null && $policy === 'truncate') {
            // Truncate first: it cancels the turns the removed branch left parked,
            // so the new message starts rather than queueing behind a dead turn.
            $created = $this->append($threadId, ['type' => 'message.created', 'actor' => $actor, 'data' => ['message' => $message]]);
            $this->truncateBranch($threadId, $messageId);
            $state = $this->snapshot($threadId);
            if ($this->activeTurn($state) !== null) {
                $this->append($threadId, ['type' => 'delivery.changed', 'data' => ['messageId' => $messageId, 'action' => 'queue']]);

                return ['messageId' => $messageId, 'seq' => $created['seq'], 'delivery' => 'queued'];
            }
            $this->startTurn($threadId, $messageId, $parts);

            return ['messageId' => $messageId, 'seq' => $created['seq'], 'delivery' => 'sent'];
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

    /** The host's edit-and-resend policy: `branch` (the default) or `truncate`. */
    private function editResendPolicy(): string
    {
        $policy = $this->cfg('edit_resend.policy', 'branch');
        if (! in_array($policy, ['branch', 'truncate'], true)) {
            throw new ChatException('config.edit_resend.policy', 500);
        }

        return $policy;
    }

    /**
     * Truncate policy: retracts every message in the branch `$byId` superseded
     * and withdraws its pending approvals. The branch is the projection's own
     * record, so it is the same rows the client shows.
     */
    private function truncateBranch(string $threadId, string $byId): void
    {
        $state = $this->snapshot($threadId);
        $rowIds = [];
        $supersededId = null;
        foreach ($state['branches'] as $branch) {
            if ($branch['byId'] === $byId) {
                $rowIds = $branch['rowIds'];
                $supersededId = $branch['supersededId'];
            }
        }
        foreach ($state['messages'] as $m) {
            if (in_array($m['id'], $rowIds, true) && $m['retracted'] === false) {
                $this->append($threadId, ['type' => 'message.retracted', 'data' => ['messageId' => $m['id']]]);
            }
        }
        foreach ($state['approvals'] as $a) {
            if (in_array($a['approvalId'], $rowIds, true) && $a['state'] === 'pending' && $this->store->claim($a['approvalId'], 'pending', 'withdrawn')) {
                $this->append($threadId, ['type' => 'approval.withdrawn', 'turnId' => $a['turnId'] ?? null, 'data' => ['approvalId' => $a['approvalId']]]);
            }
        }
        if ($supersededId !== null) {
            $this->cancelBranchTurns($threadId, $supersededId);
        }
    }

    /**
     * Cancels every unsettled turn a truncated branch referenced
     * (`Projection::turnsToCancelForBranch`). A parked turn has nothing
     * running, so it is settled here; a running one is cancelled through the
     * backend, and settled only on an `ok`.
     */
    private function cancelBranchTurns(string $threadId, string $supersededId): void
    {
        $state = $this->snapshot($threadId);
        foreach (Projection::turnsToCancelForBranch($state, $supersededId) as $turnId) {
            $turn = $this->findRecord($state, 'turns', 'turnId', $turnId);
            if ($turn === null) {
                continue;
            }
            if ($turn['state'] !== 'cancelling') {
                $this->append($threadId, ['type' => 'turn.transition', 'turnId' => $turnId, 'data' => ['turnId' => $turnId, 'action' => 'cancel']]);
            }
            $settle = $turn['state'] === 'awaiting-human';
            if (! $settle && $this->backend !== null && ($this->backend->capabilities()['cancel'] ?? false) === true) {
                $settle = $this->backend->cancel($threadId, $turnId, 'stop', (int) $this->cfg('cancel.confirm_wait_ms', 2000)) === CancelOutcome::OK;
            }
            if ($settle) {
                $this->append($threadId, ['type' => 'turn.settled', 'turnId' => $turnId, 'data' => ['turnId' => $turnId, 'action' => 'cancelled']]);
            }
        }
    }

    /**
     * Typed approval (opt-in per app). When the thread's one actionable HITL
     * item is a pending approval and the message is exactly one text part
     * matching the host's phrases, the message is the decision: it takes the
     * SAME decide path as the decision endpoint, is recorded as the human's
     * message, and is never delivered to the agent.
     *
     * @param  array<string, mixed>  $state
     * @param  array{kind: string, id: string}  $actor
     * @param  list<array<string, mixed>>  $parts
     * @return array<string, mixed>|null
     */
    private function typedDecision(Request $request, string $threadId, array $state, array $actor, array $parts): ?array
    {
        $config = (array) $this->cfg('typed_approval', []);
        if (($config['enabled'] ?? false) !== true || count($parts) !== 1 || ($parts[0]['kind'] ?? null) !== 'text' || ! is_string($parts[0]['text'] ?? null)) {
            return null;
        }
        $actionable = Hitl::pickActionable($this->hitlItems($state), null);
        $approval = $actionable === null ? null : $this->findRecord($state, 'approvals', 'approvalId', $actionable);
        $match = TypedApproval::match($parts[0]['text'], $approval, $config);
        if ($match === null || $approval === null) {
            return null;
        }

        $messageId = 'm_'.bin2hex(random_bytes(8));
        $created = $this->append($threadId, ['type' => 'message.created', 'actor' => $actor, 'data' => ['message' => [
            'id' => $messageId, 'role' => 'user', 'parts' => $parts, 'delivery' => 'sent',
        ]]]);
        try {
            $decided = $this->decide($request, $approval['approvalId'], ['optionId' => $match['optionId']]);
        } catch (Throwable $e) {
            // Not a decision after all: the message is dropped, the error stands, and nothing reached the agent.
            $this->append($threadId, ['type' => 'delivery.changed', 'data' => ['messageId' => $messageId, 'action' => 'drop']]);

            throw $e;
        }
        // Taken as the decision: `decided` is final, so the text can never reach the agent later.
        $this->append($threadId, ['type' => 'delivery.changed', 'data' => ['messageId' => $messageId, 'action' => 'decide']]);
        $this->append($threadId, ['type' => 'x-fancy-chat.typed-decision', 'actor' => $actor, 'data' => [
            'messageId' => $messageId, 'approvalId' => $approval['approvalId'], 'optionId' => $match['optionId'], 'decision' => $match['decision'],
        ]]);

        return [
            'messageId' => $messageId,
            'seq' => $created['seq'],
            'delivery' => 'decided',
            'typedDecision' => ['approvalId' => $approval['approvalId'], 'optionId' => $match['optionId'], 'decision' => $match['decision'], 'state' => $decided['approval']['state']],
        ];
    }

    /**
     * The thread's HITL items in seq order (order of first appearance), as
     * fancy-chat-js `pickActionable` takes them.
     *
     * @param  array<string, mixed>  $state
     * @return list<array{id: string, kind: string, state: string, seq: int}>
     */
    private function hitlItems(array $state): array
    {
        $items = [];
        foreach ($state['order'] as $index => $ref) {
            if ($ref['kind'] === 'approval') {
                $record = $this->findRecord($state, 'approvals', 'approvalId', $ref['id']);
            } elseif ($ref['kind'] === 'question') {
                $record = $this->findRecord($state, 'questions', 'questionId', $ref['id']);
            } else {
                continue;
            }
            $items[] = ['id' => $ref['id'], 'kind' => $ref['kind'], 'state' => (string) ($record['state'] ?? 'open'), 'seq' => $index];
        }

        return $items;
    }

    // ------------------------------------------------------------- undo

    /**
     * Opens an undo window for an action the host just took. `$windowMs` is the
     * host's choice per action (default undo.default_ms), capped at undo.max_ms.
     */
    public function openUndo(string $threadId, string $kind, string $label, ?int $windowMs = null): string
    {
        $this->assertThreadId($threadId);
        if (! isset($this->undoables[$kind])) {
            throw new ChatException('undo.kind', 422);
        }
        $window = max(1, min($windowMs ?? (int) $this->cfg('undo.default_ms', 30000), (int) $this->cfg('undo.max_ms', 600000)));
        $actionId = 'ud_'.bin2hex(random_bytes(8));
        $deadline = $this->now() + $window;
        $this->store->openUndo($actionId, $threadId, $kind, $deadline);
        $this->append($threadId, ['type' => 'undo.opened', 'data' => ['actionId' => $actionId, 'label' => $label, 'deadline' => $this->iso($deadline)]]);

        return $actionId;
    }

    /**
     * A human reverts inside the window. The revert and the deadline's commit
     * each claim the window atomically, so exactly one of them runs, once.
     *
     * @return array{undo: array<string, mixed>, seq: int}
     */
    public function revertUndo(Request $request, string $actionId): array
    {
        $actor = $this->resolveActor($request);
        $this->requireHuman($actor);
        $action = $this->store->undoAction($actionId) ?? throw new ChatException('undo.unknown', 404);
        $threadId = $action['threadId'];
        $this->authorize($request, $threadId, 'write');
        if ($action['state'] !== 'open') {
            throw new ChatException('undo.state', 409);
        }
        if ($this->now() >= $action['deadlineMs']) {
            throw new ChatException('undo.expired', 409);
        }
        $undoable = $this->undoables[$action['kind']] ?? throw new ChatException('undo.kind', 409);
        if (! $this->store->claimUndo($actionId, 'open', 'reverting')) {
            throw new ChatException('undo.state', 409);
        }
        try {
            $undoable->revert($actionId, $threadId);
        } catch (Throwable $e) {
            $this->report($e);
            $this->store->setUndoState($actionId, 'open');

            throw new ChatException('undo.revert', 500);
        }
        $event = $this->append($threadId, ['type' => 'undo.reverted', 'actor' => $actor, 'data' => ['actionId' => $actionId, 'revertedBy' => $actor]]);
        $this->store->setUndoState($actionId, 'reverted');

        return ['undo' => $this->findRecord($this->snapshot($threadId), 'undos', 'actionId', $actionId), 'seq' => $event['seq']];
    }

    /** Commits every window whose deadline has passed. Each commit runs once; a throw leaves it open for the next run. */
    public function commitExpiredUndos(): int
    {
        $count = 0;
        foreach ($this->store->expiredUndos($this->now(), max(1, (int) $this->cfg('undo.batch', 200))) as $action) {
            $undoable = $this->undoables[$action['kind']] ?? null;
            if ($undoable === null || ! $this->store->claimUndo($action['actionId'], 'open', 'committing')) {
                continue;
            }
            try {
                $undoable->commit($action['actionId'], $action['threadId']);
            } catch (Throwable $e) {
                $this->report($e);
                $this->store->setUndoState($action['actionId'], 'open');

                continue;
            }
            $this->append($action['threadId'], ['type' => 'undo.committed', 'data' => ['actionId' => $action['actionId']]]);
            $this->store->setUndoState($action['actionId'], 'committed');
            $count++;
        }

        return $count;
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
        $this->append($threadId, ['type' => 'turn.started', 'turnId' => $turnId, 'data' => ['turnId' => $turnId, 'messageId' => $messageId]]);
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

        // What this turn had decided before the run, so a park resumes only on a
        // decision made DURING it (queue-and-resume), never re-delivers an old one.
        $decidedBefore = $this->decidedIds($state, $turnId);
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

        // A turn that ended without parking never publishes what it held: there is no
        // run left to resume, so an approval of it could only mislead.
        foreach ($this->heldHitl[$turnId] ?? [] as $held) {
            $this->append($threadId, ['type' => 'x-fancy-chat.refused', 'turnId' => $turnId, 'data' => ['type' => $held['type'], 'error' => $held['type'] === 'approval.requested' ? 'approval.unparked' : 'question.unparked']]);
        }
        unset($this->heldHitl[$turnId]);

        if ($current === 'awaiting-human') {
            // Queue-and-resume: a decision recorded while the turn was still running
            // (on an approval the host appended itself) resumes it now that it parked.
            $this->resumeParkedIfDecided($threadId, $turnId, $decidedBefore);
        }
        if ($current === 'settled') {
            $this->dequeueNext($threadId);
            // After the settle is in the log and announced, and after the next queued
            // turn is on its way: quick replies can never delay either.
            $this->offerSuggestions($threadId, $turnId);
        }
    }

    // ---------------------------------------------------------- quick replies

    /**
     * Queues quick-reply generation for a turn that just settled `completed`, when
     * the host enabled it and the backend can suggest. Never throws.
     */
    private function offerSuggestions(string $threadId, string $turnId): void
    {
        if ($this->cfg('suggestions.enabled', false) !== true || ! $this->backend instanceof SuggestsReplies) {
            return;
        }
        try {
            if ($this->suggestionTarget($this->snapshot($threadId), $turnId) === null) {
                return;
            }
            $job = new GenerateSuggestions($threadId, $turnId);
            if ($this->cfg('queue.connection') !== null) {
                $job->onConnection($this->cfg('queue.connection'));
            }
            if ($this->cfg('queue.name') !== null) {
                $job->onQueue($this->cfg('queue.name'));
            }
            $this->app->make(Dispatcher::class)->dispatch($job);
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    /**
     * Generates and appends quick replies for one settled turn (the
     * GenerateSuggestions job). Off unless `fancy-chat.suggestions.enabled` and the
     * backend's mode is listed in `fancy-chat.suggestions.modes`. Only the newest
     * turn, settled `completed`, gets them. The generator's ONLY inputs are the
     * user's message that started the turn and the agent's last reply in it.
     *
     * Never throws and never touches the turn: an error is logged through the
     * host's exception handler and nothing is appended.
     */
    public function generateSuggestions(string $threadId, string $turnId): void
    {
        try {
            $backend = $this->backend;
            if ($this->cfg('suggestions.enabled', false) !== true || ! $backend instanceof SuggestsReplies) {
                return;
            }
            $mode = $backend->replyMode($threadId);
            $modes = $this->cfg('suggestions.modes', []);
            if (! is_string($mode) || ! is_array($modes) || ! in_array($mode, $modes, true)) {
                return;
            }
            $target = $this->suggestionTarget($this->snapshot($threadId), $turnId);
            if ($target === null) {
                return;
            }
            $maxInput = max(0, (int) $this->cfg('suggestions.max_input_chars', 4000));
            $maxCount = max(0, (int) $this->cfg('suggestions.max_count', 3));
            $maxChars = max(0, (int) $this->cfg('suggestions.max_chars', 80));
            if ($maxCount === 0) {
                return;
            }
            $raw = $backend->suggestReplies(new SuggestionRequest(
                $threadId,
                $turnId,
                $mode,
                mb_substr($target['user'], 0, $maxInput, 'UTF-8'),
                mb_substr($target['agent'], 0, $maxInput, 'UTF-8'),
                $maxCount,
                $maxChars,
            ));
            $items = Suggestions::sanitize($raw, ['maxCount' => $maxCount, 'maxChars' => $maxChars], $this->refusedPhrases());
            // Generation takes time: a turn that started meanwhile makes these stale.
            if ($items === [] || $this->suggestionTarget($this->snapshot($threadId), $turnId) === null) {
                return;
            }
            $this->append($threadId, ['type' => 'suggestions.updated', 'turnId' => $turnId, 'data' => ['turnId' => $turnId, 'items' => $items]]);
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    /**
     * The two inputs for a turn, or null when it gets no quick replies: it must be the
     * newest turn, settled `completed`, with a human message that started it and an
     * agent reply in it, each with some text.
     *
     * @param  array<string, mixed>  $state
     * @return array{user: string, agent: string}|null
     */
    private function suggestionTarget(array $state, string $turnId): ?array
    {
        $turns = $state['turns'];
        $last = $turns === [] ? null : $turns[count($turns) - 1];
        // An outcome exists only once a turn settled, so `completed` is the whole check.
        if ($last === null || $last['turnId'] !== $turnId || $last['outcome'] !== 'completed') {
            return null;
        }
        $user = null;
        $agent = null;
        foreach ($state['messages'] as $m) {
            if ($m['retracted'] || $m['superseded']) {
                continue;
            }
            if ($m['id'] === $last['messageId'] && ($m['author']['kind'] ?? null) === 'human') {
                $user = self::textOf($m['parts']);
            } elseif ($m['turnId'] === $turnId && ($m['author']['kind'] ?? null) === 'agent') {
                $agent = self::textOf($m['parts']);
            }
        }

        return is_string($user) && trim($user) !== '' && is_string($agent) && trim($agent) !== '' ? ['user' => $user, 'agent' => $agent] : null;
    }

    /** @param  list<array<string, mixed>>  $parts */
    private static function textOf(array $parts): string
    {
        $texts = [];
        foreach ($parts as $part) {
            if (($part['kind'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                $texts[] = $part['text'];
            }
        }

        return implode("\n\n", $texts);
    }

    /**
     * Phrases a suggestion may never be: every typed-approval phrase, the English
     * set and any the host configured in any locale, whether or not typed approval
     * is on, so a pick can never read as a decision.
     *
     * @return list<string>
     */
    private function refusedPhrases(): array
    {
        $out = [...TypedApproval::ENGLISH['approve'], ...TypedApproval::ENGLISH['reject']];
        $phrases = $this->cfg('typed_approval.phrases', []);
        foreach (is_array($phrases) ? $phrases : [] as $lists) {
            foreach (is_array($lists) ? $lists : [] as $list) {
                foreach (is_array($list) ? $list : [] as $phrase) {
                    if (is_string($phrase)) {
                        $out[] = $phrase;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string> the approval and question ids of the turn a human has decided or answered
     */
    private function decidedIds(array $state, string $turnId): array
    {
        $ids = [];
        foreach ($state['approvals'] as $a) {
            if (($a['turnId'] ?? null) === $turnId && in_array($a['state'], self::DECIDED_APPROVAL, true)) {
                $ids[] = $a['approvalId'];
            }
        }
        foreach ($state['questions'] as $q) {
            if (($q['turnId'] ?? null) === $turnId && $q['state'] === 'answered') {
                $ids[] = $q['questionId'];
            }
        }

        return $ids;
    }

    /** @param  list<string>  $decidedBefore */
    private function resumeParkedIfDecided(string $threadId, string $turnId, array $decidedBefore): void
    {
        $state = $this->snapshot($threadId);
        // Nothing new decided during the run: the turn waits for a human, whatever
        // was decided before (a backend that parks again must not loop on old decisions).
        if (array_diff($this->decidedIds($state, $turnId), $decidedBefore) === []) {
            return;
        }
        $approval = null;
        foreach ($state['approvals'] as $a) {
            if (($a['turnId'] ?? null) === $turnId && in_array($a['state'], self::DECIDED_APPROVAL, true)) {
                $approval = $a;
            }
        }
        $question = null;
        foreach ($state['questions'] as $q) {
            if (($q['turnId'] ?? null) === $turnId && $q['state'] === 'answered') {
                $question = $q;
            }
        }
        // Only a decision that exists: a turn parked on nothing decided waits for a human.
        if ($approval !== null || $question !== null) {
            $this->resumeIfReady($threadId, $turnId, $approval, $question);
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
            case 'plan.updated':
                // A held step releases only through its approval: refused BEFORE it is appended.
                if (! Json::isList($data['steps'] ?? null)) {
                    $error = 'plan.steps';
                    break;
                }
                $state = $this->snapshot($threadId);
                $runId = is_string($raw['runId'] ?? null) ? $raw['runId'] : '_';
                $previous = $this->findRecord($state, 'plans', 'runId', $runId)['steps'] ?? [];
                $check = Plan::check($previous, $data['steps'], $state['approvals']);
                $error = $check['ok'] ? null : $check['error'];
                break;
            case 'tool.result':
                // A tool's output is bounded HERE, by characters, so a tool that
                // returns megabytes cannot flood every client. A cut output is
                // marked truncated; the host's outputRef points at the rest.
                if (array_key_exists('output', $data) && ! is_string($data['output'])) {
                    unset($data['output']);
                }
                if (is_string($data['output'] ?? null)) {
                    $max = max(0, (int) $this->cfg('limits.tool_output_chars', 16384));
                    if (mb_strlen($data['output']) > $max) {
                        $data['output'] = mb_substr($data['output'], 0, $max);
                        $data['truncated'] = true;
                    }
                }
                break;
            // approval.requested and question.asked: checked in appendAs, on every path.
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
        if ($type === 'approval.requested' || $type === 'question.asked') {
            // Held, not published: see $heldHitl. Published with the park.
            $this->heldHitl[$turnId][] = $event;

            return;
        }
        if ($type === 'turn.transition' && ($data['action'] ?? null) === 'park') {
            $this->publishHeld($threadId, $turnId, $refuse);
        }
        try {
            $this->appendAs($threadId, $event, 'agent');
        } catch (ChatException $e) {
            $refuse($e->error, $type);

            return;
        }
        if ($type === 'turn.transition' || $type === 'turn.settled') {
            $current = Machines::turn($current, $data['action'])['to'] ?? $current;
        }
    }

    /**
     * Publishes what a parking turn held: ChatTurnParking first (the host records
     * evidence), then each record, through the same checks as any append.
     *
     * @param  Closure(string, mixed=): void  $refuse
     */
    private function publishHeld(string $threadId, string $turnId, Closure $refuse): void
    {
        $held = $this->heldHitl[$turnId] ?? [];
        unset($this->heldHitl[$turnId]);
        if ($held === []) {
            return;
        }
        $ids = array_map(fn (array $e): string => (string) ($e['type'] === 'approval.requested' ? ($e['data']['approval']['approvalId'] ?? '') : ($e['data']['question']['questionId'] ?? '')), $held);
        $this->app->make('events')->dispatch(new ChatTurnParking($threadId, $turnId, $ids));
        foreach ($held as $event) {
            try {
                $this->appendAs($threadId, $event, 'agent');
            } catch (ChatException $e) {
                $refuse($e->error, $event['type']);
            }
        }
    }

    /** @return array{0: ?array<string, mixed>, 1: ?string} */
    private function approvalRecord(mixed $a, ?string $turnId, mixed $runId, string $origin): array
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
            $v = Parts::validateList($preview, $origin, $this->partLimits());
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
    private function questionRecord(mixed $q, ?string $turnId): array
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
        // Every approval of this turn a human decided, and every question answered, in
        // the order they were raised: a backend that parked on several needs them all.
        $approvals = array_values(array_filter($state['approvals'], fn (array $a): bool => ($a['turnId'] ?? null) === $turnId && in_array($a['state'], self::DECIDED_APPROVAL, true)));
        $questions = array_values(array_filter($state['questions'], fn (array $q): bool => ($q['turnId'] ?? null) === $turnId && $q['state'] === 'answered'));
        $this->dispatch(new TurnInput($threadId, $turnId, 'resume', null, [], $approval, $question, $approvals, $questions));
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
        $this->commitExpiredUndos();
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
