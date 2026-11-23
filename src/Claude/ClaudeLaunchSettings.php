<?php

declare(strict_types=1);

namespace Prism\Acp\Claude;

use Prism\Acp\Ndjson\Json;
use Prism\Acp\Support\LaunchToken;

/**
 * The settings every Claude launch carries: remote control is off, and no option turns it back on.
 *
 * `disableRemoteControl` is Claude Code's documented switch. Delivered with `--settings`, it sits above the user,
 * project and local settings files and below managed settings, and nothing is written to the person's own files.
 *
 * Ported from prism-acp-ts src/remote-control.ts. Token comparison follows the reference: padding and invisible format
 * characters removed, then full Unicode lower-casing, then the part before any `=`.
 */
final class ClaudeLaunchSettings
{
    /** Applied last, so no other settings value can replace it. */
    public const FORCED = ['disableRemoteControl' => true];

    /** Host arguments a launch refuses. */
    private const REFUSED = [
        '--remote-control', '--remote-control-session-name-prefix', 'remote-control', '--rc', '--cloud', '--remote',
        '--teleport', '--settings',
    ];

    /**
     * Merge the forced keys into settings a launch already passes. The other keys are kept; the forced ones win.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function merge(array $settings): array
    {
        return array_replace($settings, self::FORCED);
    }

    /** The inline `--settings` value for a launch that has no settings file of its own. */
    public static function inline(): string
    {
        return Json::encode(self::FORCED);
    }

    /**
     * Checked before anything is written or spawned. An argument that cannot be read is refused, not passed.
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
                throw new RemoteControlRefused("binaryArgs may not carry {$token}: remote control is forced off for claude launches");
            }
        }
    }
}
