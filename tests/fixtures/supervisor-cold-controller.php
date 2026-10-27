<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Process\WindowsProviderProcess;
use Prism\Acp\Process\WindowsSupervisorBuild;
use Prism\Acp\Support\PrivateDirectory;

if (PHP_OS_FAMILY !== 'Windows' || getenv('PRISM_ACP_PHP_COLD') !== '1') {
    throw new RuntimeException('opt-in native Windows cold-cache controller only');
}
$root = PrivateDirectory::create('prism-php-cold-');
$temporaryParent = realpath(sys_get_temp_dir());
$rows = [];
$totalDeadline = microtime(true) + 240;
WindowsSupervisorBuild::useDirectory($root.DIRECTORY_SEPARATOR.'host-cache');
try {
    WindowsSupervisorBuild::executable();
    for ($round = 0; $round < 20; $round++) {
        $directory = $root.DIRECTORY_SEPARATOR.'round-'.$round;
        mkdir($directory);
        $go = $directory.DIRECTORY_SEPARATOR.'go';
        $cache = $directory.DIRECTORY_SEPARATOR.'cache';
        $workers = [];
        $outputs = array_fill(0, 8, '');
        try {
            for ($i = 0; $i < 8; $i++) {
                $workers[] = WindowsProviderProcess::launch(PHP_BINARY, ['-n', __DIR__.'/supervisor-cold-worker.php', $cache, $go, $directory.DIRECTORY_SEPARATOR.'ready-'.$i, $directory], $directory, ChildEnv::build(getenv())->env);
            }
            $readyDeadline = min($totalDeadline, microtime(true) + 10);
            do {
                $ready = true;
                for ($i = 0; $i < 8; $i++) {
                    clearstatcache(true, $directory.DIRECTORY_SEPARATOR.'ready-'.$i);
                    $ready = $ready && is_file($directory.DIRECTORY_SEPARATOR.'ready-'.$i);
                }
                if (! $ready) {
                    usleep(10000);
                }
                if (microtime(true) > $readyDeadline) {
                    throw new RuntimeException('owned cold-cache ready barrier deadline');
                }
            } while (! $ready);
            file_put_contents($go, 'go');
            $roundDeadline = min($totalDeadline, microtime(true) + 60);
            do {
                $open = false;
                foreach ($workers as $i => $worker) {
                    foreach ($worker->read(0.0) as [$kind, $bytes]) {
                        if ($kind === 'stdout') {
                            $outputs[$i] .= (string) $bytes;
                        }
                    }
                    $open = $open || $worker->isOpen();
                }
                if ($open) {
                    usleep(5000);
                }
                if (microtime(true) > $roundDeadline) {
                    throw new RuntimeException('owned cold-cache round deadline');
                }
            } while ($open);
            $failures = [];
            $approvalVerified = 0;
            foreach ($workers as $i => $worker) {
                $row = json_decode(trim($outputs[$i]), true);
                if (is_array($row) && ($row['success'] ?? false) === true && $worker->exitCode() === 0 &&
                    ($row['stop']['verified'] ?? false) && ($row['stop']['empty'] ?? false) && ($row['stop']['survivors'] ?? null) === 0 &&
                    ($row['hashVerified'] ?? false) && ($row['approval'] ?? null) === ['allow' => 0, 'silent' => 2]) {
                    $approvalVerified++;
                } else {
                    $failures[] = is_array($row) ? $row : ['name' => 'InvalidReceipt', 'message' => substr($outputs[$i], 0, 1000)];
                }
            }
            $rows[] = ['round' => $round, 'launches' => 8, 'failed' => count($failures), 'approvalVerified' => $approvalVerified, 'failures' => $failures];
            echo json_encode(['phpSupervisorColdBatch' => end($rows)], JSON_UNESCAPED_SLASHES)."\n";
        } finally {
            foreach ($workers as $worker) {
                $stop = $worker->stop(0);
                if (! $stop->verified || ! $stop->empty || $stop->survivors !== 0) {
                    throw new RuntimeException('outer cold worker was not verified empty');
                }
            }
            unset($worker);
            $workers = []; // Destructors close Windows supervisor resources before directory cleanup.
        }
    }
    $failed = array_sum(array_column($rows, 'failed'));
    $verified = array_sum(array_column($rows, 'approvalVerified'));
    echo json_encode(['phpSupervisorColdSummary' => ['batches' => count($rows), 'parallelism' => 8, 'launches' => count($rows) * 8, 'failed' => $failed, 'approvalVerified' => $verified, 'outerVerifiedEmpty' => 160, 'osCachesPurged' => false]])."\n";
} finally {
    WindowsSupervisorBuild::useDirectory(null);
    if (dirname((string) realpath($root)) !== $temporaryParent) {
        throw new RuntimeException('owned cold-cache cleanup escaped temporary parent');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isLink()) {
            throw new RuntimeException('unexpected link in owned cold-cache cleanup');
        }
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    unset($files, $file);
    rmdir($root);
}
exit($failed === 0 && $verified === 160 ? 0 : 1);
