<?php

declare(strict_types=1);

namespace Prism\Acp\Support {
    use Prism\Acp\Env\ChildEnv;
    use Prism\Acp\Process\WindowsCommand;

    function proc_open(array|string $command, array $descriptors, ?array &$pipes, ?string $cwd = null, ?array $env = null, ?array $options = null): mixed
    {
        if (! is_array($command)) {
            throw new \LogicException('the probe must bypass shell command parsing');
        }
        $index = array_search('-EncodedCommand', $command, true);
        $script = $index === false ? false : base64_decode($command[$index + 1], true);
        $readable = $script === false ? false : mb_convert_encoding($script, 'UTF-8', 'UTF-16LE');
        $GLOBALS['probe_scripts'][] = $readable;
        $GLOBALS['probe_flags'] = $GLOBALS['probe_flags'] && in_array('-NoProfile', $command, true) && in_array('-NonInteractive', $command, true);
        $GLOBALS['probe_absolute'] = $GLOBALS['probe_absolute'] && WindowsCommand::isAbsolute($command[0]) && str_ends_with(strtolower($command[0]), '\\windowspowershell\\v1.0\\powershell.exe');
        $GLOBALS['probe_literal'] = $GLOBALS['probe_literal'] && ($env['PRISM_ACP_DIRECTORY_DATA'] ?? null) === base64_encode($GLOBALS['probe_expected_home']);
        $GLOBALS['probe_no_credentials'] = $GLOBALS['probe_no_credentials'] && array_intersect(array_map('strtoupper', array_keys($env ?? [])), ChildEnv::OUTRANKING_CREDENTIALS) === [];

        return \proc_open($command, $descriptors, $pipes, $cwd, $env, $options);
    }
}

namespace {
    use Prism\Acp\Credentials\ApiKeyHome;
    use Prism\Acp\Credentials\ProviderCredentials;
    use Prism\Acp\Support\PrivateDirectory;

    require $argv[1];
    $GLOBALS['probe_scripts'] = [];
    $GLOBALS['probe_flags'] = $GLOBALS['probe_absolute'] = $GLOBALS['probe_literal'] = $GLOBALS['probe_no_credentials'] = true;
    $leaf = <<<'NAME'
    quote'-$(throw 'owned-injection')-`-;-é
    NAME;
    $homes = [$argv[2].DIRECTORY_SEPARATOR.'ordinary', $argv[2].DIRECTORY_SEPARATOR.$leaf];
    $admitted = 0;
    try {
        foreach ($homes as $home) {
            $GLOBALS['probe_expected_home'] = $home;
            $checked = ApiKeyHome::check('claude', new ProviderCredentials('apiKey', 'owned-dummy-key', $home), []);
            if ($checked->home !== $home || $checked->key() !== 'owned-dummy-key') {
                throw new LogicException('literal owned home was rewritten');
            }
            $checked->environment('claude');
            $admitted++;
        }
        $constant = (new ReflectionClass(PrivateDirectory::class))->getReflectionConstant('WINDOWS_PERMISSION_SCRIPT')->getValue();
        $same = $GLOBALS['probe_scripts'] !== [];
        foreach ($GLOBALS['probe_scripts'] as $script) {
            $same = $same && $script === $constant;
        }
        echo json_encode(['admitted' => $admitted, 'same_script' => $same, 'calls' => count($GLOBALS['probe_scripts']), 'flags' => $GLOBALS['probe_flags'], 'absolute' => $GLOBALS['probe_absolute'], 'literal' => $GLOBALS['probe_literal'], 'no_credentials' => $GLOBALS['probe_no_credentials']], JSON_THROW_ON_ERROR);
    } finally {
        foreach ($homes as $home) {
            if (is_dir($home)) {
                rmdir($home);
            }
        }
    }
}
