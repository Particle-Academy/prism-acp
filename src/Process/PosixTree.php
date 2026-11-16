<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

/**
 * @internal The POSIX supervisor's view of one provider's process tree, and how it ends it. Ported from the supervisor
 * in prism-acp-ts src/process-tree.ts (SUPERVISOR_JS), name for name where a name exists there.
 *
 * The provider leads its own process group (its pid is the group id). On Linux the tree is also every process that
 * carries this launch's PRISM_ACP_TREE_ID cookie in its environment (as Jenkins' ProcessTreeKiller does with BUILD_ID),
 * and everything whose parent chain reaches a process already recorded: that finds a child that called setsid, even one
 * orphaned before the stop. Elsewhere (macOS) there is no /proc, and the process group is all that is held.
 *
 * Every recorded pid is kept with its start time (/proc/<pid>/stat field 22), and a pid is only ever signalled while
 * its start time still matches: a pid that was reused since belongs to someone else, and is never touched.
 *
 * NOT caught (G-92): a descendant that clears its environment AND leaves the group AND is orphaned before it is seen.
 * Only a cgroup holds that, which is why cgroup mode (PosixCgroup) is used whenever one is delegated. There this tree
 * still runs, for the group and parent chain, but with environment reads OFF.
 *
 * This file is loaded by resources/posix/supervisor.php with a plain require, so it depends on nothing else here.
 */
final class PosixTree
{
    public const COOKIE_ENV = 'PRISM_ACP_TREE_ID';

    /** @var array<int, string> pid => start time of every process seen in the tree */
    private array $known = [];

    /** The most of one process's environment a cookie check reads. Past it, the cookie is simply not seen. */
    public const MAX_ENVIRON_BYTES = 4 * 1024 * 1024;

    private readonly string $needle;

    /** @var (\Closure(string): ?string)|null */
    private readonly ?\Closure $environ;

    /**
     * @param  bool|\Closure(string): ?string  $environ  how /proc/<pid>/environ is read for the cookie: true for the
     *                                                   bounded default, a closure for a test's own, FALSE to never read
     *                                                   any environment (cgroup mode, where the cgroup holds the tree)
     */
    public function __construct(
        /** The provider's pid, which is its process group's id. */
        public readonly int $pgid,
        string $cookie,
        /** This process, never part of the tree. */
        private readonly int $self = 0,
        private readonly string $proc = '/proc',
        bool|\Closure $environ = true,
    ) {
        $this->needle = self::COOKIE_ENV.'='.$cookie."\0";
        $this->environ = $environ === true ? self::readEnviron(...) : ($environ === false ? null : $environ);
    }

    /** Whether this tree reads environments at all. */
    public function readsEnvironments(): bool
    {
        return $this->environ !== null;
    }

    /** The default cookie read: at most MAX_ENVIRON_BYTES, and null when the file cannot be read. */
    public static function readEnviron(string $path): ?string
    {
        set_error_handler(static fn (): bool => true);
        try {
            $content = file_get_contents($path, false, null, 0, self::MAX_ENVIRON_BYTES);
        } finally {
            restore_error_handler();
        }

        return is_string($content) ? $content : null;
    }

    /**
     * What this platform lacks for the containment this release offers, by name; an empty list when nothing is missing.
     * Linux needs ext-pcntl, ext-posix and a readable /proc; macOS needs the two extensions. Every other platform is
     * unsupported as a whole, and says so.
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' && PHP_OS_FAMILY !== 'Darwin') {
            return [PHP_OS_FAMILY.' (supported: Windows, Linux, macOS)'];
        }
        $missing = [];
        foreach (['ext-pcntl' => ['pcntl_fork', 'pcntl_exec', 'pcntl_waitpid', 'pcntl_signal', 'pcntl_async_signals'],
            'ext-posix' => ['posix_kill', 'posix_setpgid', 'posix_getpid', 'posix_get_last_error']] as $extension => $functions) {
            foreach ($functions as $function) {
                if (! function_exists($function)) {
                    $missing[] = $extension;
                    break;
                }
            }
        }
        if (PHP_OS_FAMILY === 'Linux' && ! is_readable('/proc/self/stat')) {
            $missing[] = 'a readable /proc';
        }

        return $missing;
    }

    /** Record $pid as part of the tree, with the start time it has now. False when it is already gone. */
    public function adopt(int $pid): bool
    {
        $start = $this->startTime($pid);
        if ($start === null) {
            return false;
        }
        $this->known[$pid] = $start;

        return true;
    }

    /** Record $pid with a start time the caller observed earlier. A test seam for the pid-reuse guard as much as an API. */
    public function remember(int $pid, string $start): void
    {
        $this->known[$pid] = $start;
    }

    /** @return array<int, string> */
    public function known(): array
    {
        return $this->known;
    }

    /**
     * Every process on the system, Linux only (elsewhere: an empty list, and only the process group is held).
     *
     * @return array<int, array{ppid: int, pgrp: int, start: string, zombie: bool}>
     */
    public function scan(): array
    {
        $procs = [];
        if (PHP_OS_FAMILY !== 'Linux') {
            return $procs;
        }
        set_error_handler(static fn (): bool => true);
        try {
            $entries = scandir($this->proc);
        } finally {
            restore_error_handler();
        }
        if ($entries === false) {
            return $procs;
        }
        foreach ($entries as $name) {
            if (! ctype_digit($name)) {
                continue;
            }
            $info = $this->stat((int) $name);
            if ($info !== null) {
                $procs[(int) $name] = $info;
            }
        }

        return $procs;
    }

    /**
     * Record the tree: the provider's group, every process carrying the cookie, and everything whose parent chain
     * reaches a process already recorded (with its start time unchanged).
     *
     * @param  array<int, array{ppid: int, pgrp: int, start: string, zombie: bool}>  $procs
     */
    public function track(array $procs): void
    {
        foreach ($procs as $pid => $info) {
            if (! isset($this->known[$pid]) && $pid !== $this->self && ($info['pgrp'] === $this->pgid || $this->carriesCookie($pid))) {
                $this->known[$pid] = $info['start'];
            }
        }
        do {
            $grew = false;
            foreach ($procs as $pid => $info) {
                if (! isset($this->known[$pid]) && $pid !== $this->self && $this->same($procs, $info['ppid'])) {
                    $this->known[$pid] = $info['start'];
                    $grew = true;
                }
            }
        } while ($grew);
    }

    /**
     * Signal the tree: the process group while a member still holds its id, and every recorded pid whose start time
     * still matches.
     *
     * @param  array<int, array{ppid: int, pgrp: int, start: string, zombie: bool}>  $procs
     */
    public function signalAll(array $procs, int $signal): void
    {
        // The group only while a member still holds its id: an empty group's id is a free pid, and could be someone else's.
        $groupHeld = $procs === [];
        foreach ($procs as $info) {
            if ($info['pgrp'] === $this->pgid) {
                $groupHeld = true;
                break;
            }
        }
        if ($groupHeld) {
            @posix_kill(-$this->pgid, $signal);
        }
        foreach (array_keys($this->known) as $pid) {
            if ($pid !== $this->self && $this->same($procs, $pid)) {
                @posix_kill($pid, $signal);
            }
        }
    }

    /**
     * Freeze, collect until nothing new appears, then kill: a frozen process cannot start another. Needs ext-pcntl's
     * signal numbers, which differ by platform (SIGSTOP is 19 on Linux and 17 on macOS); missing() requires it.
     */
    public function terminate(): void
    {
        for ($round = 0; $round < 5; $round++) {
            $before = count($this->known);
            $procs = $this->scan();
            $this->track($procs);
            $this->signalAll($procs, SIGSTOP);
            if ($round > 0 && count($this->known) === $before) {
                break;
            }
        }
        $this->signalAll($this->scan(), SIGKILL);
    }

    /**
     * Processes of the tree still running: the group (as one) and every recorded pid outside it. Zombies run nothing.
     *
     * @param  list<int>  $counted  pids already counted elsewhere (a cgroup's members), left out here
     */
    public function left(array $counted = []): int
    {
        $procs = $this->scan();
        $skip = array_fill_keys($counted, true);
        $n = $this->groupAlive(array_diff_key($procs, $skip)) ? 1 : 0;
        foreach (array_keys($this->known) as $pid) {
            if (! isset($skip[$pid]) && $pid !== $this->pgid && $this->same($procs, $pid) && $procs[$pid]['pgrp'] !== $this->pgid && ! $procs[$pid]['zombie']) {
                $n++;
            }
        }

        return $n;
    }

    /** @param array<int, array{ppid: int, pgrp: int, start: string, zombie: bool}> $procs */
    private function groupAlive(array $procs): bool
    {
        if (PHP_OS_FAMILY === 'Linux') {
            foreach ($procs as $info) {
                if ($info['pgrp'] === $this->pgid && ! $info['zombie']) {
                    return true;
                }
            }

            return false;
        }
        if (@posix_kill(-$this->pgid, 0)) {
            return true;
        }

        // EPERM (1 on Linux and macOS): a member exists, it is just not ours to signal.
        return posix_get_last_error() === 1;
    }

    /** @param array<int, array{ppid: int, pgrp: int, start: string, zombie: bool}> $procs */
    private function same(array $procs, int $pid): bool
    {
        return isset($procs[$pid], $this->known[$pid]) && $procs[$pid]['start'] === $this->known[$pid];
    }

    /** The tree's cookie, which every descendant inherits unless it clears its environment. */
    private function carriesCookie(int $pid): bool
    {
        if ($this->environ === null) {
            return false;
        }
        // A presence check, compared and discarded: the environment is never kept, logged or returned.
        $environ = ($this->environ)($this->proc.'/'.$pid.'/environ');
        if (! is_string($environ)) {
            return false;
        }

        return str_starts_with($environ, $this->needle) || str_contains($environ, "\0".$this->needle);
    }

    private function startTime(int $pid): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }

        return $this->stat($pid)['start'] ?? null;
    }

    /** @return array{ppid: int, pgrp: int, start: string, zombie: bool}|null */
    private function stat(int $pid): ?array
    {
        $stat = self::read($this->proc.'/'.$pid.'/stat');
        if (! is_string($stat)) {
            return null;
        }
        $close = strrpos($stat, ')');
        if ($close === false) {
            return null;
        }
        // After the parenthesised name: state, ppid, pgrp, ... starttime is the 20th of these (field 22 overall).
        $fields = explode(' ', substr($stat, $close + 2));
        if (count($fields) < 20 || ! ctype_digit($fields[1]) || ! ctype_digit($fields[2]) || ! ctype_digit($fields[19])) {
            return null;
        }

        return ['ppid' => (int) $fields[1], 'pgrp' => (int) $fields[2], 'start' => $fields[19], 'zombie' => $fields[0] === 'Z' || $fields[0] === 'X'];
    }

    /** A /proc file that may vanish, or be another user's, between listing and reading: either is just absent. */
    private static function read(string $path): ?string
    {
        set_error_handler(static fn (): bool => true);
        try {
            $content = file_get_contents($path);
        } finally {
            restore_error_handler();
        }

        return is_string($content) ? $content : null;
    }
}
