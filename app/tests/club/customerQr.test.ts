import assert from "node:assert/strict";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement } from "react";
import type { CustomerConfig } from "../../src/customer/config";
import { CustomerApp } from "../../src/customer/CustomerApp";
import { createCustomerApi, createNonceBox, type CustomerApi, type Me } from "../../src/customer/customerApi";
import { MemberQrFlow, createQrKeeper } from "../../src/customer/memberQr";
import { QR_STORE_PREFIX, createStorageQrKeeper } from "../../src/customer/qrStore";
import { createCustomerQrApi, parseQrIssued, parseQrStatus, type CustomerQrApi, type QrIssue, type QrStatus } from "../../src/customer/qrApi";
import { createDom, type DomHarness } from "../staff/support/dom";

/*
 * STAGE-05D: the member's own Club QR in the REAL customer app.
 * - qrApi: GET/POST evangelou-club-customer/v1/me/qr parsed strictly; the
 *   token only ever comes from a POST 200.
 * - MemberQrFlow: the decision rules of docs/member-qr.md; a replacement
 *   only after an explicit confirmation (D6); 409 qr_exists handled.
 * - qrStore (round 2, owner «Ναι, να τον θυμάται»): the phone remembers the
 *   QR per account + issued_at, shows it only for the issued_at the server
 *   reports, and deletes it on every rule of the decision.
 * - CustomerApp: the real QR in the approved pass; the sample stays when the
 *   route does not exist; only qrStore writes browser storage; the token is
 *   never written out as text.
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

/** A Map-backed Storage (the real browser API shape). */
class MapStorage {
  map = new Map<string, string>();
  writes: string[] = [];
  get length() {
    return this.map.size;
  }
  key(i: number) {
    return [...this.map.keys()][i] ?? null;
  }
  getItem(k: string) {
    return this.map.get(k) ?? null;
  }
  setItem(k: string, v: string) {
    this.writes.push(`set ${k}`);
    this.map.set(k, String(v));
  }
  removeItem(k: string) {
    this.writes.push(`remove ${k}`);
    this.map.delete(k);
  }
  ours() {
    return [...this.map.keys()].filter((k) => k.startsWith(QR_STORE_PREFIX));
  }
}

const ME = "Eleni@Example.org";
const OTHER = "nikos@example.org";
const subtle = globalThis.crypto.subtle;

function persisted() {
  const storage = new MapStorage();
  const store = createStorageQrKeeper(storage as unknown as Storage, subtle)!;
  return { storage, store, keeper: createQrKeeper(store) };
}

describe("STAGE-05D round 2: the phone's QR record (qrStore.ts)", () => {
  it("kept per account + issued_at, never in the clear; read only for the exact issued_at", async () => {
    const { storage, store } = persisted();
    await store.write(ME, ISSUED, TOKEN);
    assert.equal(storage.ours().length, 1);
    const [key] = storage.ours();
    assert.match(key, /^evc-club-qr:v1:[0-9a-f]{64}$/);
    const raw = storage.getItem(key)!;
    assert.doesNotMatch(raw + key, /eleni|example|2026-11/i, "no e-mail and no date in the clear");
    assert.deepEqual(Object.keys(JSON.parse(raw)).sort(), ["a", "t", "v"]);
    assert.equal(await store.read("eleni@example.org", ISSUED), TOKEN, "the e-mail is case-insensitive");
    assert.equal(await store.read(OTHER, ISSUED), null, "another account never reads it");
    assert.equal(storage.ours().length, 1, "and never deletes it");
  });

  it("a different issued_at shows nothing and deletes the old record", async () => {
    const { storage, store } = persisted();
    await store.write(ME, ISSUED, TOKEN);
    await store.write(OTHER, ISSUED, TOKEN2);
    assert.equal(await store.read(ME, ISSUED2), null);
    assert.equal(storage.ours().length, 1, "only the other account's record is left");
    assert.equal(await store.read(OTHER, ISSUED), TOKEN2);
  });

  it("a new record replaces the account's older one; forget / forgetAll; a tampered record is never shown", async () => {
    const { storage, store } = persisted();
    await store.write(ME, ISSUED, TOKEN);
    await store.write(ME, ISSUED2, TOKEN2);
    assert.equal(storage.ours().length, 1);
    assert.equal(await store.read(ME, ISSUED2), TOKEN2);
    await store.write(OTHER, ISSUED, TOKEN);
    await store.forget(ME);
    assert.equal(storage.ours().length, 1);
    await store.forgetAll();
    assert.equal(storage.ours().length, 0);
    storage.setItem("unrelated", "kept");
    await store.write(ME, ISSUED, TOKEN);
    const [key] = storage.ours();
    storage.setItem(key, JSON.stringify({ v: 1, a: JSON.parse(storage.getItem(key)!).a, t: "evc_not-a-token" }));
    assert.equal(await store.read(ME, ISSUED), null);
    assert.equal(storage.ours().length, 0, "the bad record is deleted");
    assert.equal(storage.getItem("unrelated"), "kept", "keys that are not ours are never touched");
  });

  it("no SHA-256 or no working storage: nothing is persisted (memory only); storage errors never throw", async () => {
    assert.equal(createStorageQrKeeper(new MapStorage() as unknown as Storage, null), null);
    assert.equal(createStorageQrKeeper(null, subtle), null);
    const broken = { length: 1, key: () => { throw new Error("x"); }, getItem: () => { throw new Error("x"); }, setItem: () => { throw new Error("quota"); }, removeItem: () => { throw new Error("x"); } };
    const store = createStorageQrKeeper(broken as unknown as Storage, subtle)!;
    await store.write(ME, ISSUED, TOKEN);
    assert.equal(await store.read(ME, ISSUED), null);
    await store.forget(ME);
    await store.forgetAll();
  });
});

describe("STAGE-05D member QR flow (docs/member-qr.md decision rules)", () => {
  it("none: POST {} once, show the new QR and keep it on the phone", async () => {
    const { storage, keeper } = persisted();
    const { api, calls } = scripted([{ kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }]);
    const flow = new MemberQrFlow(api, keeper);
    await flow.open(ME);
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: true });
    assert.deepEqual(calls, ["GET", "POST {}"]);
    assert.equal(storage.ours().length, 1);
  });

  it("a LATER visit (new page, same phone): active with the same issued_at shows the remembered QR, no POST", async () => {
    const first = persisted();
    await new MemberQrFlow(scripted([{ kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }]).api, first.keeper).open(ME);
    const store = createStorageQrKeeper(first.storage as unknown as Storage, subtle)!;
    const { api, calls } = scripted([{ kind: "active", issuedAt: ISSUED }], []);
    const flow = new MemberQrFlow(api, createQrKeeper(store));
    await flow.open(ME);
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: false });
    assert.deepEqual(calls, ["GET"]);
  });

  it("active with a different issued_at (replaced elsewhere, revoked and re-issued): not shown, the record is deleted", async () => {
    const { storage, keeper } = persisted();
    await keeper.write(ME, ISSUED, TOKEN);
    const { api, calls } = scripted([{ kind: "active", issuedAt: ISSUED2 }], []);
    const flow = new MemberQrFlow(api, createQrKeeper(createStorageQrKeeper(storage as unknown as Storage, subtle)));
    await flow.open(ME);
    assert.deepEqual(flow.getState(), { phase: "elsewhere", reason: "not_here" });
    assert.deepEqual(calls, ["GET"], "no POST without the member's request");
    assert.equal(storage.ours().length, 0, "a dead code is never kept");
    // The same with the old QR still in this page's memory (shown earlier in this visit).
    const again = persisted();
    await again.keeper.write(ME, ISSUED, TOKEN);
    const f2 = new MemberQrFlow(scripted([{ kind: "active", issuedAt: ISSUED2 }], []).api, again.keeper);
    await f2.open(ME);
    assert.deepEqual(f2.getState(), { phase: "elsewhere", reason: "not_here" });
    assert.equal(again.keeper.memory(), null, "the in-memory copy is dropped too");
  });

  it("delete rules: signed_out, not_member, member_disabled, forbidden, route off and none", async () => {
    for (const [kind, othersKept] of [["signed_out", 0], ["not_member", 1], ["member_disabled", 1], ["forbidden", 1], ["off", 0], ["none", 1]] as const) {
      const { storage, keeper } = persisted();
      await keeper.write(OTHER, ISSUED, TOKEN2);
      await keeper.write(ME, ISSUED, TOKEN);
      const { api } = scripted([{ kind }], kind === "none" ? [{ kind: "unavailable" }] : []);
      const flow = new MemberQrFlow(api, keeper);
      await flow.open(ME);
      assert.equal(storage.ours().length, othersKept, `${kind}: the account's record is deleted`);
      assert.equal(keeper.memory(), null, `${kind}: nor kept in memory`);
    }
  });

  it("unavailable / error keep the record (a passing failure says nothing about the QR) and show no code", async () => {
    for (const kind of ["unavailable", "error"] as const) {
      const { storage, keeper } = persisted();
      await keeper.write(ME, ISSUED, TOKEN);
      const flow = new MemberQrFlow(scripted([{ kind }], []).api, keeper);
      await flow.open(ME);
      assert.deepEqual(flow.getState(), { phase: kind });
      assert.equal(storage.ours().length, 1);
    }
  });

  it("«Νέο QR» asks first; cancel goes back; confirm deletes the old record BEFORE POST replace: true", async () => {
    const { storage, keeper } = persisted();
    const { api, calls } = scripted([{ kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }, { kind: "unavailable" }, { kind: "issued", token: TOKEN2, issuedAt: ISSUED2 }]);
    const flow = new MemberQrFlow(api, keeper);
    await flow.open(ME);
    flow.askReplace();
    assert.deepEqual(flow.getState(), { phase: "confirm_replace", back: "shown" });
    flow.cancelReplace();
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: true });
    assert.deepEqual(calls, ["GET", "POST {}"], "cancel sends nothing");
    flow.askReplace();
    await flow.confirmReplace();
    assert.deepEqual(flow.getState(), { phase: "unavailable" });
    assert.equal(storage.ours().length, 0, "the old code is gone even though the new one did not arrive");
    await flow.open(ME);
    flow.askReplace();
    assert.equal(flow.getState().phase, "error", "open() with a GET error: nothing to replace from");
  });

  it("confirm replace: the new QR is shown and kept", async () => {
    const { storage, keeper } = persisted();
    const { api, calls } = scripted([{ kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }, { kind: "issued", token: TOKEN2, issuedAt: ISSUED2 }]);
    const flow = new MemberQrFlow(api, keeper);
    await flow.open(ME);
    flow.askReplace();
    await flow.confirmReplace();
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN2, issuedAt: ISSUED2, fresh: true });
    assert.deepEqual(calls, ["GET", "POST {}", "POST replace"]);
    assert.equal(await createStorageQrKeeper(storage as unknown as Storage, subtle)!.read(ME, ISSUED2), TOKEN2);
    await flow.confirmReplace();
    assert.equal(calls.length, 3, "confirm without a question does nothing");
  });

  it("409 qr_exists after GET none (another device was first): explained, nothing replaced", async () => {
    const { api, calls } = scripted([{ kind: "none" }], [{ kind: "qr_exists" }]);
    const flow = new MemberQrFlow(api, createQrKeeper());
    await flow.open(ME);
    assert.deepEqual(flow.getState(), { phase: "elsewhere", reason: "created_elsewhere" });
    flow.askReplace();
    assert.deepEqual(flow.getState(), { phase: "confirm_replace", back: "elsewhere" });
    assert.deepEqual(calls, ["GET", "POST {}"]);
  });

  it("409 concurrent_change: read the server again once; twice in a row is an error", async () => {
    const once = scripted([{ kind: "none" }, { kind: "active", issuedAt: ISSUED }], [{ kind: "concurrent_change" }]);
    const f1 = new MemberQrFlow(once.api, createQrKeeper());
    await f1.open(ME);
    assert.deepEqual(f1.getState(), { phase: "elsewhere", reason: "not_here" });
    assert.deepEqual(once.calls, ["GET", "POST {}", "GET"]);
    const twice = scripted([{ kind: "none" }, { kind: "none" }], [{ kind: "concurrent_change" }, { kind: "concurrent_change" }]);
    const f2 = new MemberQrFlow(twice.api, createQrKeeper());
    await f2.open(ME);
    assert.deepEqual(f2.getState(), { phase: "error" });
  });

  it("an older reply never overwrites a newer open(); forget() drops memory and the phone's record", async () => {
    let release: (s: QrStatus) => void = () => {};
    const { storage, keeper } = persisted();
    let n = 0;
    const api: CustomerQrApi = {
      status: () => (n++ === 0 ? new Promise<QrStatus>((r) => (release = r)) : Promise.resolve({ kind: "member_disabled" })),
      issue: async () => ({ kind: "issued", token: TOKEN, issuedAt: ISSUED }),
    };
    const flow = new MemberQrFlow(api, keeper);
    const first = flow.open(ME);
    await flow.open(ME);
    release({ kind: "none" });
    await first;
    assert.deepEqual(flow.getState(), { phase: "member_disabled" });
    await keeper.write(ME, ISSUED, TOKEN);
    await flow.forget();
    assert.equal(keeper.memory(), null);
    assert.equal(storage.ours().length, 0);
    assert.deepEqual(flow.getState(), { phase: "loading" });
    assert.equal(flow.seenRoute(), true);
  });
});

let dom: DomHarness;
let act: (fn: () => Promise<void>) => Promise<void>;

before(async () => {
  dom = await createDom();
  act = (await import("react")).act as unknown as typeof act;
  dom.window.scrollTo = () => {};
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
/** SHA-256 (WebCrypto) resolves on Node's thread pool: let real time pass inside act(). */
async function settle(rounds = 4) {
  for (let i = 0; i < rounds; i++) await act(async () => void (await new Promise((r) => setTimeout(r, 15))));
}
const ACTIVE: Me = { displayName: "Ελένη Μαρκοπούλου", email: ME, state: "active", paidUntilUtc: "2026-11-15T10:00:00+00:00" };

async function mountWith(statuses: (QrStatus | (() => Promise<QrStatus>))[], issues: QrIssue[], ctx = persisted()) {
  const calls: string[] = [];
  const api: CustomerQrApi = {
    async status() {
      calls.push("GET");
      const s = statuses.shift() ?? { kind: "error" };
      return typeof s === "function" ? s() : s;
    },
    async issue(replace) {
      calls.push(replace ? "POST replace" : "POST {}");
      return issues.shift() ?? { kind: "error" };
    },
  };
  const flow = new MemberQrFlow(api, ctx.keeper);
  const me: CustomerApi = { me: async () => ({ kind: "ok", me: ACTIVE }) };
  const visited: string[] = [];
  await dom.render(createElement(CustomerApp, { config: CONFIG, api: me, now: () => Date.parse("2026-11-04T10:00:00Z"), navigate: (u: string) => visited.push(u), qrFlow: flow }));
  await settle();
  return { calls, flow, visited, ...ctx };
}

describe("STAGE-05D customer app: the real QR on the card", () => {
  it("a first QR is issued, drawn in a white frame and remembered; the token never appears as text", async () => {
    const { calls, storage } = await mountWith([{ kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }]);
    assert.deepEqual(calls, ["GET", "POST {}"]);
    const svg = app().querySelector('[data-evc-qr="member"]');
    assert.ok(svg, "the member QR is drawn");
    assert.equal(svg!.getAttribute("role"), "img");
    assert.equal(svg!.getAttribute("aria-label"), "Ο κωδικός QR της κάρτας μέλους σας");
    assert.match(svg!.parentElement!.className, /bg-white/, "the white padded frame");
    assert.ok((app().querySelector('[data-evc-preview="sample-qr"]')) === (null), "no sample code next to the real one");
    assert.doesNotMatch(dom.document.body.innerHTML, /evc_[0-9a-f]{48}/, "the plaintext is drawn, never written out");
    assert.match(text(), /Μένει ίδιος μέχρι να ζητήσετε νέο/);
    assert.match(text(), /στιγμιότυπο οθόνης/);
    assert.match(text(), /Δείξτε τον κωδικό στο ταμείο/);
    assert.ok(app().querySelector('[aria-label="Κάρτα μέλους Evangelou Club"]'));
    assert.equal(storage.ours().length, 1, "the phone remembers it");
    assert.ok(app().querySelector('[role="status"]') && app().querySelector('[role="alert"]'), "two fixed live regions");
  });

  it("«Νέο QR» shows a clear Greek confirmation (focus moves to it, not inside a live region); «Άκυρο» keeps the code; «Ναι, νέο QR» replaces it", async () => {
    const { calls } = await mountWith([{ kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }, { kind: "issued", token: TOKEN2, issuedAt: ISSUED2 }]);
    await dom.click(button("Νέο QR"));
    const dialog = app().querySelector('[role="alertdialog"]') as HTMLElement;
    assert.ok(dialog);
    assert.ok((dialog.closest("[aria-live]")) === (null), "the dialog is not inside a live region");
    assert.match(dialog.textContent ?? "", /Θέλετε νέο κωδικό QR;/);
    assert.match(dialog.textContent ?? "", /θα σταματήσει να ισχύει αμέσως, σε όλες τις συσκευές/);
    assert.equal(dom.document.activeElement?.id, "evc-qr-confirm-title", "focus on the question");
    await dom.click(button("Άκυρο"));
    assert.ok(app().querySelector('[data-evc-qr="member"]'));
    assert.deepEqual(calls, ["GET", "POST {}"]);
    await dom.click(button("Νέο QR"));
    await dom.click(button("Ναι, νέο QR"));
    await settle();
    assert.deepEqual(calls, ["GET", "POST {}", "POST replace"]);
    assert.equal(app().querySelector("[data-evc-qr-state]")?.getAttribute("data-evc-qr-state"), "shown");
  });

  it("the next visit shows the remembered QR without asking the server for a new one", async () => {
    const ctx = persisted();
    await ctx.keeper.write(ME, ISSUED, TOKEN);
    const fresh = { ...ctx, keeper: createQrKeeper(createStorageQrKeeper(ctx.storage as unknown as Storage, subtle)) };
    const { calls } = await mountWith([{ kind: "active", issuedAt: ISSUED }], [], fresh);
    assert.deepEqual(calls, ["GET"]);
    assert.ok(app().querySelector('[data-evc-qr="member"]'));
  });

  it("active on another device: explained, the screenshot still works, «Νέο QR» on request only", async () => {
    const { calls } = await mountWith([{ kind: "active", issuedAt: ISSUED }], []);
    assert.match(text(), /Ο κωδικός σας δεν φαίνεται σε αυτή τη συσκευή/);
    assert.match(text(), /Αν έχετε στιγμιότυπο οθόνης, ισχύει κανονικά/);
    assert.ok((app().querySelector('[data-evc-qr="member"]')) === (null), "app().querySelector('[data-evc-qr=\"member\"]') === null");
    assert.deepEqual(calls, ["GET"]);
    button("Νέο QR");
  });

  it("not a member yet / disabled: explained in Greek, no code", async () => {
    await mountWith([{ kind: "not_member" }], []);
    assert.match(text(), /μόλις επιβεβαιωθεί η πληρωμή/);
    await dom.reset();
    await mountWith([{ kind: "member_disabled" }], []);
    assert.match(text(), /Η κάρτα σας δεν είναι ενεργή/);
    assert.ok((app().querySelector('[data-evc-qr="member"]')) === (null), "app().querySelector('[data-evc-qr=\"member\"]') === null");
  });

  it("the route does not exist (feature off): the approved sample card, never replaced by «Φόρτωση…»", async () => {
    let answer: (s: QrStatus) => void = () => {};
    await mountWith([() => new Promise<QrStatus>((r) => (answer = r))], []);
    assert.ok(app().querySelector('[data-evc-preview="sample-qr"]'), "while the first GET is pending: the sample");
    assert.doesNotMatch(text(), /Φόρτωση του κωδικού σας/);
    await act(async () => answer({ kind: "off" }));
    await settle();
    assert.ok(app().querySelector('[data-evc-preview="sample-qr"]'));
    assert.match(text(), /Ο κωδικός QR δεν είναι ακόμη ενεργός/);
    assert.ok((app().querySelector("[data-evc-qr-state]")) === (null), "app().querySelector(\"[data-evc-qr-state]\") === null");
  });

  it("unavailable: retry starts again from GET; sign-out deletes the remembered code before WordPress signs out", async () => {
    const ctx = persisted();
    const { calls, visited, storage } = await mountWith([{ kind: "unavailable" }, { kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }], ctx);
    assert.match(text(), /δεν είναι διαθέσιμος αυτή τη στιγμή/);
    await dom.click(button("Δοκιμάστε ξανά"));
    await settle();
    assert.deepEqual(calls, ["GET", "GET", "POST {}"]);
    assert.equal(storage.ours().length, 1);
    await dom.click(button("Αρχική"));
    await dom.click(button("Λογαριασμός"));
    await dom.click(button("Αποσύνδεση"));
    await settle();
    assert.deepEqual(visited, [CONFIG.logoutUrl]);
    assert.equal(storage.ours().length, 0, "deleted at sign-out");
    assert.equal(ctx.keeper.memory(), null, "and the in-memory copy too");
  });
});
