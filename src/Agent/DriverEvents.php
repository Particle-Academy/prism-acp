<?php

declare(strict_types=1);

namespace Prism\Acp\Agent;

use Prism\Acp\Async\Deferred;

/**
 * What a driver reports, as callbacks. Ported from prism-acp-ts's `DriverEvents`.
 *
 * - onUpdate: one ACP `session/update` payload.
 * - onRequestPermission: a tool call waits for a person; settles with the client's choice. Anything but an explicit
 *   "allow once" denies, and so does a value that is not a PermissionOutcome at all: a driver checks what it got.
 * - onTurnEnd: one turn finished. Separate from onExit: the agent stays alive across turns.
 * - onProtocolError: a problem; `$recoverable` true means ONE thing was lost and the session goes on, null makes no
 *   such claim.
 * - onStderr: a line the agent wrote to stderr, surfaced because the clearest explanation of an auth or billing
 *   problem arrives there and nowhere else.
 * - onExit: the agent's process ended.
 */
final readonly class DriverEvents
{
    /**
     * @param  (\Closure(array<string, mixed>): void)|null  $onUpdate
     * @param  (\Closure(array<string, mixed>): Deferred<mixed>)|null  $onRequestPermission
     * @param  (\Closure(TurnOutcome): void)|null  $onTurnEnd
     * @param  (\Closure(string, ?bool): void)|null  $onProtocolError
     * @param  (\Closure(string): void)|null  $onStderr
     * @param  (\Closure(?int, ?string): void)|null  $onExit
     */
    public function __construct(
        public ?\Closure $onUpdate = null,
        public ?\Closure $onRequestPermission = null,
        public ?\Closure $onTurnEnd = null,
        public ?\Closure $onProtocolError = null,
        public ?\Closure $onStderr = null,
        public ?\Closure $onExit = null,
    ) {}
}
