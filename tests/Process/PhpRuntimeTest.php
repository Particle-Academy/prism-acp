<?php

declare(strict_types=1);

use Prism\Acp\Claude\PhpRuntime;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Process\ProcessTreeException;
use Prism\Acp\Process\WindowsProviderProcess;

/** @return array<string, mixed> */
function measuredPhpSapi(string $binary, array $arguments, bool $explicit): array
{
    $env = ['REDIRECT_STATUS' => '200'];
    if ($explicit) {
        $env['PRISM_TEST_PHP_BINARY'] = PHP_BINARY;
    }
    $probe = WindowsProviderProcess::launch($binary, ['-n', ...$arguments, dirname(__DIR__).'/fixtures/php-sapi-probe.php'], dirname(__DIR__, 2), ChildEnv::build(getenv(), set: $env)->env);
    $out = '';
    try {
        $probe->closeInput();
        $deadline = microtime(true) + 8;
        while ($probe->isOpen() && microtime(true) < $deadline) {
            foreach ($probe->read(0.02) as [$kind, $bytes]) {
                if ($kind === 'stdout') {
                    $out .= (string) $bytes;
                }
            }
        }
        expect($probe->isOpen())->toBeFalse()->and($probe->exitCode())->toBe(0);

        return json_decode(trim($out), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        $stop = $probe->stop(0);
        expect($stop->verified)->toBeTrue()->and($stop->empty)->toBeTrue()->and($stop->survivors)->toBe(0);
    }
}

it('refuses an unqualified or malformed hook runtime before any interpreter runs', function (string $path) {
    expect(fn () => PhpRuntime::resolve($path))->toThrow(ProcessTreeException::class, 'absolute existing PHP CLI');
})->with(['php', '', "C:/php.exe\n", 'relative/php.exe', "C:/php\0.exe"]);

describe('actual Windows hook runtime selection', function () {
    it('verifies the current CLI under containment with inherited ini disabled', function () {
        expect(PhpRuntime::resolve(null))->toBe(realpath(PHP_BINARY));
    });

    foreach (['cgi' => ['php-cgi.exe', ['-q', '-f'], 'cgi-fcgi'], 'debugger' => ['phpdbg.exe', ['-qrr'], 'phpdbg']] as $name => [$filename, $arguments, $sapi]) {
        it("measures {$name} PHP_BINARY and refuses its implicit selection", function () use ($filename, $arguments, $sapi) {
            $row = measuredPhpSapi(dirname(PHP_BINARY).DIRECTORY_SEPARATOR.$filename, $arguments, false);
            expect($row['sapi'])->toBe($sapi)->and(basename($row['binary']))->toBe($filename)
                ->and($row['refused'])->toBeTrue()->and($row['reason'])->toContain('explicit trusted phpBinary');
        })->skip(! is_file(dirname(PHP_BINARY).DIRECTORY_SEPARATOR.$filename), "{$name} SAPI binary unavailable; UNCHECKED");

        it("accepts an explicit verified CLI override from {$name}", function () use ($filename, $arguments, $sapi) {
            $row = measuredPhpSapi(dirname(PHP_BINARY).DIRECTORY_SEPARATOR.$filename, $arguments, true);
            expect($row['sapi'])->toBe($sapi)->and($row['refused'])->toBeFalse()->and($row['selected'])->toBe(realpath(PHP_BINARY));
        })->skip(! is_file(dirname(PHP_BINARY).DIRECTORY_SEPARATOR.$filename), "{$name} SAPI binary unavailable; UNCHECKED");

        it("refuses {$name} as an explicit CLI interpreter", function () use ($filename) {
            expect(fn () => PhpRuntime::resolve(dirname(PHP_BINARY).DIRECTORY_SEPARATOR.$filename))->toThrow(ProcessTreeException::class, 'did not verify as a working PHP CLI');
        })->skip(! is_file(dirname(PHP_BINARY).DIRECTORY_SEPARATOR.$filename), "{$name} SAPI binary unavailable; UNCHECKED");
    }
})->skip(PHP_OS_FAMILY !== 'Windows', 'actual runtime containment is Windows only in this preview');
