<?php

declare(strict_types=1);

use Prism\Acp\Ndjson\Frame;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Ndjson\NdjsonFramer;

/** @param list<Frame> $frames
 * @return list<string>
 */
function chunkingValues(array $frames): array
{
    return array_map(fn (Frame $frame): string => $frame->ok
        ? Json::encode($frame->value)
        : $frame->error->kind.':'.$frame->error->bytes, $frames);
}

it('preserves frame order, UTF-8, CRLF, blanks and an invalid EOF across arbitrary chunk boundaries', function (int $size) {
    $wire = "\n{\"x\":\"漢😀\"}\r\n[]\nfalse\n{\"bad\":";
    $framer = new NdjsonFramer;
    $frames = [];
    foreach (str_split($wire, $size) as $chunk) {
        array_push($frames, ...$framer->push($chunk));
        expect($framer->push(''))->toBe([]);
    }
    array_push($frames, ...$framer->end());
    expect(chunkingValues($frames))->toBe(['{"x":"漢😀"}', '[]', 'false', 'invalid-json:7'])
        ->and($framer->end())->toBe([]);
})->with([1, 2, 3, 7, 31, 1024]);

it('joins an old prefix to the first new line and retains only the final partial line', function () {
    $framer = new NdjsonFramer;
    expect($framer->push('{"a":'))->toBe([]);
    expect(chunkingValues($framer->push("1}\n{\"b\":2}\n{\"c\":")))->toBe(['{"a":1}', '{"b":2}']);
    expect($framer->push('3}'))->toBe([])
        ->and(chunkingValues($framer->end()))->toBe(['{"c":3}'])
        ->and($framer->end())->toBe([]);
});

it('handles a dense chunk of completed lines and leaves an unterminated tail for EOF', function () {
    $framer = new NdjsonFramer;
    $frames = $framer->push(str_repeat("0\n \r\n{}\n", 10_000).'{"tail":true}');
    expect(chunkingValues($frames))->toBe(array_merge(...array_fill(0, 10_000, ['0', '{}'])))
        ->and(chunkingValues($framer->end()))->toBe(['{"tail":true}']);
});

it('scrubs malformed UTF-8 only after its line has been joined and withholds invalid content', function () {
    $framer = new NdjsonFramer;
    expect($framer->push('{"v":"A'."\xe2"))->toBe([]);
    $frames = $framer->push("\x82".'B"}'."\nowned-marker\xff\r\n");
    expect(chunkingValues($frames))->toBe(["{\"v\":\"A\u{FFFD}B\"}", 'invalid-json:15'])
        ->and($frames[1]->error->message)->not->toContain('owned-marker');
});

it('clears an oversized pending line once and recovers at a subsequent newline', function () {
    $framer = new NdjsonFramer;
    $marker = 'owned-marker';
    $frames = $framer->push($marker.str_repeat('x', NdjsonFramer::MAX_LINE_BYTES - strlen($marker) + 1));
    expect($frames)->toHaveCount(1)
        ->and($frames[0]->ok)->toBeFalse()
        ->and($frames[0]->error->kind)->toBe('oversize')
        ->and($frames[0]->error->bytes)->toBe(NdjsonFramer::MAX_LINE_BYTES + 1)
        ->and($frames[0]->error->message)->not->toContain($marker)
        ->and(chunkingValues($framer->push("\n{\"after\":true}\n")))->toBe(['{"after":true}'])
        ->and($framer->end())->toBe([]);
});

it('reports an oversized completed line without losing a later frame in the same chunk', function () {
    $framer = new NdjsonFramer;
    $line = '{"v":"'.str_repeat('x', NdjsonFramer::MAX_LINE_BYTES).'"}';
    $frames = $framer->push($line."\n{}\n");
    expect(chunkingValues($frames))->toBe(['oversize:'.strlen($line), '{}'])
        ->and($framer->end())->toBe([]);
});

it('accepts an exactly capped pending line when the next chunk completes CRLF and starts another frame', function () {
    $framer = new NdjsonFramer;
    $line = '"'.str_repeat('x', NdjsonFramer::MAX_LINE_BYTES - 2).'"';
    expect(strlen($line))->toBe(NdjsonFramer::MAX_LINE_BYTES)
        ->and($framer->push($line))->toBe([]);
    $frames = $framer->push("\r\n{}");
    expect($frames)->toHaveCount(1)
        ->and($frames[0]->ok)->toBeTrue()
        ->and(strlen($frames[0]->value))->toBe(NdjsonFramer::MAX_LINE_BYTES - 2)
        ->and(chunkingValues($framer->end()))->toBe(['{}']);
});
