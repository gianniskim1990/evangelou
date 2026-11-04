import assert from "node:assert/strict";
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, it } from "node:test";
import { pathToFileURL } from "node:url";
import { STORAGE_ALLOWED_FILE, storageProblems, storageSelfTest } from "../../scripts/storage-guard.mjs";

/*
 * STAGE-05D round 2 — owner decision «Ναι, να τον θυμάται»: browser storage in the customer app ONLY in
 * src/customer/qrStore.ts (localStorage), checked on the bundle's REAL import graph (as the packager does).
 */

const appDir = process.env.EVC_APP_DIR as string;

describe("customer storage guard (scripts/storage-guard.mjs)", () => {
  it("its negative fixtures all hold", () => {
    assert.deepEqual(storageSelfTest(), []);
  });

  it("the real customer graph: only qrStore.ts names a store, and only localStorage", async () => {
    const mod = await import(pathToFileURL(join(appDir, "node_modules", "esbuild", "lib", "main.js")).href);
    const esbuild = (mod.default ?? mod) as typeof import("esbuild");
    const outdir = mkdtempSync(join(tmpdir(), "evc-storage-"));
    try {
      const r = await esbuild.build({ entryPoints: [join(appDir, "src/customer/main.tsx")], absWorkingDir: appDir, bundle: true, write: false, metafile: true, outdir, format: "esm", platform: "browser", jsx: "automatic", loader: { ".png": "file", ".css": "empty", ".webp": "file" }, logLevel: "silent" });
      const inputs = Object.keys(r.metafile.inputs).filter((p) => /\.(m?[jt]sx?|cjs)$/.test(p));
      assert.ok(inputs.includes(STORAGE_ALLOWED_FILE), "sanity: the store is in the graph");
      const problems = inputs.flatMap((p) => storageProblems(p, readFileSync(join(appDir, p), "utf8")));
      assert.deepEqual(problems, []);
      const users = inputs.filter((p) => /localStorage/.test(readFileSync(join(appDir, p), "utf8").replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/.*$/gm, "")));
      assert.deepEqual(users, [STORAGE_ALLOWED_FILE], "exactly one module uses it");
    } finally {
      rmSync(outdir, { recursive: true, force: true });
    }
  });
});
