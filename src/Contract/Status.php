<?php

declare(strict_types=1);

namespace FancyChat\Contract;

/**
 * The agent status vocabulary and its ONE derivation, ported from
 * fancy-chat-js `status.ts`. Pure: it never reads a clock (`now` is in the
 * snapshot, in milliseconds).
 */
final class Status
{
    public const array STATUSES = ['not-started', 'starting', 'working', 'cooling', 'idle', 'sleeping', 'parked', 'paused-limit', 'signed-out', 'broken'];

    public const array DEFAULT_CONFIG = ['coolingMs' => 15000, 'sleepAfterMs' => 600000];

    public const array DEFAULT_RECOVERY = [
        'not-started' => [],
        'starting' => ['stop'],
        'working' => ['stop'],
        'cooling' => [],
        'idle' => [],
        'sleeping' => [],
        'parked' => ['open-hitl'],
        'paused-limit' => ['wait'],
        'signed-out' => ['sign-in'],
        'broken' => ['restart'],
    ];

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $config  coolingMs, sleepAfterMs, recovery (per status, replaces the default)
     * @return array{status: string, detail: ?array<string, mixed>, since: ?int, recovery: list<string>}
     */
    public static function derive(array $snapshot, array $config = []): array
    {
        $cfg = array_merge(self::DEFAULT_CONFIG, $config);
        $out = function (string $status, ?array $detail, int|float|null $since, ?array $fallback = null) use ($cfg): array {
            $recovery = $cfg['recovery'][$status] ?? $fallback ?? self::DEFAULT_RECOVERY[$status];

            return ['status' => $status, 'detail' => $detail, 'since' => $since, 'recovery' => array_values($recovery)];
        };

        $now = $snapshot['now'];
        $turn = $snapshot['turn'] ?? null;
        $background = $snapshot['background'] ?? 0;
        $pending = $snapshot['pendingHitl'] ?? 0;
        $lastSettledAt = $snapshot['lastSettledAt'] ?? null;
        $broken = $snapshot['broken'] ?? null;
        $limit = $snapshot['limit'] ?? null;
        $turnState = is_array($turn) ? ($turn['state'] ?? null) : null;

        if (is_string($broken) && $broken !== '') {
            return $out('broken', ['reason' => $broken], null);
        }
        if (($snapshot['signedIn'] ?? null) === false) {
            return $out('signed-out', null, null);
        }
        if (is_array($limit) && $limit !== [] && $limit['until'] > $now) {
            return $out('paused-limit', ['until' => $limit['until']], null);
        }
        if ($turnState === 'awaiting-human' || $pending > 0) {
            return $out('parked', ['pending' => $pending], $turnState === 'awaiting-human' ? $turn['since'] : null);
        }
        if ($turnState === 'pending') {
            return $out('starting', null, $turn['since']);
        }
        if ($turnState === 'running' || $turnState === 'cancelling') {
            $cancelling = $turnState === 'cancelling';

            return $out('working', ['background' => $background, 'plan' => $snapshot['plan'] ?? null, 'cancelling' => $cancelling], $turn['since'], $cancelling ? [] : null);
        }
        if ($background > 0) {
            return $out('working', ['background' => $background, 'plan' => $snapshot['plan'] ?? null, 'cancelling' => false], null, []);
        }
        if ($lastSettledAt === null) {
            return ! empty($snapshot['everStarted']) ? $out('idle', null, null) : $out('not-started', null, null);
        }
        $idleFor = $now - $lastSettledAt;
        if ($idleFor < $cfg['coolingMs']) {
            return $out('cooling', null, $lastSettledAt);
        }
        if ($idleFor >= $cfg['sleepAfterMs']) {
            return $out('sleeping', null, $lastSettledAt + $cfg['sleepAfterMs']);
        }

        return $out('idle', null, $lastSettledAt + $cfg['coolingMs']);
    }
}
