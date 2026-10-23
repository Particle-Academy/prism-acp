<?php

declare(strict_types=1);

namespace Prism\Acp\Ndjson;

/**
 * JSON in and out, made to match the reference (JavaScript's JSON.parse / JSON.stringify) byte for byte where the wire
 * can see the difference:
 *
 * - Objects decode to stdClass, so `{}` and `[]` stay different values and re-encode as they arrived.
 * - Encoding leaves "/" and non-ASCII characters unescaped, including U+2028 and U+2029, as JSON.stringify does.
 * - Whole-number floats encode without a fraction (1.0 -> 1), as in JavaScript. Two float spellings still differ, and
 *   neither occurs in the protocol: exponents (PHP 1.0e+21, JS 1e+21) and negative zero (PHP -0, JS 0).
 * - Nesting depth: 512 levels, PHP's default. JSON.parse has no fixed limit; a frame nested deeper than 512 is refused
 *   here as invalid. No real frame comes near it, and the limit keeps a hostile frame from exhausting the stack.
 */
final class Json
{
    private const DEPTH = 512;

    private const ENCODE = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_LINE_TERMINATORS;

    /** @throws \JsonException */
    public static function decode(string $text): mixed
    {
        return json_decode($text, false, self::DEPTH, JSON_THROW_ON_ERROR);
    }

    /** @throws \JsonException */
    public static function encode(mixed $value): string
    {
        return json_encode($value, self::ENCODE, self::DEPTH);
    }
}
