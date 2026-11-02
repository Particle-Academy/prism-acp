<?php

declare(strict_types=1);

namespace Prism\Acp\Support;

// Loaded only by the isolated native test host. Production has no diagnostic injection hook.
function unlink(string $path): bool
{
    try {
        ($GLOBALS['owned_native_before_unlink'] ?? null)?->__invoke($path);
    } catch (\Throwable $error) {
        $GLOBALS['owned_native_unlink_observations'][] = ['diagnosticUnavailable' => $error::class];
    }

    return \unlink($path);
}
