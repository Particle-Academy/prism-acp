<?php

declare(strict_types=1);

use Prism\Acp\Support\DirectoryProtectionException;
use Prism\Acp\Support\PrivateDirectory;

/*
 * PRIVATE DIRECTORIES ON LINUX AND MACOS: 0700, owned by this user, no group/other bits anywhere below, and no link
 * anywhere, refused rather than repaired. Admission reads metadata only: a planted file nobody may read is still
 * admitted, which it could not be if admission opened it.
 */

function posixPrivateFixture(): string
{
    $directory = PrivateDirectory::create('prism-acp-posix-private-');
    expect(fileperms($directory) & 07777)->toBe(0700)
        ->and(fileowner($directory))->toBe(posix_geteuid());

    return $directory;
}

function removePosixFixture(string $directory): void
{
    foreach (scandir($directory) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            $path = $directory.'/'.$name;
            if (is_link($path) || ! is_dir($path)) {
                @unlink($path);
            } else {
                removePosixFixture($path);
            }
        }
    }
    @chmod($directory, 0700);
    @rmdir($directory);
}

describe('POSIX private directories', function () {
    it('creates 0700 owned by this user, and admits 0600 files', function () {
        $directory = posixPrivateFixture();
        try {
            touch($directory.'/settings.json');
            chmod($directory.'/settings.json', 0600);
            PrivateDirectory::assertProtected($directory);
            expect(true)->toBeTrue();
        } finally {
            removePosixFixture($directory);
        }
    });

    it('admits a file it cannot read: admission never opens contents', function () {
        $directory = posixPrivateFixture();
        try {
            file_put_contents($directory.'/credential', 'synthetic');
            chmod($directory.'/credential', 0000);
            PrivateDirectory::assertProtected($directory);
            expect(true)->toBeTrue();
        } finally {
            @chmod($directory.'/credential', 0600);
            removePosixFixture($directory);
        }
    });

    it('refuses a wrong mode, at the root or below it', function (string $where, int $mode) {
        $directory = posixPrivateFixture();
        try {
            $target = $directory;
            if ($where !== 'root') {
                $target = $directory.'/'.$where;
                $where === 'subdir' ? mkdir($target, 0700) : touch($target);
            }
            chmod($target, $mode);
            expect(fn () => PrivateDirectory::assertProtected($directory))->toThrow(DirectoryProtectionException::class);
        } finally {
            removePosixFixture($directory);
        }
    })->with([
        'root group-readable' => ['root', 0750],
        'root world-executable' => ['root', 0701],
        'root setgid' => ['root', 02700],
        'file group-readable' => ['file', 0640],
        'file world-readable' => ['file', 0604],
        'file setuid' => ['file', 04600],
        'subdir group-writable' => ['subdir', 0770],
    ]);

    it('refuses a pre-planted symlink anywhere in the tree, even one pointing back inside', function (string $target) {
        $directory = posixPrivateFixture();
        try {
            touch($directory.'/inside');
            chmod($directory.'/inside', 0600);
            symlink($target === 'inside' ? $directory.'/inside' : sys_get_temp_dir(), $directory.'/planted');
            expect(fn () => PrivateDirectory::assertProtected($directory))->toThrow(DirectoryProtectionException::class);
        } finally {
            removePosixFixture($directory);
        }
    })->with(['inside', 'outside']);

    it('refuses a root that is itself a symlink to a private directory', function () {
        $directory = posixPrivateFixture();
        $link = sys_get_temp_dir().'/prism-acp-posix-link-'.bin2hex(random_bytes(4));
        try {
            symlink($directory, $link);
            expect(fn () => PrivateDirectory::assertProtected($link))->toThrow(DirectoryProtectionException::class)
                ->and(fn () => PrivateDirectory::protect($link))->toThrow(DirectoryProtectionException::class);
        } finally {
            @unlink($link);
            removePosixFixture($directory);
        }
    });

    it('refuses a fifo or socket planted below it', function () {
        $directory = posixPrivateFixture();
        $server = null;
        try {
            $server = stream_socket_server('unix://'.$directory.'/planted.sock');
            chmod($directory.'/planted.sock', 0600);
            expect(fn () => PrivateDirectory::assertProtected($directory))->toThrow(DirectoryProtectionException::class);
        } finally {
            if (is_resource($server)) {
                fclose($server);
            }
            removePosixFixture($directory);
        }
    });
})->skip(! in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) || ! function_exists('posix_geteuid'), 'POSIX modes and ownership');
