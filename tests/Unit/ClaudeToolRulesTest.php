<?php

declare(strict_types=1);

use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Claude\ClaudeDriverOptions;
use Prism\Acp\Claude\ClaudeToolRuleError;

// G-305, ported from prism-acp-ts 0.41.2 test/claude-tool-rules.test.ts.
const TOOL_RULES = ['Bash(echo g258, comma space)', 'Read(path with spaces,commas/**)'];

it('passes each rule as its own value after one flag', function (string $field) {
    $flag = $field === 'allowedTools' ? '--allowed-tools' : '--disallowed-tools';
    $args = ClaudeDriver::args(new ClaudeDriverOptions(...['cwd' => '/w', $field => TOOL_RULES, 'model' => 'sonnet']));

    expect(array_values(array_filter($args, fn (string $value) => $value === $flag)))->toHaveCount(1)
        ->and(array_slice($args, (int) array_search($flag, $args, true) + 1))->toBe([...TOOL_RULES, '--settings', '{"disableRemoteControl":true}']);
})->with(['allowedTools', 'disallowedTools']);

it('keeps both lists separate, in order, without delimiters', function () {
    $denied = ['Bash(git log --format="%h, %s")', 'Write'];
    $args = ClaudeDriver::args(new ClaudeDriverOptions(cwd: '/w', allowedTools: TOOL_RULES, disallowedTools: $denied));

    expect(array_slice($args, (int) array_search('--allowed-tools', $args, true)))
        ->toBe(['--allowed-tools', ...TOOL_RULES, '--disallowed-tools', ...$denied, '--settings', '{"disableRemoteControl":true}']);
});

it('emits no flag for an empty list', function () {
    expect(ClaudeDriver::args(new ClaudeDriverOptions(cwd: '/w')))->not->toContain('--allowed-tools')
        ->and(ClaudeDriver::args(new ClaudeDriverOptions(cwd: '/w')))->not->toContain('--disallowed-tools');
});

/** @return array<string, array{0: array<mixed>}> */
function malformedToolRules(): array
{
    return [
        'null entry' => [[null]], 'number entry' => [[3]], 'empty' => [['']], 'space' => [[' ']],
        'option' => [['--permission-mode']], 'spaced option' => [[' -p']], 'NUL' => [["Read\0"]],
        'newline' => [["Bash(echo\nhello)"]], 'lone surrogate as UTF-8' => [["Read(\xED\xA0\x80)"]],
        'invalid UTF-8' => [["Read(\xFF)"]], 'sparse list' => [[1 => 'Read']], 'keyed list' => [['a' => 'Read']],
        // The reference's JavaScript trim()/\s set, beyond ASCII.
        'NBSP option' => [["\u{00A0}-p"]], 'BOM only' => [["\u{FEFF}"]], 'ideographic space option' => [["\u{3000}--x"]],
        'DEL' => [["Read\x7F"]], 'C1 NEL' => [["Read\u{0085}"]], 'line separator' => [["Read\u{2028}"]],
        'paragraph separator' => [["Read\u{2029}"]], 'tab' => [["Bash(a\tb)"]], 'later bad entry' => [['Read', '-x']],
    ];
}

it('refuses a malformed rule before launch, naming the field and index but never the rule', function (array $value) {
    foreach (['allowedTools', 'disallowedTools'] as $field) {
        $options = new ClaudeDriverOptions(...['cwd' => '/w', $field => $value]);
        foreach ([fn () => ClaudeDriver::args($options), fn () => new ClaudeDriver($options)] as $call) {
            try {
                $call();
                $this->fail('accepted a malformed '.$field.' rule');
            } catch (ClaudeToolRuleError $error) {
                $expectedIndex = array_is_list($value) ? array_key_last(array_filter($value, fn (mixed $rule) => ! in_array($rule, ['Read'], true))) : null;
                expect($error->field)->toBe($field)
                    ->and($error->index)->toBe($expectedIndex)
                    ->and(ClaudeToolRuleError::CODE)->toBe('invalid_claude_tool_rule')
                    ->and($error)->toBeInstanceOf(InvalidArgumentException::class)
                    ->and($error->getMessage())->not->toContain('permission-mode')
                    ->and($error->getMessage())->toBe($field.($expectedIndex === null ? '' : "[{$expectedIndex}]")
                        .' must contain non-empty permission rules without option prefixes, control characters or malformed Unicode');
            }
        }
    }
})->with(malformedToolRules());

it('refuses a non-list value with a null index, as the reference refuses a non-array', function () {
    expect(fn () => ClaudeToolRuleError::rules(null, 'allowedTools'))->toThrow(ClaudeToolRuleError::class, 'allowedTools must contain')
        ->and(fn () => ClaudeToolRuleError::rules('Bash', 'disallowedTools'))->toThrow(ClaudeToolRuleError::class, 'disallowedTools must contain')
        ->and(fn () => ClaudeToolRuleError::rules(new stdClass, 'allowedTools'))->toThrow(ClaudeToolRuleError::class, 'allowedTools must contain');
});

it('accepts rules a host really writes, including Unicode, quotes and an inner hyphen', function () {
    $rules = ['Bash(echo "quoted, value")', 'Read(資料, one/**)', 'Bash(git log -n 1)', 'mcp__server__tool'];

    expect(ClaudeToolRuleError::rules($rules, 'allowedTools'))->toBe($rules);
});
