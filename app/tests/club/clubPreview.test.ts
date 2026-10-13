import assert from "node:assert/strict";
import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, it } from "node:test";
import { pathToFileURL } from "node:url";
import { isClubPreviewPath, resolveEntry } from "../../src/entryRoute";
import { clubPreviewEnabled } from "../../src/preview/buildFlag";
import { MEMBERS, PREVIEW_MARKER, addDays, athensToday, redemptionHistory, todaysRedemptions } from "../../src/preview/previewData";
import { parsePreviewPath, sampleQrModules } from "../../src/preview/screens";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { ClubWelcome, MemberPass } from "../../src/preview/CustomerSignature";

/**
 * UI-01 design preview: route isolation, build gating and bundle isolation.
 * Bundles REAL entry points with esbuild and inspects the module graph and
 * output (not source-string searches).
 */

const appDir = process.env.EVC_APP_DIR;

async function bundle(entry: string, previewFlag: boolean): Promise<{ inputs: string[]; output: string }> {
  assert.ok(appDir, "EVC_APP_DIR is set by the test runner");
  const mod = await import(pathToFileURL(join(appDir, "node_modules", "esbuild", "lib", "main.js")).href);
  const esbuild = (mod.default ?? mod) as typeof import("esbuild");
  const outdir = mkdtempSync(join(tmpdir(), "evc-preview-graph-"));
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
      define: { __EVC_CLUB_PREVIEW__: String(previewFlag) },
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

const PREVIEW_MODULE = /src\/preview\//;
const PREVIEW_STRINGS = [PREVIEW_MARKER, "ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ", "ΠΡΟΕΠΙΣΚΟΠΗΣΗ ΣΧΕΔΙΑΣΜΟΥ", "example.invalid"];

describe("club preview routing", () => {
  it("matches /club-preview exactly as a path segment", () => {
    for (const p of ["/club-preview", "/club-preview/", "/club-preview/staff/scan", "/club-preview/customer/card"]) {
      assert.equal(isClubPreviewPath(p), true, p);
    }
    for (const p of ["/club", "/club/", "/club/x", "/club-previewx", "/clubpreview", "/club-previews/x", "/", "/admin", "/x/club-preview"]) {
      assert.equal(isClubPreviewPath(p), false, p);
    }
  });

  it("is resolved before /club when enabled, and changes nothing when disabled", () => {
    assert.equal(resolveEntry("/club-preview/staff/scan", true), "club-preview");
    assert.equal(resolveEntry("/club", true), "club");
    assert.equal(resolveEntry("/club/anything", true), "club");
    assert.equal(resolveEntry("/club-previewx", true), "club", "not the preview; existing /club prefix behaviour kept");
    assert.equal(resolveEntry("/admin", true), "admin");
    assert.equal(resolveEntry("/", true), "ordering");
    // Disabled (production build): exactly the pre-UI-01 behaviour.
    assert.equal(resolveEntry("/club-preview/staff/scan", false), "club");
    assert.equal(resolveEntry("/club", false), "club");
    assert.equal(resolveEntry("/admin/orders", false), "admin");
    assert.equal(resolveEntry("/catalog", false), "ordering");
  });

  it("parses preview screens with safe fallbacks", () => {
    assert.deepEqual(parsePreviewPath("/club-preview/staff/scan"), { area: "staff", screen: "scan" });
    assert.deepEqual(parsePreviewPath("/club-preview/management/settings"), { area: "management", screen: "settings" });
    assert.deepEqual(parsePreviewPath("/club-preview"), { area: "customer", screen: "welcome" });
    assert.deepEqual(parsePreviewPath("/club-preview/unknown/zzz"), { area: "customer", screen: "welcome" });
    assert.deepEqual(parsePreviewPath("/club-preview/staff/zzz"), { area: "staff", screen: "dashboard" });
  });
});

describe("club preview build gate", () => {
  it("is off for Vercel production and an explicit opt-out, on otherwise", () => {
    assert.equal(clubPreviewEnabled({ VERCEL_ENV: "production" }), false);
    assert.equal(clubPreviewEnabled({ VERCEL_ENV: "production", EVC_CLUB_PREVIEW: "on" }), false);
    assert.equal(clubPreviewEnabled({ EVC_CLUB_PREVIEW: "off" }), false);
    assert.equal(clubPreviewEnabled({ VERCEL_ENV: "preview" }), true);
    assert.equal(clubPreviewEnabled({}), true);
  });

  it("with the flag OFF the public app bundle contains no preview code at all", async () => {
    const { inputs, output } = await bundle("src/main.tsx", false);
    assert.ok(inputs.some((p) => /src\/club\/ClubApp\.tsx$/.test(p)), "sanity: /club demo still bundled");
    assert.equal(inputs.find((p) => PREVIEW_MODULE.test(p) && !/preview-env\.d\.ts$/.test(p)), undefined);
    for (const s of PREVIEW_STRINGS) assert.ok(!output.includes(s), `production bundle must not contain "${s}"`);
  });

  it("with the flag ON the preview is reachable from the public entry", async () => {
    const { inputs } = await bundle("src/main.tsx", true);
    assert.ok(inputs.some((p) => /src\/preview\/ClubPreviewApp\.tsx$/.test(p)));
  });
});

describe("club preview isolation", () => {
  it("the real staff bundle never contains preview modules or preview data", async () => {
    const { inputs, output } = await bundle("src/staff/main.tsx", true);
    assert.ok(inputs.some((p) => p.endsWith("src/staff/staffApi.ts")), "sanity: staff graph");
    assert.equal(inputs.find((p) => PREVIEW_MODULE.test(p)), undefined);
    for (const s of PREVIEW_STRINGS) assert.ok(!output.includes(s), `staff bundle must not contain "${s}"`);
  });

  it("the preview imports no service, API, WordPress, admin or ordering code and makes no network calls", async () => {
    const { inputs, output } = await bundle("src/preview/ClubPreviewApp.tsx", true);
    const forbidden = [/src\/club\//, /src\/staff\//, /src\/admin\//, /src\/(App|AppContext|MenuContext|SettingsContext)\.tsx$/, /src\/lib\//, /\/api\//];
    for (const pattern of forbidden) {
      const hit = inputs.find((p) => pattern.test(p));
      assert.equal(hit, undefined, `preview must not import ${hit}`);
    }
    for (const needle of ["fetch(", "XMLHttpRequest", "sendBeacon", "/wp-json", "/api/", "localStorage", "navigator.mediaDevices"]) {
      assert.ok(!output.includes(needle), `preview bundle must not contain "${needle}"`);
    }
  });
});

describe("club preview synthetic data", () => {
  it("uses only clearly fictional, non-sensitive member data", () => {
    for (const m of MEMBERS) {
      assert.match(m.email, /@example\.invalid$/);
      assert.match(m.phoneMasked, /•/);
      assert.ok(!/\d{7,}/.test(m.phoneMasked), "no real-looking phone numbers");
    }
  });

  it("gives at most one free coffee per member per Athens day", () => {
    const today = athensToday(new Date("2026-10-13T21:30:00Z")); // 00:30 Athens next day
    assert.equal(today, "2026-10-14");
    assert.equal(athensToday(new Date("2026-10-13T20:30:00Z")), "2026-10-13");
    const all = [...redemptionHistory(today, 30), ...todaysRedemptions(today)];
    const keys = all.map((r) => `${r.memberId}|${r.date}`);
    assert.equal(new Set(keys).size, keys.length);
    assert.equal(addDays("2026-03-31", -31), "2026-02-28");
  });

  it("the sample QR is deterministic decoration, not an encoded code", () => {
    assert.deepEqual(sampleQrModules(11), sampleQrModules(11));
    assert.notDeepEqual(sampleQrModules(11), sampleQrModules(12));
    assert.ok(sampleQrModules(11).length > 100);
  });
});

describe("UI-01.R1a membership copy accuracy (rendered customer screens)", () => {
  // Approved rule: EUR 20 buys ONE CALENDAR MONTH, expiring on Europe/Athens
  // time, renewed manually. It is never "30 days".
  const today = "2026-10-13";
  const go = () => {};
  const text = (html: string) => html.replace(/<[^>]+>/g, " ").replace(/&nbsp;/g, " ").replace(/\s+/g, " ");
  const welcome = text(renderToStaticMarkup(createElement(ClubWelcome, { go })));
  const passes = (["active", "pending", "expired"] as const).flatMap((state) =>
    [false, true].map((coffeeUsedToday) => text(renderToStaticMarkup(createElement(MemberPass, { go, state, coffeeUsedToday, today })))),
  );
  const THIRTY_DAYS = /30\s*(ημέρ|ημερ|μέρ|days?)/i;

  it("never describes the membership period as 30 days", () => {
    for (const screen of [welcome, ...passes]) assert.doesNotMatch(screen, THIRTY_DAYS);
  });

  it("states one calendar month, Athens expiry and manual renewal", () => {
    assert.match(welcome, /ημερολογιακό μήνα/);
    assert.match(welcome, /ώρα Ελλάδας/);
    assert.match(welcome, /Δεν υπάρχει αυτόματη χρέωση/);
    assert.match(passes[0], /Χειροκίνητη, 20 € \/ ημερολογιακό μήνα/);
  });

  it("presents the coffee list as illustrative, not a finalized catalogue", () => {
    assert.match(welcome, /ενδεικτικές επιλογές/);
    assert.match(welcome, /Για παράδειγμα: Espresso/);
    assert.match(welcome, /Ο τελικός κατάλογος δεν έχει οριστικοποιηθεί/);
  });
});
