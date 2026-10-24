<?php

declare(strict_types=1);

namespace Prism\Acp\Ndjson;

/** One NDJSON line: a decoded value, or the reason it could not be read. */
final readonly class Frame
{
    private function __construct(
        public bool $ok,
        public mixed $value,
        public ?FrameError $error,
    ) {}

    public static function ok(mixed $value): self
    {
        return new self(true, $value, null);
    }

    public static function failed(FrameError $error): self
    {
        return new self(false, null, $error);
    }
}
