<?php

declare(strict_types=1);

namespace Prism\Acp\Env;

final class ChildEnvResult
{
    /**
     * @param  array<string, string>  $env  the environment to hand to the child
     * @param  list<string>  $withheld  NAMES of outranking credentials found and withheld; never their values
     */
    public function __construct(
        public readonly array $env,
        public readonly array $withheld,
    ) {}
}
