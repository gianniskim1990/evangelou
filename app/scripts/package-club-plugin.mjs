// Builds the REAL staff app and assembles an installable Evangelou Club
// plugin directory at app/dist-plugin/evangelou-club (no deployment).
//
//   1. vite build --config vite.staff.config.ts  (hashed assets + manifest)
//   2. copy ONLY runtime plugin files (allowlist): no tests, vendor,
//      composer files, PHPUnit config, fixtures or mock adapters
//   3. copy assets + manifest into staff-app/ (the PHP shell reads it)
//   4. verify: every manifest file exists, no source maps, no demo/mock
//      data or ordering-app code in the bundle, no test-only PHP classes
//
// Usage: node scripts/package-club-plugin.mjs [--skip-build]
import { execSync } from "node:child_process";
import { cpSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from "node:fs";
import { dirname, join, relative, sep } from "node:path";
import { fileURLToPath } from "node:url";

const appDir = join(dirname(fileURLToPath(import.meta.url)), "..");
const pluginSrc = join(appDir, "..", "wordpress", "evangelou-club");
const distStaff = join(appDir, "dist-staff");
const out = join(appDir, "dist-plugin", "evangelou-club");

const RUNTIME_ALLOWLIST = ["evangelou-club.php", "README.md", "includes", "database"];
const FORBIDDEN_BUNDLE_STRINGS = [
  "Μαρία Παπαδοπούλου", "Γιώργος Νικολάου", "DEMO-QR", "6900000001", "evaggelou-club-redemptions",
  "mockClubService", "createMockClubService", "TEST Espresso", "x-admin-password", "/api/orders", "/api/overrides",
];
const FORBIDDEN_PHP_STRINGS = ["class EVC_Mock_Membership_Adapter", "class EVC_Test_", "class EVC_Faulty_Pdo", "class EVC_Tripwire", "class EVC_Fake_Pmpro_Reader", "class EVC_Pmpro_Fixture", "class EVC_In_Memory_Provenance_Store", "class EVC_Provenance_Fact_Builder"];
const SILENCE = "<?php\n// Silence is golden.\n";

function fail(message) {
  console.error(`PACKAGE FAILED: ${message}`);
  process.exit(1);
}

function walk(dir) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    return statSync(path).isDirectory() ? walk(path) : [path];
  });
}

if (!process.argv.includes("--skip-build")) {
  const { build } = await import("vite");
  await build({ configFile: join(appDir, "vite.staff.config.ts"), logLevel: "warn" });
}
const manifestPath = join(distStaff, ".vite", "manifest.json");
if (!existsSync(manifestPath)) fail("staff manifest missing; build the staff app first");

rmSync(join(appDir, "dist-plugin"), { recursive: true, force: true });
mkdirSync(out, { recursive: true });
for (const entry of RUNTIME_ALLOWLIST) {
  cpSync(join(pluginSrc, entry), join(out, entry), { recursive: true });
}
mkdirSync(join(out, "staff-app", "assets"), { recursive: true });
cpSync(join(distStaff, "assets"), join(out, "staff-app", "assets"), { recursive: true });
cpSync(manifestPath, join(out, "staff-app", "manifest.json"));
writeFileSync(join(out, "staff-app", "index.php"), SILENCE);
writeFileSync(join(out, "staff-app", "assets", "index.php"), SILENCE);

let commit = "unknown";
try {
  commit = execSync("git rev-parse HEAD", { cwd: appDir }).toString().trim();
} catch {
  // not a git checkout
}
writeFileSync(join(out, "staff-app", "build-info.json"), JSON.stringify({ source_commit: commit }, null, 2) + "\n");

// ---- verification ---------------------------------------------------------
const manifest = JSON.parse(readFileSync(join(out, "staff-app", "manifest.json"), "utf8"));
const entry = manifest["staff.html"];
if (!entry || !entry.isEntry || !entry.file) fail("manifest has no staff.html entry");
for (const chunk of Object.values(manifest)) {
  for (const file of [chunk.file, ...(chunk.css ?? []), ...(chunk.assets ?? [])]) {
    if (file && !existsSync(join(out, "staff-app", file))) fail(`manifest references missing file ${file}`);
  }
}

const files = walk(out).map((f) => relative(out, f).split(sep).join("/"));
for (const f of files) {
  if (/(^|\/)(tests|vendor|node_modules)\//.test(f) || /(^|\/)(composer\.(json|lock)|phpunit[^/]*|\.gitignore)$/.test(f)) {
    fail(`dev-only file shipped: ${f}`);
  }
  if (f.endsWith(".map")) fail(`source map shipped: ${f}`);
}
for (const f of files.filter((x) => /\.(js|css)$/.test(x))) {
  const text = readFileSync(join(out, f), "utf8");
  for (const needle of FORBIDDEN_BUNDLE_STRINGS) {
    if (text.includes(needle)) fail(`forbidden demo/ordering content "${needle}" in ${f}`);
  }
}
for (const f of files.filter((x) => x.endsWith(".php"))) {
  const text = readFileSync(join(out, f), "utf8");
  for (const needle of FORBIDDEN_PHP_STRINGS) {
    if (text.includes(needle)) fail(`test-only PHP "${needle}" in ${f}`);
  }
}

console.log(
  `[evidence] club-plugin-package: files=${files.length} entry=${entry.file} css=${(entry.css ?? []).join(",")} commit=${commit.slice(0, 7)} path=${relative(appDir, out)}`,
);
