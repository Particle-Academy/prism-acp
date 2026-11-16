<?php

declare(strict_types=1);

namespace Prism\Acp\Claude;

/** A malformed allowedTools/disallowedTools rule, refused before launch. Ported from prism-acp-ts 0.41.2. */
final class ClaudeToolRuleError extends \InvalidArgumentException
{
    public const CODE = 'invalid_claude_tool_rule';

    /** JavaScript's String.prototype.trim() and \s set (WhiteSpace and LineTerminator), so both ports refuse alike. */
    private const JS_SPACE = '\x{0009}\x{000A}\x{000B}\x{000C}\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /** @param 'allowedTools'|'disallowedTools' $field */
    public function __construct(public readonly string $field, public readonly ?int $index)
    {
        // The rule itself is never echoed: it is host input that may be option-shaped or carry control characters.
        parent::__construct($field.($index === null ? '' : "[{$index}]").' must contain non-empty permission rules without option prefixes, control characters or malformed Unicode');
    }

    /**
     * The rules as separate argv values, in caller order. Refuses a non-list, a non-string, an empty or JS-whitespace
     * rule, a rule whose first non-space character is `-`, C0/DEL/C1 controls, U+2028/U+2029, and invalid UTF-8 (the
     * byte-string form of the reference's lone surrogate).
     *
     * @param  'allowedTools'|'disallowedTools'  $field
     * @return list<string>
     */
    public static function rules(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new self($field, null);
        }
        foreach ($value as $index => $rule) {
            if (! is_string($rule) || preg_match('//u', $rule) !== 1
                || preg_match('/\A['.self::JS_SPACE.']*(?:-|\z)/u', $rule) === 1
                || preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]/u', $rule) === 1) {
                throw new self($field, $index);
            }
        }

        return $value;
    }
}
