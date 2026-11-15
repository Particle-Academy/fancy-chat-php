<?php

declare(strict_types=1);

use FancyChat\Contract\Envelope;
use FancyChat\Contract\Hitl;
use FancyChat\Contract\Machines;
use FancyChat\Contract\Parts;
use FancyChat\Contract\Projection;
use FancyChat\Contract\Status;
use FancyChat\Tests\Support\Fixtures;

/*
 * The PHP leg of conformance/chat-v1.json. Every section that names "php" in
 * its legs is driven here, to the same output fancy-chat-js produces.
 */

it('pins the fixture and the machines file byte for byte', function (): void {
    if (getenv('FANCY_CHAT_SABOTAGE') === '1') {
        // The sabotage sweep mutates machines.json to prove BEHAVIOUR catches it.
        expect(true)->toBeTrue();

        return;
    }
    expect(Fixtures::sha256(Fixtures::path()))->toBe(Fixtures::SHA256)
        ->and(Fixtures::sha256(Fixtures::machinesPath()))->toBe(Fixtures::MACHINES_SHA256);
});

it('runs every section that names the php leg', function (): void {
    $php = [];
    foreach (Fixtures::all() as $name => $section) {
        if (is_array($section) && isset($section['legs']) && in_array('php', $section['legs'], true)) {
            $php[] = $name;
        }
    }
    sort($php);
    expect($php)->toBe(['actionable', 'approval', 'decide', 'delivery', 'envelope', 'optionTone', 'parts', 'projection', 'question', 'reservedTools', 'status', 'turn']);
});

it('validates the envelope', function (array $c): void {
    $r = Envelope::validate($c['event']);
    expect(['ok' => $r['ok'], 'error' => $r['ok'] ? null : $r['error']])->toBe(['ok' => $c['ok'], 'error' => $c['error']]);
})->with(Fixtures::cases('envelope', fn (array $c): string => $c['name']));

it('runs the turn machine', function (array $c): void {
    $r = Machines::turn($c['from'], $c['action']);
    expect($r['ok'])->toBe($c['ok']);
    $got = $r['ok'] ? ['to' => $r['to'], 'outcome' => $r['outcome'], 'reason' => $r['reason']] : ['to' => null, 'outcome' => null, 'reason' => null];
    expect($got)->toBe(['to' => $c['to'], 'outcome' => $c['outcome'], 'reason' => $c['reason']]);
})->with(Fixtures::cases('turn', fn (array $c): string => $c['from'].' --'.$c['action'].'-->'));

it('runs the delivery machine', function (array $c): void {
    $r = Machines::delivery($c['from'], $c['action']);
    expect(['ok' => $r['ok'], 'to' => $r['ok'] ? $r['to'] : null])->toBe(['ok' => $c['ok'], 'to' => $c['to']]);
})->with(Fixtures::cases('delivery', fn (array $c): string => $c['from'].' --'.$c['action'].'-->'));

it('runs the approval machine', function (array $c): void {
    $r = Machines::approval($c['from'], $c['action']);
    expect(['ok' => $r['ok'], 'to' => $r['ok'] ? $r['to'] : null])->toBe(['ok' => $c['ok'], 'to' => $c['to']]);
})->with(Fixtures::cases('approval', fn (array $c): string => $c['from'].' --'.$c['action'].'-->'));

it('takes the tone from the option kind, never the label', function (array $c): void {
    expect(Machines::optionTone($c['kind']))->toBe(['tone' => $c['tone'], 'decision' => $c['decision']]);
})->with(Fixtures::cases('optionTone', fn (array $c): string => $c['kind']));

it('lets only a human decide', function (array $c): void {
    expect(Hitl::canDecide($c['actor']))->toBe($c['allowed']);
})->with(Fixtures::cases('decide', fn (array $c): string => json_encode($c['actor'])));

it('reserves the confirmation tools', function (array $c): void {
    expect(Hitl::isReservedToolName($c['name']))->toBe($c['reserved']);
})->with(Fixtures::cases('reservedTools', fn (array $c): string => $c['name']));

it('checks question answers', function (array $c): void {
    $question = Fixtures::section('question')['question'];
    if (array_key_exists('allowNote', $c)) {
        $question['allowNote'] = $c['allowNote'];
    }
    $r = Hitl::canSubmitAnswer($question, $c['draft']);
    expect(['ok' => $r['ok'], 'error' => $r['ok'] ? null : $r['error']])->toBe(['ok' => $c['canSubmit'], 'error' => $c['error']]);
})->with(Fixtures::cases('question', fn (array $c): string => $c['name']));

it('picks the single actionable item', function (array $c): void {
    expect(Hitl::pickActionable($c['items'], $c['kinds'] ?? null))->toBe($c['actionable']);
})->with(Fixtures::cases('actionable', fn (array $c): string => $c['name']));

it('validates message parts and their plain text', function (array $c): void {
    $r = Parts::validate($c['part'], $c['context']['origin'], $c['context']['limits'] ?? []);
    expect(['ok' => $r['ok'], 'error' => $r['ok'] ? null : $r['error']])->toBe(['ok' => $c['ok'], 'error' => $c['error']]);
    if ($c['ok']) {
        expect(Parts::toText($c['part']))->toBe($c['text']);
    }
})->with(Fixtures::cases('parts', fn (array $c): string => $c['name']));

it('derives the agent status', function (array $c): void {
    $config = array_merge(Fixtures::section('status')['config'], $c['config'] ?? []);
    expect(canon(Status::derive($c['snapshot'], $config)))->toBe(canon($c['expect']));
})->with(Fixtures::cases('status', fn (array $c): string => $c['name']));

it('projects the thread', function (array $c): void {
    $defaults = Fixtures::section('projection')['defaults'];
    $events = array_map(fn (array $e): array => array_merge($defaults, $e), $c['events']);
    $p = Projection::project($events);
    expect(canon([
        'lastSeq' => $p['lastSeq'],
        'illegal' => $p['illegal'],
        'turns' => $p['turns'],
        'approvals' => array_map(fn (array $a): array => ['approvalId' => $a['approvalId'], 'state' => $a['state']], $p['approvals']),
        'questions' => array_map(fn (array $q): array => ['questionId' => $q['questionId'], 'state' => $q['state']], $p['questions']),
        'rows' => $p['rows'],
    ]))->toBe(canon($c['expect']));
})->with(Fixtures::cases('projection', fn (array $c): string => $c['name']));
