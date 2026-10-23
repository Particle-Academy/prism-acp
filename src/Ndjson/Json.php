<?php

declare(strict_types=1);

namespace Prism\Acp\Ndjson;

use Prism\Acp\Support\Js;

/**
 * JSON in and out, made to match the reference (JavaScript's JSON.parse / JSON.stringify) byte for byte where the wire
 * can see the difference:
 *
 * - Objects decode to stdClass, so `{}` and `[]` stay different values and re-encode as they arrived.
 * - Key order follows JavaScript: an object's array-index keys ("0" .. "4294967294", no leading zeros) come first in
 *   ascending order, then every other key in arrival order. JSON.parse orders them that way, so a frame passed through
 *   verbatim re-encodes in the reference's order rather than the provider's.
 * - Numbers are JavaScript's: an integer beyond 2^53 decodes to the nearest double, as JSON.parse rounds it; every
 *   number encodes in JavaScript's spelling (1e+21, 1e-7, 1000000000000000, and 0 for negative zero); and a value that
 *   overflowed to infinity encodes as null.
 * - Encoding leaves "/" and non-ASCII characters unescaped, including U+2028 and U+2029, as JSON.stringify does.
 * - Text that is not valid UTF-8 has each malformed sequence replaced with U+FFFD, exactly as Node's decoder does.
 *
 * Two differences remain, and both are a frame no well-behaved provider sends:
 *
 * - A lone UTF-16 surrogate escape ("\ud800") cannot be held in a PHP string. The frame is still accepted, with the
 *   surrogate read as U+FFFD; JavaScript keeps it and writes it back as "\ud800".
 * - An object key beginning with a NUL character is refused by PHP's decoder, so that frame is reported invalid.
 * - Nesting depth: 512 levels, PHP's default. JSON.parse has no fixed limit; a frame nested deeper than 512 is refused
 *   here as invalid. No real frame comes near it, and the limit keeps a hostile frame from exhausting the stack.
 */
final class Json
{
    private const DEPTH = 512;

    private const ENCODE = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_LINE_TERMINATORS;

    private const DECODE = JSON_THROW_ON_ERROR;

    /** A JSON string (skipped whole) or a number token, in json_encode's output. Possessive, so it is linear. */
    private const TOKEN = '/"[^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+"|-?[0-9][0-9.eE+\-]*+/';

    /** A surrogate pair, a lone surrogate, or any other escape, matched left to right so an escaped "\\" is skipped. */
    private const ESCAPE = '/\\\\u(d[89ab][0-9a-f]{2})\\\\u(d[c-f][0-9a-f]{2})|\\\\u(d[89a-f][0-9a-f]{2})|\\\\./i';

    /** A JSON string (skipped whole) or the number token -0 written without a fraction or exponent. */
    private const NEGATIVE_ZERO = '/"[^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+"|-0(?![.0-9eE])/';

    /** @throws \JsonException */
    public static function decode(string $text): mixed
    {
        $text = Js::scrub($text);
        // json_decode reads `-0` as the integer 0; JSON.parse keeps the sign, and toLocaleString shows it.
        if (str_contains($text, '-0')) {
            $text = (string) preg_replace_callback(self::NEGATIVE_ZERO, static fn (array $m): string => $m[0] === '-0' ? '-0.0' : $m[0], $text);
        }
        try {
            $value = json_decode($text, false, self::DEPTH, self::DECODE);
        } catch (\JsonException $e) {
            if ($e->getCode() !== JSON_ERROR_UTF16) {
                throw $e;
            }
            $value = json_decode(self::withoutLoneSurrogates($text), false, self::DEPTH, self::DECODE);
        }

        return self::jsValue($value);
    }

    /** @throws \JsonException */
    public static function encode(mixed $value): string
    {
        try {
            $json = json_encode($value, self::ENCODE, self::DEPTH);
        } catch (\JsonException $e) {
            if ($e->getCode() !== JSON_ERROR_INF_OR_NAN) {
                throw $e;
            }
            $json = json_encode(self::finiteOnly($value), self::ENCODE, self::DEPTH);
        }
        if (! str_contains($json, 'e+') && ! str_contains($json, 'e-') && ! str_contains($json, '-0')) {
            return $json;
        }

        return (string) preg_replace_callback(self::TOKEN, static function (array $m): string {
            $token = $m[0];
            if ($token[0] === '"' || (! str_contains($token, 'e') && $token !== '-0')) {
                return $token;
            }

            return Js::numberToString((float) $token);
        }, $json);
    }

    /**
     * An object built here, in the key order JavaScript would give it: array-index keys first, ascending. For an
     * object whose keys a provider or client chose (server names, header names), built with Object.fromEntries there.
     */
    public static function ordered(\stdClass $object): \stdClass
    {
        return self::orderObject($object);
    }

    /** An array index in JavaScript's sense: the canonical spelling of an integer from 0 to 2^32 - 2. */
    public static function isArrayIndex(int|string $key): bool
    {
        $key = (string) $key;
        if ($key === '' || strlen($key) > 10 || ! ctype_digit($key) || ($key[0] === '0' && $key !== '0')) {
            return false;
        }

        return (int) $key <= 4294967294;
    }

    private static function withoutLoneSurrogates(string $text): string
    {
        return (string) preg_replace_callback(self::ESCAPE, static fn (array $m): string => ($m[3] ?? '') !== '' ? '\\ufffd' : $m[0], $text);
    }

    /** Key order and number precision as JSON.parse leaves them. */
    private static function jsValue(mixed $value): mixed
    {
        if (is_int($value)) {
            return $value > 9007199254740992 || $value < -9007199254740992 ? (float) $value : $value;
        }
        if (is_array($value)) {
            foreach ($value as $i => $item) {
                $value[$i] = self::jsValue($item);
            }

            return $value;
        }

        return $value instanceof \stdClass ? self::orderObject($value) : $value;
    }

    private static function orderObject(\stdClass $value): \stdClass
    {
        $indexes = [];
        $others = [];
        foreach (get_object_vars($value) as $key => $item) {
            if (self::isArrayIndex($key)) {
                $indexes[(int) $key] = self::jsValue($item);
            } else {
                $others[(string) $key] = self::jsValue($item);
            }
        }
        if ($indexes === []) {
            foreach ($others as $key => $item) {
                $value->{$key} = $item;
            }

            return $value;
        }
        ksort($indexes, SORT_NUMERIC);
        $ordered = new \stdClass;
        foreach ($indexes as $key => $item) {
            $ordered->{(string) $key} = $item;
        }
        foreach ($others as $key => $item) {
            $ordered->{$key} = $item;
        }

        return $ordered;
    }

    /** JSON.stringify writes a non-finite number as null. */
    private static function finiteOnly(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if (is_array($value)) {
            return array_map(self::finiteOnly(...), $value);
        }
        if ($value instanceof \stdClass) {
            $out = new \stdClass;
            foreach (get_object_vars($value) as $key => $item) {
                $out->{$key} = self::finiteOnly($item);
            }

            return $out;
        }

        return $value;
    }
}
