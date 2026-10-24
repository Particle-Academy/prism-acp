<?php

declare(strict_types=1);

namespace Prism\Acp\Codex;

final readonly class CodexTransportHandlers
{
    /** @param \Closure(mixed):void $onFrame
     * @param  \Closure(string):void  $onStderr
     * @param  \Closure(string, ?bool):void  $onProblem
     * @param  \Closure(?int, ?string):void  $onExit
     */
    public function __construct(
        public \Closure $onFrame,
        public \Closure $onStderr,
        public \Closure $onProblem,
        public \Closure $onExit,
    ) {}
}
