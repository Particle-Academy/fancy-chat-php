<?php

declare(strict_types=1);

namespace FancyChat\Contract;

/**
 * The v1 event envelope. Refuses an unknown major (`contract.version`) and
 * ignores unknown fields; never guesses. Errors name the field, never a value.
 * Same checks, same order, same codes as fancy-chat-js `validateEvent`.
 */
final class Envelope
{
    public const array CORE_TYPES = [
        'thread.snapshot', 'thread.reset',
        'run.started', 'run.finished', 'run.failed', 'run.cancelled',
        'turn.started', 'turn.transition', 'turn.settled',
        'message.created', 'message.updated', 'message.retracted', 'delivery.changed',
        'part.started', 'part.delta', 'part.completed',
        'thinking.started', 'thinking.delta', 'thinking.completed',
        'tool.called', 'tool.progress', 'tool.result', 'plan.updated',
        'approval.requested', 'approval.decided', 'approval.applied', 'approval.withdrawn',
        'question.asked', 'question.answered',
        'undo.opened', 'undo.committed', 'undo.reverted',
        'agent.status', 'usage', 'provider.fallback', 'control.handoff',
    ];

    private const string EXTENSION = '/^x-[a-z0-9][a-z0-9-]*\.[a-z0-9][a-z0-9._-]*$/';

    private const string TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    private const array ACTOR_KINDS = ['agent', 'human', 'system'];

    private const array OPTIONAL_IDS = ['runId', 'turnId', 'parentRunId', 'rootRunId'];

    /** An ISO 8601 timestamp with a zone, as every `at` and deadline carries. */
    public static function isTimestamp(mixed $value): bool
    {
        return is_string($value) && preg_match(self::TIMESTAMP, $value) === 1;
    }

    public static function isEventType(mixed $type): bool
    {
        return is_string($type) && (in_array($type, self::CORE_TYPES, true) || preg_match(self::EXTENSION, $type) === 1);
    }

    /** @return array{ok: true}|array{ok: false, error: string} */
    public static function validate(mixed $e): array
    {
        if (! Json::isObject($e)) {
            return ['ok' => false, 'error' => 'event.shape'];
        }
        if (! array_key_exists('v', $e) || ! Json::isInteger($e['v']) || (int) $e['v'] !== 1) {
            return ['ok' => false, 'error' => 'contract.version'];
        }
        $seq = $e['seq'] ?? null;
        if (! Json::isInteger($seq) || $seq < 1) {
            return ['ok' => false, 'error' => 'event.seq'];
        }
        if (! is_string($e['threadId'] ?? null) || $e['threadId'] === '') {
            return ['ok' => false, 'error' => 'event.threadId'];
        }
        if (! self::isEventType($e['type'] ?? null)) {
            return ['ok' => false, 'error' => 'event.type'];
        }
        if (! self::isTimestamp($e['at'] ?? null)) {
            return ['ok' => false, 'error' => 'event.at'];
        }
        $actor = $e['actor'] ?? null;
        if (! Json::isObject($actor) || ! is_string($actor['kind'] ?? null) || ! in_array($actor['kind'], self::ACTOR_KINDS, true) || ! is_string($actor['id'] ?? null)) {
            return ['ok' => false, 'error' => 'event.actor'];
        }
        if (! array_key_exists('data', $e) || ! Json::isObject($e['data'])) {
            return ['ok' => false, 'error' => 'event.data'];
        }
        foreach (self::OPTIONAL_IDS as $key) {
            $value = $e[$key] ?? null;
            if ($value !== null && ! is_string($value)) {
                return ['ok' => false, 'error' => 'event.'.$key];
            }
        }

        return ['ok' => true];
    }
}
