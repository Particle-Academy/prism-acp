<?php

declare(strict_types=1);

use Prism\Acp\Claude\Approvals;

it('uses the explicit all-tools sentinel without allowing regex injection through tool names', function () {
    foreach ([['.*'], ['Bash', '*'], ['Write|Bash'], ['Bash$'], ['']] as $invalid) {
        expect(fn () => Approvals::settings(PHP_BINARY, '/private/approve.php', $invalid))->toThrow(InvalidArgumentException::class);
    }
});

it('keeps wildcard matching under an exec-form blocking native adapter', function () {
    $settings = Approvals::settings(PHP_BINARY, sys_get_temp_dir().DIRECTORY_SEPARATOR.'approve.php', ['*']);
    $hook = $settings['hooks']['PreToolUse'][0]['hooks'][0];
    expect($settings['hooks']['PreToolUse'][0]['matcher'])->toBe('')
        ->and($hook['onFailure'])->toBe('block')
        ->and($hook['command'])->toMatch('/prism-acp-supervisor-[0-9a-f]{64}\.exe$/')
        ->and($hook['args'])->toBe(['approval', PHP_BINARY, sys_get_temp_dir().DIRECTORY_SEPARATOR.'approve.php', '86400']);
})->skip(PHP_OS_FAMILY !== 'Windows', 'the native adapter is Windows only');
