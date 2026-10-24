<?php

declare(strict_types=1);

namespace Prism\Acp\Claude;

use Prism\Acp\Agent\PermissionOutcome;
use Prism\Acp\Support\Js;

/**
 * PERMISSION PROMPTS FOR CLAUDE: a person approves a tool call before it runs. Claude Code's documented route in `-p`
 * mode is a PreToolUse command hook; this supplies one for a single invocation (`--settings`), a private channel the
 * hook hands each call to (ApprovalServer), and the mapping both ways. Ported from prism-acp-ts src/claude/approvals.ts.
 *
 * FAILS CLOSED. Only an explicit "allow once" allows. A cancelled request, an unknown option, no handler, a wrong
 * token, no channel, a malformed answer: deny, or exit 2, which blocks. The hook is also marked `onFailure: "block"`.
 *
 * The hook is a PHP script run by the PHP CLI, where the reference's is a Node script: this package does not assume a
 * Node runtime. Its channel is a loopback TCP socket on Windows (PHP has no named-pipe server) and a unix socket in
 * the private directory elsewhere; either way it carries a per-session 256-bit token, compared in constant time.
 */
final class Approvals
{
    /** How long a call may wait for a person, in seconds: a person may be away. */
    public const WAIT_SECONDS = 86_400;

    /**
     * The hook Claude runs: its stdin is the hook input; it sends the token and that input over the channel named in
     * PRISM_ACP_APPROVAL_SOCKET, prints the answer, and EXITS 2 (block) on anything else.
     */
    public const HOOK_SCRIPT = <<<'PHP'
        <?php
        // prism-acp permission hook. Anything but a readable allow or deny from the channel blocks the tool call.
        $block = function (string $why): never {
            fwrite(STDERR, 'prism-acp: '.$why."\n");
            exit(2);
        };
        $address = getenv('PRISM_ACP_APPROVAL_SOCKET');
        $token = getenv('PRISM_ACP_APPROVAL_TOKEN');
        if (! is_string($address) || $address === '' || ! is_string($token) || $token === '') {
            $block('no approval channel; the tool call is blocked');
        }
        $body = (string) stream_get_contents(STDIN);
        $socket = @stream_socket_client($address, $errno, $errstr, 10);
        if ($socket === false) {
            $block('the approval channel is unreachable; the tool call is blocked');
        }
        stream_set_timeout($socket, 86_400 + 60);
        if (@fwrite($socket, $token."\n".$body) === false) {
            $block('the approval channel is unreachable; the tool call is blocked');
        }
        stream_socket_shutdown($socket, STREAM_SHUT_WR);
        $answer = (string) stream_get_contents($socket);
        $newline = strpos($answer, "\n");
        $status = $newline === false ? $answer : substr($answer, 0, $newline);
        if ($status !== '200') {
            $block('the approval channel refused ('.($status === '' ? 'no answer' : substr($status, 0, 3)).'); the tool call is blocked');
        }
        $parsed = json_decode((string) substr($answer, $newline + 1));
        $decision = is_object($parsed) && isset($parsed->hookSpecificOutput->permissionDecision) ? $parsed->hookSpecificOutput->permissionDecision : null;
        if ($decision !== 'allow' && $decision !== 'deny') {
            $block('unreadable answer; the tool call is blocked');
        }
        echo json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit(0);
        PHP;

    /**
     * The `--settings` for this invocation: one PreToolUse command hook for the named tools. The command is the PHP CLI
     * on the written hook script, both quoted (paths with spaces on Windows).
     *
     * @param  list<string>  $tools
     * @return array<string, mixed>
     */
    public static function settings(string $phpBinary, string $hookPath, array $tools): array
    {
        if ($tools === []) {
            throw new \InvalidArgumentException('permission prompts need at least one tool name');
        }
        foreach ($tools === ['*'] ? [] : $tools as $tool) {
            // A tool name that could widen the hook's matcher (a regular expression) is refused.
            if (preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/', $tool) !== 1) {
                throw new \InvalidArgumentException('refusing tool name '.json_encode($tool).': a tool name is a single identifier');
            }
        }

        return ['hooks' => ['PreToolUse' => [[
            // Claude's empty matcher matches every tool, including tools introduced after this release.
            // Only this explicit sentinel selects it; tool names can never inject a regular expression.
            'matcher' => $tools === ['*'] ? '' : implode('|', $tools),
            'hooks' => [['type' => 'command', 'command' => "\"{$phpBinary}\" \"{$hookPath}\"", 'timeout' => self::WAIT_SECONDS, 'onFailure' => 'block']],
        ]]]];
    }

    /**
     * The request a client sees: the same shape CodexDriver raises, with Allow once and Deny.
     *
     * @return array<string, mixed>
     */
    public static function permissionRequestFor(\stdClass $input): array
    {
        $tool = Js::string($input, 'tool_name') ?? 'Tool';
        $args = Js::get($input, 'tool_input');
        $summary = Js::string($args, 'command') ?? Js::string($args, 'file_path') ?? Js::string($args, 'url') ?? Js::string($args, 'description') ?? '';
        $rawInput = Js::get($input, 'tool_input');

        return [
            'toolCall' => [
                'toolCallId' => Js::string($input, 'tool_use_id') ?? 'claude-permission-'.bin2hex(random_bytes(4)),
                'title' => $summary !== '' ? "{$tool}: {$summary}" : $tool,
                'rawInput' => $rawInput,
            ],
            'options' => [
                ['optionId' => 'allow_once', 'name' => 'Allow once', 'kind' => 'allow_once'],
                ['optionId' => 'reject_once', 'name' => 'Deny', 'kind' => 'reject_once'],
            ],
        ];
    }

    /**
     * The hook's answer: allow ONLY for an explicit "allow once". The client's own reason when it gave one.
     *
     * @return array{hookEventName: 'PreToolUse', permissionDecision: 'allow'|'deny', permissionDecisionReason: string}
     */
    public static function decisionFor(PermissionOutcome $outcome): array
    {
        if ($outcome->allowsOnce()) {
            return ['hookEventName' => 'PreToolUse', 'permissionDecision' => 'allow', 'permissionDecisionReason' => $outcome->reason ?? 'Allowed by the person.'];
        }
        $fallback = $outcome->outcome === 'cancelled' ? 'The request was cancelled.' : 'The person denied this tool call.';

        return ['hookEventName' => 'PreToolUse', 'permissionDecision' => 'deny', 'permissionDecisionReason' => $outcome->reason ?? $fallback];
    }
}
