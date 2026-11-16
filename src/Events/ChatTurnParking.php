<?php

declare(strict_types=1);

namespace FancyChat\Events;

/**
 * A turn is about to park on the approvals and questions its backend raised.
 * Dispatched synchronously, BEFORE any of them is published (indexed and
 * decidable), once the backend's stream is exhausted. A listener that records
 * evidence the approvals cite (a turn recorder, an audit row) therefore runs
 * first: no human can act on an approval whose evidence does not exist yet.
 * A listener that throws fails the turn, and nothing is published.
 */
final readonly class ChatTurnParking
{
    /** @param  list<string>  $hitlIds  the approval and question ids about to be published */
    public function __construct(
        public string $threadId,
        public string $turnId,
        public array $hitlIds,
    ) {}
}
