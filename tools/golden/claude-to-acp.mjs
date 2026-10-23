// Regenerates tests/fixtures/claude-to-acp/: the corpus every Claude mapping test reads, and the REFERENCE's output for
// it. The reference is prism-acp-ts; this script runs its built dist/ and records, line for line, what it produced.
//
//   node tools/golden/claude-to-acp.mjs <path to a built prism-acp-ts checkout>
//
// The corpus is every real Claude capture the reference keeps in test/fixtures, plus hostile mutations of their frames
// (seeded, so a disagreement reproduces) and hand-written edge frames the captures never show. A line the reference
// produced by throwing is recorded as THROW: the PHP port must not throw there either, and the test says so.
import { readFileSync, writeFileSync, mkdirSync, readdirSync, rmSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const reference = resolve(process.argv[2] ?? '');
const { NdjsonFramer, ClaudeToAcp } = await import(pathToFileURL(join(reference, 'dist', 'index.js')).href);
const version = JSON.parse(readFileSync(join(reference, 'package.json'), 'utf8')).version;
const fixtures = join(reference, 'test', 'fixtures');
const out = new URL('../../tests/fixtures/claude-to-acp/', import.meta.url);
rmSync(out, { recursive: true, force: true });
mkdirSync(new URL('corpus/', out), { recursive: true });
mkdirSync(new URL('expected/', out), { recursive: true });

let seed = 0x5eed1234;
const rand = () => {
  seed ^= seed << 13; seed >>>= 0;
  seed ^= seed >>> 17;
  seed ^= seed << 5; seed >>>= 0;
  return seed / 0x100000000;
};
const pick = (list) => list[Math.floor(rand() * list.length)];

const HOSTILE = [
  null, true, false, 0, -1, 1.5, 1e21, 1e-7, 9007199254740993, -0, 1e300, '', ' ', '__proto__', 'constructor',
  'toString', 'hasOwnProperty', 'BASH', '\u212Aillshell', 'Bash\u001b[31m', '\u001b]0;title\u0007x', '\u009b2J',
  '<local-command-stdout> hi </local-command-stdout>', '<local-command-stdout></local-command-stdout>',
  '/', '//x', '\u00a0', '\u2028', '😀', 'a\u0000b', [], [1, 'x'], {}, { a: 1 }, { 1: 'a', b: 2, 0: 'z' },
  '{"a":1}', '{"a":', 'null', '[]',
];

function mutate(value, depth = 0) {
  if (Array.isArray(value)) {
    const copy = value.map((v) => (rand() < 0.3 ? mutate(v, depth + 1) : v));
    if (rand() < 0.1) copy.push(pick(HOSTILE));
    return copy;
  }
  if (value !== null && typeof value === 'object') {
    const copy = {};
    for (const [k, v] of Object.entries(value)) {
      const r = rand();
      if (k === 'type' && depth === 0 && r < 0.9) { copy[k] = v; continue; }
      if (r < 0.08) continue;
      if (r < 0.25) { copy[k] = pick(HOSTILE); continue; }
      copy[k] = typeof v === 'object' && v !== null ? mutate(v, depth + 1) : v;
    }
    if (rand() < 0.1) copy[pick(['__proto__x', 'parent_tool_use_id', 'is_error', 'isSynthetic', 'error', 'index'])] = pick(HOSTILE);
    return copy;
  }
  return value;
}

const corpus = {};
for (const name of readdirSync(fixtures).sort()) {
  if (!name.startsWith('claude-') || name === 'claude-initialize-models.json') continue;
  const text = readFileSync(join(fixtures, name), 'utf8');
  const frames = name.endsWith('.json')
    ? [JSON.parse(text)].flat()
    : text.split('\n').filter((l) => l.trim() !== '').map((l) => JSON.parse(l));
  const stem = name.replace(/\.[a-z]+$/, '');
  corpus[stem] = frames.map((f) => JSON.stringify(f)).join('\n') + '\n';
  // Only the frames a round changed are kept, with the unchanged ones that carry state (block starts, message starts)
  // left in: the mapper is stateful, and a mutated delta with no start before it tests nothing.
  for (let round = 0; round < 4; round++) {
    const lines = [];
    for (const frame of frames) {
      const changed = rand() < 0.5;
      const value = changed ? mutate(frame) : frame;
      const stateful = frame?.type === 'stream_event' && /start|stop/.test(frame?.event?.type ?? '');
      if (changed || stateful || frame?.type === 'result') lines.push(JSON.stringify(value));
    }
    corpus[`${stem}--m${round}`] = lines.join('\n') + '\n';
  }
}

// Hand-written edges, as JSON TEXT: an object literal's __proto__ key would set the prototype instead.
corpus.edges = [
  '{"type":"stream_event","event":{"type":"content_block_start","index":1.0,"content_block":{"type":"tool_use","name":"__proto__"}}}',
  '{"type":"stream_event","event":{"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"\\"\\\\u0000unparsed\\""}}}',
  '{"type":"stream_event","event":{"type":"content_block_stop","index":1}}',
  '{"type":"stream_event","event":{"type":"content_block_delta","index":0,"delta":{"type":true,"text":"a loose switch matches this"}}}',
  '{"type":"stream_event","event":{"type":"content_block_start","index":"x","content_block":{"type":"tool_use","id":"a"}}}',
  '{"type":"stream_event","event":{"type":"content_block_delta","index":"y","delta":{"type":"input_json_delta","partial_json":"{\\"k\\":1e400}"}}}',
  '{"type":"stream_event","event":{"type":"content_block_stop","index":null}}',
  '{"type":"stream_event","event":{"type":"message_start","message":{"usage":{"input_tokens":5}}}}',
  '{"type":"assistant","message":{"usage":{"input_tokens":7,"output_tokens":1}}}',
  '{"type":"result","usage":{"input_tokens":1e400},"modelUsage":{"m":{"contextWindow":1000}}}',
  '{"type":"rate_limit_event","rate_limit_info":{"status":"x","resetsAt":1e13,"rateLimitType":"t","unifiedWindows":{}}}',
  '{"type":"rate_limit_event","rate_limit_info":{"status":"x","resetsAt":1700000000.5,"rateLimitType":"t","unifiedWindows":{"t":{"utilization":0.285,"resetsAt":1700000000}}}}',
  '{"type":"rate_limit_event","rate_limit_info":{"status":"allowed","resetsAt":1700000000,"rateLimitType":"__proto__","unifiedWindows":{"__proto__":{"utilization":0.5,"resetsAt":1700000001},"five_hour":{"utilization":0.1,"resetsAt":1700000002}}}}',
  '{"type":"rate_limit_event","rate_limit_info":{"status":"rejected","resetsAt":1700000000,"rateLimitType":"toString","unifiedWindows":{}}}',
  '{"type":"system","subtype":"compact_boundary","compact_metadata":{"pre_tokens":1234567.891,"post_tokens":0.0005}}',
  '{"type":"system","subtype":"compact_boundary","compact_metadata":{"pre_tokens":-0,"post_tokens":1e21}}',
  '{"type":"system","subtype":"task_updated","task_id":"t","patch":{"description":"a\\u001b[1mb"}}',
  '{"type":"system","subtype":"task_progress","task_id":"t","summary":"\\u001b[0m","patch":{},"usage":{}}',
  '{"type":"system","subtype":"background_tasks_changed","tasks":[{"task_id":"a"},7,{"task_id":""}]}',
  '{"type":"system","subtype":"permission_denied","tool_use_id":"x"}',
  '{"type":"system","subtype":"init","session_id":"s","slash_commands":["/a","a","//b","/",7],"skills":["doctor","c"],"terminal_slash_commands":["/doctor","/"],"plugins":[{"name":"p","version":""},"q"],"agents":["x","",3]}',
  '{"type":"user","message":{"content":"  <local-command-stdout>\\u00a0 said \\u2028</local-command-stdout>\\ufeff"}}',
  '{"type":"user","message":{"content":[{"type":"tool_result","tool_use_id":"z","content":[{"text":"a"},"b",{"text":3}]}]},"parent_tool_use_id":"p"}',
  '{"type":"assistant","parent_tool_use_id":"p","message":{"content":[{"type":"tool_use","id":"q","name":"TodoWrite","input":[]},{"type":"tool_use","id":"q"}]}}',
  '{"1":"a","0":"b","type":"mystery"}',
  '"just a string"', '42', '[1,2]',
  '{"type":"user","message":{"content":"x',
  '{nope\r',
  '   ',
].join('\n') + '\n';

for (const [name, text] of Object.entries(corpus)) {
  writeFileSync(new URL(`corpus/${name}.ndjson`, out), text);
  const framer = new NdjsonFramer();
  const mapper = new ClaudeToAcp();
  const lines = [];
  for (const frame of [...framer.push(Buffer.from(text)), ...framer.end()]) {
    if (!frame.ok) { lines.push(`ERR ${frame.error.kind} ${frame.error.bytes}`); continue; }
    try {
      for (const update of mapper.frame(frame.value)) lines.push(JSON.stringify(update));
    } catch (e) {
      lines.push(`THROW ${e.constructor.name}`);
    }
  }
  lines.push(`UNMAPPED ${JSON.stringify(mapper.unmapped)}`);
  writeFileSync(new URL(`expected/${name}.txt`, out), lines.join('\n') + '\n');
}
writeFileSync(new URL('REFERENCE', out), `prism-acp-ts ${version}\n`);
console.log(`${Object.keys(corpus).length} streams from prism-acp-ts ${version}`);
