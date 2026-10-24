<?php

/*
 * prism-acp's stdin relay (Windows): stream_select cannot wait on a pipe there, so this process holds the agent
 * host's stdin and forwards every byte to stdout, which the Job Object supervisor relays over its authenticated
 * socket. The supervisor owns this process, including when fread is blocked and the host dies.
 */

while (! feof(STDIN)) {
    $chunk = fread(STDIN, 65536);
    if ($chunk === false) {
        break;
    }
    if ($chunk === '') {
        continue;
    }
    while ($chunk !== '') {
        $written = @fwrite(STDOUT, $chunk);
        if ($written === false || $written === 0) {
            exit(0);
        }
        $chunk = substr($chunk, $written);
    }
    fflush(STDOUT);
}
