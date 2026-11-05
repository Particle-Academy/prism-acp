import assert from 'node:assert/strict';

// Reviewed release deltas, independent of the missing-contract list. Adding a version requires review.
export const contracts = Object.freeze({
  readRateLimits: '0.26.0', mcpServerStatus: '0.27.0', appContainerLocal: '0.28.0',
  nativeTools: '0.29.0', approvalGuard: '0.30.0', replayMarkersAndOptOut: '0.31.0',
  restrictedStartupFlags: '0.32.0', supervisorGenerations: '0.32.0',
  claudeAppMcpOnly: '0.33.0',
  claudeSettingSources: '0.34.0', claudeStrictMcpConfig: '0.34.0',
  claudeAppendSystemPromptFile: '0.35.0', claudePluginDirs: '0.35.0', agentVersion: '0.35.0',
  codexThreadConfig: '0.36.0', ndjsonIncrementalSearch: '0.36.1',
  claudeQueuedCancelAccounting: '0.37.0',
});

// Assigned gaps are reviewed alongside release provenance; any numbered placeholder is insufficient.
const contractGaps = Object.freeze({
  readRateLimits: 'G-141', mcpServerStatus: 'G-141', appContainerLocal: 'G-141', nativeTools: 'G-141',
  replayMarkersAndOptOut: 'G-141', restrictedStartupFlags: 'G-141 (G-140)', claudeAppMcpOnly: 'G-141',
  claudeSettingSources: 'G-233', claudeStrictMcpConfig: 'G-234', claudeAppendSystemPromptFile: 'G-235',
  claudePluginDirs: 'G-236', codexThreadConfig: 'G-237', ndjsonIncrementalSearch: 'G-232',
  claudeQueuedCancelAccounting: 'G-238',
});

function core(version) {
  const match = /^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/.exec(version);
  assert(match, `Unrecognized published reference version: ${version}`);
  return match.slice(1, 4).map(BigInt);
}
function newer(left, right) {
  const a = core(left), b = core(right);
  for (let i = 0; i < 3; i++) { if (a[i] !== b[i]) return a[i] > b[i]; }
  return false;
}

export function reviewReference(metadata, review, observed) {
  assert.equal(review.goldenBaseline, '0.25.1', 'Golden baseline changes require a separate executed-reference review');
  const published = Object.keys(metadata.versions ?? {});
  assert(published.includes(review.reviewedLatest), 'Reviewed reference version is absent from the registry');
  assert.equal(metadata['dist-tags']?.latest, review.reviewedLatest, 'Latest reference needs a reviewed inventory change');
  for (const version of published) assert(!newer(version, review.reviewedLatest), `Unreviewed reference ${version} exceeds ${review.reviewedLatest}`);
  assert.deepEqual(Object.keys(observed).sort(), Object.keys(contracts).sort(), 'PHP contract inventory is incomplete or unclassified');
  const missing = new Map();
  for (const entry of review.knownMissing) {
    assert(Object.hasOwn(contracts, entry.contract), `Unknown missing contract: ${entry.contract}`);
    assert(!missing.has(entry.contract), `Duplicate missing contract: ${entry.contract}`);
    assert.equal(entry.since, contracts[entry.contract], `Wrong reference release for ${entry.contract}`);
    assert.match(entry.gap, /^G-[1-9][0-9]*(?: \(G-140\))?$/, `Missing gap citation for ${entry.contract}`);
    assert(Object.hasOwn(contractGaps, entry.contract), `No reviewed gap assignment for ${entry.contract}`);
    assert.equal(entry.gap, contractGaps[entry.contract], `Wrong gap citation for ${entry.contract}`);
    missing.set(entry.contract, entry);
  }
  for (const [name, present] of Object.entries(observed)) {
    assert.equal(typeof present, 'boolean', `Unchecked PHP contract: ${name}`);
    assert(!(present && missing.has(name)), `Stale knownMissing: PHP now exposes ${name}; review its implementation and remove the entry`);
    assert(!(!present && !missing.has(name)), `Unlisted missing PHP contract: ${name}`);
  }
  return `PHP behind TS: PARTIAL (${missing.size} contracts missing)`;
}
