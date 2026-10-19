import assert from "node:assert/strict";
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, it } from "node:test";
import { pathToFileURL } from "node:url";

/**
 * CUSTOMER-01 — the interactive customer journey is LOCAL ONLY. Bundles the
 * real shipped entry points with esbuild and inspects the actual module graph
 * and output (not source-string searches), checks that the journey's own
 * graph reaches no service, transport, staff or ordering code, and that no
 * production build configuration names the page.
 */

const appDir = process.env.EVC_APP_DIR as string;

async function bundle(entry: string, previewFlag: "true" | "false" = "true"): Promise<{ inputs: string[]; output: string }> {
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
      loader: { ".png": "file", ".css": "empty", ".webp": "file" },
      define: { __EVC_CLUB_PREVIEW__: previewFlag },
      logLevel: "silent",
    });
    return { inputs: Object.keys(result.metafile.inputs).map((p) => p.replace(/\\/g, "/")), output: result.outputFiles.map((f) => f.text).join("\n") };
  } finally {
    rmSync(outdir, { recursive: true, force: true });
  }
}

const JOURNEY_MARKERS = ["customer-journey-local", "evc-cj", "Το κατάστημα επιβεβαιώνει την πληρωμή", "Η πληρωμή δεν έγινε", "SCENARIO_START"];

describe("CUSTOMER-01 customer journey: bundle isolation", () => {
  it("neither shipped entry (ordering app incl. its design preview, real staff app) reaches the customer journey", async () => {
    for (const [entry, flag] of [["src/main.tsx", "true"], ["src/main.tsx", "false"], ["src/staff/main.tsx", "false"]] as const) {
      const { inputs, output } = await bundle(entry, flag);
      const hit = inputs.find((p) => p.includes("src/customerPreview/"));
      assert.equal(hit, undefined, `${entry} (preview ${flag}) must not include ${hit}`);
      for (const marker of JOURNEY_MARKERS) assert.ok(!output.includes(marker), `${entry} (preview ${flag}) must not contain "${marker}"`);
    }
  });

  it("the production ordering build (preview off) contains no customer preview code at all", async () => {
    const { inputs } = await bundle("src/main.tsx", "false");
    for (const dir of ["src/customerPreview/", "src/preview/CustomerPreview", "src/preview/customerView", "src/preview/onboardingCopy"]) {
      assert.equal(inputs.find((p) => p.includes(dir)), undefined, `production ordering build must not include ${dir}`);
    }
  });

  it("the journey's own graph reuses the approved screens and reaches no service, transport, staff or ordering code", async () => {
    const { inputs, output } = await bundle("src/customerPreview/main.tsx");
    for (const approved of ["src/preview/CustomerPreview.tsx", "src/preview/CustomerSignature.tsx", "src/preview/ds.tsx"]) {
      assert.ok(inputs.some((p) => p.endsWith(approved)), `sanity: reuses the approved ${approved}`);
    }
    for (const forbidden of [/src\/club\//, /src\/staff\//, /src\/main\.tsx$/, /src\/App\.tsx$/, /src\/admin\//, /Context\.tsx$/, /src\/preview\/ClubPreviewApp\.tsx$/]) {
      assert.equal(inputs.find((p) => forbidden.test(p)), undefined, `journey must not include ${forbidden}`);
    }
    for (const api of ["fetch(", "XMLHttpRequest", "sendBeacon", "WebSocket", "localStorage", "sessionStorage", "indexedDB", "getUserMedia", "wp-json"]) {
      assert.ok(!output.includes(api), `journey bundle must not use ${api}`);
    }
  });

  it("no production build names the page; the page refuses to run outside the dev server", () => {
    for (const config of ["vite.config.ts", "vite.staff.config.ts"]) {
      assert.ok(!readFileSync(join(appDir, config), "utf8").includes("customer-preview"), `${config} does not build the preview`);
    }
    const main = readFileSync(join(appDir, "src", "customerPreview", "main.tsx"), "utf8");
    assert.match(main, /if \(!import\.meta\.env\.DEV\)/);
  });
});
