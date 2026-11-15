<?php

declare(strict_types=1);

namespace FancyChat\Backend;

/**
 * What a backend is started with. `kind` is `message` for a new turn, or
 * `resume` after a human decided an approval or answered a question (the
 * record is attached, in its state after the decision).
 */
final readonly class TurnInput
{
    /**
     * @param  list<array<string, mixed>>  $parts
     * @param  array<string, mixed>|null  $approval
     * @param  array<string, mixed>|null  $question
     * @param  list<array<string, mixed>>  $approvals  on resume: every approval of the turn a human decided, in order
     * @param  list<array<string, mixed>>  $questions  on resume: every question of the turn answered, in order
     */
    public function __construct(
        public string $threadId,
        public string $turnId,
        public string $kind = 'message',
        public ?string $messageId = null,
        public array $parts = [],
        public ?array $approval = null,
        public ?array $question = null,
        public array $approvals = [],
        public array $questions = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param  array<string, mixed>  $a */
    public static function fromArray(array $a): self
    {
        return new self($a['threadId'], $a['turnId'], $a['kind'] ?? 'message', $a['messageId'] ?? null, $a['parts'] ?? [], $a['approval'] ?? null, $a['question'] ?? null, $a['approvals'] ?? [], $a['questions'] ?? []);
    }
}
