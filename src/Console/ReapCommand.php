<?php

declare(strict_types=1);

namespace FancyChat\Console;

use FancyChat\FancyChat;
use Illuminate\Console\Command;

/** Settles stalled turns. Schedule it, e.g. every minute. */
final class ReapCommand extends Command
{
    protected $signature = 'fancy-chat:reap';

    protected $description = 'Settle agent-chat turns whose thread has been silent past fancy-chat.reaper.timeout_ms';

    public function handle(FancyChat $chat): int
    {
        $count = $chat->reap();
        $this->info("Reaped {$count} stalled turn(s).");

        return self::SUCCESS;
    }
}
