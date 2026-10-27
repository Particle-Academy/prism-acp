<?php

declare(strict_types=1);

use Prism\Acp\Claude\Approvals;
use Prism\Acp\Claude\ClaudeDriverOptions;

it('refuses versions without the measured hook failure policy contract', function (mixed $version) {
    expect(Approvals::cliVersion($version))->toBeNull();
})->with([[null], false, 2, '', '2.1.294', '1.99.999', '2.0.999', 'v2.1.310', '2.01.310', '2.1.310-beta.1', "2.1.310\n", '2.1.310 (Claude Code)', '9007199254740992.0.0', '2.1.9007199254740992', '2.1.310;exit 0']);

it('accepts the stable minimum and later declared compatible versions', function (string $version) {
    expect(Approvals::cliVersion($version))->toBe($version);
})->with(['2.1.295', '2.1.310', '2.2.0', '3.0.0']);

it('bounds the host-selected approval wait', function (int $seconds) {
    expect(fn () => Approvals::waitSeconds($seconds))->toThrow(InvalidArgumentException::class);
})->with([-1, 0, 86401, PHP_INT_MAX]);

it('preserves trusted approval policy when ACP supplies a session', function () {
    $base = new ClaudeDriverOptions('/host', permissionPrompts: ['*'], phpBinary: 'C:/trusted/php.exe', permissionTimeoutSeconds: 7);
    $session = $base->forSession(['cwd' => '/session', 'mcpServers' => [], 'resumeSessionId' => 'session-id']);
    expect($session->cwd)->toBe('/session')->and($session->resumeSessionId)->toBe('session-id')
        ->and($session->phpBinary)->toBe('C:/trusted/php.exe')->and($session->permissionTimeoutSeconds)->toBe(7)
        ->and($session->permissionPrompts)->toBe(['*']);
});
