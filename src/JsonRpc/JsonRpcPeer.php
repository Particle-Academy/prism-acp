<?php

declare(strict_types=1);

namespace Prism\Acp\JsonRpc;

use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Support\Js;
use Prism\Acp\Support\Undefined;

/**
 * A bidirectional JSON-RPC 2.0 peer: ACP's client calls the agent and the agent calls the client back, so both sides
 * send requests and both answer them. Ported from prism-acp-ts src/jsonrpc.ts. Transport-agnostic: it is given a
 * `send` callback and fed parsed frames.
 *
 * Four decisions, each pinned because the alternative hangs:
 *
 * - An inbound request with no handler gets a JSON-RPC error, never silence: silence parks the far side forever.
 * - A handler that throws still produces a response.
 * - Transport death rejects EVERY in-flight request (fail()).
 * - Correlation is strictly by id, with no ordering assumption.
 *
 * A request handler runs as an asynchronous function (a Fiber), so it may await; its answer is sent when it returns.
 */
final class JsonRpcPeer
{
    /** @var array<string, \Closure(mixed): mixed> */
    private array $requests = [];

    /** @var array<string, \Closure(mixed): void> */
    private array $notifications = [];

    /** @var array<int|string, array{deferred: Deferred<mixed>, method: string}> keyed by the id as a string (PHP keeps "1" as 1) */
    private array $pending = [];

    private int $nextId = 1;

    private ?\Throwable $failure = null;

    /**
     * @param  \Closure(mixed): void  $send  hand one outbound message to the transport
     * @param  (\Closure(string, mixed): void)|null  $onProtocolError  a frame this peer cannot act on, surfaced rather than ignored
     */
    public function __construct(
        private readonly \Closure $send,
        private readonly ?\Closure $onProtocolError = null,
        private readonly ?Loop $loop = null,
    ) {}

    /** @param \Closure(mixed): mixed $handler */
    public function handle(string $method, \Closure $handler): self
    {
        $this->requests[$method] = $handler;

        return $this;
    }

    /** @param \Closure(mixed): void $handler */
    public function onNotify(string $method, \Closure $handler): self
    {
        $this->notifications[$method] = $handler;

        return $this;
    }

    public function failed(): bool
    {
        return $this->failure !== null;
    }

    /** How many outbound requests are awaiting a response. */
    public function inFlight(): int
    {
        return count($this->pending);
    }

    /**
     * Send a request; the Deferred settles with its result.
     *
     * @return Deferred<mixed>
     */
    public function request(string $method, mixed $params = Undefined::Value): Deferred
    {
        $deferred = new Deferred($this->loop);
        if ($this->failure !== null) {
            $deferred->reject($this->failure);

            return $deferred;
        }
        $id = $this->nextId++;
        $key = (string) $id;
        $this->pending[$key] = ['deferred' => $deferred, 'method' => $method];
        try {
            ($this->send)(self::payload($method, $params, $id));
        } catch (\Throwable $cause) {
            // A transport that refuses the write must not leave the caller waiting on a request that was never sent.
            unset($this->pending[$key]);
            $deferred->reject($cause);
        }

        return $deferred;
    }

    /** Send a notification. No response is expected. */
    public function notify(string $method, mixed $params = Undefined::Value): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        ($this->send)(self::payload($method, $params, null));
    }

    /**
     * Feed one inbound frame, already parsed. A request's handler starts here and may finish later; the returned
     * Deferred settles when it has answered, for a test that wants to wait.
     *
     * @return Deferred<mixed>
     */
    public function receive(mixed $frame): Deferred
    {
        $done = new Deferred($this->loop);
        if (! $frame instanceof \stdClass) {
            $this->protocolError('frame is not a JSON-RPC object', $frame);
            $done->resolve(null);

            return $done;
        }
        $id = Undefined::get($frame, 'id');
        $hasId = is_string($id) || is_int($id) || is_float($id);
        $method = Js::get($frame, 'method');

        if (is_string($method) && $hasId) {
            return $this->onRequest($method, $frame, $id);
        }
        if (is_string($method)) {
            $this->onNotification($method, $frame);
        } elseif ($hasId) {
            $this->onResponse($frame, $id);
        } else {
            $this->protocolError('frame has neither a method nor an id', $frame);
        }
        $done->resolve(null);

        return $done;
    }

    /** Abandon the peer: reject every in-flight request with the same reason, and refuse new ones. */
    public function fail(\Throwable $reason): void
    {
        if ($this->failure !== null) {
            return;
        }
        $this->failure = $reason;
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $entry) {
            $entry['deferred']->reject(new \RuntimeException("{$entry['method']}: {$reason->getMessage()}", 0, $reason));
        }
    }

    /** @return Deferred<mixed> */
    private function onRequest(string $method, \stdClass $frame, int|float|string $id): Deferred
    {
        $handler = $this->requests[$method] ?? null;
        if ($handler === null) {
            ($this->send)(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => RpcError::METHOD_NOT_FOUND, 'message' => "method not found: {$method}"]]);
            $done = new Deferred($this->loop);
            $done->resolve(null);

            return $done;
        }
        $params = Undefined::get($frame, 'params');

        return Deferred::async(function () use ($handler, $params, $id): null {
            try {
                $result = $handler($params === Undefined::Value ? null : $params);
                ($this->send)(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
            } catch (RpcError $cause) {
                $error = ['code' => $cause->rpcCode, 'message' => $cause->getMessage()];
                if ($cause->data !== Undefined::Value) {
                    $error['data'] = $cause->data;
                }
                ($this->send)(['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]);
            } catch (\Throwable $cause) {
                ($this->send)(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => RpcError::INTERNAL_ERROR, 'message' => $cause->getMessage()]]);
            }

            return null;
        }, $this->loop);
    }

    private function onNotification(string $method, \stdClass $frame): void
    {
        $handler = $this->notifications[$method] ?? null;
        if ($handler === null) {
            // No reply exists for a notification, so it is surfaced locally instead.
            $this->protocolError("unhandled notification: {$method}", $frame);

            return;
        }
        try {
            $params = Undefined::get($frame, 'params');
            $handler($params === Undefined::Value ? null : $params);
        } catch (\Throwable $cause) {
            $this->protocolError("notification handler threw: {$cause->getMessage()}", $frame);
        }
    }

    private function onResponse(\stdClass $frame, int|float|string $id): void
    {
        $key = is_string($id) ? $id : Js::numberToString($id);
        $pending = $this->pending[$key] ?? null;
        if ($pending === null) {
            $this->protocolError("response for unknown id: {$key}", $frame);

            return;
        }
        unset($this->pending[$key]);

        $error = Js::get($frame, 'error');
        if ($error instanceof \stdClass) {
            $code = Js::get($error, 'code');
            $pending['deferred']->reject(new RpcError(
                is_int($code) ? $code : (is_float($code) && floor($code) === $code ? (int) $code : RpcError::INTERNAL_ERROR),
                Js::string($error, 'message') ?? 'unknown RPC error',
                Undefined::get($error, 'data'),
            ));

            return;
        }

        $pending['deferred']->resolve(Js::get($frame, 'result'));
    }

    private function protocolError(string $problem, mixed $frame): void
    {
        $this->onProtocolError?->__invoke($problem, $frame);
    }

    /** @return array<string, mixed> */
    private static function payload(string $method, mixed $params, ?int $id): array
    {
        // In the reference's key order: jsonrpc, method, then id.
        $message = ['jsonrpc' => '2.0', 'method' => $method];
        if ($id !== null) {
            $message['id'] = $id;
        }
        // Omitted rather than sent as null: "params": null is not the same as no params.
        if ($params !== Undefined::Value) {
            $message['params'] = $params;
        }

        return $message;
    }
}
