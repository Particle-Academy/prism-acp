<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

/** What stop() found. The same shape as the reference's StopVerdict. */
final readonly class StopVerdict
{
    public function __construct(
        /** The provider exited on its own, before the tree was terminated. */
        public bool $graceful,
        /** No process of the tree is left. */
        public bool $empty,
        /** Processes the supervisor still counted after terminating the tree; null when it could not count. */
        public ?int $survivors,
        /** The supervisor confirmed the count itself. False when it ended without reporting. */
        public bool $verified,
    ) {}

    /** @return array{graceful: bool, empty: bool, survivors: int|null, verified: bool} */
    public function toArray(): array
    {
        return ['graceful' => $this->graceful, 'empty' => $this->empty, 'survivors' => $this->survivors, 'verified' => $this->verified];
    }
}
