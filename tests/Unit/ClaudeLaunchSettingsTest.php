<?php

declare(strict_types=1);

use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Claude\ClaudeDriverOptions;

/*
 * Ported from prism-acp-ts src/remote-control.ts. Every Claude launch carries the forced settings, and a host's
 * binaryArgs cannot replace them.
 */
const REMOTE_CONTROL_REFUSAL = 'Prism\Acp\Claude\RemoteControlRefused';

/** @param list<string> $binaryArgs */
function launchRefusal(array $binaryArgs): ?string
{
    try {
        new ClaudeDriver(new ClaudeDriverOptions(cwd: sys_get_temp_dir(), binaryArgs: $binaryArgs));
    } catch (Throwable $thrown) {
        return $thrown::class;
    }

    return null;
}

it('puts the forced settings in the argv of a launch with no settings file, last', function () {
    expect(array_slice(ClaudeDriver::args(new ClaudeDriverOptions(cwd: '/w')), -2))
        ->toBe(['--settings', '{"disableRemoteControl":true}']);
});

it('passes a settings file instead of the inline value, never both', function () {
    $args = ClaudeDriver::args(new ClaudeDriverOptions(cwd: '/w'), null, '/private/settings.json');
    expect(array_slice($args, -2))->toBe(['--settings', '/private/settings.json'])
        ->and(array_count_values($args)['--settings'])->toBe(1);
});

it('refuses host arguments that carry remote control or a second settings value', function (string $arg) {
    expect(launchRefusal(['/fixture/cli.php', $arg]))->toBe(REMOTE_CONTROL_REFUSAL);
})->with([
    '--settings', '--SETTINGS', '--Settings={"disableRemoteControl":false}', ' --settings', "--settings\t",
    "--set\u{200B}tings", "\u{FEFF}--settings", "--set\u{00AD}tings=x",
    '--remote-control', '--REMOTE-CONTROL', 'remote-control', 'Remote-Control', ' remote-control ',
    "remote\u{200D}-control", '--remote-control-session-name-prefix=x', '--rc', '--RC', '--cloud', '--remote', '--teleport',
]);

it('refuses a host argument it cannot read rather than passing it', function () {
    expect(launchRefusal(["--settings\xFF"]))->toBe(REMOTE_CONTROL_REFUSAL);
});

it('leaves unrelated host arguments alone (the positive control)', function (string $arg) {
    expect(launchRefusal(['/fixture/cli.php', $arg]))->toBeNull();
})->with(['--max-old-space-size=4096', '--settings-profile', '/path/remote-control/cli.js', '--no-warnings']);
