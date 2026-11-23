<?php

declare(strict_types=1);

namespace Prism\Acp\Codex;

use Prism\Acp\Claude\RemoteControlRefused;
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

    /** How many JsonSerializable layers a frame may have before it is refused. */
    private const MAX_SERIALIZABLE_DEPTH = 16;

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
     * The frame to write, after refusing a remoteControl request or notification. Replies have no method and pass.
     * A JsonSerializable frame is resolved here, so the value checked is the value written.
     *
     * @throws RemoteControlRefused
     */
    public static function checkedFrame(mixed $frame): mixed
    {
        for ($depth = 0; $frame instanceof \JsonSerializable; $depth++) {
            if ($depth >= self::MAX_SERIALIZABLE_DEPTH) {
                throw new RemoteControlRefused('frame could not be read');
            }
            $frame = $frame->jsonSerialize();
        }
        $method = match (true) {
            is_array($frame) => $frame['method'] ?? null,
            is_object($frame) => get_object_vars($frame)['method'] ?? null,
            default => null,
        };
        if (! is_string($method)) {
            return $frame;
        }
        $name = LaunchToken::normalise($method);
        if ($name === null || $name === 'remotecontrol' || str_starts_with($name, 'remotecontrol/')) {
            throw new RemoteControlRefused('remoteControl RPCs are refused: remote control is forced off for codex launches');
        }

        return $frame;
    }
}
