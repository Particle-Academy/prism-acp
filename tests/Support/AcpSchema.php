<?php

declare(strict_types=1);

namespace Tests\Support;

use Prism\Acp\Ndjson\Json;

/**
 * The ACP v1 schema, as published, and a check of a message against it.
 *
 * `tests/fixtures/acp-v1-schema.json` is `schema/v1/schema.json` from agentclientprotocol/agent-client-protocol at
 * commit d3ac0e6d8b1132c9a749a4210f04bb788c9a75a4 (2026-11-24), byte for byte; prism-acp-ts vendors the same file.
 * Refresh both together.
 *
 * prism-acp-ts checks with Fancy's `fancy-schema`. There is no Fancy validator for PHP, so this is the subset of
 * JSON Schema 2020-12 that schema actually uses, and it FAILS CLOSED: an unresolvable `$ref`, or an assertion keyword
 * it does not implement, throws rather than passing. A validator that skipped what it did not understand would report
 * every message valid. AcpSchemaTest proves both refusals, and that the plan bugs of G-362 are caught.
 *
 * The value is checked as the wire carries it (encoded, then decoded), so a PHP empty array that would go out as `[]`
 * where the schema wants an object is caught.
 */
final class AcpSchema
{
    /** Keywords that only describe; they assert nothing. */
    private const ANNOTATIONS = ['description', 'title', 'default', 'examples', 'format', 'discriminator', '$schema', '$comment', 'deprecated'];

    private static ?\stdClass $defs = null;

    /** @return list<string> problems with one `session/update` params object; empty means valid */
    public static function sessionNotification(mixed $value): array
    {
        return self::problems('SessionNotification', $value);
    }

    /** @return list<string> problems with one bare `SessionUpdate` */
    public static function sessionUpdate(mixed $value): array
    {
        return self::sessionNotification(['sessionId' => 'schema-check', 'update' => $value]);
    }

    /** @return list<string> */
    public static function problems(string $definition, mixed $value): array
    {
        $defs = self::defs();
        if (! property_exists($defs, $definition)) {
            throw new \LogicException("the ACP schema has no {$definition}");
        }
        $errors = [];
        self::check($defs->{$definition}, Json::decode(Json::encode($value)), '', $errors);

        return $errors;
    }

    /** @param  \stdClass|array<mixed>|bool  $schema */
    public static function validate(mixed $schema, mixed $value): array
    {
        $errors = [];
        self::check(Json::decode(Json::encode($schema)), Json::decode(Json::encode($value)), '', $errors);

        return $errors;
    }

    private static function defs(): \stdClass
    {
        if (self::$defs === null) {
            $schema = Json::decode((string) file_get_contents(dirname(__DIR__).'/fixtures/acp-v1-schema.json'));
            if (! $schema instanceof \stdClass || ! ($schema->{'$defs'} ?? null) instanceof \stdClass) {
                throw new \LogicException('the vendored ACP schema has no $defs');
            }
            self::$defs = $schema->{'$defs'};
        }

        return self::$defs;
    }

    /** @param list<string> $errors */
    private static function check(mixed $schema, mixed $value, string $path, array &$errors): void
    {
        if ($schema === true) {
            return;
        }
        if ($schema === false) {
            $errors[] = ($path ?: '/').' is not allowed';

            return;
        }
        if (! $schema instanceof \stdClass) {
            throw new \LogicException("schema at {$path} is not an object or boolean");
        }
        foreach (get_object_vars($schema) as $keyword => $argument) {
            $keyword = (string) $keyword;
            if (in_array($keyword, self::ANNOTATIONS, true) || str_starts_with($keyword, 'x-')) {
                continue;
            }
            match ($keyword) {
                '$ref' => self::check(self::resolve($argument), $value, $path, $errors),
                'type' => self::type($argument, $value, $path, $errors),
                'properties' => self::properties($argument, $value, $path, $errors),
                'required' => self::required($argument, $value, $path, $errors),
                'additionalProperties' => self::additional($schema, $argument, $value, $path, $errors),
                'items' => self::items($argument, $value, $path, $errors),
                'allOf' => array_map(fn (mixed $sub) => self::check($sub, $value, $path, $errors), $argument),
                'anyOf', 'oneOf' => self::alternatives($keyword, $argument, $value, $path, $errors),
                'not' => self::not($argument, $value, $path, $errors),
                'const' => self::same($argument, $value) ? null : ($errors[] = ($path ?: '/').' must be '.Json::encode($argument)),
                'enum' => self::enum($argument, $value, $path, $errors),
                'minimum' => (is_int($value) || is_float($value)) && $value < $argument ? ($errors[] = ($path ?: '/')." must be >= {$argument}") : null,
                'maximum' => (is_int($value) || is_float($value)) && $value > $argument ? ($errors[] = ($path ?: '/')." must be <= {$argument}") : null,
                'minLength' => is_string($value) && mb_strlen($value, 'UTF-8') < $argument ? ($errors[] = ($path ?: '/')." is shorter than {$argument}") : null,
                // Fail closed: a keyword that asserts something and is not implemented here must not pass silently.
                default => throw new \LogicException("unsupported schema keyword {$keyword} at {$path}"),
            };
        }
    }

    private static function resolve(mixed $reference): mixed
    {
        if (! is_string($reference) || ! str_starts_with($reference, '#/$defs/')) {
            throw new \LogicException('unresolvable $ref '.Json::encode($reference));
        }
        $name = substr($reference, strlen('#/$defs/'));
        $defs = self::$defs ?? new \stdClass;
        if (! property_exists($defs, $name)) {
            throw new \LogicException("unresolvable \$ref {$reference}");
        }

        return $defs->{$name};
    }

    /** @param list<string> $errors */
    private static function type(mixed $types, mixed $value, string $path, array &$errors): void
    {
        foreach ((array) $types as $type) {
            if (match ($type) {
                'object' => $value instanceof \stdClass,
                'array' => is_array($value),
                'string' => is_string($value),
                'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => throw new \LogicException("unknown type {$type}"),
            }) {
                return;
            }
        }
        $errors[] = ($path ?: '/').' must be '.implode(' or ', (array) $types);
    }

    /** @param list<string> $errors */
    private static function properties(mixed $properties, mixed $value, string $path, array &$errors): void
    {
        if (! $value instanceof \stdClass) {
            return;
        }
        foreach (get_object_vars($properties) as $name => $sub) {
            if (property_exists($value, (string) $name)) {
                self::check($sub, $value->{$name}, $path.'/'.$name, $errors);
            }
        }
    }

    /** @param list<string> $errors */
    private static function required(mixed $names, mixed $value, string $path, array &$errors): void
    {
        if (! $value instanceof \stdClass) {
            return;
        }
        foreach ($names as $name) {
            if (! property_exists($value, $name)) {
                $errors[] = ($path ?: '/')." is missing required property {$name}";
            }
        }
    }

    /** @param list<string> $errors */
    private static function additional(\stdClass $schema, mixed $argument, mixed $value, string $path, array &$errors): void
    {
        if (! $value instanceof \stdClass || isset($schema->patternProperties)) {
            if (isset($schema->patternProperties)) {
                throw new \LogicException("unsupported schema keyword patternProperties at {$path}");
            }

            return;
        }
        $known = isset($schema->properties) ? array_keys(get_object_vars($schema->properties)) : [];
        foreach (get_object_vars($value) as $name => $entry) {
            if (! in_array((string) $name, array_map('strval', $known), true)) {
                self::check($argument, $entry, $path.'/'.$name, $errors);
            }
        }
    }

    /** @param list<string> $errors */
    private static function items(mixed $schema, mixed $value, string $path, array &$errors): void
    {
        if (! is_array($value)) {
            return;
        }
        // Strict: the schema's x-deserialize-skip-invalid-items is how a CLIENT silently drops a bad plan entry. A
        // validator that did the same would pass the very bug it is here for.
        foreach ($value as $index => $entry) {
            self::check($schema, $entry, $path.'/'.$index, $errors);
        }
    }

    /**
     * @param  list<mixed>  $alternatives
     * @param  list<string>  $errors
     */
    private static function alternatives(string $keyword, array $alternatives, mixed $value, string $path, array &$errors): void
    {
        $matched = 0;
        foreach ($alternatives as $alternative) {
            $inner = [];
            self::check($alternative, $value, $path, $inner);
            $matched += $inner === [] ? 1 : 0;
        }
        if ($keyword === 'oneOf' ? $matched !== 1 : $matched === 0) {
            $errors[] = ($path ?: '/')." matched {$matched} of the ".count($alternatives).' alternatives';
        }
    }

    /** @param list<string> $errors */
    private static function not(mixed $schema, mixed $value, string $path, array &$errors): void
    {
        $inner = [];
        self::check($schema, $value, $path, $inner);
        if ($inner === []) {
            $errors[] = ($path ?: '/').' matched a schema it must not';
        }
    }

    /**
     * @param  list<mixed>  $options
     * @param  list<string>  $errors
     */
    private static function enum(array $options, mixed $value, string $path, array &$errors): void
    {
        foreach ($options as $option) {
            if (self::same($option, $value)) {
                return;
            }
        }
        $errors[] = ($path ?: '/').' is not one of the allowed values';
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return Json::encode(self::canonical($a)) === Json::encode(self::canonical($b));
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::canonical(...), $value);
        }
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);

            return (object) array_map(self::canonical(...), $properties);
        }

        return $value;
    }
}
