<?php

// The shipped POSIX supervisor, with its environment reader observed (tests only; see
// PosixProviderProcess::useSupervisorScript). PRISM_TEST_ENVIRON chooses how:
//   record:<file>  append one line per environment read to <file> (never what was read), then read as shipped
//   throw          any environment read throws, which ends this supervisor before it reports

declare(strict_types=1);
use Prism\Acp\Process\PosixTree;

require_once dirname(__DIR__, 2).'/src/Process/PosixTree.php';

$observe = (string) getenv('PRISM_TEST_ENVIRON');
$prismAcpEnvironReader = str_starts_with($observe, 'record:')
    ? static function (string $path) use ($observe): ?string {
        file_put_contents(substr($observe, 7), "read\n", FILE_APPEND | LOCK_EX);

        return PosixTree::readEnviron($path);
    }
: static function (string $path): ?string {
    throw new RuntimeException('an environment was read');
};

require dirname(__DIR__, 2).'/resources/posix/supervisor.php';
