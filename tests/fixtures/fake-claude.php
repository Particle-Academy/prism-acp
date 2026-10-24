<?php

/*
 * A stand-in for `claude --print --input-format stream-json --output-format stream-json`, for ClaudeDriverTest. It
 * speaks the frames the real CLI does (shapes from the captures in claude-to-acp/corpus) and records what it was
 * given, so a test can ask what reached the CLI rather than what the driver meant to send.
 *
 * FAKE_CLAUDE_LOG names a file: the first line is its argv and environment, then one line per stdin frame.
 * A prompt's text chooses the turn: "slow" waits for an interrupt, "fail" fails on a rate limit, "hook <tool>" runs
 * the PreToolUse hook from --settings as Claude would and reports what it answered, "exit" exits 3 mid-turn,
 * "file" reports the --mcp-config file's contents; anything else is echoed.
 */

$log = getenv('FAKE_CLAUDE_LOG') ?: null;
$record = function (mixed $entry) use ($log): void {
    if ($log !== null) {
        file_put_contents($log, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
    }
};
$argv = array_slice($_SERVER['argv'], 1);
$env = getenv();
$record(['argv' => $argv, 'env' => array_keys($env)]);

$emit = function (array $frame): void {
    fwrite(STDOUT, json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    fflush(STDOUT);
};
$option = function (string $flag) use ($argv): ?string {
    $at = array_search($flag, $argv, true);

    return $at === false ? null : ($argv[$at + 1] ?? null);
};

$session = '9adbc891-a558-48e0-93b0-460d6500ead8';
$started = false;
$turn = 0;
$waitingForInterrupt = false;

$text = function (int $turn, string $said) use ($emit, $session): void {
    $id = "msg_fake_{$turn}";
    $emit(['type' => 'stream_event', 'event' => ['type' => 'message_start', 'message' => ['id' => $id, 'usage' => ['input_tokens' => 10, 'cache_read_input_tokens' => 100, 'cache_creation_input_tokens' => 5, 'output_tokens' => 1]]], 'parent_tool_use_id' => null, 'session_id' => $session]);
    $emit(['type' => 'stream_event', 'event' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']], 'parent_tool_use_id' => null, 'session_id' => $session]);
    $emit(['type' => 'stream_event', 'event' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $said]], 'parent_tool_use_id' => null, 'session_id' => $session]);
    $emit(['type' => 'stream_event', 'event' => ['type' => 'content_block_stop', 'index' => 0], 'parent_tool_use_id' => null, 'session_id' => $session]);
    $emit(['type' => 'stream_event', 'event' => ['type' => 'message_delta', 'usage' => ['input_tokens' => 10, 'cache_creation_input_tokens' => 5, 'cache_read_input_tokens' => 100, 'output_tokens' => 7]], 'parent_tool_use_id' => null, 'session_id' => $session]);
};
$result = function (array $extra = []) use ($emit, $session): void {
    $emit([
        'type' => 'result', 'subtype' => 'success', 'is_error' => false, 'stop_reason' => 'end_turn', 'session_id' => $session,
        'total_cost_usd' => 0.01, 'usage' => ['input_tokens' => 10, 'output_tokens' => 7],
        'modelUsage' => ['claude-fake' => ['contextWindow' => 200000]], ...$extra,
    ]);
};

while (($line = fgets(STDIN)) !== false) {
    $frame = json_decode($line, true);
    $record(['stdin' => $frame]);
    if (! is_array($frame)) {
        continue;
    }

    if (($frame['type'] ?? null) === 'control_request') {
        $request = $frame['request'] ?? [];
        $subtype = $request['subtype'] ?? null;
        if ($subtype === 'initialize') {
            $emit(['type' => 'control_response', 'response' => ['subtype' => 'success', 'request_id' => $frame['request_id'], 'response' => ['models' => [
                ['value' => 'default', 'resolvedModel' => 'claude-opus-5-5', 'displayName' => "Default \e[1m(recommended)", 'description' => 'Opus'],
                ['value' => 'haiku', 'displayName' => 'Haiku', 'description' => ' fast '],
                ['value' => '--dangerously-skip-permissions', 'displayName' => 'not a model'],
            ]]]]);
        } elseif ($subtype === 'set_model') {
            $ok = ($request['model'] ?? '') !== 'refuse-me';
            $emit(['type' => 'control_response', 'response' => ['subtype' => $ok ? 'success' : 'error', 'request_id' => $frame['request_id'], 'error' => $ok ? null : 'no such model']]);
        } elseif ($subtype === 'interrupt' && $waitingForInterrupt) {
            $waitingForInterrupt = false;
            $emit(['type' => 'result', 'subtype' => 'error_during_execution', 'stop_reason' => null, 'terminal_reason' => 'aborted_streaming', 'is_error' => true, 'session_id' => $session]);
        }

        continue;
    }

    if (($frame['type'] ?? null) !== 'user') {
        continue;
    }
    $turn++;
    if (! $started) {
        $started = true;
        $emit(['type' => 'system', 'subtype' => 'init', 'session_id' => $session, 'model' => $option('--model') ?? 'claude-fake', 'slash_commands' => ['/compact'], 'skills' => []]);
    }
    $content = $frame['message']['content'] ?? [];
    $said = implode(' ', array_map(fn ($block) => $block['text'] ?? ('['.($block['type'] ?? '?').']'), is_array($content) ? $content : []));

    if ($said === 'slow') {
        $waitingForInterrupt = true;
        $text($turn, 'working');

        continue;
    }
    if ($said === 'exit') {
        $text($turn, 'about to go');
        exit(3);
    }
    if ($said === 'fail') {
        $emit(['type' => 'assistant', 'parent_tool_use_id' => null, 'error' => 'rate_limit', 'message' => ['id' => "msg_fake_{$turn}", 'content' => []]]);
        $emit(['type' => 'rate_limit_event', 'rate_limit_info' => ['status' => 'rejected', 'resetsAt' => 1800000000, 'rateLimitType' => 'five_hour', 'unifiedWindows' => ['five_hour' => ['utilization' => 1, 'resetsAt' => 1800000000]]]]);
        $emit(['type' => 'result', 'subtype' => 'error_during_execution', 'is_error' => true, 'stop_reason' => null, 'session_id' => $session]);

        continue;
    }
    if (str_starts_with($said, 'hook ')) {
        $settings = json_decode((string) file_get_contents((string) $option('--settings')), true);
        $hook = $settings['hooks']['PreToolUse'][0] ?? null;
        $tool = substr($said, 5);
        if ($hook === null || ($hook['matcher'] !== '' && preg_match('/^(?:'.$hook['matcher'].')$/', $tool) !== 1)) {
            $text($turn, "no hook for {$tool}");
            $result();

            continue;
        }
        // As Claude runs a command hook: through a shell, the hook input on stdin.
        $process = proc_open($hook['hooks'][0]['command'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode(['session_id' => $session, 'tool_name' => $tool, 'tool_input' => ['command' => 'rm -rf /tmp/x'], 'tool_use_id' => "toolu_{$turn}", 'cwd' => getcwd()]));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($process);
        $text($turn, json_encode(['exit' => $code, 'stdout' => json_decode((string) $out, true), 'stderr' => trim((string) $err)], JSON_UNESCAPED_SLASHES));
        $result();

        continue;
    }
    if ($said === 'file') {
        $text($turn, (string) file_get_contents((string) $option('--mcp-config')));
        $result();

        continue;
    }
    $text($turn, "echo: {$said}");
    $result();
}
