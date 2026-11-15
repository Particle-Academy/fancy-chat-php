<?php

declare(strict_types=1);

namespace FancyChat\Contract;

use InvalidArgumentException;

/**
 * Message parts, ported from fancy-chat-js `parts.ts`: the same checks in the
 * same order with the same error codes, and the same plain-text forms.
 *
 * Origin is who authored the part: only `server` code may place HITL parts or
 * raw ECharts options. Anything a model or a human typed is `agent` origin.
 */
final class Parts
{
    public const array CORE_KINDS = ['text', 'code', 'table', 'chart', 'file', 'image', 'tool-call', 'tool-result', 'card', 'question', 'approval'];

    public const array CHART_TYPES = ['line', 'bar', 'area', 'pie', 'scatter'];

    /** @var array{maxPartBytes: int, maxDepth: int, maxParts: int} */
    public const array DEFAULT_LIMITS = ['maxPartBytes' => 65536, 'maxDepth' => 3, 'maxParts' => 64];

    private const string EXTENSION_KIND = '/^x-[a-z0-9][a-z0-9-]*\.[a-z0-9][a-z0-9._-]*$/';

    private const string FENCE = '```';

    /** @var array<string, array{validate: ?callable, toText: ?callable}> */
    private static array $registry = [];

    /**
     * Registers an app part kind (`x-<app>.<name>`): a validator returning
     * true, false or an error code, and a plain-text form.
     */
    public static function register(string $kind, ?callable $validate = null, ?callable $toText = null): void
    {
        if (in_array($kind, self::CORE_KINDS, true)) {
            throw new InvalidArgumentException("Core part kind \"{$kind}\" cannot be redefined.");
        }
        if (preg_match(self::EXTENSION_KIND, $kind) !== 1) {
            throw new InvalidArgumentException('An app part kind must be named x-<app>.<name>.');
        }
        if (isset(self::$registry[$kind])) {
            throw new InvalidArgumentException("Part kind \"{$kind}\" is already registered.");
        }
        self::$registry[$kind] = ['validate' => $validate, 'toText' => $toText];
    }

    public static function forgetRegistrations(): void
    {
        self::$registry = [];
    }

    /**
     * @param  array<string, int>  $limits
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public static function validate(mixed $part, string $origin, array $limits = []): array
    {
        return self::validateAt($part, $origin, array_merge(self::DEFAULT_LIMITS, $limits), 1);
    }

    /**
     * A message's parts: the count cap, then each part.
     *
     * @param  array<string, int>  $limits
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public static function validateList(mixed $parts, string $origin, array $limits = []): array
    {
        $limits = array_merge(self::DEFAULT_LIMITS, $limits);
        if (! Json::isList($parts)) {
            return self::fail('message.parts');
        }
        if (count($parts) > $limits['maxParts']) {
            return self::fail('message.parts.count');
        }
        foreach ($parts as $part) {
            $r = self::validateAt($part, $origin, $limits, 1);
            if (! $r['ok']) {
                return $r;
            }
        }

        return ['ok' => true];
    }

    /** @return array{ok: false, error: string} */
    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }

    private static function str(mixed $v): bool
    {
        return is_string($v);
    }

    /** @param  array<string, mixed>  $p */
    private static function optStr(array $p, string $key): bool
    {
        return ! array_key_exists($key, $p) || $p[$key] === null || is_string($p[$key]);
    }

    /** @param  array<string, mixed>  $p */
    private static function optNum(array $p, string $key): bool
    {
        return ! array_key_exists($key, $p) || $p[$key] === null || Json::isNumber($p[$key]);
    }

    /**
     * @param  array{maxPartBytes: int, maxDepth: int, maxParts: int}  $limits
     * @return array{ok: true}|array{ok: false, error: string}
     */
    private static function validateAt(mixed $p, string $origin, array $limits, int $depth): array
    {
        if ($depth > $limits['maxDepth']) {
            return self::fail('part.depth');
        }
        if (! Json::isObject($p)) {
            return self::fail('part.shape');
        }
        if (! is_string($p['id'] ?? null) || $p['id'] === '') {
            return self::fail('part.id');
        }
        if ($depth === 1 && Json::bytes($p) > $limits['maxPartBytes']) {
            return self::fail('part.size');
        }
        if (! is_string($p['kind'] ?? null)) {
            return self::fail('part.kind');
        }
        $kind = $p['kind'];

        if (! in_array($kind, self::CORE_KINDS, true)) {
            if (preg_match(self::EXTENSION_KIND, $kind) !== 1) {
                return self::fail('part.kind');
            }
            $validate = self::$registry[$kind]['validate'] ?? null;
            if ($validate !== null) {
                $verdict = $validate($p);
                if ($verdict !== true) {
                    return self::fail(is_string($verdict) ? $verdict : 'part.'.$kind);
                }
            }

            return ['ok' => true];
        }

        switch ($kind) {
            case 'text':
                $formatOk = in_array($p['format'] ?? null, ['markdown', 'plain'], true);
                $interimOk = ! array_key_exists('interim', $p) || is_bool($p['interim']);

                return $formatOk && is_string($p['text'] ?? null) && $interimOk ? ['ok' => true] : self::fail('part.text');
            case 'code':
                return is_string($p['language'] ?? null) && is_string($p['code'] ?? null) && self::optStr($p, 'filename') ? ['ok' => true] : self::fail('part.code');
            case 'table':
                $cols = $p['columns'] ?? null;
                $rows = $p['rows'] ?? null;
                if (! Json::isList($cols) || ! Json::isList($rows) || ! self::optStr($p, 'caption')) {
                    return self::fail('part.table');
                }
                foreach ($cols as $c) {
                    if (! Json::isObject($c) || ! is_string($c['key'] ?? null) || $c['key'] === '' || ! is_string($c['label'] ?? null) || ! self::optStr($c, 'type')) {
                        return self::fail('part.table');
                    }
                }
                foreach ($rows as $row) {
                    if (! Json::isObject($row)) {
                        return self::fail('part.table');
                    }
                }

                return ['ok' => true];
            case 'chart':
                if (! self::optStr($p, 'title')) {
                    return self::fail('part.chart');
                }
                if (array_key_exists('echarts', $p)) {
                    // Raw ECharts options run arbitrary formatters and styling; they
                    // are accepted only from trusted server code, never from a model.
                    if ($origin !== 'server') {
                        return self::fail('part.chart.raw');
                    }

                    return Json::isObject($p['echarts']) ? ['ok' => true] : self::fail('part.chart');
                }
                $spec = $p['spec'] ?? null;
                if (! Json::isObject($spec) || ! is_string($spec['type'] ?? null) || ! in_array($spec['type'], self::CHART_TYPES, true)) {
                    return self::fail('part.chart');
                }
                if (! Json::isList($spec['series'] ?? null)) {
                    return self::fail('part.chart');
                }
                foreach ($spec['series'] as $s) {
                    if (! Json::isObject($s) || ! Json::isList($s['data'] ?? null) || ! self::optStr($s, 'name')) {
                        return self::fail('part.chart');
                    }
                }

                return ['ok' => true];
            case 'file':
                $size = $p['size'] ?? null;

                return is_string($p['name'] ?? null) && is_string($p['mimeType'] ?? null) && Json::isInteger($size) && $size >= 0 && is_string($p['ref'] ?? null)
                    ? ['ok' => true] : self::fail('part.file');
            case 'image':
                return is_string($p['ref'] ?? null) && is_string($p['alt'] ?? null) && self::optNum($p, 'width') && self::optNum($p, 'height')
                    ? ['ok' => true] : self::fail('part.image');
            case 'tool-call':
                return is_string($p['toolCallId'] ?? null) && is_string($p['name'] ?? null) && Json::isObject($p['args'] ?? null)
                    ? ['ok' => true] : self::fail('part.tool-call');
            case 'tool-result':
                $outcomeOk = in_array($p['outcome'] ?? null, ['ok', 'failure', 'refusal'], true);
                $content = $p['content'] ?? null;
                $contentOk = is_string($content) || Json::isList($content);

                return is_string($p['toolCallId'] ?? null) && $outcomeOk && $contentOk && self::optNum($p, 'durationMs')
                    ? ['ok' => true] : self::fail('part.tool-result');
            case 'card':
                if (! is_string($p['cardType'] ?? null) || ! self::optStr($p, 'title') || ! Json::isList($p['body'] ?? null)) {
                    return self::fail('part.card');
                }
                if (array_key_exists('fields', $p)) {
                    if (! Json::isList($p['fields'])) {
                        return self::fail('part.card');
                    }
                    foreach ($p['fields'] as $f) {
                        if (! Json::isObject($f) || ! is_string($f['label'] ?? null) || ! is_string($f['value'] ?? null)) {
                            return self::fail('part.card');
                        }
                    }
                }
                if (array_key_exists('actions', $p)) {
                    if (! Json::isList($p['actions'])) {
                        return self::fail('part.card.action');
                    }
                    foreach ($p['actions'] as $a) {
                        if (! Json::isObject($a) || ! is_string($a['id'] ?? null) || ! is_string($a['label'] ?? null)) {
                            return self::fail('part.card.action');
                        }
                        $actionKind = $a['kind'] ?? null;
                        if ($actionKind === 'link') {
                            if (! is_string($a['href'] ?? null) || preg_match('#^https?://#i', $a['href']) !== 1) {
                                return self::fail('part.card.action');
                            }
                        } elseif ($actionKind !== 'post-back') {
                            return self::fail('part.card.action');
                        }
                    }
                }
                foreach ($p['body'] as $child) {
                    $r = self::validateAt($child, $origin, $limits, $depth + 1);
                    if (! $r['ok']) {
                        return $r;
                    }
                }

                return ['ok' => true];
            case 'question':
            case 'approval':
                // HITL parts are references into server records; a model can never place one.
                if ($origin !== 'server') {
                    return self::fail('part.hitl.origin');
                }
                $ref = $kind === 'question' ? ($p['questionId'] ?? null) : ($p['approvalId'] ?? null);

                return is_string($ref) && $ref !== '' ? ['ok' => true] : self::fail('part.'.$kind);
        }

        return self::fail('part.kind');
    }

    /** JavaScript's String(value) for the scalar cells a table holds. */
    private static function jsString(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            $v === true => 'true',
            $v === false => 'false',
            is_float($v) && floor($v) === $v && abs($v) < 1e15 => (string) (int) $v,
            is_scalar($v) => (string) $v,
            default => (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * Plain text for a part: copy, notifications, storage, and the fallback
     * render of a kind the client does not know. Never empty-drops a part.
     *
     * @param  array<string, mixed>  $part
     */
    public static function toText(array $part): string
    {
        $kind = $part['kind'] ?? '';
        switch ($kind) {
            case 'text':
                return (string) ($part['text'] ?? '');
            case 'code':
                return self::FENCE.($part['language'] ?? '')."\n".($part['code'] ?? '')."\n".self::FENCE;
            case 'table':
                $cols = $part['columns'] ?? [];
                $cell = fn (mixed $v): string => (string) preg_replace('/[\t\n\r]/', ' ', self::jsString($v));
                $lines = [implode("\t", array_map(fn (array $c): string => $cell($c['label'] ?? null), $cols))];
                foreach ($part['rows'] ?? [] as $row) {
                    $lines[] = implode("\t", array_map(fn (array $c): string => $cell($row[$c['key']] ?? null), $cols));
                }

                return implode("\n", $lines);
            case 'chart':
                return is_string($part['title'] ?? null) && $part['title'] !== '' ? '[chart: '.$part['title'].']' : '[chart]';
            case 'file':
                return '[file: '.($part['name'] ?? '').']';
            case 'image':
                return '[image: '.($part['alt'] ?? '').']';
            case 'tool-call':
                return '[tool: '.($part['name'] ?? '').']';
            case 'tool-result':
                return is_string($part['content'] ?? null) ? $part['content'] : '[tool result: '.($part['outcome'] ?? '').']';
            case 'card':
                $lines = [];
                if (is_string($part['title'] ?? null) && $part['title'] !== '') {
                    $lines[] = $part['title'];
                }
                foreach ($part['body'] ?? [] as $child) {
                    $t = self::toText($child);
                    if ($t !== '') {
                        $lines[] = $t;
                    }
                }

                return implode("\n", $lines);
            case 'question':
                return '[question]';
            case 'approval':
                return '[approval]';
        }
        $toText = self::$registry[$kind]['toText'] ?? null;

        return $toText !== null ? (string) $toText($part) : '['.$kind.']';
    }
}
