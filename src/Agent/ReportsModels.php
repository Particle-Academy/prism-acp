<?php

declare(strict_types=1);

namespace Prism\Acp\Agent;

use Prism\Acp\Async\Deferred;

/**
 * A driver that says which models the signed-in account offers, and can switch the live session's model.
 *
 * The values arrive through Deferred, which cannot carry a type: Models::state() checks a model state where it is used.
 */
interface ReportsModels
{
    /** @return Deferred<mixed> settles with a model state (see Models::state) */
    public function modelState(): Deferred;

    /** @return Deferred<mixed> settles with null once switched; rejects if the agent refused */
    public function setModel(string $modelId): Deferred;
}
