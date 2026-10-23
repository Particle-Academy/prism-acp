<?php

declare(strict_types=1);

use Prism\Acp\Claude\ClaudeRateLimit;
use Prism\Acp\Claude\ClaudeTurnFailures;
use Prism\Acp\Codex\CodexRateLimit;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\SessionId;
use Prism\Acp\Text;
use Prism\Acp\TurnFailure;

/*
 * EVERY PURE READER GIVES THE REFERENCE'S ANSWER. tests/fixtures/units.json holds prism-acp-ts's output for a
 * systematic hostile input set: each field of a valid payload replaced by each hostile value, and deleted. Regenerate
 * with tools/golden/units.mjs. Inputs are JSON text, decoded here as a provider's line would be.
 */

$units = Json::decode((string) file_get_contents(dirname(__DIR__).'/fixtures/units.json'));

/** The reference's `{ok, limit}` / `{ok, reason}` shape, from this port's value-or-reason. */
function readResult(object|string $read): string
{
    return Json::encode(is_string($read) ? ['ok' => false, 'reason' => $read] : ['ok' => true, 'limit' => $read]);
}

it('reads a Claude rate limit as the reference does, and words the notice the same', function (string $input, string $expected, ?string $notice) {
    $read = ClaudeRateLimit::read(Json::decode($input));
    expect(readResult($read))->toBe($expected)
        ->and($read instanceof ClaudeRateLimit ? $read->notice() : null)->toBe($notice);
})->with($units->claudeRateLimit);

it('reads a Codex rate limit as the reference does, and words the notice the same', function (string $input, string $expected, ?string $notice) {
    $read = CodexRateLimit::read(Json::decode($input));
    expect(readResult($read))->toBe($expected)
        ->and($read instanceof CodexRateLimit ? $read->notice() : null)->toBe($notice);
})->with($units->codexRateLimit);

it('reads a Codex turn failure as the reference does', function (string $input, string $expected) {
    expect(Json::encode(TurnFailure::fromCodex(Json::decode($input))))->toBe($expected);
})->with($units->codexFailure);

it('takes a Claude turn\'s failure as the reference does, and starts the next turn clean', function (array $frames, string $first, string $second) {
    $tracker = new ClaudeTurnFailures;
    foreach ($frames as $frame) {
        $tracker->observe(Json::decode($frame));
    }
    expect(Json::encode($tracker->take()))->toBe($first)
        ->and(Json::encode($tracker->take()))->toBe($second);
})->with($units->claudeTurn);

it('strips terminal control sequences as the reference does', function (string $input, string $expected) {
    expect(Json::encode(Text::stripAnsiControlSequences(Json::decode($input))))->toBe($expected);
})->with($units->text);

it('accepts a CLI session id exactly when the reference does', function (string $input, bool $expected) {
    expect(SessionId::isValidCliSessionId(Json::decode($input)))->toBe($expected);
})->with($units->sessionId);
