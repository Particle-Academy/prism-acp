<?php

declare(strict_types=1);

namespace Prism\Acp\Codex;

final readonly class CodexTransportOptions
{
    /** @param array<string, string> $env
     * @param  list<string>  $binaryArgs
     */
    public function __construct(
        public string $cwd,
        public array $env,
        public string $binary = 'codex',
        public array $binaryArgs = [],
    ) {}
}
