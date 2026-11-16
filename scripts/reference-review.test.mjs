import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {contracts, reviewReference} from './reference-review.mjs';
const review = JSON.parse(readFileSync(new URL('./reference-review.json', import.meta.url)));
const metadata = {'dist-tags': {latest: '0.42.0'}, versions: {'0.25.1': {}, '0.32.0': {}, '0.33.0': {}, '0.33.2': {}, '0.34.0': {}, '0.35.0': {}, '0.36.0': {}, '0.36.1': {}, '0.37.0': {},
  '0.38.0': {}, '0.39.0': {}, '0.40.0': {}, '0.41.0': {}, '0.41.1': {}, '0.41.2': {}, '0.42.0': {}}};
// Independent main-source observations: do not derive truth from the missing list under test.
const observed = {
  readRateLimits: false, mcpServerStatus: false, appContainerLocal: false, nativeTools: false,
  approvalGuard: true, replayMarkersAndOptOut: false, restrictedStartupFlags: false,
  supervisorGenerations: true, claudeAppMcpOnly: false, claudeSettingSources: false,
  claudeStrictMcpConfig: false, claudeAppendSystemPromptFile: false, claudePluginDirs: false,
  agentVersion: true, codexThreadConfig: false, ndjsonIncrementalSearch: true,
  claudeQueuedCancelAccounting: false,
  codexSteer: false, rpcRequestDeadline: false, codexUncorrelatedResponseRedaction: false, codexCommands: false,
  codexToolNames: false, fileEdits: false, stopVerdictObserver: false, windowsShippedSupervisor: false,
  claudeToolRuleArgv: false, claudeFoldedPromptSettlement: false,
};
test('reports the reviewed partial port honestly', () => {
  assert.equal(reviewReference(metadata, review, observed), 'PHP behind TS: PARTIAL (23 contracts missing)');
  assert.deepEqual(Object.keys(contracts).sort(), Object.keys(observed).sort());
});
test('a newer published version refuses even if latest still names the reviewed release', () => {
  assert.throws(() => reviewReference({...metadata, versions: {...metadata.versions, '0.43.0-alpha.1': {}}}, review, observed), /Unreviewed reference/);
});
test('a missing contract cannot disappear from the gap list', () => {
  assert.throws(() => reviewReference(metadata, {...review, knownMissing: review.knownMissing.slice(1)}, observed), /Unlisted missing/);
});

test('the new Claude restriction cannot disappear into the older Codex contract', () => {
  const knownMissing = review.knownMissing.filter(entry => entry.contract !== 'claudeAppMcpOnly');
  assert.throws(() => reviewReference(metadata, {...review, knownMissing}, observed), /Unlisted missing PHP contract: claudeAppMcpOnly/);
});
test('an implemented contract cannot remain on the generous missing list', () => {
  assert.throws(() => reviewReference(metadata, review, {...observed, readRateLimits: true}), /Stale knownMissing/);
});
test('a required implemented security contract cannot silently regress', () => {
  assert.throws(() => reviewReference(metadata, review, {...observed, approvalGuard: false}), /Unlisted missing/);
});
test('unknown and unchecked inventory entries refuse', () => {
  assert.throws(() => reviewReference(metadata, review, {...observed, invented: false}), /incomplete or unclassified/);
  assert.throws(() => reviewReference(metadata, review, {...observed, nativeTools: null}), /Unchecked PHP contract/);
});

for (const contract of ['claudeSettingSources', 'claudeStrictMcpConfig', 'claudeAppendSystemPromptFile',
  'claudePluginDirs', 'codexThreadConfig', 'claudeQueuedCancelAccounting',
  'codexSteer', 'rpcRequestDeadline', 'codexUncorrelatedResponseRedaction', 'codexCommands', 'codexToolNames',
  'fileEdits', 'stopVerdictObserver', 'windowsShippedSupervisor', 'claudeToolRuleArgv', 'claudeFoldedPromptSettlement']) {
  test(`${contract} cannot silently disappear from the reviewed gap inventory`, () => {
    const knownMissing = review.knownMissing.filter(entry => entry.contract !== contract);
    assert.throws(() => reviewReference(metadata, {...review, knownMissing}, observed), new RegExp(`Unlisted missing PHP contract: ${contract}`));
  });
}

test('a framing regression cannot disappear from the reviewed inventory', () => {
  assert.throws(() => reviewReference(metadata, review, {...observed, ndjsonIncrementalSearch: false}), /Unlisted missing/);
});

test('the implemented framing fix cannot remain on the missing list', () => {
  const knownMissing = [...review.knownMissing, {contract: 'ndjsonIncrementalSearch', since: '0.36.1', gap: 'G-232'}];
  assert.throws(() => reviewReference(metadata, {...review, knownMissing}, observed), /Stale knownMissing/);
});

test('the already implemented default agent version cannot regress unnoticed', () => {
  assert.throws(() => reviewReference(metadata, review, {...observed, agentVersion: false}), /Unlisted missing/);
});

test('a newly reviewed contract needs a numbered gap and its actual first release', () => {
  const replace = patch => ({...review, knownMissing: review.knownMissing.map(entry => entry.contract === 'codexThreadConfig' ? {...entry, ...patch} : entry)});
  assert.throws(() => reviewReference(metadata, replace({gap: 'unknown'}), observed), /Missing gap citation/);
  assert.throws(() => reviewReference(metadata, replace({gap: 'G-0'}), observed), /Missing gap citation/);
  assert.throws(() => reviewReference(metadata, replace({gap: 'G-141'}), observed), /Wrong gap citation/);
  assert.throws(() => reviewReference(metadata, replace({gap: 'G-999'}), observed), /Wrong gap citation/);
  assert.throws(() => reviewReference(metadata, replace({since: '0.35.0'}), observed), /Wrong reference release/);
});

test('the previously reviewed 0.37.0 inventory no longer satisfies a 0.42.0 latest', () => {
  assert.throws(() => reviewReference(metadata, {...review, reviewedLatest: '0.37.0'}, observed), /Latest reference needs a reviewed inventory change/);
});

test('each 0.38.0..0.42.0 gap is pinned to its first release', () => {
  const firsts = {codexSteer: '0.38.0', rpcRequestDeadline: '0.38.0', codexUncorrelatedResponseRedaction: '0.38.0',
    codexCommands: '0.39.0', codexToolNames: '0.39.0', fileEdits: '0.39.0', stopVerdictObserver: '0.40.0',
    windowsShippedSupervisor: '0.41.0', claudeToolRuleArgv: '0.41.2', claudeFoldedPromptSettlement: '0.42.0'};
  for (const [contract, since] of Object.entries(firsts)) {
    assert.equal(contracts[contract], since, contract);
    const wrong = {...review, knownMissing: review.knownMissing.map(entry => entry.contract === contract ? {...entry, since: '0.37.0'} : entry)};
    assert.throws(() => reviewReference(metadata, wrong, observed), new RegExp('Wrong reference release for ' + contract));
  }
});

test('each 0.38.0..0.42.0 gap carries its own assigned number, never a placeholder or a neighbour', () => {
  const replace = gap => ({...review, knownMissing: review.knownMissing.map(entry => entry.contract === 'codexUncorrelatedResponseRedaction' ? {...entry, gap} : entry)});
  assert.throws(() => reviewReference(metadata, replace('G-TBD-codex-uncorrelated-redaction'), observed), /Missing gap citation/);
  assert.throws(() => reviewReference(metadata, replace('G-298'), observed), /Wrong gap citation/);
  assert.throws(() => reviewReference(metadata, replace('G-141'), observed), /Wrong gap citation/);
});

test('the security hardening gaps cannot be marked present while still listed', () => {
  for (const contract of ['codexUncorrelatedResponseRedaction', 'claudeToolRuleArgv', 'windowsShippedSupervisor']) {
    assert.throws(() => reviewReference(metadata, review, {...observed, [contract]: true}), /Stale knownMissing/);
  }
});
