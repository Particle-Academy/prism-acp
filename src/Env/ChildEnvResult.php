<?php

declare(strict_types=1);

namespace Prism\Acp\Env;

final readonly class ChildEnvResult
{
    /**
     * @param  array<string, string>  $env  the environment to hand to the child
     * @param  list<string>  $withheld  NAMES of outranking credentials found and withheld; never their values
     */
    public function __construct(
        public array $env,
        public array $withheld,
    ) {}
}
