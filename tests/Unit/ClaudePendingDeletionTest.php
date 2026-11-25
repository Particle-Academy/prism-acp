<?php

declare(strict_types=1);

use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Async\Loop;
use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Claude\ClaudeDriverOptions;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Process\WindowsProviderProcess;
use Prism\Acp\Support\PrivateDirectory;

require_once dirname(__DIR__).'/fixtures/native-file-diagnostic.php';

it('bounds pending known approval-file cleanup without blocking the event loop', function (string $fault): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        $this->markTestSkipped('actual Windows share-delete handles only');
    }
    $directory = PrivateDirectory::create('prism-acp-approve-');
    $files = array_map(fn (string $name): string => $directory.DIRECTORY_SEPARATOR.$name, ['approve.php', 'settings.json']);
    foreach ($files as $file) {
        fclose(fopen($file, 'x')); // Fresh EMPTY markers; no credential content exists or is opened.
    }
    $unknown = $directory.DIRECTORY_SEPARATOR.'unknown-marker';
    $holder = null;
    $handlerInstalled = false;
    try {
        $holder = WindowsProviderProcess::launch(ownedNativeFileDiagnosticExecutable(), [$directory, 'hold-empty-control'], sys_get_temp_dir(), ChildEnv::build(getenv())->env);
        $out = '';
        $deadline = microtime(true) + 5;
        while (! str_contains($out, "\n") && $holder->isOpen() && microtime(true) < $deadline) {
            foreach ($holder->read(0.02) as [$kind, $bytes]) {
                if ($kind === 'stdout') {
                    $out .= $bytes;
                }
            }
        }
        expect(json_decode(trim($out), true, flags: JSON_THROW_ON_ERROR)['holderReady'])->toBeTrue();
        if ($fault === 'unknown') {
            fclose(fopen($unknown, 'x'));
        }
        $loop = new Loop;
        $problems = [];
        $heartbeat = 0;
        $driver = new ClaudeDriver(new ClaudeDriverOptions($directory), new DriverEvents(onProtocolError: function (string $message, ?bool $recoverable) use (&$problems): void {
            $problems[] = ['message' => $message, 'recoverable' => $recoverable];
        }), $loop);
        (new ReflectionProperty(ClaudeDriver::class, 'approvalDirectory'))->setValue($driver, $directory);
        (new ReflectionProperty(ClaudeDriver::class, 'approvalFiles'))->setValue($driver, $files);
        $loop->delay(0.05, function () use (&$heartbeat): void {
            $heartbeat++;
        });
        if ($fault === 'transient') {
            $loop->delay(0.5, fn () => $holder->closeInput());
        }
        $expectedRemovalWarnings = 0;
        set_error_handler(function (int $severity, string $message, string $file, int $line) use ($directory, $files, $fault, $holder, &$expectedRemovalWarnings): bool {
            if ($severity === E_WARNING && $file === dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'PrivateDirectory.php'
                && str_starts_with($message, 'rmdir('.$directory.'):') && str_contains($message, 'Directory not empty')) {
                $expectedRemovalWarnings++;
                if ($fault === 'released' && $expectedRemovalWarnings === 2) {
                    // G-379, made deterministic: the holder lets go AFTER the retry's rmdir failed and BEFORE the driver
                    // reads the directory, which is the window main CI hit when the transient holder closed at 0.5 s.
                    $holder->closeInput();
                    $released = microtime(true) + 5;
                    while (($holder->isOpen() || scandir($directory) !== ['.', '..']) && microtime(true) < $released) {
                        $holder->read(0.02);
                        clearstatcache(true, $directory);
                    }
                    expect(scandir($directory))->toBe(['.', '..']);
                }

                return true; // This real OS failure is the controlled condition; every other warning remains an error.
            }
            if ($severity === E_WARNING && $file === dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Claude'.DIRECTORY_SEPARATOR.'ClaudeDriver.php'
                && in_array($message, array_map(fn (string $path): string => 'lstat(): Lstat failed for '.$path, $files), true)) {
                return true; // The only two EMPTY known markers are deliberately held delete-pending.
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        $handlerInstalled = true;
        $started = microtime(true);
        $verdict = $driver->stop(0)->await();
        $elapsed = microtime(true) - $started;
        $again = microtime(true);
        $second = $driver->stop(0)->await();
        expect(microtime(true) - $again)->toBeLessThan(0.5);
        clearstatcache(true, $directory);
        expect($verdict->toArray())->toBe(['graceful' => true, 'empty' => true, 'survivors' => 0, 'verified' => true])
            ->and($second->toArray())->toBe($verdict->toArray())->and($expectedRemovalWarnings)->toBeGreaterThan(0);
        echo json_encode(['ownedPendingCleanup' => ['fault' => $fault, 'elapsedSeconds' => $elapsed, 'heartbeat' => $heartbeat, 'directoryGone' => ! is_dir($directory)]], JSON_THROW_ON_ERROR)."\n";
        if ($fault === 'transient' || $fault === 'released') {
            // The driver's verdict first: no reported problem means its own removal succeeded inside the bound. Read
            // the directory after that, for up to a second: a directory removed while another process (an indexer or a
            // scanner) holds a handle to it stays in its parent until that handle closes. One still present after the
            // wait, or a reported problem, is a real failure. main CI (Windows, PHP 8.5, 2026-11-24) once saw the
            // directory present here, and the old assertion order could not say which of the two it was.
            expect($problems)->toBe([])->and($elapsed)->toBeLessThan(3.0);
            if ($fault === 'transient') {
                expect($elapsed)->toBeGreaterThanOrEqual(0.45)->and($heartbeat)->toBe(1);
            }
            $settled = microtime(true) + 1.0;
            while (is_dir($directory) && microtime(true) < $settled) {
                usleep(20_000);
                clearstatcache(true, $directory);
            }
            expect(is_dir($directory))->toBeFalse();
        } else {
            expect(is_dir($directory))->toBeTrue()->and($problems)->toHaveCount(1)
                ->and($problems[0]['recoverable'])->toBeFalse()->and($problems[0]['message'])->not->toContain($directory)
                ->and((new ReflectionProperty(ClaudeDriver::class, 'approvalDirectory'))->getValue($driver))->toBe($directory);
            if ($fault === 'persistent') {
                expect($elapsed)->toBeGreaterThanOrEqual(2.8)->toBeLessThan(4.5)->and($heartbeat)->toBe(1);
            } else {
                expect($elapsed)->toBeLessThan(1.5)->and($heartbeat)->toBe(0)->and(is_file($unknown))->toBeTrue();
            }
        }
    } finally {
        if ($handlerInstalled) {
            restore_error_handler();
        }
        if ($holder !== null) {
            $holder->closeInput();
            $deadline = microtime(true) + 5;
            while ($holder->isOpen() && microtime(true) < $deadline) {
                $holder->read(0.02);
            }
            $stop = $holder->stop(0);
            expect($stop->verified)->toBeTrue()->and($stop->empty)->toBeTrue()->and($stop->survivors)->toBe(0);
        }
        clearstatcache();
        expect(PrivateDirectory::remove($directory, ...[...$files, $unknown]))->toBeTrue();
    }
})->with(['transient', 'released', 'persistent', 'unknown']);
