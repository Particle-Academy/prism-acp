<?php

declare(strict_types=1);

namespace Prism\Acp\Agent;

use Prism\Acp\Async\Deferred;

/** The turn a session has in flight: what settles session/prompt, and whether the client cancelled it. Internal. */
final class Turn
{
    public bool $cancelled = false;

    /** @param Deferred<TurnOutcome> $settle */
    public function __construct(public readonly Deferred $settle) {}
}
