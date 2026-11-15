<?php

declare(strict_types=1);

/*
 * One competing writer for the concurrency test: boots the package against a
 * shared SQLite file and appends N events to one thread as fast as it can.
 *
 *     php append-worker.php <sqlite-file> <thread> <count>
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use FancyChat\FancyChat;
use FancyChat\Tests\Support\TestApp;

[, $db, $thread, $count] = $argv;

$app = TestApp::boot(['fancy-chat.store' => 'database'], $db);
$chat = $app->make(FancyChat::class);
for ($i = 0; $i < (int) $count; $i++) {
    $chat->append($thread, ['type' => 'usage', 'data' => ['worker' => getmypid(), 'i' => $i]]);
}
exit(0);
