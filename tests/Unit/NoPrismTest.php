<?php

declare(strict_types=1);

/*
 * Owner ruling, 2026-11-15: "the whole prism eco system is an optional addon".
 * The chat core may not depend on, import, or name anything from the Prism
 * ecosystem; Prism adapters live in particle-academy/fancy-chat-prism-php.
 *
 * The check reads composer.json (every require field) and every `use`
 * statement and string literal in src. A check never seen to fire cannot be
 * told from one that cannot, so it also runs against a fixture package that
 * DOES use Prism, and must find every reference there.
 */

/** @return array{findings: list<string>, sources: int} */
function prismFindings(string $root, string $extension = 'php'): array
{
    $findings = [];
    $manifest = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach (['require', 'require-dev', 'suggest', 'conflict', 'provide', 'replace'] as $field) {
        foreach (array_keys($manifest[$field] ?? []) as $name) {
            if (stripos($name, 'prism') !== false) {
                $findings[] = "composer.json {$field}: {$name}";
            }
        }
    }
    $sources = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! str_ends_with($file->getFilename(), '.'.$extension)) {
            continue;
        }
        $sources++;
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $i => $token) {
            if (! is_array($token)) {
                continue;
            }
            $isName = in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true);
            $isLiteral = $token[0] === T_CONSTANT_ENCAPSED_STRING;
            if (($isName || $isLiteral) && stripos($token[1], 'prism') !== false) {
                $findings[] = $file->getFilename().': '.$token[1];
            }
        }
    }

    return ['findings' => $findings, 'sources' => $sources];
}

it('names no Prism package and references no Prism code', function (): void {
    $root = dirname(__DIR__, 2);
    $r = prismFindings($root);
    expect($r['sources'])->toBeGreaterThan(10)->and($r['findings'])->toBe([]);
});

it('fires on a package that does use Prism (negative control)', function (): void {
    $r = prismFindings(dirname(__DIR__).'/Fixtures/uses-prism', 'php.txt');
    expect($r['sources'])->toBe(1)->and($r['findings'])->toBe([
        'composer.json require: prism-php/prism',
        'composer.json require-dev: particle-academy/prism-harness',
        'Adapter.php.txt: Prism\\Prism\\Prism',
        "Adapter.php.txt: 'ParticleAcademy\\PrismHarness\\Harness'",
    ]);
});

it('references no host user model anywhere', function (): void {
    $root = dirname(__DIR__, 2);
    $hits = [];
    foreach (['src', 'config', 'database', 'routes'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $text = (string) file_get_contents($file->getPathname());
            if (preg_match('/Models.User\b|\bUser::|->user\(\)|Authenticatable|auth\(\)/', $text)) {
                $hits[] = $file->getFilename();
            }
        }
    }
    expect($hits)->toBe([]);
});
