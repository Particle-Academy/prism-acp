<?php

declare(strict_types=1);

namespace Prism\Acp;

/** Provider session ids cross from untrusted frames into resume requests and argv. */
final class SessionId
{
    public static function isValidCliSessionId(mixed $value): bool
    {
        // \z, not $: a $ would accept a trailing newline, and this id is passed as an argument.
        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $value) === 1;
    }
}
