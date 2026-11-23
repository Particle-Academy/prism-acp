import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {contracts, reviewReference} from './reference-review.mjs';
const review = JSON.parse(readFileSync(new URL('./reference-review.json', import.meta.url)));
const metadata = {'dist-tags': {latest: '0.47.0'}, versions: {'0.25.1': {}, '0.32.0': {}, '0.33.0': {}, '0.33.2': {}, '0.34.0': {}, '0.35.0': {}, '0.36.0': {}, '0.36.1': {}, '0.37.0': {},
  '0.38.0': {}, '0.39.0': {}, '0.40.0': {}, '0.41.0': {}, '0.41.1': {}, '0.41.2': {}, '0.42.0': {}, '0.43.0': {}, '0.44.0': {}, '0.45.0': {}, '0.46.0': {}, '0.47.0': {}}};
// Independent main-source observations: do not derive truth from the missing list under test.
const observed = {
  readRateLimits: false, mcpServerStatus: false, appContainerLocal: false, nativeTools: false,
  approvalGuard: true, replayMarkersAndOptOut: false, restrictedStartupFlags: false,
  supervisorGenerations: true, claudeAppMcpOnly: false, claudeSettingSources: false,
  claudeStrictMcpConfig: false, claudeAppendSystemPromptFile: false, claudePluginDirs: false,
  agentVersion: true, codexThreadConfig: false, ndjsonIncrementalSearch: true,
  claudeQueuedCancelAccounting: false,
  codexSteer: false, rpcRequestDeadline: false, codexUncorrelatedResponseRedaction: true, codexCommands: false,
  codexToolNames: false, fileEdits: false, stopVerdictObserver: false, windowsShippedSupervisor: false,
  claudeToolRuleArgv: true, claudeFoldedPromptSettlement: false,
  codexFailureProviderMessage: false, claudeMessageId: false, claudeUnsolicitedTurn: false, driverLiveness: false,
  claudeAccountSelection: false, childEnvCredentialList: true, claudeSharedSessions: false,
  claudeLaunchSettings: true, claudeBrowserSignIn: false,
};
test('reports the reviewed partial port honestly', () => {
  assert.equal(reviewReference(metadata, review, observed), 'PHP behind TS: PARTIAL (28 contracts missing)');
  assert.deepEqual(Object.keys(contracts).sort(), Object.keys(observed).sort());
});
test('a newer published version refuses even if latest still names the reviewed release', () => {
  assert.throws(() => reviewReference({...metadata, versions: {...metadata.versions, '0.48.0-alpha.1': {}}}, review, observed), /Unreviewed reference/);
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
  'codexSteer', 'rpcRequestDeadline', 'codexCommands', 'codexToolNames',
  'fileEdits', 'stopVerdictObserver', 'windowsShippedSupervisor', 'claudeFoldedPromptSettlement',
  'codexFailureProviderMessage', 'claudeMessageId', 'claudeUnsolicitedTurn', 'driverLiveness', 'claudeAccountSelection',
  'claudeSharedSessions', 'claudeBrowserSignIn']) {
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
  assert.equal(contracts.codexUncorrelatedResponseRedaction, '0.38.0');
  assert.equal(contracts.claudeToolRuleArgv, '0.41.2');
  const firsts = {codexSteer: '0.38.0', rpcRequestDeadline: '0.38.0',
    codexCommands: '0.39.0', codexToolNames: '0.39.0', fileEdits: '0.39.0', stopVerdictObserver: '0.40.0',
    windowsShippedSupervisor: '0.41.0', claudeFoldedPromptSettlement: '0.42.0'};
  for (const [contract, since] of Object.entries(firsts)) {
    assert.equal(contracts[contract], since, contract);
    const wrong = {...review, knownMissing: review.knownMissing.map(entry => entry.contract === contract ? {...entry, since: '0.37.0'} : entry)};
    assert.throws(() => reviewReference(metadata, wrong, observed), new RegExp('Wrong reference release for ' + contract));
  }
});

test('each 0.38.0..0.42.0 gap carries its own assigned number, never a placeholder or a neighbour', () => {
  const replace = gap => ({...review, knownMissing: review.knownMissing.map(entry => entry.contract === 'codexSteer' ? {...entry, gap} : entry)});
  assert.throws(() => reviewReference(metadata, replace('G-TBD-codex-steer'), observed), /Missing gap citation/);
  assert.throws(() => reviewReference(metadata, replace('G-298'), observed), /Wrong gap citation/);
  assert.throws(() => reviewReference(metadata, replace('G-141'), observed), /Wrong gap citation/);
});

test('the open supervisor security gap cannot be marked present while still listed', () => {
  assert.throws(() => reviewReference(metadata, review, {...observed, windowsShippedSupervisor: true}), /Stale knownMissing/);
});

for (const [contract, gap, since] of [['codexUncorrelatedResponseRedaction', 'G-299', '0.38.0'], ['claudeToolRuleArgv', 'G-305', '0.41.2']]) {
  test(`the ${gap} security fix (${contract}) cannot silently regress`, () => {
    assert.throws(() => reviewReference(metadata, review, {...observed, [contract]: false}), new RegExp('Unlisted missing PHP contract: ' + contract));
  });
  test(`the ${gap} security fix (${contract}) cannot remain on the missing list`, () => {
    const knownMissing = [...review.knownMissing, {contract, since, gap}];
    assert.throws(() => reviewReference(metadata, {...review, knownMissing}, observed), /Stale knownMissing/);
  });
}

test('the previously reviewed 0.42.0 inventory no longer satisfies a 0.47.0 latest', () => {
  assert.throws(() => reviewReference(metadata, {...review, reviewedLatest: '0.42.0'}, observed), /Latest reference needs a reviewed inventory change/);
});

for (const [contract, since, gap] of [
  ['codexFailureProviderMessage', '0.43.0', 'G-329'], ['claudeMessageId', '0.43.0', 'G-330'],
  ['claudeUnsolicitedTurn', '0.44.0', 'G-331'], ['driverLiveness', '0.44.0', 'G-332'],
  ['claudeAccountSelection', '0.45.0', 'G-333'],
  ['claudeSharedSessions', '0.46.0', 'G-335'],
  ['claudeBrowserSignIn', '0.47.0', 'G-337'],
]) {
  test(`${contract} is pinned to ${since} and ${gap}, and cannot stay listed once present`, () => {
    const replace = change => ({...review, knownMissing: review.knownMissing.map(entry => entry.contract === contract ? {...entry, ...change} : entry)});
    assert.equal(contracts[contract], since);
    assert.throws(() => reviewReference(metadata, replace({since: '0.42.0'}), observed), new RegExp('Wrong reference release for ' + contract));
    assert.throws(() => reviewReference(metadata, replace({gap: 'G-141'}), observed), new RegExp('Wrong gap citation for ' + contract));
    assert.throws(() => reviewReference(metadata, review, {...observed, [contract]: true}), /Stale knownMissing/);
  });
}

for (const [contract, since, gap] of [['childEnvCredentialList', '0.45.0', 'G-334'], ['claudeLaunchSettings', '0.46.0', 'G-336']]) {
  test(`${contract} cannot silently regress`, () => {
    assert.equal(contracts[contract], since);
    assert.throws(() => reviewReference(metadata, review, {...observed, [contract]: false}), new RegExp('Unlisted missing PHP contract: ' + contract));
  });
  test(`${contract} cannot remain on the missing list`, () => {
    const knownMissing = [...review.knownMissing, {contract, since, gap}];
    assert.throws(() => reviewReference(metadata, {...review, knownMissing}, observed), /Stale knownMissing/);
  });
}
