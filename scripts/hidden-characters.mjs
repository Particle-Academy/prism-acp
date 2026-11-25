// Record the TypeScript reference's hidden-character sets as code point ranges: tests/fixtures/hidden-characters.json.
//
// Usage: node scripts/hidden-characters.mjs <prism-acp-ts checkout at the reference tag> [version]
//
// The regular expressions are read from the reference's own source (src/plan.ts HIDDEN, src/acp/agent.ts
// MARKDOWN_HIDDEN, src/text.ts HIDDEN) and run by this Node, so the recording is what the reference matches, not a
// copy of PHP's list. Each code point is tested alone. Surrogates are left out (a PHP string cannot hold one), and so
// is the context-dependent part of MARKDOWN_HIDDEN: a carriage return is recorded because one alone is refused, and
// HiddenCharactersTest checks CRLF separately. No network, no dependencies.
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const [root, version = 'unknown'] = process.argv.slice(2);
if (!root) {
  console.error('usage: node scripts/hidden-characters.mjs <prism-acp-ts checkout> [version]');
  process.exit(1);
}

function literal(file, name) {
  const source = readFileSync(join(root, file), 'utf8');
  const match = source.match(new RegExp(`const ${name} = /(.+)/([a-z]*);`));
  if (!match) throw new Error(`${name} not found in ${file}`);
  return new RegExp(match[1], match[2].replace('g', ''));
}

function ranges(pattern) {
  const out = [];
  let start = -1;
  for (let cp = 0; cp <= 0x110000; cp += 1) {
    const surrogate = cp >= 0xd800 && cp <= 0xdfff;
    const hit = cp <= 0x10ffff && !surrogate && pattern.test(String.fromCodePoint(cp));
    if (hit && start < 0) start = cp;
    if (!hit && start >= 0) {
      out.push([start, cp - 1]);
      start = -1;
    }
  }
  return out;
}

const sets = {
  plan: literal('src/plan.ts', 'HIDDEN'),
  markdown: literal('src/acp/agent.ts', 'MARKDOWN_HIDDEN'),
  provider: literal('src/text.ts', 'HIDDEN'),
};
const recording = {
  reference: `@particle-academy/prism-acp ${version}`,
  runtime: `node ${process.version}, Unicode ${process.versions.unicode}`,
  note: 'Each set as [first, last] code point ranges, every code point tested alone. Surrogates are not listed.',
  sets: Object.fromEntries(Object.entries(sets).map(([name, pattern]) => [name, { pattern: pattern.source, ranges: ranges(pattern) }])),
};
writeFileSync(new URL('../tests/fixtures/hidden-characters.json', import.meta.url), `${JSON.stringify(recording, null, 2)}\n`);
