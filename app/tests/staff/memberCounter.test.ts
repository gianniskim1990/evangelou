import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement } from "react";
import { clubError } from "../../src/club/errors";
import type { RedeemBenefitRequest, RedemptionOutcome } from "../../src/club/types";
import type { StaffConfig } from "../../src/staff/config";
import { COFFEE_CATALOGUE_VERSION, COUNTER_COFFEES } from "../../src/staff/member/coffeeCatalogue";
import { CounterFlow, countdown, RETRY_MESSAGE } from "../../src/staff/member/counterFlow";
import { createMemberApi, mapLookupReply, normalizeScannedCode, parseLookupFound, retryAfterOf, type LookupOutcome, type MemberApi } from "../../src/staff/member/memberApi";
import { browserCameraEnv, cameraSupported, DECODE_MAX_SIDE, startQrCamera, type CameraEnv, type FrameCanvas } from "../../src/staff/member/qrCamera";
import { encodeQr } from "../../src/customer/qrEncode";
import { StaffApp } from "../../src/staff/StaffApp";
import { StaffApiError, type SessionStatus, type StaffApi } from "../../src/staff/staffApi";
import { StaffController } from "../../src/staff/staffController";
import { MemoryStorage } from "../club/helpers";
import { createDom, type DomHarness } from "./support/dom";

/*
 * STAGE-05D: the REAL counter (QR scan -> first name + status -> coffee).
 * Fake transport / fake clock only; nothing reaches a network.
 */

const appDir = process.env.EVC_APP_DIR as string;
const MEMBER_ID = `mem_${"ab".repeat(16)}`;
const TOKEN = `evc_${"0123456789abcdef".repeat(3)}`;
const CONFIG: StaffConfig = {
  restBase: "https://shop.example/wp-json/evangelou-club/v1",
  nonce: "0a1b2c3d4e",
  loginUrl: "https://shop.example/wp-login.php?redirect_to=%2Fclub-admin%2F&reauth=1",
  shellUrl: "https://shop.example/club-admin/",
  idleLockSeconds: 300,
  sessionRef: "0123456789abcdef0123456789abcdef",
  appVersion: "test",
  features: { qrLookup: false, phoneLookup: false, coffeeRedemption: false, history: false },
};
const session = (qrLookup: boolean): SessionStatus => ({
  expiresAt: "2026-11-04T22:00:00+02:00",
  idleTimeoutSeconds: 1800,
  features: { qrLookup, phoneLookup: false, coffeeRedemption: qrLookup, history: false },
});

class FakeStaffApi implements StaffApi {
  sessions: SessionStatus[] = [session(true)];
  endCalls = 0;
  staffFetch: typeof fetch = async () => {
    throw new Error("not used");
  };
  currentNonce = () => CONFIG.nonce;
  async getSession() {
    return this.sessions.length > 1 ? (this.sessions.shift() as SessionStatus) : this.sessions[0];
  }
  async endSession() {
    this.endCalls++;
  }
}

function deferred<T>() {
  let resolve!: (v: T) => void;
  let reject!: (e: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

class FakeMemberApi implements MemberApi {
  lookups: string[] = [];
  redeems: RedeemBenefitRequest[] = [];
  lookupReplies: (LookupOutcome | (() => Promise<LookupOutcome>))[] = [];
  redeemReplies: ((r: RedeemBenefitRequest) => Promise<RedemptionOutcome>)[] = [];
  async lookupQr(token: string) {
    this.lookups.push(token);
    const r = this.lookupReplies.shift() ?? { kind: "unavailable" };
    return typeof r === "function" ? r() : r;
  }
  redeem = {
    redeemBenefit: (r: RedeemBenefitRequest) => {
      this.redeems.push(r);
      const f = this.redeemReplies.shift();
      return f ? f(r) : Promise.reject(clubError("server_error"));
    },
  };
}

/** What a hand scanner types into a tablet set to the Greek layout (the same keys). */
const LATIN_TO_GREEK: Record<string, string> = { a: "α", b: "β", c: "ψ", d: "δ", e: "ε", f: "φ", v: "ω" };
const toGreekLayout = (s: string) => [...s].map((ch) => LATIN_TO_GREEK[ch] ?? ch).join("");

const found = (status: "active" | "used_today" | "inactive", firstName: string | null = "Μαρία"): LookupOutcome => ({ kind: "found", member: { memberId: MEMBER_ID, firstName }, status });
const redeemed = (r: RedeemBenefitRequest): Promise<RedemptionOutcome> =>
  Promise.resolve({ benefit: { state: "used", businessDate: "2026-11-04", redeemedAt: "2026-11-04T10:00:00+02:00", coffeeCode: r.coffeeCode }, requestId: r.requestId, replayed: false });

/** Interval callbacks are captured, never run by a real clock. */
function fakeTimers() {
  const live = new Map<number, () => void>();
  let next = 1;
  return {
    live,
    setInterval: (fn: () => void) => (live.set(next, fn), next++),
    clearInterval: (id: unknown) => void live.delete(id as number),
    tick: () => [...live.values()].forEach((fn) => fn()),
  };
}

async function readyCounter(qrLookup = true, now = () => 1_000_000, timers = fakeTimers()) {
  const api = new FakeStaffApi();
  api.sessions = [session(qrLookup)];
  const controller = new StaffController({ api, config: CONFIG, navigate: () => {}, storage: new MemoryStorage() });
  await controller.boot();
  const members = new FakeMemberApi();
  const flow = new CounterFlow({ controller, api: members, now, timers });
  return { api, controller, members, flow, timers };
}

describe("STAGE-05D coffee catalogue = the server's approved list (D3)", () => {
  it("same version, codes, labels and order as EVC_Approved_Coffee_Catalogue_Source, with «Άλλος καφές»", () => {
    const php = readFileSync(join(appDir, "..", "wordpress", "evangelou-club", "includes", "staff", "class-evc-redemption-catalogue-source.php"), "utf8");
    assert.match(php, new RegExp(`const VERSION = '${COFFEE_CATALOGUE_VERSION}';`));
    const items = php.slice(php.indexOf("const ITEMS = array("), php.indexOf(");", php.indexOf("const ITEMS = array(")));
    const pairs = [...items.matchAll(/'([a-z_]+)' => '([^']+)'/g)].map((m) => ({ code: m[1], label: m[2] }));
    assert.deepEqual(pairs, COUNTER_COFFEES.map((c) => ({ ...c })));
    assert.ok(COUNTER_COFFEES.some((c) => c.code === "other_coffee" && c.label === "Άλλος καφές"));
  });
});

describe("STAGE-05D lookup reply (POST /members/lookup)", () => {
  it("200: exactly member {member_id, first_name} + status; nothing else is accepted", () => {
    assert.deepEqual(parseLookupFound({ member: { member_id: MEMBER_ID, first_name: "Μαρία" }, status: "active" }), { member: { memberId: MEMBER_ID, firstName: "Μαρία" }, status: "active" });
    assert.deepEqual(parseLookupFound({ status: "used_today", member: { first_name: null, member_id: MEMBER_ID } }), { member: { memberId: MEMBER_ID, firstName: null }, status: "used_today" }, "any key order");
    const bad: unknown[] = [
      { member: { member_id: MEMBER_ID, first_name: "Μαρία", last_name: "Π" }, status: "active" },
      { member: { member_id: MEMBER_ID, first_name: "Μαρία" }, status: "active", phone: "69" },
      { member: { member_id: "mem_X", first_name: "Μαρία" }, status: "active" },
      { member: { member_id: MEMBER_ID, first_name: "" }, status: "active" },
      { member: { member_id: MEMBER_ID, first_name: "Α".repeat(41) }, status: "active" },
      { member: { member_id: MEMBER_ID, first_name: "Μαρία" }, status: "expired" },
      { member: { member_id: MEMBER_ID }, status: "active" },
      { membership: { status: "active" }, member: { member_id: MEMBER_ID, display_name: "Μαρία Π." } },
    ];
    for (const b of bad) assert.equal(parseLookupFound(b), null, JSON.stringify(b));
    assert.throws(() => mapLookupReply(200, bad[0], null), (e: unknown) => e instanceof StaffApiError && e.kind === "invalid_response");
  });

  it("404 member_not_found WITH the envelope is «not found»; a bare 404 is never", () => {
    const env = (code: string, details?: unknown) => ({ error: { code, message: "x", ...(details ? { details } : {}) } });
    assert.deepEqual(mapLookupReply(404, env("member_not_found"), null), { kind: "not_found" });
    assert.throws(() => mapLookupReply(404, { code: "rest_no_route" }, null), (e: unknown) => e instanceof StaffApiError && e.kind === "invalid_response");
    assert.throws(() => mapLookupReply(404, null, null), StaffApiError);
    assert.deepEqual(mapLookupReply(400, env("invalid_qr"), null), { kind: "invalid_qr" });
    assert.throws(() => mapLookupReply(400, env("invalid_request"), null), StaffApiError);
    assert.deepEqual(mapLookupReply(429, env("rate_limited", { retry_after_seconds: 300 }), "300"), { kind: "rate_limited", retryAfterSeconds: 300 });
    assert.deepEqual(mapLookupReply(503, env("server_error"), null), { kind: "unavailable" });
    assert.deepEqual(mapLookupReply(500, null, null), { kind: "unavailable" });
    assert.throws(() => mapLookupReply(401, env("unauthorized"), null), (e: unknown) => e instanceof StaffApiError && e.kind === "unauthorized" && e.status === 401);
    assert.throws(() => mapLookupReply(403, env("forbidden"), null), (e: unknown) => e instanceof StaffApiError && e.kind === "forbidden" && e.status === 403);
  });

  it("Retry-After: the header first, then details, else 300; bounded to 1..3600", () => {
    assert.equal(retryAfterOf("120", null), 120);
    assert.equal(retryAfterOf(null, { error: { details: { retry_after_seconds: 90 } } }), 90);
    assert.equal(retryAfterOf("soon", null), 300);
    assert.equal(retryAfterOf("0", null), 0, "Retry-After: 0 means now, not 300 s");
    assert.equal(retryAfterOf(null, { error: { details: { retry_after_seconds: 0 } } }), 0);
    assert.equal(retryAfterOf(null, { error: { details: { retry_after_seconds: -5 } } }), 300);
    assert.equal(retryAfterOf(null, null), 300);
    assert.equal(retryAfterOf("999999", null), 3600);
  });

  it("transport: the staff fetch (nonce), POST with exactly {method, qr_token}; empty or overlong codes are never sent", async () => {
    const calls: { url: string; init: RequestInit }[] = [];
    const api = new FakeStaffApi();
    api.staffFetch = (async (url: string, init: RequestInit) => {
      calls.push({ url, init });
      return new Response(JSON.stringify({ member: { member_id: MEMBER_ID, first_name: "Μαρία" }, status: "active" }), { status: 200 });
    }) as unknown as typeof fetch;
    const m = createMemberApi(api, `${CONFIG.restBase}/`);
    const signal = new AbortController().signal;
    assert.deepEqual(await m.lookupQr(`  ${TOKEN}\n`, signal), found("active"));
    assert.equal(calls[0].url, `${CONFIG.restBase}/members/lookup`);
    assert.equal(calls[0].init.method, "POST");
    assert.equal(calls[0].init.body, JSON.stringify({ method: "qr", qr_token: TOKEN }), "trimmed (hand scanners add a newline)");
    for (const typed of [TOKEN.toUpperCase(), toGreekLayout(TOKEN), toGreekLayout(TOKEN).toLocaleUpperCase("el-GR")]) {
      assert.deepEqual(await m.lookupQr(typed, signal), found("active"), typed);
      assert.equal(calls.at(-1)!.init.body, JSON.stringify({ method: "qr", qr_token: TOKEN }), `sent as the Latin token: ${typed}`);
    }
    const sent = calls.length;
    for (const bad of [`${TOKEN}0`, TOKEN.slice(0, -1), `evc_${"g".repeat(48)}`, "https://example.org/x", "ΕΥΑΓΓΕΛΟΥ", `mem_${"a".repeat(32)}`]) {
      assert.deepEqual(await m.lookupQr(bad, signal), { kind: "invalid_qr" }, bad);
    }
    assert.equal(calls.length, sent, "a code that is not a Club token is refused on the tablet: it never counts against the D9 limit");
    assert.deepEqual(await m.lookupQr("   ", signal), { kind: "invalid_qr" });
    assert.deepEqual(await m.lookupQr("x".repeat(257), signal), { kind: "invalid_qr" });
    assert.equal(calls.length, sent);
  });

  it("transport failures: network, timeout and a caller abort are told apart", async () => {
    const api = new FakeStaffApi();
    api.staffFetch = (async () => {
      throw new TypeError("offline");
    }) as unknown as typeof fetch;
    await assert.rejects(createMemberApi(api, CONFIG.restBase).lookupQr(TOKEN, new AbortController().signal), (e: unknown) => e instanceof StaffApiError && e.kind === "network");
    api.staffFetch = ((_: string, init: RequestInit) =>
      new Promise((_r, reject) => init.signal?.addEventListener("abort", () => reject(new Error("aborted"))))) as unknown as typeof fetch;
    await assert.rejects(createMemberApi(api, CONFIG.restBase, 5).lookupQr(TOKEN, new AbortController().signal), (e: unknown) => e instanceof StaffApiError && e.kind === "timeout");
    const caller = new AbortController();
    const pending = createMemberApi(api, CONFIG.restBase, 10_000).lookupQr(TOKEN, caller.signal);
    caller.abort();
    await assert.rejects(pending, (e: unknown) => e instanceof StaffApiError && e.kind === "aborted");
  });
});

describe("STAGE-05D counter flow", () => {
  it("nothing runs unless the session is ready AND features.qr_lookup is true", async () => {
    const { flow, members } = await readyCounter(false);
    flow.openScan();
    assert.deepEqual(flow.getState(), { screen: "home" });
    await flow.submitScan(TOKEN);
    assert.deepEqual(members.lookups, []);
  });

  it("scan -> member: first name and status only (D8); active offers the 7 coffees; a coffee is recorded once", async () => {
    const { flow, members } = await readyCounter();
    members.lookupReplies = [found("active")];
    members.redeemReplies = [redeemed];
    flow.openScan();
    await flow.submitScan(TOKEN);
    assert.deepEqual(flow.getState(), { screen: "member", member: { memberId: MEMBER_ID, firstName: "Μαρία" }, status: "active", coffee: null, redeem: "idle", message: null });
    await flow.redeem();
    assert.equal(members.redeems.length, 0, "no coffee chosen: nothing sent");
    flow.chooseCoffee("not_a_coffee");
    assert.equal((flow.getState() as { coffee: string | null }).coffee, null);
    flow.chooseCoffee("other_coffee");
    await flow.redeem();
    assert.equal(members.redeems.length, 1);
    assert.equal(members.redeems[0].memberId, MEMBER_ID);
    assert.equal(members.redeems[0].coffeeCode, "other_coffee");
    assert.equal(members.redeems[0].benefitType, "free_coffee");
    assert.match(members.redeems[0].requestId, /^[0-9a-f]{8}-[0-9a-f]{4}-4/);
    assert.deepEqual(flow.getState(), { screen: "scan", busy: false, notice: { kind: "recorded", firstName: "Μαρία", coffeeLabel: "Άλλος καφές" }, blockedUntil: null });
  });

  it("used_today and inactive: no coffee can be chosen or sent", async () => {
    for (const status of ["used_today", "inactive"] as const) {
      const { flow, members } = await readyCounter();
      members.lookupReplies = [found(status)];
      flow.openScan();
      await flow.submitScan(TOKEN);
      flow.chooseCoffee("espresso");
      await flow.redeem();
      assert.equal((flow.getState() as { coffee: string | null }).coffee, null, status);
      assert.equal(members.redeems.length, 0, status);
    }
  });

  it("an unknown outcome keeps the SAME request_id for «Δοκιμή ξανά» and locks the coffee choice", async () => {
    const { flow, members } = await readyCounter();
    members.lookupReplies = [found("active")];
    members.redeemReplies = [() => Promise.reject(clubError("network_error")), () => Promise.reject(clubError("server_error")), redeemed];
    flow.openScan();
    await flow.submitScan(TOKEN);
    flow.chooseCoffee("espresso");
    await flow.redeem();
    assert.equal((flow.getState() as { redeem: string }).redeem, "retry");
    assert.equal((flow.getState() as { message: string }).message, RETRY_MESSAGE);
    flow.chooseCoffee("cappuccino");
    assert.equal((flow.getState() as { coffee: string }).coffee, "espresso", "the coffee cannot change while the outcome is unknown");
    await flow.redeem();
    await flow.redeem();
    assert.equal(members.redeems.length, 3);
    assert.equal(new Set(members.redeems.map((r) => r.requestId)).size, 1, "one request_id for the whole intent");
    assert.equal(flow.getState().screen, "scan");
  });

  it("already redeemed / inactive at redeem time / not found / invalid coffee", async () => {
    const cases = [
      [clubError("benefit_already_redeemed", { businessDate: "2026-11-04" }), "member", "used_today"],
      [clubError("membership_inactive", { status: "expired" }), "member", "inactive"],
      [clubError("member_not_found"), "scan", null],
      [clubError("invalid_coffee"), "member", "active"],
    ] as const;
    for (const [error, screen, status] of cases) {
      const { flow, members } = await readyCounter();
      members.lookupReplies = [found("active")];
      members.redeemReplies = [() => Promise.reject(error)];
      flow.openScan();
      await flow.submitScan(TOKEN);
      flow.chooseCoffee("espresso");
      await flow.redeem();
      const s = flow.getState();
      assert.equal(s.screen, screen, error.code);
      if (s.screen === "member") {
        assert.equal(s.status, status, error.code);
        assert.equal(s.redeem, "idle", error.code);
        assert.ok(s.message, error.code);
      } else {
        assert.deepEqual(s.notice, { kind: "not_found" });
      }
    }
  });

  it("not found, invalid QR, unavailable, offline and error notices; the scan stays open", async () => {
    const cases: [LookupOutcome | (() => Promise<LookupOutcome>), string][] = [
      [{ kind: "not_found" }, "not_found"],
      [{ kind: "invalid_qr" }, "invalid_qr"],
      [{ kind: "unavailable" }, "unavailable"],
      [() => Promise.reject(new StaffApiError("network")), "offline"],
      [() => Promise.reject(new StaffApiError("timeout")), "offline"],
      [() => Promise.reject(new StaffApiError("invalid_response", 404)), "error"],
    ];
    for (const [reply, kind] of cases) {
      const { flow, members } = await readyCounter();
      members.lookupReplies = [reply];
      flow.openScan();
      await flow.submitScan(TOKEN);
      assert.deepEqual(flow.getState(), { screen: "scan", busy: false, notice: { kind }, blockedUntil: null }, kind);
    }
  });

  it("429: the scan stays closed until Retry-After has passed (D9), then opens again", async () => {
    let now = 1_000_000;
    const { flow, members } = await readyCounter(true, () => now);
    members.lookupReplies = [{ kind: "rate_limited", retryAfterSeconds: 300 }, found("active")];
    flow.openScan();
    await flow.submitScan(TOKEN);
    assert.deepEqual(flow.getState(), { screen: "scan", busy: false, notice: { kind: "rate_limited" }, blockedUntil: 1_300_000 });
    now = 1_299_999;
    await flow.submitScan(TOKEN);
    assert.equal(members.lookups.length, 1, "not sent while blocked");
    flow.home();
    flow.openScan();
    assert.deepEqual((flow.getState() as { notice: unknown }).notice, { kind: "rate_limited" }, "re-opening still says why");
    now = 1_300_000;
    flow.openScan();
    await flow.submitScan(TOKEN);
    assert.equal(members.lookups.length, 2);
    assert.equal(flow.getState().screen, "member");
  });

  it("401 / 403 end the session; a lock or a feature switched off drops the member; a late reply is ignored", async () => {
    for (const [error, phase] of [[new StaffApiError("unauthorized", 401), "expired"], [new StaffApiError("forbidden", 403), "forbidden"]] as const) {
      const { flow, members, controller } = await readyCounter();
      members.lookupReplies = [() => Promise.reject(error)];
      flow.openScan();
      await flow.submitScan(TOKEN);
      assert.equal(controller.getState().phase, phase);
      assert.deepEqual(flow.getState(), { screen: "home" });
    }
    {
      const { flow, members, controller } = await readyCounter();
      members.lookupReplies = [found("active")];
      members.redeemReplies = [() => Promise.reject(clubError("unauthorized", undefined, 401))];
      flow.openScan();
      await flow.submitScan(TOKEN);
      flow.chooseCoffee("espresso");
      await flow.redeem();
      assert.equal(controller.getState().phase, "expired", "a redeem 401 ends the session, it is not a retry");
      assert.deepEqual(flow.getState(), { screen: "home" });
    }
    {
      const { flow, members, controller } = await readyCounter();
      const late = deferred<LookupOutcome>();
      members.lookupReplies = [() => late.promise];
      flow.openScan();
      const pending = flow.submitScan(TOKEN);
      await controller.lock();
      late.resolve(found("active"));
      await pending;
      assert.deepEqual(flow.getState(), { screen: "home" }, "nothing after the lock");
    }
    {
      const { flow, members, controller, api } = await readyCounter();
      members.lookupReplies = [found("active")];
      flow.openScan();
      await flow.submitScan(TOKEN);
      assert.equal(flow.getState().screen, "member");
      api.sessions = [session(false)];
      await controller.refreshStatus();
      assert.deepEqual(flow.getState(), { screen: "home" }, "qr_lookup switched off on the server");
    }
  });
});

describe("STAGE-05D camera (BarcodeDetector)", () => {
  function env(over: Partial<CameraEnv> = {}) {
    const ticks: (() => void)[] = [];
    const tracks = [{ stopped: false, stop() { this.stopped = true; } }];
    let reads: string[][] = [];
    const e: CameraEnv & { ticks: typeof ticks; tracks: typeof tracks; setReads(r: string[][]): void } = {
      BarcodeDetector: class {
        constructor(o: { formats: string[] }) {
          assert.deepEqual(o.formats, ["qr_code"]);
        }
        async detect() {
          return (reads.shift() ?? []).map((rawValue) => ({ rawValue }));
        }
      },
      mediaDevices: { getUserMedia: async (c?: MediaStreamConstraints) => (assert.deepEqual(c, { video: { facingMode: "environment" }, audio: false }), { getTracks: () => tracks } as unknown as MediaStream) },
      setInterval: (fn) => (ticks.push(fn), ticks.length),
      clearInterval: () => {
        ticks.length = 0;
      },
      ticks,
      tracks,
      setReads(r) {
        reads = r;
      },
      ...over,
    };
    return e;
  }
  const video = () => ({ srcObject: null as unknown, muted: false, setAttribute() {}, play: () => Promise.resolve() }) as unknown as HTMLVideoElement;
  const flush = () => new Promise((r) => setTimeout(r, 0));

  it("only offered when the browser has BarcodeDetector and a camera", () => {
    assert.equal(cameraSupported(env()), true);
    assert.equal(cameraSupported(env({ BarcodeDetector: undefined })), false);
    assert.equal(cameraSupported(env({ mediaDevices: undefined })), false);
  });

  it("reads one code, then stops the camera; «denied» is told apart", async () => {
    const e = env();
    const codes: string[] = [];
    const v = video();
    await startQrCamera(v, (c) => codes.push(c), e, new AbortController().signal);
    e.setReads([[], [TOKEN], [TOKEN]]);
    e.ticks[0]();
    await flush();
    e.ticks[0]?.();
    await flush();
    assert.deepEqual(codes, [TOKEN]);
    assert.equal(e.tracks[0].stopped, true);
    assert.equal(v.srcObject, null);
    assert.equal(e.ticks.length, 0, "the timer is gone");
    const none = new AbortController().signal;
    await assert.rejects(startQrCamera(video(), () => {}, env({ mediaDevices: { getUserMedia: async () => Promise.reject(Object.assign(new Error("no"), { name: "NotAllowedError" })) } }), none), (x: unknown) => x === "denied");
    await assert.rejects(startQrCamera(video(), () => {}, env({ mediaDevices: { getUserMedia: async () => Promise.reject(new Error("busy")) } }), none), (x: unknown) => x === "unavailable");
  });
});

let dom: DomHarness;
let act: (fn: () => Promise<void>) => Promise<void>;

before(async () => {
  dom = await createDom();
  act = (await import("react")).act as unknown as typeof act;
});
after(() => dom.dispose());
beforeEach(async () => {
  await dom.reset();
});

const NO_CAMERA: CameraEnv = { setInterval: () => 0, clearInterval: () => {} };
const text = () => dom.stage.textContent ?? "";
const button = (label: string) => {
  const b = [...dom.document.querySelectorAll("button")].find((x) => x.textContent?.trim() === label);
  assert.ok(b, `button «${label}» exists`);
  return b as HTMLButtonElement;
};
async function type(el: HTMLInputElement, value: string) {
  await act(async () => {
    const setter = Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype, "value")!.set!;
    setter.call(el, value);
    el.dispatchEvent(new dom.window.Event("input", { bubbles: true }));
  });
}

async function mountApp(qrLookup: boolean) {
  const ctx = await readyCounter(qrLookup);
  await dom.render(createElement(StaffApp, { controller: ctx.controller, counter: ctx.flow, camera: NO_CAMERA }));
  return ctx;
}

describe("STAGE-05D StaffApp: the counter on the real dashboard", () => {
  it("features.qr_lookup false: no scan, the cards stay disabled exactly as before", async () => {
    const { flow } = await mountApp(false);
    assert.equal(dom.document.querySelector('[data-evc-action="qr"]'), null);
    assert.match(text(), /Σάρωση QR μέλους — μη διαθέσιμη|Σάρωση QR μέλους/);
    assert.ok(dom.document.querySelector('[aria-label="Σάρωση QR μέλους — μη διαθέσιμη"]'));
    assert.match(text(), /Δεν έχει ενεργοποιηθεί ακόμη/);
    await act(async () => flow.openScan());
    assert.equal(dom.document.querySelector('[data-evc-counter]'), null);
  });

  it("scan with the hand scanner -> «Μαρία», active, 7 coffees with «Άλλος καφές» -> recorded; no revoke anywhere (D10)", async () => {
    const { members } = await mountApp(true);
    members.lookupReplies = [found("active")];
    members.redeemReplies = [redeemed];
    assert.match(text(), /Ενεργή: σάρωση QR και δωρεάν καφές/);
    assert.equal(dom.document.querySelector('[aria-label="Καταχώρηση δωρεάν καφέ — μη διαθέσιμη"]'), null, "the coffee comes after the scan");
    assert.ok(dom.document.querySelector('[aria-label="Αναζήτηση με τηλέφωνο — μη διαθέσιμη"]'), "phone lookup stays off");
    await dom.click(dom.document.querySelector('[data-evc-action="qr"]')!);
    assert.equal(dom.document.activeElement, dom.document.getElementById("evc-scan-code"), "no camera: focus on the scanner field");
    assert.equal(button("Αναζήτηση").disabled, true, "nothing to send yet");
    await type(dom.document.getElementById("evc-scan-code") as HTMLInputElement, TOKEN);
    await dom.click(button("Αναζήτηση"));
    await act(async () => {});
    assert.deepEqual(members.lookups, [TOKEN]);
    assert.equal((dom.document.getElementById("evc-scan-code") as HTMLInputElement | null)?.value ?? "", "", "the scanned code is not kept on screen");
    assert.equal(dom.document.querySelector("h1")?.textContent, "Μαρία");
    assert.match(text(), /Ενεργό μέλος · δικαιούται καφέ σήμερα/);
    const radios = [...dom.document.querySelectorAll('input[type="radio"][name="evc-coffee"]')] as HTMLInputElement[];
    assert.deepEqual(radios.map((r) => r.value), COUNTER_COFFEES.map((c) => c.code));
    assert.ok(dom.document.querySelector("legend")?.textContent?.includes("Ποιος καφές;"));
    assert.equal(button("Καταχώρηση καφέ").disabled, true);
    await dom.click(radios.find((r) => r.value === "other_coffee")!);
    await dom.click(button("Καταχώρηση καφέ"));
    await act(async () => {});
    assert.equal(members.redeems[0]?.coffeeCode, "other_coffee");
    assert.match(text(), /Ο καφές καταχωρήθηκε: Άλλος καφές · Μαρία/);
    assert.equal(dom.document.querySelector('[role="status"]')?.textContent?.includes("καταχωρήθηκε"), true, "announced politely");
    assert.doesNotMatch(dom.document.body.innerHTML, /ακύρωση QR|Ακύρωση QR|revoke|Ανάκληση/i, "the tablet never revokes");
    assert.doesNotMatch(text(), /evc_[0-9a-f]{48}/);
    assert.equal(dom.network.fetch.length, 0);
  });

  it("used today / inactive / not found are told in Greek; no coffee picker", async () => {
    for (const [reply, phrase] of [
      [found("used_today", null), /Ο σημερινός καφές έχει ήδη δοθεί/],
      [found("inactive"), /Η συνδρομή δεν είναι ενεργή/],
      [{ kind: "not_found" } as LookupOutcome, /Δεν βρέθηκε μέλος με αυτόν τον κωδικό/],
      [{ kind: "rate_limited", retryAfterSeconds: 300 } as LookupOutcome, /Πολλές αποτυχημένες σαρώσεις από αυτό το tablet\. Δοκιμάστε ξανά σε 5:00\./],
      [{ kind: "unavailable" } as LookupOutcome, /Η υπηρεσία μελών δεν είναι διαθέσιμη/],
    ] as const) {
      await dom.reset();
      const { members } = await mountApp(true);
      members.lookupReplies = [reply];
      await dom.click(dom.document.querySelector('[data-evc-action="qr"]')!);
      await type(dom.document.getElementById("evc-scan-code") as HTMLInputElement, TOKEN);
      await dom.click(button("Αναζήτηση"));
      await act(async () => {});
      assert.match(text(), phrase);
      assert.equal(dom.document.querySelectorAll('input[name="evc-coffee"]').length, 0);
    }
    assert.ok(dom.document.querySelector('[role="alert"]'));
  });

  it("a first name the server does not have is shown as «Μέλος του Club»; locking the screen removes the member", async () => {
    const { members, controller } = await mountApp(true);
    members.lookupReplies = [found("active", null)];
    await dom.click(dom.document.querySelector('[data-evc-action="qr"]')!);
    await type(dom.document.getElementById("evc-scan-code") as HTMLInputElement, TOKEN);
    await dom.click(button("Αναζήτηση"));
    await act(async () => {});
    assert.equal(dom.document.querySelector("h1")?.textContent, "Μέλος του Club");
    await act(async () => controller.lock());
    assert.doesNotMatch(text(), /Μέλος του Club|Ποιος καφές/);
    assert.match(text(), /Η οθόνη κλειδώθηκε/);
  });
});

describe("STAGE-05D round 2: hand scanner text, Retry-After, camera fallback and leaks", () => {
  it("Greek keyboard layout and Caps Lock are mapped back to the Latin token", () => {
    assert.equal(normalizeScannedCode(toGreekLayout(TOKEN)), TOKEN);
    assert.equal(normalizeScannedCode(toGreekLayout(TOKEN).toLocaleUpperCase("el-GR")), TOKEN, "Caps Lock on the Greek layout");
    assert.equal(normalizeScannedCode(TOKEN.toUpperCase()), TOKEN, "Caps Lock on the Latin layout");
    assert.equal(normalizeScannedCode(`\t ${toGreekLayout(TOKEN)}\r\n`), TOKEN, "the scanner's Enter / Tab");
    assert.equal(normalizeScannedCode("ΕΥΑΓΓΕΛΟΥ"), "eyaggeloy", "other letters map too, and then fail the token format");
  });

  it("Retry-After 0 does not block; the countdown is m:ss", async () => {
    const { flow, members } = await readyCounter();
    members.lookupReplies = [{ kind: "rate_limited", retryAfterSeconds: 0 }, found("active")];
    flow.openScan();
    await flow.submitScan(TOKEN);
    assert.equal((flow.getState() as { blockedUntil: number | null }).blockedUntil, null, "nothing to wait for");
    await flow.submitScan(TOKEN);
    assert.equal(members.lookups.length, 2);
    assert.equal(countdown(1_300_000, 1_000_000), "5:00");
    assert.equal(countdown(1_300_000, 1_299_001), "0:01");
    assert.equal(countdown(1_300_000, 1_300_000), "0:00");
  });

  it("an aborted lookup does not leave the scan busy", async () => {
    const { flow, members } = await readyCounter();
    members.lookupReplies = [() => Promise.reject(new StaffApiError("aborted"))];
    flow.openScan();
    await flow.submitScan(TOKEN);
    assert.equal((flow.getState() as { busy: boolean }).busy, false);
  });

  function stream() {
    const tracks = [{ stopped: false, stop() { this.stopped = true; } }];
    return { tracks, media: { getTracks: () => tracks } as unknown as MediaStream };
  }
  const video = (w = 1280, h = 720) => ({ srcObject: null as unknown, muted: false, videoWidth: w, videoHeight: h, setAttribute() {}, play: () => Promise.resolve() }) as unknown as HTMLVideoElement;
  const flush = () => new Promise((r) => setTimeout(r, 0));

  it("a camera granted AFTER the screen closed or locked is stopped at once (no leak)", async () => {
    const s = stream();
    const granted = deferred<MediaStream>();
    const ticks: (() => void)[] = [];
    const env: CameraEnv = {
      BarcodeDetector: class {
        async detect() {
          return [];
        }
      },
      mediaDevices: { getUserMedia: () => granted.promise },
      setInterval: (fn) => ticks.push(fn),
      clearInterval: () => {},
    };
    const run = new AbortController();
    const v = video();
    const started = startQrCamera(v, () => {}, env, run.signal);
    run.abort();
    granted.resolve(s.media);
    await assert.rejects(started, (x: unknown) => x === "aborted");
    assert.equal(s.tracks[0].stopped, true, "the late stream is stopped");
    assert.equal(v.srcObject, null, "never attached");
    assert.equal(ticks.length, 0, "no decode loop");
    await assert.rejects(startQrCamera(v, () => {}, env, run.signal), (x: unknown) => x === "aborted", "an aborted signal never asks for the camera");
  });

  it("a lock while the fallback decoder is still loading also stops the stream", async () => {
    const s = stream();
    const loading = deferred<(d: Uint8ClampedArray, w: number, h: number) => string | null>();
    const env: CameraEnv = {
      mediaDevices: { getUserMedia: async () => s.media },
      loadDecoder: () => loading.promise,
      createCanvas: () => ({ width: 0, height: 0, getContext: () => null }),
      setInterval: () => 1,
      clearInterval: () => {},
    };
    const run = new AbortController();
    const started = startQrCamera(video(), () => {}, env, run.signal);
    await flush();
    run.abort();
    loading.resolve(() => null);
    await assert.rejects(started, (x: unknown) => x === "aborted");
    assert.equal(s.tracks[0].stopped, true);
  });

  it("aborting a running camera stops it", async () => {
    const s = stream();
    const env: CameraEnv = { BarcodeDetector: class { async detect() { return []; } }, mediaDevices: { getUserMedia: async () => s.media }, setInterval: () => 1, clearInterval: () => {} };
    const run = new AbortController();
    await startQrCamera(video(), () => {}, env, run.signal);
    assert.equal(s.tracks[0].stopped, false);
    run.abort();
    assert.equal(s.tracks[0].stopped, true);
  });

  it("without BarcodeDetector (iPad Safari): the lazy decoder reads downscaled in-memory frames", async () => {
    const s = stream();
    const ticks: (() => void)[] = [];
    let loads = 0;
    const sizes: [number, number][] = [];
    const canvas: FrameCanvas = {
      width: 0,
      height: 0,
      getContext: () => ({
        drawImage: (_v, _x, _y, w, h) => sizes.push([w, h]),
        getImageData: (_x, _y, w, h) => ({ data: new Uint8ClampedArray(w * h * 4), width: w, height: h }),
      }),
    };
    const env: CameraEnv = {
      mediaDevices: { getUserMedia: async () => s.media },
      loadDecoder: async () => {
        loads++;
        let n = 0;
        return (data, w, h) => (assert.equal(data.length, w * h * 4), n++ === 0 ? null : TOKEN);
      },
      createCanvas: () => canvas,
      setInterval: (fn) => ticks.push(fn),
      clearInterval: () => {
        ticks.length = 0;
      },
    };
    assert.equal(cameraSupported(env), true, "offered without BarcodeDetector");
    assert.equal(cameraSupported({ ...env, loadDecoder: undefined }), false);
    assert.equal(loads, 0, "nothing is loaded until the camera is opened");
    const codes: string[] = [];
    await startQrCamera(video(1920, 1080), (c) => codes.push(c), env, new AbortController().signal);
    assert.equal(loads, 1);
    ticks[0]();
    await flush();
    ticks[0]?.();
    await flush();
    assert.deepEqual(codes, [TOKEN]);
    assert.deepEqual(sizes[0], [DECODE_MAX_SIDE, 270], "downscaled to the longest side");
    assert.equal(s.tracks[0].stopped, true);
  });

  it("the real lazy decoder (jsQR) reads a member QR frame", async () => {
    const decode = await browserCameraEnv().loadDecoder!();
    const m = encodeQr(TOKEN);
    const scale = 6;
    const side = (m.length + 8) * scale;
    const data = new Uint8ClampedArray(side * side * 4).fill(255);
    m.forEach((row, y) => row.forEach((dark, x) => {
      if (!dark) return;
      for (let dy = 0; dy < scale; dy++) for (let dx = 0; dx < scale; dx++) {
        const p = ((y + 4) * scale + dy) * side + (x + 4) * scale + dx;
        data[p * 4] = data[p * 4 + 1] = data[p * 4 + 2] = 0;
      }
    }));
    assert.equal(decode(data, side, side), TOKEN);
  });
});

describe("STAGE-05D round 2: scan screen (DOM)", () => {
  it("no camera: the scanner field has focus on arrival and again after a lookup", async () => {
    const { members } = await mountApp(true);
    members.lookupReplies = [{ kind: "not_found" }];
    await dom.click(dom.document.querySelector('[data-evc-action="qr"]')!);
    const field = dom.document.getElementById("evc-scan-code") as HTMLInputElement;
    assert.equal(dom.document.activeElement, field, "a hand scanner can type straight away");
    for (const attr of [["autocomplete", "off"], ["autocorrect", "off"], ["autocapitalize", "off"], ["spellcheck", "false"]]) assert.equal(field.getAttribute(attr[0]), attr[1], attr[0]);
    await type(field, toGreekLayout(TOKEN));
    await dom.focus(button("Αναζήτηση"));
    assert.notEqual(dom.document.activeElement, field, "sanity: focus left the field (as a tap on the button does)");
    await dom.click(button("Αναζήτηση"));
    await act(async () => {});
    assert.deepEqual(members.lookups, [toGreekLayout(TOKEN)]);
    assert.equal(dom.document.activeElement, dom.document.getElementById("evc-scan-code"), "focus is back for the next scan");
    assert.ok(dom.document.querySelector('[role="status"]') && dom.document.querySelector('[role="alert"]'), "two fixed live regions");
  });

  it("429: a moving countdown, then the scan re-opens by itself when Retry-After has passed", async () => {
    let now = 1_000_000;
    const timers = fakeTimers();
    const ctx = await readyCounter(true, () => now, timers);
    await dom.render(createElement(StaffApp, { controller: ctx.controller, counter: ctx.flow, camera: NO_CAMERA }));
    ctx.members.lookupReplies = [{ kind: "rate_limited", retryAfterSeconds: 90 }];
    await dom.click(dom.document.querySelector('[data-evc-action="qr"]')!);
    await type(dom.document.getElementById("evc-scan-code") as HTMLInputElement, TOKEN);
    await dom.click(button("Αναζήτηση"));
    await act(async () => {});
    assert.match(text(), /Δοκιμάστε ξανά σε 1:30\./);
    assert.equal((dom.document.getElementById("evc-scan-code") as HTMLInputElement).disabled, true);
    assert.equal(timers.live.size, 1, "one redraw timer while waiting");
    now += 31_000;
    await act(async () => timers.tick());
    assert.match(text(), /Δοκιμάστε ξανά σε 0:59\./, "the countdown moves");
    now += 59_000;
    await act(async () => timers.tick());
    assert.match(text(), /Μπορείτε να σαρώσετε ξανά\./);
    assert.equal((dom.document.getElementById("evc-scan-code") as HTMLInputElement).disabled, false, "re-enabled without any click");
    assert.equal(timers.live.size, 0, "the timer is gone");
  });

  it("camera asked for, then the screen locks before the browser grants it: the stream is stopped", async () => {
    const tracks = [{ stopped: false, stop() { this.stopped = true; } }];
    const granted = deferred<MediaStream>();
    const camera: CameraEnv = { BarcodeDetector: class { async detect() { return []; } }, mediaDevices: { getUserMedia: () => granted.promise }, setInterval: () => 1, clearInterval: () => {} };
    const ctx = await readyCounter(true);
    await dom.render(createElement(StaffApp, { controller: ctx.controller, counter: ctx.flow, camera }));
    await dom.click(dom.document.querySelector('[data-evc-action="qr"]')!);
    assert.equal(dom.document.activeElement?.textContent, "Σάρωση QR μέλους", "with a camera, focus on the heading");
    await dom.click(button("Άνοιγμα κάμερας"));
    await act(async () => ctx.controller.lock());
    assert.match(text(), /Η οθόνη κλειδώθηκε/);
    await act(async () => granted.resolve({ getTracks: () => tracks } as unknown as MediaStream));
    await act(async () => {});
    assert.equal(tracks[0].stopped, true, "no camera left running behind the lock screen");
  });

  it("«Κλείσιμο κάμερας» while the browser is still asking also stops the late stream", async () => {
    const tracks = [{ stopped: false, stop() { this.stopped = true; } }];
    const granted = deferred<MediaStream>();
    const camera: CameraEnv = { BarcodeDetector: class { async detect() { return []; } }, mediaDevices: { getUserMedia: () => granted.promise }, setInterval: () => 1, clearInterval: () => {} };
    const ctx = await readyCounter(true);
    await dom.render(createElement(StaffApp, { controller: ctx.controller, counter: ctx.flow, camera }));
    await dom.click(dom.document.querySelector('[data-evc-action="qr"]')!);
    await dom.click(button("Άνοιγμα κάμερας"));
    await dom.click(button("Κλείσιμο κάμερας"));
    await act(async () => granted.resolve({ getTracks: () => tracks } as unknown as MediaStream));
    await act(async () => {});
    assert.equal(tracks[0].stopped, true);
    button("Άνοιγμα κάμερας");
  });
});
