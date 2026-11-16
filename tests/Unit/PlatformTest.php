<?php

declare(strict_types=1);

use Prism\Acp\Process\ContainmentUnavailable;
use Prism\Acp\Process\Provider;

it('refuses unsupported process containment before running a provider', function () {
    $marker = sys_get_temp_dir().'/prism-acp-no-spawn-'.bin2hex(random_bytes(6));
    try {
        expect(fn () => Provider::launch(PHP_BINARY, ['-r', 'file_put_contents($argv[1], "spawned");', $marker], sys_get_temp_dir(), []))
            ->toThrow(ContainmentUnavailable::class, 'supported: Windows, Linux, macOS')
            ->and(fn () => Provider::containment())->toThrow(ContainmentUnavailable::class);
        expect(file_exists($marker))->toBeFalse();
    } finally {
        @unlink($marker);
    }
})->skip(in_array(PHP_OS_FAMILY, ['Windows', 'Linux', 'Darwin'], true), 'this platform has containment; tested in ProcessTreeTest and PosixProcessTreeTest');

it('says what each platform\'s containment does not hold', function () {
    expect(Provider::weakness())->toBe(match (PHP_OS_FAMILY) {
        'Windows' => null,
        'Linux' => Provider::LINUX_WEAKNESS,
        default => Provider::MACOS_WEAKNESS,
    })
        ->and(Provider::MACOS_WEAKNESS)->toContain('escapes')
        ->and(Provider::LINUX_WEAKNESS)->toContain('G-92');
    if (PHP_OS_FAMILY === 'Windows') {
        expect(Provider::containment())->toBe(['kind' => 'job-object', 'mode' => 'job-object', 'weakness' => null]);
    }
});
