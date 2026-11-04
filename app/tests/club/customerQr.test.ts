import assert from "node:assert/strict";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement } from "react";
import type { CustomerConfig } from "../../src/customer/config";
import { CustomerApp } from "../../src/customer/CustomerApp";
import { createCustomerApi, createNonceBox, type CustomerApi, type Me } from "../../src/customer/customerApi";
import { MemberQrFlow, createQrMemory, type QrMemory } from "../../src/customer/memberQr";
import { createCustomerQrApi, parseQrIssued, parseQrStatus, type CustomerQrApi, type QrIssue, type QrStatus } from "../../src/customer/qrApi";
import { createDom, type DomHarness } from "../staff/support/dom";

/*
 * STAGE-05D: the member's own Club QR in the REAL customer app.
 * - qrApi: GET/POST evangelou-club-customer/v1/me/qr parsed strictly; the
 *   token only ever comes from a POST 200.
 * - MemberQrFlow: the decision rules of docs/member-qr.md; a replacement
 *   only after an explicit confirmation (D6); 409 qr_exists handled.
 * - CustomerApp: the real QR in the approved pass; the sample stays when the
 *   route does not exist; nothing in browser storage, the token never as text.
 */

const ORIGIN = "https://shop.example";
const CONFIG: CustomerConfig = { restBase: `${ORIGIN}/wp-json/evangelou-club-customer/v1`, nonce: "0123456789", loginUrl: `${ORIGIN}/wp-login.php?redirect_to=x`, logoutUrl: `${ORIGIN}/wp-login.php?action=logout&_wpnonce=abc`, appVersion: "t" };
const TOKEN = `evc_${"0123456789abcdef".repeat(3)}`;
const TOKEN2 = `evc_${"fedcba9876543210".repeat(3)}`;
const ISSUED = "2026-11-04T12:00:00+02:00";
const ISSUED2 = "2026-11-05T09:30:00+02:00";

type Reply = { status: number; body: unknown; headers?: Record<string, string> } | "throw" | "hang";

function fakeFetch(replies: Reply[]) {
  const calls: { url: string; init: RequestInit }[] = [];
  let i = 0;
  const impl = (async (url: string, init: RequestInit) => {
    calls.push({ url, init });
    const r = replies[Math.min(i++, replies.length - 1)];
    if (r === "throw") throw new TypeError("network");
    if (r === "hang") return new Promise(() => {});
    return { status: r.status, headers: new Headers(r.headers ?? {}), json: async () => r.body } as unknown as Response;
  }) as unknown as typeof fetch;
  return { impl, calls };
}

const err = (code: string) => ({ error: { code, message: "x" } });

describe("STAGE-05D customer QR client (GET/POST /me/qr)", () => {
  it("GET: every documented state, strictly; a token in a GET reply is not the contract", () => {
    assert.deepEqual(parseQrStatus({ result: "ok", qr: { state: "active", issued_at: ISSUED } }), { kind: "active", issuedAt: ISSUED });
    for (const state of ["none", "not_member", "member_disabled"] as const) assert.deepEqual(parseQrStatus({ result: "ok", qr: { state } }), { kind: state });
    const bad: unknown[] = [
      null,
      { result: "ok" },
      { result: "ok", qr: { state: "active" } },
      { result: "ok", qr: { state: "active", issued_at: "2026-11-04 12:00:00" } },
      { result: "ok", qr: { state: "active", issued_at: ISSUED, token: TOKEN } },
      { result: "ok", qr: { state: "none", token: TOKEN } },
      { result: "ok", qr: { state: "revoked" } },
      { result: "ok", qr: { state: "none" }, extra: 1 },
      { result: "unavailable", qr: { state: "none" } },
    ];
    for (const b of bad) assert.equal(parseQrStatus(b), null, JSON.stringify(b));
  });

  it("POST 200: exactly state + token + issued_at, a well-formed token only", () => {
    assert.deepEqual(parseQrIssued({ result: "ok", qr: { state: "active", token: TOKEN, issued_at: ISSUED } }), { kind: "issued", token: TOKEN, issuedAt: ISSUED });
    for (const qr of [
      { state: "active", token: TOKEN.toUpperCase(), issued_at: ISSUED },
      { state: "active", token: `${TOKEN}0`, issued_at: ISSUED },
      { state: "active", token: TOKEN },
      { state: "none", token: TOKEN, issued_at: ISSUED },
      { state: "active", token: TOKEN, issued_at: ISSUED, member_id: "mem_x" },
    ]) {
      assert.equal(parseQrIssued({ result: "ok", qr }), null, JSON.stringify(qr));
    }
  });

  it("transport: same origin, cookie + nonce, no-store, no redirects; the nonce refresh is shared with GET /me", async () => {
    const box = createNonceBox(CONFIG.nonce);
    const f = fakeFetch([
      { status: 200, body: { result: "ok", qr: { state: "none" } }, headers: { "X-WP-Nonce": "abcdef0123" } },
      { status: 200, body: { result: "ok", qr: { state: "active", token: TOKEN, issued_at: ISSUED } } },
    ]);
    const qr = createCustomerQrApi(CONFIG, box, f.impl);
    assert.deepEqual(await qr.status(), { kind: "none" });
    assert.deepEqual(await qr.issue(false), { kind: "issued", token: TOKEN, issuedAt: ISSUED });
    assert.equal(f.calls[0].url, `${CONFIG.restBase}/me/qr`);
    assert.equal(f.calls[0].init.method, "GET");
    assert.equal(f.calls[0].init.body, undefined);
    assert.equal(f.calls[1].init.method, "POST");
    assert.equal(f.calls[1].init.body, "{}");
    for (const c of f.calls) {
      assert.equal(c.init.credentials, "same-origin");
      assert.equal(c.init.cache, "no-store");
      assert.equal(c.init.redirect, "error");
    }
    assert.equal((f.calls[0].init.headers as Record<string, string>)["X-WP-Nonce"], "0123456789");
    assert.equal((f.calls[1].init.headers as Record<string, string>)["X-WP-Nonce"], "abcdef0123", "the refreshed nonce");
    const me = fakeFetch([{ status: 401, body: null }]);
    await createCustomerApi(CONFIG, me.impl, 1000, box).me();
    assert.equal((me.calls[0].init.headers as Record<string, string>)["X-WP-Nonce"], "abcdef0123", "shared with /me");
    const replace = fakeFetch([{ status: 200, body: { result: "ok", qr: { state: "active", token: TOKEN2, issued_at: ISSUED2 } } }]);
    await createCustomerQrApi(CONFIG, box, replace.impl).issue(true);
    assert.equal(replace.calls[0].init.body, '{"replace":true}');
    assert.equal(box.get(), "abcdef0123");
    box.update("<script>");
    assert.equal(box.get(), "abcdef0123", "a malformed nonce is ignored");
  });

  it("GET answers: 404 = the feature is off; 401 / 403 / 503 / anything else", async () => {
    const cases: [Reply, QrStatus][] = [
      [{ status: 404, body: { code: "rest_no_route" } }, { kind: "off" }],
      [{ status: 401, body: err("unauthorized") }, { kind: "signed_out" }],
      [{ status: 403, body: err("forbidden") }, { kind: "forbidden" }],
      [{ status: 503, body: { result: "unavailable" } }, { kind: "unavailable" }],
      [{ status: 503, body: { result: "unavailable", x: 1 } }, { kind: "error" }],
      [{ status: 500, body: null }, { kind: "error" }],
      [{ status: 200, body: "<html>" }, { kind: "error" }],
      ["throw", { kind: "error" }],
    ];
    for (const [reply, want] of cases) {
      const f = fakeFetch([reply]);
      assert.deepEqual(await createCustomerQrApi(CONFIG, createNonceBox(CONFIG.nonce), f.impl).status(), want, JSON.stringify(reply));
    }
    const hang = fakeFetch(["hang"]);
    assert.deepEqual(await createCustomerQrApi(CONFIG, createNonceBox(CONFIG.nonce), hang.impl, 20).status(), { kind: "error" }, "bounded");
  });

  it("POST answers: 409 qr_exists / concurrent_change, 404 not_a_member, 403 member_disabled vs forbidden", async () => {
    const cases: [Reply, QrIssue][] = [
      [{ status: 409, body: err("qr_exists") }, { kind: "qr_exists" }],
      [{ status: 409, body: err("concurrent_change") }, { kind: "concurrent_change" }],
      [{ status: 409, body: err("something_else") }, { kind: "error" }],
      [{ status: 404, body: err("not_a_member") }, { kind: "not_member" }],
      [{ status: 404, body: { code: "rest_no_route" } }, { kind: "error" }],
      [{ status: 403, body: err("member_disabled") }, { kind: "member_disabled" }],
      [{ status: 403, body: err("forbidden") }, { kind: "forbidden" }],
      [{ status: 401, body: err("unauthorized") }, { kind: "signed_out" }],
      [{ status: 503, body: { result: "unavailable" } }, { kind: "unavailable" }],
      [{ status: 400, body: err("invalid_request") }, { kind: "error" }],
      ["throw", { kind: "error" }],
    ];
    for (const [reply, want] of cases) {
      const f = fakeFetch([reply]);
      assert.deepEqual(await createCustomerQrApi(CONFIG, createNonceBox(CONFIG.nonce), f.impl).issue(false), want, JSON.stringify(reply));
    }
  });
});

/** Scripted fake API: answers in order, records calls. */
function scripted(statuses: QrStatus[], issues: QrIssue[]) {
  const calls: string[] = [];
  const api: CustomerQrApi = {
    async status() {
      calls.push("GET");
      return statuses.shift() ?? { kind: "error" };
    },
    async issue(replace) {
      calls.push(replace ? "POST replace" : "POST {}");
      return issues.shift() ?? { kind: "error" };
    },
  };
  return { api, calls };
}

describe("STAGE-05D member QR flow (docs/member-qr.md decision rules)", () => {
  it("none: POST {} once and show the new QR, kept in memory only", async () => {
    const memory = createQrMemory();
    const { api, calls } = scripted([{ kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }]);
    const flow = new MemberQrFlow(api, memory);
    await flow.open();
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: true });
    assert.deepEqual(calls, ["GET", "POST {}"]);
    assert.deepEqual(memory.get(), { token: TOKEN, issuedAt: ISSUED });
  });

  it("active with the same issued_at kept here: shown again with no POST", async () => {
    const memory = createQrMemory();
    memory.set({ token: TOKEN, issuedAt: ISSUED });
    const { api, calls } = scripted([{ kind: "active", issuedAt: ISSUED }], []);
    const flow = new MemberQrFlow(api, memory);
    await flow.open();
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: false });
    assert.deepEqual(calls, ["GET"]);
  });

  it("active but nothing (or an older QR) kept: never replaced by itself, the old kept code is forgotten", async () => {
    for (const kept of [null, { token: TOKEN, issuedAt: ISSUED }]) {
      const memory = createQrMemory();
      if (kept) memory.set(kept);
      const { api, calls } = scripted([{ kind: "active", issuedAt: ISSUED2 }], []);
      const flow = new MemberQrFlow(api, memory);
      await flow.open();
      assert.deepEqual(flow.getState(), { phase: "elsewhere", reason: "not_here" });
      assert.deepEqual(calls, ["GET"], "no POST without the member's request");
      assert.equal(memory.get(), null, "a dead code is never shown");
    }
  });

  it("«Νέο QR» asks first; cancel goes back; confirm POSTs replace: true", async () => {
    const memory = createQrMemory();
    const { api, calls } = scripted([{ kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }, { kind: "issued", token: TOKEN2, issuedAt: ISSUED2 }]);
    const flow = new MemberQrFlow(api, memory);
    await flow.open();
    flow.askReplace();
    assert.deepEqual(flow.getState(), { phase: "confirm_replace", back: "shown" });
    flow.cancelReplace();
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: true });
    assert.deepEqual(calls, ["GET", "POST {}"], "cancel sends nothing");
    flow.askReplace();
    await flow.confirmReplace();
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN2, issuedAt: ISSUED2, fresh: true });
    assert.deepEqual(calls, ["GET", "POST {}", "POST replace"]);
    assert.deepEqual(memory.get(), { token: TOKEN2, issuedAt: ISSUED2 });
    await flow.confirmReplace();
    assert.equal(calls.length, 3, "confirm without a question does nothing");
  });

  it("409 qr_exists after GET none (another device was first): explained, nothing replaced", async () => {
    const { api, calls } = scripted([{ kind: "none" }], [{ kind: "qr_exists" }]);
    const flow = new MemberQrFlow(api, createQrMemory());
    await flow.open();
    assert.deepEqual(flow.getState(), { phase: "elsewhere", reason: "created_elsewhere" });
    flow.askReplace();
    assert.deepEqual(flow.getState(), { phase: "confirm_replace", back: "elsewhere" });
    assert.deepEqual(calls, ["GET", "POST {}"]);
  });

  it("409 concurrent_change: read the server again once; twice in a row is an error", async () => {
    const once = scripted([{ kind: "none" }, { kind: "active", issuedAt: ISSUED }], [{ kind: "concurrent_change" }]);
    const f1 = new MemberQrFlow(once.api, createQrMemory());
    await f1.open();
    assert.deepEqual(f1.getState(), { phase: "elsewhere", reason: "not_here" });
    assert.deepEqual(once.calls, ["GET", "POST {}", "GET"]);
    const twice = scripted([{ kind: "none" }, { kind: "none" }], [{ kind: "concurrent_change" }, { kind: "concurrent_change" }]);
    const f2 = new MemberQrFlow(twice.api, createQrMemory());
    await f2.open();
    assert.deepEqual(f2.getState(), { phase: "error" });
    assert.deepEqual(twice.calls, ["GET", "POST {}", "GET", "POST {}"]);
  });

  it("not a member, disabled, signed out, forbidden, unavailable, error, off", async () => {
    for (const kind of ["not_member", "member_disabled", "signed_out", "forbidden", "unavailable", "error", "off"] as const) {
      const memory = createQrMemory();
      memory.set({ token: TOKEN, issuedAt: ISSUED });
      const { api, calls } = scripted([{ kind }], []);
      const flow = new MemberQrFlow(api, memory);
      await flow.open();
      assert.deepEqual(flow.getState(), { phase: kind }, kind);
      assert.deepEqual(calls, ["GET"], kind);
    }
    const unknownCommit = scripted([{ kind: "none" }], [{ kind: "unavailable" }]);
    const flow = new MemberQrFlow(unknownCommit.api, createQrMemory());
    await flow.open();
    assert.deepEqual(flow.getState(), { phase: "unavailable" }, "an unknown COMMIT outcome is unavailable; retry starts with GET again");
  });

  it("an older reply never overwrites a newer open(); forget() drops the plaintext", async () => {
    let release: (s: QrStatus) => void = () => {};
    const memory: QrMemory = createQrMemory();
    let n = 0;
    const api: CustomerQrApi = {
      status: () => (n++ === 0 ? new Promise<QrStatus>((r) => (release = r)) : Promise.resolve({ kind: "member_disabled" })),
      issue: async () => ({ kind: "issued", token: TOKEN, issuedAt: ISSUED }),
    };
    const flow = new MemberQrFlow(api, memory);
    const first = flow.open();
    await flow.open();
    release({ kind: "none" });
    await first;
    assert.deepEqual(flow.getState(), { phase: "member_disabled" });
    assert.equal(memory.get(), null);
    memory.set({ token: TOKEN, issuedAt: ISSUED });
    flow.forget();
    assert.equal(memory.get(), null);
    assert.deepEqual(flow.getState(), { phase: "loading" });
  });
});

let dom: DomHarness;
let act: (fn: () => Promise<void>) => Promise<void>;
const storageWrites: string[] = [];

before(async () => {
  dom = await createDom();
  act = (await import("react")).act as unknown as typeof act;
  dom.window.scrollTo = () => {};
  for (const area of ["localStorage", "sessionStorage"] as const) {
    const s = dom.window[area];
    for (const m of ["setItem", "removeItem", "clear"] as const) {
      const original = s[m].bind(s);
      Object.defineProperty(s, m, { value: (...args: unknown[]) => (storageWrites.push(`${area}.${m}`), (original as (...a: unknown[]) => unknown)(...args)), configurable: true });
    }
  }
});
after(() => dom.dispose());
beforeEach(async () => {
  await dom.reset();
});

const app = () => dom.document.querySelector('[data-evc-customer="app"]') as HTMLElement;
const text = () => app().textContent ?? "";
const button = (label: string) => {
  const b = [...dom.document.querySelectorAll("button")].find((x) => x.textContent?.trim() === label);
  assert.ok(b, `button «${label}» exists`);
  return b as HTMLButtonElement;
};
const ACTIVE: Me = { displayName: "Ελένη Μαρκοπούλου", email: "eleni@example.org", state: "active", paidUntilUtc: "2026-11-15T10:00:00+00:00" };

async function mountWith(statuses: QrStatus[], issues: QrIssue[], memory = createQrMemory()) {
  const s = scripted(statuses, issues);
  const flow = new MemberQrFlow(s.api, memory);
  const api: CustomerApi = { me: async () => ({ kind: "ok", me: ACTIVE }) };
  const visited: string[] = [];
  await dom.render(createElement(CustomerApp, { config: CONFIG, api, now: () => Date.parse("2026-11-04T10:00:00Z"), navigate: (u: string) => visited.push(u), qrFlow: flow }));
  await act(async () => {});
  await act(async () => {});
  return { ...s, flow, memory, visited };
}

describe("STAGE-05D customer app: the real QR on the card", () => {
  it("a first QR is issued and drawn in the pass; the token never appears as text and nothing is stored", async () => {
    const { calls, memory } = await mountWith([{ kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }]);
    assert.deepEqual(calls, ["GET", "POST {}"]);
    const svg = app().querySelector('[data-evc-qr="member"]');
    assert.ok(svg, "the member QR is drawn");
    assert.equal(svg!.getAttribute("role"), "img");
    assert.equal(svg!.getAttribute("aria-label"), "Ο κωδικός QR της κάρτας μέλους σας");
    assert.equal(app().querySelector('[data-evc-preview="sample-qr"]'), null, "no sample code next to the real one");
    assert.doesNotMatch(dom.document.body.innerHTML, /evc_[0-9a-f]{48}/, "the plaintext is drawn, never written out");
    assert.match(text(), /Μένει ίδιος μέχρι να ζητήσετε νέο/);
    assert.match(text(), /στιγμιότυπο οθόνης/);
    assert.match(text(), /Δείξτε τον κωδικό στο ταμείο/);
    assert.doesNotMatch(text(), /δεν είναι ακόμη ενεργός/);
    assert.ok(app().querySelector('[aria-label="Κάρτα μέλους Evangelou Club"]'));
    assert.equal(memory.get()?.token, TOKEN);
    assert.deepEqual(storageWrites, []);
    assert.equal(dom.network.fetch.length + dom.network.xhr.length, 0, "only the injected client talks to the server");
  });

  it("«Νέο QR» shows a clear Greek confirmation (focus moves to it); «Άκυρο» keeps the code; «Ναι, νέο QR» replaces it", async () => {
    const { calls } = await mountWith([{ kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }, { kind: "issued", token: TOKEN2, issuedAt: ISSUED2 }]);
    await dom.click(button("Νέο QR"));
    const dialog = app().querySelector('[role="alertdialog"]') as HTMLElement;
    assert.ok(dialog);
    assert.match(dialog.textContent ?? "", /Θέλετε νέο κωδικό QR;/);
    assert.match(dialog.textContent ?? "", /θα σταματήσει να ισχύει αμέσως, σε όλες τις συσκευές/);
    assert.equal(dom.document.activeElement?.id, "evc-qr-confirm-title", "focus on the question");
    await dom.click(button("Άκυρο"));
    assert.ok(app().querySelector('[data-evc-qr="member"]'));
    assert.deepEqual(calls, ["GET", "POST {}"]);
    await dom.click(button("Νέο QR"));
    await dom.click(button("Ναι, νέο QR"));
    await act(async () => {});
    assert.deepEqual(calls, ["GET", "POST {}", "POST replace"]);
    assert.equal(app().querySelector("[data-evc-qr-state]")?.getAttribute("data-evc-qr-state"), "shown");
  });

  it("active on another device: explained, the screenshot still works, «Νέο QR» on request only", async () => {
    const { calls } = await mountWith([{ kind: "active", issuedAt: ISSUED }], []);
    assert.match(text(), /Ο κωδικός σας δεν φαίνεται σε αυτή τη συσκευή/);
    assert.match(text(), /Αν έχετε στιγμιότυπο οθόνης, ισχύει κανονικά/);
    assert.equal(app().querySelector('[data-evc-qr="member"]'), null);
    assert.deepEqual(calls, ["GET"]);
    button("Νέο QR");
  });

  it("not a member yet / disabled: explained in Greek, no code", async () => {
    await mountWith([{ kind: "not_member" }], []);
    assert.match(text(), /μόλις επιβεβαιωθεί η πληρωμή/);
    await dom.reset();
    await mountWith([{ kind: "member_disabled" }], []);
    assert.match(text(), /Η κάρτα σας δεν είναι ενεργή/);
    assert.equal(app().querySelector('[data-evc-qr="member"]'), null);
  });

  it("the QR route does not exist (feature off): the approved sample card, unchanged", async () => {
    await mountWith([{ kind: "off" }], []);
    assert.ok(app().querySelector('[data-evc-preview="sample-qr"]'));
    assert.match(text(), /Ο κωδικός QR δεν είναι ακόμη ενεργός/);
    assert.equal(app().querySelector("[data-evc-qr-state]"), null);
  });

  it("unavailable: retry starts again from GET; sign-out forgets the code", async () => {
    const memory = createQrMemory();
    const { calls, visited } = await mountWith([{ kind: "unavailable" }, { kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }], memory);
    assert.match(text(), /δεν είναι διαθέσιμος αυτή τη στιγμή/);
    await dom.click(button("Δοκιμάστε ξανά"));
    await act(async () => {});
    assert.deepEqual(calls, ["GET", "GET", "POST {}"]);
    assert.equal(memory.get()?.token, TOKEN);
    await dom.click(button("Αρχική"));
    await dom.click(button("Λογαριασμός"));
    await dom.click(button("Αποσύνδεση"));
    assert.deepEqual(visited, [CONFIG.logoutUrl]);
    assert.equal(memory.get(), null, "the plaintext is dropped at sign-out");
    assert.deepEqual(storageWrites, []);
  });
});
