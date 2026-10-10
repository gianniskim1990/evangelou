import assert from "node:assert/strict";
import { describe, it } from "node:test";
import type { StaffConfig } from "../../src/staff/config";
import { StaffApiError, type SessionStatus, type StaffApi } from "../../src/staff/staffApi";
import { LOCK_MARKER_KEY, StaffController } from "../../src/staff/staffController";
import { MemoryStorage } from "../club/helpers";

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

const SESSION: SessionStatus = {
  expiresAt: "2026-10-10T22:00:00+03:00",
  idleTimeoutSeconds: 1800,
  features: { qrLookup: false, phoneLookup: false, coffeeRedemption: false, history: false },
};

class FakeApi implements StaffApi {
  sessionCalls = 0;
  endCalls = 0;
  sessionResult: () => Promise<SessionStatus> = async () => SESSION;
  endResult: () => Promise<void> = async () => undefined;
  staffFetch: typeof fetch = async () => {
    throw new Error("not used");
  };
  currentNonce = () => CONFIG.nonce;
  getSession() {
    this.sessionCalls++;
    return this.sessionResult();
  }
  endSession() {
    this.endCalls++;
    return this.endResult();
  }
}

function setup(storage = new MemoryStorage()) {
  const api = new FakeApi();
  const navigations: string[] = [];
  const controller = new StaffController({ api, config: CONFIG, navigate: (u) => navigations.push(u), storage });
  return { api, controller, navigations, storage };
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

const fail = (kind: "unauthorized" | "forbidden" | "network" | "timeout" | "server") => async () => {
  throw new StaffApiError(kind);
};

describe("StaffController — session bootstrap and recovery", () => {
  it("boots from loading by verifying the real session (I)", async () => {
    const { api, controller } = setup();
    assert.equal(controller.getState().phase, "loading");
    await controller.boot();
    assert.deepEqual(controller.getState(), { phase: "ready", session: SESSION });
    assert.equal(api.sessionCalls, 1);
  });

  it("maps 401 to expired, 403 to forbidden, network/timeout to offline, 5xx to error", async () => {
    const cases = [["unauthorized", "expired"], ["forbidden", "forbidden"], ["network", "offline"], ["timeout", "offline"], ["server", "error"]] as const;
    for (const [kind, phase] of cases) {
      const { api, controller } = setup();
      api.sessionResult = fail(kind);
      await controller.boot();
      assert.equal(controller.getState().phase, phase, kind);
    }
  });

  it("offline/error recover to ready ONLY after a successful session check (M)", async () => {
    for (const first of ["network", "server"] as const) {
      const { api, controller } = setup();
      api.sessionResult = fail(first);
      await controller.boot();
      assert.notEqual(controller.getState().phase, "ready");
      api.sessionResult = fail("timeout");
      await controller.refreshStatus();
      assert.equal(controller.getState().phase, "offline", "still not ready while unverified");
      api.sessionResult = async () => SESSION;
      await controller.refreshStatus();
      assert.equal(controller.getState().phase, "ready");
    }
  });

  it("recovery from offline can discover an expired session", async () => {
    const { api, controller } = setup();
    api.sessionResult = fail("network");
    await controller.boot();
    api.sessionResult = fail("unauthorized");
    await controller.refreshStatus();
    assert.equal(controller.getState().phase, "expired");
  });

  it("forbidden, expired and locked do not run session checks", async () => {
    for (const kind of ["forbidden", "unauthorized"] as const) {
      const { api, controller } = setup();
      api.sessionResult = fail(kind);
      await controller.boot();
      api.sessionResult = async () => SESSION;
      await controller.refreshStatus();
      assert.notEqual(controller.getState().phase, "ready", kind);
      assert.equal(api.sessionCalls, 1);
    }
  });
});

describe("StaffController — protected operations are ready-only (fail closed)", () => {
  it("non-ready states never run protected operations (J)", async () => {
    const states: [string, () => Promise<StaffController>][] = [
      ["loading", async () => setup().controller],
      ["forbidden", async () => { const s = setup(); s.api.sessionResult = fail("forbidden"); await s.controller.boot(); return s.controller; }],
      ["offline", async () => { const s = setup(); s.api.sessionResult = fail("network"); await s.controller.boot(); return s.controller; }],
      ["error", async () => { const s = setup(); s.api.sessionResult = fail("server"); await s.controller.boot(); return s.controller; }],
      ["expired", async () => { const s = setup(); s.api.sessionResult = fail("unauthorized"); await s.controller.boot(); return s.controller; }],
      ["locked", async () => { const s = setup(); await s.controller.boot(); await s.controller.lock(); return s.controller; }],
    ];
    for (const [name, make] of states) {
      const controller = await make();
      assert.equal(controller.getState().phase, name);
      let called = false;
      const result = await controller.runProtected(async () => {
        called = true;
        return "member data";
      });
      assert.deepEqual(result, { status: "stale" }, name);
      assert.equal(called, false, `${name}: protected operation never started`);
    }
  });

  it("non-ready states can neither accept nor reveal sensitive data (K)", async () => {
    const { api, controller } = setup();
    assert.equal(controller.setSensitive({ member: "x" }), false, "loading");
    await controller.boot();
    assert.equal(controller.setSensitive({ member: "x" }), true, "ready");
    assert.deepEqual(controller.getSensitive(), { member: "x" });

    api.sessionResult = fail("network");
    await controller.refreshStatus();
    assert.equal(controller.getState().phase, "offline");
    assert.equal(controller.getSensitive(), null, "not revealed offline");
    assert.equal(controller.setSensitive({ member: "y" }), false, "not accepted offline");

    api.sessionResult = async () => SESSION;
    await controller.refreshStatus();
    assert.equal(controller.getState().phase, "ready");
    assert.equal(controller.getSensitive(), null, "data cleared on leaving ready; NOT restored on recovery");
  });

  it("a late protected response after authorization loss is discarded (L)", async () => {
    for (const kind of ["forbidden", "unauthorized", "network", "server"] as const) {
      const { api, controller } = setup();
      await controller.boot();
      const late = deferred<string>();
      let aborted = false;
      const pending = controller.runProtected((signal) => {
        signal.addEventListener("abort", () => (aborted = true));
        return late.promise;
      });
      api.sessionResult = fail(kind);
      await controller.refreshStatus();
      assert.notEqual(controller.getState().phase, "ready", kind);
      late.resolve("private member data");
      assert.deepEqual(await pending, { status: "stale" }, kind);
      assert.equal(aborted, true, `${kind}: in-flight request aborted`);
      assert.equal(controller.getSensitive(), null);
    }
  });

  it("a late protected response is discarded even after offline → ready recovery", async () => {
    const { api, controller } = setup();
    await controller.boot();
    const late = deferred<string>();
    const pending = controller.runProtected(() => late.promise);
    api.sessionResult = fail("network");
    await controller.refreshStatus();
    api.sessionResult = async () => SESSION;
    await controller.refreshStatus();
    assert.equal(controller.getState().phase, "ready");
    late.resolve("private member data from the previous epoch");
    assert.deepEqual(await pending, { status: "stale" });
  });

  it("a late protected response cannot survive a lock", async () => {
    const { controller } = setup();
    await controller.boot();
    const late = deferred<string>();
    const pending = controller.runProtected(() => late.promise);
    await controller.lock();
    late.resolve("private member data");
    assert.deepEqual(await pending, { status: "stale" });
  });

  it("a late session-status success after lock does not reopen the app", async () => {
    const { api, controller } = setup();
    await controller.boot();
    const slow = deferred<SessionStatus>();
    api.sessionResult = () => slow.promise;
    const check = controller.refreshStatus();
    await controller.lock();
    slow.resolve(SESSION);
    await check;
    assert.equal(controller.getState().phase, "locked");
  });
});

describe("StaffController — lock, bounded logout and real re-authentication", () => {
  it("lock clears sensitive data, records the marker and confirms revocation", async () => {
    const { api, controller, storage } = setup();
    await controller.boot();
    controller.setSensitive({ member: "x" });
    await controller.lock();
    assert.deepEqual(controller.getState(), { phase: "locked", revocation: "confirmed" });
    assert.equal(controller.getSensitive(), null);
    assert.equal(storage.getItem(LOCK_MARKER_KEY), CONFIG.sessionRef);
    assert.ok(!JSON.stringify([...storage.data.values()]).includes(CONFIG.nonce), "nonce never persisted");
    assert.equal(api.endCalls, 1);
  });

  it("while ending is pending the app is locked and re-login is still possible (C)", async () => {
    const { api, controller, navigations } = setup();
    await controller.boot();
    const hang = deferred<void>();
    api.endResult = () => hang.promise;
    const locking = controller.lock();
    assert.deepEqual(controller.getState(), { phase: "locked", revocation: "pending" });
    controller.reauthenticate(); // never waits on the pending request
    assert.deepEqual(navigations, [CONFIG.loginUrl]);
    hang.reject(new StaffApiError("timeout"));
    await locking;
    assert.deepEqual(controller.getState(), { phase: "locked", revocation: "unconfirmed" });
  });

  it("a failed/unknown logout is reported unconfirmed, never as success, and never unlocks (D, E)", async () => {
    // 401/403 included: a missing or stale nonce yields them while the session may still be alive.
    for (const kind of ["timeout", "network", "server", "unauthorized", "forbidden"] as const) {
      const { api, controller } = setup();
      await controller.boot();
      api.endResult = fail(kind);
      await controller.lock();
      assert.deepEqual(controller.getState(), { phase: "locked", revocation: "unconfirmed" }, kind);
      assert.equal(controller.isReady, false);
    }
  });

  it("an explicit retry of ending the session can succeed (F)", async () => {
    const { api, controller } = setup();
    await controller.boot();
    api.endResult = fail("timeout");
    await controller.lock();
    assert.equal((controller.getState() as { revocation: string }).revocation, "unconfirmed");
    api.endResult = async () => undefined;
    await controller.retryEndSession();
    assert.deepEqual(controller.getState(), { phase: "locked", revocation: "confirmed" });
    assert.equal(api.endCalls, 2);
    await controller.retryEndSession();
    assert.equal(api.endCalls, 2, "no further calls once confirmed");
  });

  it("concurrent lock/retry share one in-flight end request", async () => {
    const { api, controller } = setup();
    await controller.boot();
    const hang = deferred<void>();
    api.endResult = () => hang.promise;
    const a = controller.lock();
    const b = controller.retryEndSession();
    hang.resolve();
    await Promise.all([a, b]);
    assert.equal(api.endCalls, 1);
  });

  it("refuses protected work while locked (no redemption possible)", async () => {
    const { controller } = setup();
    await controller.boot();
    await controller.lock();
    let called = false;
    const result = await controller.runProtected(async () => {
      called = true;
      return "redeemed";
    });
    assert.deepEqual(result, { status: "stale" });
    assert.equal(called, false);
  });

  it("a reload of the same locked session stays locked and re-ends it", async () => {
    const storage = new MemoryStorage();
    storage.setItem(LOCK_MARKER_KEY, CONFIG.sessionRef);
    const { api, controller } = setup(storage);
    await controller.boot();
    assert.equal(controller.getState().phase, "locked");
    assert.equal(api.sessionCalls, 0, "no status call (and no data) before re-login");
    assert.equal(api.endCalls, 1);
  });

  it("a new login (different session) clears an old marker", async () => {
    const storage = new MemoryStorage();
    storage.setItem(LOCK_MARKER_KEY, "ffffffffffffffffffffffffffffffff");
    const { controller } = setup(storage);
    await controller.boot();
    assert.equal(controller.getState().phase, "ready");
    assert.equal(storage.getItem(LOCK_MARKER_KEY), null);
  });

  it("unlocking always goes through a real WordPress re-login (reauth=1)", async () => {
    const { controller, navigations } = setup();
    await controller.boot();
    await controller.lock();
    controller.reauthenticate();
    assert.deepEqual(navigations, [CONFIG.loginUrl]);
    assert.ok(navigations[0].includes("reauth=1"));
    assert.equal(controller.getState().phase, "locked", "never unlocks in place");
  });

  it("logout locks, ends the session and navigates to login", async () => {
    const { api, controller, navigations } = setup();
    await controller.boot();
    await controller.logout();
    assert.equal(api.endCalls, 1);
    assert.deepEqual(navigations, [CONFIG.loginUrl]);
  });

  it("works without sessionStorage", async () => {
    const api = new FakeApi();
    const controller = new StaffController({ api, config: CONFIG, navigate: () => undefined, storage: null });
    await controller.boot();
    await controller.lock();
    assert.equal(controller.getState().phase, "locked");
  });
});
