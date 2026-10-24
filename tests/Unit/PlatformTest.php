<?php

declare(strict_types=1);

use Prism\Acp\Process\ContainmentUnavailable;
use Prism\Acp\Process\Provider;

it('refuses unsupported process containment before running a provider', function () {
    $marker = sys_get_temp_dir().'/prism-acp-no-spawn-'.bin2hex(random_bytes(6));
    try {
        expect(fn () => Provider::launch(PHP_BINARY, ['-r', 'file_put_contents($argv[1], "spawned");', $marker], sys_get_temp_dir(), []))
            ->toThrow(ContainmentUnavailable::class, 'Windows Job Objects only');
        expect(file_exists($marker))->toBeFalse();
    } finally {
        @unlink($marker);
    }
})->skip(PHP_OS_FAMILY === 'Windows', 'Windows has Job Object containment');
