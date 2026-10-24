<?php

/* Synthetic app-server peer, run under the real supervisor. No provider or network inference. */
$log = getenv('FAKE_CODEX_LOG') ?: null;
$record = static function (mixed $entry) use ($log): void {
    if ($log !== null) {
        file_put_contents($log, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
    }
};
$record(['argv' => array_slice($_SERVER['argv'], 1), 'env' => array_keys(getenv()), 'pid' => getmypid()]);
$emit = static function (array $frame): void {
    fwrite(STDOUT, json_encode($frame, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    fflush(STDOUT);
};
if (getenv('FAKE_CODEX_MODE') === 'framing') {
    $bytes = json_encode(['jsonrpc' => '2.0', 'method' => 'fixture', 'params' => ['text' => 'π😀']], JSON_UNESCAPED_UNICODE)."\n";
    foreach (str_split($bytes) as $byte) {
        fwrite(STDOUT, $byte);
        fflush(STDOUT);
    }
    fwrite(STDOUT, "private malformed bearer value\n");
    fwrite(STDERR, "private stderr bearer value\n");
    exit(0);
}
$thread = 'thread-process-fixture';
$sequence = 0;
$turn = null;
$approval = null;
$finish = static function (string $text, string $status = 'completed') use ($emit, &$turn, &$thread): void {
    $emit(['jsonrpc' => '2.0', 'method' => 'item/completed', 'params' => ['threadId' => $thread, 'turnId' => $turn,
        'item' => ['id' => 'message-'.$turn, 'type' => 'agentMessage', 'text' => $text]]]);
    $emit(['jsonrpc' => '2.0', 'method' => 'turn/completed', 'params' => ['threadId' => $thread, 'turn' => ['id' => $turn, 'status' => $status]]]);
};
while (($line = fgets(STDIN)) !== false) {
    $frame = json_decode($line, true);
    $record(['stdin' => $frame]);
    if (! is_array($frame)) {
        continue;
    }
    if ($approval !== null && ($frame['id'] ?? null) === $approval && ! isset($frame['method'])) {
        $finish('decision: '.($frame['result']['decision'] ?? 'missing'));
        $approval = null;

        continue;
    }
    $method = $frame['method'] ?? null;
    if (! array_key_exists('id', $frame)) {
        continue;
    }
    $result = new stdClass;
    switch ($method) {
        case 'thread/start':
        case 'thread/resume':
            $thread = $frame['params']['threadId'] ?? $thread;
            $result = ['thread' => ['id' => $thread], 'model' => 'fixture-model'];
            break;
        case 'thread/turns/list':
            $result = ['data' => [['id' => 'old-turn']], 'nextCursor' => null];
            break;
        case 'thread/items/list':
            $result = ['data' => [['item' => ['id' => 'old-message', 'type' => 'agentMessage', 'text' => 'replayed history']]], 'nextCursor' => null];
            break;
        case 'model/list':
            $second = ($frame['params']['cursor'] ?? null) === 'next-page';
            $result = ['data' => [['id' => $second ? 'second' : 'fixture-model', 'model' => $second ? 'second' : 'fixture-model', 'displayName' => $second ? 'Second' : 'Fixture', 'isDefault' => ! $second]], 'nextCursor' => $second ? null : 'next-page'];
            break;
        case 'account/rateLimits/read':
            $result = ['rateLimits' => ['primary' => ['usedPercent' => 10, 'windowDurationMins' => 300, 'resetsAt' => 1800000000], 'secondary' => null]];
            break;
        case 'turn/start':
            $turn = 'turn-process-'.(++$sequence);
            foreach ($frame['params']['input'] ?? [] as $block) {
                if (($block['type'] ?? null) === 'localImage') {
                    $record(['image' => ['path' => $block['path'], 'bytes' => base64_encode((string) file_get_contents($block['path']))]]);
                }
            }
            $result = ['turn' => ['id' => $turn]];
            break;
        case 'config/read':
            $result = ['config' => new stdClass];
            break;
    }
    $emit(['jsonrpc' => '2.0', 'id' => $frame['id'], 'result' => $result]);
    if ($method === 'turn/start') {
        $text = implode(' ', array_column($frame['params']['input'] ?? [], 'text'));
        $emit(['jsonrpc' => '2.0', 'method' => 'turn/started', 'params' => ['threadId' => $thread, 'turn' => ['id' => $turn]]]);
        if ($text === 'slow') {
            $emit(['jsonrpc' => '2.0', 'method' => 'item/agentMessage/delta', 'params' => ['threadId' => $thread, 'turnId' => $turn, 'itemId' => 'working', 'delta' => 'working']]);
        } elseif ($text === 'approval') {
            $approval = 'native-approval-'.$sequence;
            $emit(['jsonrpc' => '2.0', 'id' => $approval, 'method' => 'item/commandExecution/requestApproval', 'params' => [
                'threadId' => $thread, 'turnId' => $turn, 'itemId' => 'command-'.$sequence, 'command' => 'fixture command', 'cwd' => getcwd()]]);
        } elseif ($text === 'exit') {
            exit(3);
        } else {
            $finish('echo: '.$text);
        }
    } elseif ($method === 'turn/interrupt') {
        $finish('cancelled', 'interrupted');
    }
}
