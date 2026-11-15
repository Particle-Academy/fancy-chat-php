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
    public const string SHA256 = '9e72d5b5896698d7fa75187725912e0b00d15b06a08cd38b38f66f6b43ee55f0';

    public const string MACHINES_SHA256 = '55db613b7bffa251544a7d8303bca8187e954b851b646d27f9e1a4c4393e4f81';

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
