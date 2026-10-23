<?php

declare(strict_types=1);

namespace Prism\Acp\Process;

/**
 * A provider could not be started inside a contained process tree, or the tree could not be prepared. The message names
 * its cause and never a path. Nothing is ever started uncontained instead.
 */
class ProcessTreeException extends \RuntimeException {}
