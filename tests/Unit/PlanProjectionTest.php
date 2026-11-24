<?php

declare(strict_types=1);

use Prism\Acp\Meta;
use Prism\Acp\Plan\PlanProjection;
use Prism\Acp\Plan\PlanProjectionException;
use Tests\Support\AcpSchema;

/*
 * The shared plan mapping (G-360 §6), row for row with prism-acp-ts's test/plan.test.ts. Every message is checked
 * against the published ACP v1 schema, strictly.
 */

const PLAN_WITH = ['plan' => true];
const PLAN_WITHOUT = ['plan' => false];
const PLAN_STATES = ['todo', 'running', 'held', 'waiting_input', 'waiting_condition', 'done', 'failed', 'skipped'];

/** @param list<array<string, mixed>> $steps */
function planSnapshot(array $steps, array $extra = []): array
{
    return [...['planId' => 'pl_01JPLAN', 'revision' => 3, 'planStatus' => 'active', 'holdingApprovalId' => null, 'steps' => $steps], ...$extra];
}

function planStep(string $state, mixed $title = null, array $extra = []): array
{
    return [...['id' => "ps_{$state}", 'title' => $title ?? "step {$state}", 'state' => $state, 'owner' => 'agent', 'approvalId' => null], ...$extra];
}

/** @return list<array<string, mixed>> */
function planEntries(array $update): array
{
    return $update['sessionUpdate'] === 'plan' ? $update['entries'] : $update['plan']['entries'];
}

function planTitleOf(string $text, string $state = 'todo'): string
{
    return planEntries(PlanProjection::update(planSnapshot([planStep($state, $text)]), PLAN_WITH))[0]['content'];
}

it('reads the client capability exactly', function (mixed $capabilities, bool $accepts): void {
    expect(PlanProjection::clientAcceptsPlanUpdates($capabilities))->toBe($accepts);
})->with([
    [(object) ['plan' => new stdClass], true],
    [(object) ['plan' => (object) ['_meta' => new stdClass]], true],
    [(object) ['plan' => null], false],
    [new stdClass, false],
    [null, false],
    [(object) ['plan' => true], false],
    [(object) ['plan' => []], false],
    [(object) ['plan' => 'yes'], false],
]);

it('projects every state to a schema-valid message', function (array $client): void {
    expect(AcpSchema::sessionUpdate(PlanProjection::update(planSnapshot(array_map(fn ($s) => planStep($s), PLAN_STATES)), $client)))->toBe([]);
})->with([[PLAN_WITH], [PLAN_WITHOUT]]);

it('sends plan_update keyed by planId only when the client advertised plan', function (): void {
    $update = PlanProjection::update(planSnapshot([planStep('todo')]), PLAN_WITH);
    expect($update['sessionUpdate'])->toBe('plan_update')
        ->and($update['plan']['type'])->toBe('items')
        ->and($update['plan']['planId'])->toBe('pl_01JPLAN')
        ->and($update)->not->toHaveKey('entries');
});

it('sends the full-replacement plan when the client did not', function (): void {
    $update = PlanProjection::update(planSnapshot([planStep('todo')]), PLAN_WITHOUT);
    expect($update['sessionUpdate'])->toBe('plan')->and($update)->not->toHaveKey('plan')->and($update)->not->toHaveKey('planId');
});

it('gives every entry the required priority, always medium', function (array $client): void {
    foreach (planEntries(PlanProjection::update(planSnapshot(array_map(fn ($s) => planStep($s), PLAN_STATES)), $client)) as $entry) {
        expect($entry['priority'])->toBe('medium');
    }
})->with([[PLAN_WITH], [PLAN_WITHOUT]]);

it('maps each state', function (string $state, string $status): void {
    expect(planEntries(PlanProjection::update(planSnapshot([planStep($state)]), PLAN_WITHOUT))[0]['status'])->toBe($status);
})->with([
    ['todo', 'pending'], ['waiting_input', 'pending'], ['waiting_condition', 'pending'], ['running', 'in_progress'],
    ['held', 'in_progress'], ['done', 'completed'], ['failed', 'pending'], ['skipped', 'pending'],
]);

it('shows a failed or skipped step unchecked and labelled, never completed', function (string $state): void {
    $entry = planEntries(PlanProjection::update(planSnapshot([planStep($state, 'Deploy')]), PLAN_WITH))[0];
    expect($entry['status'])->not->toBe('completed')
        ->and($entry['content'])->toBe("[{$state}] Deploy")
        ->and($entry['_meta'][Meta::PLAN_STEP]['state'])->toBe($state);
})->with(['failed', 'skipped']);

it('prefixes a person step, after the outcome label', function (): void {
    [$a, $b] = planEntries(PlanProjection::update(planSnapshot([
        planStep('todo', 'Approve the spend', ['owner' => 'human']),
        planStep('failed', 'Sign off', ['id' => 'ps_2', 'owner' => 'human']),
    ]), PLAN_WITHOUT));
    expect($a['content'])->toBe('(you) Approve the spend')->and($b['content'])->toBe('[failed] (you) Sign off');
});

it('carries the real state in namespaced _meta, never at the root', function (): void {
    $update = PlanProjection::update(planSnapshot([planStep('held', 'Write', ['approvalId' => 'ap_1'])], ['holdingApprovalId' => 'ap_1']), PLAN_WITH);
    $plan = $update['plan'];
    $keys = array_keys($plan);
    sort($keys);
    expect($keys)->toBe(['_meta', 'entries', 'planId', 'type'])
        ->and($plan['_meta'])->toBe([Meta::PLAN => ['planId' => 'pl_01JPLAN', 'revision' => 3, 'planStatus' => 'active', 'holdingApprovalId' => 'ap_1']]);
    $entry = $plan['entries'][0];
    $keys = array_keys($entry);
    sort($keys);
    expect($keys)->toBe(['_meta', 'content', 'priority', 'status'])
        ->and($entry['_meta'])->toBe([Meta::PLAN_STEP => ['id' => 'ps_held', 'state' => 'held', 'owner' => 'agent', 'approvalId' => 'ap_1']]);
});

it('carries the plan _meta on the legacy plan too', function (): void {
    expect(PlanProjection::update(planSnapshot([planStep('todo')]), PLAN_WITHOUT)['_meta'])
        ->toBe([Meta::PLAN => ['planId' => 'pl_01JPLAN', 'revision' => 3, 'planStatus' => 'active', 'holdingApprovalId' => null]]);
});

it('sends an empty plan as an empty list, which is still valid', function (array $client): void {
    $update = PlanProjection::update(planSnapshot([]), $client);
    expect(planEntries($update))->toBe([])->and(AcpSchema::sessionUpdate($update))->toBe([]);
})->with([[PLAN_WITH], [PLAN_WITHOUT]]);

it('does not let a title forge a host label', function (): void {
    expect(planTitleOf('[failed] Deploy', 'done'))->toBe('Deploy')
        ->and(planTitleOf('[SKIPPED]  (you) [failed]Deploy'))->toBe('Deploy')
        ->and(planTitleOf('(You) approve this'))->toBe('approve this')
        ->and(planTitleOf('[failed] Deploy', 'failed'))->toBe('[failed] Deploy');
});

it('does not let a title carry escapes, controls or bidi overrides', function (): void {
    expect(planTitleOf("a\e[31mred\e[0m b"))->toBe('ared b')
        ->and(planTitleOf("line\nbreak\ttab"))->toBe('line break tab')
        ->and(planTitleOf("safe\u{202E}gnp.exe"))->toBe('safe gnp.exe')
        ->and(planTitleOf("zero\u{200B}width\u{2066}iso\u{2069}"))->toBe('zero width iso')
        ->and(planTitleOf("\u{202E}[failed] hidden", 'done'))->toBe('hidden')
        ->and(planTitleOf("bad\xFFbyte"))->toBe("bad\u{FFFD}byte");
});

it('bounds a title by code point', function (): void {
    $long = planTitleOf(str_repeat('😀', PlanProjection::TITLE_MAX + 20));
    expect(mb_strlen($long, 'UTF-8'))->toBe(PlanProjection::TITLE_MAX)->and(str_ends_with($long, '…'))->toBeTrue()
        ->and(AcpSchema::sessionUpdate(PlanProjection::update(planSnapshot([planStep('todo', str_repeat('x', 5000))]), PLAN_WITHOUT)))->toBe([]);
});

it('never leaves an entry with no text', function (): void {
    expect(planTitleOf(''))->toBe('Untitled step')
        ->and(planTitleOf("\0\u{202E}  "))->toBe('Untitled step')
        ->and(planTitleOf('[failed]', 'skipped'))->toBe('[skipped] Untitled step');
});

it('refuses a malformed snapshot rather than guessing', function (array $snapshot): void {
    foreach ([PLAN_WITH, PLAN_WITHOUT] as $client) {
        expect(fn () => PlanProjection::update($snapshot, $client))->toThrow(PlanProjectionException::class);
    }
})->with([
    'an unknown state' => [planSnapshot([planStep('finished')])],
    'a non-string title' => [planSnapshot([planStep('todo', 7)])],
    'a step id with a newline' => [planSnapshot([planStep('todo', 't', ['id' => "ps_1\n"])])],
    'an empty plan id' => [planSnapshot([], ['planId' => ''])],
    'a negative revision' => [planSnapshot([], ['revision' => -1])],
    'a fractional revision' => [planSnapshot([], ['revision' => 1.5])],
    'an unknown plan status' => [planSnapshot([], ['planStatus' => 'approved'])],
    'an unknown owner' => [planSnapshot([planStep('todo', 't', ['owner' => 'model'])])],
    'a duplicate step id' => [planSnapshot([planStep('todo'), planStep('todo')])],
    'more steps than the ceiling' => [planSnapshot(array_map(fn (int $i) => planStep('todo', 't', ['id' => "ps_{$i}"]), range(0, 50)))],
    'steps that are not a list' => [planSnapshot(['a' => planStep('todo')])],
]);

it('sends plan_removed only to a client that advertised plan', function (): void {
    $removed = PlanProjection::removed('pl_01JPLAN', PLAN_WITH);
    expect($removed)->toBe(['sessionUpdate' => 'plan_removed', 'planId' => 'pl_01JPLAN'])
        ->and(AcpSchema::sessionUpdate($removed))->toBe([])
        ->and(PlanProjection::removed('pl_01JPLAN', PLAN_WITHOUT))->toBeNull()
        ->and(fn () => PlanProjection::removed("pl\n1", PLAN_WITH))->toThrow(PlanProjectionException::class);
});
