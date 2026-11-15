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
     */
    public function __construct(
        public string $threadId,
        public string $turnId,
        public string $kind = 'message',
        public ?string $messageId = null,
        public array $parts = [],
        public ?array $approval = null,
        public ?array $question = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param  array<string, mixed>  $a */
    public static function fromArray(array $a): self
    {
        return new self($a['threadId'], $a['turnId'], $a['kind'] ?? 'message', $a['messageId'] ?? null, $a['parts'] ?? [], $a['approval'] ?? null, $a['question'] ?? null);
    }
}
