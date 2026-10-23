<?php

declare(strict_types=1);

use Prism\Acp\Claude\ClaudeToAcp;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Ndjson\NdjsonFramer;

/*
 * THE CLAUDE MAPPING PRODUCES THE REFERENCE'S BYTES. Every stream in tests/fixtures/claude-to-acp/corpus went through
 * prism-acp-ts's framer and ClaudeToAcp (the version is in REFERENCE), and expected/ holds what it produced, one line
 * per update, then the frames it left unmapped. Regenerate with tools/golden/claude-to-acp.mjs.
 *
 * The corpus is every real Claude capture the reference keeps, hostile mutations of their frames, and hand-written edge
 * frames. The comparison is of bytes, not of decoded values: key order, number spelling and `{}` against `[]` are all
 * part of what a client receives.
 */

$dir = dirname(__DIR__).'/fixtures/claude-to-acp';

/** @return list<string> */
function mapStream(string $bytes): array
{
    $framer = new NdjsonFramer;
    $mapper = new ClaudeToAcp;
    $lines = [];
    foreach ([...$framer->push($bytes), ...$framer->end()] as $frame) {
        if (! $frame->ok) {
            $lines[] = "ERR {$frame->error->kind} {$frame->error->bytes}";

            continue;
        }
        foreach ($mapper->frame($frame->value) as $update) {
            $lines[] = Json::encode($update);
        }
    }
    $lines[] = 'UNMAPPED '.Json::encode($mapper->unmapped);

    return $lines;
}

$streams = array_map(fn (string $path) => basename($path, '.ndjson'), glob($dir.'/corpus/*.ndjson') ?: []);

it('has a corpus to compare: the real captures, their mutations and the edges', function () use ($streams) {
    expect(count($streams))->toBeGreaterThan(20)
        ->and($streams)->toContain('claude-tool-turn', 'claude-subagent-turn', 'claude-plan-turn', 'edges');
});

it('maps every stream exactly as the reference does', function (string $stream) use ($dir) {
    $expected = explode("\n", rtrim((string) file_get_contents("{$dir}/expected/{$stream}.txt"), "\n"));
    expect($expected)->not->toContain('THROW');
    $actual = mapStream((string) file_get_contents("{$dir}/corpus/{$stream}.ndjson"));

    foreach ($expected as $i => $line) {
        expect($actual[$i] ?? '(no line)')->toBe($line, "{$stream}: line ".($i + 1).' differs from the reference');
    }
    expect($actual)->toHaveCount(count($expected));
})->with($streams);
