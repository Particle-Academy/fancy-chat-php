<?php

declare(strict_types=1);

use FancyChat\Prism\KnowledgeBridge;

/*
 * Owner, 2026-11-16: "fancy chat is built entirely on the prism eco system, the
 * extra packages like memory and graph and browser and such were the optional
 * parts." Harness is a hard dependency; prism-graph is optional (suggested, and
 * checked at runtime); the old adapter package is absorbed.
 */

function manifest(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
}

it('requires Prism Harness and Prism directly, with sibling ranges', function (): void {
    $m = manifest();

    expect($m['require'])->toHaveKey('particle-academy/prism-harness')
        ->and($m['require']['particle-academy/prism-harness'])->toBe('>=0.26 <2.0')
        ->and($m['require']['particle-academy/prism'])->toBe('>=0.143 <2.0');
});

it('keeps prism-graph optional: suggested from 0.4 (getSource), installed only to test the bridge', function (): void {
    $m = manifest();

    expect($m['require'])->not->toHaveKey('particle-academy/prism-graph')
        ->and($m['suggest'])->toHaveKey('particle-academy/prism-graph')
        ->and($m['suggest']['particle-academy/prism-graph'])->toStartWith('>=0.4')
        ->and($m['require-dev']['particle-academy/prism-graph'])->toBe('^0.4')
        ->and($m['require'])->not->toHaveKey('particle-academy/prism-memory');
});

it('replaces fancy-chat-prism-php, which it absorbed', function (): void {
    expect(manifest()['replace'])->toBe(['particle-academy/fancy-chat-prism-php' => '*']);
});

it('ships no scripted backend: a host binds Prism Harness', function (): void {
    expect(is_file(dirname(__DIR__, 2).'/src/Backend/NullBackend.php'))->toBeFalse()
        ->and(class_exists('FancyChat\Backend\NullBackend'))->toBeFalse();
});

it('knows when prism-graph is installed', function (): void {
    expect(KnowledgeBridge::available())->toBeTrue();
});

it('refuses to mount the knowledge routes without prism-graph, naming the package to install', function (): void {
    // A child process whose autoloader cannot find Prism\Graph: the same as a host
    // that never installed it.
    $script = dirname(__DIR__).'/Support/without-graph.php';
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1', $out, $code);
    $result = json_decode(implode("\n", $out), true);

    expect($code)->toBe(0)
        ->and($result)->toBe([
            'available' => false,
            'routes' => 'LogicException: FancyChat\Prism\KnowledgeBridge needs particle-academy/prism-graph >=0.4: composer require particle-academy/prism-graph',
        ]);
});
