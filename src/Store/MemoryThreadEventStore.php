<?php

declare(strict_types=1);

namespace FancyChat\Store;

use FancyChat\Contract\Projection;

/**
 * In-process store, for tests, demos and single-process hosts. Not shared
 * between processes: use the database driver for queued turns.
 */
final class MemoryThreadEventStore implements ThreadEventStore
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $events = [];

    /** @var array<string, int> */
    private array $last = [];

    /** @var array<string, array{seq: int, state: array<string, mixed>}> */
    private array $checkpoints = [];

    /** @var array<string, int> */
    private array $touched = [];

    /** @var array<string, array{thread: string, kind: string, state: string}> */
    private array $hitl = [];

    public function append(string $threadId, array $event, int $nowMs): array
    {
        $seq = ($this->last[$threadId] ?? 0) + 1;
        $this->last[$threadId] = $seq;
        $event['seq'] = $seq;
        $this->events[$threadId][] = $event;
        $this->touched[$threadId] = $nowMs;

        return $event;
    }

    public function after(string $threadId, int $after, int $limit): array
    {
        $out = [];
        foreach ($this->events[$threadId] ?? [] as $event) {
            if ($event['seq'] > $after) {
                $out[] = $event;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    public function lastSeq(string $threadId): int
    {
        return $this->last[$threadId] ?? 0;
    }

    public function checkpoint(string $threadId): array
    {
        return $this->checkpoints[$threadId] ?? ['seq' => 0, 'state' => Projection::emptyState()];
    }

    public function prune(string $threadId, int $keep): void
    {
        $events = $this->events[$threadId] ?? [];
        $drop = count($events) - $keep;
        if ($drop <= 0) {
            return;
        }
        $checkpoint = $this->checkpoint($threadId);
        $state = $checkpoint['state'];
        foreach (array_slice($events, 0, $drop) as $event) {
            Projection::apply($state, $event);
        }
        $this->checkpoints[$threadId] = ['seq' => $state['lastSeq'], 'state' => $state];
        $this->events[$threadId] = array_values(array_slice($events, $drop));
    }

    public function index(string $hitlId, string $threadId, string $kind, string $state): void
    {
        $this->hitl[$hitlId] = ['thread' => $threadId, 'kind' => $kind, 'state' => $state];
    }

    public function locate(string $hitlId): ?string
    {
        return $this->hitl[$hitlId]['thread'] ?? null;
    }

    public function claim(string $hitlId, string $from, string $to): bool
    {
        if (($this->hitl[$hitlId]['state'] ?? null) !== $from) {
            return false;
        }
        $this->hitl[$hitlId]['state'] = $to;

        return true;
    }

    public function setHitlState(string $hitlId, string $state): void
    {
        if (isset($this->hitl[$hitlId])) {
            $this->hitl[$hitlId]['state'] = $state;
        }
    }

    public function staleThreads(int $beforeMs): array
    {
        return array_keys(array_filter($this->touched, fn (int $at): bool => $at <= $beforeMs));
    }
}
