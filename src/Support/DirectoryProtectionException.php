<?php

declare(strict_types=1);

namespace Prism\Acp\Support;

/** Structural refusal only: no path, key, OS diagnostic or unsafe previous exception. */
final class DirectoryProtectionException extends \RuntimeException
{
    public readonly string $kind;

    public readonly string $reason;

    public function __construct(bool $credentialHome = false, string $reason = 'permissions')
    {
        $this->kind = 'unsafe_directory';
        $this->reason = in_array($reason, ['depth_limit', 'entry_limit', 'time_limit'], true) ? $reason : 'permissions';
        $prefix = $credentialHome ? 'credentials.home' : 'Private directory';
        $message = match ($this->reason) {
            'depth_limit' => $prefix.' verification exceeded the depth limit. Reduce the tree to the documented limits or choose a new app-owned home before retrying.',
            'entry_limit' => $prefix.' verification exceeded the entry count limit of 16,384. Prune old app-owned session/log files, or use a fresh app-owned home before retrying.',
            'time_limit' => $prefix.' verification reached the time limit. Use a smaller app-owned home before retrying; no entries were skipped or trusted.',
            default => $prefix.' is not verifiably private. Reset this folder\'s permissions using the documented chmod/icacls commands, or choose a new app-owned directory. Restore current-user ownership and owner-only access before retrying.',
        };
        parent::__construct($message);
    }
}
