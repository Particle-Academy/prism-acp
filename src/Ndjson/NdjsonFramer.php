<?php

declare(strict_types=1);

namespace Prism\Acp\Ndjson;

use Prism\Acp\Support\Js;

/**
 * NDJSON framing for the Agent Client Protocol: one JSON value per line, newline-delimited.
 *
 * The three decisions are PINNED, identically in every port (prism-acp-ts src/ndjson.ts is the reference):
 *
 * 1. A trailing "\r" is STRIPPED before parsing, not left to the parser's tolerance.
 * 2. MAX_LINE_BYTES is 64,000,000 in every port. It bounds a runaway line (a producer that never writes a newline);
 *    it is not a size budget for ordinary frames.
 * 3. An oversized or unparseable line reports its SIZE, never its CONTENT: a line can carry a prompt, a file or a
 *    credential.
 *
 * Blank lines are skipped. PHP strings are bytes, so the cap is measured in bytes by construction, and a multi-byte
 * character split across two chunks is simply held in the buffer until its line completes. A completed line is
 * measured after malformed UTF-8 is replaced, as the reference measures it; a line still accumulating is measured raw,
 * which differs only for a line that is both malformed and within a few bytes of the cap.
 *
 * JSON objects decode to stdClass, never to arrays: `{}` and `[]` are different values on the wire, and a frame
 * re-encoded by this package must come out as the same JSON the reference produces.
 */
final class NdjsonFramer
{
    /** The same number in every port. */
    public const MAX_LINE_BYTES = 64_000_000;

    private string $buffer = '';

    /**
     * Feed one chunk. Returns every frame completed by it, which may be none.
     *
     * @return list<Frame>
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;

        return $this->drain();
    }

    /**
     * Finish the stream: a producer that exits without a trailing newline has still sent a complete line, and it is
     * usually the `result` frame that carries the outcome.
     *
     * @return list<Frame>
     */
    public function end(): array
    {
        $frames = $this->drain();
        $rest = $this->buffer;
        $this->buffer = '';
        $frame = $rest === '' ? null : self::parseLine($rest);

        return $frame === null ? $frames : [...$frames, $frame];
    }

    /**
     * Parse one line. Null for a blank line, which is not an error.
     */
    public static function parseLine(string $line): ?Frame
    {
        // Measured and parsed as the reference sees the line: after Node's decoder has replaced malformed UTF-8.
        $line = Js::scrub($line);
        $text = str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;
        if (Js::trim($text) === '') {
            return null;
        }

        $bytes = strlen($text);
        if ($bytes > self::MAX_LINE_BYTES) {
            return Frame::failed(FrameError::oversize($bytes));
        }

        try {
            return Frame::ok(Json::decode($text));
        } catch (\JsonException) {
            // The reason, never the line: json_decode's own message can quote the input.
            return Frame::failed(FrameError::invalidJson($bytes));
        }
    }

    /**
     * @return list<Frame>
     */
    private function drain(): array
    {
        $frames = [];
        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $newline);
            $this->buffer = (string) substr($this->buffer, $newline + 1);
            $frame = self::parseLine($line);
            if ($frame !== null) {
                $frames[] = $frame;
            }
        }

        // The cap applies to a line still being accumulated, or an endless line would grow the buffer forever.
        $pending = strlen($this->buffer);
        if ($pending > self::MAX_LINE_BYTES) {
            $this->buffer = '';
            $frames[] = Frame::failed(FrameError::oversize($pending));
        }

        return $frames;
    }

    /** Serialise one value as an NDJSON line, trailing newline included. */
    public static function encodeLine(mixed $value): string
    {
        return Json::encode($value)."\n";
    }
}
