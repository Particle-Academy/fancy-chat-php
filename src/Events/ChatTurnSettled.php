<?php

declare(strict_types=1);

namespace FancyChat\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A turn settled: `completed`, `failed`, `refused`, `cancelled` or `interrupted`.
 * Fired once per turn, on every path that settles one (the turn job, cancel, the
 * reaper, a truncated branch, a host append), and only after the surrounding
 * database transaction commits. A "reply ready" notification listens for
 * `succeeded()`; a park is not a settle and never fires it.
 */
final readonly class ChatTurnSettled implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $threadId,
        public string $turnId,
        public string $outcome,
        public ?string $reason,
    ) {}

    public function succeeded(): bool
    {
        return $this->outcome === 'completed';
    }
}
