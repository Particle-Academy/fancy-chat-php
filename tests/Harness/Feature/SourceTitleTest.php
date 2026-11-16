<?php

declare(strict_types=1);

namespace FancyChat\Tests\Harness;

use Prism\Graph\Chunking\MarkdownChunker;
use Prism\Graph\Origin;
use Prism\Graph\Scope;
use Prism\Graph\Trust;

/*
 * CC-fancy-0044: every chunk the bridge serves carries its source's title as
 * `sourceTitle`, looked up with prism-graph 0.4's scoped `getSource` (G-295),
 * never by paging `listSources` and never by reading the store.
 */

function sourceIdOf(string $title, string $owner = 'u_1'): string
{
    return collect(kget('/sources?limit=200', 'human:'.$owner)['body']['items'])->firstWhere('title', $title)['sourceId'];
}

it('labels a chunk by id with its source title', function (): void {
    knowledgeHost(graphWithData());
    $chunk = kget('/sources/'.rawurlencode(sourceIdOf('Handbook')).'/chunks')['body']['items'][0]['chunkId'];

    $r = kget('/chunks?ids[]='.rawurlencode($chunk));

    expect($r['status'])->toBe(200)
        ->and($r['body']['items'][0]['chunkId'])->toBe($chunk)
        ->and($r['body']['items'][0]['sourceTitle'])->toBe('Handbook');
});

it('labels search hits and a source listing, keeping a URI title as-is', function (): void {
    knowledgeHost(graphWithData());

    $hits = kget('/search?q='.rawurlencode('optimistic'))['body']['items'];
    expect($hits)->not->toBeEmpty()
        ->and($hits[0]['sourceTitle'])->toBe('file:///notes.md');

    $listed = kget('/sources/'.rawurlencode(sourceIdOf('Handbook')).'/chunks')['body']['items'];
    expect(array_unique(array_column($listed, 'sourceTitle')))->toBe(['Handbook']);
});

it('finds the title of a source far down the listing, so it is a lookup and not a page walk', function (): void {
    $graph = graphWithData();
    $mine = $graph->scope(new Scope('acme', 'u_1'));
    // Sixty newer sources push the handbook past the first listing page (50).
    for ($i = 0; $i < 60; $i++) {
        $mine->ingest('filler-'.$i, 'file:///filler-'.$i.'.md', "# Filler {$i}\n\nNothing about refunds here {$i}.\n", new MarkdownChunker, new Origin(Trust::Document, 'ingest'), ['title' => 'Filler '.$i]);
    }
    knowledgeHost($graph, ['hydratePages' => 1]);
    $handbook = collect($mine->listChunks(collect($mine->listSources(null, 200)['items'])->firstWhere('title', 'Handbook')['sourceId'])['items'])->first()['chunkId'];

    $r = kget('/chunks?ids[]='.rawurlencode($handbook));

    expect($r['body']['items'][0]['sourceTitle'])->toBe('Handbook');
});

it('never labels a chunk with a title from outside the scope', function (): void {
    knowledgeHost(graphWithData());
    $secret = sourceIdOf('file:///secret.md', 'u_2');

    // u_1 asks for u_2's source: an empty page, and nothing to label.
    $r = kget('/sources/'.rawurlencode($secret).'/chunks');

    expect($r['status'])->toBe(200)
        ->and($r['body']['items'])->toBe([]);
});
