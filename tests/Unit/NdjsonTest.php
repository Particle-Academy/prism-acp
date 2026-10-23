<?php

declare(strict_types=1);

use Prism\Acp\Ndjson\Frame;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Ndjson\NdjsonFramer;

/*
 * Ported from prism-acp-ts test/ndjson.test.ts. The three framing decisions are pinned identically in every port.
 */

/** @param list<string> $chunks */
function framesOf(array $chunks): array
{
    $framer = new NdjsonFramer;
    $out = [];
    foreach ($chunks as $chunk) {
        array_push($out, ...$framer->push($chunk));
    }

    return [...$out, ...$framer->end()];
}

function values(array $frames): array
{
    return array_map(fn (Frame $f) => $f->ok ? Json::encode($f->value) : $f->error->kind, $frames);
}

describe('framing', function () {
    it('reads one line', fn () => expect(values(framesOf(["{\"a\":1}\n"])))->toBe(['{"a":1}']));

    it('reads a line that arrives across chunks', fn () => expect(values(framesOf(['{"a', '":1}', "\n"])))->toBe(['{"a":1}']));

    it('reads several lines from one chunk', fn () => expect(values(framesOf(["{\"a\":1}\n{\"b\":2}\n"])))->toBe(['{"a":1}', '{"b":2}']));

    it('keeps a trailing line that never got its newline', fn () => expect(values(framesOf(['{"a":1}'])))->toBe(['{"a":1}']));

    it('skips blank lines rather than failing on them', fn () => expect(values(framesOf(["\n", "  \n", "{\"a\":1}\n", "\n"])))->toBe(['{"a":1}']));
});

describe('decision 1: a trailing \r is stripped, not left to the parser', function () {
    it('parses a CRLF-framed stream', fn () => expect(values(framesOf(["{\"a\":1}\r\n{\"b\":2}\r\n"])))->toBe(['{"a":1}', '{"b":2}']));

    it('strips the \r at the line level', fn () => expect(Json::encode(NdjsonFramer::parseLine("{\"a\":1}\r")->value))->toBe('{"a":1}'));

    it('treats a \r-only line as blank', fn () => expect(NdjsonFramer::parseLine("\r"))->toBeNull());

    // PHP's json_decode happens to tolerate the \r, so a value test cannot tell whether it was stripped. The byte count
    // of a bad line can, and it is part of the cross-language contract: the \r is not counted.
    it('does not count the stripped \r in a bad line\'s reported size', fn () => expect(NdjsonFramer::parseLine("{nope\r")->error->bytes)->toBe(5));
});

describe('decision 2: the byte cap is the same number everywhere', function () {
    it('is 64_000_000', fn () => expect(NdjsonFramer::MAX_LINE_BYTES)->toBe(64_000_000));

    it('accepts a line just under the cap', function () {
        $frame = NdjsonFramer::parseLine(Json::encode(['a' => str_repeat('x', NdjsonFramer::MAX_LINE_BYTES - 20)]));
        expect($frame->ok)->toBeTrue();
    });

    it('refuses a completed line over the cap', function () {
        $frame = NdjsonFramer::parseLine(Json::encode(['a' => str_repeat('x', NdjsonFramer::MAX_LINE_BYTES)]));
        expect($frame->ok)->toBeFalse()->and($frame->error->kind)->toBe('oversize');
    });

    it('refuses an UNTERMINATED line over the cap instead of buffering forever', function () {
        $framer = new NdjsonFramer;
        $half = str_repeat('x', intdiv(NdjsonFramer::MAX_LINE_BYTES, 2) + 100_000);
        expect($framer->push($half))->toBe([]);
        $frames = $framer->push($half);
        expect($frames)->toHaveCount(1)->and($frames[0]->ok)->toBeFalse();
    });

    it('measures the cap in BYTES: 3-byte ideographs under the cap in characters are over it in bytes', function () {
        $chars = intdiv(NdjsonFramer::MAX_LINE_BYTES, 3) + 10;
        $frame = NdjsonFramer::parseLine(Json::encode(['a' => str_repeat('漢', $chars)]));
        expect($frame->ok)->toBeFalse();
    });
});

describe('decision 3: a framing error reports size, never content', function () {
    $secret = 'sk-ant-SUPERSECRET-DO-NOT-LOG';

    it('withholds the content of an oversized line', function () use ($secret) {
        $frame = NdjsonFramer::parseLine(Json::encode(['k' => $secret.str_repeat('x', NdjsonFramer::MAX_LINE_BYTES)]));
        expect($frame->ok)->toBeFalse()
            ->and($frame->error->message)->not->toContain('sk-ant')
            ->and($frame->error->bytes)->toBeGreaterThan(NdjsonFramer::MAX_LINE_BYTES);
    });

    it('withholds the content of an unparseable line', function () use ($secret) {
        $frame = NdjsonFramer::parseLine("{\"k\":\"{$secret}\"");
        expect($frame->ok)->toBeFalse()
            ->and($frame->error->kind)->toBe('invalid-json')
            ->and($frame->error->message)->not->toContain('sk-ant');
    });

    it('still reports the byte size, so the error is diagnosable', fn () => expect(NdjsonFramer::parseLine('{nope')->error->bytes)->toBe(5));
});

describe('UTF-8 across chunk boundaries', function () {
    it('does not corrupt a character split between chunks', function () {
        $bytes = "{\"a\":\"漢字\"}\n";
        expect(values(framesOf([substr($bytes, 0, 8), substr($bytes, 8)])))->toBe(['{"a":"漢字"}']);
    });
});

describe('JSON matches the reference byte for byte', function () {
    // Each expected string is what JavaScript's JSON.stringify produces for the same value (checked with node).
    it('keeps an empty object an object and an empty list a list', function () {
        expect(Json::encode(Json::decode('{"a":{},"b":[]}')))->toBe('{"a":{},"b":[]}');
    });

    it('leaves slashes, non-ASCII and the line separators unescaped, as JSON.stringify does', function () {
        expect(Json::encode(['u' => 'https://x.test/a', 'k' => '漢', 'ls' => "\u{2028}\u{2029}"]))
            ->toBe("{\"u\":\"https://x.test/a\",\"k\":\"漢\",\"ls\":\"\u{2028}\u{2029}\"}");
    });

    it('round-trips a frame through the framer', function () {
        $line = NdjsonFramer::encodeLine(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        expect($line)->toBe("{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"initialize\"}\n")
            ->and(values(framesOf([$line])))->toBe(['{"jsonrpc":"2.0","id":1,"method":"initialize"}']);
    });
});
