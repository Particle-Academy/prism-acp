<?php

use Prism\Acp\Agent\Stdio;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$input = Stdio::stdin();
file_put_contents($argv[1], (string) getmypid());
while (true) {
    usleep(100_000);
}
