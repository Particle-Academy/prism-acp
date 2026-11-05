import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {contracts, reviewReference} from './reference-review.mjs';
const review = JSON.parse(readFileSync(new URL('./reference-review.json', import.meta.url)));
const metadata = {'dist-tags': {latest: '0.37.0'}, versions: {'0.25.1': {}, '0.32.0': {}, '0.33.0': {}, '0.33.2': {}, '0.34.0': {}, '0.35.0': {}, '0.36.0': {}, '0.36.1': {}, '0.37.0': {}}};
// Independent main-source observations: do not derive truth from the missing list under test.
const observed = {
  readRateLimits: false, mcpServerStatus: false, appContainerLocal: false, nativeTools: false,
  approvalGuard: true, replayMarkersAndOptOut: false, restrictedStartupFlags: false,
  supervisorGenerations: true, claudeAppMcpOnly: false, claudeSettingSources: false,
  claudeStrictMcpConfig: false, claudeAppendSystemPromptFile: false, claudePluginDirs: false,
  agentVersion: true, codexThreadConfig: false, ndjsonIncrementalSearch: false,
  claudeQueuedCancelAccounting: false,
};
test('reports the reviewed partial port honestly', () => {
  assert.equal(reviewReference(metadata, review, observed), 'PHP behind TS: PARTIAL (14 contracts missing)');
  assert.deepEqual(Object.keys(contracts).sort(), Object.keys(observed).sort());
});
test('a newer published version refuses even if latest still names the reviewed release', () => {
  assert.throws(() => reviewReference({...metadata, versions: {...metadata.versions, '0.38.0-alpha.1': {}}}, review, observed), /Unreviewed reference/);
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
  'claudePluginDirs', 'codexThreadConfig', 'ndjsonIncrementalSearch', 'claudeQueuedCancelAccounting']) {
  test(`${contract} cannot silently disappear from the reviewed gap inventory`, () => {
    const knownMissing = review.knownMissing.filter(entry => entry.contract !== contract);
    assert.throws(() => reviewReference(metadata, {...review, knownMissing}, observed), new RegExp(`Unlisted missing PHP contract: ${contract}`));
  });
}

test('a landed framing fix must clear its missing entry without clearing other gaps', () => {
  assert.throws(() => reviewReference(metadata, review, {...observed, ndjsonIncrementalSearch: true}), /Stale knownMissing/);
  const updated = {...review, knownMissing: review.knownMissing.filter(entry => entry.contract !== 'ndjsonIncrementalSearch')};
  assert.equal(reviewReference(metadata, updated, {...observed, ndjsonIncrementalSearch: true}), 'PHP behind TS: PARTIAL (13 contracts missing)');
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
