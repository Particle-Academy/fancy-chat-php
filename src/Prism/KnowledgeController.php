<?php

declare(strict_types=1);

namespace FancyChat\Prism;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * The knowledge routes' actions. A controller, not closures, so `route:cache` can
 * serialise them: no route action holds the bridge or the host's scope closure.
 * Each request resolves `KnowledgeBridge` from the container, which the host binds
 * in a service provider (with cached routes the routes file never runs, so nothing
 * else binds it).
 */
final class KnowledgeController
{
    public const string UNBOUND = KnowledgeBridge::class.' is not bound in the container: bind FancyChat\Prism\KnowledgeBridge in a service provider (with route:cache the routes file is not run, so KnowledgeBridge::routes() cannot bind it).';

    public function __construct(private Container $container) {}

    public function search(Request $request): JsonResponse
    {
        return $this->bridge()->serve('search', $request);
    }

    public function sources(Request $request): JsonResponse
    {
        return $this->bridge()->serve('sources', $request);
    }

    public function sourceChunks(Request $request, string $source): JsonResponse
    {
        return $this->bridge()->serve('sourceChunks', $request, $source);
    }

    public function chunks(Request $request): JsonResponse
    {
        return $this->bridge()->serve('chunks', $request);
    }

    public function facts(Request $request): JsonResponse
    {
        return $this->bridge()->serve('facts', $request);
    }

    public function timeline(Request $request): JsonResponse
    {
        return $this->bridge()->serve('timeline', $request);
    }

    public function allFacts(Request $request): JsonResponse
    {
        return $this->bridge()->serve('allFacts', $request);
    }

    public function ingestJobs(Request $request): JsonResponse
    {
        return $this->bridge()->serve('ingestJobs', $request);
    }

    public function ingestWorker(Request $request): JsonResponse
    {
        return $this->bridge()->serve('ingestWorker', $request);
    }

    public function accept(Request $request, string $fact): JsonResponse
    {
        return $this->bridge()->serve('accept', $request, $fact);
    }

    public function reject(Request $request, string $fact): JsonResponse
    {
        return $this->bridge()->serve('reject', $request, $fact);
    }

    private function bridge(): KnowledgeBridge
    {
        if (! $this->container->bound(KnowledgeBridge::class)) {
            throw new LogicException(self::UNBOUND);
        }

        return $this->container->make(KnowledgeBridge::class);
    }
}
