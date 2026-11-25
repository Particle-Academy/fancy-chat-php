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
        return ['lastSeq' => 0, 'illegal' => 0, 'order' => [], 'messages' => [], 'turns' => [], 'thinking' => [], 'tools' => [], 'plans' => [], 'approvals' => [], 'questions' => [], 'undos' => [], 'branches' => [], 'suggestions' => null];
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
                $targetAt = null;
                if ($supersedes !== null) {
                    foreach ($state['order'] as $k => $ref) {
                        if ($ref['kind'] === 'message' && $ref['id'] === $supersedes) {
                            $targetAt = $k;
                            break;
                        }
                    }
                }
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
                if ($supersedes !== null && $targetAt !== null) {
                    // Edit and resend: the rows the new message replaces. The host's policy decides their fate.
                    $state['branches'][] = [
                        'supersededId' => $supersedes,
                        'byId' => $m['id'],
                        'rowIds' => array_values(array_map(fn (array $ref): string => $ref['id'], array_slice($state['order'], $targetAt + 1))),
                    ];
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
                if (array_key_exists('thinkingId', $data) && ! is_string($data['thinkingId'])) {
                    $illegal();
                    break;
                }
                $explicit = is_string($data['thinkingId'] ?? null) ? $data['thinkingId'] : null;
                // Without an explicit id: the run's newest block, unless it has completed and
                // this event opens new thinking (fancy-chat-js projection, ThinkingEntry).
                $ofRun = array_keys(array_filter($state['thinking'], fn (array $t): bool => $t['runId'] === $runId));
                $latest = $ofRun === [] ? null : $ofRun[count($ofRun) - 1];
                if ($explicit !== null) {
                    $i = self::indexOf($state['thinking'], 'thinkingId', $explicit);
                } else {
                    $i = $latest !== null && ($state['thinking'][$latest]['live'] || $type === 'thinking.completed') ? $latest : null;
                }
                if ($i === null) {
                    if ($type === 'thinking.completed') {
                        $illegal();
                        break;
                    }
                    $thinkingId = $explicit ?? ($ofRun === [] ? $runId : $runId.'#'.(count($ofRun) + 1));
                    if (self::indexOf($state['thinking'], 'thinkingId', $thinkingId) !== null) {
                        $illegal();
                        break;
                    }
                    $state['thinking'][] = ['thinkingId' => $thinkingId, 'runId' => $runId, 'text' => '', 'live' => true];
                    $state['order'][] = ['kind' => 'thinking', 'id' => $thinkingId];
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
                    'durationExact' => true,
                    'outputRef' => null,
                    'truncated' => false,
                    'output' => null,
                    'outputLanguage' => null,
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
                $state['tools'][$i]['durationExact'] = ($data['durationExact'] ?? null) !== false;
                $state['tools'][$i]['outputRef'] = is_string($data['outputRef'] ?? null) ? $data['outputRef'] : null;
                $state['tools'][$i]['truncated'] = ($data['truncated'] ?? null) === true;
                // Plain text only: render as text or code, never HTML (fancy-chat-js ToolEntry).
                $state['tools'][$i]['output'] = is_string($data['output'] ?? null) ? $data['output'] : null;
                $state['tools'][$i]['outputLanguage'] = is_string($data['outputLanguage'] ?? null) ? $data['outputLanguage'] : null;
                break;

            case 'plan.updated':
                if (! Json::isList($data['steps'] ?? null)) {
                    $illegal();
                    break;
                }
                $runId = $event['runId'] ?? self::NO_RUN;
                $i = self::indexOf($state['plans'], 'runId', $runId);
                // A held step releases only through its approval.
                if (! Plan::check($i === null ? [] : $state['plans'][$i]['steps'], $data['steps'], $state['approvals'])['ok']) {
                    $illegal();
                    break;
                }
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
                // The message that started the turn, both ways (fancy-chat-js TurnEntry.messageId).
                $origin = is_string($data['messageId'] ?? null) ? self::indexOf($state['messages'], 'id', $data['messageId']) : null;
                $state['turns'][] = ['turnId' => $data['turnId'], 'state' => 'pending', 'outcome' => null, 'reason' => null, 'messageId' => $origin === null ? null : $state['messages'][$origin]['id']];
                if ($origin !== null && $state['messages'][$origin]['turnId'] === null) {
                    $state['messages'][$origin]['turnId'] = $data['turnId'];
                }
                // Quick replies belong to the turn they follow; a new turn retires them.
                $state['suggestions'] = null;
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

            case 'undo.opened':
                if (! is_string($data['actionId'] ?? null) || ! is_string($data['label'] ?? null) || ! Envelope::isTimestamp($data['deadline'] ?? null) || self::indexOf($state['undos'], 'actionId', $data['actionId']) !== null) {
                    $illegal();
                    break;
                }
                $state['undos'][] = ['actionId' => $data['actionId'], 'label' => $data['label'], 'deadline' => $data['deadline'], 'state' => 'open'];
                $state['order'][] = ['kind' => 'undo', 'id' => $data['actionId']];
                break;

            case 'undo.committed':
            case 'undo.reverted':
                $i = self::indexOf($state['undos'], 'actionId', $data['actionId'] ?? null);
                // Only the human reverts; the host (or its timer) commits.
                $allowed = $type === 'undo.committed' || self::decidedByActor($event, $data['revertedBy'] ?? null);
                if ($i === null || $state['undos'][$i]['state'] !== 'open' || ! $allowed) {
                    $illegal();
                    break;
                }
                $state['undos'][$i]['state'] = $type === 'undo.committed' ? 'committed' : 'reverted';
                break;

            case 'suggestions.updated':
                // Only for the NEWEST turn, settled completed, and never from a human:
                // a suggestion is offered to the person, never set by one.
                $last = $state['turns'] === [] ? null : $state['turns'][count($state['turns']) - 1];
                $checked = Suggestions::checkItems($data['items'] ?? null);
                if (
                    ($event['actor']['kind'] ?? null) === 'human'
                    || ! is_string($data['turnId'] ?? null)
                    || $last === null
                    || $last['turnId'] !== $data['turnId']
                    || $last['outcome'] !== 'completed'
                    || ! $checked['ok']
                ) {
                    $illegal();
                    break;
                }
                $state['suggestions'] = $checked['items'] === [] ? null : ['turnId' => $data['turnId'], 'items' => $checked['items']];
                break;

            default:
                // Runs, usage, agent status, handoff and x-* events carry no row.
                break;
        }

        $state['lastSeq'] = $seq;

        return true;
    }

    /**
     * Under the truncate policy, the turns a removed branch must cancel: every
     * UNSETTLED turn the branch references, through a message in it (the
     * superseded message included) or an approval placed in it, in `turns`
     * order. Settled turns and turns outside the branch are never touched.
     * fancy-chat-js `turnsToCancelForBranch`.
     *
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    public static function turnsToCancelForBranch(array $state, string $supersededId): array
    {
        $branch = null;
        foreach ($state['branches'] ?? [] as $b) {
            if ($b['supersededId'] === $supersededId) {
                $branch = $b;
                break;
            }
        }
        if ($branch === null) {
            return [];
        }
        $ids = array_flip(array_merge([$supersededId], $branch['rowIds']));
        $referenced = [];
        foreach ($state['messages'] as $m) {
            if (isset($ids[$m['id']]) && is_string($m['turnId'] ?? null)) {
                $referenced[$m['turnId']] = true;
            }
        }
        foreach ($state['approvals'] as $a) {
            if (isset($ids[$a['approvalId']]) && is_string($a['turnId'] ?? null)) {
                $referenced[$a['turnId']] = true;
            }
        }
        $out = [];
        foreach ($state['turns'] as $t) {
            if ($t['state'] !== 'settled' && isset($referenced[$t['turnId']])) {
                $out[] = $t['turnId'];
            }
        }

        return $out;
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
                    $i = self::indexOf($state['thinking'], 'thinkingId', $id);
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
                case 'undo':
                    $i = self::indexOf($state['undos'], 'actionId', $id);
                    if ($i !== null) {
                        $rows[] = ['kind' => 'undo', 'id' => $id] + $state['undos'][$i];
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
     * @return array{lastSeq: int, illegal: int, rows: list<array<string, mixed>>, turns: list<array<string, mixed>>, approvals: list<array<string, mixed>>, questions: list<array<string, mixed>>, suggestions: array{turnId: string, items: list<array{id: string, text: string}>}|null, state: array<string, mixed>}
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
            'suggestions' => $state['suggestions'] ?? null,
            'state' => $state,
        ];
    }
}
