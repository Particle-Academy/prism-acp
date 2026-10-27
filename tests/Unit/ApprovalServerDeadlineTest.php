<?php

declare(strict_types=1);

use Prism\Acp\Agent\PermissionOutcome;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Claude\ApprovalServer;

it('expires a pending decision and drops its channel before a late allow can be written', function () {
    $loop = new Loop;
    $pending = new Deferred($loop);
    $asked = 0;
    $server = new ApprovalServer('tcp://127.0.0.1:0', function () use ($pending, &$asked): Deferred {
        $asked++;

        return $pending;
    }, $loop, 1);
    $channel = $server->listen();
    $client = stream_socket_client($channel['socket']);
    if ($client === false) {
        throw new RuntimeException('owned channel client failed');
    }
    try {
        fwrite($client, $channel['token']."\n".'{"tool_name":"Bash"}');
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        stream_set_blocking($client, false);
        $out = '';
        $closed = false;
        $watcher = $loop->onReadable($client, function () use ($client, &$out, &$closed): void {
            $out .= (string) fread($client, 65536);
            $closed = feof($client);
        });
        $deadline = microtime(true) + 4;
        $loop->run(function () use (&$closed, $deadline): bool {
            return $closed || microtime(true) >= $deadline;
        });
        $loop->cancel($watcher);
        expect($closed)->toBeTrue()->and($asked)->toBe(1)->and($out)->toStartWith("200\n");
        $answer = json_decode(substr($out, 4), true);
        expect($answer['hookSpecificOutput']['permissionDecision'])->toBe('deny');
        $before = $out;
        $pending->resolve(PermissionOutcome::selected('allow_once'));
        $loop->drain();
        expect((new ReflectionProperty($server, 'connections'))->getValue($server))->toBe([])
            ->and($out)->toBe($before);
    } finally {
        fclose($client);
        $server->close();
    }
});

it('bounds an incomplete unauthenticated request without asking for a decision', function () {
    $loop = new Loop;
    $asked = 0;
    $server = new ApprovalServer('tcp://127.0.0.1:0', function () use ($loop, &$asked): Deferred {
        $asked++;

        return new Deferred($loop);
    }, $loop, 1);
    $channel = $server->listen();
    $client = stream_socket_client($channel['socket']);
    if ($client === false) {
        throw new RuntimeException('owned incomplete channel client failed');
    }
    try {
        fwrite($client, 'incomplete-token');
        stream_set_blocking($client, false);
        $out = '';
        $closed = false;
        $watcher = $loop->onReadable($client, function () use ($client, &$out, &$closed): void {
            $out .= (string) fread($client, 65536);
            $closed = feof($client);
        });
        $deadline = microtime(true) + 4;
        $loop->run(function () use (&$closed, $deadline): bool {
            return $closed || microtime(true) >= $deadline;
        });
        $loop->cancel($watcher);
        expect($closed)->toBeTrue()->and($asked)->toBe(0)->and($out)->toBe("408\n")
            ->and((new ReflectionProperty($server, 'connections'))->getValue($server))->toBe([]);
    } finally {
        fclose($client);
        $server->close();
    }
});
