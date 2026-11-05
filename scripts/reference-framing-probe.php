<?php

declare(strict_types=1);

namespace Prism\Acp\Ndjson;

/** Owned CLI inventory instrumentation; never loaded by the package autoloader. */
final class ReferenceFramingProbe
{
    private static bool $recording = false;

    private static int $searchedBytes = 0;

    private static int $calls = 0;

    public static function search(string $haystack, int $offset): void
    {
        if (self::$recording) {
            self::$searchedBytes += strlen($haystack) - $offset;
            self::$calls++;
        }
    }

    /** @return array{inputBytes: int, searchedBytes: int, calls: int, incremental: bool} */
    public static function observe(): array
    {
        self::$searchedBytes = self::$calls = 0;
        self::$recording = true;
        $framer = new NdjsonFramer;
        $inputBytes = 0;
        try {
            $chunks = ['{"v":"', ...array_fill(0, 16, str_repeat('x', 1024)), '"}'."\n"];
            foreach ($chunks as $index => $chunk) {
                $inputBytes += strlen($chunk);
                $frames = $framer->push($chunk);
                if ($index < count($chunks) - 1 && $frames !== []) {
                    throw new \RuntimeException('Owned framing probe emitted an unfinished frame');
                }
            }
            if (count($frames) !== 1 || ! $frames[0]->ok || strlen($frames[0]->value->v) !== 16 * 1024) {
                throw new \RuntimeException('Owned framing probe did not complete its synthetic frame');
            }
        } finally {
            self::$recording = false;
        }
        if (self::$calls === 0) {
            throw new \RuntimeException('Framing search could not be measured; review the inventory probe');
        }

        return ['inputBytes' => $inputBytes, 'searchedBytes' => self::$searchedBytes, 'calls' => self::$calls,
            'incremental' => self::$searchedBytes <= $inputBytes];
    }
}

/** Forward the native search unchanged while counting its actual search range in this CLI process only. */
function strpos(string $haystack, string $needle, int $offset = 0): int|false
{
    ReferenceFramingProbe::search($haystack, $offset);

    return \strpos($haystack, $needle, $offset);
}
