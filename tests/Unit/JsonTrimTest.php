<?php

declare(strict_types=1);

use FancyChat\Contract\Json;

/*
 * A REGRESSION GUARD, not a fix. Json::jsTrim runs on human-typed answer values
 * and notes (POST questions/{id}/answer), and its trailing `[\s...]+$` has the
 * shape of fancy-chat-ui code scanning #1. Measured 2026-11-15: PCRE2
 * auto-possessifies the class, so 200k characters run in about 10 ms on the
 * current regex and this file passed before any change. It is here so a later
 * rewrite of the trim (or an engine without that optimisation) cannot go
 * quadratic, or hit the backtrack limit, where preg_replace returns null and
 * `(string) null` would read a real note as blank.
 */

it('trims in linear time when white space is not at the end', function (): void {
    $hostile = 'a'.str_repeat(' ', 200000).'b';
    $started = hrtime(true);
    expect(Json::jsTrim($hostile))->toBe($hostile);
    expect((hrtime(true) - $started) / 1e6)->toBeLessThan(250.0);
});

it('never reads a long, real value as blank', function (): void {
    $note = "  keep\u{3000}".str_repeat("\t", 100000)."this\n ";
    expect(Json::jsTrim($note))->toBe("keep\u{3000}".str_repeat("\t", 100000).'this');
});

it('trims every white space JavaScript trims, from both ends only', function (string $in, string $out): void {
    expect(Json::jsTrim($in))->toBe($out);
})->with([
    'ascii' => [" \t\n\r\x0B\x0Cx y\r\n", 'x y'],
    'nbsp and ideographic' => ["\u{00A0}\u{3000}x\u{00A0}y\u{FEFF}", "x\u{00A0}y"],
    'en quad to hair space' => ["\u{2000}\u{200A}x\u{2009}", 'x'],
    'line and paragraph separators' => ["\u{2028}x\u{2029}", 'x'],
    'narrow nbsp, math space' => ["\u{202F}x\u{205F}", 'x'],
    'ogham' => ["\u{1680}x", 'x'],
    'all white space' => ["  \u{3000} ", ''],
    'empty' => ['', ''],
    'zero width space is not white space' => ["\u{200B}x", "\u{200B}x"],
]);
