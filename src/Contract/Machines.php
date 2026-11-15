<?php

declare(strict_types=1);

namespace FancyChat\Contract;

/**
 * Runners over contract/machines.json, vendored byte-identical from
 * fancy-chat-js and pinned by hash. Nothing here encodes a transition of its
 * own: every answer comes from the file.
 */
final class Machines
{
    /** @var array<string, mixed>|null */
    private static ?array $table = null;

    /** @return array<string, mixed> */
    public static function table(): array
    {
        return self::$table ??= json_decode((string) file_get_contents(dirname(__DIR__, 2).'/contract/machines.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Drops the cached table (the sabotage sweep rewrites the file between runs). */
    public static function reload(): void
    {
        self::$table = null;
    }

    /** @return array<string, mixed>|null */
    private static function find(string $machine, string $from, string $action): ?array
    {
        foreach (self::table()[$machine]['transitions'] as $row) {
            if ($row['from'] === $from && $row['action'] === $action) {
                return $row;
            }
        }

        return null;
    }

    /** @return array{ok: true, to: string, outcome: ?string, reason: ?string}|array{ok: false, error: string} */
    public static function turn(string $from, string $action): array
    {
        $row = self::find('turn', $from, $action);
        if ($row === null) {
            return ['ok' => false, 'error' => 'turn.illegal'];
        }

        return ['ok' => true, 'to' => $row['to'], 'outcome' => $row['outcome'] ?? null, 'reason' => $row['reason'] ?? null];
    }

    /** True when `action` moves a turn to `settled`. Only `turn.settled` may carry one. */
    public static function isSettlingTurnAction(string $action): bool
    {
        foreach (self::table()['turn']['transitions'] as $row) {
            if ($row['action'] === $action && $row['to'] === 'settled') {
                return true;
            }
        }

        return false;
    }

    /** @return array{ok: true, to: string}|array{ok: false, error: string} */
    public static function delivery(string $from, string $action): array
    {
        $row = self::find('delivery', $from, $action);

        return $row === null ? ['ok' => false, 'error' => 'delivery.illegal'] : ['ok' => true, 'to' => $row['to']];
    }

    /** @return array{ok: true, to: string}|array{ok: false, error: string} */
    public static function approval(string $from, string $action): array
    {
        $row = self::find('approval', $from, $action);

        return $row === null ? ['ok' => false, 'error' => 'approval.illegal'] : ['ok' => true, 'to' => $row['to']];
    }

    /**
     * Button tone and decision come from the option KIND, never its label.
     *
     * @return array{tone: ?string, decision: ?string}
     */
    public static function optionTone(string $kind): array
    {
        $kinds = self::table()['optionKinds'];
        if (! array_key_exists($kind, $kinds)) {
            return ['tone' => null, 'decision' => null];
        }

        return ['tone' => $kinds[$kind]['tone'], 'decision' => $kinds[$kind]['decision']];
    }
}
