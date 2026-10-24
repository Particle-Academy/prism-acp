<?php

declare(strict_types=1);

namespace Prism\Acp\Codex;

use Prism\Acp\JsonRpc\RpcError;
use Prism\Acp\Support\Js;
use Prism\Acp\Support\Undefined;
use Prism\Acp\Text;

/** Shared field/privacy and bounded-text rules from the pinned Codex driver. */
final class CodexPayload
{
    public static function stripPrivateIdentifiers(mixed $value, bool $sensitive = false): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $entry): mixed => self::stripPrivateIdentifiers($entry, $sensitive), $value);
        }
        if (! $value instanceof \stdClass) {
            return $value;
        }
        $result = new \stdClass;
        foreach (get_object_vars($value) as $key => $entry) {
            $normalized = strtolower(str_replace(['_', '-'], '', (string) $key));
            if (preg_match('/(?:account|credit|ratelimit|limit).*id\z/', $normalized) === 1 || ($sensitive && $normalized === 'id')) {
                continue;
            }
            $child = $sensitive || in_array($normalized, ['account', 'ratelimit', 'ratelimits', 'limit', 'ratelimitresetcredits', 'credit', 'credits'], true);
            $result->{$key} = self::stripPrivateIdentifiers($entry, $child);
        }

        return $result;
    }

    public static function describe(mixed $value): string
    {
        return match (true) {
            $value === Undefined::Value => 'undefined', $value === null => 'null',
            is_string($value) => 'string('.Js::length($value).')',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => Js::numberToString($value),
            is_array($value) => 'array('.count($value).')', default => 'object',
        };
    }

    /** @return array<string,mixed> */
    public static function publicUpdate(string $sessionUpdate, \stdClass $value): array
    {
        /** @var \stdClass $public */
        $public = self::stripPrivateIdentifiers($value);
        $update = ['sessionUpdate' => $sessionUpdate];
        // Assignment preserves numeric JSON property names; PHP array unpacking would renumber them.
        foreach (get_object_vars($public) as $key => $entry) {
            $update[(string) $key] = $entry;
        }

        return $update;
    }

    public static function safeError(string $prefix, \Throwable $cause): string
    {
        if ($cause instanceof RpcError) {
            $whitespace = '/[\x09-\x0D\x20\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u';
            $message = Js::slice(Js::trim((string) preg_replace($whitespace, ' ', Text::stripAnsiControlSequences($cause->getMessage())['text'])), 300);

            return $prefix.' (RPC code '.$cause->rpcCode.($message === '' ? '' : ': '.$message).')';
        }

        // PHP's exception types differ from JS's Error. The cross-language contract is structural, never its message.
        return $prefix.' (Error)';
    }

    /** Last UTF-16 units, without manufacturing a lone surrogate in a PHP UTF-8 string. */
    public static function tail(string $text, int $units): string
    {
        $encoded = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        if (strlen($encoded) <= $units * 2) {
            return $text;
        }
        $suffix = substr($encoded, -$units * 2);
        $first = ord($suffix[0]) | (ord($suffix[1]) << 8);
        if ($first >= 0xDC00 && $first <= 0xDFFF) {
            $suffix = substr($suffix, 2);
        }

        return mb_convert_encoding($suffix, 'UTF-8', 'UTF-16LE');
    }

    /** @return list<array{type: string, content: array{type: string, text: string}}> */
    public static function textToolContent(string $text): array
    {
        return [['type' => 'content', 'content' => ['type' => 'text', 'text' => $text]]];
    }

    public static function textFromContent(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (! is_array($value)) {
            return '';
        }

        return implode('', array_map(fn (mixed $entry): string => Js::string($entry, 'text') ?? '', $value));
    }

    public static function status(mixed $status, mixed $exitCode = null): string
    {
        if ($status === 'failed' || $status === 'declined' || (Js::isNumber($exitCode) && $exitCode !== 0 && $exitCode !== 0.0)) {
            return 'failed';
        }
        if ($status === 'completed' || ($status === 'exited' && ($exitCode === 0 || $exitCode === 0.0))) {
            return 'completed';
        }

        return 'in_progress';
    }
}
