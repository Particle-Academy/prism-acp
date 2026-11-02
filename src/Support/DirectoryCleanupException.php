<?php

declare(strict_types=1);

namespace Prism\Acp\Support;

/** A newly created directory could not be removed after protection failed. */
final class DirectoryCleanupException extends \RuntimeException
{
    public readonly string $kind;

    public function __construct()
    {
        $this->kind = 'cleanup_failed';
        parent::__construct('Private directory cleanup failed. Inspect and remove the app-created directory using its trusted path, then choose a new private directory before retrying.');
    }
}
