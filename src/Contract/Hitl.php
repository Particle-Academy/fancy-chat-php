<?php

declare(strict_types=1);

namespace FancyChat\Contract;

/**
 * Human-in-the-loop helpers, ported from fancy-chat-js `hitl.ts`.
 */
final class Hitl
{
    private const array ACTIONABLE = ['approval' => 'pending', 'question' => 'open'];

    private const array IN_FLIGHT = ['approval' => 'deciding', 'question' => 'answering'];

    /**
     * Only a human may decide an approval or answer a question: kind exactly
     * `human`, id non-empty. The host's authenticated resolver produces this
     * actor, never a request body.
     */
    public static function canDecide(mixed $actor): bool
    {
        return Json::isObject($actor) && ($actor['kind'] ?? null) === 'human' && is_string($actor['id'] ?? null) && $actor['id'] !== '';
    }

    /** A tool name in the human's confirmation channel. Never offered to an agent. */
    public static function isReservedToolName(string $name): bool
    {
        $lower = mb_strtolower($name);
        $reserved = Machines::table()['reservedTools'];
        if (in_array($lower, $reserved['exact'], true)) {
            return true;
        }
        foreach ($reserved['contains'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function hasValue(string $type, mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if ($type === 'multi') {
            return Json::isList($value) && $value !== [];
        }

        return is_string($value) && Json::jsTrim($value) !== '';
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $draft  {values, notes}
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public static function canSubmitAnswer(array $question, array $draft): array
    {
        $fields = [];
        foreach ($question['fields'] as $field) {
            $fields[$field['id']] = $field;
        }
        $values = Json::isObject($draft['values'] ?? null) ? $draft['values'] : [];
        $notes = Json::isObject($draft['notes'] ?? null) ? $draft['notes'] : [];

        foreach ($values as $id => $value) {
            $field = $fields[(string) $id] ?? null;
            if ($field === null) {
                return ['ok' => false, 'error' => 'question.field'];
            }
            if ($value === null) {
                continue;
            }
            if ($field['type'] === 'multi') {
                if (! Json::isList($value) || array_filter($value, fn ($v): bool => ! is_string($v)) !== []) {
                    return ['ok' => false, 'error' => 'question.value'];
                }
            } elseif (! is_string($value)) {
                return ['ok' => false, 'error' => 'question.value'];
            }
            if ($field['type'] !== 'text') {
                $offered = array_map(fn (array $o): string => $o['id'], $field['options'] ?? []);
                $chosen = is_array($value) ? $value : [$value];
                foreach ($chosen as $choice) {
                    if (! in_array($choice, $offered, true)) {
                        return ['ok' => false, 'error' => 'question.option'];
                    }
                }
            }
        }

        foreach ($question['fields'] as $field) {
            if (! ($field['required'] ?? false)) {
                continue;
            }
            if (self::hasValue($field['type'], $values[$field['id']] ?? null)) {
                continue;
            }
            $note = $notes[$field['id']] ?? null;
            if (($question['allowNote'] ?? false) && is_string($note) && Json::jsTrim($note) !== '') {
                continue;
            }

            return ['ok' => false, 'error' => 'question.required'];
        }

        return ['ok' => true];
    }

    /**
     * Exactly one HITL item is actionable within a coordination scope: the
     * oldest by seq. While any decision is in flight, none is.
     *
     * @param  list<array{id: string, kind: string, state: string, seq: int}>  $items
     * @param  list<string>|null  $kinds
     */
    public static function pickActionable(array $items, ?array $kinds = null): ?string
    {
        $scoped = $kinds === null ? $items : array_values(array_filter($items, fn (array $i): bool => in_array($i['kind'], $kinds, true)));
        foreach ($scoped as $item) {
            if ((self::IN_FLIGHT[$item['kind']] ?? null) === $item['state']) {
                return null;
            }
        }
        $best = null;
        foreach ($scoped as $item) {
            if ((self::ACTIONABLE[$item['kind']] ?? null) !== $item['state']) {
                continue;
            }
            if ($best === null || $item['seq'] < $best['seq']) {
                $best = $item;
            }
        }

        return $best['id'] ?? null;
    }
}
