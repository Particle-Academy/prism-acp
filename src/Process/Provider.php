<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

/**
 * Start a provider CLI under a supervisor that owns its whole process tree: the one way this package runs a provider.
 * Never through a shell. A platform with no containment here is refused, not run uncontained.
 */
final class Provider
{
    /**
     * @param  list<string>  $args
     * @param  array<string, string>  $env  the provider's environment (build it with ChildEnv)
     */
    public static function launch(string $command, array $args, string $cwd, array $env): ProviderProcess
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return WindowsProviderProcess::launch($command, $args, $cwd, $env);
        }

        throw new ContainmentUnavailable('cannot contain the provider: this release supports Windows Job Objects only');
    }
}
