<?php

declare(strict_types=1);

use Prism\Acp\Ndjson\Json;
use Prism\Acp\Support\HiddenCharacters;

/*
 * Every code point, checked against the TypeScript reference's own sets as its runtime matches them
 * (tests/fixtures/hidden-characters.json, recorded by scripts/hidden-characters.mjs from prism-acp-ts's source). The
 * PHP sets are spelled out code point by code point, so a difference is a difference in the lists, never in which
 * Unicode version an engine was built with.
 */

/** @return array<int, true> each code point the recording lists */
function recordedSet(string $name): array
{
    $recording = Json::decode((string) file_get_contents(dirname(__DIR__).'/fixtures/hidden-characters.json'));
    $set = [];
    foreach ($recording->sets->{$name}->ranges as [$first, $last]) {
        for ($cp = $first; $cp <= $last; $cp++) {
            $set[$cp] = true;
        }
    }

    return $set;
}

it('matches the reference on every code point', function (string $name, string $pattern): void {
    $recorded = recordedSet($name);
    $differences = [];
    for ($cp = 0; $cp <= 0x10FFFF; $cp++) {
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            continue;
        }
        $php = preg_match($pattern, mb_chr($cp, 'UTF-8')) === 1;
        if ($php !== isset($recorded[$cp])) {
            $differences[] = sprintf('U+%04X %s', $cp, $php ? 'PHP only' : 'reference only');
        }
    }
    expect($differences)->toBe([]);
})->with([
    'plan ids and paths' => ['plan', HiddenCharacters::PLAN],
    'markdown content' => ['markdown', HiddenCharacters::MARKDOWN],
    'provider text' => ['provider', HiddenCharacters::PROVIDER],
]);

it('was recorded from the reference this port follows', function (): void {
    $recording = Json::decode((string) file_get_contents(dirname(__DIR__).'/fixtures/hidden-characters.json'));
    expect($recording->reference)->toBe('@particle-academy/prism-acp 0.48.0')
        ->and(str_contains($recording->sets->plan->pattern, '\p{Cf}'))->toBeTrue();
});

it('treats text that is not valid UTF-8, such as an encoded lone surrogate, as hidden', function (string $text): void {
    expect(HiddenCharacters::inPlanText($text))->toBeTrue()
        ->and(HiddenCharacters::inMarkdown($text))->toBeTrue();
})->with([["a\xED\xA0\x80"], ["a\xED\xBF\xBF"], ["\xFF"], ["a\xC3"], ["\xC0\xAF"]]);

it('refuses a carriage return in markdown only when no line feed follows it', function (): void {
    expect(HiddenCharacters::inMarkdown("a\r\nb"))->toBeFalse()
        ->and(HiddenCharacters::inMarkdown("a\rb"))->toBeTrue()
        ->and(HiddenCharacters::inMarkdown("a\r"))->toBeTrue()
        ->and(HiddenCharacters::inMarkdown("a\r\r\n"))->toBeTrue()
        ->and(HiddenCharacters::inPlanText("a\r\nb"))->toBeTrue();
});
