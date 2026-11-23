<?php

declare(strict_types=1);

namespace Prism\Acp\Codex;

use Prism\Acp\Claude\RemoteControlRefused;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Ndjson\NdjsonFramer;
use Prism\Acp\Support\LaunchToken;

/**
 * Remote control is off for every Codex launch. Codex has no documented per-launch switch, so it is off by
 * construction: the launch is `app-server --listen stdio://`, host arguments cannot add a remote-control flag or
 * subcommand, and no `remoteControl/*` request or notification is written.
 *
 * Ported from prism-acp-ts src/remote-control.ts.
 */
final class CodexRemoteControl
{
    /** Host arguments a launch refuses. */
    private const REFUSED = ['--remote-control', 'remote-control', 'enable-remote-control', 'daemon', '--remote', 'cloud'];

    /**
     * Checked before anything is spawned. An argument that cannot be read is refused, not passed.
     *
     * @param  array<mixed>  $args
     *
     * @throws RemoteControlRefused
     */
    public static function assertHostArgs(array $args): void
    {
        foreach ($args as $arg) {
            $token = is_string($arg) ? LaunchToken::normalise($arg) : null;
            if ($token === null) {
                throw new RemoteControlRefused('binaryArgs must be valid UTF-8 strings');
            }
            $token = explode('=', $token, 2)[0];
            if (in_array($token, self::REFUSED, true)) {
                throw new RemoteControlRefused("binaryArgs may not carry {$token}: remote control is forced off for codex launches");
            }
        }
    }

    /**
     * The line to write for a frame. It is serialized once and that serialized form is what gets checked, so a
     * JsonSerializable value cannot show the check one value and the wire another.
     *
     * A frame is one JSON-RPC object. A list is refused outright: nothing in the driver sends one. Replies have no
     * method and pass.
     *
     * @throws RemoteControlRefused
     * @throws \JsonException for a value that cannot be encoded; nothing is written
     */
    public static function encodedFrame(mixed $frame): string
    {
        $line = NdjsonFramer::encodeLine($frame);
        self::assertWritten(Json::decode($line));

        return $line;
    }

    /**
     * The value to hand a transport that does its own encoding. An ordinary array frame stays the array it was, since
     * its method cannot change between reads; anything else becomes the decoded form of what was checked.
     *
     * @throws RemoteControlRefused
     * @throws \JsonException for a value that cannot be encoded; nothing is written
     */
    public static function checkedFrame(mixed $frame): mixed
    {
        $line = self::encodedFrame($frame);
        if (is_array($frame) && (! array_key_exists('method', $frame) || is_string($frame['method']))) {
            return $frame;
        }

        return Json::decode($line);
    }

    private static function assertWritten(mixed $written): void
    {
        if (is_array($written)) {
            throw new RemoteControlRefused('list frames are refused: a Codex frame is one JSON-RPC object');
        }
        $method = $written instanceof \stdClass ? ($written->method ?? null) : null;
        if (! is_string($method)) {
            return;
        }
        $name = LaunchToken::normalise($method);
        if ($name === null || $name === 'remotecontrol' || str_starts_with($name, 'remotecontrol/')) {
            throw new RemoteControlRefused('remoteControl RPCs are refused: remote control is forced off for codex launches');
        }
    }
}
