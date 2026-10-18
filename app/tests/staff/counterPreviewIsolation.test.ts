import assert from "node:assert/strict";
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, it } from "node:test";
import { pathToFileURL } from "node:url";

/**
 * UI-04O — the integrated counter-flow preview is LOCAL ONLY. Bundles the
 * real shipped entry points with esbuild and inspects the actual module graph
 * and output (not source-string searches), and checks that no production
 * build configuration names the preview page.
 */

const appDir = process.env.EVC_APP_DIR as string;

async function bundle(entry: string): Promise<{ inputs: string[]; output: string }> {
  assert.ok(appDir, "EVC_APP_DIR is set by the test runner");
  const mod = await import(pathToFileURL(join(appDir, "node_modules", "esbuild", "lib", "main.js")).href);
  const esbuild = (mod.default ?? mod) as typeof import("esbuild");
  const outdir = mkdtempSync(join(tmpdir(), "evc-graph-"));
  try {
    const result = await esbuild.build({
      entryPoints: [join(appDir, entry)],
      bundle: true,
      write: false,
      metafile: true,
      outdir,
      format: "esm",
      platform: "browser",
      jsx: "automatic",
      loader: { ".png": "file", ".css": "empty" },
      define: { __EVC_CLUB_PREVIEW__: "true" },
      logLevel: "silent",
    });
    return { inputs: Object.keys(result.metafile.inputs).map((p) => p.replace(/\\/g, "/")), output: result.outputFiles.map((f) => f.text).join("\n") };
  } finally {
    rmSync(outdir, { recursive: true, force: true });
  }
}

const PREVIEW_MARKERS = ["counter-flow-local", "ΤΟΠΙΚΗ ΠΡΟΕΠΙΣΚΟΠΗΣΗ", "evc_5ca1ab1e", "mem_c0ffee", "lkp1.preview", "preview-only"];

describe("UI-04O counter preview: bundle isolation", () => {
  it("neither shipped entry (real staff app, ordering app incl. its design preview) reaches the counter preview", async () => {
    for (const entry of ["src/staff/main.tsx", "src/main.tsx"]) {
      const { inputs, output } = await bundle(entry);
      const hit = inputs.find((p) => p.includes("src/staff/counterPreview/"));
      assert.equal(hit, undefined, `${entry} must not include ${hit}`);
      for (const marker of PREVIEW_MARKERS) assert.ok(!output.includes(marker), `${entry} must not contain "${marker}"`);
    }
  });

  it("the real staff app still reaches no redemption machine, recovery screen or recovery storage", async () => {
    const { inputs } = await bundle("src/staff/main.tsx");
    for (const dir of ["src/staff/redemptionRecovery/", "src/staff/recoveryScreen/", "src/staff/counterPreview/", "src/preview/"]) {
      assert.equal(inputs.find((p) => p.includes(dir)), undefined, `staff bundle must not include ${dir}`);
    }
  });

  it("the preview's own graph never reaches a real transport, the real staff entry or the v1 REST client", async () => {
    const { inputs } = await bundle("src/staff/counterPreview/main.tsx");
    assert.ok(inputs.some((p) => p.endsWith("src/staff/redemptionRecovery/machine.ts")), "sanity: the REAL machine is used");
    assert.ok(inputs.some((p) => p.endsWith("src/staff/staffController.ts")), "sanity: the REAL controller is used");
    for (const forbidden of [/src\/staff\/main\.tsx$/, /src\/staff\/StaffApp\.tsx$/, /src\/club\/restClubService\.ts$/, /src\/club\/clubService\.ts$/, /src\/main\.tsx$/, /src\/App\.tsx$/]) {
      assert.equal(inputs.find((p) => forbidden.test(p)), undefined, `preview must not include ${forbidden}`);
    }
  });

  it("no production build names the preview page; the page refuses to run outside the dev server", () => {
    for (const config of ["vite.config.ts", "vite.staff.config.ts"]) {
      assert.ok(!readFileSync(join(appDir, config), "utf8").includes("counter-preview"), `${config} does not build the preview`);
    }
    const main = readFileSync(join(appDir, "src", "staff", "counterPreview", "main.tsx"), "utf8");
    assert.match(main, /if \(!import\.meta\.env\.DEV\)/);
  });
});
