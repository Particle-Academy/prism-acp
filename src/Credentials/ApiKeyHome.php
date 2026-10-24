<?php

declare(strict_types=1);

namespace Prism\Acp\Credentials;

use Prism\Acp\Process\WindowsCommand;
use Prism\Acp\Support\Js;
use Prism\Acp\Support\PrivateDirectory;

/** Checked key and native home. Existing app-owned homes are accepted; only new homes are made private. */
final readonly class ApiKeyHome
{
    private function __construct(#[\SensitiveParameter] private string $apiKey, public string $home) {}

    /** @param array<string,string|null> $parentEnv */
    public static function check(string $provider, #[\SensitiveParameter] ?ProviderCredentials $credentials, array $parentEnv): ?self
    {
        if ($credentials === null || $credentials->mode === 'subscription') {
            return null;
        }
        if ($credentials->mode !== 'apiKey') {
            throw new \InvalidArgumentException('credentials.mode must be "subscription" or "apiKey"');
        }
        $key = $credentials->key();
        if ($key === null || Js::trim($key) === '' || preg_match('/[\x00-\x1f\x7f]/', $key) === 1) {
            throw new \InvalidArgumentException('credentials.apiKey must be a non-empty key with no control characters');
        }
        if ($credentials->home === null || ! self::absolute($credentials->home)) {
            throw new \InvalidArgumentException('credentials.home must be an absolute path');
        }
        $home = self::normalize($credentials->home);
        $variable = self::variable($provider);
        $nativeHome = PHP_OS_FAMILY === 'Windows' ? (getenv('USERPROFILE') ?: ((getenv('HOMEDRIVE') ?: '').(getenv('HOMEPATH') ?: ''))) : getenv('HOME');
        if (! is_string($nativeHome) || ! self::absolute($nativeHome)) {
            throw new \RuntimeException('cannot determine the native subscription configuration home');
        }
        $subscriptionHomes = [self::normalize($nativeHome.DIRECTORY_SEPARATOR.'.'.$provider)];
        foreach ($parentEnv as $name => $value) {
            if (strtoupper((string) $name) === $variable) {
                $moved = Js::trim($value ?? '');
                if ($moved !== '' && self::absolute($moved)) {
                    $subscriptionHomes[] = self::normalize($moved);
                }
                break;
            }
        }
        foreach ($subscriptionHomes as $subscriptionHome) {
            if (self::canonical($home) === self::canonical($subscriptionHome)) {
                throw new \InvalidArgumentException("credentials.home is this user's own {$provider} configuration home: an API key there would replace or mix with their subscription login");
            }
        }
        if (file_exists($home) || is_link($home)) {
            if (! is_dir($home)) {
                throw new \InvalidArgumentException('credentials.home exists and is not a directory');
            }
        } else {
            $created = false;
            try {
                if (! @mkdir($home, 0700, true)) {
                    throw new \RuntimeException('home creation failed');
                }
                $created = true;
                PrivateDirectory::protect($home);
            } catch (\Throwable) {
                // A failed first attempt must not leave an unprotected home that a retry treats as app-provided.
                if ($created) {
                    @rmdir($home);
                }
                throw new \RuntimeException('credentials.home could not be created as a private directory');
            }
        }

        return new self($key, $home);
    }

    /** @return array<string,string> */
    public function environment(string $provider): array
    {
        return $provider === 'claude' ? ['CLAUDE_CONFIG_DIR' => $this->home, 'ANTHROPIC_API_KEY' => $this->apiKey]
            : [self::variable($provider) => $this->home];
    }

    public function key(): string
    {
        return $this->apiKey;
    }

    /** @return array{home:string,apiKey:string} */
    public function __debugInfo(): array
    {
        return ['home' => $this->home, 'apiKey' => '[redacted]'];
    }

    private static function variable(string $provider): string
    {
        return match ($provider) {
            'claude' => 'CLAUDE_CONFIG_DIR', 'codex' => 'CODEX_HOME',
            default => throw new \InvalidArgumentException('credential provider must be claude or codex'),
        };
    }

    private static function absolute(string $path): bool
    {
        return PHP_OS_FAMILY === 'Windows' ? WindowsCommand::isAbsolute($path) : str_starts_with($path, '/');
    }

    /** Lexical absolute resolution, including dot segments, without requiring the directory to exist. */
    private static function normalize(string $path): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $path = str_replace('\\', '/', $path);
            if (str_starts_with($path, '//')) {
                $parts = explode('/', substr($path, 2));
                $prefix = '//'.array_shift($parts).'/'.array_shift($parts).'/';
            } else {
                $prefix = substr($path, 0, 3);
                $parts = explode('/', substr($path, 3));
            }
        } else {
            $prefix = '/';
            $parts = explode('/', $path);
        }
        $stack = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($stack);
            } else {
                $stack[] = $part;
            }
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $prefix.implode('/', $stack));
    }

    /** Resolve existing junction/symlink ancestors too, so a missing child cannot conceal an alias. */
    private static function canonical(string $path): string
    {
        $suffix = [];
        $ancestor = $path;
        while (($real = realpath($ancestor)) === false) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                $real = $ancestor;
                break;
            }
            array_unshift($suffix, basename($ancestor));
            $ancestor = $parent;
        }
        // PHP expands Windows directory junctions one level per realpath call. Follow the whole chain.
        $stable = false;
        for ($hop = 0; $hop < 64; $hop++) {
            $next = realpath($real);
            if ($next === false) {
                break;
            }
            if ($next === $real) {
                $stable = true;
                break;
            }
            $real = $next;
        }
        if (! $stable) {
            throw new \RuntimeException('credentials.home could not be resolved safely');
        }
        $canonical = rtrim($real, '\\/').($suffix === [] ? '' : DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $suffix));

        return PHP_OS_FAMILY === 'Linux' ? $canonical : mb_strtolower($canonical, 'UTF-8');
    }
}
