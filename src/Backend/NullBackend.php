<?php

declare(strict_types=1);

namespace FancyChat\Backend;

use Closure;

/**
 * A scripted backend for tests and demos. The script receives the TurnInput
 * and returns (or yields) the turn's events. It records every cancel.
 */
final class NullBackend implements AgentBackend
{
    /** @var list<array{threadId: string, turnId: string, mode: string, confirmWaitMs: int}> */
    public array $cancels = [];

    /** @param  array{steering: bool, cancel: bool, approvals: bool}  $capabilities */
    public function __construct(
        private ?Closure $script = null,
        private array $capabilities = ['steering' => false, 'cancel' => true, 'approvals' => true],
        private string $cancelOutcome = CancelOutcome::OK,
    ) {}

    public function start(TurnInput $input): iterable
    {
        return $this->script === null ? [] : (($this->script)($input) ?? []);
    }

    public function cancel(string $threadId, string $turnId, string $mode, int $confirmWaitMs): string
    {
        $this->cancels[] = compact('threadId', 'turnId', 'mode', 'confirmWaitMs');

        return $this->cancelOutcome;
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }
}
