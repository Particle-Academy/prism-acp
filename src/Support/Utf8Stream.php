<?php

declare(strict_types=1);

namespace Prism\Acp\Support;

/**
 * Bytes to text across chunks, as Node's streaming UTF-8 decoder does: a character split between two chunks is held
 * until it completes, and a malformed sequence becomes U+FFFD.
 */
final class Utf8Stream
{
    private string $held = '';

    public function push(string $bytes): string
    {
        $bytes = $this->held.$bytes;
        $this->held = '';
        // Hold back an incomplete sequence at the end: up to three bytes that could still begin a valid character.
        $length = strlen($bytes);
        for ($back = 1; $back <= min(3, $length); $back++) {
            $byte = ord($bytes[$length - $back]);
            if ($byte < 0x80) {
                break;
            }
            if ($byte >= 0xC0) {
                $need = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);
                if ($need > $back) {
                    $this->held = substr($bytes, $length - $back);
                    $bytes = substr($bytes, 0, $length - $back);
                }
                break;
            }
        }

        return Js::scrub($bytes);
    }

    /** The end of the stream: anything held is malformed now. */
    public function end(): string
    {
        $held = $this->held;
        $this->held = '';

        return Js::scrub($held);
    }
}
