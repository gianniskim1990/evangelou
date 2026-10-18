import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { assertUniqueIds, checkCoverage, classifyRun, evidenceLine, parseShard, shardOf, summarize, validatePlan, verifyPartition } from "../../scripts/mutation-shard.mjs";

/*
 * The sharded mutation run must cover every mutant exactly once, fail closed on
 * any count/id problem, and never count an infrastructure failure as a kill.
 * Pure data only: no suite is run here.
 */

const ids = (n: number) => Array.from({ length: n }, (_, i) => ({ id: `M${i + 1}` }));

describe("mutation sharding: deterministic partition", () => {
  it("232 mutants split into two shards of exactly 116, disjoint and complete", () => {
    const all = ids(232);
    assert.deepEqual(verifyPartition(all, 2), [116, 116]);
    const a = shardOf(all, 0, 2).map((m) => m.id);
    const b = shardOf(all, 1, 2).map((m) => m.id);
    assert.equal(a.length, 116);
    assert.equal(b.length, 116);
    assert.equal(new Set([...a, ...b]).size, 232);
    assert.deepEqual([...a, ...b].sort(), all.map((m) => m.id).sort());
    assert.equal(a.filter((x) => b.includes(x)).length, 0);
  });

  it("is stable: the same input always gives the same shards, in the original order", () => {
    const all = ids(232);
    assert.deepEqual(shardOf(all, 0, 2), shardOf(all, 0, 2));
    assert.deepEqual(shardOf(all, 0, 2).map((m) => m.id).slice(0, 3), ["M1", "M3", "M5"]);
    assert.deepEqual(shardOf(all, 1, 2).map((m) => m.id).slice(0, 3), ["M2", "M4", "M6"]);
  });

  it("odd sizes stay balanced to within one", () => {
    assert.deepEqual(verifyPartition(ids(233), 2), [117, 116]);
    assert.deepEqual(verifyPartition(ids(7), 3), [3, 2, 2]);
  });

  it("parseShard accepts K/N only", () => {
    assert.deepEqual(parseShard("1/2"), { index: 0, count: 2 });
    assert.deepEqual(parseShard("2/2"), { index: 1, count: 2 });
    for (const bad of ["0/2", "3/2", "1/0", "1", "a/b", "1/2/3", "-1/2", "", "1.5/2"]) assert.throws(() => parseShard(bad), /Invalid shard/, bad);
  });
});

describe("mutation sharding: fail closed before anything runs", () => {
  it("duplicate or empty ids are rejected", () => {
    assert.throws(() => assertUniqueIds([{ id: "A" }, { id: "B" }, { id: "A" }]), /Duplicate mutant ids: A/);
    assert.throws(() => assertUniqueIds([{ id: "" }]), /no id/);
    assert.throws(() => verifyPartition([{ id: "A" }, { id: "A" }], 2), /Duplicate/);
  });

  it("a sharded run without an expected total is refused", () => {
    assert.throws(() => validatePlan(ids(10), { shard: parseShard("1/2") }), /--expect-total/);
  });

  it("an unexpected mutant count (removed, disabled or added) is refused", () => {
    assert.throws(() => validatePlan(ids(231), { expectTotal: 232, shard: parseShard("1/2") }), /Mutant count is 231, expected 232/);
    assert.throws(() => validatePlan(ids(233), { expectTotal: 232, shard: parseShard("2/2") }), /Mutant count is 233, expected 232/);
    assert.equal(validatePlan(ids(232), { expectTotal: 232, shard: parseShard("2/2") }).length, 116);
  });

  it("a shard with nothing to do is refused", () => {
    assert.throws(() => validatePlan(ids(2), { expectTotal: 2, shard: parseShard("3/3") }), /Unbalanced|no mutants/);
  });
});

describe("mutation sharding: kills versus infrastructure", () => {
  it("only a failing suite (exit 1) or the runner's own time limit is a kill", () => {
    assert.equal(classifyRun({ status: 1 }), "killed_tests");
    assert.equal(classifyRun({ status: null, signal: "SIGTERM", error: { code: "ETIMEDOUT" } }), "killed_timeout");
    assert.equal(classifyRun({ status: 0 }), "survived");
  });

  it("signals, runner shutdown and unexpected exit codes are infrastructure, never a kill", () => {
    assert.equal(classifyRun({ status: null, signal: "SIGTERM" }), "infrastructure");
    assert.equal(classifyRun({ status: 143 }), "infrastructure");
    assert.equal(classifyRun({ status: 137 }), "infrastructure");
    assert.equal(classifyRun({ status: 2 }), "infrastructure");
    assert.equal(classifyRun({ status: null, signal: "SIGKILL" }), "infrastructure");
    assert.equal(classifyRun({ status: null, error: { code: "ENOENT" } }), "infrastructure");
  });

  it("an interrupted or infrastructure-affected shard is incomplete and cannot pass", () => {
    const base = { shardLabel: "1/2", expectTotal: 232, total: 232, assigned: 3, sourcesRestored: true };
    const ok = summarize({ ...base, results: { a: "killed_tests", b: "killed_timeout", c: "killed_tests" } });
    assert.equal(ok.passed, true);
    assert.equal(ok.complete, true);
    assert.equal(ok.killedByTests, 2);
    assert.equal(ok.killedByTimeout, 1);
    const cut = summarize({ ...base, results: { a: "killed_tests" }, interrupted: "SIGTERM" });
    assert.equal(cut.complete, false);
    assert.equal(cut.passed, false);
    const partial = summarize({ ...base, results: { a: "killed_tests", b: "killed_tests" } });
    assert.equal(partial.complete, false, "fewer completed than assigned");
    assert.equal(partial.passed, false);
    const infra = summarize({ ...base, results: { a: "killed_tests", b: "infrastructure", c: "killed_tests" } });
    assert.equal(infra.complete, false);
    assert.equal(infra.killed, 2, "an infrastructure result is not counted as killed");
    assert.equal(infra.passed, false);
    const survived = summarize({ ...base, results: { a: "killed_tests", b: "survived", c: "killed_tests" } });
    assert.equal(survived.complete, true);
    assert.deepEqual(survived.survivors, ["b"]);
    assert.equal(survived.passed, false);
    const dirty = summarize({ ...base, results: { a: "killed_tests", b: "killed_tests", c: "killed_tests" }, sourcesRestored: false });
    assert.equal(dirty.passed, false);
  });

  it("the evidence line states expected count, killed, survivors, restoration and completeness", () => {
    const line = evidenceLine(summarize({ shardLabel: "2/2", expectTotal: 232, total: 232, assigned: 2, results: { a: "killed_tests", b: "killed_timeout" }, sourcesRestored: true }));
    assert.match(line, /shard=2\/2 total=232 expected_total=232 assigned=2 completed=2 killed=2 \(tests=1 timeout=1\) survived=0 infrastructure=0 sources_restored=yes complete=yes result=PASS/);
    const failed = evidenceLine(summarize({ shardLabel: "1/2", expectTotal: 232, total: 232, assigned: 2, results: { a: "killed_tests" }, sourcesRestored: true, interrupted: "SIGTERM" }));
    assert.match(failed, /complete=NO interrupted=SIGTERM result=FAIL/);
  });
});

describe("mutation sharding: the coverage gate", () => {
  const all = ids(8).map((m) => m.id);
  const report = (label: string, mine: string[], over: Record<string, unknown> = {}) =>
    ({ ...summarize({ shardLabel: label, expectTotal: 8, total: 8, assigned: mine.length, results: Object.fromEntries(mine.map((id) => [id, "killed_tests"])), sourcesRestored: true }), ...over }) as ReturnType<typeof summarize>;
  const good = () => [report("1/2", ["M1", "M3", "M5", "M7"]), report("2/2", ["M2", "M4", "M6", "M8"])];

  it("passes only when the shards together cover every mutant exactly once", () => {
    assert.deepEqual(checkCoverage(all, good(), 2, 8), []);
  });

  it("a missing report (cancelled or shut-down job) fails", () => {
    const errors = checkCoverage(all, [good()[0], null], 2, 8);
    assert.ok(errors.some((e) => /shard 2\/2: report is missing/.test(e)));
    assert.ok(errors.some((e) => /not run/.test(e)));
  });

  it("an incomplete shard, a missing mutant or a duplicated mutant fails", () => {
    const [a, b] = good();
    assert.ok(checkCoverage(all, [a, report("2/2", ["M2", "M4", "M6"])], 2, 8).some((e) => /M8|not run/.test(e)));
    assert.ok(checkCoverage(all, [a, { ...b, complete: false, completed: 3 }], 2, 8).some((e) => /incomplete/.test(e)));
    assert.ok(checkCoverage(all, [a, report("2/2", ["M2", "M4", "M6", "M7"])], 2, 8).some((e) => /ran in shard 1 and 2/.test(e)));
  });

  it("a survivor, an infrastructure result or unrestored sources fail", () => {
    const [a, b] = good();
    assert.ok(checkCoverage(all, [a, { ...b, survivors: ["M2"], results: { ...b.results, M2: "survived" } }], 2, 8).some((e) => /surviving/.test(e)));
    assert.ok(checkCoverage(all, [a, { ...b, infrastructure: ["M4"], results: { ...b.results, M4: "infrastructure" } }], 2, 8).some((e) => /infrastructure/.test(e)));
    assert.ok(checkCoverage(all, [a, { ...b, sourcesRestored: false }], 2, 8).some((e) => /not restored/.test(e)));
  });

  it("a wrong shard label, total or mutant list size fails", () => {
    const [a, b] = good();
    assert.ok(checkCoverage(all, [b, a], 2, 8).some((e) => /says shard/.test(e)));
    assert.ok(checkCoverage(all, [{ ...a, total: 7 }, b], 2, 8).some((e) => /expected 8/.test(e)));
    assert.ok(checkCoverage(all.slice(1), good(), 2, 8).some((e) => /expected 8|unknown/.test(e)));
    assert.ok(checkCoverage([...all, "M1"], good(), 2, 8).some((e) => /Duplicate/.test(e)));
  });
});
