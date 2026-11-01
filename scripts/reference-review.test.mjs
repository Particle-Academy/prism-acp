import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {contracts, reviewReference} from './reference-review.mjs';
const review = JSON.parse(readFileSync(new URL('./reference-review.json', import.meta.url)));
const metadata = {'dist-tags': {latest: '0.33.2'}, versions: {'0.25.1': {}, '0.32.0': {}, '0.33.0': {}, '0.33.2': {}}};
const observed = Object.fromEntries(Object.keys(contracts).map(name => [name, !review.knownMissing.some(e => e.contract === name)]));
test('reports the reviewed partial port honestly', () => {
  assert.equal(reviewReference(metadata, review, observed), 'PHP behind TS: PARTIAL (7 contracts missing)');
});
test('a newer published version refuses even if latest still names the reviewed release', () => {
  assert.throws(() => reviewReference({...metadata, versions: {...metadata.versions, '0.34.0-alpha.1': {}}}, review, observed), /Unreviewed reference/);
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
