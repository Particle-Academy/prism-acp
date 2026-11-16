<?php

// A provider stand-in with a process tree to end, POSIX only: the PHP twin of process-tree.cjs. Level 0 (the "provider")
// echoes stdin to stdout and starts level 1, which starts level 2; level 2 calls setsid() first, leaving the provider's
// process group and session, the way a tool subprocess that wants to outlive its parent would. Every level appends its
// pid to the file in argv[1].
//   ORPHAN=1        level 1 exits once level 2 is up, orphaning it (no parent chain reaches it any more)
//   CLEAR_ENV=1     level 2 starts with an EMPTY environment, so it carries no tree cookie (the G-92 case)
//   STAY_IN_GROUP=1 level 2 does NOT call setsid (the only case macOS containment holds)
//   EXIT_ON_EOF=1   level 0 exits with code 7 when its stdin closes

declare(strict_types=1);

[$file, $level] = [$argv[1], (int) ($argv[2] ?? '0')];

if ($level === 2 && getenv('STAY_IN_GROUP') !== '1') {
    posix_setsid();
}
$keep = null;
if ($level < 2) {
    $env = $level === 1 && getenv('CLEAR_ENV') === '1' ? [] : null;
    $keep = proc_open([PHP_BINARY, __FILE__, $file, (string) ($level + 1)],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
}
file_put_contents($file, getmypid()."\n", FILE_APPEND | LOCK_EX);

if ($level === 1 && getenv('ORPHAN') === '1') {
    // Wait for level 2's pid, then leave it parentless.
    $end = microtime(true) + 10;
    while (microtime(true) < $end && count(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) < 3) {
        usleep(20_000);
    }
    exit(0);
}
if ($level === 0) {
    while (($line = fgets(STDIN)) !== false) {
        fwrite(STDOUT, 'echo:'.$line);
        fflush(STDOUT);
    }
    if (getenv('EXIT_ON_EOF') === '1') {
        exit(7);
    }
}
while (true) {
    sleep(60);
}
