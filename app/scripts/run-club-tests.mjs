// Runs the Evangelou Club frontend tests (app/tests/club/*.test.ts).
// No extra dependencies: esbuild (already a devDependency) bundles each test
// file for Node, then Node's built-in test runner executes them.
// Tests run under a deliberately hostile TZ (UTC+14) so nothing silently
// depends on the device timezone.
import { build } from "esbuild";
import { spawnSync } from "node:child_process";
import { mkdtempSync, readdirSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const appDir = join(dirname(fileURLToPath(import.meta.url)), "..");
const testDir = join(appDir, "tests", "club");
const entries = readdirSync(testDir)
  .filter((f) => f.endsWith(".test.ts"))
  .map((f) => join(testDir, f));

if (entries.length === 0) {
  console.error("No Club test files found.");
  process.exit(1);
}

const outDir = mkdtempSync(join(tmpdir(), "evc-club-tests-"));
let status = 1;
try {
  await build({
    entryPoints: entries,
    bundle: true,
    platform: "node",
    format: "esm",
    target: "node20",
    outdir: outDir,
    outExtension: { ".js": ".mjs" },
    sourcemap: "inline",
    logLevel: "warning",
  });
  const files = readdirSync(outDir)
    .filter((f) => f.endsWith(".mjs"))
    .map((f) => join(outDir, f));
  const result = spawnSync(process.execPath, ["--enable-source-maps", "--test", "--test-reporter=spec", ...files], {
    stdio: process.env.EVC_QUIET_TESTS === "1" ? "ignore" : "inherit",
    env: { ...process.env, TZ: process.env.EVC_TEST_TZ ?? "Pacific/Kiritimati" },
  });
  status = result.status ?? 1;
} finally {
  rmSync(outDir, { recursive: true, force: true });
}
process.exit(status);
