<?php

declare(strict_types=1);

// Native auth-status uses pretty JSON rather than ClaudeDriver's stream-json. Run that READ-ONLY command inside
// the driver's existing Job Object, inheriting its exact child environment. No prompt or model turn is sent.
$process = proc_open([$argv[1], 'auth', 'status'], [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']], $pipes,
    options: ['bypass_shell' => true]);
if (! is_resource($process)) {
    exit(2);
}
$text = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$code = proc_close($process);
$status = json_decode((string) $text, true);
file_put_contents($argv[2], json_encode(['code' => $code, 'authMethod' => $status['authMethod'] ?? null,
    'configDirectory' => $status['configDirectory'] ?? null, 'loggedIn' => $status['loggedIn'] ?? null]));
// No native status or key is sent through ACP. This is a no-turn transport/credential probe.
exit($code);
