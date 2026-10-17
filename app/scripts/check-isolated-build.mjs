// Isolated-module build regression check (UI-04M follow-up).
//
// Question answered: "do the files of this change that are NOT part of the
// shipped import graph (tests, docs, scripts, unwired modules) alter what the
// production build ships?"  They must not: a pure, unwired module may never
// change the staff or ordering bundles.
//
// Why it exists: Tailwind v4 scans EVERY non-ignored file under app/ for
// class-name candidates, including docs, scripts and tests. A bare utility
// word anywhere (e.g. in a markdown sentence) adds that utility to the shared
// CSS, which changes the CSS hash and, through the asset reference, the JS
// hash of both the staff and the ordering apps, while the application logic is
// byte-identical.
//
// How: with the SAME dependencies and commands it builds two clean git
// worktrees:
//   A = HEAD
//   B = HEAD with every changed non-shipped file restored to its state at
//       --base (deleted again if it was added)
// and compares every produced file (name + sha256) of dist/ and dist-staff/.
// Files that ARE in the shipped graph, and CSS/HTML/config, stay as they are
// in both trees, so legitimate UI changes never fail this check, and no
// absolute hash is pinned anywhere.
//
// Usage (from app/):
//   node scripts/check-isolated-build.mjs --base <git-ref>
//   node scripts/check-isolated-build.mjs --canary     # proves the detector can fail
// Exit: 0 = unchanged output, 1 = output changed (or canary undetected), 2 = usage/error.
import { spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, statSync, symlinkSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, relative, sep } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { compare, removeTempTree, selectors } from "./isolated-build-compare.mjs";

const appDir = join(dirname(fileURLToPath(import.meta.url)), "..");
const args = process.argv.slice(2);
const argValue = (flag) => (args.includes(flag) ? args[args.indexOf(flag) + 1] : undefined);
const baseRef = argValue("--base");
const canary = args.includes("--canary");
const quiet = args.includes("--quiet");

if (!canary && !baseRef) {
  console.error("usage: node scripts/check-isolated-build.mjs --base <git-ref> | --canary");
  process.exit(2);
}

const log = (...m) => {
  if (!quiet) console.log(...m);
};

function git(cwd, ...gitArgs) {
  const r = spawnSync("git", gitArgs, { cwd, encoding: "buffer", maxBuffer: 256 * 1024 * 1024 });
  return { ok: r.status === 0, out: r.stdout ?? Buffer.alloc(0), err: (r.stderr ?? Buffer.alloc(0)).toString() };
}

const top = git(appDir, "rev-parse", "--show-toplevel");
if (!top.ok) {
  console.error("not inside a git checkout");
  process.exit(2);
}
const repoRoot = top.out.toString().trim();
const appRel = relative(repoRoot, appDir).split(sep).join("/");
const head = git(repoRoot, "rev-parse", "HEAD").out.toString().trim();

const work = mkdtempSync(join(tmpdir(), "evc-isolated-build-"));
const trees = [];

// Identical dependencies for every tree: ONE link to the real node_modules,
// placed in the temp directory ABOVE the worktrees (module resolution walks up
// the directory tree). It must never live inside a worktree: removing a
// worktree must not be able to follow the link into the real node_modules
// (an earlier draft did exactly that and emptied it).
const modulesLink = join(work, "node_modules");
symlinkSync(join(appDir, "node_modules"), modulesLink, "junction");

function makeTree(label) {
  const dir = join(work, label);
  const r = git(repoRoot, "worktree", "add", "--detach", "--quiet", dir, head);
  if (!r.ok) throw new Error(`git worktree add failed: ${r.err}`);
  trees.push(dir);
  return dir;
}

function cleanup() {
  for (const dir of trees) git(repoRoot, "worktree", "remove", "--force", dir);
  git(repoRoot, "worktree", "prune");
  // Drops the link itself first (never its target); if that cannot be proven the
  // temp directory is left behind rather than risk the real node_modules.
  if (!removeTempTree(work, modulesLink)) {
    console.error(`WARNING: could not prove the dependency link was removed; left ${work} in place.`);
  }
}

function viteBuild(tree, config) {
  const cwd = join(tree, appRel);
  const argv = [join(modulesLink, "vite", "bin", "vite.js"), "build", ...(config ? ["--config", config] : [])];
  const r = spawnSync(process.execPath, argv, { cwd, encoding: "utf8", env: { ...process.env, CI: "1" } });
  if (r.status !== 0) throw new Error(`vite build failed in ${tree} (${config ?? "ordering"}):\n${r.stdout}\n${r.stderr}`);
}

function listFiles(dir) {
  const out = [];
  if (!existsSync(dir)) return out;
  for (const name of readdirSync(dir).sort()) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) out.push(...listFiles(p));
    else out.push(p);
  }
  return out;
}

/** name -> sha256 for every file the build produced. */
function outputOf(tree, parts) {
  const cwd = join(tree, appRel);
  const map = new Map();
  for (const part of parts) {
    for (const file of listFiles(join(cwd, part))) {
      map.set(`${part}/${relative(join(cwd, part), file).split(sep).join("/")}`, createHash("sha256").update(readFileSync(file)).digest("hex"));
    }
  }
  return map;
}

const STAFF = { config: "vite.staff.config.ts", dir: "dist-staff" };
const ORDERING = { config: null, dir: "dist" };

function buildAll(tree, apps) {
  for (const app of apps) viteBuild(tree, app.config);
  return outputOf(tree, apps.map((a) => a.dir));
}

function cssOf(tree, part, outputMap) {
  const css = [...outputMap.keys()].filter((k) => k.startsWith(`${part}/assets/`) && k.endsWith(".css"));
  // The shared stylesheet is the entry CSS (index-*.css / staff-*.css), not a lazy chunk's.
  const name = css.find((k) => /\/(index|staff)-[^/]*\.css$/.test(k)) ?? css[0];
  return name ? readFileSync(join(tree, appRel, name), "utf8") : "";
}

/** Repo-relative paths of every module in the shipped import graph (staff + ordering entries). */
async function shippedGraph(tree) {
  const cwd = join(tree, appRel);
  const mod = await import(pathToFileURL(join(appDir, "node_modules", "esbuild", "lib", "main.js")).href);
  const esbuild = mod.default ?? mod;
  const shipped = new Set();
  for (const entry of ["src/main.tsx", "src/staff/main.tsx"]) {
    const result = await esbuild.build({
      entryPoints: [join(cwd, entry)],
      bundle: true,
      write: false,
      metafile: true,
      outdir: join(work, "esbuild-out"),
      format: "esm",
      platform: "browser",
      jsx: "automatic",
      loader: { ".png": "file", ".css": "empty", ".svg": "file", ".webp": "file", ".jpg": "file" },
      logLevel: "silent",
      splitting: true,
    });
    for (const input of Object.keys(result.metafile.inputs)) {
      shipped.add(relative(repoRoot, join(cwd, input)).split(sep).join("/"));
    }
  }
  return shipped;
}

/** Changed files that cannot legitimately influence the shipped build. */
function nonShippedChanges(shipped) {
  const diff = git(repoRoot, "diff", "--name-status", "--no-renames", `${baseRef}...${head}`);
  if (!diff.ok) throw new Error(`git diff failed for base "${baseRef}": ${diff.err}`);
  const files = [];
  for (const line of diff.out.toString().split("\n").filter(Boolean)) {
    const [, path] = line.split("\t");
    if (!path.startsWith(`${appRel}/`)) continue; // outside app/: never scanned or built
    const inApp = path.slice(appRel.length + 1);
    const isSource = /^src\/.*\.(ts|tsx)$/.test(inApp);
    // Root HTML other than the two build entries (e.g. the local-only recovery preview page) is scanned by Tailwind but never built.
    const strayHtml = /^[^/]+\.html$/.test(inApp) && inApp !== "index.html" && inApp !== "staff.html";
    const nonShipped = /^(tests|docs|scripts)\//.test(inApp) || strayHtml || (isSource && !shipped.has(path));
    if (nonShipped) files.push(path);
  }
  return files;
}

function revert(tree, path) {
  const target = join(tree, path);
  const atBase = git(repoRoot, "show", `${baseRef}:${path}`);
  if (atBase.ok) {
    mkdirSync(dirname(target), { recursive: true });
    writeFileSync(target, atBase.out);
  } else {
    rmSync(target, { force: true });
  }
}

function report(title, a, b, treeA, treeB) {
  console.log(`\n${title}`);
  const cmp = compare(a, b);
  for (const name of cmp.changed) console.log(`  same name, DIFFERENT content:         ${name}`);
  for (const name of cmp.onlyB) console.log(`  only without the non-shipped changes: ${name}`);
  for (const name of cmp.onlyA) console.log(`  only with the non-shipped changes:    ${name}`);
  for (const part of ["dist-staff", "dist"]) {
    const ca = selectors(cssOf(treeA, part, a));
    const cb = selectors(cssOf(treeB, part, b));
    const added = [...ca].filter((s) => !cb.has(s));
    const removed = [...cb].filter((s) => !ca.has(s));
    if (added.length || removed.length) console.log(`  ${part} CSS selectors added: ${added.join(" ") || "-"} | removed: ${removed.join(" ") || "-"}`);
  }
}

async function main() {
  const treeA = makeTree("A");

  if (canary) {
    // Prove the detector can fail: add a docs-only file containing a bare utility word.
    const treeB = makeTree("B");
    const baseline = buildAll(treeB, [STAFF]);
    const css = cssOf(treeB, STAFF.dir, baseline);
    const candidates = [["out", "line"], ["over", "line"], ["line", "-", "through"], ["upper", "case"]].map((p) => p.join(""));
    const word = candidates.find((c) => !css.includes(`.${c}{`));
    if (!word) throw new Error("no canary candidate is absent from the baseline CSS");
    writeFileSync(join(treeA, appRel, "docs", "__isolation-canary.md"), `canary ${word}\n`);
    const withCanary = buildAll(treeA, [STAFF]);
    const cmp = compare(withCanary, baseline);
    if (cmp.same) {
      console.error(`CANARY NOT DETECTED: a docs-only file containing a bare utility word did not change the staff build; the check is blind.`);
      return 1;
    }
    console.log(`canary detected: a docs-only file changed the staff output (${cmp.onlyA.length + cmp.onlyB.length} added/removed, ${cmp.changed.length} changed-content file(s)).`);
    return 0;
  }

  const shipped = await shippedGraph(treeA);
  const isolated = nonShippedChanges(shipped);
  log(`base ${baseRef} (${git(repoRoot, "rev-parse", "--short", baseRef).out.toString().trim()}) -> HEAD ${head.slice(0, 7)}`);
  log(`shipped import graph: ${shipped.size} modules; non-shipped changed files: ${isolated.length}`);
  for (const f of isolated) log(`  ${f}`);
  if (isolated.length === 0) {
    log("nothing to check: no non-shipped file changed");
    return 0;
  }

  const outA = buildAll(treeA, [STAFF, ORDERING]);
  const treeB = makeTree("B");
  for (const f of isolated) revert(treeB, f);
  const outB = buildAll(treeB, [STAFF, ORDERING]);

  const cmp = compare(outA, outB);
  log(`staff    : ${[...outA.keys()].filter((k) => k.startsWith("dist-staff/assets/") && /\.(js|css)$/.test(k)).join(", ")}`);
  log(`ordering : ${[...outA.keys()].filter((k) => k.startsWith("dist/assets/index-")).join(", ")}`);
  if (cmp.same) {
    console.log("OK: the non-shipped changes leave the staff and ordering build output byte-identical.");
    return 0;
  }

  report("FAIL: non-shipped files change the shipped build output.", outA, outB, treeA, treeB);
  // Leave-one-out: which single file explains the whole difference (staff build only, cheaper)?
  const culprits = [];
  const baselineStaff = new Map([...outB].filter(([k]) => k.startsWith("dist-staff/")));
  for (const f of isolated) {
    const treeC = makeTree(`C${culprits.length}-${trees.length}`);
    for (const other of isolated) if (other !== f) revert(treeC, other);
    // treeC = baseline state for every non-shipped file except `f` (kept at HEAD)
    const staffOnly = buildAll(treeC, [STAFF]);
    if (!compare(staffOnly, baselineStaff).same) culprits.push(f);
  }
  console.log(`\nculprit file(s) (each alone reproduces the change): ${culprits.length ? culprits.join(", ") : "no single file; a combination"}`);
  console.log("Tailwind scans every file under app/ for class-name candidates: avoid bare utility words in docs, tests and scripts.");
  return 1;
}

let exit = 2;
try {
  exit = await main();
} catch (err) {
  console.error(err instanceof Error ? err.message : err);
  exit = 2;
} finally {
  cleanup();
}
process.exit(exit);
