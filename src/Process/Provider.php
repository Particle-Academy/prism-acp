<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

/**
 * Start a provider CLI under a supervisor that owns its whole process tree: the one way this package runs a provider.
 * Never through a shell. A platform with no containment here is refused, not run uncontained.
 *
 * What the tree's containment holds depends on the platform, and containment() says it in words:
 *  - Windows: a Job Object (kill-on-close, no breakaway). Nothing in the tree can leave it.
 *  - Linux: the provider's process group, plus descendants found through /proc by parent chain or by the per-launch
 *    PRISM_ACP_TREE_ID cookie. Requires ext-pcntl, ext-posix and a readable /proc.
 *  - macOS: the process group ONLY, which is weaker: a descendant that leaves the group escapes. Requires ext-pcntl and
 *    ext-posix.
 *  - Anything else: ContainmentUnavailable, before anything is spawned.
 */
final class Provider
{
    /** Linux's one known hole (G-92), the same as the TypeScript port's. */
    public const LINUX_WEAKNESS = 'a descendant that clears its environment, leaves the process group and is orphaned before the stop is not found (G-92)';

    /** macOS holds the process group only. */
    public const MACOS_WEAKNESS = 'process group only: a descendant that leaves the process group (setsid or setpgid) escapes the stop';

    /**
     * @param  list<string>  $args
     * @param  array<string, string>  $env  the provider's environment (build it with ChildEnv)
     */
    public static function launch(string $command, array $args, string $cwd, array $env): ProviderProcess
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return WindowsProviderProcess::launch($command, $args, $cwd, $env);
        }

        return PosixProviderProcess::launch($command, $args, $cwd, $env);
    }

    /**
     * How this platform holds a provider's tree, and what it does NOT hold, before anything is launched: 'job-object' or
     * 'process-group', with `weakness` null only where nothing in the tree can leave it.
     *
     * @return array{kind: 'job-object'|'process-group', weakness: string|null}
     *
     * @throws ContainmentUnavailable naming what this platform lacks
     */
    public static function containment(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return ['kind' => 'job-object', 'weakness' => null];
        }
        $missing = PosixTree::missing();
        if ($missing !== []) {
            throw new ContainmentUnavailable('cannot contain the provider: this platform lacks '.implode(', ', $missing));
        }

        return ['kind' => 'process-group', 'weakness' => self::weakness()];
    }

    /** What this platform's containment does not hold; null on Windows. */
    public static function weakness(): ?string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => null,
            'Linux' => self::LINUX_WEAKNESS,
            default => self::MACOS_WEAKNESS,
        };
    }
}
