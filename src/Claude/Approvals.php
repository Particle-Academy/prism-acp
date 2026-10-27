<?php

declare(strict_types=1);

namespace Prism\Acp\Claude;

use Prism\Acp\Agent\PermissionOutcome;
use Prism\Acp\Process\ContainmentUnavailable;
use Prism\Acp\Process\WindowsCommand;
use Prism\Acp\Process\WindowsSupervisorBuild;
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
        // Only a validated explicit allow exits42, consumed by the native guard. Never install this script directly.
        $block = function (string $why): never {
            fwrite(STDOUT, json_encode(['hookSpecificOutput' => ['hookEventName' => 'PreToolUse', 'permissionDecision' => 'deny', 'permissionDecisionReason' => $why]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            fwrite(STDERR, "prism-acp: approval hook failed or denied; tool call blocked\n");
            exit(2);
        };
        $address = getenv('PRISM_ACP_APPROVAL_SOCKET');
        $token = getenv('PRISM_ACP_APPROVAL_TOKEN');
        if (! is_string($address) || $address === '' || ! is_string($token) || $token === '') {
            $block('no approval channel; the tool call is blocked');
        }
        $body = stream_get_contents(STDIN, 1_000_001);
        if ($body === false || strlen($body) > 1_000_000 || ! json_decode($body) instanceof stdClass) {
            $block('unreadable hook input; the tool call is blocked');
        }
        $socket = @stream_socket_client($address, $errno, $errstr, 10);
        if ($socket === false) {
            $block('the approval channel is unreachable; the tool call is blocked');
        }
        stream_set_timeout($socket, 86_400 + 60);
        $request = $token."\n".$body;
        while ($request !== '') {
            $written = @fwrite($socket, $request);
            if ($written === false || $written === 0) {
                $block('the approval channel is unreachable; the tool call is blocked');
            }
            $request = substr($request, $written);
        }
        stream_socket_shutdown($socket, STREAM_SHUT_WR);
        $answer = stream_get_contents($socket, 1_000_001);
        $meta = stream_get_meta_data($socket);
        fclose($socket);
        if ($answer === false || strlen($answer) > 1_000_000 || ($meta['timed_out'] ?? false)) {
            $block('unreadable approval response; the tool call is blocked');
        }
        $newline = strpos($answer, "\n");
        $status = $newline === false ? $answer : substr($answer, 0, $newline);
        if ($newline === false || $status !== '200') {
            $block('the approval channel refused; the tool call is blocked');
        }
        $parsed = json_decode((string) substr($answer, $newline + 1));
        $output = $parsed instanceof stdClass ? ($parsed->hookSpecificOutput ?? null) : null;
        if (! $parsed instanceof stdClass || count(get_object_vars($parsed)) !== 1 || ! $output instanceof stdClass ||
            count(get_object_vars($output)) !== 3 || ($output->hookEventName ?? null) !== 'PreToolUse' ||
            ! is_string($output->permissionDecisionReason ?? null) || ! in_array($output->permissionDecision ?? null, ['allow', 'deny'], true)) {
            $block('unreadable answer; the tool call is blocked');
        }
        $bytes = json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        while ($bytes !== '') {
            $written = @fwrite(STDOUT, $bytes);
            if ($written === false || $written === 0) {
                exit(2);
            }
            $bytes = substr($bytes, $written);
        }
        exit($output->permissionDecision === 'allow' ? 42 : 2);
        PHP;

    /** onFailure:block needs stable Claude2.1.295 or later; no opaque/prerelease version claims. */
    public static function cliVersion(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/', $value, $parts) !== 1) {
            return null;
        }
        foreach (array_slice($parts, 1) as $part) {
            if (strlen($part) > 16 || (strlen($part) === 16 && strcmp($part, '9007199254740991') > 0)) {
                return null;
            }
        }
        [$major, $minor, $patch] = array_map(intval(...), array_slice($parts, 1));

        return $major > 2 || ($major === 2 && ($minor > 1 || ($minor === 1 && $patch >= 295))) ? $value : null;
    }

    public static function waitSeconds(int $seconds): int
    {
        if ($seconds < 1 || $seconds > self::WAIT_SECONDS) {
            throw new \InvalidArgumentException('permission prompt timeout must be from 1 to 86400 seconds');
        }

        return $seconds;
    }

    /**
     * The `--settings` for this invocation: one exec-form native PreToolUse guard for the named tools.
     * The verified PHP CLI and hook path are separate arguments, including paths with spaces on Windows.
     *
     * @param  list<string>  $tools
     * @return array<string, mixed>
     */
    public static function settings(string $phpBinary, string $hookPath, array $tools, int $timeoutSeconds = self::WAIT_SECONDS): array
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
        $wait = self::waitSeconds($timeoutSeconds);
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new ContainmentUnavailable('Claude permission prompts require the native Windows approval adapter in this release');
        }
        foreach ([$phpBinary, $hookPath] as $path) {
            if ($path === '' || preg_match('/[\x00\r\n]/', $path) === 1 || ! WindowsCommand::isAbsolute($path)) {
                throw new \InvalidArgumentException('permission hook paths must be absolute single-line paths');
            }
        }
        $adapter = WindowsSupervisorBuild::executable();

        return ['hooks' => ['PreToolUse' => [[
            // Claude's empty matcher matches every tool, including tools introduced after this release.
            // Only this explicit sentinel selects it; tool names can never inject a regular expression.
            'matcher' => $tools === ['*'] ? '' : implode('|', $tools),
            'hooks' => [['type' => 'command', 'command' => $adapter, 'args' => ['approval', $phpBinary, $hookPath, (string) $wait], 'timeout' => $wait + 5, 'onFailure' => 'block']],
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
