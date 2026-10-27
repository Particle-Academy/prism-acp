<?php

declare(strict_types=1);

use Prism\Acp\Process\ProcessTreeException;
use Prism\Acp\Process\WindowsSupervisorBuild;
use Prism\Acp\Support\PrivateDirectory;
use Tests\Support\WindowsPermissions;

describe('Windows cache generations used by providers and approval adapters', function () {
    it('binds the pointer, filename and bytes and preserves only the private cache owner', function () {
        $root = PrivateDirectory::create('prism-php-cache-');
        WindowsSupervisorBuild::useDirectory($root);
        try {
            $executable = WindowsSupervisorBuild::executable();
            $hash = hash_file('sha256', $executable);
            $pointer = $root.'\\prism-acp-supervisor.current.sha256';
            $executableAcl = WindowsPermissions::inspect($executable, true);
            $pointerAcl = WindowsPermissions::inspect($pointer, true);
            expect(basename($executable))->toBe('prism-acp-supervisor-'.$hash.'.exe')
                ->and(file_get_contents($pointer))->toBe($hash)
                ->and(WindowsSupervisorBuild::executable())->toBe($executable)
                ->and(WindowsPermissions::inspect($root)['code'])->toBe(0)
                ->and($executableAcl['code'])->toBe(0)->and($executableAcl['evidence'])->toContain('inherited=True')
                ->and($pointerAcl['code'])->toBe(0)->and($pointerAcl['evidence'])->toContain('inherited=True');
        } finally {
            WindowsSupervisorBuild::useDirectory(null);
            expect(PrivateDirectory::remove($root, ...(glob($root.'/*') ?: [])))->toBeTrue();
        }
    });

    it('refuses altered bytes without a compiler and rebuilds a separate generation when restored', function () {
        $root = PrivateDirectory::create('prism-php-cache-altered-');
        $systemRoot = getenv('SystemRoot');
        WindowsSupervisorBuild::useDirectory($root);
        try {
            $first = WindowsSupervisorBuild::executable();
            file_put_contents($first, 'altered', FILE_APPEND);
            $altered = hash_file('sha256', $first);
            putenv('SystemRoot='.$root);
            expect(fn () => WindowsSupervisorBuild::executable())->toThrow(ProcessTreeException::class, 'csc.exe');
            putenv($systemRoot === false ? 'SystemRoot' : 'SystemRoot='.$systemRoot);
            $replacement = WindowsSupervisorBuild::executable();
            expect($replacement)->not->toBe($first)
                ->and(hash_file('sha256', $first))->toBe($altered)
                ->and(hash_file('sha256', $replacement))->toBe(file_get_contents($root.'\\prism-acp-supervisor.current.sha256'));
        } finally {
            putenv($systemRoot === false ? 'SystemRoot' : 'SystemRoot='.$systemRoot);
            WindowsSupervisorBuild::useDirectory(null);
            expect(PrivateDirectory::remove($root, ...(glob($root.'/*') ?: [])))->toBeTrue();
        }
    });

    it('refuses a malformed pointer when it cannot build a verified replacement', function (string $value) {
        $root = PrivateDirectory::create('prism-php-cache-pointer-');
        $systemRoot = getenv('SystemRoot');
        WindowsSupervisorBuild::useDirectory($root);
        try {
            WindowsSupervisorBuild::executable();
            file_put_contents($root.'\\prism-acp-supervisor.current.sha256', $value);
            putenv('SystemRoot='.$root);
            expect(fn () => WindowsSupervisorBuild::executable())->toThrow(ProcessTreeException::class, 'csc.exe');
        } finally {
            putenv($systemRoot === false ? 'SystemRoot' : 'SystemRoot='.$systemRoot);
            WindowsSupervisorBuild::useDirectory(null);
            expect(PrivateDirectory::remove($root, ...(glob($root.'/*') ?: [])))->toBeTrue();
        }
    })->with(['../../outside.exe', str_repeat('x', 129), str_repeat('A', 64)]);
})->skip(PHP_OS_FAMILY !== 'Windows', 'native Windows cache only');
