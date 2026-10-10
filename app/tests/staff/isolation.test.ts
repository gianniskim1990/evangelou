import assert from "node:assert/strict";
import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, it } from "node:test";
import { pathToFileURL } from "node:url";

/**
 * Bundles the REAL entry points with esbuild and inspects the actual module
 * graph (metafile inputs) and output — not source-string searches.
 */

const appDir = process.env.EVC_APP_DIR;

type Built = { inputs: string[]; output: string };

async function bundle(entry: string): Promise<Built> {
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
      logLevel: "silent",
    });
    return {
      inputs: Object.keys(result.metafile.inputs).map((p) => p.replace(/\\/g, "/")),
      output: result.outputFiles.map((f) => f.text).join("\n"),
    };
  } finally {
    rmSync(outdir, { recursive: true, force: true });
  }
}

const FORBIDDEN_STAFF_MODULES = [
  /src\/club\/clubService\.ts$/,
  /src\/club\/mockMembers\.ts$/,
  /src\/club\/mockRedemptionStore\.ts$/,
  /src\/club\/mockCoffeeCatalog\.ts$/,
  /src\/club\/ClubApp\.tsx$/,
  /src\/club\/screens\//,
  /src\/App\.tsx$/,
  /src\/admin\//,
  /src\/(AppContext|MenuContext|SettingsContext)\.tsx$/,
  /src\/main\.tsx$/,
];

const DEMO_STRINGS = ["Μαρία Παπαδοπούλου", "DEMO-QR", "6900000001", "evaggelou-club-redemptions", "TEST Espresso", "x-admin-password"];

describe("staff bundle isolation (real import graph)", () => {
  it("the /club-admin/ entry never reaches demo, mock or ordering modules", async () => {
    const { inputs, output } = await bundle("src/staff/main.tsx");
    assert.ok(inputs.some((p) => p.endsWith("src/staff/staffApi.ts")), "sanity: graph contains the staff API");
    for (const pattern of FORBIDDEN_STAFF_MODULES) {
      const hit = inputs.find((p) => pattern.test(p));
      assert.equal(hit, undefined, `staff bundle must not include ${hit}`);
    }
    for (const needle of DEMO_STRINGS) {
      assert.ok(!output.includes(needle), `staff bundle must not contain demo data "${needle}"`);
    }
  });

  it("the public /club demo still runs on the mock service", async () => {
    const { inputs } = await bundle("src/club/ClubApp.tsx");
    assert.ok(inputs.some((p) => /src\/club\/clubService\.ts$/.test(p)));
    assert.ok(inputs.some((p) => /src\/club\/mockMembers\.ts$/.test(p)));
    const mod = await import("../../src/club/clubService");
    assert.equal(mod.clubService, mod.mockClubService);
  });
});
