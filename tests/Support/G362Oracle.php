<?php

declare(strict_types=1);

namespace Tests\Support;

use Prism\Acp\Meta;

/**
 * The G-362 corrections to the published reference oracle.
 *
 * The golden fixtures are what prism-acp-ts 0.25.1 does, regenerated in CI from the published package, and they stay
 * that way: advancing the baseline is a separate decision (docs/reference-review-*.md). 0.25.1 has the G-362 plan
 * bugs, so PHP no longer matches it in exactly these places, and each one is a correction applied HERE, in the open,
 * rather than an edit to a fixture CI regenerates:
 *
 * - A Codex plan entry carries `priority: medium` (ACP requires it).
 * - Codex `turn/plan/delta` is not forwarded as `plan_update`; it is reported as an unmapped frame.
 * - A driver is told the client's plan capability: `clientCapabilities: {plan: false}` in its options, because no
 *   golden scenario's client advertises it.
 *
 * G362OracleTest proves each correction still changes the oracle. When the baseline advances past the fix, they stop
 * changing anything, that test fails, and this file is deleted with it.
 */
final class G362Oracle
{
    public const DELTA_REASON = 'turn/plan/delta is not a plan snapshot';

    /** The Codex oracle's expected value, corrected. Counts each correction in $applied. */
    public static function codex(mixed $expected, int &$applied = 0): mixed
    {
        if (! $expected instanceof \stdClass || ! isset($expected->events) || ! is_array($expected->events)) {
            return $expected;
        }
        $copy = clone $expected;
        $copy->events = [];
        foreach ($expected->events as $event) {
            $copy->events[] = self::codexEvent($event, $applied);
        }

        return $copy;
    }

    private static function codexEvent(mixed $event, int &$applied): mixed
    {
        if (! $event instanceof \stdClass || ($event->type ?? null) !== 'update' || ! $event->value instanceof \stdClass) {
            return $event;
        }
        $value = $event->value;
        if (($value->sessionUpdate ?? null) === 'plan' && is_array($value->entries ?? null)) {
            $entries = [];
            foreach ($value->entries as $entry) {
                if ($entry instanceof \stdClass && ! property_exists($entry, 'priority')) {
                    $entry = (object) ['content' => $entry->content, 'priority' => 'medium', 'status' => $entry->status];
                    $applied++;
                }
                $entries[] = $entry;
            }

            return (object) ['type' => 'update', 'value' => (object) [...get_object_vars($value), 'entries' => $entries]];
        }
        if (($value->sessionUpdate ?? null) === 'plan_update' && property_exists($value, 'delta')) {
            $applied++;

            // The frame the reference forwarded is the turn/plan/delta params; it is now reported, not sent.
            return (object) ['type' => 'update', 'value' => (object) [
                'sessionUpdate' => 'notice',
                'notice' => (object) ['level' => 'debug', 'message' => self::DELTA_REASON],
                '_meta' => (object) [Meta::UNMAPPED_FRAME => (object) ['reason' => self::DELTA_REASON, 'frame' => self::deltaFrame($value)]],
            ]];
        }

        return $event;
    }

    /** The reference sent `delta: params.delta ?? params`; the fixture's params are `{turnId, delta}`. */
    private static function deltaFrame(\stdClass $value): \stdClass
    {
        return $value->delta instanceof \stdClass ? $value->delta : (object) ['turnId' => 'turn-fixture', 'delta' => $value->delta];
    }

    /**
     * One agent-oracle log entry, corrected: a driver `start` call's options gain `clientCapabilities` after
     * `mcpServers`, where prism-acp-ts puts it.
     */
    public static function agentEntry(mixed $entry, int &$applied = 0): mixed
    {
        if (! $entry instanceof \stdClass || ! isset($entry->call) || ! is_array($entry->call) || ($entry->call[1] ?? null) !== 'start'
            || ! ($entry->call[2] ?? null) instanceof \stdClass || property_exists($entry->call[2], 'clientCapabilities')) {
            return $entry;
        }
        $options = new \stdClass;
        foreach (get_object_vars($entry->call[2]) as $key => $value) {
            $options->{$key} = $value;
            if ($key === 'mcpServers') {
                $options->clientCapabilities = (object) ['plan' => false];
            }
        }
        $applied++;
        $call = $entry->call;
        $call[2] = $options;

        return (object) [...get_object_vars($entry), 'call' => $call];
    }
}
