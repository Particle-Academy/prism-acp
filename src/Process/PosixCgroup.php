<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

/**
 * @internal A provider's own cgroup v2, under a cgroup this user has been DELEGATED (Linux only). Every process the
 * provider starts is born in it and, unlike a process group, cannot leave it by setsid, setpgid, clearing its
 * environment or being orphaned. Ending the tree is `cgroup.kill` where the kernel has it (5.14+), otherwise freeze
 * and SIGKILL every member until none is left. Nothing here reads any process's environment.
 *
 * Delegated means: this process's own cgroup (from /proc/self/cgroup, under the cgroup2 mount) is a directory this
 * user may create cgroups in, whose cgroup.procs this user may write, and whose cgroup.subtree_control is empty, so the
 * kernel lets it hold processes and child cgroups at once. A systemd user scope or service with Delegate=yes gives
 * exactly that; nothing here uses sudo, root or systemd itself.
 *
 * What it does NOT hold: the delegation is to this user, so a same-user descendant may write its own pid into a cgroup
 * elsewhere in the delegated subtree (the kernel only requires write access to the common ancestor's cgroup.procs).
 * The supervisor still ends the provider's process group and parent chain as well, so such an escapee is missed only
 * if it ALSO leaves the group and is orphaned. Root, or CAP_SYS_ADMIN, can move any process anywhere.
 *
 * Loaded by resources/posix/supervisor.php with a plain require, so it depends on nothing else here.
 */
final readonly class PosixCgroup
{
    /** Our cgroups' names: the prefix and 16 lowercase hex digits, nothing else, so a sweep never touches another's. */
    public const NAME_PATTERN = '/\Aprism-acp-[0-9a-f]{16}\z/';

    private function __construct(
        public string $path,
        /** Whether to use cgroup.kill when the kernel has it. False forces the freeze-and-kill path (tests). */
        private bool $useKillFile = true,
    ) {}

    /**
     * The delegated cgroup this process may create provider cgroups under, or null with why not.
     *
     * @return array{path: string|null, reason: string}
     */
    public static function delegated(string $proc = '/proc'): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return ['path' => null, 'reason' => 'cgroups are Linux only'];
        }
        $membership = self::read($proc.'/self/cgroup');
        if ($membership === null || preg_match('/^0::(\/[^\n]*)$/m', $membership, $unified) !== 1) {
            return ['path' => null, 'reason' => 'this process is not in a cgroup v2 hierarchy'];
        }
        $mount = self::unifiedMount($proc);
        if ($mount === null) {
            return ['path' => null, 'reason' => 'no cgroup2 mount is visible'];
        }
        [$mountPoint, $mountRoot] = $mount;
        $relative = $unified[1];
        if ($mountRoot !== '/') {
            if (! str_starts_with($relative.'/', rtrim($mountRoot, '/').'/')) {
                return ['path' => null, 'reason' => 'this process\'s cgroup is outside the visible cgroup2 mount'];
            }
            $relative = substr($relative, strlen(rtrim($mountRoot, '/'))) ?: '/';
        }
        if ($relative === '/' || str_contains($relative, '/../') || str_ends_with($relative, '/..')) {
            return ['path' => null, 'reason' => 'this process is in the root cgroup, which is never delegated'];
        }
        $path = rtrim($mountPoint, '/').$relative;
        clearstatcache();
        if (! is_dir($path) || is_link($path)) {
            return ['path' => null, 'reason' => 'this process\'s cgroup directory is not visible'];
        }
        if (! is_writable($path) || ! is_writable($path.'/cgroup.procs')) {
            return ['path' => null, 'reason' => 'this process\'s cgroup is not delegated to this user (not writable)'];
        }
        $subtree = self::read($path.'/cgroup.subtree_control');
        if ($subtree === null || trim($subtree) !== '') {
            return ['path' => null, 'reason' => 'this process\'s cgroup enables controllers for its children, so it cannot also hold processes'];
        }
        if (! is_file($path.'/cgroup.kill') && ! is_file($path.'/cgroup.freeze')) {
            return ['path' => null, 'reason' => 'this kernel has neither cgroup.kill nor cgroup.freeze'];
        }

        return ['path' => $path, 'reason' => 'delegated'];
    }

    /** A fresh, empty provider cgroup under $parent, or null when it cannot be made. */
    public static function create(string $parent, bool $useKillFile = true): ?self
    {
        $path = $parent.'/prism-acp-'.bin2hex(random_bytes(8));
        set_error_handler(static fn (): bool => true);
        try {
            $made = mkdir($path);
        } finally {
            restore_error_handler();
        }
        if (! $made || ! is_writable($path.'/cgroup.procs')) {
            if ($made) {
                self::rmdir($path);
            }

            return null;
        }

        return new self($path, $useKillFile);
    }

    /** Move the calling process into this cgroup. Its descendants are then born here. */
    public function adoptSelf(): bool
    {
        return self::write($this->path.'/cgroup.procs', '0');
    }

    /** Move $pid into this cgroup (tests, and only for this user's processes). */
    public function adopt(int $pid): bool
    {
        return self::write($this->path.'/cgroup.procs', (string) $pid);
    }

    /** @return list<int> the processes in it now; an exited process is no longer listed */
    public function pids(): array
    {
        $listing = self::read($this->path.'/cgroup.procs');
        if ($listing === null) {
            return [];
        }
        $pids = [];
        foreach (explode("\n", trim($listing)) as $line) {
            if (ctype_digit($line)) {
                $pids[] = (int) $line;
            }
        }

        return $pids;
    }

    /**
     * End every process in it: cgroup.kill where it exists, otherwise freeze (nothing in it can fork once frozen) and
     * SIGKILL each member until the cgroup is empty or $seconds pass. Returns whether it is empty.
     */
    public function kill(float $seconds = 5.0): bool
    {
        $deadline = microtime(true) + $seconds;
        if ($this->useKillFile && is_file($this->path.'/cgroup.kill') && self::write($this->path.'/cgroup.kill', '1')) {
            while ($this->pids() !== [] && microtime(true) < $deadline) {
                usleep(10_000);
            }

            return $this->pids() === [];
        }
        self::write($this->path.'/cgroup.freeze', '1');
        $frozenBy = min($deadline, microtime(true) + 1.0);
        while (! $this->frozen() && microtime(true) < $frozenBy) {
            usleep(5_000);
        }
        while (($pids = $this->pids()) !== [] && microtime(true) < $deadline) {
            foreach ($pids as $pid) {
                // Members of our own cgroup: a pid listed here is in it now, so a reused pid cannot be listed.
                @posix_kill($pid, SIGKILL);
            }
            usleep(10_000);
        }

        return $this->pids() === [];
    }

    /** Remove it once empty. A cgroup with members, or with child cgroups, cannot be removed and is left. */
    public function remove(float $seconds = 2.0): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            if (self::rmdir($this->path)) {
                return true;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        return ! is_dir($this->path);
    }

    /**
     * Remove provider cgroups a crashed supervisor left under $parent: ONLY ones named by NAME_PATTERN that are empty
     * (no process, nothing populated below) and have no child cgroup. Anything else is never touched.
     *
     * @return list<string> the names removed
     */
    public static function sweepStale(string $parent): array
    {
        $entries = self::scandir($parent);
        $removed = [];
        foreach ($entries as $name) {
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                continue;
            }
            $path = $parent.'/'.$name;
            if (is_link($path) || ! is_dir($path) || ! self::isEmptyLeaf($path)) {
                continue;
            }
            if (self::rmdir($path)) {
                $removed[] = $name;
            }
        }

        return $removed;
    }

    private function frozen(): bool
    {
        return preg_match('/^frozen 1$/m', (string) self::read($this->path.'/cgroup.events')) === 1;
    }

    private static function isEmptyLeaf(string $path): bool
    {
        $procs = self::read($path.'/cgroup.procs');
        $events = self::read($path.'/cgroup.events');
        if ($procs === null || trim($procs) !== '' || $events === null || preg_match('/^populated 0$/m', $events) !== 1) {
            return false;
        }
        foreach (self::scandir($path) as $name) {
            if (is_dir($path.'/'.$name) && ! is_link($path.'/'.$name)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{0: string, 1: string}|null the cgroup2 mount point and the cgroup path it shows as its root */
    private static function unifiedMount(string $proc): ?array
    {
        foreach (explode("\n", (string) self::read($proc.'/self/mountinfo')) as $line) {
            $halves = explode(' - ', $line, 2);
            if (count($halves) !== 2 || ! str_starts_with($halves[1], 'cgroup2 ')) {
                continue;
            }
            $fields = explode(' ', $halves[0]);
            if (count($fields) >= 5) {
                return [self::unescape($fields[4]), self::unescape($fields[3])];
            }
        }

        return null;
    }

    /** mountinfo escapes space, tab, newline and backslash as octal. */
    private static function unescape(string $field): string
    {
        return (string) preg_replace_callback('/\\\\([0-7]{3})/', static fn (array $m): string => chr((int) octdec($m[1])), $field);
    }

    /** @return list<string> */
    private static function scandir(string $path): array
    {
        set_error_handler(static fn (): bool => true);
        try {
            $entries = scandir($path);
        } finally {
            restore_error_handler();
        }

        return $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
    }

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

    private static function write(string $path, string $value): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return file_put_contents($path, $value) === strlen($value);
        } finally {
            restore_error_handler();
        }
    }

    private static function rmdir(string $path): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return rmdir($path);
        } finally {
            restore_error_handler();
        }
    }
}
