<?php

declare(strict_types=1);

use Prism\Acp\Ndjson\Json;
use Tests\Support\G362Oracle;

/*
 * The G-362 corrections to the reference oracle must each still be correcting something. When the golden baseline
 * advances past prism-acp-ts's fix, the oracle already carries these shapes, a count drops to zero, and this fails:
 * delete G362Oracle and this test then, rather than leave a correction that silently does nothing.
 */

$fixtures = dirname(__DIR__).'/fixtures';

it('still corrects the Codex plan entries and the plan delta in the published oracle', function () use ($fixtures): void {
    $entries = 0;
    $deltas = 0;
    foreach (Json::decode((string) file_get_contents("{$fixtures}/codex-driver.json"))->scenarios as $scenario) {
        $before = $entries;
        $corrected = G362Oracle::codex($scenario->expected, $entries);
        foreach ($corrected->events ?? [] as $event) {
            $deltas += ($event->value->_meta->{'particle.academy/unmapped_frame'}->reason ?? null) === G362Oracle::DELTA_REASON ? 1 : 0;
        }
        // A correction never touches a scenario with no plan in it.
        if (! str_contains(Json::encode($scenario->expected), '"plan')) {
            expect($entries)->toBe($before);
        }
    }
    expect($entries)->toBeGreaterThan(0)->and($deltas)->toBe(1);
});

it('still corrects the driver options in the published agent oracle', function () use ($fixtures): void {
    $applied = 0;
    foreach (Json::decode((string) file_get_contents("{$fixtures}/agent-expected.json"))->results as $result) {
        foreach ($result->log as $entry) {
            G362Oracle::agentEntry($entry, $applied);
        }
    }
    expect($applied)->toBeGreaterThan(0);
});
