// Regenerates tests/fixtures/units.json: the REFERENCE's answer for every pure reader this package ports, over a
// systematic hostile input set (each field of a valid payload replaced by each hostile value, and deleted).
//
//   node tools/golden/units.mjs <path to a built prism-acp-ts checkout>
//
// Inputs are stored as JSON TEXT and parsed by each language's own decoder, so a key such as __proto__ reaches the
// reader the way a provider's line delivers it.
import { readFileSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const reference = resolve(process.argv[2] ?? '');
const lib = await import(pathToFileURL(join(reference, 'dist', 'index.js')).href);
const version = JSON.parse(readFileSync(join(reference, 'package.json'), 'utf8')).version;

const HOSTILE = [
  'null', 'true', '0', '-0', '-1', '0.5', '1e-7', '1e13', '1e400', '9007199254740993', '60.0', '1.5', '""', '"x"',
  '"__proto__"', '"toString"', '"constructor"', '[]', '{}', '{"a":1}',
];

/** Every single-field variation of a JSON object at `path`: each hostile value, and the field deleted. */
function variations(base, paths) {
  const out = [JSON.stringify(base)];
  for (const path of paths) {
    for (const value of [...HOSTILE, undefined]) {
      const copy = structuredClone(base);
      let node = copy;
      for (const key of path.slice(0, -1)) node = node[key];
      if (value === undefined) delete node[path.at(-1)];
      else node[path.at(-1)] = JSON.parse(value);
      out.push(JSON.stringify(copy));
    }
  }
  return out;
}

const claudeBase = {
  status: 'allowed', resetsAt: 1792450800, rateLimitType: 'five_hour', overageStatus: 'rejected',
  overageDisabledReason: 'out_of_credits', isUsingOverage: false,
  unifiedWindows: { five_hour: { utilization: 0.29, resetsAt: 1792450800 }, seven_day: { utilization: 0.07, resetsAt: 1792929200 } },
};
const claudeInputs = [
  ...variations(claudeBase, [
    ['status'], ['resetsAt'], ['rateLimitType'], ['overageStatus'], ['overageDisabledReason'], ['isUsingOverage'],
    ['unifiedWindows'], ['unifiedWindows', 'five_hour'], ['unifiedWindows', 'five_hour', 'utilization'],
    ['unifiedWindows', 'five_hour', 'resetsAt'],
  ]),
  'null', '[]', '"x"', '7', '{}',
  '{"status":"rejected","resetsAt":1700000000,"rateLimitType":"__proto__","unifiedWindows":{"__proto__":{"utilization":0.5,"resetsAt":1700000001}}}',
  '{"status":"rejected","resetsAt":8.64e12,"rateLimitType":"w","unifiedWindows":{"w":{"utilization":1.005,"resetsAt":8.64e12}}}',
  '{"status":"rejected","resetsAt":8640000000000.001,"rateLimitType":"w","unifiedWindows":{}}',
  // Exact halves after x100 (12.5 and 0.5): Math.round sends a tie UP, which a port's own rounding may not.
  '{"status":"allowed","resetsAt":1700000000,"rateLimitType":"h","unifiedWindows":{"h":{"utilization":0.125,"resetsAt":1700000000}}}',
  '{"status":"allowed","resetsAt":1700000000,"rateLimitType":"h","unifiedWindows":{"h":{"utilization":0.005,"resetsAt":1700000000}}}',
  '{"status":"allowed","resetsAt":1700000000,"rateLimitType":"w","unifiedWindows":{"2":{"utilization":0.125,"resetsAt":1},"w":{"utilization":0.285,"resetsAt":1700000000.5},"1":{"utilization":0,"resetsAt":5e-324}}}',
];

const codexBase = {
  rateLimits: {
    primary: { usedPercent: 45, windowDurationMins: 300, resetsAt: 1800000000 },
    secondary: { usedPercent: 12.5, windowDurationMins: 10080, resetsAt: 1800500000 },
    credits: { hasCredits: true, unlimited: false, balance: 3.5 },
    planType: 'pro', spendControlReached: false, rateLimitReachedType: null, ordinaryUsageAllowed: true,
  },
  rateLimitResetCredits: { availableCount: 2 },
};
const codexInputs = [
  ...variations(codexBase, [
    ['rateLimits'], ['rateLimits', 'primary'], ['rateLimits', 'primary', 'usedPercent'],
    ['rateLimits', 'primary', 'windowDurationMins'], ['rateLimits', 'primary', 'resetsAt'], ['rateLimits', 'secondary'],
    ['rateLimits', 'credits'], ['rateLimits', 'credits', 'hasCredits'], ['rateLimits', 'credits', 'unlimited'],
    ['rateLimits', 'credits', 'balance'], ['rateLimits', 'planType'], ['rateLimits', 'spendControlReached'],
    ['rateLimits', 'rateLimitReachedType'], ['rateLimits', 'ordinaryUsageAllowed'], ['rateLimitResetCredits'],
    ['rateLimitResetCredits', 'availableCount'],
  ]),
  'null', '[]', '{}', '{"primary":null,"secondary":null}', '{"ordinaryUsageAllowed":null,"primary":null,"secondary":null}',
  '{"ordinaryUsageAllowed":false,"rateLimits":{"ordinaryUsageAllowed":true,"primary":null,"secondary":null}}',
  '{"ordinaryUsageAllowed":null,"rateLimits":{"ordinaryUsageAllowed":"x","primary":null,"secondary":null}}',
];

const failureInputs = [
  ...['null', '7', '"x"', '[]', '{}', '{"codexErrorInfo":null}', '{"codexErrorInfo":""}', '{"codexErrorInfo":{}}'],
  ...['usageLimitExceeded', 'rateLimitExceeded', 'serverOverloaded', 'unauthorized', 'other', '__proto__', 'constructor', 'toString']
    .flatMap((r) => [`{"codexErrorInfo":${JSON.stringify(r)}}`, `{"codexErrorInfo":{${JSON.stringify(r)}:{"httpStatusCode":429}}}`]),
  '{"codexErrorInfo":{"2":1,"b":2}}', '{"codexErrorInfo":{"":1}}',
];

const event = (status, resetsAt, type = 'five_hour', windows = `{"five_hour":{"utilization":1,"resetsAt":${resetsAt}}}`) =>
  `{"type":"rate_limit_event","rate_limit_info":{"status":"${status}","resetsAt":${resetsAt},"rateLimitType":"${type}","unifiedWindows":${windows}}}`;
const assistant = (error, parent = 'null') => `{"type":"assistant","parent_tool_use_id":${parent},"message":{"id":"m","content":[]},"error":${error}}`;
const turnInputs = [
  [assistant('"rate_limit"'), event('rejected', 1800000000)],
  [event('rejected', 1800000000), assistant('"rate_limit"')],
  [assistant('"rate_limit"'), event('allowed', 1800000000)],
  [assistant('"overloaded"'), event('rejected', 1800000000)],
  [assistant('"rate_limit"', '"toolu_1"')],
  [assistant('"rate_limit"'), '{"type":"assistant","parent_tool_use_id":null,"message":{}}'],
  [assistant('7')],
  [assistant('"__proto__"')], [assistant('"constructor"')], [assistant('"something_new"')],
  [assistant('"authentication_failed"')], [assistant('"billing_error"')], [assistant('"account_on_hold"')],
  [assistant('"rate_limit"'), event('rejected', 1800000000, 'seven_day', '{"seven_day":{"utilization":1,"resetsAt":1900000000}}')],
  [assistant('"rate_limit"'), event('rejected', 1800000000, 'toString', '{}')],
  [assistant('"rate_limit"'), event('rejected', 1e13)],
  [assistant('"rate_limit"'), '{"type":"rate_limit_event","status":"rejected","resetsAt":1800000000,"rateLimitType":"w","unifiedWindows":{}}'],
  ['null', '[]', '"x"'],
];

const textInputs = [
  '"\\u001b[31mred\\u001b[0m"', '"\\u009b2Kvisible"', '"before\\u009d0;title\\u009cafter"', '"before\\u001b"',
  '"plain\\tUnicode λ and CRLF\\r\\n"', '"\\u001b]8;;http://x\\u001b\\\\link\\u001b]8;;\\u001b\\\\"', '"a\\u001b]0;t\\u0007b"',
  '"a\\u001bPq#0\\u001b\\\\b"', '"a\\u001b(Bb"', '"a\\u001b"', '"\\u001b["', '"\\u001b[?25"', '"a\\u0000b\\u007fc\\u0085d"',
  '"😀\\u001b[1m😀"', '"\\u0090x\\u009c y"', '"\\u009ex\\u001b\\\\y"', '"a\\u001b\\u001b[1mb"', '"a\\u001b !b"',
  '"\\u001b\\u0020\\u0021"', '""', '"\\u200b\\u2028\\ufeff"',
];

const sessionIdInputs = [
  '"abc"', '"9adbc891-a558-48e0-93b0-460d6500ead8"', '""', '"-abc"', '".x"', '"a b"', '"a\\n"', '"a\\nb"', '"A:b.c_d-e"',
  JSON.stringify('a'.repeat(128)), JSON.stringify('a'.repeat(129)), '"ünï"', '7', 'null', '["a"]', '"a/b"', '"a\\u0000"',
];

const out = {
  reference: `prism-acp-ts ${version}`,
  claudeRateLimit: claudeInputs.map((input) => {
    const read = lib.readRateLimit(JSON.parse(input));
    let notice = null;
    if (read.ok) notice = lib.rateLimitNotice(read.limit);
    return [input, JSON.stringify(read), notice];
  }),
  codexRateLimit: codexInputs.map((input) => {
    const read = lib.readCodexRateLimit(JSON.parse(input));
    return [input, JSON.stringify(read), read.ok ? lib.codexRateLimitNotice(read.limit) : null];
  }),
  codexFailure: failureInputs.map((input) => [input, JSON.stringify(lib.codexFailureOf(JSON.parse(input)) ?? null)]),
  claudeTurn: turnInputs.map((frames) => {
    const tracker = new lib.ClaudeTurnFailures();
    for (const frame of frames) tracker.observe(JSON.parse(frame));
    const first = tracker.take();
    return [frames, JSON.stringify(first ?? null), JSON.stringify(tracker.take() ?? null)];
  }),
  text: textInputs.map((input) => [input, JSON.stringify(lib.stripAnsiControlSequences(JSON.parse(input)))]),
};
// isValidCliSessionId is not exported from the package root (the driver applies it), so its own module is loaded.
const { isValidCliSessionId } = await import(pathToFileURL(join(reference, 'dist', 'session-id.js')).href);
out.sessionId = sessionIdInputs.map((input) => [input, isValidCliSessionId(JSON.parse(input))]);

writeFileSync(new URL('../../tests/fixtures/units.json', import.meta.url), JSON.stringify(out, null, 1) + '\n');
console.log(Object.entries(out).filter(([, v]) => Array.isArray(v)).map(([k, v]) => `${k}: ${v.length}`).join(', '));
