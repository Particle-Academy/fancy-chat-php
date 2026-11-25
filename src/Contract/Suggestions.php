<?php

declare(strict_types=1);

namespace FancyChat\Contract;

/**
 * Quick replies, ported from fancy-chat-js `suggestions.ts` (CC-fancy-0065).
 * Same rules, same order, same output; the shared fixture's `suggestions`
 * section holds both legs to it.
 */
final class Suggestions
{
    /** @var array{maxCount: int, maxChars: int} */
    public const array DEFAULT_LIMITS = ['maxCount' => 3, 'maxChars' => 80];

    /** One clean line: a non-empty string with no control or format characters and no padding. */
    public static function isText(mixed $text): bool
    {
        return is_string($text) && $text !== '' && mb_check_encoding($text, 'UTF-8')
            && preg_match('/[\p{Cc}\p{Cf}]/u', $text) === 0 && $text === trim($text, ' ');
    }

    /**
     * What the server runs on the model's output before appending anything:
     * strings only; control characters become spaces, format characters are
     * removed, white space collapses and the ends are trimmed; empty and
     * duplicate items are dropped; one longer than `maxChars` code points is
     * dropped, never cut; at most `maxCount` are kept; and a refused phrase
     * (normalised as typed approval normalises) is dropped.
     *
     * @param  array{maxCount?: mixed, maxChars?: mixed}  $limits
     * @param  list<mixed>  $refuse
     * @return list<array{id: string, text: string}>
     */
    public static function sanitize(mixed $raw, array $limits = [], array $refuse = []): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            return [];
        }
        $maxCount = self::limit($limits['maxCount'] ?? null, self::DEFAULT_LIMITS['maxCount']);
        $maxChars = self::limit($limits['maxChars'] ?? null, self::DEFAULT_LIMITS['maxChars']);
        $refused = [];
        foreach ($refuse as $phrase) {
            if (is_string($phrase) && ($n = TypedApproval::normalize($phrase)) !== '') {
                $refused[$n] = true;
            }
        }
        $seen = [];
        $out = [];
        foreach ($raw as $item) {
            if (count($out) >= $maxCount) {
                break;
            }
            if (! is_string($item) || ! mb_check_encoding($item, 'UTF-8')) {
                continue;
            }
            $text = self::clean($item);
            if ($text === '' || mb_strlen($text, 'UTF-8') > $maxChars || isset($seen[$text]) || isset($refused[TypedApproval::normalize($text)])) {
                continue;
            }
            $seen[$text] = true;
            $out[] = ['id' => 's'.(count($out) + 1), 'text' => $text];
        }

        return $out;
    }

    /**
     * Checks a `suggestions.updated` item list: plain objects, unique non-empty ids, clean text.
     *
     * @return array{ok: true, items: list<array{id: string, text: string}>}|array{ok: false, error: string}
     */
    public static function checkItems(mixed $items): array
    {
        if (! Json::isList($items)) {
            return ['ok' => false, 'error' => 'suggestions.items'];
        }
        $ids = [];
        $out = [];
        foreach ($items as $item) {
            if (! Json::isObject($item) || ! is_string($item['id'] ?? null) || $item['id'] === '' || isset($ids[$item['id']])) {
                return ['ok' => false, 'error' => 'suggestions.id'];
            }
            if (! self::isText($item['text'] ?? null)) {
                return ['ok' => false, 'error' => 'suggestions.text'];
            }
            $ids[$item['id']] = true;
            $out[] = ['id' => $item['id'], 'text' => $item['text']];
        }

        return ['ok' => true, 'items' => $out];
    }

    private static function limit(mixed $value, int $fallback): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        return is_float($value) && is_finite($value) ? max(0, (int) floor($value)) : $fallback;
    }

    private static function clean(string $text): string
    {
        $text = (string) preg_replace('/\p{Cc}/u', ' ', $text);
        $text = (string) preg_replace('/\p{Cf}/u', '', $text);
        $text = (string) preg_replace('/\p{Z}+/u', ' ', $text);

        return trim($text, ' ');
    }
}
