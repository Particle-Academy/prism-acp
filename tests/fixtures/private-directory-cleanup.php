<?php

declare(strict_types=1);

namespace Prism\Acp\Support {
    use Prism\Acp\Claude\ClaudeDriver;

    function rmdir(string $path): bool
    {
        if ($path === $GLOBALS['owned_cleanup_path']) {
            $GLOBALS['owned_cleanup_attempts']++;
            $field = new \ReflectionProperty(ClaudeDriver::class, $GLOBALS['owned_cleanup_field']);
            $GLOBALS['retained_during_failure'] = $GLOBALS['retained_during_failure'] || $field->getValue($GLOBALS['owned_driver']) === $path;
            if ($GLOBALS['owned_cleanup_denials']-- > 0) {
                return false;
            }
        }

        return \rmdir($path);
    }
}

namespace {
    use Prism\Acp\Agent\DriverEvents;
    use Prism\Acp\Async\Loop;
    use Prism\Acp\Claude\ClaudeDriver;
    use Prism\Acp\Claude\ClaudeDriverOptions;
    use Prism\Acp\Support\PrivateDirectory;

    require $argv[1];
    $root = $argv[2];
    $kind = $argv[3];
    $persistent = $argv[4] === 'persistent';
    $home = PrivateDirectory::create($kind === 'approvals' ? 'prism-acp-approve-' : 'prism-acp-mcp-');
    $marker = $home.DIRECTORY_SEPARATOR.'empty-marker';
    $file = fopen($marker, 'x');
    fclose($file);
    $loop = new Loop;
    $problems = [];
    $driver = new ClaudeDriver(new ClaudeDriverOptions($root), new DriverEvents(onProtocolError: function (string $message, ?bool $recoverable) use (&$problems): void {
        $problems[] = ['message' => $message, 'recoverable' => $recoverable];
    }), $loop);
    $fields = $kind === 'approvals' ? ['approvalDirectory' => $home, 'approvalFiles' => [$marker]] : ['mcpConfigDirectory' => $home, 'mcpConfigPath' => $marker];
    foreach ($fields as $name => $value) {
        (new ReflectionProperty(ClaudeDriver::class, $name))->setValue($driver, $value);
    }
    $GLOBALS['owned_driver'] = $driver;
    $GLOBALS['owned_cleanup_path'] = $home;
    $GLOBALS['owned_cleanup_field'] = $kind === 'approvals' ? 'approvalDirectory' : 'mcpConfigDirectory';
    $GLOBALS['owned_cleanup_denials'] = $persistent ? 100 : 1;
    $GLOBALS['owned_cleanup_attempts'] = 0;
    $GLOBALS['retained_during_failure'] = false;
    try {
        $verdict = $driver->stop(0)->await();
        $driver->stop(0)->await(); // Repeated stops must not turn the single retry into an unbounded loop.
        clearstatcache(true, $home);
        $retained = (new ReflectionProperty(ClaudeDriver::class, $GLOBALS['owned_cleanup_field']))->getValue($driver);
        echo json_encode([
            'attempts' => $GLOBALS['owned_cleanup_attempts'], 'retained_during_failure' => $GLOBALS['retained_during_failure'],
            'removed' => ! is_dir($home), 'retained_after_terminal_failure' => $retained === $home,
            'verdict' => $verdict->toArray(), 'terminal_failures' => count($problems),
            'terminal_not_recoverable' => $problems === [] || $problems[0]['recoverable'] === false,
            'safe_message' => $problems === [] || ! str_contains($problems[0]['message'], $home),
        ], JSON_THROW_ON_ERROR);
    } finally {
        if (is_file($marker)) {
            unlink($marker);
        }
        if (is_dir($home)) {
            rmdir($home);
        }
    }
}
