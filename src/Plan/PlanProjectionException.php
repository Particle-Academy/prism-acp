<?php

declare(strict_types=1);

namespace Prism\Acp\Plan;

/** A plan snapshot that cannot be shown honestly: an unknown state, owner or status, a bad id, or too many steps. */
final class PlanProjectionException extends \InvalidArgumentException {}
