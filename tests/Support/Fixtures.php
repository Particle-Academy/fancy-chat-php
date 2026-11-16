<?php

declare(strict_types=1);

namespace FancyChat\Tests\Support;

/**
 * The shared conformance fixture and transition table, byte-identical with
 * fancy-chat-js. Both hashes are pinned in both legs (CRLF folded to LF, so a
 * Windows checkout reads what Linux CI reads), so "the same fixtures" is a test
 * result rather than a promise.
 */
final class Fixtures
{
    public const string SHA256 = '295439897db175e8040f4a48effd5f55a18817aac342d2d39335dc030c39040b';

    public const string MACHINES_SHA256 = '78959500f6db660cc3b13c808033468edd2dfa5d9d89e30e9d60f0696764c142';

    public static function path(): string
    {
        return dirname(__DIR__, 2).'/conformance/chat-v1.json';
    }

    public static function machinesPath(): string
    {
        return dirname(__DIR__, 2).'/contract/machines.json';
    }

    public static function sha256(string $path): string
    {
        return hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($path)));
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return json_decode((string) file_get_contents(self::path()), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public static function section(string $name): array
    {
        return self::all()[$name];
    }

    /**
     * Cases as a Pest dataset keyed by a readable label.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(string $section, callable $label): array
    {
        $out = [];
        foreach (self::section($section)['cases'] as $i => $case) {
            $out[$i.': '.$label($case)] = [$case];
        }

        return $out;
    }
}
