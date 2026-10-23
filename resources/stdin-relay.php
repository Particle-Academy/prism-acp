<?php

/*
 * prism-acp's stdin relay (Windows): stream_select cannot wait on a pipe there, so this process holds the agent
 * host's stdin and forwards every byte over the loopback socket named in PRISM_ACP_RELAY, after the token in
 * PRISM_ACP_RELAY_TOKEN. It exits when stdin ends (closing the socket, which the host reads as the client leaving)
 * or when the host closes the socket.
 */

$address = getenv('PRISM_ACP_RELAY');
$token = getenv('PRISM_ACP_RELAY_TOKEN');
if (! is_string($address) || ! is_string($token) || $address === '' || $token === '') {
    exit(2);
}
$socket = @stream_socket_client($address, $errno, $errstr, 10);
if ($socket === false) {
    exit(2);
}
if (@fwrite($socket, $token."\n") === false) {
    exit(2);
}
while (! feof(STDIN)) {
    $chunk = fread(STDIN, 65536);
    if ($chunk === false) {
        break;
    }
    if ($chunk === '') {
        continue;
    }
    while ($chunk !== '') {
        $written = @fwrite($socket, $chunk);
        if ($written === false || $written === 0) {
            exit(0);
        }
        $chunk = substr($chunk, $written);
    }
}
stream_socket_shutdown($socket, STREAM_SHUT_WR);
fclose($socket);
