<?php

declare(strict_types=1);

use FancyChat\Contract\Projection;

/*
 * Projection::apply is total: a malformed event is counted illegal and the
 * cursor moves past it. It never throws. The same shapes as fancy-chat-js's
 * tests/totality.test.ts, where a null plan step used to throw. PHP already
 * refused every one of them; this keeps it that way.
 */

// fancy-chat-js CORE_EVENT_TYPES, plus an app extension type.
const TOTALITY_TYPES = [
    'thread.snapshot', 'thread.reset', 'run.started', 'run.finished', 'run.failed', 'run.cancelled', 'turn.started',
    'turn.transition', 'turn.settled', 'message.created', 'message.updated', 'message.retracted', 'delivery.changed',
    'part.started', 'part.delta', 'part.completed', 'thinking.started', 'thinking.delta', 'thinking.completed',
    'tool.called', 'tool.progress', 'tool.result', 'plan.updated', 'approval.requested', 'approval.decided',
    'approval.applied', 'approval.withdrawn', 'question.asked', 'question.answered', 'undo.opened', 'undo.committed',
    'undo.reverted', 'agent.status', 'usage', 'provider.fallback', 'control.handoff', 'x-app.thing',
];

function totalityShapes(): array
{
    return [
        null, 'x', [], ['message' => null], ['message' => ['id' => 'm_1']], ['message' => ['id' => 'm_1', 'parts' => null]],
        ['message' => ['id' => 'm_1', 'parts' => 'x']], ['message' => ['id' => 'm_1', 'parts' => [null]]], ['message' => ['id' => 'm_1', 'parts' => [[]]]],
        ['messageId' => 'm_1', 'partId' => 'p', 'text' => 5], ['messageId' => 'm_1', 'part' => null], ['messageId' => 'm_1', 'part' => ['id' => 'p']],
        ['messageId' => 'm_1', 'part' => ['id' => 'p', 'kind' => 'text', 'text' => null]], ['messageId' => 'm_1', 'partId' => ['x'], 'text' => 'a'],
        ['approval' => ['approvalId' => 'a', 'options' => null]], ['approval' => ['approvalId' => 'a', 'options' => 'x']], ['approval' => ['approvalId' => 'a', 'preview' => 'x']],
        ['question' => ['questionId' => 'q', 'fields' => null]], ['question' => ['questionId' => 'q', 'fields' => [null]]],
        ['toolCallId' => 'tc', 'args' => null], ['toolCallId' => ['x']], ['steps' => null], ['steps' => [null]], ['steps' => ['x', 5]],
        ['turnId' => 'u', 'action' => null], ['turnId' => ['x'], 'action' => 'start'], ['snapshot' => ['messages' => null]], ['snapshot' => ['order' => 'x']],
        ['actionId' => 'x', 'window' => null], ['approvalId' => 'a', 'decidedBy' => null, 'decision' => 'approve'], ['approvalId' => ['x']],
        ['questionId' => ['x']], ['text' => ['x']],
    ];
}

function totalitySeeded(): array
{
    $s = Projection::emptyState();
    $pre = [
        ['type' => 'message.created', 'data' => ['message' => ['id' => 'm_1', 'role' => 'assistant', 'parts' => []]]],
        ['type' => 'part.started', 'data' => ['messageId' => 'm_1', 'part' => ['id' => 'p', 'kind' => 'text', 'text' => '']]],
        ['type' => 'turn.started', 'data' => ['turnId' => 'u']],
        ['type' => 'plan.updated', 'runId' => 'r', 'data' => ['steps' => [['id' => 's', 'title' => 'Pay', 'status' => 'held', 'owner' => 'human', 'approvalId' => 'a']]]],
        ['type' => 'approval.requested', 'data' => ['approval' => ['approvalId' => 'a', 'title' => '', 'options' => [['id' => 'o', 'kind' => 'allow-once', 'label' => 'x']], 'state' => 'pending']]],
    ];
    foreach ($pre as $i => $p) {
        Projection::apply($s, ['v' => 1, 'seq' => $i + 1, 'threadId' => 't', 'at' => '2026-11-15T10:00:00.000Z', 'actor' => ['kind' => 'system', 'id' => 's'], 'data' => []] + $p);
    }

    return $s;
}

it('never throws on a malformed event, and advances the cursor', function (string $type): void {
    foreach (totalityShapes() as $data) {
        foreach ([['kind' => 'agent', 'id' => 'a'], null, 'x'] as $actor) {
            $s = totalitySeeded();
            $seq = $s['lastSeq'] + 1;
            Projection::apply($s, ['v' => 1, 'seq' => $seq, 'threadId' => 't', 'at' => '2026-11-15T10:00:00.000Z', 'type' => $type, 'actor' => $actor, 'data' => $data, 'runId' => 'r', 'turnId' => 'u']);
            expect($s['lastSeq'])->toBe($seq, $type.' '.json_encode($data));
        }
    }
})->with(TOTALITY_TYPES);
