<?php

declare(strict_types=1);

namespace FancyChat\Backend;

/**
 * The seam between the chat server and the agent. The backend is Prism Harness:
 * FancyChat\Prism\HarnessBackend. This interface exists so a host can wrap it
 * (routing some threads elsewhere) and so the suite can script a turn; nothing
 * is bound by default. A backend yields contract events (no seq, no thread id;
 * the server assigns both) and the server enforces the contract on every one.
 */
interface AgentBackend
{
    /**
     * Runs one turn (or resumes a parked one) and yields its events:
     * `message.created`, `part.*`, `thinking.*`, `tool.*`, `plan.updated`,
     * `approval.requested`, `question.asked`, `usage`, `x-*`, and the turn
     * actions `turn.transition` (`park`) and `turn.settled` (`refuse`, `fail`,
     * `finish`). A turn still running when the iterable ends is finished.
     *
     * @return iterable<array<string, mixed>>
     */
    public function start(TurnInput $input): iterable;

    /**
     * Stops (`stop`) or interrupts (`interrupt`) a turn, waiting up to
     * `$confirmWaitMs` for confirmation. Returns a CancelOutcome value.
     */
    public function cancel(string $threadId, string $turnId, string $mode, int $confirmWaitMs): string;

    /** @return array{steering: bool, cancel: bool, approvals: bool} */
    public function capabilities(): array;
}
