<?php

declare(strict_types=1);

namespace Prism\Acp\Credentials;

use Prism\Acp\Support\Js;

/** Published TS0.25.1 semantics over decoded text: release safe diagnostics and retain only a possible key prefix. */
final class SecretRedactor
{
    public const REDACTED = '[redacted]';

    private string $held = '';

    public function __construct(#[\SensitiveParameter] private readonly string $secret)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('a secret to redact cannot be empty');
        }
    }

    public function push(string $chunk): string
    {
        $text = str_replace($this->secret, self::REDACTED, $this->held.$chunk);
        $keep = $this->pendingPrefix($text);
        $this->held = substr($text, strlen($text) - $keep);

        return substr($text, 0, strlen($text) - $keep);
    }

    public function end(): string
    {
        $held = $this->held;
        $this->held = '';

        return Js::length($held) >= 4 ? self::REDACTED : $held;
    }

    private function pendingPrefix(string $text): int
    {
        // Inputs are complete decoded UTF-8 chunks; any matching suffix is a complete prefix of the key.
        for ($length = min(strlen($text), strlen($this->secret) - 1); $length > 0; $length--) {
            if (str_starts_with($this->secret, substr($text, -$length))) {
                return $length;
            }
        }

        return 0;
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return [];
    }
}
