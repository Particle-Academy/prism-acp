<?php

declare(strict_types=1);

namespace Prism\Acp\Agent;

use Prism\Acp\Async\Deferred;

/**
 * A driver that can interrupt the turn in flight and keep the agent and its session alive. The turn still ends through
 * onTurnEnd (with stopReason `cancelled` once the agent confirms). With no turn running it is a no-op. A driver without
 * it is stopped by `session/cancel` instead.
 */
interface CancelsTurns
{
    /** @return Deferred<null> settles once the agent accepted the interrupt; rejects if it refused */
    public function cancel(): Deferred;
}
