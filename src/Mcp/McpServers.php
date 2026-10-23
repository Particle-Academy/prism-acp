<?php

declare(strict_types=1);

namespace Prism\Acp\Mcp;

use Prism\Acp\JsonRpc\RpcError;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Support\Js;
use Prism\Acp\Support\Undefined;

/**
 * A session's MCP servers, as the ACP client supplies them, with explicit credentials. Ported from prism-acp-ts
 * src/mcp.ts. The whole list is validated before any child starts, and a bad entry refuses the list rather than
 * being dropped. Diagnostics name the field, never a URL, header, argument or credential.
 *
 * A server is an array: ['name', 'command', 'args' => list<string>, 'env' => list<NamedValue>] for stdio, or
 * ['type' => 'http'|'sse', 'name', 'url', 'headers' => list<NamedValue>]. A named value is ['name', 'value'].
 *
 * @phpstan-type NamedValue array{name: string, value: string}
 * @phpstan-type StdioServer array{name: string, command: string, args: list<string>, env: list<NamedValue>}
 * @phpstan-type HttpServer array{type: 'http'|'sse', name: string, url: string, headers: list<NamedValue>}
 * @phpstan-type Server StdioServer|HttpServer
 */
final class McpServers
{
    /** Codex's documented per-server tool approval modes (`mcp_servers.<id>.default_tools_approval_mode`). */
    public const TOOL_APPROVALS = ['auto', 'prompt', 'writes', 'approve'];

    /**
     * Validate the entire list. Absent (Undefined) is no servers, for callers that omitted the field; an explicit
     * null is not a list and is refused, as it is there.
     *
     * @return list<Server>
     */
    public static function read(mixed $value): array
    {
        if ($value === Undefined::Value) {
            return [];
        }
        if (! is_array($value)) {
            self::invalid('must be an array');
        }
        $names = [];
        $servers = [];
        foreach (array_values($value) as $index => $entry) {
            $prefix = "entry {$index}";
            if (! $entry instanceof \stdClass) {
                self::invalid("{$prefix} must be an object");
            }
            $name = self::text(Js::get($entry, 'name'), "{$prefix}.name", false);
            if (isset($names[$name])) {
                self::invalid("{$prefix} has a duplicate server name");
            }
            $names[$name] = true;
            $type = Js::get($entry, 'type');
            if ($type === 'http' || $type === 'sse') {
                $url = self::text(Js::get($entry, 'url'), "{$prefix}.url", false);
                if (! self::isHttpUrl($url)) {
                    // The reference's "must use HTTP or HTTPS" is thrown inside its own try and always replaced by this.
                    self::invalid("{$prefix}.url must be an absolute HTTP or HTTPS URL");
                }
                $servers[] = ['type' => $type, 'name' => $name, 'url' => $url, 'headers' => self::pairs(Js::get($entry, 'headers'), "{$prefix}.headers", true)];

                continue;
            }
            if (Js::has($entry, 'type') && $type !== 'stdio') {
                self::invalid("{$prefix} has an unsupported transport");
            }
            $command = self::text(Js::get($entry, 'command'), "{$prefix}.command", false);
            if (! str_starts_with($command, '/') && preg_match('#\A[A-Za-z]:[\\\\/]#', $command) !== 1) {
                self::invalid("{$prefix}.command must be an absolute path");
            }
            $args = Js::get($entry, 'args');
            if (! is_array($args)) {
                self::invalid("{$prefix}.args must be an array");
            }
            $servers[] = [
                'name' => $name,
                'command' => $command,
                'args' => array_map(fn (mixed $arg): string => self::text($arg, "{$prefix}.args", true), array_values($args)),
                'env' => self::pairs(Js::get($entry, 'env'), "{$prefix}.env", false),
            ];
        }

        return $servers;
    }

    /**
     * @param  list<Server>  $servers
     * @param  array{http: bool, sse: bool}  $capabilities
     */
    public static function assertSupport(array $servers, array $capabilities): void
    {
        foreach ($servers as $server) {
            $type = $server['type'] ?? 'stdio';
            if ($type === 'http' && ! $capabilities['http']) {
                self::invalid('HTTP transport is not supported by this driver');
            }
            if ($type === 'sse' && ! $capabilities['sse']) {
                self::invalid('SSE transport is not supported by this driver');
            }
        }
    }

    /** @param list<Server> $servers */
    public static function claudeConfig(array $servers): \stdClass
    {
        $entries = new \stdClass;
        foreach ($servers as $server) {
            $entries->{$server['name']} = isset($server['command'])
                ? (object) ['type' => 'stdio', 'command' => $server['command'], 'args' => $server['args'], 'env' => self::namedValues($server['env'])]
                : (object) ['type' => $server['type'], 'url' => $server['url'], 'headers' => self::namedValues($server['headers'])];
        }

        return (object) ['mcpServers' => Json::ordered($entries)];
    }

    /**
     * App Server thread/start and thread/resume config overrides. `approval` names servers in the list only, and only
     * Codex's documented modes: anything else is refused, loudly, rather than dropped.
     *
     * @param  list<Server>  $servers
     * @param  array<string, string>  $approval
     */
    public static function codexConfig(array $servers, array $approval = []): \stdClass
    {
        self::assertSupport($servers, ['http' => true, 'sse' => false]);
        $names = array_column($servers, 'name');
        foreach ($approval as $name => $mode) {
            $name = (string) $name;
            if (! in_array($name, $names, true)) {
                self::invalid('mcpToolApproval names '.Json::encode($name).', which is not one of the MCP servers given');
            }
            if (! in_array($mode, self::TOOL_APPROVALS, true)) {
                self::invalid('mcpToolApproval for '.Json::encode($name).' is '.Json::encode($mode).'; Codex documents auto, prompt, writes and approve');
            }
        }
        $entries = new \stdClass;
        foreach ($servers as $server) {
            $entry = isset($server['command'])
                ? ['command' => $server['command'], 'args' => $server['args'], 'env' => self::namedValues($server['env'])]
                : ['url' => $server['url'], 'http_headers' => self::namedValues($server['headers'])];
            if (array_key_exists($server['name'], $approval)) {
                $entry['default_tools_approval_mode'] = $approval[$server['name']];
            }
            $entries->{$server['name']} = (object) $entry;
        }

        return (object) ['mcp_servers' => Json::ordered($entries)];
    }

    /**
     * Whether a URL is absolute HTTP(S) as the WHATWG URL parser reads it: leading and trailing C0 controls and spaces
     * removed, the scheme matched without case, and a non-empty host made of no forbidden code point. This is the
     * subset of that parser a session's MCP URL can reach; where the two could differ it refuses.
     */
    private static function isHttpUrl(string $url): bool
    {
        $url = (string) preg_replace('/\A[\x00-\x20]+|[\x00-\x20]+\z/', '', $url);
        $url = (string) preg_replace('/[\t\n\r]/', '', $url);
        if (preg_match('#\A(https?):(.*)\z#is', $url, $m) !== 1) {
            return false;
        }
        $rest = ltrim($m[2], '/\\');
        $authority = preg_split('#[/\\\\?\#]#', $rest, 2)[0] ?? '';
        $at = strrpos($authority, '@');
        $hostPort = $at === false ? $authority : substr($authority, $at + 1);
        if (str_starts_with($hostPort, '[')) {
            return preg_match('#\A\[[0-9A-Fa-f:.]+\](?::[0-9]*)?\z#', $hostPort) === 1;
        }
        $colon = strrpos($hostPort, ':');
        $host = $colon === false ? $hostPort : substr($hostPort, 0, $colon);
        $port = $colon === false ? '' : substr($hostPort, $colon + 1);
        if ($port !== '' && (! ctype_digit($port) || (int) $port > 65535)) {
            return false;
        }

        if ($host === '' || preg_match('#[\x00-\x20\#%/:<>?@\[\\\\\]^|\x7f]#', $host) === 1) {
            return false;
        }

        return ! self::endsInNumber($host) || self::isIpv4($host);
    }

    /** WHATWG's "ends in a number": such a host must parse as IPv4, or the URL is refused. */
    private static function endsInNumber(string $host): bool
    {
        $labels = explode('.', $host);
        if (end($labels) === '' && count($labels) > 1) {
            array_pop($labels);
        }
        $last = (string) end($labels);

        return $last !== '' && (ctype_digit($last) || preg_match('#\A0[xX][0-9A-Fa-f]*\z#', $last) === 1);
    }

    /** WHATWG's IPv4 parser, as a yes or no: up to four parts, decimal, octal (leading 0) or hex (0x). */
    private static function isIpv4(string $host): bool
    {
        $parts = explode('.', $host);
        if (end($parts) === '' && count($parts) > 1) {
            array_pop($parts);
        }
        if (count($parts) > 4) {
            return false;
        }
        $numbers = [];
        foreach ($parts as $part) {
            if ($part === '') {
                return false;
            }
            if (preg_match('#\A0[xX]([0-9A-Fa-f]*)\z#', $part, $m) === 1) {
                $number = $m[1] === '' ? 0 : hexdec($m[1]);
            } elseif (strlen($part) > 1 && $part[0] === '0') {
                if (! preg_match('#\A[0-7]+\z#', substr($part, 1))) {
                    return false;
                }
                $number = octdec(substr($part, 1));
            } elseif (ctype_digit($part)) {
                $number = (float) $part;
            } else {
                return false;
            }
            $numbers[] = (float) $number;
        }
        $last = array_pop($numbers);
        foreach ($numbers as $number) {
            if ($number > 255) {
                return false;
            }
        }

        return $last < 256 ** (5 - count($parts));
    }

    /**
     * @param  list<NamedValue>  $values
     */
    private static function namedValues(array $values): \stdClass
    {
        $out = new \stdClass;
        foreach ($values as $pair) {
            $out->{$pair['name']} = $pair['value'];
        }

        return Json::ordered($out);
    }

    /** @return list<NamedValue> */
    private static function pairs(mixed $value, string $field, bool $header): array
    {
        if (! is_array($value)) {
            self::invalid("{$field} must be an array");
        }
        $seen = [];
        $out = [];
        foreach ($value as $entry) {
            if (! $entry instanceof \stdClass) {
                self::invalid("{$field} entries must be objects");
            }
            $name = self::text(Js::get($entry, 'name'), "{$field}.name", false);
            $bad = $header
                ? preg_match("#\\A[!\\#$%&'*+.^_`|~0-9A-Za-z-]+\\z#", $name) !== 1
                : preg_match('/[=\r\n]/', $name) === 1;
            if ($bad) {
                self::invalid("{$field} contains an invalid name");
            }
            $folded = mb_strtolower($name, 'UTF-8');
            if (isset($seen[$folded])) {
                self::invalid("{$field} contains duplicate names");
            }
            $seen[$folded] = true;
            $item = self::text(Js::get($entry, 'value'), "{$field}.value", true);
            if ($header && preg_match('/[\r\n]/', $item) === 1) {
                self::invalid("{$field} contains an invalid header value");
            }
            $out[] = ['name' => $name, 'value' => $item];
        }

        return $out;
    }

    private static function text(mixed $value, string $field, bool $empty): string
    {
        if (! is_string($value) || str_contains($value, "\0") || (! $empty && Js::trim($value) === '')) {
            self::invalid("{$field} must be ".($empty ? 'a' : 'a non-empty').' string without NUL');
        }

        return $value;
    }

    private static function invalid(string $reason): never
    {
        throw new RpcError(RpcError::INVALID_PARAMS, "invalid mcpServers: {$reason}");
    }
}
