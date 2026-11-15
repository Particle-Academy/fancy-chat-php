<?php

declare(strict_types=1);

namespace FancyChat\Contract;

use stdClass;

/**
 * JSON shape helpers. PHP decodes `{}` and `[]` to the same empty array, so an
 * "is this an object" test treats the empty array as an empty object, and the
 * wire encoder turns empty map-shaped fields back into `{}`.
 */
final class Json
{
    /** Fields whose value is a JSON object on the wire, even when empty. */
    private const array MAP_KEYS = [
        'data', 'args', 'values', 'notes', 'snapshot', 'actor', 'author', 'decidedBy', 'answeredBy',
        'echarts', 'spec', 'meta', 'message', 'part', 'approval', 'question', 'progress', 'limit', 'x', 'capabilities',
    ];

    /** Fields whose value is a list of JSON objects on the wire. */
    private const array LIST_OF_MAP_KEYS = [
        'rows', 'columns', 'options', 'fields', 'actions', 'steps', 'preview', 'parts', 'body', 'series',
        'order', 'messages', 'turns', 'thinking', 'tools', 'plans', 'approvals', 'questions', 'events', 'undos', 'branches',
    ];

    /** A JSON object as PHP decodes it: an associative array, or the empty array. */
    public static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /** A JSON array: a list (the empty array included). */
    public static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /** An integer in the JSON sense: an int, or an integral float (as JS `Number.isInteger`). */
    public static function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
    }

    public static function isNumber(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    /** The byte size fancy-chat-js measures: unescaped slashes and unicode. */
    public static function bytes(mixed $value): int
    {
        return strlen((string) json_encode(self::toWire($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** JavaScript's String.prototype.trim: every Unicode white space and line terminator. */
    public static function jsTrim(string $value): string
    {
        return (string) preg_replace('/^[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u', '', $value);
    }

    /**
     * The value as it must go on the wire: every empty map-shaped field becomes
     * `{}` (a stdClass), recursively, so a client never receives `"args": []`.
     */
    public static function toWire(mixed $value, ?string $key = null, bool $inMapList = false): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if ($value === []) {
            if (($key !== null && in_array($key, self::MAP_KEYS, true)) || $inMapList) {
                return new stdClass;
            }

            return [];
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $item) {
            if ($isList) {
                $out[$k] = self::toWire($item, null, $key !== null && in_array($key, self::LIST_OF_MAP_KEYS, true));
            } else {
                $out[$k] = self::toWire($item, (string) $k);
            }
        }

        return $out;
    }

    public static function encode(mixed $value): string
    {
        return json_encode(self::toWire($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
