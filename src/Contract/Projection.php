<?php

declare(strict_types=1);

namespace FancyChat\Contract;

/**
 * The thread projection, ported from fancy-chat-js `projection.ts`. The state
 * is plain JSON and IS the snapshot format a `thread.reset` carries; rows come
 * out identical to the TS leg (every field present, null rather than absent).
 *
 * Idempotent: an event at or below `lastSeq` changes nothing. An illegal event
 * bumps `illegal` and leaves every entry untouched.
 */
final class Projection
{
    private const string NO_RUN = '_';

    /** @return array<string, mixed> */
    public static function emptyState(): array
    {
        return ['lastSeq' => 0, 'illegal' => 0, 'order' => [], 'messages' => [], 'turns' => [], 'thinking' => [], 'tools' => [], 'plans' => [], 'approvals' => [], 'questions' => []];
    }

    /** @param  list<array<string, mixed>>  $list */
    private static function indexOf(array $list, string $key, mixed $id): ?int
    {
        foreach ($list as $i => $item) {
            if (($item[$key] ?? null) === $id) {
                return $i;
            }
        }

        return null;
    }

    /**
     * A decision or answer counts only when the event's actor is a human AND the
     * recorded decider is that same human.
     *
     * @param  array<string, mixed>  $event
     */
    private static function decidedByActor(array $event, mixed $claimed): bool
    {
        $actor = $event['actor'] ?? null;

        return Hitl::canDecide($actor) && Json::isObject($claimed)
            && ($claimed['kind'] ?? null) === $actor['kind'] && ($claimed['id'] ?? null) === $actor['id'];
    }

    /**
     * Applies one event to the state, in place. Returns whether it was applied.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $event
     */
    public static function apply(array &$state, array $event): bool
    {
        $seq = $event['seq'] ?? 0;
        if ($seq <= $state['lastSeq']) {
            return false;
        }
        $data = Json::isObject($event['data'] ?? null) ? $event['data'] : [];
        $illegal = function () use (&$state): void {
            $state['illegal']++;
        };
        $type = $event['type'] ?? '';

        switch ($type) {
            case 'thread.snapshot':
            case 'thread.reset':
                $snap = $data['snapshot'] ?? null;
                if (! Json::isObject($snap)) {
                    $illegal();
                    break;
                }
                $state = array_merge(self::emptyState(), $snap);
                break;

            case 'message.created':
                $m = $data['message'] ?? null;
                if (! Json::isObject($m) || ! is_string($m['id'] ?? null) || self::indexOf($state['messages'], 'id', $m['id']) !== null) {
                    $illegal();
                    break;
                }
                $supersedes = is_string($m['supersedes'] ?? null) ? $m['supersedes'] : null;
                $state['messages'][] = [
                    'id' => $m['id'],
                    'role' => is_string($m['role'] ?? null) ? $m['role'] : 'assistant',
                    'author' => Json::isObject($m['author'] ?? null) ? $m['author'] : $event['actor'],
                    'turnId' => is_string($data['turnId'] ?? null) ? $data['turnId'] : ($event['turnId'] ?? null),
                    'parts' => Json::isList($m['parts'] ?? null) ? $m['parts'] : [],
                    'open' => [],
                    'delivery' => is_string($m['delivery'] ?? null) ? $m['delivery'] : null,
                    'supersedes' => $supersedes,
                    'retracted' => false,
                    'superseded' => false,
                ];
                if ($supersedes !== null) {
                    $t = self::indexOf($state['messages'], 'id', $supersedes);
                    if ($t !== null) {
                        $state['messages'][$t]['superseded'] = true;
                    }
                }
                $state['order'][] = ['kind' => 'message', 'id' => $m['id']];
                break;

            case 'message.updated':
                $i = self::indexOf($state['messages'], 'id', $data['messageId'] ?? null);
                if ($i === null || ! Json::isList($data['parts'] ?? null)) {
                    $illegal();
                    break;
                }
                $state['messages'][$i]['parts'] = $data['parts'];
                $ids = array_map(fn (array $p): mixed => $p['id'] ?? null, $data['parts']);
                $state['messages'][$i]['open'] = array_values(array_filter($state['messages'][$i]['open'], fn ($id): bool => in_array($id, $ids, true)));
                break;

            case 'message.retracted':
                $i = self::indexOf($state['messages'], 'id', $data['messageId'] ?? null);
                if ($i === null) {
                    $illegal();
                } else {
                    $state['messages'][$i]['retracted'] = true;
                }
                break;

            case 'delivery.changed':
                $i = self::indexOf($state['messages'], 'id', $data['messageId'] ?? null);
                $next = $i !== null && $state['messages'][$i]['delivery'] !== null && is_string($data['action'] ?? null)
                    ? Machines::delivery($state['messages'][$i]['delivery'], $data['action']) : null;
                if ($i === null || $next === null || ! $next['ok']) {
                    $illegal();
                } else {
                    $state['messages'][$i]['delivery'] = $next['to'];
                }
                break;

            case 'part.started':
                $i = self::indexOf($state['messages'], 'id', $data['messageId'] ?? null);
                $part = $data['part'] ?? null;
                if ($i === null || ! Json::isObject($part) || ! is_string($part['id'] ?? null) || self::indexOf($state['messages'][$i]['parts'], 'id', $part['id']) !== null) {
                    $illegal();
                    break;
                }
                $state['messages'][$i]['parts'][] = $part;
                $state['messages'][$i]['open'][] = $part['id'];
                break;

            case 'part.delta':
                $i = self::indexOf($state['messages'], 'id', $data['messageId'] ?? null);
                $j = $i === null ? null : self::indexOf($state['messages'][$i]['parts'], 'id', $data['partId'] ?? null);
                if ($i === null || $j === null) {
                    $illegal();
                    break;
                }
                $current = $state['messages'][$i]['parts'][$j];
                if (($current['kind'] ?? null) === 'text' && is_string($data['text'] ?? null)) {
                    $state['messages'][$i]['parts'][$j]['text'] = (string) ($current['text'] ?? '').$data['text'];
                } elseif (Json::isObject($data['part'] ?? null)) {
                    $state['messages'][$i]['parts'][$j] = array_merge($data['part'], ['id' => $current['id']]);
                } else {
                    $illegal();
                }
                break;

            case 'part.completed':
                $i = self::indexOf($state['messages'], 'id', $data['messageId'] ?? null);
                if ($i === null || self::indexOf($state['messages'][$i]['parts'], 'id', $data['partId'] ?? null) === null) {
                    $illegal();
                } else {
                    $state['messages'][$i]['open'] = array_values(array_filter($state['messages'][$i]['open'], fn ($id): bool => $id !== $data['partId']));
                }
                break;

            case 'thinking.started':
            case 'thinking.delta':
            case 'thinking.completed':
                $runId = $event['runId'] ?? self::NO_RUN;
                $i = self::indexOf($state['thinking'], 'runId', $runId);
                if ($i === null) {
                    if ($type === 'thinking.completed') {
                        $illegal();
                        break;
                    }
                    $state['thinking'][] = ['runId' => $runId, 'text' => '', 'live' => true];
                    $state['order'][] = ['kind' => 'thinking', 'id' => $runId];
                    $i = count($state['thinking']) - 1;
                }
                if ($type === 'thinking.started') {
                    $state['thinking'][$i]['live'] = true;
                } elseif ($type === 'thinking.delta' && is_string($data['text'] ?? null)) {
                    $state['thinking'][$i]['text'] .= $data['text'];
                } elseif ($type === 'thinking.completed') {
                    $state['thinking'][$i]['live'] = false;
                }
                break;

            case 'tool.called':
                if (! is_string($data['toolCallId'] ?? null) || ! is_string($data['name'] ?? null) || self::indexOf($state['tools'], 'toolCallId', $data['toolCallId']) !== null) {
                    $illegal();
                    break;
                }
                $state['tools'][] = [
                    'toolCallId' => $data['toolCallId'],
                    'runId' => $event['runId'] ?? null,
                    'name' => $data['name'],
                    'args' => Json::isObject($data['args'] ?? null) ? $data['args'] : [],
                    'startedAt' => is_string($data['startedAt'] ?? null) ? $data['startedAt'] : null,
                    'progress' => null,
                    'outcome' => null,
                    'durationMs' => null,
                    'outputRef' => null,
                    'truncated' => false,
                ];
                $state['order'][] = ['kind' => 'tool', 'id' => $data['toolCallId']];
                break;

            case 'tool.progress':
                $i = self::indexOf($state['tools'], 'toolCallId', $data['toolCallId'] ?? null);
                if ($i === null) {
                    $illegal();
                    break;
                }
                $state['tools'][$i]['progress'] = [
                    'fraction' => Json::isNumber($data['fraction'] ?? null) ? $data['fraction'] : null,
                    'text' => is_string($data['text'] ?? null) ? $data['text'] : null,
                ];
                break;

            case 'tool.result':
                $i = self::indexOf($state['tools'], 'toolCallId', $data['toolCallId'] ?? null);
                $outcome = $data['outcome'] ?? null;
                if ($i === null || ! in_array($outcome, ['ok', 'failure', 'refusal'], true)) {
                    $illegal();
                    break;
                }
                $state['tools'][$i]['outcome'] = $outcome;
                $state['tools'][$i]['durationMs'] = Json::isNumber($data['durationMs'] ?? null) ? $data['durationMs'] : null;
                $state['tools'][$i]['outputRef'] = is_string($data['outputRef'] ?? null) ? $data['outputRef'] : null;
                $state['tools'][$i]['truncated'] = ($data['truncated'] ?? null) === true;
                break;

            case 'plan.updated':
                if (! Json::isList($data['steps'] ?? null)) {
                    $illegal();
                    break;
                }
                $runId = $event['runId'] ?? self::NO_RUN;
                $i = self::indexOf($state['plans'], 'runId', $runId);
                if ($i === null) {
                    $state['plans'][] = ['runId' => $runId, 'steps' => []];
                    $state['order'][] = ['kind' => 'plan', 'id' => $runId];
                    $i = count($state['plans']) - 1;
                }
                $state['plans'][$i]['steps'] = $data['steps'];
                break;

            case 'turn.started':
                if (! is_string($data['turnId'] ?? null) || self::indexOf($state['turns'], 'turnId', $data['turnId']) !== null) {
                    $illegal();
                    break;
                }
                $state['turns'][] = ['turnId' => $data['turnId'], 'state' => 'pending', 'outcome' => null, 'reason' => null];
                break;

            case 'turn.transition':
            case 'turn.settled':
                $i = self::indexOf($state['turns'], 'turnId', $data['turnId'] ?? null);
                $action = is_string($data['action'] ?? null) ? $data['action'] : '';
                // Only turn.settled settles a turn; a transition never may.
                $settling = Machines::isSettlingTurnAction($action);
                if ($i === null || $settling !== ($type === 'turn.settled')) {
                    $illegal();
                    break;
                }
                $next = Machines::turn($state['turns'][$i]['state'], $action);
                if (! $next['ok']) {
                    $illegal();
                    break;
                }
                $state['turns'][$i]['state'] = $next['to'];
                $state['turns'][$i]['outcome'] = $next['outcome'];
                $state['turns'][$i]['reason'] = is_string($data['reason'] ?? null) ? $data['reason'] : $next['reason'];
                break;

            case 'approval.requested':
                $a = $data['approval'] ?? null;
                if (! Json::isObject($a) || ! is_string($a['approvalId'] ?? null) || self::indexOf($state['approvals'], 'approvalId', $a['approvalId']) !== null) {
                    $illegal();
                    break;
                }
                $state['approvals'][] = array_merge($a, ['state' => 'pending']);
                $state['order'][] = ['kind' => 'approval', 'id' => $a['approvalId']];
                break;

            case 'approval.decided':
                $i = self::indexOf($state['approvals'], 'approvalId', $data['approvalId'] ?? null);
                if ($i === null || ! self::decidedByActor($event, $data['decidedBy'] ?? null) || ! is_string($data['decision'] ?? null)) {
                    $illegal();
                    break;
                }
                $current = $state['approvals'][$i]['state'];
                $from = $current === 'pending' ? Machines::approval('pending', 'begin-decision') : ['ok' => true, 'to' => $current];
                $next = $from['ok'] ? Machines::approval($from['to'], $data['decision']) : $from;
                if (! $next['ok']) {
                    $illegal();
                    break;
                }
                $state['approvals'][$i]['state'] = $next['to'];
                $state['approvals'][$i]['decision'] = $data['decision'];
                $state['approvals'][$i]['decidedBy'] = $data['decidedBy'];
                $state['approvals'][$i]['optionId'] = is_string($data['optionId'] ?? null) ? $data['optionId'] : null;
                $state['approvals'][$i]['note'] = is_string($data['note'] ?? null) ? $data['note'] : null;
                break;

            case 'approval.applied':
                $i = self::indexOf($state['approvals'], 'approvalId', $data['approvalId'] ?? null);
                $next = $i === null ? null : Machines::approval($state['approvals'][$i]['state'], ($data['ok'] ?? null) === true ? 'apply' : 'apply-fail');
                if ($i === null || ! $next['ok']) {
                    $illegal();
                    break;
                }
                $state['approvals'][$i]['state'] = $next['to'];
                $state['approvals'][$i]['error'] = is_string($data['error'] ?? null) ? $data['error'] : null;
                break;

            case 'approval.withdrawn':
                $i = self::indexOf($state['approvals'], 'approvalId', $data['approvalId'] ?? null);
                $next = $i === null ? null : Machines::approval($state['approvals'][$i]['state'], ($data['expired'] ?? null) === true ? 'expire' : 'withdraw');
                if ($i === null || ! $next['ok']) {
                    $illegal();
                } else {
                    $state['approvals'][$i]['state'] = $next['to'];
                }
                break;

            case 'question.asked':
                $q = $data['question'] ?? null;
                if (! Json::isObject($q) || ! is_string($q['questionId'] ?? null) || self::indexOf($state['questions'], 'questionId', $q['questionId']) !== null) {
                    $illegal();
                    break;
                }
                $state['questions'][] = array_merge($q, ['state' => 'open']);
                $state['order'][] = ['kind' => 'question', 'id' => $q['questionId']];
                break;

            case 'question.answered':
                $i = self::indexOf($state['questions'], 'questionId', $data['questionId'] ?? null);
                if ($i === null || $state['questions'][$i]['state'] !== 'open' || ! self::decidedByActor($event, $data['answeredBy'] ?? null)) {
                    $illegal();
                    break;
                }
                $state['questions'][$i]['state'] = 'answered';
                $state['questions'][$i]['answeredBy'] = $data['answeredBy'];
                $state['questions'][$i]['values'] = Json::isObject($data['values'] ?? null) ? $data['values'] : [];
                $state['questions'][$i]['notes'] = Json::isObject($data['notes'] ?? null) ? $data['notes'] : [];
                break;

            default:
                // Runs, usage, agent status, undo, handoff and x-* events carry no row.
                break;
        }

        $state['lastSeq'] = $seq;

        return true;
    }

    /**
     * The rows a thread renders, in order of first appearance.
     *
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    public static function rows(array $state): array
    {
        $rows = [];
        foreach ($state['order'] as $ref) {
            $kind = $ref['kind'];
            $id = $ref['id'];
            switch ($kind) {
                case 'message':
                    $i = self::indexOf($state['messages'], 'id', $id);
                    if ($i !== null) {
                        $rows[] = ['kind' => 'message'] + $state['messages'][$i];
                    }
                    break;
                case 'thinking':
                    $i = self::indexOf($state['thinking'], 'runId', $id);
                    if ($i !== null) {
                        $rows[] = ['kind' => 'thinking', 'id' => $id] + $state['thinking'][$i];
                    }
                    break;
                case 'tool':
                    $i = self::indexOf($state['tools'], 'toolCallId', $id);
                    if ($i !== null) {
                        $rows[] = ['kind' => 'tool', 'id' => $id] + $state['tools'][$i];
                    }
                    break;
                case 'plan':
                    $i = self::indexOf($state['plans'], 'runId', $id);
                    if ($i !== null) {
                        $rows[] = ['kind' => 'plan', 'id' => $id] + $state['plans'][$i];
                    }
                    break;
                case 'approval':
                    $i = self::indexOf($state['approvals'], 'approvalId', $id);
                    if ($i !== null) {
                        $rows[] = ['kind' => 'approval', 'id' => $id, 'approvalId' => $id, 'state' => $state['approvals'][$i]['state']];
                    }
                    break;
                case 'question':
                    $i = self::indexOf($state['questions'], 'questionId', $id);
                    if ($i !== null) {
                        $rows[] = ['kind' => 'question', 'id' => $id, 'questionId' => $id, 'state' => $state['questions'][$i]['state'] ?? 'open'];
                    }
                    break;
            }
        }

        return $rows;
    }

    /**
     * Pure: folds events, in the order given, into the projected thread.
     *
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>|null  $initial
     * @return array{lastSeq: int, illegal: int, rows: list<array<string, mixed>>, turns: list<array<string, mixed>>, approvals: list<array<string, mixed>>, questions: list<array<string, mixed>>, state: array<string, mixed>}
     */
    public static function project(array $events, ?array $initial = null): array
    {
        $state = $initial ?? self::emptyState();
        foreach ($events as $event) {
            self::apply($state, $event);
        }

        return [
            'lastSeq' => $state['lastSeq'],
            'illegal' => $state['illegal'],
            'rows' => self::rows($state),
            'turns' => $state['turns'],
            'approvals' => $state['approvals'],
            'questions' => $state['questions'],
            'state' => $state,
        ];
    }
}
