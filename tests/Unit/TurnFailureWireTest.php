<?php

declare(strict_types=1);

use Prism\Acp\Agent\AcpAgent;
use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Agent\TurnOutcome;
use Prism\Acp\Async\Loop;
use Prism\Acp\JsonRpc\JsonRpcPeer;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Support\ProviderText;
use Prism\Acp\TurnFailure;
use Tests\Support\FakeDriver;

require_once dirname(__DIR__).'/Support/FakeDriver.php';

/*
 * The turn failure a client receives is rebuilt from its documented fields (G-372), as prism-acp-ts 0.48.0's
 * wireTurnFailure rebuilds it: the reason bounded and marked as provider text, the kind one of the five or `other`,
 * and the reset a date JavaScript can hold, rounded up to a whole millisecond. Each row ends a real turn through the
 * driver interface and reads the `session/prompt` error off the ACP wire.
 */

/** The `error` the client receives for a turn the driver ended with this failure. */
function failedTurnError(?TurnFailure $failure): stdClass
{
    $loop = new Loop;
    $sent = [];
    $log = [];
    $drivers = [];
    $peer = new JsonRpcPeer(send: function (mixed $message) use (&$sent): void {
        $sent[] = Json::decode(Json::encode($message));
    }, loop: $loop);
    new AcpAgent(
        peer: $peer,
        driverFactory: function (array $options, DriverEvents $events) use (&$drivers, &$log, $loop) {
            return $drivers[] = FakeDriver::make(count($drivers), [], $options, $events, $log, $loop);
        },
        newSessionId: fn (): string => 'sess_fail',
        loop: $loop,
    );
    $peer->receive(Json::decode(Json::encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => 1]])));
    $peer->receive(Json::decode(Json::encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'session/new', 'params' => ['cwd' => getcwd(), 'mcpServers' => []]])));
    $loop->drain();
    $peer->receive(Json::decode(Json::encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'session/prompt', 'params' => ['sessionId' => 'sess_fail', 'prompt' => [['type' => 'text', 'text' => 'hi']]]])));
    $loop->drain();
    ($drivers[0]->events->onTurnEnd)(new TurnOutcome(null, true, 'error', $failure));
    $loop->drain();
    foreach ($sent as $message) {
        if (($message->id ?? null) === 3) {
            return $message->error;
        }
    }
    throw new RuntimeException('session/prompt was not answered');
}

function wireFailure(TurnFailure $failure): ?stdClass
{
    $error = failedTurnError($failure);

    return isset($error->data) ? $error->data->{'particle.academy/turn_failure'} : null;
}

it('sends a documented kind as it is, and every other kind as other', function (string $kind, string $wire): void {
    expect(wireFailure(new TurnFailure('rate_limit', $kind))->kind)->toBe($wire);
})->with([
    ['rate_limit', 'rate_limit'], ['overloaded', 'overloaded'], ['auth', 'auth'], ['billing', 'billing'], ['other', 'other'],
    // Padded, cased, look-alike (Cyrillic a), zero-width, bidi and invented kinds are not the documented one.
    ['rate_limit ', 'other'], [' auth', 'other'], ['Rate_Limit', 'other'], ['r'.mb_chr(0x0430, 'UTF-8').'te_limit', 'other'],
    ['rate_limit'.mb_chr(0x200B, 'UTF-8'), 'other'], [mb_chr(0x202E, 'UTF-8').'billing', 'other'], ['owned-unknown-kind', 'other'], ['', 'other'],
]);

it('sends a reset only as a date JavaScript can hold, rounded up to a whole millisecond', function (int|float|null $reset, ?int $wire): void {
    $failure = wireFailure(new TurnFailure('rate_limit', 'rate_limit', $reset));
    expect($failure->resetsAtMs ?? null)->toBe($wire);
    if ($wire !== null) {
        // What a client does with it, and what used to throw past the last date.
        expect((new DateTimeImmutable('@'.intdiv($wire, 1000)))->format('Y'))->toBeString();
    }
})->with([
    [1_800_000_000_000, 1_800_000_000_000],
    [1_800_000_000_000.4, 1_800_000_000_001],
    [0, 0],
    [8_640_000_000_000_000, 8_640_000_000_000_000],
    [8_640_000_000_000_001, null],
    [8.64e15 + 2048, null],
    [1e300, null],
    [-1, null],
    [-0.5, null],
    [INF, null],
    [-INF, null],
    [NAN, null],
    [null, null],
]);

it('sends the documented fields only, as plain JSON', function (): void {
    $failure = wireFailure(new TurnFailure('rate_limit', 'rate_limit', 1_800_000_000_000));
    expect(Json::encode($failure))->toBe('{"reason":"rate_limit","kind":"rate_limit","resetsAtMs":1800000000000}');
});

it('marks hidden and control characters in the reason and bounds it', function (): void {
    expect(wireFailure(new TurnFailure("limit\e[31m".mb_chr(0x202E, 'UTF-8').'x', 'other'))->reason)->toBe('limit<U+001B>[31m<U+202E>x')
        ->and(wireFailure(new TurnFailure("bad \xC3 utf-8", 'other'))->reason)->toBe('bad '.mb_chr(0xFFFD, 'UTF-8').' utf-8')
        ->and(wireFailure(new TurnFailure("tab\tand\nline", 'other'))->reason)->toBe("tab\tand\nline");
    $long = wireFailure(new TurnFailure(str_repeat('r', 10_000), 'other'))->reason;
    expect($long)->toBe(str_repeat('r', ProviderText::MAX + mb_strlen(ProviderText::TRUNCATED, 'UTF-8')).ProviderText::TRUNCATED);
});

it('sends no failure data for a failure that names no reason', function (): void {
    expect(property_exists(failedTurnError(new TurnFailure('', 'rate_limit', 1_800_000_000_000)), 'data'))->toBeFalse()
        ->and(property_exists(failedTurnError(null), 'data'))->toBeFalse();
});

it('serializes as the wire form wherever it is encoded', function (): void {
    expect(Json::encode(new TurnFailure('rate_limit ', 'rate_limit ', INF)))->toBe('{"reason":"rate_limit ","kind":"other"}');
});
