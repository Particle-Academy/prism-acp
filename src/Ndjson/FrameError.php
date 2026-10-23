<?php

declare(strict_types=1);

namespace Prism\Acp\Ndjson;

/** Why a line could not be read. Carries the line's SIZE, never any of its content. */
final class FrameError
{
    private function __construct(
        /** 'oversize' when the cap was hit, 'invalid-json' when parsing failed. */
        public readonly string $kind,
        /** Byte length of the offending line, reported instead of its content. */
        public readonly int $bytes,
        /** Safe to log: never contains any part of the line. */
        public readonly string $message,
    ) {}

    public static function oversize(int $bytes): self
    {
        return new self('oversize', $bytes, sprintf(
            'NDJSON frame exceeds %d bytes (%d bytes; content withheld)', NdjsonFramer::MAX_LINE_BYTES, $bytes,
        ));
    }

    public static function invalidJson(int $bytes): self
    {
        return new self('invalid-json', $bytes, sprintf('NDJSON frame is not valid JSON (%d bytes; content withheld)', $bytes));
    }
}
