<?php

declare(strict_types=1);

use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;

/*
 * The loop and Deferred are this port's Node runtime: everything asynchronous in the reference runs on them, so what
 * they promise is checked here directly.
 */

it('awaits inside a Fiber without blocking the loop, and resumes with the value', function () {
    $loop = new Loop;
    $value = new Deferred($loop);
    $order = [];
    $task = Deferred::async(function () use ($value, &$order) {
        $order[] = 'waiting';
        $got = $value->await();
        $order[] = "got {$got}";

        return $got * 2;
    }, $loop);
    $order[] = 'returned to caller';
    $loop->delay(0.01, fn () => $value->resolve(21));

    expect($task->await())->toBe(42)
        ->and($order)->toBe(['waiting', 'returned to caller', 'got 21']);
});

it('rethrows a rejection where it is awaited', function () {
    $loop = new Loop;
    $value = new Deferred($loop);
    $task = Deferred::async(fn () => $value->await(), $loop);
    $loop->defer(fn () => $value->reject(new RuntimeException('no')));

    expect(fn () => $task->await())->toThrow(RuntimeException::class, 'no');
});

it('settles once: a second resolve or reject is ignored', function () {
    $value = new Deferred(new Loop);
    $value->resolve(1);
    $value->resolve(2);
    $value->reject(new RuntimeException('late'));

    expect($value->await())->toBe(1);
});

it('watches a socket for input', function () {
    $loop = new Loop;
    [$a, $b] = stream_socket_pair(PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $got = new Deferred($loop);
    $id = $loop->onReadable($b, function () use ($b, $got, $loop, &$id) {
        $got->resolve(fread($b, 100));
        $loop->cancel($id);
    });
    $loop->delay(0.01, fn () => fwrite($a, 'hello'));

    expect($got->await())->toBe('hello');
});

it('says so when the loop runs dry before a value arrives, rather than hanging', function () {
    expect(fn () => (new Deferred(new Loop))->await())->toThrow(LogicException::class);
});
