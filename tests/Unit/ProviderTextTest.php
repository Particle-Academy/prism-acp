<?php

declare(strict_types=1);

use Prism\Acp\Support\HiddenCharacters;
use Prism\Acp\Support\Js;
use Prism\Acp\Support\ProviderText;

/*
 * ProviderText::bound, as prism-acp-ts's boundProviderText: hidden and control characters marked as <U+XXXX>, then
 * bounded to a number of UTF-16 code units with ProviderText::TRUNCATED appended when cut. Provider text can be any
 * size, so the work done must follow the bound, not the input.
 */

/** The reference's algorithm over the whole input, with no shortcut: what bound() must equal. */
function boundWhole(string $value, int $max): string
{
    $marked = (string) preg_replace_callback(
        HiddenCharacters::PROVIDER,
        static fn (array $match): string => sprintf('<U+%04X>', mb_ord($match[0], 'UTF-8')),
        Js::scrub($value),
    );
    if (Js::length($marked) <= $max) {
        return $marked;
    }
    $cut = Js::slice($marked, $max);
    $mark = strrpos($cut, '<U+');
    if ($mark !== false && ! str_contains(substr($cut, $mark), '>')) {
        $cut = substr($cut, 0, $mark);
    }

    return $cut.ProviderText::TRUNCATED;
}

it('bounds very large text without holding it as characters', function (): void {
    memory_reset_peak_usage();
    $before = memory_get_peak_usage();
    $text = str_repeat("r\e", 4_000_000);
    $bounded = ProviderText::bound($text);
    $used = memory_get_peak_usage() - $before - strlen($text);
    expect(str_ends_with($bounded, ProviderText::TRUNCATED))->toBeTrue()
        ->and(Js::length($bounded))->toBeLessThanOrEqual(ProviderText::MAX + 12)
        ->and($used)->toBeLessThan(16 * 1024 * 1024);
});

it('gives the same result as marking and cutting the whole text', function (string $value, int $max): void {
    expect(ProviderText::bound($value, $max))->toBe(boundWhole($value, $max));
})->with(function (): Generator {
    $pieces = ['a', 'é', "\u{1F600}", "\e", "\u{202E}", "\r", "\n", "\t", "\xC3", "\xED\xA0\x80", "\xFF", '<U+', '>', "\u{E0041}"];
    mt_srand(377);
    foreach ([1, 2, 7, 8, 9, 16, 33] as $max) {
        for ($case = 0; $case < 40; $case++) {
            $value = '';
            $length = mt_rand(0, 4 * $max + 12);
            for ($i = 0; $i < $length; $i++) {
                $value .= $pieces[mt_rand(0, count($pieces) - 1)];
            }
            yield "max {$max} case {$case}" => [$value, $max];
        }
    }
    yield 'default bound, astral at the cut' => [str_repeat('a', ProviderText::MAX - 1)."\u{1F600}tail", ProviderText::MAX];
    yield 'default bound, mark at the cut' => [str_repeat('a', ProviderText::MAX - 3)."\e".'tail', ProviderText::MAX];
    yield 'default bound, invalid UTF-8 at the end' => [str_repeat("\u{1F600}", 3000)."\xC3", ProviderText::MAX];
});
