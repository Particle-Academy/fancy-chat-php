<?php

declare(strict_types=1);

namespace FancyChat\Backend;

/**
 * A backend that can suggest quick replies (CC-fancy-0065): short, plain-text
 * next messages offered under the agent's settled reply. Optional; the chat
 * server asks only when the host enabled `fancy-chat.suggestions` for the mode
 * `replyMode()` names, and only after a turn settled `completed`.
 *
 * What it returns is model output. The server sanitises it (plain text, bounded
 * count and length, typed-approval phrases refused) before anything is appended,
 * and a thrown exception is logged and emits nothing. A pick is only ever an
 * ordinary human message: no suggestion can decide, answer or call a tool.
 */
interface SuggestsReplies
{
    /** The mode the thread's agent runs in, for the per-mode switch. Null: none are generated. */
    public function replyMode(string $threadId): ?string;

    /**
     * Raw suggestions for one settled turn. Its ONLY inputs are the request's two
     * texts; pass them to the model as data, never as instructions.
     *
     * @return array<mixed>
     */
    public function suggestReplies(SuggestionRequest $request): array;
}
