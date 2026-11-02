<?php

declare(strict_types=1);

namespace Prism\Acp\Support {
    // An isolated native-function clock fault; production has no injectable clock or policy knob.
    function microtime(bool $asFloat = false): float|string
    {
        static $clock = 1000.0;
        $clock += 31.0;

        return $asFloat ? $clock : (string) $clock;
    }
}

namespace {
    use Prism\Acp\Credentials\ApiKeyHome;
    use Prism\Acp\Credentials\ProviderCredentials;
    use Prism\Acp\Support\DirectoryProtectionException;

    require $argv[1];
    $home = $argv[2];
    $error = null;
    try {
        ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'owned-argument-key', $home), []);
    } catch (Throwable $caught) {
        $error = $caught;
    }
    echo json_encode([
        'kind' => $error instanceof DirectoryProtectionException ? $error->kind : null,
        'reason' => $error instanceof DirectoryProtectionException ? $error->reason : null,
        'message_names_limit' => $error !== null && str_contains($error->getMessage(), 'time limit'),
        'safe_message' => $error !== null && ! str_contains($error->getMessage(), $home) && ! str_contains($error->getMessage(), 'owned-argument-key'),
        'new_home_removed' => ! is_dir($home),
    ], JSON_THROW_ON_ERROR);
}
