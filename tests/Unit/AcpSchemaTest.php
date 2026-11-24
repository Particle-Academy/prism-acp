<?php

declare(strict_types=1);

use Tests\Support\AcpSchema;

/*
 * The schema check is only worth something if it cannot pass what it does not understand. These pin that it fails
 * closed, and that it catches the two G-362 shapes a lenient client would silently drop.
 */

it('accepts a plan with every required field', function (): void {
    expect(AcpSchema::sessionUpdate(['sessionUpdate' => 'plan', 'entries' => [['content' => 'a', 'priority' => 'medium', 'status' => 'pending']]]))->toBe([]);
});

it('catches a plan entry with no priority (G-362 bug A)', function (): void {
    expect(AcpSchema::sessionUpdate(['sessionUpdate' => 'plan', 'entries' => [['content' => 'a', 'status' => 'pending']]]))->not->toBe([]);
});

it('catches a plan_update carrying a delta (G-362 bug B)', function (): void {
    expect(AcpSchema::sessionUpdate(['sessionUpdate' => 'plan_update', 'delta' => 'progress']))->not->toBe([]);
});

it('catches an empty PHP array where the wire needs an object', function (): void {
    expect(AcpSchema::sessionUpdate(['sessionUpdate' => 'plan', 'entries' => [], '_meta' => []]))->not->toBe([]);
});

it('throws on an unresolvable $ref rather than passing', function (): void {
    expect(fn () => AcpSchema::validate(['$ref' => '#/$defs/NoSuchThing'], 1))->toThrow(LogicException::class);
});

it('throws on an assertion keyword it does not implement rather than passing', function (): void {
    expect(fn () => AcpSchema::validate(['type' => 'object', 'unevaluatedProperties' => false], new stdClass))->toThrow(LogicException::class)
        ->and(fn () => AcpSchema::validate(['patternProperties' => ['^a' => true]], new stdClass))->toThrow(LogicException::class);
});

it('reports oneOf matching more than one alternative', function (): void {
    expect(AcpSchema::validate(['oneOf' => [['type' => 'string'], ['type' => 'string']]], 'x'))->not->toBe([]);
});
