<?php

declare(strict_types=1);

namespace FancyChat\Console;

use FancyChat\FancyChat;
use Illuminate\Console\Command;

/** Commits undo windows whose deadline has passed. Schedule it, e.g. every few seconds or every minute. */
final class CommitUndoCommand extends Command
{
    protected $signature = 'fancy-chat:commit-undo';

    protected $description = 'Commit agent-chat undo windows whose deadline has passed';

    public function handle(FancyChat $chat): int
    {
        $count = $chat->commitExpiredUndos();
        $this->info("Committed {$count} undo window(s).");

        return self::SUCCESS;
    }
}
