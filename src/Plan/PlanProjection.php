<?php

declare(strict_types=1);

namespace Prism\Acp\Plan;

use Prism\Acp\Meta;
use Prism\Acp\Support\Js;
use Prism\Acp\Text;

/**
 * An agent plan, as ACP shows it (G-360 §6). prism-acp-ts's `src/plan.ts` is the same mapping, row for row.
 *
 * `plan` is a full replacement of the session's one checklist and every client accepts it. `plan_update` (keyed by
 * `planId`) and `plan_removed` exist only for a client that advertised `clientCapabilities.plan`, and MUST NOT be sent
 * to any other. AcpAgent also enforces that on every update a driver sends.
 *
 * ACP's `completed` means "successfully completed", and it has no failed or skipped status. A failed or skipped step
 * is therefore shown UNCHECKED, labelled `[failed]` or `[skipped]`, with the real state in `_meta`. Every entry's
 * `priority` is `medium`: a step has none, the field is required, and any other value would be invented.
 *
 * Titles are model text: cleaned (escapes, controls, invisible and bidi characters become spaces), bounded, and unable
 * to begin with a label this class writes. A malformed snapshot throws PlanProjectionException rather than being
 * guessed at.
 *
 * A snapshot is `{planId, revision, planStatus, holdingApprovalId?, steps: list<{id, title, state, owner?, approvalId?}>}`.
 */
final class PlanProjection
{
    /** The longest title shown, in code points, including the `…` that marks a cut. */
    public const TITLE_MAX = 160;

    /** The most steps one plan may carry: prism-harness's operator ceiling. */
    public const STEPS_MAX = 50;

    public const PRIORITY = 'medium';

    public const UNTITLED = 'Untitled step';

    /**
     * Each harness state's ACP status. `proposed` is a step a pending change would add (G-360): it is not in the plan
     * yet, so it is shown pending, never in progress or completed, with the real state in `_meta`.
     */
    private const STATUS = [
        'todo' => 'pending', 'waiting_input' => 'pending', 'waiting_condition' => 'pending', 'proposed' => 'pending',
        'running' => 'in_progress', 'held' => 'in_progress', 'done' => 'completed',
        'failed' => 'pending', 'skipped' => 'pending',
    ];

    private const PLAN_STATUSES = ['proposed', 'active', 'waiting', 'blocked', 'done', 'cancelled', 'rejected'];

    private const OWNERS = ['agent', 'human'];

    private const ID = '/\A[A-Za-z0-9_.:-]{1,128}\z/';

    /**
     * Replaced by a space in a title; the same set as prism-acp-ts's plan.ts HIDDEN (lone surrogates cannot occur in
     * scrubbed UTF-8, so that range is not listed).
     */
    private const HIDDEN = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\p{Cf}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}\x{034F}\x{180B}-\x{180F}\x{2800}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{2028}\x{2029}]/u';

    private const LABEL = '/\A(?:\[failed\]|\[skipped\]|\(you\))/i';

    /**
     * Whether `clientCapabilities` advertises plan updates: omitted or null means no; an object means yes. Anything
     * else is not the schema's shape and reads as no.
     */
    public static function clientAcceptsPlanUpdates(mixed $clientCapabilities): bool
    {
        // As the peer decodes it: a JSON object is a stdClass, so `{}` and `[]` stay distinct.
        return Js::get($clientCapabilities, 'plan') instanceof \stdClass;
    }

    /**
     * The `session/update` that shows this plan: `plan_update` for a client that advertised plan, otherwise `plan`.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array{plan: bool}  $client
     * @return array<string, mixed>
     */
    public static function update(array $snapshot, array $client): array
    {
        $planId = self::id($snapshot['planId'] ?? null, 'planId');
        $revision = $snapshot['revision'] ?? null;
        if (! is_int($revision) || $revision < 0 || $revision > 9007199254740991) {
            throw new PlanProjectionException('revision must be a non-negative integer');
        }
        $status = $snapshot['planStatus'] ?? null;
        if (! is_string($status) || ! in_array($status, self::PLAN_STATUSES, true)) {
            throw new PlanProjectionException('unknown plan status');
        }
        $holding = self::optionalId($snapshot['holdingApprovalId'] ?? null, 'holdingApprovalId');
        $steps = $snapshot['steps'] ?? null;
        if (! is_array($steps) || ! array_is_list($steps)) {
            throw new PlanProjectionException('steps must be a list');
        }
        if (count($steps) > self::STEPS_MAX) {
            throw new PlanProjectionException('a plan carries at most '.self::STEPS_MAX.' steps');
        }

        $seen = [];
        $entries = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                throw new PlanProjectionException('a step must be an object');
            }
            $id = self::id($step['id'] ?? null, 'step id');
            if (isset($seen[$id])) {
                throw new PlanProjectionException('duplicate step id');
            }
            $seen[$id] = true;
            $entries[] = self::entry($step);
        }
        $meta = [Meta::PLAN => ['planId' => $planId, 'revision' => $revision, 'planStatus' => $status, 'holdingApprovalId' => $holding]];

        return $client['plan'] === true
            ? ['sessionUpdate' => 'plan_update', 'plan' => ['type' => 'items', 'planId' => $planId, 'entries' => $entries, '_meta' => $meta]]
            : ['sessionUpdate' => 'plan', 'entries' => $entries, '_meta' => $meta];
    }

    /**
     * `plan_removed` for a client that advertised plan; null (send nothing) for one that did not.
     *
     * @param  array{plan: bool}  $client
     * @return array<string, mixed>|null
     */
    public static function removed(string $planId, array $client): ?array
    {
        $id = self::id($planId, 'planId');

        return $client['plan'] === true ? ['sessionUpdate' => 'plan_removed', 'planId' => $id] : null;
    }

    /**
     * One ACP `PlanEntry`. The step is the caller's data and is checked here, so any array is accepted.
     *
     * @param  array<mixed>  $step
     * @return array{content: string, priority: string, status: string, _meta: array<string, array<string, mixed>>}
     */
    public static function entry(array $step): array
    {
        $id = self::id($step['id'] ?? null, 'step id');
        $state = $step['state'] ?? null;
        if (! is_string($state) || ! array_key_exists($state, self::STATUS)) {
            throw new PlanProjectionException('unknown step state');
        }
        $owner = $step['owner'] ?? 'agent';
        if (! is_string($owner) || ! in_array($owner, self::OWNERS, true)) {
            throw new PlanProjectionException('unknown step owner');
        }
        $title = $step['title'] ?? null;
        if (! is_string($title)) {
            throw new PlanProjectionException('a step title must be a string');
        }
        $approvalId = self::optionalId($step['approvalId'] ?? null, 'approvalId');

        $label = ($state === 'failed' ? '[failed] ' : ($state === 'skipped' ? '[skipped] ' : '')).($owner === 'human' ? '(you) ' : '');

        return [
            'content' => $label.self::title($title),
            'priority' => self::PRIORITY,
            'status' => self::STATUS[$state],
            '_meta' => [Meta::PLAN_STEP => ['id' => $id, 'state' => $state, 'owner' => $owner, 'approvalId' => $approvalId]],
        ];
    }

    /** A model-written title, made safe to show: one line, nothing hidden, no host label, at most TITLE_MAX. */
    public static function title(string $value): string
    {
        $text = Text::stripAnsiControlSequences(Js::scrub($value))['text'];
        $text = (string) preg_replace(self::HIDDEN, ' ', $text);
        $text = (string) preg_replace('/ {2,}/', ' ', $text);
        $text = trim($text, ' ');
        while (preg_match(self::LABEL, $text, $match) === 1) {
            $text = ltrim(substr($text, strlen($match[0])), ' ');
        }
        if (mb_strlen($text, 'UTF-8') > self::TITLE_MAX) {
            $text = rtrim(mb_substr($text, 0, self::TITLE_MAX - 1, 'UTF-8'), ' ').'…';
        }

        return $text === '' ? self::UNTITLED : $text;
    }

    private static function id(mixed $value, string $name): string
    {
        if (! is_string($value) || preg_match(self::ID, $value) !== 1) {
            throw new PlanProjectionException("{$name} is not a valid id");
        }

        return $value;
    }

    private static function optionalId(mixed $value, string $name): ?string
    {
        return $value === null ? null : self::id($value, $name);
    }
}
