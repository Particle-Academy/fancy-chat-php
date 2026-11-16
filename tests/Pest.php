<?php

declare(strict_types=1);

use FancyChat\Tests\Support\ScriptedBackend;
use FancyChat\Backend\TurnInput;
use FancyChat\FancyChat;
use FancyChat\StagedWrite;
use FancyChat\Tests\Harness\Support\TestApp as HarnessTestApp;
use FancyChat\Tests\Support\TestApp;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

uses()->afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
})->in(__DIR__.'/Feature');

/*
 * The Prism Harness suite (src/Prism/, folded in from fancy-chat-prism-php in
 * 0.2.0). Its files and helpers live in the FancyChat\Tests\Harness namespace,
 * so its helper names never collide with the ones below.
 */
uses()->beforeEach(function (): void {
    HarnessTestApp::boot();
})->afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
})->in(__DIR__.'/Harness/Feature');

require_once __DIR__.'/Harness/helpers.php';

/**
 * Recursively sorts associative keys so `toBe` compares structure strictly
 * (no loose `null == false`) without caring about key order. Lists keep order.
 */
function canon(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    $out = array_map('canon', $value);
    if (! array_is_list($out)) {
        ksort($out);
    }

    return $out;
}

dataset('stores', ['memory', 'database']);

/** @param  array<string, mixed>  $config */
function chatApp(array $config = [], ?string $sqlitePath = null): Application
{
    return TestApp::boot($config, $sqlitePath);
}

function chat(): FancyChat
{
    return app(FancyChat::class);
}

/** The clock every manager in a test reads, in ms; tests move it with tick(). */
final class TestClock
{
    public static int $now = 1_763_200_000_000;
}

function tick(int $ms): void
{
    TestClock::$now += $ms;
}

/**
 * A host wired the way most tests want: `X-Who: kind:id` names the actor
 * (`human:u_1`, `agent:compass`), access allows everything unless `X-Deny`
 * names the ability.
 */
function bindHost(?FancyChat $manager = null): FancyChat
{
    TestClock::$now = 1_763_200_000_000;
    $manager ??= chat();

    return $manager
        ->clock(fn (): int => TestClock::$now)
        ->actor(function (Request $request): ?array {
            $who = $request->header('X-Who');
            if (! is_string($who) || ! str_contains($who, ':')) {
                return null;
            }
            [$kind, $id] = explode(':', $who, 2);

            return ['kind' => $kind, 'id' => $id];
        })
        ->access(fn (Request $request, string $threadId, string $ability): bool => $request->header('X-Deny') !== $ability);
}

/**
 * One request through the real router.
 *
 * @param  array<string, mixed>|null  $body
 * @param  array<string, string>  $headers
 * @return array{status: int, body: mixed, raw: string, headers: \Symfony\Component\HttpFoundation\ResponseHeaderBag, response: \Symfony\Component\HttpFoundation\Response}
 */
function http(string $method, string $uri, ?array $body = null, ?string $who = null, array $headers = []): array
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($who !== null) {
        $server['HTTP_X_WHO'] = $who;
    }
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $request = Request::create($uri, $method, [], [], [], $server, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    app()->instance('request', $request);
    $response = app('router')->dispatch($request);
    if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
        ob_start();
        $response->sendContent();
        $raw = (string) ob_get_clean();
    } else {
        $raw = (string) $response->getContent();
    }

    return [
        'status' => $response->getStatusCode(),
        'body' => json_decode($raw, true),
        'raw' => $raw,
        'headers' => $response->headers,
        'response' => $response,
    ];
}

/** A backend that answers every message with one streamed text part. */
function replyBackend(string $text = 'Acme is won.'): ScriptedBackend
{
    return new ScriptedBackend(function (TurnInput $input) use ($text): iterable {
        yield ['type' => 'thinking.started', 'runId' => 'r_'.$input->turnId];
        yield ['type' => 'thinking.delta', 'runId' => 'r_'.$input->turnId, 'data' => ['text' => 'Look it up.']];
        yield ['type' => 'tool.called', 'runId' => 'r_'.$input->turnId, 'data' => ['toolCallId' => 'tc_'.$input->turnId, 'name' => 'search', 'args' => []]];
        yield ['type' => 'tool.result', 'runId' => 'r_'.$input->turnId, 'data' => ['toolCallId' => 'tc_'.$input->turnId, 'outcome' => 'ok', 'durationMs' => 12]];
        yield ['type' => 'message.created', 'runId' => 'r_'.$input->turnId, 'data' => ['message' => ['id' => 'a_'.$input->turnId, 'role' => 'assistant', 'parts' => []]]];
        yield ['type' => 'part.started', 'data' => ['messageId' => 'a_'.$input->turnId, 'part' => ['id' => 'p', 'kind' => 'text', 'format' => 'markdown', 'text' => '']]];
        yield ['type' => 'part.delta', 'data' => ['messageId' => 'a_'.$input->turnId, 'partId' => 'p', 'text' => $text]];
        yield ['type' => 'part.completed', 'data' => ['messageId' => 'a_'.$input->turnId, 'partId' => 'p']];
    });
}

function textMessage(string $text): array
{
    return ['parts' => [['id' => 'p1', 'kind' => 'text', 'format' => 'plain', 'text' => $text]]];
}

/** Every event of a thread, by long-poll, in pages. */
function pollAll(string $thread, int $after = 0): array
{
    $events = [];
    for ($guard = 0; $guard < 100; $guard++) {
        $page = http('GET', "/fancy-chat/threads/{$thread}/events/poll?after={$after}&wait=0", null, 'human:u_1');
        expect($page['status'])->toBe(200);
        if ($page['body']['events'] === []) {
            break;
        }
        array_push($events, ...$page['body']['events']);
        $after = $page['body']['next'];
    }

    return $events;
}

/** A staged write that counts its applies. */
final class CountingWrite implements StagedWrite
{
    /** @var list<array{approval: array<string, mixed>, decision: string, edited: mixed}> */
    public array $applied = [];

    public function __construct(private bool $succeed = true) {}

    public function preview(array $proposal): array
    {
        return [['id' => 'pv', 'kind' => 'text', 'format' => 'plain', 'text' => 'Send to '.($proposal['to'] ?? '?')]];
    }

    public function apply(array $approval, string $decision, mixed $edited = null): array
    {
        $this->applied[] = ['approval' => $approval, 'decision' => $decision, 'edited' => $edited];

        return $this->succeed ? ['ok' => true] : ['ok' => false, 'error' => 'smtp.down'];
    }
}

/**
 * A backend that asks for approval and parks, then finishes on resume. It
 * records every input it was started with.
 */
/** @param  array{steering: bool, cancel: bool, approvals: bool}  $capabilities */
function approvalBackend(array &$inputs, string $stagedWrite = 'send-email', array $capabilities = ['steering' => false, 'cancel' => true, 'approvals' => true]): ScriptedBackend
{
    return new ScriptedBackend(function (TurnInput $input) use (&$inputs, $stagedWrite): iterable {
        $inputs[] = $input;
        if ($input->kind === 'resume') {
            yield ['type' => 'message.created', 'data' => ['message' => ['id' => 'done_'.$input->turnId, 'role' => 'assistant', 'parts' => [['id' => 'p', 'kind' => 'text', 'format' => 'plain', 'text' => 'Done.']]]]];

            return;
        }
        yield ['type' => 'approval.requested', 'data' => ['approval' => [
            'approvalId' => 'ap_'.$input->turnId,
            'title' => 'Send the email?',
            'summary' => 'To 3 recipients',
            'stagedWrite' => $stagedWrite,
            'proposal' => ['to' => 'team'],
            'options' => [['id' => 'yes', 'kind' => 'allow-once', 'label' => 'Send'], ['id' => 'no', 'kind' => 'reject-once', 'label' => 'Cancel'], ['id' => 'edit', 'kind' => 'edit', 'label' => 'Edit']],
        ]]];
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }, $capabilities);
}

/** Start a parked turn and return [approvalId, turnId]. */
function parkedApproval(array &$inputs, ?CountingWrite $write = null): array
{
    bindHost()->backend(approvalBackend($inputs))->stagedWrite('send-email', $write ?? new CountingWrite);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('email the team'), 'human:u_1');
    $state = chat()->snapshot('t_1');
    expect($state['turns'][0]['state'])->toBe('awaiting-human');

    return [$state['approvals'][0]['approvalId'], $state['turns'][0]['turnId']];
}
