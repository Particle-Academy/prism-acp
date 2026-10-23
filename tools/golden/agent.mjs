// Runs tests/fixtures/agent-scenarios.json through the REFERENCE's AcpAgent with a fake driver, and writes what it sent
// and did to tests/fixtures/agent-expected.json.
//
//   node tools/golden/agent.mjs <path to a built prism-acp-ts checkout>
//
// A step is: a client frame (fed to the peer), or a driver event (fed to the session's driver events). After each step
// the event loop is drained, and everything the agent sent, every call it made on a driver, and every problem it
// reported, is recorded in order. The PHP test replays the same steps and compares the log entry by entry.
import { readFileSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const reference = resolve(process.argv[2] ?? '');
const { AcpAgent, JsonRpcPeer, PromptRefused } = await import(pathToFileURL(join(reference, 'dist', 'index.js')).href);
const version = JSON.parse(readFileSync(join(reference, 'package.json'), 'utf8')).version;
const fixtures = new URL('../../tests/fixtures/', import.meta.url);
const { scenarios } = JSON.parse(readFileSync(new URL('agent-scenarios.json', fixtures), 'utf8'));

const drain = async () => { for (let i = 0; i < 5; i++) await new Promise((r) => setImmediate(r)); };

function fakeDriver(index, spec, options, events, log) {
  const pick = (value, n) => (Array.isArray(value) ? value[n] ?? value.at(-1) : value);
  let prompts = 0;
  let cancels = 0;
  const driver = {
    cliSessionId: spec.cliSessionId ?? null,
    withheldCredentials: [],
    start: () => log.push({ call: [index, 'start', options] }),
    prompt: (content) => {
      log.push({ call: [index, 'prompt', content] });
      const how = pick(spec.promptThrows, prompts++) ?? null;
      if (how === 'refused') throw new PromptRefused(0, 'image/png', 'the driver will not send it');
      if (how === 'error') throw new Error('the agent could not be written to');
    },
    endInput: () => log.push({ call: [index, 'endInput'] }),
    kill: () => log.push({ call: [index, 'kill'] }),
  };
  const cancel = pick(spec.cancel ?? 'ok', 0);
  if (cancel !== 'none') {
    driver.cancel = () => {
      log.push({ call: [index, 'cancel'] });
      return pick(spec.cancel ?? 'ok', cancels++) === 'reject' ? Promise.reject(new Error('the agent refused the interrupt')) : Promise.resolve();
    };
  }
  if (spec.models !== 'none' && spec.models !== undefined) {
    driver.modelState = () => (spec.models === 'reject' ? Promise.reject(new Error('no model list')) : Promise.resolve(spec.models));
    driver.setModel = (modelId) => {
      log.push({ call: [index, 'setModel', modelId] });
      return modelId.startsWith('-') ? Promise.reject(new Error(`refusing model ${JSON.stringify(modelId)}`)) : Promise.resolve();
    };
  }
  return driver;
}

const results = [];
for (const scenario of scenarios) {
  const log = [];
  const drivers = [];
  let ids = 0;
  const peer = new JsonRpcPeer({
    send: (message) => log.push({ sent: message }),
    onProtocolError: (problem) => log.push({ peerProblem: problem }),
  });
  new AcpAgent(peer, {
    driverFactory: (options, events) => {
      const index = drivers.length;
      const driver = fakeDriver(index, scenario.driver ?? {}, options, events, log);
      drivers.push({ driver, events });
      return driver;
    },
    ...(scenario.capabilities === null ? {} : { driverCapabilities: scenario.capabilities }),
    ...(scenario.probe === undefined ? {} : { probeSession: (id) => scenario.probe[id] ?? { existence: 'present', detail: '' } }),
    agentInfo: { name: 'golden', version: '1' },
    newSessionId: () => `s${++ids}`,
    onStderr: (sessionId, line) => log.push({ stderr: [sessionId, line] }),
    onProtocolError: (sessionId, problem, detail) => log.push({ problem: [sessionId, problem, detail?.recoverable ?? null] }),
  });

  for (const step of scenario.steps) {
    log.push({ step });
    if ('client' in step) {
      void peer.receive(step.client);
    } else {
      const { events } = drivers[step.driver];
      if ('update' in step) events.onUpdate?.(step.update);
      if ('turnEnd' in step) events.onTurnEnd?.(step.turnEnd);
      if ('exit' in step) events.onExit?.(step.exit, null);
      if ('stderr' in step) events.onStderr?.(step.stderr);
      if ('protocolError' in step) events.onProtocolError?.(step.protocolError, step.recoverable === undefined ? undefined : { recoverable: step.recoverable });
      if ('permission' in step) {
        events.onRequestPermission?.(step.permission).then(
          (outcome) => log.push({ permission: [step.driver, outcome] }),
          (error) => log.push({ permissionError: [step.driver, error.message] }),
        );
      }
    }
    await drain();
  }
  results.push({ name: scenario.name, log });
}

writeFileSync(new URL('agent-expected.json', fixtures), JSON.stringify({ reference: `prism-acp-ts ${version}`, results }, null, 1) + '\n');
console.log(`${results.length} scenarios, ${results.reduce((n, r) => n + r.log.length, 0)} log entries`);
