import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { clubError } from "../../src/club/errors";
import { mapErrorResponse } from "../../src/club/restClubService";
import { ClubApiError } from "../../src/club/types";
import type { StaffConfig } from "../../src/staff/config";
import { StaffApiError, type SessionStatus, type StaffApi } from "../../src/staff/staffApi";
import { authLossOf, StaffController } from "../../src/staff/staffController";
import { MemoryStorage } from "../club/helpers";

/**
 * Task 1D-C: protected-request authorization failure gate and
 * latest-request-wins session checks. Deterministic: deferred promises only,
 * no timers, no wall clock. No member endpoint and no mock member data.
 */

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
  sessionSignals: AbortSignal[] = [];
  staffFetch: typeof fetch = async () => {
    throw new Error("not used");
  };
  currentNonce = () => CONFIG.nonce;
  getSession(signal?: AbortSignal) {
    this.sessionCalls++;
    if (signal) this.sessionSignals.push(signal);
    return this.sessionResult();
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

async function ready() {
  const api = new FakeApi();
  const navigations: string[] = [];
  const controller = new StaffController({ api, config: CONFIG, navigate: (u) => navigations.push(u), storage: new MemoryStorage() });
  await controller.boot();
  assert.equal(controller.getState().phase, "ready");
  return { api, controller, navigations };
}

/** A ClubApiError exactly as the REST adapter builds it from a real response. */
const httpError = (status: number, code?: string) =>
  mapErrorResponse(status, code ? JSON.stringify({ error: { code, message: "server text never shown" } }) : "", null);

const MEMBER = { member: "synthetic, test-only" };

describe("authorization-loss boundary (authLossOf)", () => {
  it("accepts only our own error types with code AND status agreeing", () => {
    assert.equal(authLossOf(httpError(401, "unauthorized")), "unauthorized");
    assert.equal(authLossOf(httpError(403, "forbidden")), "forbidden");
    assert.equal(authLossOf(httpError(401)), "unauthorized", "bare 401 from a real response");
    assert.equal(authLossOf(httpError(403)), "forbidden");
    assert.equal(authLossOf(new StaffApiError("unauthorized", 401)), "unauthorized");
    assert.equal(authLossOf(new StaffApiError("forbidden", 403)), "forbidden");
  });

  it("never trusts lookalikes, client-made errors or business answers", () => {
    const untrusted: unknown[] = [
      { code: "unauthorized", httpStatus: 401 },
      { kind: "unauthorized", status: 401 },
      Object.assign(new Error("unauthorized"), { code: "unauthorized", httpStatus: 401 }),
      new ClubApiError("unauthorized", "client-made"), // no HTTP status
      new ClubApiError("forbidden", "mismatch", undefined, 401),
      new ClubApiError("membership_inactive", "x", undefined, 401),
      new StaffApiError("unauthorized"), // no status
      new StaffApiError("forbidden", 401),
      httpError(409, "membership_inactive"),
      httpError(409, "benefit_already_redeemed"),
      httpError(409, "idempotency_key_reused"),
      httpError(404, "member_not_found"),
      httpError(400, "invalid_request"),
      httpError(429, "rate_limited"),
      httpError(500, "server_error"),
      httpError(503),
      clubError("network_error"),
      new StaffApiError("timeout"),
      new StaffApiError("network"),
      new TypeError("boom"),
      null,
      "unauthorized",
    ];
    for (const e of untrusted) assert.equal(authLossOf(e), null, JSON.stringify(e) ?? String(e));
  });
});

describe("protected operations: 401 / 403 fail closed", () => {
  const cases = [
    ["A: ClubApiError 401", () => httpError(401, "unauthorized"), "expired", "unauthorized"],
    ["B: ClubApiError 403", () => httpError(403, "forbidden"), "forbidden", "forbidden"],
    ["C: StaffApiError 401", () => new StaffApiError("unauthorized", 401), "expired", "unauthorized"],
    ["C: StaffApiError 403", () => new StaffApiError("forbidden", 403), "forbidden", "forbidden"],
  ] as const;

  for (const [name, makeError, phase, reason] of cases) {
    it(`${name} -> ${phase}, sensitive data cleared (D, E)`, async () => {
      const { controller } = await ready();
      assert.equal(controller.setSensitive(MEMBER), true);
      let calls = 0;
      const result = await controller.runProtected(async () => {
        calls++;
        throw makeError();
      });
      assert.deepEqual(result, { status: "auth_lost", reason });
      assert.equal(controller.getState().phase, phase);
      assert.equal(controller.getSensitive(), null);
      assert.equal(controller.setSensitive(MEMBER), false, "cannot be refilled");
      assert.equal(calls, 1, "K: never retried automatically");
    });
  }

  it("F: other in-flight protected requests are aborted and their late results discarded", async () => {
    const { controller } = await ready();
    controller.setSensitive(MEMBER);
    const slow = deferred<string>();
    let slowSignal: AbortSignal | undefined;
    const other = controller.runProtected((signal) => {
      slowSignal = signal;
      return slow.promise;
    });
    const failing = controller.runProtected(async () => {
      throw httpError(401, "unauthorized");
    });
    assert.deepEqual(await failing, { status: "auth_lost", reason: "unauthorized" });
    assert.equal(slowSignal?.aborted, true, "aborted");
    slow.resolve("member data that arrived too late");
    assert.deepEqual(await other, { status: "stale" });
    assert.equal(controller.getSensitive(), null);
  });

  it("G: a late success after 403 can never return the app to ready", async () => {
    const { controller, api } = await ready();
    const late = deferred<string>();
    const pending = controller.runProtected(() => late.promise);
    await controller.runProtected(async () => {
      throw httpError(403, "forbidden");
    });
    late.resolve("late");
    assert.deepEqual(await pending, { status: "stale" });
    assert.equal(controller.getState().phase, "forbidden");
    // Not even a fresh status check reopens it: forbidden needs a real re-login.
    await controller.refreshStatus();
    assert.equal(controller.getState().phase, "forbidden");
    assert.equal(api.sessionCalls, 1, "no status call from forbidden");
  });

  it("after auth loss further protected work is refused without calling the server", async () => {
    const { controller } = await ready();
    await controller.runProtected(async () => {
      throw httpError(401, "unauthorized");
    });
    let called = false;
    const result = await controller.runProtected(async () => {
      called = true;
      return "x";
    });
    assert.deepEqual(result, { status: "stale" });
    assert.equal(called, false);
  });

  it("re-authentication still goes only through WordPress (reauth=1)", async () => {
    const { controller, navigations } = await ready();
    await controller.runProtected(async () => {
      throw httpError(401, "unauthorized");
    });
    controller.reauthenticate();
    assert.deepEqual(navigations, [CONFIG.loginUrl]);
    assert.equal(controller.getState().phase, "expired");
  });
});

describe("protected operations: business and transport failures keep the session", () => {
  const cases: [string, () => unknown, string][] = [
    ["H: 409 membership_inactive", () => httpError(409, "membership_inactive"), "membership_inactive"],
    ["I: 409 benefit_already_redeemed", () => httpError(409, "benefit_already_redeemed"), "benefit_already_redeemed"],
    ["409 idempotency_key_reused", () => httpError(409, "idempotency_key_reused"), "idempotency_key_reused"],
    ["J: 404 member_not_found", () => httpError(404, "member_not_found"), "member_not_found"],
    ["J: 400 invalid_request", () => httpError(400, "invalid_request"), "invalid_request"],
    ["J: 429 rate_limited", () => httpError(429, "rate_limited"), "rate_limited"],
    ["J: 500 server_error", () => httpError(500, "server_error"), "server_error"],
    ["J: 503 without envelope", () => httpError(503), "server_error"],
    ["J: network", () => clubError("network_error"), "network_error"],
    ["client-made unauthorized without HTTP status", () => new ClubApiError("unauthorized", "client"), "unauthorized"],
  ];
  for (const [name, makeError, code] of cases) {
    it(`${name}: ready kept, error returned unchanged, no retry`, async () => {
      const { controller } = await ready();
      controller.setSensitive(MEMBER);
      let calls = 0;
      const error = makeError();
      const result = await controller.runProtected(async () => {
        calls++;
        throw error;
      });
      assert.equal(result.status, "error");
      assert.ok(result.status === "error" && result.error === error, "same error object, not re-wrapped");
      assert.ok(result.status === "error" && result.error instanceof ClubApiError && result.error.code === code);
      assert.equal(controller.getState().phase, "ready");
      assert.deepEqual(controller.getSensitive(), MEMBER);
      assert.equal(calls, 1, "K: no automatic replay");
    });
  }

  it("J: staff transport timeout / network keep their kind", async () => {
    for (const kind of ["timeout", "network", "server"] as const) {
      const { controller } = await ready();
      const result = await controller.runProtected(async () => {
        throw new StaffApiError(kind);
      });
      assert.ok(result.status === "error" && result.error instanceof StaffApiError && result.error.kind === kind, kind);
      assert.equal(controller.getState().phase, "ready");
    }
  });

  it("an unexpected thrown value is a non-auth network error, never an auth loss", async () => {
    const { controller } = await ready();
    const result = await controller.runProtected(async () => {
      throw { code: "unauthorized", httpStatus: 401 };
    });
    assert.ok(result.status === "error" && result.error instanceof StaffApiError && result.error.kind === "network");
    assert.equal(controller.getState().phase, "ready");
  });
});

describe("session status checks: latest request wins", () => {
  it("L: an older success arriving last cannot overwrite a newer failure", async () => {
    const { api, controller } = await ready();
    const older = deferred<SessionStatus>();
    api.sessionResult = () => older.promise;
    const first = controller.refreshStatus();
    api.sessionResult = async () => {
      throw new StaffApiError("network");
    };
    await controller.refreshStatus();
    assert.equal(controller.getState().phase, "offline");
    assert.equal(api.sessionSignals[1].aborted, true, "older check aborted when superseded");
    older.resolve(SESSION);
    await first;
    assert.equal(controller.getState().phase, "offline", "outdated ready not restored");
  });

  it("L: from offline, an older success arriving last does not reopen after a newer failure", async () => {
    const { api, controller } = await ready();
    api.sessionResult = async () => {
      throw new StaffApiError("network");
    };
    await controller.refreshStatus();
    const older = deferred<SessionStatus>();
    api.sessionResult = () => older.promise;
    const first = controller.refreshStatus();
    api.sessionResult = async () => {
      throw new StaffApiError("server", 500);
    };
    await controller.refreshStatus();
    assert.equal(controller.getState().phase, "error");
    older.resolve(SESSION);
    await first;
    assert.equal(controller.getState().phase, "error");
  });

  it("M: an older ordinary failure arriving last cannot overwrite a newer success", async () => {
    const { api, controller } = await ready();
    const older = deferred<SessionStatus>();
    api.sessionResult = () => older.promise;
    const first = controller.refreshStatus();
    api.sessionResult = async () => SESSION;
    await controller.refreshStatus();
    // Ready stayed ready (no epoch change), so only supersession can stop the older request.
    assert.equal(api.sessionSignals[1].aborted, true, "older check aborted by the newer one");
    assert.equal(api.sessionSignals[2].aborted, false, "newest check never aborted");
    older.reject(new StaffApiError("network"));
    await first;
    assert.equal(controller.getState().phase, "ready");
  });

  it("M: an older 401 arriving last still fails closed (authorization loss always wins)", async () => {
    for (const [error, phase] of [
      [new StaffApiError("unauthorized", 401), "expired"],
      [new StaffApiError("forbidden", 403), "forbidden"],
    ] as const) {
      const { api, controller } = await ready();
      controller.setSensitive(MEMBER);
      const older = deferred<SessionStatus>();
      api.sessionResult = () => older.promise;
      const first = controller.refreshStatus();
      api.sessionResult = async () => SESSION;
      await controller.refreshStatus();
      older.reject(error);
      await first;
      assert.equal(controller.getState().phase, phase);
      assert.equal(controller.getSensitive(), null);
    }
  });

  it("an aborted superseded check never shows an error screen", async () => {
    const { api, controller } = await ready();
    const older = deferred<SessionStatus>();
    api.sessionResult = () => older.promise;
    const first = controller.refreshStatus();
    api.sessionResult = async () => SESSION;
    await controller.refreshStatus();
    older.reject(new StaffApiError("aborted"));
    await first;
    assert.equal(controller.getState().phase, "ready");
  });

  it("N: a status check completing after lock leaves the app locked", async () => {
    const { api, controller } = await ready();
    const slow = deferred<SessionStatus>();
    api.sessionResult = () => slow.promise;
    const check = controller.refreshStatus();
    await controller.lock();
    slow.resolve(SESSION);
    await check;
    assert.equal(controller.getState().phase, "locked");
  });

  it("O: a status check completing after a protected 403 leaves the app forbidden", async () => {
    const { api, controller } = await ready();
    const slow = deferred<SessionStatus>();
    api.sessionResult = () => slow.promise;
    const check = controller.refreshStatus();
    await controller.runProtected(async () => {
      throw httpError(403, "forbidden");
    });
    slow.resolve(SESSION);
    await check;
    assert.equal(controller.getState().phase, "forbidden");
  });

  it("O: a status check completing after a protected 401 leaves the app expired", async () => {
    const { api, controller } = await ready();
    const slow = deferred<SessionStatus>();
    api.sessionResult = () => slow.promise;
    const check = controller.refreshStatus();
    await controller.runProtected(async () => {
      throw httpError(401, "unauthorized");
    });
    slow.resolve(SESSION);
    await check;
    assert.equal(controller.getState().phase, "expired");
  });

  it("P: boot racing a visibility re-check ends ready; offline recovery still works", async () => {
    const api = new FakeApi();
    const controller = new StaffController({ api, config: CONFIG, navigate: () => undefined, storage: new MemoryStorage() });
    const bootCheck = deferred<SessionStatus>();
    api.sessionResult = () => bootCheck.promise;
    const booting = controller.boot();
    api.sessionResult = async () => SESSION;
    await controller.refreshStatus();
    assert.equal(controller.getState().phase, "ready");
    bootCheck.reject(new StaffApiError("network"));
    await booting;
    assert.equal(controller.getState().phase, "ready", "superseded boot failure ignored");

    api.sessionResult = async () => {
      throw new StaffApiError("timeout");
    };
    await controller.refreshStatus();
    assert.equal(controller.getState().phase, "offline");
    api.sessionResult = async () => SESSION;
    await controller.refreshStatus();
    assert.equal(controller.getState().phase, "ready");
  });

  it("the newest check's own result is applied even while an older one is still pending", async () => {
    const { api, controller } = await ready();
    const older = deferred<SessionStatus>();
    api.sessionResult = () => older.promise;
    void controller.refreshStatus();
    api.sessionResult = async () => {
      throw new StaffApiError("unauthorized", 401);
    };
    await controller.refreshStatus();
    assert.equal(controller.getState().phase, "expired");
    older.resolve(SESSION);
    await Promise.resolve();
    assert.equal(controller.getState().phase, "expired");
  });
});
