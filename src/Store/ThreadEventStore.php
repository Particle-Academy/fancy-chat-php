<?php

declare(strict_types=1);

namespace FancyChat\Store;

/**
 * A thread's append-only event log. `append` assigns the per-thread seq
 * atomically: monotonic and gapless, even under concurrent writers. Events
 * older than the retention window are folded into a checkpoint state (the
 * projection at `seq`), so a snapshot never loses them.
 *
 * It also keeps a small index of HITL items (approval or question id to
 * thread, plus a state used ONLY as the in-flight lock; the projection is the
 * source of truth for the record itself).
 */
interface ThreadEventStore
{
    /**
     * Stores a validated event, assigning `seq`. The event's `seq` is ignored.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed> the stored event
     */
    public function append(string $threadId, array $event, int $nowMs): array;

    /** @return list<array<string, mixed>> events with seq > $after, ascending, at most $limit */
    public function after(string $threadId, int $after, int $limit): array;

    public function lastSeq(string $threadId): int;

    /** @return array{seq: int, state: array<string, mixed>} */
    public function checkpoint(string $threadId): array;

    /** Folds all but the newest `$keep` events into the checkpoint. */
    public function prune(string $threadId, int $keep): void;

    public function index(string $hitlId, string $threadId, string $kind, string $state): void;

    public function locate(string $hitlId): ?string;

    /** Atomically moves a HITL item from one state to another; false if it was not in `$from`. */
    public function claim(string $hitlId, string $from, string $to): bool;

    public function setHitlState(string $hitlId, string $state): void;

    /** @return list<string> threads whose last append was at or before `$beforeMs` */
    public function staleThreads(int $beforeMs): array;
}
