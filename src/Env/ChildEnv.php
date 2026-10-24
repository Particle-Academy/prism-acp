<?php

declare(strict_types=1);

namespace Prism\Acp\Env;

/**
 * The environment handed to a spawned agent CLI: built from an ALLOW-list, never inherited wholesale.
 *
 * An agent CLI on a consumer subscription carries no API key, but its SDK resolves credentials from an ORDERED list
 * in which an inherited API key comes first. A valid inherited key bills per token while the session looks like a
 * subscription session; an invalid one fails every call with 401 instead of falling back to the working login.
 * So credentials that outrank the subscription are withheld, even when a caller allow-lists them; only the
 * explicitly named option passes one through.
 *
 * Per spawn, never ambient: this builds the CHILD's environment and never touches the process's own, which other
 * code in the same application may legitimately rely on. Names compare case-insensitively, because Windows
 * environment names are. Values are never logged or returned except into the child's environment.
 *
 * The lists are the reference's (prism-acp-ts src/env.ts), name for name.
 */
final class ChildEnv
{
    /** Credentials that outrank a CLI's own subscription login. */
    public const OUTRANKING_CREDENTIALS = [
        'ANTHROPIC_API_KEY',
        'ANTHROPIC_AUTH_TOKEN',
        'ANTHROPIC_BASE_URL',
        'ANTHROPIC_API_URL',
        'CLAUDE_API_KEY',
        'CLAUDE_CODE_OAUTH_TOKEN',
        'OPENAI_API_KEY',
        'OPENAI_BASE_URL',
    ];

    /** What a CLI needs in order to run at all. Anything beyond this is passed explicitly, where a diff shows it. */
    public const BASE_ALLOW = [
        // POSIX
        'PATH', 'HOME', 'SHELL', 'TMPDIR', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TERM', 'TZ', 'USER', 'LOGNAME',
        // Windows
        'APPDATA', 'COMSPEC', 'LOCALAPPDATA', 'NUMBER_OF_PROCESSORS', 'OS', 'PATHEXT', 'PROCESSOR_ARCHITECTURE',
        'PROGRAMDATA', 'PROGRAMFILES', 'PROGRAMFILES(X86)', 'SYSTEMDRIVE', 'SYSTEMROOT', 'TEMP', 'TMP', 'USERDOMAIN',
        'USERNAME', 'USERPROFILE', 'WINDIR',
        // Networking, on both
        'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'ALL_PROXY',
    ];

    /**
     * Build a child environment allow-list-first.
     *
     * @param  array<string, string|null>  $parent  usually getenv()
     * @param  list<string>  $allow  extra names to pass through, beyond BASE_ALLOW
     * @param  array<string, string>  $set  values set explicitly; applied last, so they win
     * @param  bool  $allowSubscriptionOverridingCredentials  the ONLY way to pass an outranking credential through
     */
    public static function build(
        array $parent,
        array $allow = [],
        array $set = [],
        bool $allowSubscriptionOverridingCredentials = false,
    ): ChildEnvResult {
        $outranking = array_fill_keys(array_map(strtoupper(...), self::OUTRANKING_CREDENTIALS), true);
        $allowed = array_fill_keys(array_map(strtoupper(...), [...self::BASE_ALLOW, ...$allow]), true);

        $env = [];
        $withheld = [];
        foreach ($parent as $name => $value) {
            if ($value === null) {
                continue;
            }
            $name = (string) $name;
            $key = strtoupper($name);
            if (isset($outranking[$key])) {
                // Refused even when listed in $allow: adding a name to the allow-list must never be how a
                // subscription user ends up billed per token.
                if ($allowSubscriptionOverridingCredentials) {
                    $env[$name] = $value;
                } else {
                    $withheld[] = $name;
                }

                continue;
            }
            if (isset($allowed[$key])) {
                $env[$name] = $value;
            }
        }

        foreach ($set as $name => $value) {
            $env[(string) $name] = $value;
        }

        return new ChildEnvResult($env, $withheld);
    }
}
