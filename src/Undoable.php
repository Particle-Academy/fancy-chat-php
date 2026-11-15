<?php

declare(strict_types=1);

namespace FancyChat;

/**
 * An action a human can take back inside a window (an email send, a post). The
 * host registers one per action kind with FancyChat::undoable(), opens a window
 * with FancyChat::openUndo(), and keeps its own payload keyed by the action id.
 *
 * The server calls exactly one of these, at most once per action, under a lock:
 * `revert` when a human reverts before the deadline, `commit` once the
 * deadline has passed. A throw leaves the window open, to be retried.
 */
interface Undoable
{
    public function commit(string $actionId, string $threadId): void;

    public function revert(string $actionId, string $threadId): void;
}
