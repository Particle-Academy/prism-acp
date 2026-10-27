import assert from 'node:assert/strict';

// Recompute the precondition from actual per-worker observations; never trust the controller's aggregate alone.
export function verifyCompilerCompetition(batches, summary) {
  assert.equal(batches.length, 20, 'Incomplete cache rounds');
  const counts = batches.map(batch => {
    assert.equal(batch.workerCompiledDigests?.length, 8, 'Missing worker compiler observations');
    for (const digests of batch.workerCompiledDigests) {
      assert(Array.isArray(digests), 'Invalid compiler observations');
      for (const digest of digests) assert.match(digest, /^[0-9a-f]{64}$/, 'Invalid compiler digest');
    }
    const count = new Set(batch.workerCompiledDigests.flat()).size;
    assert(count >= 2, 'Competing actual compiler generations were not observed in every cache round');
    assert.equal(batch.compilerGenerations, count, 'Compiler aggregate disagrees with actual digests');
    return count;
  });
  const minimum = Math.min(...counts);
  assert.equal(summary.minCompilerGenerations, minimum, 'Compiler summary disagrees with actual digests');
  return minimum;
}
