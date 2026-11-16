<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

/**
 * Start a provider CLI under a supervisor that owns its whole process tree: the one way this package runs a provider.
 * Never through a shell. A platform with no containment here is refused, not run uncontained.
 *
 * What the tree's containment holds depends on the platform, and containment() says it in words:
 *  - Windows: a Job Object (kill-on-close, no breakaway). Nothing in the tree can leave it.
 *  - Linux, `cgroup` mode: the provider's own cgroup v2, under one delegated to this user. Every descendant is born in
 *    it, and no environment is read. Used whenever such a cgroup is available.
 *  - Linux, `process-group+proc` mode (the fallback): the process group, plus descendants found through /proc by
 *    parent chain or by the per-launch PRISM_ACP_TREE_ID cookie, for which same-user environments are read.
 *  - macOS: the process group ONLY, which is weaker: a descendant that leaves the group escapes.
 *  - Anything else, or Linux/macOS without ext-pcntl and ext-posix (and /proc on Linux): ContainmentUnavailable,
 *    before anything is spawned.
 */
final class Provider
{
    /** Linux, cgroup mode. */
    public const LINUX_CGROUP_WEAKNESS = 'cgroup: a descendant cannot leave by setsid, setpgid, a cleared environment or being orphaned, and no environment is read; '
        .'missed only if it deliberately writes its own pid into another cgroup of this user\'s delegation AND leaves the process group AND is orphaned, or is moved by root or CAP_SYS_ADMIN';

    /** Linux, the process-group+proc fallback: the reference's one known hole (G-92), and what it reads. */
    public const LINUX_WEAKNESS = 'process-group+proc: a descendant that clears its environment, leaves the process group and is orphaned before the stop is not found (G-92); '
        .'to find descendants, the environments of this user\'s processes are read for the tree marker (presence only, nothing kept)';

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
     * How this platform would hold a provider's tree, and what it does NOT hold, before anything is launched. `kind` is
     * 'job-object', 'cgroup' or 'process-group' (ProcessTree::$containment after a launch); `mode` adds Linux's
     * 'process-group+proc' fallback. ProcessTree::$weakness reports the mode a launch actually got.
     *
     * @return array{kind: 'job-object'|'cgroup'|'process-group', mode: 'job-object'|'cgroup'|'process-group+proc'|'process-group', weakness: string|null, reason?: string}
     *
     * @throws ContainmentUnavailable naming what this platform lacks
     */
    public static function containment(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return ['kind' => 'job-object', 'mode' => 'job-object', 'weakness' => null];
        }
        $missing = PosixTree::missing();
        if ($missing !== []) {
            throw new ContainmentUnavailable('cannot contain the provider: this platform lacks '.implode(', ', $missing));
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return ['kind' => 'process-group', 'mode' => 'process-group', 'weakness' => self::MACOS_WEAKNESS];
        }
        $delegated = PosixProviderProcess::cgroupsEnabled() ? PosixCgroup::delegated() : ['path' => null, 'reason' => 'cgroups are disabled'];
        if ($delegated['path'] !== null) {
            return ['kind' => 'cgroup', 'mode' => 'cgroup', 'weakness' => self::LINUX_CGROUP_WEAKNESS];
        }

        return ['kind' => 'process-group', 'mode' => 'process-group+proc', 'weakness' => self::LINUX_WEAKNESS, 'reason' => $delegated['reason']];
    }

    /** What a containment kind does not hold on this platform; null on Windows. */
    public static function weakness(string $kind = 'process-group'): ?string
    {
        return match (true) {
            PHP_OS_FAMILY === 'Windows' => null,
            PHP_OS_FAMILY === 'Linux' && $kind === 'cgroup' => self::LINUX_CGROUP_WEAKNESS,
            PHP_OS_FAMILY === 'Linux' => self::LINUX_WEAKNESS,
            default => self::MACOS_WEAKNESS,
        };
    }
}
