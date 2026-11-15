<?php

declare(strict_types=1);

namespace FancyChat\Contract;

use DateTimeImmutable;
use Throwable;

/**
 * The undo window, ported from fancy-chat-js `undo.ts`. Pure: the caller
 * supplies the clock. Shut at the deadline, and shut on any deadline that is
 * not the contract's timestamp form, so a malformed record can never be
 * reverted late.
 */
final class Undo
{
    /** Milliseconds since the epoch for a contract timestamp, or null. */
    public static function toMs(mixed $value): ?int
    {
        if (! Envelope::isTimestamp($value)) {
            return null;
        }
        try {
            $at = new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }

        return $at->getTimestamp() * 1000 + intdiv((int) $at->format('u'), 1000);
    }

    /**
     * @param  array<string, mixed>  $entry  deadline, state
     * @return array{remainingMs: int, canRevert: bool}
     */
    public static function window(array $entry, ?int $now): array
    {
        if (($entry['state'] ?? null) !== 'open' || $now === null) {
            return ['remainingMs' => 0, 'canRevert' => false];
        }
        $deadline = self::toMs($entry['deadline'] ?? null);
        if ($deadline === null) {
            return ['remainingMs' => 0, 'canRevert' => false];
        }
        $remaining = max(0, $deadline - $now);

        return ['remainingMs' => $remaining, 'canRevert' => $remaining > 0];
    }
}
