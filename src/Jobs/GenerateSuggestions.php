<?php

declare(strict_types=1);

namespace FancyChat\Jobs;

use FancyChat\FancyChat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Generates quick replies for one settled turn (CC-fancy-0065). Its own job, so
 * the model call happens after the turn is settled and announced and after the
 * next queued turn was dispatched: it can never delay the turn. Never retried;
 * a failure is logged by FancyChat::generateSuggestions and emits nothing.
 */
final class GenerateSuggestions implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** The generator's own time limit, plus a little for the log write. */
    public int $timeout;

    public int $tries = 1;

    public function __construct(public string $threadId, public string $turnId)
    {
        $this->timeout = max(1, (int) config('fancy-chat.suggestions.timeout_seconds', 15)) + 15;
    }

    public function handle(FancyChat $chat): void
    {
        $chat->generateSuggestions($this->threadId, $this->turnId);
    }
}
