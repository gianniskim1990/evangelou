import assert from "node:assert/strict";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement } from "react";
import type { CustomerConfig } from "../../src/customer/config";
import { CustomerApp } from "../../src/customer/CustomerApp";
import { createCustomerApi, createNonceBox, type CustomerApi, type Me } from "../../src/customer/customerApi";
import { MemberQrFlow, QR_MAX_ISSUES_PER_MINUTE, QR_MAX_REQUESTS_PER_MINUTE, QR_RETRY_DELAYS_MS, createQrKeeper, type QrFlowClock, type QrKeeper } from "../../src/customer/memberQr";
import { QR_STORE_PREFIX, createStorageQrKeeper } from "../../src/customer/qrStore";
import { createCustomerQrApi, parseQrIssued, parseQrStatus, type CustomerQrApi, type QrIssue, type QrStatus } from "../../src/customer/qrApi";
import { createDom, type DomHarness } from "../staff/support/dom";
import { MemberQrPanel } from "../../src/customer/MemberQrPanel";

/*
 * STAGE-05D: the member's own Club QR in the REAL customer app.
 * - qrApi: GET/POST evangelou-club-customer/v1/me/qr parsed strictly; the
 *   token only ever comes from a POST 200.
 * - MemberQrFlow: the decision rules of docs/member-qr.md; owner decision
 *   D21 (2026-11-19): no «Νέο QR»; D22 (2026-11-19): the same QR on every
 *   device, the client only ever sends POST {} (never a replace); errors
 *   retry with backoff under a hard per-minute cap; round 7: a failed check
 *   never takes a shown QR off the screen, the phone's copy is shown as
 *   «unchecked» while the server cannot be reached, retries pause off the card.
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
    assert.deepEqual(await qr.issue(), { kind: "issued", token: TOKEN, issuedAt: ISSUED });
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
    // D22: the client has no way to ask for a replacement; every POST body is exactly {}.
    const again = fakeFetch([{ status: 200, body: { result: "ok", qr: { state: "active", token: TOKEN, issued_at: ISSUED } } }]);
    const api = createCustomerQrApi(CONFIG, box, again.impl) as unknown as { issue(...a: unknown[]): Promise<QrIssue> };
    await api.issue(true);
    await api.issue({ replace: true });
    assert.deepEqual(again.calls.map((c) => c.init.body), ["{}", "{}"], "never a replace, whatever is passed");
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
      assert.deepEqual(await createCustomerQrApi(CONFIG, createNonceBox(CONFIG.nonce), f.impl).issue(), want, JSON.stringify(reply));
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
    // D22: the API takes no argument; anything passed (a reintroduced `replace`) shows up as "POST replace".
    async issue(...args: unknown[]) {
      calls.push(args.length > 0 && args[0] !== false ? "POST replace" : "POST {}");
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

/** A manual clock for MemberQrFlow: timers fire only inside advance(). */
function fakeClock() {
  let t = 1_000_000;
  let id = 0;
  const timers = new Map<number, { at: number; fn: () => void }>();
  const clock: QrFlowClock & { pending(): number[]; advance(ms: number): Promise<void> } = {
    now: () => t,
    setTimer(fn, ms) {
      timers.set(++id, { at: t + ms, fn });
      return id;
    },
    clearTimer(h) {
      timers.delete(h as number);
    },
    pending: () => [...timers.values()].map((x) => x.at - t),
    async advance(ms) {
      const end = t + ms;
      for (;;) {
        const due = [...timers.entries()].filter(([, x]) => x.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
        if (!due) break;
        t = due[1].at;
        timers.delete(due[0]);
        due[1].fn();
        await flush();
      }
      t = end;
      await flush();
    },
  };
  return clock;
}
/** Lets the flow's promise chains (memory keeper only: no thread-pool hashing) run to the end. */
async function flush() {
  for (let i = 0; i < 30; i++) await new Promise((r) => setImmediate(r));
}

/** Round 7: waits (real time, bounded) for work that runs SHA-256 on Node's thread pool, which a loaded CI runner
 *  may not finish within flush()'s fixed number of turns. */
async function eventually(check: () => boolean, what: string) {
  for (let i = 0; i < 400 && !check(); i++) await new Promise((r) => setTimeout(r, 5));
  assert.ok(check(), `in time: ${what}`);
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
    assert.equal(storage.ours().length, 0, "round 4, shared phone: another account's sign-in deletes it");
  });

  it("a different issued_at shows nothing and deletes the old record", async () => {
    const { storage, store } = persisted();
    await store.write(ME, ISSUED, TOKEN);
    assert.equal(await store.read(ME, ISSUED2), null);
    assert.equal(storage.ours().length, 0);
  });

  it("round 4, shared phone: a QR shown for another account replaces every earlier member's record", async () => {
    const { storage, store } = persisted();
    await store.write(ME, ISSUED, TOKEN);
    await store.write(OTHER, ISSUED2, TOKEN2);
    assert.equal(storage.ours().length, 1, "at most one QR on the phone");
    assert.equal(await store.read(ME, ISSUED), null, "the first member's token is gone");
    assert.equal(await store.read(OTHER, ISSUED2), null, "(and that read, as a sign-in of the first member, removed the other one)");
    assert.equal(storage.ours().length, 0);
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

  it("D22: active with a different issued_at (revoked and re-issued, or kept from an older QR): the old record goes, then POST {} (never a replace)", async () => {
    const { storage, keeper } = persisted();
    await keeper.write(ME, ISSUED, TOKEN);
    const order: string[] = [];
    const { api, calls } = scripted([{ kind: "active", issuedAt: ISSUED2 }], [{ kind: "unavailable" }]);
    const watched: CustomerQrApi = { status: api.status, issue: () => (order.push(`POST with ${storage.ours().length} kept`), api.issue()) };
    const flow = new MemberQrFlow(watched, createQrKeeper(createStorageQrKeeper(storage as unknown as Storage, subtle)), fakeClock());
    await flow.open(ME);
    assert.deepEqual(calls, ["GET", "POST {}"], "the member's own stable QR is asked for; nothing is replaced");
    assert.deepEqual(order, ["POST with 0 kept"], "the dead code is deleted BEFORE the request");
    assert.deepEqual(flow.getState(), { phase: "unavailable" });
    assert.equal(storage.ours().length, 0, "a dead code is never kept");
    // The same with the old QR still in this page's memory (shown earlier in this visit).
    const again = persisted();
    await again.keeper.write(ME, ISSUED, TOKEN);
    const f2 = new MemberQrFlow(scripted([{ kind: "active", issuedAt: ISSUED2 }], [{ kind: "issued", token: TOKEN2, issuedAt: ISSUED2 }]).api, again.keeper, fakeClock());
    await f2.open(ME);
    assert.deepEqual(f2.getState(), { phase: "shown", token: TOKEN2, issuedAt: ISSUED2, fresh: true });
    assert.deepEqual(again.keeper.memory(), { account: ME.toLowerCase(), issuedAt: ISSUED2, token: TOKEN2 }, "only the new code in memory");
    assert.equal(again.storage.ours().length, 1, "and on the phone");
  });

  it("delete rules: signed_out, not_member, member_disabled, forbidden, route off and none", async () => {
    for (const [kind, othersKept] of [["signed_out", 0], ["not_member", 0], ["member_disabled", 0], ["forbidden", 0], ["off", 0], ["none", 0]] as const) {
      const { storage, keeper } = persisted();
      await keeper.write(OTHER, ISSUED, TOKEN2);
      await keeper.write(ME, ISSUED, TOKEN);
      const { api } = scripted([{ kind }], kind === "none" ? [{ kind: "unavailable" }] : []);
      const flow = new MemberQrFlow(api, keeper, fakeClock());
      await flow.open(ME);
      assert.equal(storage.ours().length, othersKept, `${kind}: the account's record is deleted`);
      assert.equal(keeper.memory(), null, `${kind}: nor kept in memory`);
    }
  });

  it("round 7: unavailable / error keep the record (a passing failure says nothing about the QR) and show it as unchecked", async () => {
    for (const kind of ["unavailable", "error"] as const) {
      const { storage, keeper } = persisted();
      await keeper.write(ME, ISSUED, TOKEN);
      const clock = fakeClock();
      const flow = new MemberQrFlow(scripted([{ kind }], []).api, keeper, clock);
      await flow.open(ME);
      assert.deepEqual(flow.getState(), { phase: "unchecked", token: TOKEN }, `${kind}: the phone's own copy, not claimed as checked`);
      assert.equal(storage.ours().length, 1, "nothing deleted");
      assert.deepEqual(clock.pending(), [QR_RETRY_DELAYS_MS[0]], "and checked again silently");
    }
    for (const kind of ["unavailable", "error"] as const) {
      const flow = new MemberQrFlow(scripted([{ kind }], []).api, persisted().keeper, fakeClock());
      await flow.open(ME);
      assert.deepEqual(flow.getState(), { phase: kind }, `${kind}: nothing kept, nothing shown`);
    }
  });

  it("D21: the flow has no «Νέο QR» question any more", () => {
    const flow = new MemberQrFlow(scripted([], []).api, createQrKeeper(), fakeClock()) as unknown as Record<string, unknown>;
    for (const gone of ["askReplace", "cancelReplace", "confirmReplace"]) assert.equal(flow[gone], undefined, gone);
  });

  it("409 qr_exists after GET none (another device or tab was first): read the server again once and follow the rules", async () => {
    const kept = scripted([{ kind: "none" }, { kind: "active", issuedAt: ISSUED }], [{ kind: "qr_exists" }]);
    const { storage, keeper } = persisted();
    const flow = new MemberQrFlow(kept.api, keeper, fakeClock());
    // The other tab of this phone wrote its new QR while this tab's POST was refused.
    const otherTab = createStorageQrKeeper(storage as unknown as Storage, subtle)!;
    const issue = kept.api.issue;
    kept.api.issue = async () => {
      const out = await issue();
      await otherTab.write(ME, ISSUED, TOKEN);
      return out;
    };
    await flow.open(ME);
    assert.deepEqual(kept.calls, ["GET", "POST {}", "GET"], "the other tab's code is used, nothing replaced");
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: false });
    const elsewhere = scripted([{ kind: "none" }, { kind: "active", issuedAt: ISSUED }], [{ kind: "qr_exists" }, { kind: "issued", token: TOKEN2, issuedAt: ISSUED2 }]);
    const f2 = new MemberQrFlow(elsewhere.api, createQrKeeper(), fakeClock());
    await f2.open(ME);
    assert.deepEqual(elsewhere.calls, ["GET", "POST {}", "GET", "POST {}"], "D22: POST {} again gives the member's own QR; never a replace");
    assert.deepEqual(f2.getState(), { phase: "shown", token: TOKEN2, issuedAt: ISSUED2, fresh: true });
  });

  it("409 concurrent_change: read the server again once; twice in a row is an error (then the backoff)", async () => {
    const once = scripted([{ kind: "none" }, { kind: "active", issuedAt: ISSUED }], [{ kind: "concurrent_change" }, { kind: "issued", token: TOKEN, issuedAt: ISSUED }]);
    const f1 = new MemberQrFlow(once.api, createQrKeeper(), fakeClock());
    await f1.open(ME);
    assert.deepEqual(once.calls, ["GET", "POST {}", "GET", "POST {}"]);
    assert.equal(f1.getState().phase, "shown");
    const twice = scripted([{ kind: "none" }, { kind: "none" }], [{ kind: "concurrent_change" }, { kind: "concurrent_change" }]);
    const f2 = new MemberQrFlow(twice.api, createQrKeeper(), fakeClock());
    await f2.open(ME);
    assert.deepEqual(f2.getState(), { phase: "error" });
  });

  it("MAJOR 1 race: sign-out while the new QR is still being written leaves NOTHING on the phone", async () => {
    const { storage, keeper } = persisted();
    let release: (v: QrIssue) => void = () => {};
    const api: CustomerQrApi = { status: async () => ({ kind: "none" }), issue: () => new Promise<QrIssue>((r) => (release = r)) };
    const flow = new MemberQrFlow(api, keeper);
    const opening = flow.open(ME);
    await new Promise((r) => setTimeout(r, 20));
    release({ kind: "issued", token: TOKEN, issuedAt: ISSUED });
    // Let show() start the write (it is now hashing on the thread pool), then tap «Αποσύνδεση».
    for (let i = 0; i < 3; i++) await Promise.resolve();
    const before = storage.writes.length;
    await flow.forget();
    assert.equal(storage.ours().length, 0, "nothing when sign-out resolves (CustomerApp navigates right then)");
    await opening;
    await new Promise((r) => setTimeout(r, 50));
    assert.equal(storage.ours().length, 0, "and nothing a moment later: the pending write never lands");
    assert.ok(!storage.writes.slice(before).some((w) => w.startsWith("set ")), "no setItem after the forget started");
    assert.equal(keeper.memory(), null);
  });

  it("race2 (review of 5d8e1c6): a read still in flight at sign-out never puts the token back into memory", async () => {
    for (let spins = 0; spins < 30; spins++) {
      const storage = new MapStorage();
      await createStorageQrKeeper(storage as unknown as Storage, subtle)!.write(ME, ISSUED, TOKEN);
      const keeper = createQrKeeper(createStorageQrKeeper(storage as unknown as Storage, subtle));
      const flow = new MemberQrFlow({ status: async () => ({ kind: "active", issuedAt: ISSUED }), issue: async () => ({ kind: "error" }) }, keeper);
      const opening = flow.open(ME);
      for (let i = 0; i < spins; i++) await Promise.resolve();
      await flow.forget();
      await opening;
      await new Promise((r) => setTimeout(r, 5));
      assert.equal(keeper.memory(), null, `spins ${spins}: memory`);
      assert.equal(storage.ours().length, 0, `spins ${spins}: phone`);
      assert.notEqual(flow.getState().phase, "shown", `spins ${spins}: not shown`);
    }
  });

  it("race2, deterministic: a phone read that resolves just before a sign-out never refills memory", async () => {
    let resolveRead: (t: string | null) => void = () => {};
    const persistent: QrKeeper = {
      read: () => new Promise<string | null>((r) => (resolveRead = r)),
      write: async () => {},
      forget: async () => {},
      forgetAll: async () => {},
    };
    const keeper = createQrKeeper(persistent);
    const reading = keeper.read(ME, ISSUED);
    resolveRead(TOKEN); // the phone answered with the token...
    await keeper.forgetAll(); // ...and the sign-out runs before this tab's read continues
    assert.equal(await reading, null, "the read is void");
    assert.equal(keeper.memory(), null, "nothing came back into memory");
  });

  it("round 4: another tab's sign-out (its storage event) drops this tab's copy and asks the server again", async () => {
    const storage = new MapStorage();
    let fire: () => void = () => {};
    const store = createStorageQrKeeper(storage as unknown as Storage, subtle, (onRemoved) => (fire = onRemoved))!;
    const keeper = createQrKeeper(store);
    const { api, calls } = scripted([{ kind: "none" }, { kind: "signed_out" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }]);
    const flow = new MemberQrFlow(api, keeper);
    await flow.open(ME);
    assert.equal(flow.getState().phase, "shown");
    storage.map.clear(); // the other tab's forgetAll
    fire();
    assert.equal(keeper.memory(), null, "the in-memory copy is dropped at once");
    await new Promise((r) => setTimeout(r, 20));
    assert.deepEqual(calls, ["GET", "POST {}", "GET"], "and the server is asked again");
    assert.deepEqual(flow.getState(), { phase: "signed_out" });
  });

  it("round 4: the browser storage event filter: only our keys being removed (or a clear) count", () => {
    let handler: ((e: Event) => void) | null = null;
    const saved = globalThis.addEventListener;
    (globalThis as unknown as { addEventListener: unknown }).addEventListener = (type: string, h: (e: Event) => void) => {
      if (type === "storage") handler = h;
    };
    try {
      let n = 0;
      createStorageQrKeeper(new MapStorage() as unknown as Storage, subtle)!.onExternalChange!(() => n++);
      assert.ok(handler);
      const fire = (key: string | null, newValue: string | null) => handler!(Object.assign(new Event("storage"), { key, newValue }));
      fire(`${QR_STORE_PREFIX}abc`, null);
      fire(null, null);
      fire(`${QR_STORE_PREFIX}abc`, "{}");
      fire("someone-else", null);
      assert.equal(n, 2);
    } finally {
      (globalThis as unknown as { addEventListener: unknown }).addEventListener = saved;
    }
  });

  it("a write started before forget(account) is void; a write started after it is kept", async () => {
    const storage = new MapStorage();
    const store = createStorageQrKeeper(storage as unknown as Storage, subtle)!;
    const early = store.write(ME, ISSUED, TOKEN);
    await store.forget(ME);
    await early;
    assert.equal(storage.ours().length, 0);
    await store.write(ME, ISSUED2, TOKEN2);
    assert.equal(await store.read(ME, ISSUED2), TOKEN2);
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
  });
});

describe("STAGE-08 round 6/7, owner decisions D21 + D22: the QR is always shown, the same on every device (rate-capped)", () => {
  it("D22: nothing kept on this device while another device shows the QR: POST {} gives the SAME QR here; a first QR is created", async () => {
    const elsewhere = scripted([{ kind: "active", issuedAt: ISSUED }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }]);
    const f1 = new MemberQrFlow(elsewhere.api, createQrKeeper(), fakeClock());
    await f1.open(ME);
    assert.deepEqual(elsewhere.calls, ["GET", "POST {}"], "never a replace");
    assert.deepEqual(f1.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: true });
    const first = scripted([{ kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }]);
    const f2 = new MemberQrFlow(first.api, createQrKeeper(), fakeClock());
    await f2.open(ME);
    assert.deepEqual(first.calls, ["GET", "POST {}"], "POST {} never replaces anything");
    assert.equal(f2.getState().phase, "shown");
  });

  it("a kept QR that is still the active one is shown without any POST, and no timer renews it (the server sets no expiry)", async () => {
    const keeper = createQrKeeper();
    await keeper.write(ME, ISSUED, TOKEN);
    const clock = fakeClock();
    const { api, calls } = scripted([{ kind: "active", issuedAt: ISSUED }], []);
    const flow = new MemberQrFlow(api, keeper, clock);
    await flow.open(ME);
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: false });
    await clock.advance(30 * 60_000);
    assert.deepEqual(calls, ["GET"], "nothing is sent while the page just stays open");
    assert.deepEqual(clock.pending(), []);
  });

  it("visible again: asks the server (at most every 30 s), keeps the QR on screen meanwhile, and follows an administrator's revocation", async () => {
    const clock = fakeClock();
    const { api, calls } = scripted(
      [{ kind: "none" }, { kind: "active", issuedAt: ISSUED }, { kind: "active", issuedAt: ISSUED2 }],
      [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }, { kind: "issued", token: TOKEN2, issuedAt: ISSUED2 }],
    );
    const flow = new MemberQrFlow(api, createQrKeeper(), clock);
    await flow.open(ME);
    const phases: string[] = [];
    flow.subscribe(() => phases.push(flow.getState().phase));
    await clock.advance(10_000);
    flow.wake();
    await flush();
    assert.deepEqual(calls, ["GET", "POST {}"], "10 s later: too soon, nothing sent");
    await clock.advance(20_000);
    flow.wake();
    await flush();
    assert.deepEqual(calls, ["GET", "POST {}", "GET"], "30 s later: the server is asked");
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: false });
    assert.ok(!phases.includes("loading"), "the QR stayed on screen (no «Φόρτωση…» flash)");
    await clock.advance(30_000);
    flow.wake();
    await flush();
    assert.deepEqual(calls, ["GET", "POST {}", "GET", "GET", "POST {}"], "revoked and re-issued by the server: the member's new code, asked for with POST {}");
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN2, issuedAt: ISSUED2, fresh: true });
  });

  it("errors retry by themselves with backoff (5 s, 15 s, 30 s, then every 60 s), and the next success resets it", async () => {
    const clock = fakeClock();
    const statuses: QrStatus[] = [{ kind: "error" }, { kind: "unavailable" }, { kind: "error" }, { kind: "error" }, { kind: "error" }, { kind: "none" }, { kind: "error" }];
    const { api, calls } = scripted(statuses, [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }]);
    const flow = new MemberQrFlow(api, createQrKeeper(), clock);
    await flow.open(ME);
    assert.deepEqual(flow.getState(), { phase: "error" });
    assert.deepEqual(clock.pending(), [QR_RETRY_DELAYS_MS[0]]);
    assert.deepEqual([...QR_RETRY_DELAYS_MS], [5_000, 15_000, 30_000, 60_000]);
    await clock.advance(4_999);
    assert.equal(calls.length, 1, "not before 5 s");
    await clock.advance(1);
    assert.equal(calls.length, 2);
    assert.deepEqual(flow.getState(), { phase: "unavailable" });
    assert.deepEqual(clock.pending(), [15_000]);
    await clock.advance(15_000);
    assert.deepEqual(clock.pending(), [30_000]);
    await clock.advance(30_000);
    assert.deepEqual(clock.pending(), [60_000]);
    await clock.advance(60_000);
    assert.deepEqual(clock.pending(), [60_000], "then every 60 s");
    await clock.advance(60_000);
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: true });
    assert.deepEqual(clock.pending(), [], "nothing scheduled once the QR is shown");
    await clock.advance(30_000);
    flow.wake();
    await flush();
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: true }, "round 7: a failed re-check never takes the QR off the screen");
    assert.deepEqual(clock.pending(), [5_000], "the backoff starts again after a success (silently)");
  });

  it("request cap: however often «Δοκιμάστε ξανά» or a wake-up fires, at most 8 requests and 3 POSTs in any minute", async () => {
    const clock = fakeClock();
    const calls: { at: number; what: string }[] = [];
    const api: CustomerQrApi = {
      async status() {
        calls.push({ at: clock.now(), what: "GET" });
        return { kind: "active", issuedAt: ISSUED };
      },
      async issue() {
        calls.push({ at: clock.now(), what: "POST" });
        return { kind: "unavailable" };
      },
    };
    const flow = new MemberQrFlow(api, createQrKeeper(), clock);
    await flow.open(ME);
    for (let i = 0; i < 40; i++) {
      flow.retry();
      flow.wake();
      await flush();
      await clock.advance(1_000);
    }
    await clock.advance(10 * 60_000);
    assert.equal(QR_MAX_REQUESTS_PER_MINUTE, 8);
    assert.equal(QR_MAX_ISSUES_PER_MINUTE, 3);
    for (const c of calls) {
      const inMinute = calls.filter((x) => x.at > c.at - 60_000 && x.at <= c.at);
      assert.ok(inMinute.length <= QR_MAX_REQUESTS_PER_MINUTE, `at most 8 requests in the minute up to ${c.at}`);
      assert.ok(inMinute.filter((x) => x.what === "POST").length <= QR_MAX_ISSUES_PER_MINUTE, `at most 3 POSTs in the minute up to ${c.at}`);
    }
    assert.ok(calls.filter((c) => c.what === "POST").length >= 3, "it does keep trying");
    assert.ok(["error", "unavailable"].includes(flow.getState().phase), "still an error state (with its retry link)");
    assert.equal(clock.pending().length, 1, "and exactly one retry is scheduled");
  });

  it("a spent budget waits for the window instead of the short backoff step", async () => {
    const clock = fakeClock();
    const api: CustomerQrApi = { status: async () => ({ kind: "active", issuedAt: ISSUED }), issue: async () => ({ kind: "unavailable" }) };
    const flow = new MemberQrFlow(api, createQrKeeper(), clock);
    await flow.open(ME);
    flow.retry();
    await flush();
    flow.retry();
    await flush();
    // 3 POSTs spent at t0: the next try may not come before the first of them leaves the minute.
    assert.ok(clock.pending()[0] >= 60_000 - 1, `waits for the window (${clock.pending()[0]} ms)`);
  });

  it("no retry for a final answer, retry() only in an error state, and sign-out cancels a scheduled retry", async () => {
    for (const kind of ["not_member", "member_disabled", "forbidden", "signed_out", "off"] as const) {
      const clock = fakeClock();
      const flow = new MemberQrFlow(scripted([{ kind }], []).api, createQrKeeper(), clock);
      await flow.open(ME);
      assert.deepEqual(clock.pending(), [], `${kind}: nothing scheduled`);
    }
    const clock = fakeClock();
    const shown = scripted([{ kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }]);
    const f1 = new MemberQrFlow(shown.api, createQrKeeper(), clock);
    await f1.open(ME);
    f1.retry();
    await flush();
    assert.deepEqual(shown.calls, ["GET", "POST {}"], "retry() does nothing while a QR is shown");
    const failing = scripted([{ kind: "error" }], []);
    const f2 = new MemberQrFlow(failing.api, createQrKeeper(), clock);
    await f2.open(ME);
    assert.equal(clock.pending().length, 1);
    await f2.forget();
    assert.deepEqual(clock.pending(), [], "sign-out leaves no retry behind");
    await clock.advance(10 * 60_000);
    assert.deepEqual(failing.calls, ["GET"]);
  });
});

describe("STAGE-08 round 7: weak Wi-Fi, leaving the card, D22 never-replace (survived mutants pinned)", () => {
  /** A QR shown and verified, then the server answers `next` statuses; phases seen after the first show. */
  async function shownThen(next: QrStatus[], issues: QrIssue[] = []) {
    const clock = fakeClock();
    const { api, calls } = scripted([{ kind: "active", issuedAt: ISSUED }, ...next], issues);
    const keeper = createQrKeeper();
    await keeper.write(ME, ISSUED, TOKEN);
    const flow = new MemberQrFlow(api, keeper, clock);
    await flow.open(ME);
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: false });
    const phases: string[] = [];
    flow.subscribe(() => phases.push(flow.getState().phase));
    return { clock, calls, flow, phases, keeper };
  }

  it("MAJOR-1: a quiet re-check that gets error / unavailable keeps the QR on screen and retries silently", async () => {
    for (const kind of ["error", "unavailable"] as const) {
      const { clock, calls, flow, phases } = await shownThen([{ kind }, { kind }, { kind: "active", issuedAt: ISSUED }]);
      await clock.advance(30_000);
      flow.wake();
      await flush();
      assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: false }, `${kind}: still shown`);
      assert.deepEqual(clock.pending(), [5_000], `${kind}: a silent retry after 5 s`);
      await clock.advance(5_000);
      assert.equal(flow.getState().phase, "shown", "the silent retry failed too: still shown");
      assert.deepEqual(clock.pending(), [15_000], "the backoff grows");
      await clock.advance(15_000);
      assert.deepEqual(calls, ["GET", "GET", "GET", "GET"]);
      assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: false });
      assert.deepEqual(clock.pending(), [], "checked: nothing scheduled");
      assert.ok(!phases.some((p) => p !== "shown"), `${kind}: never «Φόρτωση…» or an error on screen (${phases.join(",")})`);
    }
  });

  it("MAJOR-1: a spent budget on a quiet re-check keeps the QR too; the silent retries stay within the cap", async () => {
    const statuses: QrStatus[] = Array.from({ length: 40 }, () => ({ kind: "error" }) as QrStatus);
    const { clock, calls, flow } = await shownThen(statuses);
    const at: number[] = [];
    const real = clock.now;
    for (let i = 0; i < 30; i++) {
      await clock.advance(6_000);
      flow.wake();
      await flush();
      at.push(real());
      assert.equal(flow.getState().phase, "shown", `round ${i}: still shown`);
    }
    assert.ok(calls.length <= 1 + Math.ceil((30 * 6_000) / 60_000 + 1) * QR_MAX_REQUESTS_PER_MINUTE, `bounded (${calls.length})`);
    assert.equal(clock.pending().length, 1, "exactly one silent retry pending");
  });

  it("MAJOR-1: after a 409 the shown code is known to be stale, so a failure then is shown as it is", async () => {
    const { clock, calls, flow } = await shownThen([{ kind: "active", issuedAt: ISSUED2 }, { kind: "error" }], [{ kind: "concurrent_change" }]);
    await clock.advance(30_000);
    flow.wake();
    await flush();
    assert.deepEqual(calls, ["GET", "GET", "POST {}", "GET"]);
    assert.deepEqual(flow.getState(), { phase: "error" });
  });

  it("offline open: the phone's own QR for this member is shown as unchecked, then checked silently when possible", async () => {
    const { storage, keeper } = persisted();
    await keeper.write(ME, ISSUED, TOKEN);
    const fresh = createQrKeeper(createStorageQrKeeper(storage as unknown as Storage, subtle));
    const clock = fakeClock();
    const { api, calls } = scripted([{ kind: "error" }, { kind: "active", issuedAt: ISSUED }], []);
    const flow = new MemberQrFlow(api, fresh, clock);
    const phases: string[] = [];
    flow.subscribe(() => phases.push(flow.getState().phase));
    await flow.open(ME);
    assert.deepEqual(flow.getState(), { phase: "unchecked", token: TOKEN }, "no claim that it was verified");
    assert.equal(storage.ours().length, 1, "nothing deleted while the server cannot be asked");
    assert.deepEqual(clock.pending(), [5_000]);
    await clock.advance(5_000);
    await eventually(() => flow.getState().phase === "shown", "the silent check");
    assert.deepEqual(calls, ["GET", "GET"]);
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: false }, "verified once the server answers");
    assert.deepEqual(phases.slice(phases.indexOf("unchecked")), ["unchecked", "shown"], "no «Φόρτωση…» between the unchecked and the checked QR");
  });

  it("offline open: never another account's QR, nothing without a kept QR, and a dead one is dropped when checked", async () => {
    const { storage, keeper } = persisted();
    await keeper.write(OTHER, ISSUED, TOKEN2);
    const f1 = new MemberQrFlow(scripted([{ kind: "error" }], []).api, createQrKeeper(createStorageQrKeeper(storage as unknown as Storage, subtle)), fakeClock());
    await f1.open(ME);
    assert.deepEqual(f1.getState(), { phase: "error" }, "another member's record is never shown");
    const ctx = persisted();
    await ctx.keeper.write(ME, ISSUED, TOKEN);
    const clock = fakeClock();
    const later = scripted([{ kind: "unavailable" }, { kind: "member_disabled" }], []);
    const f2 = new MemberQrFlow(later.api, createQrKeeper(createStorageQrKeeper(ctx.storage as unknown as Storage, subtle)), clock);
    await f2.open(ME);
    assert.equal(f2.getState().phase, "unchecked");
    await clock.advance(5_000);
    await eventually(() => f2.getState().phase === "member_disabled" && ctx.storage.ours().length === 0, "the check");
    assert.deepEqual(f2.getState(), { phase: "member_disabled" }, "the check decides: the kept QR goes");
    assert.equal(ctx.storage.ours().length, 0);
  });

  it("qrStore.peek(): the account's one record whatever its issued_at, read only; never another account's", async () => {
    const { storage, store } = persisted();
    assert.equal(await store.peek!(ME), null);
    await store.write(ME, ISSUED, TOKEN);
    assert.equal(await store.peek!("ELENI@example.org"), TOKEN);
    assert.equal(await store.peek!(OTHER), null);
    assert.equal(storage.ours().length, 1, "peek deletes nothing (unlike read())");
    const early = store.peek!(ME);
    await store.forgetAll();
    assert.equal(await early, null, "a sign-out during the digest voids the answer");
    const keeper = createQrKeeper(store);
    await store.write(ME, ISSUED, TOKEN);
    const reading = keeper.peek!(ME);
    await keeper.forgetAll();
    assert.equal(await reading, null);
  });

  it("wake() after a failed quiet check re-checks after 5 s, not 30 s", async () => {
    const { clock, calls, flow } = await shownThen([{ kind: "error" }, { kind: "error" }, { kind: "active", issuedAt: ISSUED }]);
    await clock.advance(30_000);
    flow.wake();
    await flush();
    await clock.advance(5_000); // the silent retry: fails again, the next one waits 15 s
    assert.equal(calls.length, 3);
    assert.deepEqual(clock.pending(), [15_000]);
    await clock.advance(4_999);
    flow.wake();
    await flush();
    assert.equal(calls.length, 3, "not before 5 s");
    await clock.advance(1);
    flow.wake();
    await flush();
    assert.equal(calls.length, 4, "5 s after the failed check: asked again, before the 15 s timer and long before 30 s");
    assert.equal(flow.getState().phase, "shown");
  });

  it("the 5 s throttle of wake() in an error state: a wake right after a failure sends nothing", async () => {
    const clock = fakeClock();
    const { api, calls } = scripted([{ kind: "error" }, { kind: "error" }, { kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }]);
    const flow = new MemberQrFlow(api, createQrKeeper(), clock);
    await flow.open(ME);
    assert.deepEqual(flow.getState(), { phase: "error" });
    await clock.advance(1_000);
    flow.wake();
    await flush();
    await clock.advance(3_999);
    flow.wake();
    await flush();
    assert.deepEqual(calls, ["GET"], "nothing before 5 s, however often the tab wakes");
    await clock.advance(1);
    assert.deepEqual(calls, ["GET", "GET"], "the scheduled retry at 5 s");
  });

  it("the loading guard of wake(): visibilitychange + focus arriving together while loading send nothing more", async () => {
    const clock = fakeClock();
    const calls: string[] = [];
    let answer: (s: QrStatus) => void = () => {};
    const api: CustomerQrApi = {
      status: () => (calls.push("GET"), new Promise<QrStatus>((r) => (answer = r))),
      issue: async () => (calls.push("POST {}"), { kind: "issued", token: TOKEN, issuedAt: ISSUED }),
    };
    const flow = new MemberQrFlow(api, createQrKeeper(), clock);
    const opening = flow.open(ME);
    assert.deepEqual(flow.getState(), { phase: "loading" });
    await clock.advance(31_000);
    flow.wake(); // visibilitychange
    flow.wake(); // focus, in the same moment
    await flush();
    assert.deepEqual(calls, ["GET"], "the open in flight is not doubled");
    answer({ kind: "none" });
    await opening;
    assert.deepEqual(calls, ["GET", "POST {}"]);
  });

  it("the concurrent-change bound: exactly one re-read, then an error (pinned call sequence)", async () => {
    const clock = fakeClock();
    const { api, calls } = scripted([{ kind: "none" }, { kind: "none" }, { kind: "none" }, { kind: "none" }], [{ kind: "concurrent_change" }, { kind: "qr_exists" }, { kind: "concurrent_change" }, { kind: "issued", token: TOKEN, issuedAt: ISSUED }]);
    const flow = new MemberQrFlow(api, createQrKeeper(), clock);
    await flow.open(ME);
    assert.deepEqual(calls, ["GET", "POST {}", "GET", "POST {}"]);
    assert.deepEqual(flow.getState(), { phase: "error" });
  });

  it("D22: the flow never sends a replace, whatever the server says", async () => {
    const clock = fakeClock();
    const statuses: QrStatus[] = [{ kind: "active", issuedAt: ISSUED }, { kind: "none" }, { kind: "active", issuedAt: ISSUED2 }, { kind: "active", issuedAt: ISSUED }];
    const { api, calls } = scripted(statuses, [{ kind: "qr_exists" }, { kind: "concurrent_change" }, { kind: "issued", token: TOKEN, issuedAt: ISSUED }]);
    const flow = new MemberQrFlow(api, createQrKeeper(), clock);
    await flow.open(ME);
    await clock.advance(10 * 60_000);
    flow.wake();
    await flush();
    assert.ok(calls.includes("POST {}"));
    assert.ok(!calls.includes("POST replace"), calls.join(","));
  });

  it("LOW-3: pause() cancels the retry; nothing is sent while the member is off the card; open() resumes", async () => {
    const clock = fakeClock();
    const { api, calls } = scripted([{ kind: "error" }, { kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }]);
    const flow = new MemberQrFlow(api, createQrKeeper(), clock);
    await flow.open(ME);
    assert.deepEqual(clock.pending(), [5_000]);
    flow.pause();
    assert.deepEqual(clock.pending(), [], "the retry timer is gone");
    await clock.advance(10 * 60_000);
    flow.wake();
    flow.retry();
    await flush();
    assert.deepEqual(calls, ["GET"], "nothing while off the card (wake / retry included)");
    await flow.open(ME);
    assert.deepEqual(calls, ["GET", "GET", "POST {}"], "back on the card: asked again");
    assert.equal(flow.getState().phase, "shown");
  });

  it("LOW-3: a GET in flight when the member leaves never leads to a POST, and a timer that fires late does nothing", async () => {
    const clock = fakeClock();
    const calls: string[] = [];
    let answer: (s: QrStatus) => void = () => {};
    const api: CustomerQrApi = {
      status: () => (calls.push("GET"), new Promise<QrStatus>((r) => (answer = r))),
      issue: async (...a: unknown[]) => (calls.push(a.length ? "POST replace" : "POST {}"), { kind: "issued", token: TOKEN, issuedAt: ISSUED }),
    };
    const flow = new MemberQrFlow(api, createQrKeeper(), clock);
    const opening = flow.open(ME);
    flow.pause();
    answer({ kind: "active", issuedAt: ISSUED });
    await opening;
    await flush();
    assert.deepEqual(calls, ["GET"], "no POST after leaving the card");
    const ext = new Set<() => void>();
    const keeper: QrKeeper = { ...createQrKeeper(), onExternalChange: (l) => void ext.add(l) };
    const f2 = new MemberQrFlow(scripted([{ kind: "active", issuedAt: ISSUED }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }]).api, keeper, clock);
    await f2.open(ME);
    const before = f2.getState();
    f2.pause();
    for (const l of ext) l();
    await flush();
    assert.equal(f2.getState(), before, "another tab's change while off the card: nothing sent, re-checked on return");
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

async function mountWith(statuses: (QrStatus | (() => Promise<QrStatus>))[], issues: QrIssue[], ctx = persisted(), clock: QrFlowClock = fakeClock()) {
  const calls: string[] = [];
  const api: CustomerQrApi = {
    async status() {
      calls.push("GET");
      const s = statuses.shift() ?? { kind: "error" };
      return typeof s === "function" ? s() : s;
    },
    async issue(...args: unknown[]) {
      calls.push(args.length > 0 && args[0] !== false ? "POST replace" : "POST {}");
      return issues.shift() ?? { kind: "error" };
    },
  };
  const flow = new MemberQrFlow(api, ctx.keeper, clock);
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
    assert.match(text(), /Ο κωδικός σας μένει ίδιος σε όλες τις συσκευές σας και δεν αλλάζει όταν ανοίγετε την κάρτα\./);
    assert.match(text(), /Στιγμιότυπο οθόνης ισχύει κανονικά στο ταμείο, από όποια συσκευή κι αν το δείξετε\./);
    assert.doesNotMatch(text(), /Νέο QR|ανανεώνεται|δεν ισχύουν πια|σε άλλη συσκευή, ο κωδικός αλλάζει/);
    assert.match(text(), /Δείξτε τον κωδικό στο ταμείο/);
    assert.ok(app().querySelector('[aria-label="Κάρτα μέλους Evangelou Club"]'));
    assert.equal(storage.ours().length, 1, "the phone remembers it");
    assert.ok(app().querySelector('[role="status"]') && app().querySelector('[role="alert"]'), "two fixed live regions");
  });

  it("D21: no «Νέο QR» button and no question in the normal state; a visible-again tab asks the server", async () => {
    const clock = fakeClock();
    const { calls } = await mountWith([{ kind: "none" }, { kind: "active", issuedAt: ISSUED }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }], persisted(), clock);
    assert.ok(app().querySelector('[data-evc-qr="member"]'));
    const panel = app().querySelector("[data-evc-qr-state]") as HTMLElement;
    assert.equal(panel.querySelectorAll("button").length, 0, "no button at all next to the QR");
    assert.ok(![...dom.document.querySelectorAll("button")].some((b) => /Νέο QR|νέο κωδικό/.test(b.textContent ?? "")), "no «Νέο QR» anywhere");
    assert.equal(app().querySelector('[role="alertdialog"]'), null);
    await clock.advance(30_000);
    await act(async () => void dom.document.dispatchEvent(new dom.window.Event("visibilitychange")));
    await settle();
    assert.deepEqual(calls, ["GET", "POST {}", "GET"], "visibilitychange: the server is asked again");
    assert.ok(app().querySelector('[data-evc-qr="member"]'), "the same QR, still shown");
  });

  it("the next visit shows the remembered QR without asking the server for a new one", async () => {
    const ctx = persisted();
    await ctx.keeper.write(ME, ISSUED, TOKEN);
    const fresh = { ...ctx, keeper: createQrKeeper(createStorageQrKeeper(ctx.storage as unknown as Storage, subtle)) };
    const { calls } = await mountWith([{ kind: "active", issuedAt: ISSUED }], [], fresh);
    assert.deepEqual(calls, ["GET"]);
    assert.ok(app().querySelector('[data-evc-qr="member"]'));
  });

  it("D22: active on another device: the same QR is shown here at once (POST {}); the old «not on this device» state is gone", async () => {
    const { calls, storage } = await mountWith([{ kind: "active", issuedAt: ISSUED }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }]);
    assert.deepEqual(calls, ["GET", "POST {}"]);
    assert.ok(app().querySelector('[data-evc-qr="member"]'));
    assert.doesNotMatch(text(), /δεν φαίνεται σε αυτή τη συσκευή|δημιουργήθηκε σε άλλη συσκευή|Νέο QR/);
    assert.equal(storage.ours().length, 1);
  });

  it("D21: while the new QR is fetched the panel shows a neutral «Φόρτωση…», never a button", async () => {
    let answer: (i: QrIssue) => void = () => {};
    const ctx = persisted();
    const calls: string[] = [];
    const api: CustomerQrApi = {
      status: async () => (calls.push("GET"), { kind: "active", issuedAt: ISSUED }),
      issue: (...r: unknown[]) => (calls.push(r.length > 0 ? "POST replace" : "POST {}"), new Promise<QrIssue>((res) => (answer = res))),
    };
    const flow = new MemberQrFlow(api, ctx.keeper, fakeClock());
    const me: CustomerApi = { me: async () => ({ kind: "ok", me: ACTIVE }) };
    await dom.render(createElement(CustomerApp, { config: CONFIG, api: me, now: () => Date.parse("2026-11-04T10:00:00Z"), navigate: () => {}, qrFlow: flow }));
    await settle();
    assert.deepEqual(calls, ["GET", "POST {}"]);
    assert.equal(app().querySelector("[data-evc-qr-state]")?.getAttribute("data-evc-qr-state"), "loading");
    assert.match(text(), /Φόρτωση του κωδικού σας/);
    assert.equal(app().querySelectorAll("[data-evc-qr-state] button").length, 0);
    await act(async () => answer({ kind: "issued", token: TOKEN, issuedAt: ISSUED }));
    await settle();
    assert.ok(app().querySelector('[data-evc-qr="member"]'));
  });

  it("not a member yet / disabled: explained in Greek, no code", async () => {
    await mountWith([{ kind: "not_member" }], []);
    assert.match(text(), /μόλις επιβεβαιωθεί η πληρωμή/);
    await dom.reset();
    await mountWith([{ kind: "member_disabled" }], []);
    assert.match(text(), /Η κάρτα σας δεν είναι ενεργή/);
    assert.ok((app().querySelector('[data-evc-qr="member"]')) === (null), "app().querySelector('[data-evc-qr=\"member\"]') === null");
  });

  it("round 17: the route does not exist (feature off): «Φόρτωση…» while asked, then «not available yet»; never the sample card", async () => {
    let answer: (s: QrStatus) => void = () => {};
    await mountWith([() => new Promise<QrStatus>((r) => (answer = r))], []);
    assert.ok(app().querySelector('[data-evc-preview="sample-qr"]') === null, "while the first GET is pending: never the sample");
    assert.ok(app().querySelector('[data-evc-qr-placeholder="loading"]'), "the neutral place-holder");
    assert.match(text(), /Φόρτωση του κωδικού σας…/);
    await act(async () => answer({ kind: "off" }));
    await settle();
    assert.ok(app().querySelector('[data-evc-preview="sample-qr"]') === null);
    assert.equal(app().querySelector("[data-evc-qr-state]")?.getAttribute("data-evc-qr-state"), "off");
    assert.ok(app().querySelector('[data-evc-qr-placeholder="not-available"]'));
    assert.match(text(), /Ο κωδικός QR δεν είναι ακόμη διαθέσιμος/);
    assert.match(text(), /Ο κωδικός QR θα εμφανιστεί σε αυτή την κάρτα μόλις ενεργοποιηθεί\./);
    assert.doesNotMatch(text(), /Δείξτε τον κωδικό στο ταμείο/, "no «show the code» note without a code");
    assert.equal(app().querySelectorAll("[data-evc-qr-state] svg").length, 0, "no pattern of any kind");
  });

  it("unavailable: retry starts again from GET; sign-out deletes the remembered code before WordPress signs out", async () => {
    const ctx = persisted();
    const { calls, visited, storage } = await mountWith([{ kind: "unavailable" }, { kind: "none" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }], ctx);
    assert.match(text(), /δεν είναι διαθέσιμος αυτή τη στιγμή/);
    assert.match(text(), /Θα ξαναπροσπαθήσουμε αυτόματα σε λίγο/);
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

describe("STAGE-08 round 7: the card's listeners and timers stop when the member leaves it", () => {
  it("the panel removes its visibilitychange, focus and online listeners when it unmounts", async () => {
    const g = globalThis as unknown as { addEventListener?: unknown; removeEventListener?: unknown };
    const saved = { add: g.addEventListener, remove: g.removeEventListener };
    // Node's global is not the jsdom window: route the window listeners there for this test.
    g.addEventListener = dom.window.addEventListener.bind(dom.window);
    g.removeEventListener = dom.window.removeEventListener.bind(dom.window);
    try {
      let wakes = 0;
      const state = { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: false } as const;
      const flow = { subscribe: () => () => {}, getState: () => state, wake: () => void wakes++, retry: () => {} } as unknown as MemberQrFlow;
      await dom.render(createElement(MemberQrPanel, { flow }));
      const fire = async () => {
        await act(async () => {
          dom.document.dispatchEvent(new dom.window.Event("visibilitychange"));
          dom.window.dispatchEvent(new dom.window.Event("focus"));
          dom.window.dispatchEvent(new dom.window.Event("online"));
        });
      };
      await fire();
      assert.equal(wakes, 3, "mounted: each event wakes the flow");
      await dom.reset();
      await fire();
      assert.equal(wakes, 3, "unmounted: no listener is left behind (visibilitychange, focus, online)");
    } finally {
      g.addEventListener = saved.add;
      g.removeEventListener = saved.remove;
    }
  });

  it("LOW-3: leaving the card pauses the retries (no request, no POST off the card); coming back asks again", async () => {
    const clock = fakeClock();
    // In-memory keeper (no WebCrypto on the thread pool): the timing is the fake clock's alone.
    const { calls } = await mountWith([{ kind: "error" }, { kind: "active", issuedAt: ISSUED }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }], { ...persisted(), keeper: createQrKeeper() }, clock);
    await eventually(() => clock.pending().length === 1, "the first answer");
    assert.deepEqual(calls, ["GET"]);
    assert.deepEqual(clock.pending(), [5_000], "a retry is pending on the card");
    await dom.click(button("Αρχική"));
    await settle();
    assert.deepEqual(clock.pending(), [], "off the card: no timer keeps running");
    await act(async () => clock.advance(10 * 60_000));
    await settle();
    assert.deepEqual(calls, ["GET"], "nothing sent while the member is elsewhere");
    await dom.click(button("Κάρτα"));
    await settle();
    await eventually(() => calls.length >= 3, "back on the card");
    await settle();
    assert.deepEqual(calls, ["GET", "GET", "POST {}"], "back on the card: asked again (POST {}, never a replace)");
    assert.ok(app().querySelector('[data-evc-qr="member"]'));
  });

  it("round 7: a QR on screen survives a failed visible-again check in the real app", async () => {
    const clock = fakeClock();
    const { flow } = await mountWith([{ kind: "none" }, { kind: "error" }], [{ kind: "issued", token: TOKEN, issuedAt: ISSUED }], { ...persisted(), keeper: createQrKeeper() }, clock);
    await eventually(() => flow.getState().phase === "shown", "the first QR");
    await settle();
    assert.ok(app().querySelector('[data-evc-qr="member"]'));
    await act(async () => clock.advance(30_000));
    await act(async () => void dom.document.dispatchEvent(new dom.window.Event("visibilitychange")));
    await eventually(() => clock.pending().length === 1, "the failed check and its silent retry");
    await settle();
    assert.ok(app().querySelector('[data-evc-qr="member"]'), "still the QR");
    assert.doesNotMatch(text(), /Δεν φορτώθηκε|Φόρτωση του κωδικού/);
  });
});

describe("STAGE-08 round 18 (round-17 review, UX): a cold start shows this member's kept code at once while it is checked", () => {
  it("the kept code of THIS account is «checking» before GET /me/qr answers, then the answer replaces it", async () => {
    const { keeper } = persisted();
    await keeper.write(ME, ISSUED, TOKEN);
    let release: (s: QrStatus) => void = () => {};
    const api: CustomerQrApi = { status: () => new Promise<QrStatus>((r) => (release = r)), issue: async () => ({ kind: "issued", token: TOKEN2, issuedAt: ISSUED2 }) };
    // A fresh page: a new in-memory keeper over the phone's store.
    const flow = new MemberQrFlow(api, createQrKeeper(createStorageQrKeeper(persisted().storage as unknown as Storage, subtle)!));
    const reopened = new MemberQrFlow(api, keeper);
    const open = reopened.open(ME);
    await new Promise((r) => setTimeout(r, 0));
    assert.deepEqual(reopened.getState(), { phase: "checking", token: TOKEN }, "the kept code at once");
    release({ kind: "active", issuedAt: ISSUED });
    await open;
    assert.deepEqual(reopened.getState(), { phase: "shown", token: TOKEN, issuedAt: ISSUED, fresh: false });
    // Nothing kept (another phone store): «Φόρτωση…» as before, never a code.
    const other = flow.open(ME);
    await new Promise((r) => setTimeout(r, 0));
    assert.deepEqual(flow.getState(), { phase: "loading" });
    release({ kind: "none" });
    await other;
  });

  it("never another account's code; a stale kept code leaves the screen before the POST; a revoked member loses it", async () => {
    const { keeper } = persisted();
    await keeper.write("someone@else.org", ISSUED, TOKEN);
    const answers: QrStatus[] = [];
    let release: () => void = () => {};
    const api: CustomerQrApi = {
      status: () => new Promise<QrStatus>((r) => (release = () => r(answers.shift()!))),
      issue: async () => ({ kind: "issued", token: TOKEN2, issuedAt: ISSUED2 }),
    };
    const flow = new MemberQrFlow(api, keeper);
    const seen: string[] = [];
    flow.subscribe(() => seen.push(`${flow.getState().phase}:${"token" in flow.getState() ? (flow.getState() as { token: string }).token.slice(-4) : ""}`));
    answers.push({ kind: "none" });
    let open = flow.open(ME);
    await new Promise((r) => setTimeout(r, 0));
    assert.deepEqual(flow.getState(), { phase: "loading" }, "another account's code is never shown");
    release();
    await open;
    assert.ok(!seen.some((s) => s.endsWith(TOKEN.slice(-4))), "not even for a moment");
    // This account keeps TOKEN2 now; the server moved on (an administrator's revocation): issued_at differs.
    seen.length = 0;
    answers.push({ kind: "active", issuedAt: "2026-12-01T10:00:00+02:00" });
    open = flow.open(ME);
    await new Promise((r) => setTimeout(r, 0));
    assert.equal(flow.getState().phase, "checking");
    release();
    await open;
    const checking = seen.indexOf(`checking:${TOKEN2.slice(-4)}`);
    assert.ok(checking >= 0 && seen.indexOf("loading:", checking) > checking, `the stale code leaves before the POST: ${seen.join(" ")}`);
    // A disabled member: the kept code goes.
    answers.push({ kind: "member_disabled" });
    open = flow.open(ME);
    await new Promise((r) => setTimeout(r, 0));
    release();
    await open;
    assert.deepEqual(flow.getState(), { phase: "member_disabled" });
    assert.equal(await keeper.peek!(ME), null);
  });

  it("a kept code read after the server already answered never replaces the answer", async () => {
    let releasePeek: (t: string | null) => void = () => {};
    const keeper: QrKeeper = {
      read: async () => TOKEN2,
      write: async () => {},
      forget: async () => {},
      forgetAll: async () => {},
      peek: () => new Promise<string | null>((r) => (releasePeek = r)),
    };
    const api: CustomerQrApi = { status: async () => ({ kind: "active", issuedAt: ISSUED2 }), issue: async () => ({ kind: "error" }) };
    const flow = new MemberQrFlow(api, keeper);
    await flow.open(ME);
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN2, issuedAt: ISSUED2, fresh: false });
    releasePeek(TOKEN);
    await new Promise((r) => setTimeout(r, 0));
    assert.deepEqual(flow.getState(), { phase: "shown", token: TOKEN2, issuedAt: ISSUED2, fresh: false }, "the late kept code is ignored");
  });

  it("the panel draws the kept code with a quiet «checking» line and is busy", async () => {
    const { keeper } = persisted();
    await keeper.write(ME, ISSUED, TOKEN);
    const api: CustomerQrApi = { status: () => new Promise<QrStatus>(() => {}), issue: async () => ({ kind: "error" }) };
    const flow = new MemberQrFlow(api, keeper);
    void flow.open(ME);
    await new Promise((r) => setTimeout(r, 0));
    await dom.render(createElement(MemberQrPanel, { flow }));
    const panel = dom.document.querySelector("[data-evc-qr-state]") as HTMLElement;
    assert.equal(panel.dataset.evcQrState, "checking");
    assert.equal(panel.getAttribute("aria-busy"), "true");
    assert.ok(panel.querySelector('[data-evc-qr="member"]'), "the member's code");
    assert.match(panel.textContent ?? "", /Έλεγχος του κωδικού σας…/);
    assert.doesNotMatch(panel.textContent ?? "", /ΔΕΙΓΜΑ|Φόρτωση του κωδικού/);
    flow.pause();
  });
});
