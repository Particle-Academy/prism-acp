<?php

declare(strict_types=1);

/** @return list<int> */
function relayDescendants(int $hostPid): array
{
    // Read only identities, never command lines or environments that could contain credentials.
    $script = '$all = @(Get-CimInstance Win32_Process | Select-Object ProcessId,ParentProcessId); '
        .'$ids = @('.$hostPid.'); do { $next = @($all | Where-Object { $_.ParentProcessId -in $ids -and $_.ProcessId -notin $ids } | ForEach-Object { [int]$_.ProcessId }); $ids += $next } while ($next.Count); '
        .'ConvertTo-Json -Compress -InputObject @($ids | Where-Object { $_ -ne '.$hostPid.' })';
    $process = proc_open(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script],
        [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        throw new RuntimeException('could not inspect the relay process tree');
    }
    $json = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('the relay process inspection failed');
    }

    return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
}

it('ends a blocked Windows stdin relay when its host is hard killed while input stays open', function () {
    $ready = sys_get_temp_dir().'/prism-acp-relay-'.bin2hex(random_bytes(6));
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/fixtures/stdin-relay-host.php', $ready],
        [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes,
        null, null, ['bypass_shell' => true]);
    expect(is_resource($process))->toBeTrue();
    $hostPid = proc_get_status($process)['pid'];
    $pids = [];
    try {
        $deadline = microtime(true) + 20;
        while (! is_file($ready) && microtime(true) < $deadline) {
            usleep(50_000);
        }
        expect(is_file($ready))->toBeTrue();
        $pids = relayDescendants($hostPid);
        expect($pids)->not->toBeEmpty();
        // Do not close $pipes[0]: the relay is blocked on a real pipe whose writer is still alive.
        expect(proc_terminate($process))->toBeTrue();
        $deadline = microtime(true) + 5;
        do {
            $alive = array_values(array_filter($pids, function (int $pid): bool {
                $check = proc_open(['tasklist.exe', '/FI', 'PID eq '.$pid, '/NH', '/FO', 'CSV'],
                    [1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']], $out, null, null, ['bypass_shell' => true]);
                $csv = (string) stream_get_contents($out[1]);
                fclose($out[1]);
                proc_close($check);

                return str_contains($csv, '"'.$pid.'"');
            }));
            if ($alive !== []) {
                usleep(100_000);
            }
        } while ($alive !== [] && microtime(true) < $deadline);
        expect($alive)->toBe([]);
    } finally {
        // A failing negative control must not leave its deliberately escaped helper behind.
        foreach ([$hostPid, ...$pids] as $pid) {
            $cleanup = proc_open(['taskkill.exe', '/F', '/PID', (string) $pid],
                [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $unused, null, null, ['bypass_shell' => true]);
            if (is_resource($cleanup)) {
                proc_close($cleanup);
            }
        }
        fclose($pipes[0]);
        proc_close($process);
        @unlink($ready);
    }
})->skip(PHP_OS_FAMILY !== 'Windows', 'the Windows stdin relay');
