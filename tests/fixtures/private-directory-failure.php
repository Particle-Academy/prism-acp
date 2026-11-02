<?php

declare(strict_types=1);

// Faults live only in this isolated test host, never behind a production injection API.

namespace Prism\Acp\Support {
    function mkdir(string $path, int $permissions = 0777, bool $recursive = false): bool
    {
        $made = \mkdir($path, $permissions, $recursive);
        if ($made) {
            $GLOBALS['owned_created'] = $path;
        }

        return $made;
    }

    function sys_get_temp_dir(): string
    {
        return $GLOBALS['owned_root'];
    }

    function chmod(string $path, int $permissions): bool
    {
        if ($GLOBALS['owned_fault'] === 'protect') {
            \chmod($path, 0777);

            return false;
        }

        return \chmod($path, $permissions);
    }

    function proc_open(array|string $command, array $descriptors, ?array &$pipes, ?string $cwd = null, ?array $env = null, ?array $options = null): mixed
    {
        // Protection failure on Windows; no provider or external tool is launched.
        if ($GLOBALS['owned_fault'] === 'protect') {
            return false;
        }

        return \proc_open($command, $descriptors, $pipes, $cwd, $env, $options);
    }

    function lstat(string $path): array|false
    {
        return $GLOBALS['owned_fault'] === 'verify' ? false : \lstat($path);
    }

    function rmdir(string $path): bool
    {
        return $GLOBALS['owned_cleanup_denied'] ? false : \rmdir($path);
    }
}

namespace Prism\Acp\Credentials {
    function mkdir(string $path, int $permissions = 0777, bool $recursive = false): bool
    {
        return \Prism\Acp\Support\mkdir($path, $permissions, $recursive);
    }

    function rmdir(string $path): bool
    {
        return \Prism\Acp\Support\rmdir($path);
    }
}

namespace {
    use Prism\Acp\Credentials\ApiKeyHome;
    use Prism\Acp\Credentials\ProviderCredentials;
    use Prism\Acp\Support\DirectoryCleanupException;
    use Prism\Acp\Support\DirectoryProtectionException;
    use Prism\Acp\Support\PrivateDirectory;

    require $argv[1];
    $GLOBALS['owned_root'] = $argv[2];
    $GLOBALS['owned_created'] = $argv[2].DIRECTORY_SEPARATOR.'new-home';
    $GLOBALS['owned_fault'] = $argv[4];
    $GLOBALS['owned_cleanup_denied'] = $argv[5] === 'deny';
    $error = null;
    try {
        if ($argv[3] === 'api') {
            ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'owned-dummy-key', $GLOBALS['owned_created']), []);
        } else {
            PrivateDirectory::create('private-');
        }
    } catch (Throwable $caught) {
        $error = $caught;
    }
    $home = $GLOBALS['owned_created'];
    $GLOBALS['owned_fault'] = 'none';
    $retry = 'not-needed';
    if (is_dir($home)) {
        try {
            ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'owned-dummy-key', $home), []);
            $retry = 'accepted';
        } catch (Throwable $caught) {
            $retry = $caught instanceof DirectoryProtectionException ? $caught->kind : 'unexpected';
        }
    }
    echo json_encode([
        'class' => $error === null ? null : $error::class,
        'kind' => $error instanceof DirectoryCleanupException || $error instanceof DirectoryProtectionException ? $error->kind : null,
        'has_previous' => $error?->getPrevious() !== null,
        'safe_message' => $error !== null && ! str_contains($error->getMessage(), $argv[2]) && ! str_contains($error->getMessage(), 'owned-dummy-key'),
        'residual' => is_dir($home),
        'retry' => $retry,
        'leaf' => basename($home),
    ], JSON_THROW_ON_ERROR);
}
