<?php

declare(strict_types=1);

namespace FancyChat;

use RuntimeException;

/**
 * A refusal with a stable error code (`approval.state`, `part.size`, ...) and
 * the HTTP status it maps to. The message IS the code: it never carries a
 * value from the request or the backend.
 */
final class ChatException extends RuntimeException
{
    public function __construct(public readonly string $error, public readonly int $status = 422)
    {
        parent::__construct($error);
    }
}
