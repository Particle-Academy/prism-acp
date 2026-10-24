// A scheduled failure makes a new reference release visible. It is reviewed and the fixture pin advanced together.
const response = await fetch('https://registry.npmjs.org/@particle-academy%2fprism-acp', {
  headers: { 'Cache-Control': 'no-cache' }, signal: AbortSignal.timeout(30_000),
});
if (!response.ok) throw new Error(`reference registry request failed (${response.status})`);
const metadata = await response.json();
const latest = metadata['dist-tags']?.latest;
if (latest !== '0.25.0') throw new Error(`Reference ${latest} needs review; fixtures and published code are pinned to 0.25.0`);
console.log(`Published TypeScript reference is current: ${latest}`);
