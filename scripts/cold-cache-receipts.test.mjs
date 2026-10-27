import assert from 'node:assert/strict';
import test from 'node:test';
import {verifyCompilerCompetition} from './cold-cache-receipts.mjs';
const rows = () => Array.from({length:20}, () => ({workerCompiledDigests:Array.from({length:8}, (_, i) => [(i%2 ? 'a' : 'b').repeat(64)]), compilerGenerations:2}));
test('requires competing observed compiler bytes in all twenty rounds', () => {
  assert.equal(verifyCompilerCompetition(rows(), {minCompilerGenerations:2}), 2);
});
test('a claimed aggregate cannot substitute for absent actual observations', () => {
  const batches=rows();batches[7].workerCompiledDigests=Array.from({length:8},()=>[]);
  assert.throws(()=>verifyCompilerCompetition(batches,{minCompilerGenerations:2}),/not observed/);
});
test('one generation in one round refuses the whole measurement', () => {
  const batches=rows();batches[19].workerCompiledDigests=Array.from({length:8},()=>['a'.repeat(64)]);
  assert.throws(()=>verifyCompilerCompetition(batches,{minCompilerGenerations:2}),/not observed/);
});
test('summary and round aggregates must agree with the actual digest sets', () => {
  assert.throws(()=>verifyCompilerCompetition(rows(),{minCompilerGenerations:8}),/summary disagrees/);
  const batches=rows();batches[2].compilerGenerations=8;
  assert.throws(()=>verifyCompilerCompetition(batches,{minCompilerGenerations:2}),/aggregate disagrees/);
});
test('missing worker observations and invalid digests refuse', () => {
  const batches=rows();batches[0].workerCompiledDigests.pop();
  assert.throws(()=>verifyCompilerCompetition(batches,{minCompilerGenerations:2}),/Missing worker/);
  const invalid=rows();invalid[0].workerCompiledDigests[0]=['unverified'];
  assert.throws(()=>verifyCompilerCompetition(invalid,{minCompilerGenerations:2}),/Invalid compiler digest/);
});
