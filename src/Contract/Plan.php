<?php

declare(strict_types=1);

namespace FancyChat\Contract;

/**
 * Plan-step HITL, ported from fancy-chat-js `plan.ts`. A held step releases
 * ONLY through the approval it was held on: it may leave `held` once that
 * approval is approved, edited or applied, and may only go to `skipped` or
 * `failed` once it was refused. Dropping the step or swapping its approval id
 * releases nothing.
 */
final class Plan
{
    public const array STATUSES = ['pending', 'running', 'held', 'done', 'failed', 'skipped'];

    private const array RELEASED_BY = ['approved', 'edited', 'applied'];

    private const array REFUSED_BY = ['rejected', 'withdrawn', 'expired', 'apply-failed'];

    /**
     * @param  list<array<string, mixed>>  $prev
     * @param  list<array<string, mixed>>  $next
     * @param  list<array<string, mixed>>  $approvals  approvalId, state
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public static function check(array $prev, array $next, array $approvals): array
    {
        foreach ($next as $step) {
            if (! is_array($step) || ! in_array($step['status'] ?? null, self::STATUSES, true) || ! in_array($step['owner'] ?? null, ['agent', 'human'], true)) {
                return ['ok' => false, 'error' => 'plan.step'];
            }
        }
        foreach ($prev as $before) {
            if (($before['status'] ?? null) !== 'held') {
                continue;
            }
            $after = null;
            foreach ($next as $step) {
                if (($step['id'] ?? null) === ($before['id'] ?? null)) {
                    $after = $step;
                    break;
                }
            }
            if ($after === null) {
                return ['ok' => false, 'error' => 'plan.held'];
            }
            if ($after['status'] === 'held') {
                continue;
            }
            $approval = null;
            if (is_string($before['approvalId'] ?? null)) {
                foreach ($approvals as $a) {
                    if (($a['approvalId'] ?? null) === $before['approvalId']) {
                        $approval = $a;
                        break;
                    }
                }
            }
            if ($approval === null) {
                return ['ok' => false, 'error' => 'plan.held'];
            }
            if (in_array($approval['state'] ?? null, self::RELEASED_BY, true)) {
                continue;
            }
            if (in_array($approval['state'] ?? null, self::REFUSED_BY, true) && in_array($after['status'], ['skipped', 'failed'], true)) {
                continue;
            }

            return ['ok' => false, 'error' => 'plan.held'];
        }

        return ['ok' => true];
    }
}
