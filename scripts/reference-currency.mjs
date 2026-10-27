// A reviewed partial port is explicit; neither the golden baseline nor latest review auto-advances.
import {spawnSync} from 'node:child_process';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {reviewReference} from './reference-review.mjs';
const response = await fetch('https://registry.npmjs.org/@particle-academy%2fprism-acp', {
  headers: { 'Cache-Control': 'no-cache' }, signal: AbortSignal.timeout(30_000),
});
if (!response.ok) throw new Error(`reference registry request failed (${response.status})`);
const metadata = await response.json();
const review = JSON.parse(readFileSync(new URL('./reference-review.json', import.meta.url)));
const probe = spawnSync('php', [fileURLToPath(new URL('./reference-contracts.php', import.meta.url))], {encoding:'utf8', timeout:5000, maxBuffer:1000000, windowsHide:true});
if (probe.error || probe.status !== 0 || probe.stderr !== '') throw Error('PHP contract inventory failed: '+(probe.error?.message ?? probe.stderr));
const observed = JSON.parse(probe.stdout.trim());
const verdict = reviewReference(metadata, review, observed);
assert(readFileSync(new URL('../README.md', import.meta.url), 'utf8').includes(verdict), 'README parity statement disagrees with the measured inventory');
console.log(verdict);
console.log(JSON.stringify({goldenBaseline:review.goldenBaseline, reviewedLatest:review.reviewedLatest, observed, knownMissing:review.knownMissing}));
