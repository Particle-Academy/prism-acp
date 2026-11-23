<?php

declare(strict_types=1);

namespace Prism\Acp\Claude;

/** A launch was refused because its host arguments would change the forced launch settings. */
final class RemoteControlRefused extends \InvalidArgumentException
{
    public const CODE = 'remote_control_refused';

    public string $refusal = self::CODE;
}
