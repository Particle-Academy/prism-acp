<?php

declare(strict_types=1);

namespace Prism\Acp\Support;

/**
 * Untrusted provider text, made safe to show. Ported from prism-acp-ts's `boundProviderText` (src/text.ts).
 *
 * Hidden and control characters are marked VISIBLE as `<U+XXXX>`, not removed, so a reader can see that something
 * was there and nothing invisible reorders or hides the text around it. The result is then bounded to a number of
 * UTF-16 code units, the unit the reference counts in, with TRUNCATED appended when it was cut. The cut never splits a
 * character or a `<U+XXXX>` mark. Text that is not valid UTF-8 is first read as Node's decoder reads it (Js::scrub).
 */
final class ProviderText
{
    /** The most UTF-16 code units one piece of provider text keeps, after marking, before TRUNCATED. */
    public const MAX = 2048;

    /** Appended to provider text cut at MAX. */
    public const TRUNCATED = '…[truncated]';

    public static function bound(string $value, int $max = self::MAX): string
    {
        $marked = (string) preg_replace_callback(
            HiddenCharacters::PROVIDER,
            static fn (array $match): string => sprintf('<U+%04X>', mb_ord($match[0], 'UTF-8')),
            Js::scrub($value),
        );
        if (Js::length($marked) <= $max) {
            return $marked;
        }
        $cut = Js::slice($marked, $max);
        $mark = strrpos($cut, '<U+');
        if ($mark !== false && ! str_contains(substr($cut, $mark), '>')) {
            $cut = substr($cut, 0, $mark);
        }

        return $cut.self::TRUNCATED;
    }
}
