<?php

declare(strict_types=1);

namespace FancyChat\Tests\Harness;

use FancyChat\FancyChat;
use FancyChat\Prism\HarnessBackend;
use FancyChat\Tests\Harness\Support\FakeSession;
use FancyChat\Tests\Harness\Support\TestApp;
use Illuminate\Http\Request;
use Prism\Harness\Steps\RunStep;
use FancyChat\Prism\KnowledgeBridge;
use PDO;
use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Fact;
use Prism\Graph\Graph;
use Prism\Graph\Origin;
use Prism\Graph\Scope;
use Prism\Graph\ScopedGraph;
use Prism\Graph\Storage\BaseSchema;
use Prism\Graph\Storage\PdoGraphStore;
use Prism\Graph\Trust;
use Prism\Harness\Subagents\RunContext;

function chat(): FancyChat
{
    return app(FancyChat::class);
}

/** A host whose `X-Who: kind:id` header names the actor; access allows all. */
function host(HarnessBackend $backend): FancyChat
{
    return chat()
        ->actor(function (Request $request): ?array {
            $who = $request->header('X-Who');
            if (! is_string($who) || ! str_contains($who, ':')) {
                return null;
            }
            [$kind, $id] = explode(':', $who, 2);

            return ['kind' => $kind, 'id' => $id];
        })
        ->access(fn (): bool => true)
        ->backend($backend);
}

/**
 * @param  array<string, mixed>|null  $body
 * @return array{status: int, body: mixed}
 */
function http(string $method, string $uri, ?array $body = null, ?string $who = 'human:u_1'): array
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($who !== null) {
        $server['HTTP_X_WHO'] = $who;
    }
    $request = Request::create($uri, $method, [], [], [], $server, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    app()->instance('request', $request);
    $response = app('router')->dispatch($request);

    return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
}

/** A human sends one text message on thread t_1; returns the turn id. */
function say(string $text, string $thread = 't_1'): string
{
    $r = http('POST', "/fancy-chat/threads/{$thread}/messages", ['parts' => [['id' => 'p1', 'kind' => 'text', 'format' => 'plain', 'text' => $text]]]);
    expect($r['status'])->toBeLessThan(300);

    return lastTurn($thread)['turnId'];
}

/** @return array<string, mixed> */
function lastTurn(string $thread = 't_1'): array
{
    $turns = chat()->snapshot($thread)['turns'];

    return $turns[count($turns) - 1];
}

/**
 * Every logged event of a thread, raw.
 *
 * @return list<array<string, mixed>>
 */
function events(string $thread = 't_1'): array
{
    return chat()->store()->after($thread, 0, 10000);
}

/**
 * The logged events of the given types, in order.
 *
 * @param  list<string>  $types
 * @return list<array<string, mixed>>
 */
function only(array $types, string $thread = 't_1'): array
{
    return array_values(array_filter(events($thread), fn (array $e): bool => in_array($e['type'], $types, true)));
}

/** @return list<string> the logged event types, in order, minus the server's own bookkeeping */
function backendTypes(string $thread = 't_1'): array
{
    $own = ['message.created', 'turn.started', 'turn.transition', 'delivery.changed'];
    $types = [];
    foreach (events($thread) as $e) {
        if (($e['actor']['kind'] ?? null) === 'agent' || ! in_array($e['type'], $own, true)) {
            $types[] = $e['type'];
        }
    }

    return $types;
}

/**
 * Real RunSteps, numbered from `$from` with no gaps, on one run.
 *
 * @param  list<array{0: string, 1?: array<string, mixed>}>  $script  [type, data]
 * @return list<RunStep>
 */
function runSteps(?RunContext $context, array $script, string $runId = 'run_1', ?string $parentRunId = null, ?string $rootRunId = null, int $from = 1): array
{
    $out = [];
    foreach ($script as $i => $entry) {
        $out[] = new RunStep($runId, $parentRunId, $rootRunId ?? $runId, $context?->turnId, $from + $i, '2026-11-15T10:00:00.'.str_pad((string) $i, 6, '0', STR_PAD_LEFT).'Z', $entry[0], $entry[1] ?? []);
    }

    return $out;
}

function backendFor(FakeSession $session, array $options = []): HarnessBackend
{
    return new HarnessBackend(fn (string $threadId): FakeSession => $session, ...$options);
}

/*
 * The knowledge bridge's fixtures: a prism-graph store with two owners, and the
 * host that binds the scope from ITS authentication.
 */

function graphWithData(): Graph
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (BaseSchema::statements('sqlite') as $sql) {
        $pdo->exec($sql);
    }
    $graph = new Graph(new PdoGraphStore($pdo));
    $mine = $graph->scope(new Scope('acme', 'u_1'));
    $mine->ingest('handbook', 'file:///handbook.md', "# Refunds\n\nRefunds take 30 days.\n\n# Shipping\n\nShipping is free over 50.\n", new MarkdownChunker, new Origin(Trust::Document, 'ingest'), ['title' => 'Handbook', 'kind' => 'md']);
    $mine->ingest('notes', 'file:///notes.md', "# Notes\n\nThe agent thinks Acme is optimistic.\n", new MarkdownChunker, new Origin(Trust::Agent, 'agent'));
    $sourceOf = fn (string $title): string => collect($mine->listSources()['items'])->firstWhere('title', $title)['sourceId'];
    $chunks = $mine->listChunks($sourceOf('Handbook'))['items'];
    $refund = collect($chunks)->first(fn (array $c): bool => str_contains($c['text'], 'Refunds take'))['chunkId'];
    $mine->assert(new Fact('f_refund', 'acme', 'refund_window', '30 days', [$refund], '2026-01-01T00:00:00Z'));
    $notes = $mine->listChunks($sourceOf('file:///notes.md'))['items'][0]['chunkId'];
    $mine->assert(new Fact('f_mood', 'acme', 'mood', 'optimistic', [$notes], '2026-01-01T00:00:00Z'));
    // Another owner's data, which u_1 must never see.
    $graph->scope(new Scope('acme', 'u_2'))->ingest('secret', 'file:///secret.md', "# Secret\n\nRefunds take 99 days for u_2.\n", new MarkdownChunker, new Origin(Trust::Document, 'ingest'));

    return $graph;
}

/** The host binds the scope from ITS authentication (here X-Who), never from the request's data. */
function knowledgeHost(Graph $graph, array $options = []): KnowledgeBridge
{
    $bridge = new KnowledgeBridge(...['scope' => function (Request $r) use ($graph): ?ScopedGraph {
        $who = $r->header('X-Who');

        return is_string($who) && str_contains($who, ':') ? $graph->scope(new Scope('acme', explode(':', $who, 2)[1])) : null;
    }] + $options);
    KnowledgeBridge::routes($bridge);
    app('router')->getRoutes()->refreshNameLookups();

    return $bridge;
}

function kget(string $uri, ?string $who = 'human:u_1'): array
{
    return http('GET', '/fancy-chat/knowledge'.$uri, null, $who);
}
