import assert from "node:assert/strict";
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { after, before, beforeEach, describe, it } from "node:test";
import { pathToFileURL } from "node:url";
import { createElement } from "react";
import type { CustomerConfig } from "../../src/customer/config";
import { CustomerApp, OfflineApp } from "../../src/customer/CustomerApp";
import type { CustomerApi, Me, MeResult } from "../../src/customer/customerApi";
import { MemberQrFlow, createQrKeeper, type QrFlowClock } from "../../src/customer/memberQr";
import type { CustomerQrApi, QrIssue, QrStatus } from "../../src/customer/qrApi";
import { createDom, type DomHarness } from "../staff/support/dom";
import { SAMPLE_ONLY_MODULES, sampleBundleMarkers, sampleBundleProblems, sampleGraphProblems, sampleGuardSelfTest } from "../../scripts/sample-guard.mjs";

/*
 * STAGE-08 round 17 — owner-found bug on staging (2026-11-23, plugin 98e25e8): an ACTIVE member saw the design
 * preview's SAMPLE QR for about two seconds before the real one (the live card kept the sample until the first
 * GET /me/qr answer). Structural guard: the member app's import graph never reaches the preview-only sample
 * modules, its bundle contains no sample marker, and every boot phase of the live app renders no sample.
 * The real entry's cold start is covered by customerBootPhases.test.ts.
 */

const appDir = process.env.EVC_APP_DIR as string;
const SAMPLE = [/ΔΕΙΓΜΑ/, /ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ/, /sample-qr/, /[Δδ]είγμα/, /Αλεξάνδρα Κωνσταντίνου/, /example\.invalid/, /Ο κωδικός QR δεν είναι ακόμη ενεργός/];
const CONFIG: CustomerConfig = { restBase: "https://shop.example/wp-json/evangelou-club-customer/v1", nonce: "0123456789", loginUrl: "https://shop.example/wp-login.php", logoutUrl: "https://shop.example/wp-login.php?action=logout&_wpnonce=abc", appVersion: "t" };
const NOW = Date.parse("2026-11-04T10:00:00Z");
const TOKEN = `evc_${"0123456789abcdef".repeat(3)}`;
const ISSUED = "2026-11-04T12:00:00+02:00";
const EMAIL = "eleni@example.org";

async function bundle(minify: boolean): Promise<{ inputs: string[]; output: string }> {
  const mod = await import(pathToFileURL(join(appDir, "node_modules", "esbuild", "lib", "main.js")).href);
  const esbuild = (mod.default ?? mod) as typeof import("esbuild");
  const outdir = mkdtempSync(join(tmpdir(), "evc-sample-"));
  try {
    const result = await esbuild.build({
      entryPoints: [join(appDir, "src/customer/main.tsx")],
      absWorkingDir: appDir,
      bundle: true,
      write: false,
      metafile: true,
      minify,
      // As Vite writes it: the Greek text as UTF-8, not \u escapes (the markers are compared as text).
      charset: "utf8",
      outdir,
      format: "esm",
      platform: "browser",
      jsx: "automatic",
      define: { "process.env.NODE_ENV": '"production"' },
      loader: { ".png": "file", ".css": "empty", ".webp": "file" },
      logLevel: "silent",
    });
    return { inputs: Object.keys(result.metafile.inputs).map((p) => p.replace(/\\/g, "/")), output: result.outputFiles.map((f) => f.text).join("\n") };
  } finally {
    rmSync(outdir, { recursive: true, force: true });
  }
}

describe("round 17: the member app cannot reach the preview sample (structure)", () => {
  it("the guard's own fixtures: clean accepted, every sample form refused", () => {
    assert.deepEqual(sampleGuardSelfTest(appDir), []);
  });

  it("the import graph of src/customer/main.tsx contains none of the preview-only sample modules", async () => {
    const { inputs } = await bundle(false);
    assert.deepEqual(sampleGraphProblems(inputs), []);
    for (const mod of SAMPLE_ONLY_MODULES as string[]) assert.equal(inputs.find((p) => p.endsWith(mod)), undefined, mod);
    // The design preview and the local journey preview still use them (sanity: the modules are real and used).
    for (const [file, mod] of [["src/preview/ClubPreviewApp.tsx", "customerSampleScreens"], ["src/customerPreview/CustomerJourney.tsx", "customerSampleScreens"], ["src/preview/customerSampleScreens.tsx", "sampleQr"]]) {
      assert.match(readFileSync(join(appDir, file), "utf8"), new RegExp(`from "[./]*(preview/)?${mod}"`), `${file} imports ${mod}`);
    }
  });

  it("the shared approved pass has no sample fallback: the QR place and the note come from the caller", () => {
    const pass = readFileSync(join(appDir, "src/preview/CustomerSignature.tsx"), "utf8");
    for (const word of ["PassQr", "sampleCustomerView", "ΔΕΙΓΜΑ", "(δείγμα)", "Ο κωδικός είναι δείγμα"]) assert.ok(!pass.includes(word), `CustomerSignature.tsx must not contain ${word}`);
    assert.match(pass, /\n  qr: ReactNode;\n/, "qr is required");
    assert.match(pass, /\n  qrNote: string;\n/, "qrNote is required");
  });

  it("the production-like customer bundle (minified and not) contains no sample marker", async () => {
    const markers = sampleBundleMarkers(appDir);
    for (const minify of [false, true]) {
      const { output } = await bundle(minify);
      assert.deepEqual(sampleBundleProblems(minify ? "min.js" : "plain.js", output, markers), [], `minify ${minify}`);
      assert.ok(output.includes("Φόρτωση του κωδικού σας…"), "sanity: the live loading words are in the bundle");
    }
  });

  it("the packager runs the guard on the graph, on every built customer file, and its self-test", () => {
    const packager = readFileSync(join(appDir, "scripts/package-club-plugin.mjs"), "utf8");
    assert.match(packager, /sampleGraphProblems\(inputs/);
    assert.match(packager, /sampleBundleProblems\(f, text, SAMPLE_MARKERS\)/);
    assert.match(packager, /sampleGuardSelfTest\(appDir\)/);
  });
});

// ---- Every boot phase of the live app (component level) -------------------------------------------------------

let dom: DomHarness;
let act: (fn: () => Promise<void> | void) => Promise<void>;
let observer: MutationObserver | null = null;
const violations: string[] = [];

function check(where: string) {
  const html = dom.document.body.innerHTML;
  for (const re of SAMPLE) if (re.test(html)) violations.push(`${where}: ${re}`);
}

before(async () => {
  dom = await createDom();
  act = (await import("react")).act as unknown as typeof act;
  dom.window.scrollTo = () => {};
  observer = new dom.window.MutationObserver(() => check("mutation"));
  observer.observe(dom.document.body, { subtree: true, childList: true, characterData: true, attributes: true });
});
after(() => {
  observer?.disconnect();
  dom.dispose();
});
beforeEach(async () => {
  await dom.reset();
  violations.length = 0;
});

const app = () => dom.document.querySelector('[data-evc-customer="app"]') as HTMLElement;
const text = () => app()?.textContent ?? "";
const qrPhase = () => dom.document.querySelector("[data-evc-qr-state]")?.getAttribute("data-evc-qr-state") ?? null;
const settle = async () => {
  for (let i = 0; i < 30; i++) await act(async () => {});
};

/** A clock whose timers never fire (no automatic retry during a test). */
const stillClock: QrFlowClock = { now: () => NOW, setTimer: () => 0, clearTimer: () => {} };

function me(state: Me["state"]): Me {
  return { displayName: "Ελένη Μαρκοπούλου", email: EMAIL, state, paidUntilUtc: state === "active" || state === "expired" ? "2026-11-15T10:00:00+00:00" : null };
}

/** Held-back answers: each call waits until the test releases it. */
function heldApi() {
  const gets: ((s: QrStatus) => void)[] = [];
  const posts: ((i: QrIssue) => void)[] = [];
  const api: CustomerQrApi = {
    status: () => new Promise<QrStatus>((r) => gets.push(r)),
    issue: () => new Promise<QrIssue>((r) => posts.push(r)),
  };
  return { api, answerGet: (s: QrStatus) => gets.splice(0).forEach((r) => r(s)), answerPost: (i: QrIssue) => posts.splice(0).forEach((r) => r(i)) };
}

describe("round 17: every boot phase of the live app renders no sample QR or sample text", () => {
  it("active member: /me pending → card with «Φόρτωση…» while GET and POST /me/qr wait → real code; no sample at any step", async () => {
    let answerMe: (r: MeResult) => void = () => {};
    const held = heldApi();
    const flow = new MemberQrFlow(held.api, createQrKeeper(), stillClock);
    await dom.render(createElement(CustomerApp, { config: CONFIG, api: { me: () => new Promise<MeResult>((r) => (answerMe = r)) }, now: () => NOW, navigate: () => {}, qrFlow: flow }));
    await settle();
    check("before /me");
    assert.match(text(), /Φόρτωση του λογαριασμού σας…/);
    await act(async () => answerMe({ kind: "ok", me: me("active") }));
    await settle();
    check("/me answered, GET /me/qr pending");
    assert.equal(qrPhase(), "loading");
    assert.ok(dom.document.querySelector('[data-evc-qr-placeholder="loading"]'));
    assert.equal(dom.document.querySelectorAll("[data-evc-qr-state] svg").length, 0, "no pattern while loading");
    assert.match(text(), /Φόρτωση του κωδικού σας…/);
    assert.ok(app().querySelector('[aria-label="Κάρτα μέλους Evangelou Club"]'), "the live label, never the sample label");
    assert.match(text(), /Δείξτε τον κωδικό στο ταμείο/, "while the code loads: the live note, not the «not available» one");
    assert.doesNotMatch(text(), /θα εμφανιστεί σε αυτή την κάρτα/);
    await act(async () => held.answerGet({ kind: "none" }));
    await settle();
    check("POST pending");
    assert.equal(qrPhase(), "loading");
    await act(async () => held.answerPost({ kind: "issued", token: TOKEN, issuedAt: ISSUED }));
    await settle();
    assert.equal(qrPhase(), "shown");
    assert.ok(dom.document.querySelector('[data-evc-qr="member"]'));
    assert.deepEqual(violations, []);
  });

  it("unchecked: the server cannot be reached and this phone keeps the member's real code → that code at once, never a sample", async () => {
    const keeper = createQrKeeper();
    await keeper.write(EMAIL, ISSUED, TOKEN);
    const api: CustomerQrApi = { status: async () => ({ kind: "error" }), issue: async () => ({ kind: "error" }) };
    const flow = new MemberQrFlow(api, keeper, stillClock);
    await dom.render(createElement(CustomerApp, { config: CONFIG, api: { me: async (): Promise<MeResult> => ({ kind: "ok", me: me("active") }) }, now: () => NOW, navigate: () => {}, qrFlow: flow }));
    await settle();
    assert.equal(qrPhase(), "unchecked");
    assert.ok(dom.document.querySelector('[data-evc-qr="member"]'), "the kept real code");
    assert.deepEqual(violations, []);
  });

  it("unreachable without a kept code → a Greek error with a retry, never a sample", async () => {
    const api: CustomerQrApi = { status: async () => ({ kind: "unavailable" }), issue: async () => ({ kind: "error" }) };
    const flow = new MemberQrFlow(api, createQrKeeper(), stillClock);
    await dom.render(createElement(CustomerApp, { config: CONFIG, api: { me: async (): Promise<MeResult> => ({ kind: "ok", me: me("active") }) }, now: () => NOW, navigate: () => {}, qrFlow: flow }));
    await settle();
    assert.equal(qrPhase(), "unavailable");
    assert.match(text(), /Θα ξαναπροσπαθήσουμε αυτόματα σε λίγο/);
    assert.equal(dom.document.querySelectorAll("[data-evc-qr-state] svg").length, 0);
    assert.deepEqual(violations, []);
  });

  it("no QR route on this site (off) and no QR flow at all → «not available yet», never a sample", async () => {
    const off: CustomerQrApi = { status: async () => ({ kind: "off" }), issue: async () => ({ kind: "error" }) };
    await dom.render(createElement(CustomerApp, { config: CONFIG, api: { me: async (): Promise<MeResult> => ({ kind: "ok", me: me("active") }) }, now: () => NOW, navigate: () => {}, qrFlow: new MemberQrFlow(off, createQrKeeper(), stillClock) }));
    await settle();
    assert.equal(qrPhase(), "off");
    assert.ok(dom.document.querySelector('[data-evc-qr-placeholder="not-available"]'));
    await dom.reset();
    await dom.render(createElement(CustomerApp, { config: CONFIG, api: { me: async (): Promise<MeResult> => ({ kind: "ok", me: me("active") }) }, now: () => NOW, navigate: () => {} }));
    await settle();
    assert.ok(dom.document.querySelector('[data-evc-qr-placeholder="not-available"]'));
    assert.deepEqual(violations, []);
  });

  it("every non-active membership on the card (also while its QR answer is pending) → no sample", async () => {
    for (const state of ["expired", "pending_payment", "cancelled", "none", "unverified"] as const) {
      await dom.reset();
      const held = heldApi();
      await dom.render(createElement(CustomerApp, { config: CONFIG, api: { me: async (): Promise<MeResult> => ({ kind: "ok", me: me(state) }) }, now: () => NOW, navigate: () => {}, qrFlow: new MemberQrFlow(held.api, createQrKeeper(), stillClock) }));
      await settle();
      const card = [...dom.document.querySelectorAll("button")].find((b) => b.textContent?.includes("Κάρτα"));
      if (card) await dom.click(card);
      await settle();
      check(state);
      await act(async () => held.answerGet({ kind: "not_member" }));
      await settle();
      check(`${state} answered`);
    }
    assert.deepEqual(violations, []);
  });

  it("the service worker's offline page and the error pages → no sample", async () => {
    await dom.render(createElement(OfflineApp, { reload: () => {} }));
    await settle();
    for (const result of [{ kind: "signed_out" }, { kind: "forbidden" }, { kind: "unavailable" }, { kind: "error" }] as MeResult[]) {
      await dom.reset();
      await dom.render(createElement(CustomerApp, { config: CONFIG, api: { me: async () => result } as CustomerApi, now: () => NOW, navigate: () => {} }));
      await settle();
    }
    assert.deepEqual(violations, []);
  });
});
