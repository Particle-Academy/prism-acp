<?php

declare(strict_types=1);

namespace Prism\Acp;

/**
 * Provider text made safe to show: terminal escape sequences and non-whitespace control characters removed.
 *
 * Works on code points, as the reference does on UTF-16 units; every character it acts on is in the Basic Multilingual
 * Plane, so the two agree. Input that is not valid UTF-8 has its invalid bytes replaced with U+FFFD first, which is what
 * the reference sees after Node decodes the same bytes.
 */
final class Text
{
    /** @return array{text: string, changed: bool} */
    public static function stripAnsiControlSequences(string $value): array
    {
        $chars = mb_str_split(mb_scrub($value, 'UTF-8'), 1, 'UTF-8');
        $codes = array_map(fn (string $c): int => mb_ord($c, 'UTF-8'), $chars);
        $count = count($codes);
        $output = '';
        $index = 0;

        while ($index < $count) {
            $code = $codes[$index];

            if ($code === 0x1B) {
                $next = $codes[$index + 1] ?? -1;
                if ($next === 0x5B) {
                    $index = self::skipCsi($codes, $index + 2);
                } elseif ($next === 0x5D) {
                    $index = self::skipControlString($codes, $index + 2, true);
                } elseif (in_array($next, [0x50, 0x58, 0x5E, 0x5F], true)) {
                    $index = self::skipControlString($codes, $index + 2, false);
                } else {
                    $index = self::skipEscape($codes, $index + 1);
                }

                continue;
            }
            if ($code === 0x9B) {
                $index = self::skipCsi($codes, $index + 1);

                continue;
            }
            if ($code === 0x9D) {
                $index = self::skipControlString($codes, $index + 1, true);

                continue;
            }
            if (in_array($code, [0x90, 0x98, 0x9E, 0x9F], true)) {
                $index = self::skipControlString($codes, $index + 1, false);

                continue;
            }
            if ($code < 0x20 || ($code >= 0x7F && $code <= 0x9F)) {
                // Keep ordinary text layout while removing other C0 and all C1 controls.
                if ($code === 0x09 || $code === 0x0A || $code === 0x0D) {
                    $output .= $chars[$index];
                }
                $index += 1;

                continue;
            }

            $output .= $chars[$index];
            $index += 1;
        }

        return ['text' => $output, 'changed' => $output !== $value];
    }

    /** @param list<int> $codes */
    private static function skipCsi(array $codes, int $start): int
    {
        for ($index = $start; $index < count($codes); $index++) {
            if ($codes[$index] >= 0x40 && $codes[$index] <= 0x7E) {
                return $index + 1;
            }
        }

        return count($codes);
    }

    /** @param list<int> $codes */
    private static function skipControlString(array $codes, int $start, bool $allowBell): int
    {
        for ($index = $start; $index < count($codes); $index++) {
            $code = $codes[$index];
            if (($allowBell && $code === 0x07) || $code === 0x9C) {
                return $index + 1;
            }
            if ($code === 0x1B && ($codes[$index + 1] ?? -1) === 0x5C) {
                return $index + 2;
            }
        }

        return count($codes);
    }

    /** @param list<int> $codes */
    private static function skipEscape(array $codes, int $start): int
    {
        $index = $start;
        while ($index < count($codes)) {
            $code = $codes[$index];
            if ($code >= 0x30 && $code <= 0x7E) {
                return $index + 1;
            }
            if ($code < 0x20 || $code > 0x2F) {
                return $index;
            }
            $index += 1;
        }

        return count($codes);
    }
}
