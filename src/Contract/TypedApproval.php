<?php

declare(strict_types=1);

namespace FancyChat\Contract;

use Normalizer;

/**
 * Typed approval, ported from fancy-chat-js `typed-approval.ts`. OPT-IN PER APP
 * (owner, 2026-11-15): off by default and no phrases until the host sets them.
 * Deterministic, never an LLM. A match takes the same decide path as the
 * decision endpoint.
 */
final class TypedApproval
{
    /** @var array{enabled: bool, locale: string, phrases: array<string, array{approve?: list<string>, reject?: list<string>}>} */
    public const array DEFAULT = ['enabled' => false, 'locale' => 'en', 'phrases' => []];

    /** English phrases a host may opt into. Never applied unless passed in `phrases`. */
    public const array ENGLISH = ['approve' => ['approve', 'yes, approve'], 'reject' => ['reject', 'no, reject']];

    private const array TRAILING_PUNCTUATION = ['.', '!', '?', "\u{3002}", "\u{FF01}", "\u{FF1F}"];

    private const array SPACES = [' ', "\t", "\n", "\r", "\f", "\v"];

    /**
     * NFKC, lower case, trimmed (JavaScript's trim), trailing `.`/`!`/`?`
     * dropped, inner ASCII white space collapsed to one space. Scans, never a
     * backtracking regex.
     */
    public static function normalize(string $text): string
    {
        $folded = Normalizer::normalize($text, Normalizer::FORM_KC);
        $s = Json::jsTrim(mb_strtolower(is_string($folded) ? $folded : $text, 'UTF-8'));
        $chars = mb_str_split($s, 1, 'UTF-8');
        $end = count($chars);
        while ($end > 0 && in_array($chars[$end - 1], self::TRAILING_PUNCTUATION, true)) {
            $end--;
        }
        $chars = mb_str_split(Json::jsTrim(implode('', array_slice($chars, 0, $end))), 1, 'UTF-8');
        $out = '';
        $space = false;
        foreach ($chars as $ch) {
            if (in_array($ch, self::SPACES, true)) {
                $space = true;

                continue;
            }
            if ($space && $out !== '') {
                $out .= ' ';
            }
            $space = false;
            $out .= $ch;
        }

        return $out;
    }

    /**
     * The decision a typed message makes on a pending approval, or null. Only an
     * exact phrase matches, a typed phrase picks a ONE-TIME option only, and
     * edit is never typed.
     *
     * @param  array<string, mixed>|null  $approval  state, options
     * @param  array<string, mixed>  $config
     * @return array{optionId: string, decision: string}|null
     */
    public static function match(string $text, ?array $approval, array $config = []): ?array
    {
        $cfg = array_merge(self::DEFAULT, $config);
        if ($cfg['enabled'] !== true || $approval === null || ($approval['state'] ?? null) !== 'pending') {
            return null;
        }
        $lists = $cfg['phrases'][$cfg['locale']] ?? null;
        if (! is_array($lists)) {
            return null;
        }
        $typed = self::normalize($text);
        if ($typed === '') {
            return null;
        }
        $decision = null;
        foreach ((array) ($lists['approve'] ?? []) as $phrase) {
            if (is_string($phrase) && self::normalize($phrase) === $typed) {
                $decision = 'approve';
            }
        }
        if ($decision === null) {
            foreach ((array) ($lists['reject'] ?? []) as $phrase) {
                if (is_string($phrase) && self::normalize($phrase) === $typed) {
                    $decision = 'reject';
                }
            }
        }
        if ($decision === null) {
            return null;
        }
        $once = $decision === 'approve' ? 'allow-once' : 'reject-once';
        foreach ((array) ($approval['options'] ?? []) as $option) {
            if (is_array($option) && ($option['kind'] ?? null) === $once && Machines::optionTone($once)['decision'] === $decision && is_string($option['id'] ?? null)) {
                return ['optionId' => $option['id'], 'decision' => $decision];
            }
        }

        return null;
    }
}
