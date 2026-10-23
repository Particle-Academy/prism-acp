<?php

declare(strict_types=1);

namespace Prism\Acp\Prompt;

use Prism\Acp\Support\Js;

/**
 * Prompt content: what a person sends in one turn, as ACP carries it. A block is ACP's own prompt ContentBlock, field
 * for field (`text`; `image` with base64 `data` and `mimeType`; `resource` holding `text` or a base64 `blob`), as the
 * stdClass the wire delivered, so a client's blocks reach a driver unchanged. A plain string is one text block.
 *
 * Ported from prism-acp-ts src/prompt.ts.
 */
final class Prompt
{
    /** The kinds a driver may declare it takes. */
    public const KINDS = ['text', 'image', 'resource-text', 'resource-blob'];

    /** The image types both providers document taking (Anthropic's vision guide: JPEG, PNG, GIF, WebP). */
    public const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    private const KIND_LABEL = [
        'text' => 'text',
        'image' => 'images',
        'resource-text' => 'text files',
        'resource-blob' => 'binary files',
    ];

    /** The kind of a block, or null when it is not one of ACP's prompt blocks this package knows how to send. */
    public static function blockKind(mixed $block): ?string
    {
        $type = Js::get($block, 'type');
        if ($type === 'text') {
            return 'text';
        }
        if ($type === 'image') {
            return 'image';
        }
        if ($type === 'resource') {
            $resource = Js::get($block, 'resource');
            if (Js::string($resource, 'text') !== null) {
                return 'resource-text';
            }
            if (Js::string($resource, 'blob') !== null) {
                return 'resource-blob';
            }
        }

        return null;
    }

    /**
     * Check a prompt against what a driver takes and return its blocks. Throws PromptRefused for the FIRST block it
     * will not send, so nothing is written for a prompt that would arrive incomplete.
     *
     * @param  string|list<mixed>  $content
     * @param  list<string>  $accepts
     * @return list<\stdClass>
     */
    public static function blocks(string|array $content, array $accepts): array
    {
        $blocks = is_string($content) ? [(object) ['type' => 'text', 'text' => $content]] : $content;
        if ($blocks === []) {
            throw new \InvalidArgumentException('a prompt needs at least one block');
        }
        $checked = [];
        foreach ($blocks as $index => $block) {
            $kind = self::blockKind($block);
            if ($kind === null || ! $block instanceof \stdClass) {
                throw new PromptRefused($index, self::describe($block), 'not a prompt block this driver can send');
            }
            if (! in_array($kind, $accepts, true)) {
                throw new PromptRefused($index, self::describe($block), 'this driver does not take '.self::KIND_LABEL[$kind]);
            }
            if ($kind === 'text' && Js::string($block, 'text') === null) {
                throw new PromptRefused($index, 'text', 'it has no text');
            }
            if ($kind === 'image') {
                $mime = Js::string($block, 'mimeType');
                if ($mime === null || ! in_array($mime, self::IMAGE_TYPES, true)) {
                    throw new PromptRefused($index, self::describe($block), 'images must be one of '.implode(', ', self::IMAGE_TYPES));
                }
                $data = Js::string($block, 'data');
                if ($data === null || $data === '' || preg_match('#\A[A-Za-z0-9+/]+={0,2}\z#', $data) !== 1) {
                    throw new PromptRefused($index, self::describe($block), 'its data is not base64 image bytes');
                }
            }
            if ($kind === 'resource-text' || $kind === 'resource-blob') {
                $resource = Js::get($block, 'resource');
                $uri = Js::string($resource, 'uri');
                if ($uri === null || $uri === '') {
                    throw new PromptRefused($index, self::describe($block), 'it has no uri');
                }
                if ($kind === 'resource-blob' && Js::string($resource, 'blob') === '') {
                    throw new PromptRefused($index, self::describe($block), 'its blob is empty');
                }
            }
            $checked[] = $block;
        }

        return $checked;
    }

    /**
     * A text file as prompt text: its uri and type on an opening line, then its text, then a closing line, so the
     * model can tell where the file starts and ends and which file it is.
     */
    public static function fileAsText(\stdClass $resource): string
    {
        $mime = Js::string($resource, 'mimeType');
        $label = $mime === null ? '' : " ({$mime})";

        return '<file '.Js::string($resource, 'uri').$label.">\n".Js::string($resource, 'text')."\n</file>";
    }

    /** What a block is, for a refusal: its MIME type where it has one, else its ACP type. */
    private static function describe(mixed $block): string
    {
        if (! $block instanceof \stdClass) {
            return match (true) {
                is_string($block) => 'string',
                is_int($block), is_float($block) => 'number',
                is_bool($block) => 'boolean',
                // JavaScript's typeof: null and an array are both "object".
                default => 'object',
            };
        }
        $resource = Js::get($block, 'resource');
        $mime = Js::string($block, 'mimeType') ?? Js::string($resource, 'mimeType');
        $type = Js::string($block, 'type') ?? 'untyped';

        return $mime === null ? $type : "{$type} {$mime}";
    }
}
