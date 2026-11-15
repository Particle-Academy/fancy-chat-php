<?php

declare(strict_types=1);

namespace FancyChat\Jobs;

use FancyChat\Backend\TurnInput;
use FancyChat\FancyChat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Runs one turn (or resumes one) on the host's backend. Queued, so a request
 * returns as soon as the message is in the log; the client watches the turn
 * arrive over SSE or long-poll. The host binds its backend in a service
 * provider, so a queue worker has it too.
 */
final class RunTurn implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** @param  array<string, mixed>  $input  a TurnInput as an array */
    public function __construct(public array $input) {}

    public function handle(FancyChat $chat): void
    {
        $chat->runTurn(TurnInput::fromArray($this->input));
    }
}
