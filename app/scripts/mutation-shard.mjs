// Pure helpers of the sharded frontend mutation run (scripts/run-club-mutations.mjs
// and scripts/check-mutation-coverage.mjs). No file, process or network access.
//
// Design:
//  - a shard is "K/N": it runs the mutants whose stable index (position in the
//    mutant list) satisfies index % N === K - 1. The N shards are therefore
//    disjoint, together cover every mutant exactly once, and differ in size by
//    at most one; interleaving also spreads the slow (DOM) mutants evenly;
//  - the expected total is passed in by the CI workflow, so removing or
//    disabling a mutant (or adding one without updating the workflow) fails
//    closed instead of silently shrinking coverage;
//  - an outcome is "killed" only if the test suite FAILED (exit 1) or the
//    mutant made it hang until the runner's own time limit; any other exit
//    (a signal, a runner shutdown, an unexpected code) is INFRASTRUCTURE and
//    can never count as a kill.

/** @typedef {{ id: string }} MutantLike */

/**
 * @param {string} text "K/N" with 1 <= K <= N
 * @returns {{ index: number, count: number }} zero-based index
 */
export function parseShard(text) {
  const m = /^(\d+)\/(\d+)$/.exec(String(text));
  if (!m) throw new Error(`Invalid shard "${text}": expected K/N, e.g. 1/2.`);
  const k = Number(m[1]);
  const n = Number(m[2]);
  if (!Number.isInteger(k) || !Number.isInteger(n) || n < 1 || k < 1 || k > n) {
    throw new Error(`Invalid shard "${text}": need 1 <= K <= N.`);
  }
  return { index: k - 1, count: n };
}

/** Throws if any mutant id is empty or repeated. */
export function assertUniqueIds(mutants) {
  const seen = new Set();
  const duplicates = new Set();
  for (const m of mutants) {
    if (typeof m.id !== "string" || m.id.length === 0) throw new Error("A mutant has no id.");
    if (seen.has(m.id)) duplicates.add(m.id);
    seen.add(m.id);
  }
  if (duplicates.size > 0) throw new Error(`Duplicate mutant ids: ${[...duplicates].join(", ")}`);
}

/** Mutants of shard `index` (0-based) out of `count`, in their original order. */
export function shardOf(mutants, index, count) {
  return mutants.filter((_, i) => i % count === index);
}

/**
 * Proves the partition: every mutant lands in exactly one shard, the shards are
 * balanced (sizes differ by at most one) and nothing is missing or doubled.
 * @returns {number[]} the shard sizes
 */
export function verifyPartition(mutants, count) {
  assertUniqueIds(mutants);
  const owners = new Map();
  const sizes = [];
  for (let k = 0; k < count; k++) {
    const part = shardOf(mutants, k, count);
    sizes.push(part.length);
    for (const m of part) {
      if (owners.has(m.id)) throw new Error(`Mutant ${m.id} is in shards ${owners.get(m.id) + 1} and ${k + 1}.`);
      owners.set(m.id, k);
    }
  }
  const missing = mutants.filter((m) => !owners.has(m.id)).map((m) => m.id);
  if (missing.length > 0) throw new Error(`Mutants in no shard: ${missing.join(", ")}`);
  if (owners.size !== mutants.length) throw new Error("Partition does not cover the mutant list.");
  if (Math.max(...sizes) - Math.min(...sizes) > 1) throw new Error(`Unbalanced shards: ${sizes.join(", ")}`);
  return sizes;
}

/**
 * Fail-closed validation before anything runs.
 * @param {MutantLike[]} mutants
 * @param {{ expectTotal?: number, shard?: { index: number, count: number } }} options
 */
export function validatePlan(mutants, options) {
  assertUniqueIds(mutants);
  if (options.shard && options.expectTotal === undefined) {
    throw new Error("A sharded run needs --expect-total (the number of mutants all shards must cover).");
  }
  if (options.expectTotal !== undefined && mutants.length !== options.expectTotal) {
    throw new Error(`Mutant count is ${mutants.length}, expected ${options.expectTotal}: a mutant was removed, disabled or added without updating the workflow.`);
  }
  if (options.shard) {
    verifyPartition(mutants, options.shard.count);
    const assigned = shardOf(mutants, options.shard.index, options.shard.count);
    if (assigned.length === 0) throw new Error("This shard has no mutants.");
    return assigned;
  }
  return mutants.slice();
}

/**
 * Classifies the result of ONE test-suite run against a mutated source.
 * @param {{ status: number | null, signal?: string | null, error?: { code?: string } | null }} run spawnSync-like result
 * @returns {"killed_tests" | "killed_timeout" | "survived" | "infrastructure"}
 */
export function classifyRun(run) {
  if (run.error && run.error.code === "ETIMEDOUT") return "killed_timeout";
  if (run.error) return "infrastructure";
  if (run.status === 1) return "killed_tests";
  if (run.status === 0) return "survived";
  return "infrastructure"; // a signal (null status), a shutdown (143), or any unexpected exit code
}

/**
 * @param {{ shardLabel: string, expectTotal: number | undefined, total: number, assigned: number, results: Record<string, string>, sourcesRestored: boolean, interrupted?: string | null }} input
 */
export function summarize(input) {
  const outcomes = Object.values(input.results);
  const count = (name) => outcomes.filter((o) => o === name).length;
  const completed = outcomes.length;
  const survivors = Object.entries(input.results).filter(([, o]) => o === "survived").map(([id]) => id);
  const infrastructure = Object.entries(input.results).filter(([, o]) => o === "infrastructure").map(([id]) => id);
  const complete = !input.interrupted && completed === input.assigned && infrastructure.length === 0;
  return {
    shard: input.shardLabel,
    expectedTotal: input.expectTotal ?? null,
    total: input.total,
    assigned: input.assigned,
    completed,
    killedByTests: count("killed_tests"),
    killedByTimeout: count("killed_timeout"),
    killed: count("killed_tests") + count("killed_timeout"),
    survivors,
    infrastructure,
    sourcesRestored: input.sourcesRestored,
    interrupted: input.interrupted ?? null,
    complete,
    passed: complete && survivors.length === 0 && input.sourcesRestored,
    results: input.results,
  };
}

/** One-line machine- and human-readable evidence. */
export function evidenceLine(report) {
  return (
    `[evidence] club-frontend-mutation-shard: shard=${report.shard} total=${report.total} expected_total=${report.expectedTotal ?? "n/a"} ` +
    `assigned=${report.assigned} completed=${report.completed} killed=${report.killed} (tests=${report.killedByTests} timeout=${report.killedByTimeout}) ` +
    `survived=${report.survivors.length} infrastructure=${report.infrastructure.length} sources_restored=${report.sourcesRestored ? "yes" : "NO"} ` +
    `complete=${report.complete ? "yes" : "NO"}${report.interrupted ? ` interrupted=${report.interrupted}` : ""} result=${report.passed ? "PASS" : "FAIL"}`
  );
}

/**
 * Cross-checks the shard reports against the full mutant list.
 * @param {string[]} allIds every mutant id of the current source
 * @param {Array<ReturnType<typeof summarize> | null>} reports one per shard, null when missing
 * @param {number} shardCount
 * @param {number} expectTotal
 * @returns {string[]} errors (empty = the shards together covered all mutants exactly once and all were killed)
 */
export function checkCoverage(allIds, reports, shardCount, expectTotal) {
  const errors = [];
  if (allIds.length !== expectTotal) errors.push(`Source has ${allIds.length} mutants, expected ${expectTotal}.`);
  if (new Set(allIds).size !== allIds.length) errors.push("Duplicate mutant ids in the source list.");
  if (reports.length !== shardCount) errors.push(`Expected ${shardCount} shard reports, got ${reports.length}.`);
  const seen = new Map();
  reports.forEach((report, i) => {
    const label = `shard ${i + 1}/${shardCount}`;
    if (!report) {
      errors.push(`${label}: report is missing (the job did not finish).`);
      return;
    }
    if (report.shard !== `${i + 1}/${shardCount}`) errors.push(`${label}: report says shard ${report.shard}.`);
    if (report.expectedTotal !== expectTotal || report.total !== expectTotal) errors.push(`${label}: reports a total of ${report.total}/${report.expectedTotal}, expected ${expectTotal}.`);
    if (!report.complete) errors.push(`${label}: incomplete (${report.completed}/${report.assigned}${report.interrupted ? `, interrupted: ${report.interrupted}` : ""}).`);
    if (report.infrastructure.length > 0) errors.push(`${label}: infrastructure failure on ${report.infrastructure.join(", ")}.`);
    if (report.survivors.length > 0) errors.push(`${label}: surviving mutants ${report.survivors.join(", ")}.`);
    if (!report.sourcesRestored) errors.push(`${label}: sources were not restored.`);
    for (const [id, outcome] of Object.entries(report.results)) {
      if (outcome !== "killed_tests" && outcome !== "killed_timeout") errors.push(`${label}: ${id} is "${outcome}", not killed.`);
      if (seen.has(id)) errors.push(`Mutant ${id} ran in shard ${seen.get(id)} and ${i + 1}.`);
      seen.set(id, i + 1);
    }
  });
  const missing = allIds.filter((id) => !seen.has(id));
  if (missing.length > 0) errors.push(`${missing.length} mutant(s) were not run: ${missing.slice(0, 8).join(", ")}${missing.length > 8 ? ", ..." : ""}.`);
  const unknown = [...seen.keys()].filter((id) => !allIds.includes(id));
  if (unknown.length > 0) errors.push(`Reports contain unknown mutant ids: ${unknown.join(", ")}.`);
  return errors;
}
