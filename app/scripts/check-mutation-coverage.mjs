// Final gate of the sharded mutation run (CI): proves the shard reports together
// cover EVERY mutant of the current source exactly once and that all were killed.
// A missing or incomplete report (cancelled job, runner shutdown) is a failure,
// never a pass.
//
// Usage (from app/): node scripts/check-mutation-coverage.mjs --expect-total 232 --shards 2 report-1.json report-2.json
import { spawnSync } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { checkCoverage } from "./mutation-shard.mjs";

const appDir = join(dirname(fileURLToPath(import.meta.url)), "..");
const argv = process.argv.slice(2);
const value = (flag) => (argv.includes(flag) ? argv[argv.indexOf(flag) + 1] : undefined);
const expectTotal = Number(value("--expect-total"));
const shardCount = Number(value("--shards"));
const files = argv.filter((a, i) => !a.startsWith("--") && !["--expect-total", "--shards"].includes(argv[i - 1]));

if (!Number.isInteger(expectTotal) || !Number.isInteger(shardCount) || shardCount < 1) {
  console.error("usage: node scripts/check-mutation-coverage.mjs --expect-total T --shards N report-1.json ... report-N.json");
  process.exit(2);
}

const listing = spawnSync(process.execPath, [join(appDir, "scripts", "run-club-mutations.mjs"), "--list"], { encoding: "utf8" });
if (listing.status !== 0) {
  console.error(`Cannot list the mutants: ${listing.stderr}`);
  process.exit(2);
}
const allIds = listing.stdout.split("\n").filter(Boolean);

const reports = [];
for (let i = 0; i < shardCount; i++) {
  const file = files[i];
  if (!file || !existsSync(file)) {
    reports.push(null);
    continue;
  }
  try {
    reports.push(JSON.parse(readFileSync(file, "utf8")));
  } catch {
    reports.push(null);
  }
}

const errors = checkCoverage(allIds, reports, shardCount, expectTotal);
for (const [i, r] of reports.entries()) {
  console.log(r ? `shard ${i + 1}/${shardCount}: assigned=${r.assigned} completed=${r.completed} killed=${r.killed} (tests=${r.killedByTests} timeout=${r.killedByTimeout}) survived=${r.survivors.length} infrastructure=${r.infrastructure.length} restored=${r.sourcesRestored ? "yes" : "NO"} complete=${r.complete ? "yes" : "NO"}` : `shard ${i + 1}/${shardCount}: NO REPORT`);
}
if (errors.length > 0) {
  for (const e of errors) console.error(`COVERAGE ERROR: ${e}`);
  console.error("[evidence] club-frontend-mutation-coverage: result=FAIL");
  process.exit(1);
}
const killed = reports.reduce((n, r) => n + r.killed, 0);
console.log(`[evidence] club-frontend-mutation-coverage: shards=${shardCount} mutants=${allIds.length} each_run_once=yes killed=${killed} survived=0 infrastructure=0 result=PASS`);
