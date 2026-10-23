import assert from "node:assert/strict";
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, it } from "node:test";
import { pathToFileURL } from "node:url";

/**
 * STAGE-01 — the REAL customer app is its own bundle (vite.customer.config.ts,
 * served only by the WordPress /club-member/ page). Bundles the real entry
 * points with esbuild and inspects the module graph and output.
 */

const appDir = process.env.EVC_APP_DIR as string;

async function bundle(entry: string, previewFlag: "true" | "false" = "false"): Promise<{ inputs: string[]; output: string }> {
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

describe("STAGE-01 customer app: bundle isolation", () => {
  it("neither the ordering app (preview on or off) nor the real staff app reaches the customer app", async () => {
    for (const [entry, flag] of [["src/main.tsx", "true"], ["src/main.tsx", "false"], ["src/staff/main.tsx", "false"]] as const) {
      const { inputs, output } = await bundle(entry, flag);
      assert.equal(inputs.find((p) => p.includes("src/customer/")), undefined, `${entry} (preview ${flag})`);
      for (const marker of ["evc-customer-config", "customer-me-v1", "evangelou-club-customer"]) assert.ok(!output.includes(marker), `${entry} must not contain ${marker}`);
    }
  });

  it("the customer app reuses the approved screens and reaches no simulation, staff, ordering or mock code", async () => {
    const { inputs, output } = await bundle("src/customer/main.tsx");
    for (const approved of ["src/preview/CustomerPreview.tsx", "src/preview/CustomerSignature.tsx", "src/preview/ds.tsx"]) {
      assert.ok(inputs.some((p) => p.endsWith(approved)), `sanity: reuses ${approved}`);
    }
    for (const forbidden of [/src\/customerPreview\//, /src\/club\//, /src\/staff\//, /src\/main\.tsx$/, /src\/App\.tsx$/, /src\/admin\//, /Context\.tsx$/, /src\/preview\/ClubPreviewApp\.tsx$/, /src\/preview\/(Staff|Management)/]) {
      assert.equal(inputs.find((p) => forbidden.test(p)), undefined, `customer app must not include ${forbidden}`);
    }
    for (const api of ["localStorage", "sessionStorage", "indexedDB", "XMLHttpRequest", "sendBeacon", "WebSocket", "getUserMedia"]) {
      assert.ok(!output.includes(api), `customer app must not use ${api}`);
    }
  });

  it("only GET /me is ever requested, and only by the client module", () => {
    const sources = ["src/customer/CustomerApp.tsx", "src/customer/liveView.ts", "src/customer/config.ts", "src/customer/main.tsx"].map((f) => readFileSync(join(appDir, f), "utf8"));
    for (const s of sources) assert.ok(!/\bfetch\(/.test(s), "no direct fetch outside customerApi.ts");
    const client = readFileSync(join(appDir, "src/customer/customerApi.ts"), "utf8");
    assert.match(client, /method: "GET"/);
    assert.doesNotMatch(client, /method: "(POST|PUT|PATCH|DELETE)"/);
    const init = client.slice(client.indexOf("fetchImpl(url, {"), client.indexOf("signal: controller.signal"));
    assert.ok(init.length > 0, "sanity: found the request options");
    assert.doesNotMatch(init, /body/, "the request has no body");
  });

  it("no ordering or Vercel build configuration builds the customer page", () => {
    assert.ok(!readFileSync(join(appDir, "vite.config.ts"), "utf8").includes("customer.html"));
    assert.ok(!readFileSync(join(appDir, "vite.staff.config.ts"), "utf8").includes("customer"));
  });
});
