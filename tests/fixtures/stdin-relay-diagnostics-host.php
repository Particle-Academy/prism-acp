<?php

use Prism\Acp\Agent\Stdio;
use Prism\Acp\Process\WindowsProviderProcess;

// Load an unchanged copy of the production launcher beside an instrumented relay resource. Other classes use
// the ordinary package autoloader, so the real Job Object supervisor and pipe/socket path are exercised.
require $argv[1].'/src/Agent/Stdio.php';
require dirname(__DIR__, 2).'/vendor/autoload.php';

Stdio::stdin();
// The public surface exposes only input. Inspect its private owner here so this fixture can also retain stderr
// and prove the diagnostic actually happened, rather than passing because the error was disabled everywhere.
$relay = (new ReflectionProperty(Stdio::class, 'inputRelay'))->getValue();
if (! $relay instanceof WindowsProviderProcess) {
    throw new RuntimeException('the fixture did not start the real Windows stdin relay');
}
$deadline = microtime(true) + 15;
try {
    while ($relay->isOpen() && microtime(true) < $deadline) {
        foreach ($relay->read(0.05) as [$kind, $bytes]) {
            if ($kind !== 'stdout' && $kind !== 'stderr') {
                continue;
            }
            $output = $kind === 'stdout' ? STDOUT : STDERR;
            while ($bytes !== '') {
                $written = fwrite($output, $bytes);
                if ($written === false || $written === 0) {
                    throw new RuntimeException('the fixture output closed');
                }
                $bytes = substr($bytes, $written);
            }
        }
    }
    if ($relay->isOpen()) {
        throw new RuntimeException('the relay did not close after input EOF');
    }
} finally {
    $verdict = $relay->stop(1000);
    if (! $verdict->verified || ! $verdict->empty || $verdict->survivors !== 0) {
        throw new RuntimeException('the fixture relay tree did not empty');
    }
}
