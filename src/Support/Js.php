<?php

declare(strict_types=1);

namespace Prism\Acp\Support;

/**
 * The few JavaScript semantics the reference's output depends on, so text built here reads byte for byte as it does
 * there: a string's length (UTF-16 code units, not bytes), a number's spelling, an ISO date, and Math.round.
 *
 * A JSON object is a stdClass and a JSON array a list throughout this package, the shapes Json::decode produces.
 */
final class Js
{
    /**
     * A JSON object: a stdClass, never a PHP array.
     *
     * @phpstan-assert-if-true \stdClass $value
     */
    public static function isObject(mixed $value): bool
    {
        return $value instanceof \stdClass;
    }

    /** A property of a JSON object, or null when the value is not an object or lacks it (JS's `undefined`). */
    public static function get(mixed $object, string $key): mixed
    {
        return $object instanceof \stdClass && property_exists($object, $key) ? $object->{$key} : null;
    }

    /** A property that is a string, or null: `typeof x.key === 'string' ? x.key : undefined`. */
    public static function string(mixed $object, string $key): ?string
    {
        $value = self::get($object, $key);

        return is_string($value) ? $value : null;
    }

    /** A property that is a number, or null: `typeof x.key === 'number' ? x.key : undefined`. */
    public static function number(mixed $object, string $key): int|float|null
    {
        $value = self::get($object, $key);

        return is_int($value) || is_float($value) ? $value : null;
    }

    /** Whether a JSON object HAS the property, null or not: JS's `!== undefined` on a parsed value. */
    public static function has(mixed $object, string $key): bool
    {
        return $object instanceof \stdClass && property_exists($object, $key);
    }

    /**
     * A number in the JSON sense: an int or a float, finite or not.
     *
     * @phpstan-assert-if-true int|float $value
     */
    public static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /**
     * A finite number at or above $minimum.
     *
     * @phpstan-assert-if-true int|float $value
     */
    public static function finite(mixed $value, float|int $minimum): bool
    {
        return (is_int($value) || (is_float($value) && is_finite($value))) && $value >= $minimum;
    }

    /**
     * Number.isSafeInteger: a whole number within +/-(2^53 - 1). A float such as 60.0 qualifies, as it does there.
     *
     * @phpstan-assert-if-true int|float $value
     */
    public static function isSafeInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return abs($value) <= 9007199254740991;
        }

        return is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= 9007199254740991;
    }

    /** `string.length`: UTF-16 code units. */
    public static function length(string $value): int
    {
        $length = 0;
        foreach (mb_str_split($value, 1, 'UTF-8') as $char) {
            $length += mb_ord($char, 'UTF-8') > 0xFFFF ? 2 : 1;
        }

        return $length;
    }

    /** JavaScript's whitespace (String#trim), as UTF-8 byte sequences so it works on any input. */
    private const WHITESPACE = '(?:[\t\n\x0B\f\r ]|\xC2\xA0|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80|\xEF\xBB\xBF)';

    /** String#trim: removes JavaScript's whitespace, which includes no-break spaces, U+2028/9 and the BOM. */
    public static function trim(string $value): string
    {
        return (string) preg_replace('/\A'.self::WHITESPACE.'++|'.self::WHITESPACE.'++\z/', '', $value);
    }

    /** Text as Node's UTF-8 decoder delivers it: each malformed sequence replaced with U+FFFD, the same count. */
    public static function scrub(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            return mb_scrub($value, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }

    /**
     * `string.slice(0, $units)`: the first $units UTF-16 code units. A character the cut would split in two is left
     * out whole (the reference would keep a lone surrogate, which a PHP string cannot hold).
     */
    public static function slice(string $value, int $units): string
    {
        $out = '';
        $length = 0;
        foreach (mb_str_split($value, 1, 'UTF-8') as $char) {
            $length += mb_ord($char, 'UTF-8') > 0xFFFF ? 2 : 1;
            if ($length > $units) {
                break;
            }
            $out .= $char;
        }

        return $out;
    }

    /** Math.round: the nearest integer, a half rounding toward +infinity. */
    public static function round(float|int $value): float|int
    {
        if (is_int($value) || ! is_finite($value)) {
            return $value;
        }
        $floor = floor($value);
        $rounded = $value - $floor >= 0.5 ? $floor + 1 : $floor;

        return abs($rounded) <= 9007199254740991 ? (int) $rounded : $rounded;
    }

    /** String(number): JavaScript's shortest round-trip spelling, an exponent only outside 1e-7 <= |n| < 1e21. */
    public static function numberToString(float|int $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'Infinity' : '-Infinity';
        }
        if ($value == 0) {
            return '0';
        }
        $sign = $value < 0 ? '-' : '';
        // serialize_precision -1 gives the shortest digits that round-trip, as JavaScript's algorithm does.
        $text = (string) json_encode(abs($value), JSON_PRESERVE_ZERO_FRACTION);
        $exponent = 0;
        if (preg_match('/^([0-9.]+)e([+-]?[0-9]+)$/i', $text, $m) === 1) {
            $text = $m[1];
            $exponent = (int) $m[2];
        }
        [$whole, $fraction] = array_pad(explode('.', $text), 2, '');
        $digits = ltrim($whole.$fraction, '0');
        $point = strlen($whole) + $exponent - (strlen($whole.$fraction) - strlen(ltrim($whole.$fraction, '0')));
        $digits = rtrim($digits, '0');
        $k = strlen($digits);
        $n = $point;

        if ($k <= $n && $n <= 21) {
            return $sign.$digits.str_repeat('0', $n - $k);
        }
        if ($n > 0 && $n <= 21) {
            return $sign.substr($digits, 0, $n).'.'.substr($digits, $n);
        }
        if ($n > -6 && $n <= 0) {
            return $sign.'0.'.str_repeat('0', -$n).$digits;
        }
        $e = $n - 1;
        $mantissa = $k === 1 ? $digits : $digits[0].'.'.substr($digits, 1);

        return $sign.$mantissa.'e'.($e >= 0 ? '+' : '-').abs($e);
    }

    /** Number#toLocaleString('en-US'): grouped thousands, at most three fraction digits. */
    public static function localeEn(float|int $value): string
    {
        if (is_int($value)) {
            return number_format($value);
        }
        if (! is_finite($value)) {
            return is_nan($value) ? 'NaN' : ($value > 0 ? '∞' : '-∞');
        }
        // The sign is carried separately: number_format drops it from a negative zero and from a value that rounds to
        // zero, and Intl keeps it ("-0").
        $negative = $value < 0 || ($value == 0 && fdiv(1, $value) < 0);

        return ($negative ? '-' : '').rtrim(rtrim(number_format(abs($value), 3), '0'), '.');
    }

    /**
     * Date#toISOString for epoch milliseconds. Like JavaScript it refuses a time outside +/-8.64e15 ms (a RangeError
     * there, a ValueError here) and spells a year outside 0..9999 with a sign and six digits.
     */
    public static function isoString(float|int $ms): string
    {
        if (! is_finite((float) $ms) || abs($ms) > 8.64e15) {
            throw new \ValueError('Invalid time value');
        }
        $ms = (int) ($ms < 0 ? ceil($ms) : floor($ms));
        $seconds = intdiv($ms, 1000);
        $millis = $ms % 1000;
        if ($millis < 0) {
            $millis += 1000;
            $seconds -= 1;
        }
        $date = (new \DateTimeImmutable('@'.$seconds))->setTimezone(new \DateTimeZone('UTC'));
        $year = (int) $date->format('Y');
        $yearText = $year >= 0 && $year <= 9999
            ? sprintf('%04d', $year)
            : ($year < 0 ? '-' : '+').sprintf('%06d', abs($year));

        return $yearText.$date->format('-m-d\TH:i:s').sprintf('.%03dZ', $millis);
    }

    /**
     * Describe a value WITHOUT quoting it, as the reference's refusals do: a number, boolean or null as itself, a string
     * as its type and length only (it may be a prompt or a credential), an array by its length.
     */
    public static function describe(mixed $value, bool $absent = false): string
    {
        return match (true) {
            $absent => 'undefined',
            $value === null => 'null',
            is_string($value) => 'string('.self::length($value).')',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => self::numberToString($value),
            is_array($value) => 'array('.count($value).')',
            default => 'object',
        };
    }
}
