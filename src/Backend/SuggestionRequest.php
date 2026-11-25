<?php

declare(strict_types=1);

namespace FancyChat\Backend;

/**
 * What a quick-reply generator is given: the user's last message and the agent's
 * settled reply, as plain text, each already cut to
 * `fancy-chat.suggestions.max_input_chars`. Nothing else of the thread: no
 * history, no tool output, no thinking. The ids route the call; the limits are
 * what the server will keep.
 */
final readonly class SuggestionRequest
{
    public function __construct(
        public string $threadId,
        public string $turnId,
        public string $mode,
        public string $userMessage,
        public string $agentReply,
        public int $maxCount,
        public int $maxChars,
    ) {}
}
