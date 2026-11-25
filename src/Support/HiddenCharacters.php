<?php

declare(strict_types=1);

namespace Prism\Acp\Support;

/**
 * The characters a client would not show, or that change what it shows, spelled out code point by code point.
 *
 * Each set is written out here rather than taken from `\p{Cf}`, `trim()` or another engine default. An engine
 * default follows whichever Unicode version that engine was built with, so two languages can disagree without
 * either one changing its code. `FORMAT` lists every format character (`Cf`) of Unicode 17.0, the version the
 * TypeScript reference's runtime uses. tests/fixtures/hidden-characters.json records each set as the TypeScript
 * reference matches it, and HiddenCharactersTest checks every code point against that recording.
 *
 * A PHP string cannot hold a lone surrogate. The UTF-8 encoding of one (ED A0 80 to ED BF BF) is not valid UTF-8, so
 * every check here treats text that is not valid UTF-8 as holding a hidden character.
 */
final class HiddenCharacters
{
    /**
     * Every format character (`Cf`), Unicode 17.0: soft hyphen, Arabic number signs, the Arabic letter mark,
     * zero-width characters, bidi marks, embeddings, overrides and isolates, invisible operators, the BOM,
     * interlinear annotation, Kaithi, Egyptian hieroglyph and shorthand format controls, musical symbol formatting,
     * and tag characters.
     */
    public const FORMAT = '\x{00AD}\x{0600}-\x{0605}\x{061C}\x{06DD}\x{070F}\x{0890}-\x{0891}\x{08E2}\x{180E}'
        .'\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}\x{FFF9}-\x{FFFB}'
        .'\x{110BD}\x{110CD}\x{13430}-\x{1343F}\x{1BCA0}-\x{1BCA3}\x{1D173}-\x{1D17A}\x{E0001}\x{E0020}-\x{E007F}';

    /**
     * Not shown, or not one line: every C0 control (tab and line breaks included), DEL, C1, every format character,
     * variation selectors, the combining grapheme joiner, Mongolian variation selectors, the braille blank, Hangul
     * fillers and the line and paragraph separators. prism-acp-ts's plan.ts HIDDEN is the same set.
     */
    public const PLAN = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}'.self::FORMAT.'\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}'
        .'\x{034F}\x{180B}-\x{180F}\x{2800}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{2028}\x{2029}]/u';

    /**
     * What markdown plan content may not hold: C0 and C1 controls other than tab and line feed, a carriage return not
     * followed by a line feed, bidi marks, embeddings, overrides and isolates, the deprecated format characters, the
     * interlinear annotation marks, the line and paragraph separators and the BOM. Line breaks (LF, CRLF) and
     * zero-width joiners are allowed, because markdown and emoji need them. prism-acp-ts's agent.ts MARKDOWN_HIDDEN
     * is the same set.
     */
    public const MARKDOWN = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{200E}'
        .'\x{200F}\x{202A}-\x{202E}\x{2066}-\x{206F}\x{2028}\x{2029}\x{FEFF}\x{FFF9}-\x{FFFB}]|\r(?!\n)/u';

    /**
     * What provider text has marked: PLAN without tab and line feed, which provider text may keep. prism-acp-ts's
     * text.ts HIDDEN is the same set.
     */
    public const PROVIDER = '/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}-\x{009F}'.self::FORMAT.'\x{FE00}-\x{FE0F}'
        .'\x{E0100}-\x{E01EF}\x{034F}\x{180B}-\x{180F}\x{2800}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{2028}\x{2029}]/u';

    /** Whether text holds a character PLAN names, or is not valid UTF-8. */
    public static function inPlanText(string $value): bool
    {
        return self::holds(self::PLAN, $value);
    }

    /** Whether markdown holds a character MARKDOWN names, or is not valid UTF-8. */
    public static function inMarkdown(string $value): bool
    {
        return self::holds(self::MARKDOWN, $value);
    }

    private static function holds(string $pattern, string $value): bool
    {
        // preg_match answers false, not 0, for text that is not valid UTF-8; that counts as holding one too.
        return ! mb_check_encoding($value, 'UTF-8') || preg_match($pattern, $value) !== 0;
    }
}
