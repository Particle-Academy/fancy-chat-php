<?php

declare(strict_types=1);

namespace FancyChat\Backend;

/** What a cancel returns. `uncertain` means the client should offer a restart. */
final class CancelOutcome
{
    public const string OK = 'ok';

    public const string NOT_RUNNING = 'not-running';

    public const string NO_TURN = 'no-turn';

    public const string UNSUPPORTED = 'unsupported';

    public const string REFUSED = 'refused';

    public const string UNCERTAIN = 'uncertain';

    public const array ALL = [self::OK, self::NOT_RUNNING, self::NO_TURN, self::UNSUPPORTED, self::REFUSED, self::UNCERTAIN];
}
