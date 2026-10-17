import assert from "node:assert/strict";
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
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

  it("the UI-03D.D recovery state machine is not wired into any shipped entry", async () => {
    for (const entry of ["src/staff/main.tsx", "src/main.tsx"]) {
      const { inputs, output } = await bundle(entry);
      const hit = inputs.find((p) => p.includes("src/staff/redemptionRecovery/"));
      assert.equal(hit, undefined, `${entry} must not include ${hit}`);
      assert.ok(!output.includes("previously_recorded_unconfirmed"), `${entry} must not contain the recovery machine`);
    }
    const own = await bundle("src/staff/redemptionRecovery/machine.ts");
    const local = own.inputs.filter((p) => !p.includes("node_modules")).map((p) => p.slice(p.lastIndexOf("src/"))).sort();
    assert.deepEqual(local, ["src/club/format.ts", "src/staff/redemptionRecovery/machine.ts", "src/staff/redemptionRecovery/wire.ts"]);
    for (const needle of ["fetch(", "sessionStorage", "localStorage", "XMLHttpRequest"]) {
      assert.ok(!own.output.includes(needle), `recovery machine must not use ${needle}`);
    }
  });

  it("the UI-04L recovery view model is pure presentation and is not wired", async () => {
    const own = await bundle("src/staff/redemptionRecovery/viewModel.ts");
    const local = own.inputs.filter((p) => !p.includes("node_modules")).map((p) => p.slice(p.lastIndexOf("src/"))).sort();
    assert.deepEqual(local, ["src/club/format.ts", "src/staff/redemptionRecovery/machine.ts", "src/staff/redemptionRecovery/viewModel.ts", "src/staff/redemptionRecovery/wire.ts"]);
    for (const needle of ["fetch(", "sessionStorage", "localStorage", "indexedDB", "document.", "window.", "XMLHttpRequest", "console.", "React"]) {
      assert.ok(!own.output.includes(needle), `view model must not use ${needle}`);
    }
    for (const entry of ["src/staff/main.tsx", "src/main.tsx"]) {
      const { inputs, output } = await bundle(entry);
      assert.equal(inputs.find((p) => p.endsWith("viewModel.ts") && p.includes("redemptionRecovery")), undefined, `${entry} must not import the view model`);
      assert.ok(!output.includes("Δοκιμή καταχώρησης ξανά"), `${entry} must not contain the recovery copy`);
    }
  });

  it("the UI-04N recovery screen is presentation only, makes no request and is wired into no shipped entry", async () => {
    const own = await bundle("src/staff/recoveryScreen/RecoveryScreen.tsx");
    const local = own.inputs.filter((p) => !p.includes("node_modules")).map((p) => p.slice(p.lastIndexOf("src/"))).sort();
    assert.deepEqual(
      local.filter((p) => !p.endsWith(".css")),
      [
        "src/club/format.ts",
        "src/staff/recoveryScreen/RecoveryScreen.tsx",
        "src/staff/redemptionRecovery/a11yContract.ts",
        "src/staff/redemptionRecovery/machine.ts",
        "src/staff/redemptionRecovery/viewModel.ts",
        "src/staff/redemptionRecovery/wire.ts",
      ],
    );
    for (const needle of ["fetch(", "XMLHttpRequest", "sendBeacon", "WebSocket", "EventSource", "sessionStorage", "localStorage", "indexedDB", "setTimeout", "setInterval", "location."]) {
      assert.ok(!own.output.includes(needle), `recovery screen must not use ${needle}`);
    }
    for (const entry of ["src/staff/main.tsx", "src/main.tsx"]) {
      const { inputs, output } = await bundle(entry);
      assert.equal(inputs.find((p) => p.includes("src/staff/recoveryScreen/")), undefined, `${entry} must not import the recovery screen or its preview`);
      assert.ok(!output.includes("evc-rs"), `${entry} must not contain the recovery screen`);
    }
    // The local preview is a dev-server page only: it is not an input of either production build.
    const prod = [readFileSync(join(appDir ?? "", "vite.config.ts"), "utf8"), readFileSync(join(appDir ?? "", "vite.staff.config.ts"), "utf8")].join("\n");
    assert.ok(!prod.includes("recovery-preview"), "no production build config references the preview page");
  });

  it("the UI-04M accessibility contract is pure (no DOM, focus or React) and is not wired", async () => {
    const own = await bundle("src/staff/redemptionRecovery/a11yContract.ts");
    const local = own.inputs.filter((p) => !p.includes("node_modules")).map((p) => p.slice(p.lastIndexOf("src/"))).sort();
    assert.deepEqual(local, [
      "src/club/format.ts",
      "src/staff/redemptionRecovery/a11yContract.ts",
      "src/staff/redemptionRecovery/machine.ts",
      "src/staff/redemptionRecovery/viewModel.ts",
      "src/staff/redemptionRecovery/wire.ts",
    ]);
    for (const needle of ["fetch(", "sessionStorage", "localStorage", "document.", "window.", ".focus(", "setAttribute", "XMLHttpRequest", "React", "setTimeout"]) {
      assert.ok(!own.output.includes(needle), `a11y contract must not use ${needle}`);
    }
    for (const entry of ["src/staff/main.tsx", "src/main.tsx"]) {
      const { inputs, output } = await bundle(entry);
      assert.equal(inputs.find((p) => p.endsWith("a11yContract.ts")), undefined, `${entry} must not import the a11y contract`);
      assert.ok(!output.includes("panel_primary"), `${entry} must not contain the a11y contract`);
    }
  });

  it("the UI-04J browser storage adapter uses per-tab sessionStorage only and is not wired", async () => {
    const own = await bundle("src/staff/redemptionRecovery/browserStorage.ts");
    const local = own.inputs.filter((p) => !p.includes("node_modules")).map((p) => p.slice(p.lastIndexOf("src/"))).sort();
    assert.deepEqual(local, ["src/club/format.ts", "src/staff/redemptionRecovery/browserStorage.ts", "src/staff/redemptionRecovery/machine.ts", "src/staff/redemptionRecovery/wire.ts"]);
    assert.ok(own.output.includes("window.sessionStorage"));
    for (const needle of ["fetch(", "localStorage", "indexedDB", "document.cookie", "XMLHttpRequest", "sendBeacon", "BroadcastChannel", "console."]) {
      assert.ok(!own.output.includes(needle), `adapter must not use ${needle}`);
    }
    for (const entry of ["src/staff/main.tsx", "src/main.tsx"]) {
      const { inputs, output } = await bundle(entry);
      assert.equal(inputs.find((p) => p.endsWith("browserStorage.ts")), undefined, `${entry} must not import the adapter`);
      assert.ok(!output.includes("evc-staff-redemption-recovery"), `${entry} must not contain the recovery key`);
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
