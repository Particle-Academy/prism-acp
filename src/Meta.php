<?php

declare(strict_types=1);

namespace Prism\Acp;

/**
 * This package's `_meta` keys, namespaced so they cannot collide with ACP's own or a client's. Every string is the
 * reference's (prism-acp-ts src/meta.ts), character for character: a client reading `_meta` must not care which
 * language produced the update.
 */
final class Meta
{
    public const NS = 'particle.academy';

    /** Reserved by ACP for W3C trace context. */
    public const RESERVED = ['traceparent', 'tracestate', 'baggage'];

    public const THINKING_SIGNATURE = self::NS.'/thinking_signature';

    public const THINKING_TOKENS_ESTIMATE = self::NS.'/thinking_tokens_estimate';

    public const RATE_LIMIT = self::NS.'/rate_limit';

    public const PERMISSION_DENIED = self::NS.'/permission_denied';

    public const DRIVER_CAPABILITIES = self::NS.'/driver_capabilities';

    public const EXEC_POLICY_AMENDMENT = self::NS.'/execpolicy_amendment';

    public const BACKGROUND_TASKS = self::NS.'/background_tasks';

    public const TASK = self::NS.'/task';

    public const UNMAPPED_FRAME = self::NS.'/unmapped_frame';

    public const CLI_SESSION_ID = self::NS.'/cli_session_id';

    public const MODEL = self::NS.'/model';

    public const COMMAND_SOURCE = self::NS.'/command_source';

    public const PLUGINS = self::NS.'/plugins';

    public const SUBAGENTS = self::NS.'/subagents';

    public const USAGE = self::NS.'/usage';

    public const PARENT_TOOL_CALL = self::NS.'/parent_tool_call_id';

    public const TURN_FAILURE = self::NS.'/turn_failure';

    public const COMPACTION = self::NS.'/compaction';

    public const LOCAL_COMMAND = self::NS.'/local_command';

    public const PERMISSION_REASON = self::NS.'/permission_reason';

    public const MODEL_INFO = self::NS.'/model_info';

    /** A namespaced key; a bare name only, never one of ACP's reserved trace keys. */
    public static function key(string $name): string
    {
        if ($name === '') {
            throw new \InvalidArgumentException('meta key name must not be empty');
        }
        if (str_contains($name, '/')) {
            throw new \InvalidArgumentException("meta key name must not contain '/': {$name}");
        }
        if (in_array($name, self::RESERVED, true)) {
            throw new \InvalidArgumentException("'{$name}' is reserved by ACP for W3C trace context");
        }

        return self::NS.'/'.$name;
    }

    /**
     * Attach `_meta` entries to an ACP object without disturbing its own fields. Existing `_meta` is MERGED: two mapping
     * sites can both have something to say about one update, and the second must not erase the first.
     *
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $entries
     * @return array<string, mixed>
     */
    public static function with(array $value, array $entries): array
    {
        $existing = isset($value['_meta']) && is_array($value['_meta']) ? $value['_meta'] : [];
        $value['_meta'] = [...$existing, ...$entries];

        return $value;
    }

    /**
     * A model a client asked for, refused before it reaches a provider's command line or request: empty, or shaped like
     * a flag ("--dangerously-skip-permissions" passed as a "model" would be read by the CLI as the flag).
     */
    public static function assertModel(string $model): string
    {
        // JavaScript's trim: a model of only no-break spaces is as empty there as one of ASCII spaces.
        if (Support\Js::trim($model) === '' || str_starts_with($model, '-')) {
            throw new \InvalidArgumentException(sprintf(
                'refusing model %s: a model is a non-empty name that does not start with "-"',
                Ndjson\Json::encode($model),
            ));
        }

        return $model;
    }
}
