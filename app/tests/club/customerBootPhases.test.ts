import assert from "node:assert/strict";
import { after, before, describe, it } from "node:test";
import { createDom, type DomHarness } from "../staff/support/dom";

/*
 * STAGE-08 round 17 — owner-found bug on staging (2026-11-23, plugin 98e25e8): an ACTIVE member opened the
 * home-screen app and saw the design preview's SAMPLE QR for about two seconds before the real one.
 *
 * This file boots the REAL member entry (src/customer/main.tsx, the file the WordPress /club-member/ page and the
 * service worker's cached assets run) with a fake network whose every answer is held back, and watches the DOM
 * with a MutationObserver from the first render: no committed DOM state may ever contain the sample code, its
 * stamp, its labels, or the fictional preview member. Each boot phase is also checked by hand:
 *   1. before GET /me answers;  2. GET /me answered (active), GET /me/qr pending (the owner's window);
 *   3. GET /me/qr answered, POST /me/qr pending;  4. the real code drawn.
 */

const ORIGIN = "http://localhost";
const REST = `${ORIGIN}/wp-json/evangelou-club-customer/v1`;
const TOKEN = `evc_${"0123456789abcdef".repeat(3)}`;
const ISSUED = "2026-11-04T12:00:00+02:00";
const SAMPLE = [/ΔΕΙΓΜΑ/, /ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ/, /sample-qr/, /[Δδ]είγμα/, /Αλεξάνδρα Κωνσταντίνου/, /example\.invalid/, /Ο κωδικός QR δεν είναι ακόμη ενεργός/];

type Pending = { url: string; method: string; resolve: (status: number, body: unknown) => void };

let dom: DomHarness;
const pending: Pending[] = [];
const violations: string[] = [];
const seenStates = new Set<string>();
let observer: MutationObserver;

// Round 18 (round-17 review, Low 1; mutant R17X-4): the route GET /me/qr exists in this boot, so the card may never say
// that the code is not available yet, at any moment of it.
const NOT_AVAILABLE = [/δεν είναι ακόμη διαθέσιμος/, /data-evc-qr-placeholder="not-available"/];

function check(where: string) {
  const html = dom.document.body.innerHTML;
  for (const re of SAMPLE) if (re.test(html)) violations.push(`${where}: ${re}`);
  for (const re of NOT_AVAILABLE) if (re.test(html)) violations.push(`${where}: not available while the route exists: ${re}`);
  const panel = dom.document.querySelector("[data-evc-qr-state]");
  if (panel) seenStates.add(panel.getAttribute("data-evc-qr-state") ?? "");
}

async function turns(n = 20) {
  for (let i = 0; i < n; i++) await new Promise((r) => setTimeout(r, 0));
}

/** Every waiting request for the path (StrictMode runs the mount effects twice in development). */
function take(path: string, method = "GET"): { resolve: (status: number, body: unknown) => void } {
  const mine = pending.filter((p) => p.method === method && new URL(p.url).pathname.endsWith(path));
  assert.ok(mine.length > 0, `a ${method} ${path} request is waiting (have: ${pending.map((p) => `${p.method} ${p.url}`).join(", ")})`);
  for (const p of mine) pending.splice(pending.indexOf(p), 1);
  return { resolve: (status, body) => mine.forEach((p) => p.resolve(status, body)) };
}

before(async () => {
  dom = await createDom();
  // main.tsx renders with its own createRoot, outside act() (as in the browser).
  (globalThis as Record<string, unknown>).IS_REACT_ACT_ENVIRONMENT = false;
  const fakeFetch = (input: string, init: RequestInit = {}) =>
    new Promise((resolveFetch) => {
      const url = String(input);
      const method = (init.method ?? "GET").toUpperCase();
      const answer = (status: number, body: unknown) =>
        resolveFetch({ status, ok: status >= 200 && status < 300, headers: { get: () => null }, json: async () => body });
      // The figures, history and notifications are not part of this bug: no route (404) at once.
      if (!/\/me(\/qr)?$/.test(new URL(url).pathname)) return answer(404, { code: "rest_no_route" });
      pending.push({ url, method, resolve: answer });
    });
  for (const target of [globalThis as Record<string, unknown>, dom.window as unknown as Record<string, unknown>]) {
    Object.defineProperty(target, "fetch", { value: fakeFetch, configurable: true, writable: true });
  }
  const config = dom.document.createElement("script");
  config.type = "application/json";
  config.id = "evc-customer-config";
  config.textContent = JSON.stringify({ restBase: REST, homePath: "/", nonce: "0123456789", loginUrl: `${ORIGIN}/wp-login.php`, logoutUrl: `${ORIGIN}/wp-login.php?action=logout&_wpnonce=abc`, appVersion: "t" });
  dom.document.body.appendChild(config);
  observer = new dom.window.MutationObserver(() => check("mutation"));
  observer.observe(dom.document.body, { subtree: true, childList: true, characterData: true, attributes: true });
});
after(() => {
  observer?.disconnect();
  for (const p of pending.splice(0)) p.resolve(404, null);
  dom.dispose();
});

describe("round 17: cold start of the real member entry never shows the sample QR", () => {
  it("every boot phase of an ACTIVE member: account loading, code loading, code issued; never a sample", async () => {
    await import("../../src/customer/main");
    await turns();
    check("phase 1");
    assert.match(dom.document.body.textContent ?? "", /Φόρτωση του λογαριασμού σας…/, "phase 1: the account is loading");
    assert.equal(dom.document.querySelector("[data-evc-qr-state]"), null, "phase 1: no card yet");

    take("/me").resolve(200, {
      result: "ok",
      contract: "customer-me-v1",
      account: { display_name: "Ελένη Μαρκοπούλου", email: "eleni@example.org" },
      membership: { state: "active", paid_until_utc: "2099-11-15T10:00:00+00:00" },
      club: { coffee_today: "not_connected", card: "sample_only" },
    });
    await turns(60);
    check("phase 2");
    // The owner's window: the card is up, GET /me/qr has not answered.
    assert.match(dom.document.body.textContent ?? "", /Ελένη Μαρκοπούλου/);
    assert.equal(dom.document.querySelector("[data-evc-qr-state]")?.getAttribute("data-evc-qr-state"), "loading");
    assert.ok(dom.document.querySelector('[data-evc-qr-placeholder="loading"]'), "phase 2: the neutral place-holder");
    assert.match(dom.document.body.textContent ?? "", /Φόρτωση του κωδικού σας…/);
    assert.equal(dom.document.querySelectorAll("[data-evc-qr-state] svg").length, 0, "phase 2: no pattern of any kind");
    assert.doesNotMatch(dom.document.body.textContent ?? "", /δεν είναι ακόμη διαθέσιμος/, "phase 2: never «not available» while GET /me/qr runs");
    assert.ok(dom.document.querySelector('[data-evc-qr-placeholder="not-available"]') === null, "phase 2: no not-available place-holder");

    take("/me/qr").resolve(200, { result: "ok", qr: { state: "active", issued_at: ISSUED } });
    await turns(60);
    check("phase 3");
    assert.equal(dom.document.querySelector("[data-evc-qr-state]")?.getAttribute("data-evc-qr-state"), "loading", "phase 3: POST pending");
    assert.equal(dom.document.querySelectorAll("[data-evc-qr-state] svg").length, 0);

    take("/me/qr", "POST").resolve(200, { result: "ok", qr: { state: "active", token: TOKEN, issued_at: ISSUED } });
    await turns(60);
    check("phase 4");
    assert.ok(dom.document.querySelector('[data-evc-qr="member"]'), "phase 4: the member's real code");
    assert.ok(seenStates.has("loading") && seenStates.has("shown"));
    assert.deepEqual(violations, [], "no DOM state of the boot ever contained a sample or a not-available place-holder");
  });
});
