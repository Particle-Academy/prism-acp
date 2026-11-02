<?php

declare(strict_types=1);

namespace Prism\Acp\Support {
    use Prism\Acp\Env\ChildEnv;

    // Change only our owned empty home during real MCP configuration setup, after admission/key/env checks.
    function mkdir(string $path, int $permissions = 0777, bool $recursive = false): bool
    {
        $made = \mkdir($path, $permissions, $recursive);
        if ($made && str_starts_with(basename($path), 'prism-acp-mcp-')) {
            $GLOBALS['owned_config'] = $path;
            $home = $GLOBALS['owned_home'];
            if (PHP_OS_FAMILY === 'Windows') {
                $tool = (getenv('SystemRoot') ?: 'C:\\Windows').'\\System32\\icacls.exe';
                $p = \proc_open([$tool, $home, '/grant', '*S-1-1-0:(OI)(CI)RX'],
                    [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes,
                    env_vars: ChildEnv::build(getenv())->env, options: ['bypass_shell' => true]);
                if (! is_resource($p) || proc_close($p) !== 0) {
                    throw new \RuntimeException('owned handoff ACL mutation failed');
                }
            } elseif (! chmod($home, 0777)) {
                throw new \RuntimeException('owned handoff mode mutation failed');
            }
            $GLOBALS['owned_changed'] = true;
        }

        return $made;
    }
}

namespace Prism\Acp\Process {
    // A missing handoff guard must fail the assertion without ever launching a provider or compiler.
    function proc_open(array|string $command, array $descriptors, ?array &$pipes, ?string $cwd = null, ?array $env = null, ?array $options = null): mixed
    {
        $GLOBALS['provider_boundary_reached'] = true;
        throw new \LogicException('unexpected provider handoff');
    }
}

namespace {
    use Prism\Acp\Agent\DriverEvents;
    use Prism\Acp\Async\Loop;
    use Prism\Acp\Claude\ClaudeDriver;
    use Prism\Acp\Claude\ClaudeDriverOptions;
    use Prism\Acp\Credentials\ProviderCredentials;
    use Prism\Acp\Support\DirectoryProtectionException;

    require $argv[1];
    $GLOBALS['owned_home'] = $argv[2].DIRECTORY_SEPARATOR.'home';
    $GLOBALS['owned_changed'] = $GLOBALS['provider_boundary_reached'] = false;
    $GLOBALS['owned_config'] = null;
    $loop = new Loop;
    $driver = new ClaudeDriver(new ClaudeDriverOptions($argv[2], binary: PHP_BINARY, parentEnv: [],
        mcpServers: [['name' => 'owned-fixture', 'command' => 'never-executed', 'args' => [], 'env' => []]],
        credentials: new ProviderCredentials('apiKey', 'owned-argument-key', $GLOBALS['owned_home'])), new DriverEvents, $loop);
    $error = null;
    try {
        $driver->start();
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $driver->kill();
        $loop->drain();
    }
    $configRemoved = $GLOBALS['owned_config'] !== null && ! is_dir($GLOBALS['owned_config']);
    echo json_encode([
        'changed_after_admission' => $GLOBALS['owned_changed'],
        'refused' => $error instanceof DirectoryProtectionException,
        'safe_message' => $error !== null && ! str_contains($error->getMessage(), $argv[2]) && ! str_contains($error->getMessage(), 'owned-argument-key'),
        'provider_not_reached' => ! $GLOBALS['provider_boundary_reached'],
        'config_removed' => $configRemoved,
    ], JSON_THROW_ON_ERROR);
    rmdir($GLOBALS['owned_home']);
}
