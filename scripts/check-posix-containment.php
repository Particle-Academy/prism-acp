<?php

declare(strict_types=1);

// CI, Linux and macOS: fail unless POSIX containment is available on this runner. The containment tests skip, with a
// visible reason, where ext-pcntl, ext-posix or /proc is missing; this makes such a skip a red build, not a green one.
// With the argument `cgroup`, also fail unless Linux cgroup mode is the one a launch would use.

require dirname(__DIR__).'/vendor/autoload.php';

use Prism\Acp\Process\PosixTree;
use Prism\Acp\Process\Provider;

$missing = PosixTree::missing();
if ($missing !== []) {
    fwrite(STDERR, '::error::POSIX containment unavailable on '.PHP_OS_FAMILY.': '.implode(', ', $missing)."\n");
    exit(1);
}
$containment = Provider::containment();
echo PHP_OS_FAMILY.' '.json_encode($containment, JSON_UNESCAPED_SLASHES)."\n";
if (($argv[1] ?? '') === 'cgroup' && $containment['mode'] !== 'cgroup') {
    fwrite(STDERR, '::error::cgroup containment is not active here: '.($containment['reason'] ?? $containment['mode'])."\n");
    exit(1);
}
