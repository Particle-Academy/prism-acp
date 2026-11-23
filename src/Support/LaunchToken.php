<?php

declare(strict_types=1);

namespace Prism\Acp\Support;

/**
 * How launch arguments and RPC method names are compared, as prism-acp-ts src/remote-control.ts does: padding and
 * invisible format characters removed, then full Unicode lower-casing.
 */
final class LaunchToken
{
    /** The reference's PADDING: JavaScript's `\s`, spelled out, plus the invisible format characters. */
    private const PADDING = '/[\x{09}-\x{0d}\x{20}\x{a0}\x{ad}\x{1680}\x{180e}\x{2000}-\x{200f}\x{2028}-\x{202f}\x{205f}-\x{2064}\x{3000}\x{feff}]/u';

    /** The comparable form, or null for text that is not valid UTF-8 and so cannot be compared. */
    public static function normalise(string $token): ?string
    {
        if (! mb_check_encoding($token, 'UTF-8')) {
            return null;
        }
        $stripped = preg_replace(self::PADDING, '', $token);

        return $stripped === null ? null : mb_strtolower($stripped, 'UTF-8');
    }
}
