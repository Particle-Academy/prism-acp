<?php

declare(strict_types=1);

use Prism\Acp\Agent\AcpAgent;
use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Async\Loop;
use Prism\Acp\JsonRpc\JsonRpcPeer;
use Prism\Acp\Ndjson\Json;
use Tests\Support\AcpSchema;
use Tests\Support\FakeDriver;

require_once dirname(__DIR__).'/Support/FakeDriver.php';

/*
 * The plan gate's caps and character refusals (G-376, G-377), row for row with prism-acp-ts 0.48.0's acp-agent test.
 * Every row enters where a driver does, DriverEvents::onUpdate, and is read back off the ACP wire.
 */

const BOUNDS_ENTRY = ['content' => 'step', 'priority' => 'medium', 'status' => 'pending'];
const BOUNDS_HIDDEN_ID = 'plan update was not sent: its planId holds a control, format or invisible character';
const BOUNDS_HIDDEN_MARKDOWN = 'plan update was not sent: its markdown path holds a control, format or invisible character, or its content a control or bidi character';
const BOUNDS_TOO_MANY = 'plan update was not sent: it has more than 256 entries';
const BOUNDS_MALFORMED_ENTRIES = 'plan update was not sent: an entry was not an object with a string content, an ACP status and an object _meta';
const BOUNDS_MALFORMED_SNAPSHOT = 'update was not sent: it does not serialize as an object of the kind it declares';
const BOUNDS_NO_KIND = 'update without a string sessionUpdate of its own was not sent';

/** @return array{driver: FakeDriver, sent: list<mixed>, problems: list<string>, loop: Loop} */
function boundsSession(mixed $clientCapabilities, ?Closure $reporter = null): array
{
    $loop = new Loop;
    $sent = [];
    $log = [];
    $problems = [];
    $drivers = [];
    $peer = new JsonRpcPeer(send: function (mixed $message) use (&$sent): void {
        $sent[] = Json::decode(Json::encode($message));
    }, loop: $loop);
    new AcpAgent(
        peer: $peer,
        driverFactory: function (array $options, DriverEvents $events) use (&$drivers, &$log, $loop) {
            return $drivers[] = FakeDriver::make(count($drivers), [], $options, $events, $log, $loop);
        },
        newSessionId: fn (): string => 'sess_plan',
        onProtocolError: $reporter ?? function (string $sessionId, string $problem) use (&$problems): void {
            $problems[] = $problem;
        },
        loop: $loop,
    );
    $params = ['protocolVersion' => 1];
    if ($clientCapabilities !== null) {
        $params['clientCapabilities'] = $clientCapabilities;
    }
    $peer->receive(Json::decode(Json::encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => $params])));
    $peer->receive(Json::decode(Json::encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'session/new', 'params' => ['cwd' => getcwd(), 'mcpServers' => []]])));
    $loop->drain();

    return ['driver' => $drivers[0], 'sent' => &$sent, 'problems' => &$problems, 'loop' => $loop];
}

/** Emit as the driver does. Nothing the gate refuses may throw back into the driver's event path. */
function boundsEmit(array $session, mixed $update): void
{
    ($session['driver']->events->onUpdate)($update);
}

/** @return list<stdClass> the session/update params sent */
function boundsParams(array $session): array
{
    $session['loop']->drain();

    return array_values(array_map(
        fn (stdClass $m) => $m->params,
        array_filter($session['sent'], fn (mixed $m) => ($m->method ?? null) === 'session/update'),
    ));
}

/** @return list<mixed> each update sent */
function boundsSent(array $session): array
{
    return array_map(fn (stdClass $params) => $params->update, boundsParams($session));
}

function u(string $hex): string
{
    return mb_chr((int) hexdec($hex), 'UTF-8');
}

/** @return array<string, mixed> */
function boundsMarkdown(array $plan): array
{
    return ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'markdown', 'planId' => 'pl_md', 'content' => '# plan', ...$plan]];
}

function boundsAccepts(mixed $capabilities): bool
{
    return $capabilities instanceof stdClass && isset($capabilities->plan);
}

/** A JsonSerializable whose answer is decided by a callback, counting its calls. */
function boundsSerializing(Closure $answer): JsonSerializable
{
    return new class($answer) implements JsonSerializable
    {
        public int $calls = 0;

        public function __construct(private readonly Closure $answer) {}

        public function jsonSerialize(): mixed
        {
            return ($this->answer)(++$this->calls);
        }
    };
}

dataset('plan clients', [
    'advertised plan' => [(object) ['plan' => new stdClass]],
    'did not' => [new stdClass],
]);

it('refuses, and reports, a planId holding a control, format or invisible character', function (): void {
    $session = boundsSession((object) ['plan' => new stdClass]);
    $ids = [
        'pl'.u('202E').'1', "pl\e[31m", 'pl'.u('200B'), "pl\0", "pl\xED\xA0\x80",
        // Padded and disguised: trailing zero-width, a leading BOM, an isolate pair, a tag character, a soft hyphen,
        // a Hangul filler, a line separator, a bare CR, DEL, a C1 control, a variation selector.
        'pl_1'.u('200B'), u('FEFF').'pl_1', 'pl'.u('2066').'1'.u('2069'), 'pl_1'.u('E0041'), 'pl'.u('00AD').'1',
        'pl'.u('3164'), 'pl'.u('2028').'1', "pl\r1", "pl\x7F", 'pl'.u('0085'), 'pl'.u('FE0F'),
        // Not valid UTF-8: refused, never sent. A PHP string cannot hold the lone surrogate the reference refuses.
        "pl\xFF",
    ];
    foreach ($ids as $planId) {
        boundsEmit($session, ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => $planId, 'entries' => [BOUNDS_ENTRY]]]);
        boundsEmit($session, ['sessionUpdate' => 'plan_removed', 'planId' => $planId]);
    }
    expect(boundsSent($session))->toBe([])
        ->and($session['problems'])->toBe(array_fill(0, count($ids) * 2, BOUNDS_HIDDEN_ID));

    // The controls. An ordinary id, non-ASCII letters, an id padded with spaces and one with a look-alike letter are
    // sent unchanged, as the reference sends them: a planId is an opaque key the client matches byte for byte.
    $controls = ['plan_é.1:a-b', ' pl_1 ', 'pl_'.u('0440').'1'];
    foreach ($controls as $planId) {
        boundsEmit($session, ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => $planId, 'entries' => [BOUNDS_ENTRY]]]);
    }
    expect(array_map(fn ($update) => $update->plan->planId, boundsSent($session)))->toBe($controls);
});

it('refuses, and reports, a markdown path with a hidden character or markdown content with a control or bidi character', function (): void {
    $session = boundsSession((object) ['plan' => new stdClass]);
    $bad = [
        ['path' => u('202E').'../../etc/passwd'], ['path' => 'a'.u('200B').'b'], ['path' => "a\0"], ['path' => "docs/a\r\n.md"],
        ['path' => "docs/\xED\xA0\x80.md"],
        ['content' => "safe\rEVIL"], ['content' => "ends\r"], ['content' => 'a'.u('206A').'b'], ['content' => 'a'.u('FFF9').'b'],
        ['content' => "x\e[2J"], ['content' => u('202E').'evil'], ['content' => 'a'.u('2066').'b'.u('2069')], ['content' => "a\x07"],
        ['content' => "a\xED\xA0\x80"], ['content' => 'a'.u('2028').'b'], ['content' => 'a'.u('200F').'b'], ['content' => 'a'.u('061C')],
        ['content' => u('FEFF').'# plan'], ['content' => 'a'.u('009B').'31m'], ['content' => "a\x0B"], ['content' => "a\xC3"],
    ];
    foreach ($bad as $plan) {
        boundsEmit($session, boundsMarkdown($plan));
    }
    expect(boundsSent($session))->toBe([])
        ->and($session['problems'])->toBe(array_fill(0, count($bad), BOUNDS_HIDDEN_MARKDOWN));

    // The control: line breaks, tabs, a joined emoji and a non-ASCII path are ordinary markdown.
    $content = "# Plan\r\n\t- step ".u('1F469').u('200D').u('1F4BB')."\n";
    boundsEmit($session, boundsMarkdown(['content' => $content, 'path' => 'docs/plän.md']));
    $sent = boundsSent($session);
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->plan->content)->toBe($content)
        ->and($sent[0]->plan->path)->toBe('docs/plän.md')
        ->and(AcpSchema::sessionNotification(boundsParams($session)[0]))->toBe([]);
});

it('refuses, and reports, markdown content, a path or a planId over the documented caps', function (): void {
    $session = boundsSession((object) ['plan' => new stdClass]);
    // At every cap, counted in code points: an astral character is one.
    boundsEmit($session, boundsMarkdown(['content' => str_repeat(u('1F600'), 65536), 'path' => str_repeat('p', 4096), 'planId' => str_repeat('i', 256)]));
    boundsEmit($session, ['sessionUpdate' => 'plan_removed', 'planId' => str_repeat('é', 256)]);
    expect(boundsSent($session))->toHaveCount(2)->and($session['problems'])->toBe([]);

    $bad = [
        boundsMarkdown(['content' => str_repeat('x', 65537)]),
        boundsMarkdown(['path' => str_repeat('p', 4097)]),
        boundsMarkdown(['planId' => str_repeat('i', 257)]),
        ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => str_repeat('i', 257), 'entries' => [BOUNDS_ENTRY]]],
        ['sessionUpdate' => 'plan_removed', 'planId' => str_repeat('é', 257)],
    ];
    foreach ($bad as $update) {
        boundsEmit($session, $update);
    }
    expect(boundsSent($session))->toHaveCount(2)->and($session['problems'])->toHaveCount(count($bad));
    foreach ($session['problems'] as $problem) {
        expect($problem)->toContain('larger than');
    }
});

it('refuses, and reports, more entries than the cap, before serializing any', function (mixed $capabilities): void {
    $session = boundsSession($capabilities);
    $at = array_map(fn (int $i): array => ['content' => "step {$i}", 'priority' => 'medium', 'status' => 'pending'], range(0, 255));
    boundsEmit($session, ['sessionUpdate' => 'plan', 'entries' => $at]);
    boundsEmit($session, ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => 'pl_1', 'entries' => $at]]);
    expect(boundsSent($session))->toHaveCount(2)->and($session['problems'])->toBe([]);

    // One more, where the extra entry throws if it is ever serialized: the count refuses first.
    $trap = boundsSerializing(fn (): never => throw new RuntimeException('read'));
    $over = [...$at, $trap];
    boundsEmit($session, ['sessionUpdate' => 'plan', 'entries' => $over]);
    boundsEmit($session, ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => 'pl_1', 'entries' => $over]]);
    expect(boundsSent($session))->toHaveCount(2)
        ->and($trap->calls)->toBe(0)
        ->and($session['problems'])->toBe([BOUNDS_TOO_MANY, BOUNDS_TOO_MANY]);
})->with('plan clients');

it('counts only a real list: an iterable, ArrayAccess or Countable object cannot pass more than the cap', function (): void {
    $session = boundsSession((object) ['plan' => new stdClass]);
    $many = array_map(fn (int $i): array => ['content' => "step {$i}", 'priority' => 'medium', 'status' => 'pending'], range(0, 4999));
    $lying = new class($many) implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
    {
        /** @param list<mixed> $items */
        public function __construct(private readonly array $items) {}

        public function count(): int
        {
            return 1;
        }

        public function getIterator(): Iterator
        {
            return new ArrayIterator($this->items);
        }

        public function offsetExists(mixed $offset): bool
        {
            return isset($this->items[$offset]);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->items[$offset];
        }

        public function offsetSet(mixed $offset, mixed $value): void {}

        public function offsetUnset(mixed $offset): void {}

        public function jsonSerialize(): mixed
        {
            return $this->items;
        }
    };
    $lists = [$lying, new ArrayObject($many), new ArrayIterator($many), [0 => BOUNDS_ENTRY, 2 => BOUNDS_ENTRY], ['a' => BOUNDS_ENTRY]];
    foreach ($lists as $entries) {
        boundsEmit($session, ['sessionUpdate' => 'plan', 'entries' => $entries]);
        boundsEmit($session, ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => 'pl_1', 'entries' => $entries]]);
    }
    expect(boundsSent($session))->toBe([])->and($session['problems'])->toHaveCount(count($lists) * 2);
    foreach ($session['problems'] as $problem) {
        expect($problem)->not->toContain('more than');
    }
});

it('refuses, and reports, an entry content or a _meta over the documented caps', function (mixed $capabilities): void {
    $session = boundsSession($capabilities);
    // JSON {"k":"..."} is eight bytes around the value; é is two bytes in UTF-8 and one code point.
    $meta = fn (int $bytes): array => ['k' => str_repeat('x', $bytes - 8)];
    $wideMeta = ['k' => str_repeat('é', 8189)];
    $full = ['content' => str_repeat('é', 1024), 'priority' => 'medium', 'status' => 'pending', '_meta' => $meta(16384)];
    boundsEmit($session, ['sessionUpdate' => 'plan', 'entries' => [$full], '_meta' => $meta(16384)]);
    expect(boundsSent($session))->toHaveCount(1)->and($session['problems'])->toBe([]);

    $over = [...$full, 'content' => $full['content'].'x'];
    $bad = [
        ['sessionUpdate' => 'plan', 'entries' => [$over]],
        ['sessionUpdate' => 'plan', 'entries' => [[...$full, '_meta' => $meta(16385)]]],
        ['sessionUpdate' => 'plan', 'entries' => [[...$full, '_meta' => $wideMeta]]],
        ['sessionUpdate' => 'plan', 'entries' => [$full], '_meta' => $meta(16385)],
        ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => 'pl_1', 'entries' => [$over]]],
        ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => 'pl_1', 'entries' => [$full], '_meta' => $meta(16385)]],
        ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => 'pl_1', 'entries' => [$full]], '_meta' => $wideMeta],
        ['sessionUpdate' => 'plan_removed', 'planId' => 'pl_1', '_meta' => $meta(16385)],
    ];
    foreach ($bad as $update) {
        boundsEmit($session, $update);
    }
    expect(boundsSent($session))->toHaveCount(1)->and($session['problems'])->toHaveCount(count($bad));
    // A client without plan support is refused plan_removed before its _meta is read; every other refusal names the cap.
    $named = boundsAccepts($capabilities) ? $session['problems'] : array_slice($session['problems'], 0, -1);
    foreach ($named as $problem) {
        expect($problem)->toContain('larger than');
    }
})->with('plan clients');

it('sends the checked fields, not a copy of what the driver passed', function (mixed $capabilities): void {
    $session = boundsSession($capabilities);
    boundsEmit($session, ['sessionUpdate' => 'plan', 'entries' => [[...BOUNDS_ENTRY, 'extra' => 'x', '_meta' => ['k' => 1]]], '_meta' => ['m' => 1], 'stray' => true]);
    boundsEmit($session, ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => 'pl_1', 'entries' => [[...BOUNDS_ENTRY, '_meta' => null]], 'stray' => 1], '_meta' => null]);
    boundsEmit($session, ['sessionUpdate' => 'plan_removed', 'planId' => 'pl_1', '_meta' => ['k' => 1], 'extra' => 'x']);
    $sent = boundsSent($session);
    expect(Json::encode($sent[0]))->toBe(Json::encode(['sessionUpdate' => 'plan', 'entries' => [[...BOUNDS_ENTRY, '_meta' => ['k' => 1]]], '_meta' => ['m' => 1]]))
        ->and(Json::encode($sent[1]))->toBe(Json::encode(boundsAccepts($capabilities)
            ? ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => 'pl_1', 'entries' => [BOUNDS_ENTRY]]]
            : ['sessionUpdate' => 'plan', 'entries' => [BOUNDS_ENTRY]]));
    if (boundsAccepts($capabilities)) {
        expect(Json::encode($sent[2]))->toBe(Json::encode(['sessionUpdate' => 'plan_removed', 'planId' => 'pl_1', '_meta' => ['k' => 1]]));
    }
    foreach (boundsParams($session) as $params) {
        expect(AcpSchema::sessionNotification($params))->toBe([]);
    }
})->with('plan clients');

it('reads an entry only as an array or a plain object: a JsonSerializable, ArrayAccess or magic-getter entry is refused', function (): void {
    $session = boundsSession((object) ['plan' => new stdClass]);
    $magic = new class
    {
        public function __get(string $name): mixed
        {
            return BOUNDS_ENTRY[$name] ?? null;
        }

        public function __isset(string $name): bool
        {
            return isset(BOUNDS_ENTRY[$name]);
        }
    };
    $bad = [
        boundsSerializing(fn (): array => BOUNDS_ENTRY), $magic, new ArrayObject(BOUNDS_ENTRY),
        [...BOUNDS_ENTRY, 'content' => "bad \xC3 utf-8"], [...BOUNDS_ENTRY, 'status' => 'done'], [...BOUNDS_ENTRY, 'content' => 5], 'step',
    ];
    foreach ($bad as $entry) {
        boundsEmit($session, ['sessionUpdate' => 'plan', 'entries' => [$entry]]);
    }
    expect(boundsSent($session))->toBe([])
        ->and($session['problems'])->toBe(array_fill(0, count($bad), BOUNDS_MALFORMED_ENTRIES));
});

it('sends _meta as it serialized once: a JsonSerializable that answers differently each time cannot change what is sent', function (): void {
    $session = boundsSession((object) ['plan' => new stdClass]);
    // Small when checked; anything at all on any later read.
    $shifting = boundsSerializing(fn (int $call): array => $call === 1 ? ['k' => 1] : ['k' => str_repeat('x', 100000), 'sessionUpdate' => 'plan_removed']);
    boundsEmit($session, ['sessionUpdate' => 'plan', 'entries' => [BOUNDS_ENTRY], '_meta' => ['inner' => $shifting]]);
    $sent = boundsSent($session);
    expect($shifting->calls)->toBe(1)
        ->and(Json::encode($sent))->toBe(Json::encode([['sessionUpdate' => 'plan', 'entries' => [BOUNDS_ENTRY], '_meta' => ['inner' => ['k' => 1]]]]));
});

it('sends no plan whose _meta would not serialize as an object, at any level, and throws nothing into the driver', function (mixed $capabilities): void {
    $session = boundsSession($capabilities);
    $metas = [
        5, 'x', ['a', 'b'], boundsSerializing(fn (): array => ['k' => 1]),
        ['k' => boundsSerializing(fn (): never => throw new RuntimeException('no'))],
        ['k' => "bad \xC3 utf-8"], (object) ['nested' => ['deeper' => "\xFF"]],
    ];
    foreach ($metas as $meta) {
        foreach ([
            ['sessionUpdate' => 'plan', 'entries' => [[...BOUNDS_ENTRY, '_meta' => $meta]]],
            ['sessionUpdate' => 'plan', 'entries' => [BOUNDS_ENTRY], '_meta' => $meta],
            ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => 'pl_1', 'entries' => [[...BOUNDS_ENTRY, '_meta' => $meta]]]],
            ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => 'pl_1', 'entries' => [BOUNDS_ENTRY], '_meta' => $meta]],
            ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => 'pl_1', 'entries' => [BOUNDS_ENTRY]], '_meta' => $meta],
        ] as $update) {
            boundsEmit($session, $update);
        }
    }
    expect(boundsSent($session))->toBe([])->and($session['problems'])->toHaveCount(count($metas) * 5);
})->with('plan clients');

it('sends every other update as it serialized once, and refuses one that does not serialize or has no kind of its own', function (): void {
    $session = boundsSession((object) ['plan' => new stdClass]);
    $chunk = fn (mixed $content): array => ['sessionUpdate' => 'agent_message_chunk', 'content' => $content];
    $shifting = boundsSerializing(fn (int $call): array => ['type' => 'text', 'text' => $call === 1 ? 'checked' : 'swapped']);
    boundsEmit($session, $chunk($shifting));
    boundsEmit($session, $chunk(['type' => 'text', 'text' => "bad \xC3 utf-8"]));
    boundsEmit($session, $chunk(boundsSerializing(fn (): never => throw new RuntimeException('no'))));
    boundsEmit($session, ['content' => ['type' => 'text', 'text' => 'no kind']]);
    boundsEmit($session, ['sessionUpdate' => 7]);
    $sent = boundsSent($session);
    expect($shifting->calls)->toBe(1)
        ->and(Json::encode($sent))->toBe(Json::encode([$chunk(['type' => 'text', 'text' => 'checked'])]))
        ->and($session['problems'])->toBe([BOUNDS_MALFORMED_SNAPSHOT, BOUNDS_MALFORMED_SNAPSHOT, BOUNDS_NO_KIND, BOUNDS_NO_KIND]);
});

it('a host reporter that throws neither reaches the driver nor stops a corrected plan', function (): void {
    $session = boundsSession(null, function (): never {
        throw new RuntimeException('reporter');
    });
    boundsEmit($session, ['sessionUpdate' => 'plan']);
    boundsEmit($session, ['sessionUpdate' => 'plan_removed', 'planId' => 'pl'.u('202E')]);
    boundsEmit($session, ['sessionUpdate' => 'plan', 'entries' => [['content' => 'x', 'status' => 'pending']]]);
    expect(Json::encode(array_map(fn ($update) => $update->entries, boundsSent($session))))
        ->toBe(Json::encode([[['content' => 'x', 'priority' => 'medium', 'status' => 'pending']]]));
});
