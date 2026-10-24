<?php

declare(strict_types=1);

namespace Prism\Acp\Credentials;

/** App/tenant configuration, never accepted from ACP or model output. No key prefix/length policy is imposed. */
final readonly class ProviderCredentials
{
    public function __construct(
        public string $mode = 'subscription',
        #[\SensitiveParameter] private ?string $apiKey = null,
        public ?string $home = null,
    ) {}

    public function key(): ?string
    {
        return $this->apiKey;
    }

    /** @return array{mode:string,apiKey:string,home:?string} */
    public function __debugInfo(): array
    {
        return ['mode' => $this->mode, 'apiKey' => '[redacted]', 'home' => $this->home];
    }
}
