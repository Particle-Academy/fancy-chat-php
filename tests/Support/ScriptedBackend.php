<?php

declare(strict_types=1);

namespace FancyChat\Tests\Support;

use FancyChat\Backend\AgentBackend;
use FancyChat\Backend\CancelOutcome;
use FancyChat\Backend\TurnInput;

use Closure;

/**
 * A scripted backend, for this suite and tests/e2e/serve.php ONLY. Not shipped:
 * a host binds Prism Harness (FancyChat\Prism\HarnessBackend). The script receives the TurnInput
 * and returns (or yields) the turn's events. It records every cancel.
 */
final class ScriptedBackend implements AgentBackend
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
