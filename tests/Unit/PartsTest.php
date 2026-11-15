<?php

declare(strict_types=1);

use FancyChat\Contract\Json;
use FancyChat\Contract\Parts;

afterEach(fn () => Parts::forgetRegistrations());

it('caps part size at exactly the configured byte count', function (): void {
    $part = ['id' => 'p', 'kind' => 'text', 'format' => 'plain', 'text' => 'abc'];
    $bytes = strlen((string) json_encode($part));
    expect(Json::bytes($part))->toBe($bytes)
        ->and(Parts::validate($part, 'agent', ['maxPartBytes' => $bytes]))->toBe(['ok' => true])
        ->and(Parts::validate($part, 'agent', ['maxPartBytes' => $bytes - 1]))->toBe(['ok' => false, 'error' => 'part.size']);
});

it('caps the part count per message and admits deeper cards when asked', function (): void {
    $parts = [['id' => 'a', 'kind' => 'text', 'format' => 'plain', 'text' => 'x'], ['id' => 'b', 'kind' => 'text', 'format' => 'plain', 'text' => 'y']];
    expect(Parts::validateList($parts, 'agent', ['maxParts' => 1]))->toBe(['ok' => false, 'error' => 'message.parts.count'])
        ->and(Parts::validateList($parts, 'agent', ['maxParts' => 2]))->toBe(['ok' => true]);
    $deep = ['id' => 'a', 'kind' => 'card', 'cardType' => 'x', 'body' => [['id' => 'b', 'kind' => 'card', 'cardType' => 'x', 'body' => [['id' => 'c', 'kind' => 'card', 'cardType' => 'x', 'body' => [['id' => 'd', 'kind' => 'card', 'cardType' => 'x', 'body' => []]]]]]]];
    expect(Parts::validate($deep, 'agent'))->toBe(['ok' => false, 'error' => 'part.depth'])
        ->and(Parts::validate($deep, 'agent', ['maxDepth' => 4]))->toBe(['ok' => true]);
});

it('uses an app part validator and plain-text form, and refuses bad registrations', function (): void {
    Parts::register('x-moic.deal', fn (array $p): bool|string => is_int($p['dealId'] ?? null) ? true : 'part.x-moic.deal.id', fn (array $p): string => 'Deal #'.$p['dealId']);
    expect(Parts::validate(['id' => 'p', 'kind' => 'x-moic.deal', 'dealId' => 7], 'agent'))->toBe(['ok' => true])
        ->and(Parts::validate(['id' => 'p', 'kind' => 'x-moic.deal', 'dealId' => '7'], 'agent'))->toBe(['ok' => false, 'error' => 'part.x-moic.deal.id'])
        ->and(Parts::toText(['id' => 'p', 'kind' => 'x-moic.deal', 'dealId' => 7]))->toBe('Deal #7')
        ->and(Parts::toText(['id' => 'p', 'kind' => 'x-other.thing']))->toBe('[x-other.thing]');
    expect(fn () => Parts::register('text'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Parts::register('deal'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Parts::register('x-moic.deal'))->toThrow(InvalidArgumentException::class);
});

it('writes table cells the way JavaScript String() does', function (): void {
    $table = ['id' => 't', 'kind' => 'table', 'columns' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']], 'rows' => [['a' => true, 'b' => 2.0], ['a' => null, 'b' => "x\ty"]]];
    expect(Parts::toText($table))->toBe("A\tB\ntrue\t2\n\tx y");
});

it('encodes empty maps as objects and empty lists as arrays on the wire', function (): void {
    $wire = Json::encode(['data' => [], 'rows' => [[]], 'parts' => [], 'args' => ['q' => []], 'order' => []]);
    expect($wire)->toBe('{"data":{},"rows":[{}],"parts":[],"args":{"q":[]},"order":[]}');
});
