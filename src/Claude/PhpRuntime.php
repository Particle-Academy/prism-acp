<?php

declare(strict_types=1);

namespace Prism\Acp\Claude;

use Prism\Acp\Env\ChildEnv;
use Prism\Acp\Process\ProcessTreeException;
use Prism\Acp\Process\Provider;
use Prism\Acp\Process\WindowsCommand;

/** @internal A hook needs an actual PHP CLI, never a CGI/FPM/embedded host executable or a PATH guess. */
final class PhpRuntime
{
    public static function resolve(?string $given): string
    {
        if ($given === null) {
            if (PHP_SAPI !== 'cli') {
                throw new ProcessTreeException('Claude permission prompts from a non-CLI SAPI require an explicit trusted phpBinary CLI path');
            }
            $given = PHP_BINARY;
        }
        if ($given === '' || preg_match('/[\x00\r\n]/', $given) === 1 || ! WindowsCommand::isAbsolute($given) || ! is_file($given)) {
            throw new ProcessTreeException('Claude permission prompts require an absolute existing PHP CLI path');
        }
        $path = realpath($given);
        if ($path === false) {
            throw new ProcessTreeException('the PHP CLI path could not be resolved');
        }
        $expected = 'prism-acp-cli-'.bin2hex(random_bytes(12));
        $code = 'if(PHP_SAPI!=="cli"){exit(2);}fwrite(STDOUT,'.var_export($expected, true).');';
        $probe = Provider::launch($path, ['-n', '-r', $code], sys_get_temp_dir(), ChildEnv::build(getenv())->env);
        $stdout = '';
        $stderr = '';
        try {
            $probe->closeInput();
            $deadline = microtime(true) + 2.0;
            while ($probe->isOpen() && microtime(true) < $deadline && strlen($stdout) + strlen($stderr) <= 256) {
                foreach ($probe->read(0.02) as [$kind, $bytes]) {
                    if ($kind === 'stdout') {
                        $stdout .= (string) $bytes;
                    } elseif ($kind === 'stderr') {
                        $stderr .= (string) $bytes;
                    }
                }
            }
        } finally {
            $stop = $probe->stop(0);
        }
        if ($probe->exitCode() !== 0 || $stdout !== $expected || $stderr !== '' || ! $stop->verified || ! $stop->empty || $stop->survivors !== 0) {
            throw new ProcessTreeException('the selected phpBinary did not verify as a working PHP CLI; permission prompts refused');
        }

        return $path;
    }
}
