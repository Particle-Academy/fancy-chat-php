<?php

declare(strict_types=1);

namespace FancyChat\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \FancyChat\FancyChat actor(callable $resolver)
 * @method static \FancyChat\FancyChat access(callable $resolver)
 * @method static \FancyChat\FancyChat backend(\FancyChat\Backend\AgentBackend $backend)
 * @method static \FancyChat\FancyChat stagedWrite(string $name, \FancyChat\StagedWrite $write)
 * @method static array<string, mixed> append(string $threadId, array<string, mixed> $partial)
 * @method static array<string, mixed> snapshot(string $threadId, ?int $at = null)
 *
 * @see \FancyChat\FancyChat
 */
final class FancyChat extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \FancyChat\FancyChat::class;
    }
}
