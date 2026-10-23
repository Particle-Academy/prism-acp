<?php

declare(strict_types=1);

namespace Prism\Acp\Support;

/**
 * JavaScript's `undefined`, where the reference tells it apart from `null`: a property that is absent, or a parse that
 * produced nothing. No JSON value can be equal to it, which a sentinel string could not promise.
 */
enum Undefined
{
    case Value;

    /** A property of a JSON object, or Undefined::Value when it is absent (or the value is not an object). */
    public static function get(mixed $object, string $key): mixed
    {
        return $object instanceof \stdClass && property_exists($object, $key) ? $object->{$key} : self::Value;
    }
}
