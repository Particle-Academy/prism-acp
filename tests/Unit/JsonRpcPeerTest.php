<?php

declare(strict_types=1);

use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\JsonRpc\JsonRpcPeer;
use Prism\Acp\JsonRpc\RpcError;
use Prism\Acp\Ndjson\Json;

/*
 * The peer's four pinned decisions, each one because the alternative hangs. Ported from prism-acp-ts
 * test/jsonrpc.test.ts; the wire shapes are compared as JSON text, as the far side reads them.
 */

/** @return array{0: JsonRpcPeer, 1: list<string>, 2: list<string>, 3: Loop} */
function sinkPeer(): array
{
    $loop = new Loop;
    $sent = new ArrayObject;
    $problems = new ArrayObject;
    $peer = new JsonRpcPeer(
        send: fn (mixed $m) => $sent->append(Json::encode($m)),
        onProtocolError: fn (string $p) => $problems->append($p),
        loop: $loop,
    );

    return [$peer, $sent, $problems, $loop];
}

it('sends a well-formed request, in the reference\'s key order, and resolves on its response', function () {
    [$peer, $sent] = sinkPeer();
    $answer = $peer->request('session/prompt', ['text' => 'hi']);
    expect($sent[0])->toBe('{"jsonrpc":"2.0","method":"session/prompt","id":1,"params":{"text":"hi"}}');
    $peer->receive(Json::decode('{"jsonrpc":"2.0","id":1,"result":{"stopReason":"end_turn"}}'));
    expect(Json::encode($answer->await()))->toBe('{"stopReason":"end_turn"}');
});

it('omits params entirely when there are none: "params": null is not the same as no params', function () {
    [$peer, $sent] = sinkPeer();
    $peer->request('initialize');
    $peer->notify('session/cancel');
    expect($sent[0])->toBe('{"jsonrpc":"2.0","method":"initialize","id":1}')
        ->and($sent[1])->toBe('{"jsonrpc":"2.0","method":"session/cancel"}');
});

it('correlates strictly by id, with responses out of order', function () {
    [$peer] = sinkPeer();
    $first = $peer->request('a');
    $second = $peer->request('b');
    $peer->receive(Json::decode('{"jsonrpc":"2.0","id":2,"result":"second"}'));
    $peer->receive(Json::decode('{"jsonrpc":"2.0","id":1,"result":"first"}'));
    expect($first->await())->toBe('first')->and($second->await())->toBe('second');
});

it('rejects with the code and data the far side sent', function () {
    [$peer] = sinkPeer();
    $answer = $peer->request('fs/read_text_file');
    $peer->receive(Json::decode('{"jsonrpc":"2.0","id":1,"error":{"code":-32602,"message":"bad path","data":{"path":"/x"}}}'));
    try {
        $answer->await();
        $this->fail('it resolved');
    } catch (RpcError $error) {
        expect($error->rpcCode)->toBe(-32602)->and($error->getMessage())->toBe('bad path')
            ->and(Json::encode($error->data))->toBe('{"path":"/x"}');
    }
});

it('answers a request with no handler with an error, never silence', function () {
    [$peer, $sent] = sinkPeer();
    $peer->receive(Json::decode('{"jsonrpc":"2.0","id":9,"method":"fs/unknown"}'));
    expect($sent[0])->toBe('{"jsonrpc":"2.0","id":9,"error":{"code":-32601,"message":"method not found: fs/unknown"}}');
});

it('answers a request whose handler throws, with its RpcError code and data, or as an internal error', function () {
    [$peer, $sent, , $loop] = sinkPeer();
    $peer->handle('a', fn () => throw new RpcError(-32602, 'bad', ['k' => 1]));
    $peer->handle('b', fn () => throw new RuntimeException('boom'));
    $peer->handle('c', fn () => null);
    $later = new Deferred($loop);
    $peer->handle('d', fn () => $later->await());
    foreach (['a', 'b', 'c', 'd'] as $n => $method) {
        $peer->receive(Json::decode('{"jsonrpc":"2.0","id":'.($n + 1).',"method":"'.$method.'"}'));
    }
    $later->resolve(['late' => true]);
    $loop->drain();
    expect([...$sent])->toBe([
        '{"jsonrpc":"2.0","id":1,"error":{"code":-32602,"message":"bad","data":{"k":1}}}',
        '{"jsonrpc":"2.0","id":2,"error":{"code":-32603,"message":"boom"}}',
        '{"jsonrpc":"2.0","id":3,"result":null}',
        '{"jsonrpc":"2.0","id":4,"result":{"late":true}}',
    ]);
});

it('rejects EVERY in-flight request when the transport dies, and refuses new ones', function () {
    [$peer] = sinkPeer();
    $a = $peer->request('a');
    $b = $peer->request('b');
    $peer->fail(new RuntimeException('child exited'));
    expect(fn () => $a->await())->toThrow(RuntimeException::class, 'a: child exited')
        ->and(fn () => $b->await())->toThrow(RuntimeException::class, 'b: child exited')
        ->and(fn () => $peer->request('c')->await())->toThrow(RuntimeException::class, 'child exited')
        ->and($peer->inFlight())->toBe(0);
});

it('reports what it cannot act on rather than dropping it', function () {
    [$peer, , $problems] = sinkPeer();
    $peer->receive(Json::decode('{"jsonrpc":"2.0","id":5,"result":1}'));
    $peer->receive(Json::decode('{"jsonrpc":"2.0","method":"nobody/listens"}'));
    $peer->receive(Json::decode('[1]'));
    $peer->receive(Json::decode('{"jsonrpc":"2.0"}'));
    expect([...$problems])->toBe([
        'response for unknown id: 5',
        'unhandled notification: nobody/listens',
        'frame is not a JSON-RPC object',
        'frame has neither a method nor an id',
    ]);
});
