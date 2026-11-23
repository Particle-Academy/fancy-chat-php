<?php

declare(strict_types=1);

namespace FancyChat\Tests\Harness;

use FancyChat\Prism\KnowledgeBridge;
use FancyChat\Tests\Harness\Support\TestApp;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use LogicException;
use Prism\Graph\Graph;
use Prism\Graph\Scope;
use Prism\Graph\ScopedGraph;

/*
 * `php artisan route:cache`: every knowledge route must serialise, and the cached
 * routes must answer exactly what the uncached ones do. A closure route that
 * captures the bridge (and through it the host's scope closure) cannot be cached.
 */

/** The scope a host would build from ITS authentication (X-Who here). */
function cacheScope(Graph $graph): \Closure
{
    return function (Request $r) use ($graph): ?ScopedGraph {
        $who = $r->header('X-Who');

        return is_string($who) && str_contains($who, ':') ? $graph->scope(new Scope('acme', explode(':', $who, 2)[1])) : null;
    };
}

function humanDecider(): \Closure
{
    return function (Request $r): ?array {
        $who = $r->header('X-Who');
        if (! is_string($who) || ! str_contains($who, ':')) {
            return null;
        }
        [$kind, $id] = explode(':', $who, 2);

        return ['kind' => $kind, 'id' => $id];
    };
}

/** @return list<RoutingRoute> */
function knowledgeRoutes(): array
{
    return array_values(array_filter(
        app('router')->getRoutes()->getRoutes(),
        fn (RoutingRoute $r): bool => str_contains($r->uri(), 'knowledge/'),
    ));
}

/** What `route:cache` writes: every route prepared, the collection compiled, var_export'ed. */
function cacheRoutes(): array
{
    $routes = app('router')->getRoutes();
    foreach ($routes as $route) {
        $route->prepareForSerialization();
    }

    return eval('return '.var_export($routes->compile(), true).';');
}

/** A fresh app that loads the routes from the cache, as a cached host does (the routes file is not run). */
function bootFromCache(array $compiled, ?KnowledgeBridge $bind): void
{
    TestApp::boot();
    if ($bind !== null) {
        app()->instance(KnowledgeBridge::class, $bind); // what the host's service provider does
    }
    app('router')->setCompiledRoutes($compiled);
}

const KNOWLEDGE_ROUTES = [
    'GET app/fancy-chat/knowledge/search', 'GET app/fancy-chat/knowledge/sources',
    'GET app/fancy-chat/knowledge/sources/{source}/chunks', 'GET app/fancy-chat/knowledge/chunks',
    'GET app/fancy-chat/knowledge/facts', 'GET app/fancy-chat/knowledge/facts/timeline',
    'GET app/fancy-chat/knowledge/facts/all', 'GET app/fancy-chat/knowledge/ingest/jobs',
    'GET app/fancy-chat/knowledge/ingest/worker',
    'POST app/fancy-chat/knowledge/facts/{fact}/accept', 'POST app/fancy-chat/knowledge/facts/{fact}/reject',
];

it('serialises every knowledge route for route:cache, mounted inside a host route group', function (): void {
    $bridge = new KnowledgeBridge(scope: cacheScope(graphWithData()), decider: humanDecider());
    Route::prefix('app')->middleware('web')->group(function () use ($bridge): void {
        KnowledgeBridge::routes($bridge, middleware: ['throttle:60,1']);
    });

    $routes = knowledgeRoutes();
    expect(array_map(fn (RoutingRoute $r): string => $r->methods()[0].' '.$r->uri(), $routes))->toEqualCanonicalizing(KNOWLEDGE_ROUTES);
    foreach ($routes as $route) {
        $route->prepareForSerialization(); // what route:cache does to each route
        expect(serialize($route))->toBeString()
            ->and($route->getAction('uses'))->toStartWith('FancyChat\Prism\KnowledgeController@')
            ->and($route->gatherMiddleware())->toBe(['web', 'throttle:60,1']);
    }
});

it('answers the same through cached routes as uncached ones, with the bridge bound in the container', function (): void {
    $graph = graphWithData();
    $bridge = new KnowledgeBridge(scope: cacheScope($graph), decider: humanDecider());
    Route::prefix('app')->group(fn () => KnowledgeBridge::routes($bridge));
    $handbook = collect($graph->scope(new Scope('acme', 'u_1'))->listSources()['items'])->firstWhere('title', 'Handbook')['sourceId'];
    $ref = $graph->scope(new Scope('acme', 'u_1'))->facts('acme')[0]->evidence[0];
    $reads = [
        ['/search?q=refunds&limit=5', 'human:u_1'], ['/sources', 'human:u_1'], ['/sources', null],
        ['/sources/'.rawurlencode($handbook).'/chunks', 'human:u_1'], ['/chunks?ids[]='.urlencode($ref), 'human:u_1'],
        ['/facts?subject=acme&unaccepted=1', 'human:u_1'], ['/facts', 'human:u_1'], ['/facts/timeline?subject=acme', 'human:u_1'],
        ['/facts/all', 'human:u_1'], ['/ingest/jobs', 'human:u_1'], ['/ingest/worker', 'human:u_1'],
        ['/sources?cursor=garbage', 'human:u_1'],
    ];
    $uncached = array_map(fn (array $r): array => http('GET', '/app/fancy-chat/knowledge'.$r[0], null, $r[1]), $reads);

    bootFromCache(cacheRoutes(), $bridge);
    $cached = array_map(fn (array $r): array => http('GET', '/app/fancy-chat/knowledge'.$r[0], null, $r[1]), $reads);

    expect($cached)->toBe($uncached)
        ->and(array_column($cached, 'status'))->toBe([200, 200, 403, 200, 200, 200, 422, 200, 501, 501, 501, 422]);
    expect(http('POST', '/app/fancy-chat/knowledge/facts/f_mood/accept', [], 'agent:u_1')['status'])->toBe(403)
        ->and(http('POST', '/app/fancy-chat/knowledge/facts/f_mood/reject', [], 'human:u_1'))
        ->toBe(['status' => 200, 'body' => ['ok' => true, 'factId' => 'f_mood', 'decision' => 'reject']]);
});

it('mounts from the container binding when no bridge is passed, and the bound bridge serves', function (): void {
    app()->instance(KnowledgeBridge::class, new KnowledgeBridge(scope: cacheScope(graphWithData())));
    KnowledgeBridge::routes();
    app('router')->getRoutes()->refreshNameLookups();

    expect(kget('/sources')['status'])->toBe(200)
        ->and(kget('/sources', null)['status'])->toBe(403)
        ->and(collect(knowledgeRoutes())->map(fn (RoutingRoute $r): string => $r->uri())->filter(fn (string $u): bool => str_ends_with($u, 'accept'))->all())->toBe([]);
});

it('binds the passed bridge when nothing is bound, and refuses a second, different bridge', function (): void {
    $bridge = knowledgeHost(graphWithData());
    expect(app(KnowledgeBridge::class))->toBe($bridge);
    KnowledgeBridge::routes($bridge, 'again/knowledge'); // the same instance again is fine
    expect(fn () => KnowledgeBridge::routes(new KnowledgeBridge(scope: fn (): null => null), 'other/knowledge'))
        ->toThrow(LogicException::class, 'a different KnowledgeBridge is already bound');
});

it('refuses to mount with no bridge passed and none bound, saying what to bind', function (): void {
    expect(fn () => KnowledgeBridge::routes())
        ->toThrow(LogicException::class, 'bind FancyChat\Prism\KnowledgeBridge in a service provider');
});

it('keeps accept and reject off when the bound bridge has no decider, even if cached routes include them', function (): void {
    $graph = graphWithData();
    KnowledgeBridge::routes(new KnowledgeBridge(scope: cacheScope($graph), decider: humanDecider()));
    bootFromCache(cacheRoutes(), new KnowledgeBridge(scope: cacheScope($graph)));

    expect(fn () => http('POST', '/fancy-chat/knowledge/facts/f_mood/accept', [], 'human:u_1'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
    expect(collect($graph->scope(new Scope('acme', 'u_1'))->facts('acme', unaccepted: true))->firstWhere('id', 'f_mood'))->not->toBeNull();
});

it('fails clearly when routes are cached and nothing binds the bridge', function (): void {
    KnowledgeBridge::routes(new KnowledgeBridge(scope: cacheScope(graphWithData())));
    bootFromCache(cacheRoutes(), null);

    expect(fn () => http('GET', '/fancy-chat/knowledge/sources'))
        ->toThrow(LogicException::class, 'bind FancyChat\Prism\KnowledgeBridge in a service provider');
});
