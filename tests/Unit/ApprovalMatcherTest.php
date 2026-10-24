<?php

declare(strict_types=1);

use Prism\Acp\Claude\Approvals;

it('uses the explicit all-tools sentinel without allowing regex injection through tool names', function () {
    $settings = Approvals::settings(PHP_BINARY, '/private/approve.php', ['*']);
    expect($settings['hooks']['PreToolUse'][0]['matcher'])->toBe('')
        ->and($settings['hooks']['PreToolUse'][0]['hooks'][0]['onFailure'])->toBe('block');
    foreach ([['.*'], ['Bash', '*'], ['Write|Bash'], ['Bash$'], ['']] as $invalid) {
        expect(fn () => Approvals::settings(PHP_BINARY, '/private/approve.php', $invalid))->toThrow(InvalidArgumentException::class);
    }
});
