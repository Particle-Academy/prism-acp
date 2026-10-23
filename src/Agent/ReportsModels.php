<?php

declare(strict_types=1);

namespace Prism\Acp\Agent;

use Prism\Acp\Async\Deferred;

/**
 * A driver that says which models the signed-in account offers, and can switch the live session's model.
 *
 * @phpstan-import-type ModelState from \Prism\Acp\Models\Models
 */
interface ReportsModels
{
    /** @return Deferred<ModelState> */
    public function modelState(): Deferred;

    /** @return Deferred<null> */
    public function setModel(string $modelId): Deferred;
}
