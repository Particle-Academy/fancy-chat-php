<?php

declare(strict_types=1);

namespace FancyChat;

/**
 * A change an agent proposes and a human approves. The server calls `apply`
 * only once a human decision record exists for the approval (see
 * FancyChat::applyStagedWrite); the outcome is recorded as `approval.applied`
 * and reaches the agent when its turn resumes.
 */
interface StagedWrite
{
    /**
     * What the human sees before deciding, as message parts.
     *
     * @param  array<string, mixed>  $proposal
     * @return list<array<string, mixed>>
     */
    public function preview(array $proposal): array;

    /**
     * @param  array<string, mixed>  $approval  the approval record, decision included
     * @param  string  $decision  `approve` or `edit`
     * @param  mixed  $edited  the human's edit, for an `edit` decision
     * @return array{ok: bool, error?: string}
     */
    public function apply(array $approval, string $decision, mixed $edited = null): array;
}
