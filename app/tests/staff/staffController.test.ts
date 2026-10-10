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

describe("StaffController", () => {
  it("boots to ready from the real session endpoint", async () => {
    const { controller } = setup();
    await controller.boot();
    assert.deepEqual(controller.getState(), { phase: "ready", session: SESSION });
  });

  it("maps 401 to expired, 403 to forbidden, network to offline", async () => {
    for (const [kind, phase] of [["unauthorized", "expired"], ["forbidden", "forbidden"], ["network", "offline"], ["server", "error"]] as const) {
      const { api, controller } = setup();
      api.sessionResult = async () => {
        throw new StaffApiError(kind);
      };
      await controller.boot();
      assert.equal(controller.getState().phase, phase, kind);
    }
  });

  it("lock clears sensitive data, records the marker and ends the server session", async () => {
    const { api, controller, storage } = setup();
    await controller.boot();
    assert.equal(controller.setSensitive({ member: "x" }), true);
    await controller.lock();
    assert.deepEqual(controller.getState(), { phase: "locked", serverEnded: true, ending: false });
    assert.equal(controller.getSensitive(), null);
    assert.equal(controller.setSensitive({ member: "y" }), false, "nothing sensitive can be stored while locked");
    assert.equal(storage.getItem(LOCK_MARKER_KEY), CONFIG.sessionRef);
    assert.ok(!JSON.stringify([...storage.data.values()]).includes(CONFIG.nonce), "nonce never persisted");
    assert.equal(api.endCalls, 1);
  });

  it("aborts in-flight requests and discards their late responses after lock", async () => {
    const { controller } = setup();
    await controller.boot();
    let release!: (v: string) => void;
    let aborted = false;
    const pending = controller.run(
      (signal) =>
        new Promise<string>((resolve) => {
          signal.addEventListener("abort", () => (aborted = true));
          release = resolve;
        }),
    );
    await controller.lock();
    release("private member data");
    assert.deepEqual(await pending, { status: "stale" });
    assert.equal(aborted, true);
    assert.equal(controller.getSensitive(), null);
  });

  it("discards a late response once the account lost access (forbidden) mid-flight", async () => {
    const { api, controller } = setup();
    await controller.boot();
    let release!: (v: string) => void;
    const pending = controller.run(() => new Promise<string>((resolve) => (release = resolve)));
    api.sessionResult = async () => {
      throw new StaffApiError("forbidden");
    };
    await controller.refreshStatus();
    assert.equal(controller.getState().phase, "forbidden");
    release("private member data");
    assert.deepEqual(await pending, { status: "stale" }, "older-epoch result is never applied");
  });

  it("refuses new requests while locked (no redemption possible)", async () => {
    const { controller } = setup();
    await controller.boot();
    await controller.lock();
    let called = false;
    const result = await controller.run(async () => {
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
    const { api, controller, navigations } = setup();
    await controller.boot();
    api.endResult = async () => {
      throw new StaffApiError("network");
    };
    await controller.lock();
    assert.deepEqual(controller.getState(), { phase: "locked", serverEnded: false, ending: false });
    api.endResult = async () => undefined;
    await controller.reauthenticate();
    assert.equal(api.endCalls, 2, "retries ending the server session first");
    assert.deepEqual(navigations, [CONFIG.loginUrl]);
    assert.ok(navigations[0].includes("reauth=1"));
    assert.equal(controller.getState().phase, "locked", "never unlocks in place");
  });

  it("a session already gone server-side counts as ended", async () => {
    const { api, controller } = setup();
    await controller.boot();
    api.endResult = async () => {
      throw new StaffApiError("unauthorized");
    };
    await controller.lock();
    assert.deepEqual(controller.getState(), { phase: "locked", serverEnded: true, ending: false });
  });

  it("logout locks, ends the session and navigates to login", async () => {
    const { api, controller, navigations } = setup();
    await controller.boot();
    await controller.logout();
    assert.equal(api.endCalls, 1);
    assert.deepEqual(navigations, [CONFIG.loginUrl]);
  });

  it("an expired session clears data and offers only a real login", async () => {
    const { api, controller, navigations } = setup();
    await controller.boot();
    controller.setSensitive({ member: "x" });
    api.sessionResult = async () => {
      throw new StaffApiError("unauthorized");
    };
    await controller.refreshStatus();
    assert.equal(controller.getState().phase, "expired");
    assert.equal(controller.getSensitive(), null);
    await controller.reauthenticate();
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
