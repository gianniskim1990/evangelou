import assert from "node:assert/strict";
import { describe, it } from "node:test";
import {
  MAX_RECOVERY_VALUE_LENGTH,
  RECOVERY_STORAGE_KEY,
  RecoveryStorageError,
  browserSessionStorage,
  createSessionRecoveryStorage,
  type StorageProvider,
} from "../../src/staff/redemptionRecovery/browserStorage";
import { availableActions, shouldOfferPrepare, type MachineState, type RecoveryStorage } from "../../src/staff/redemptionRecovery/machine";
import { LOCK_MARKER_KEY } from "../../src/staff/staffController";
import {
  CAPPUCCINO,
  ESPRESSO,
  FakeServer,
  MEMBER_A,
  MEMBER_B,
  SESSION_1,
  SESSION_2,
  commitThenLose,
  deferred,
  loseBeforeCommit,
  neverPrepares,
  phase,
  requestIdOf,
  tablet,
  until,
} from "./support/recoveryFixtures";

/*
 * UI-04J — the per-tab sessionStorage adapter, against fake browser Storage
 * objects (failure injection, opener cloning) and the fake server that models
 * the PHP contracts. The adapter is NOT imported by the staff app.
 */

const RID = "3fa85f64-5717-4562-b3fc-2c963f66afa6";
const HID = "7c9e6679-7425-40de-944b-e07fc1f90ae7";
const VALID = {
  v: 2,
  session_ref: SESSION_1,
  member_id: MEMBER_A,
  redemption_request_id: RID,
  payload_sha256: "a".repeat(64),
  stage: "redemption_sent",
  handoff_request_id: null,
  claim: null,
};

function domError(name: string): Error {
  return new DOMException("browser detail that must never be surfaced", name);
}

/** A browser-like Storage (one per tab) with failure injection. */
class FakeBrowserStorage implements Storage {
  private map = new Map<string, string>();
  quota = Infinity;
  failGet: string | null = null;
  failSet: string | null = null;
  failRemove: string | null = null;
  readonly setCalls: [string, string][] = [];

  get length(): number {
    return this.map.size;
  }
  key(i: number): string | null {
    return [...this.map.keys()][i] ?? null;
  }
  getItem(k: string): string | null {
    if (this.failGet) throw domError(this.failGet);
    return this.map.has(k) ? (this.map.get(k) as string) : null;
  }
  setItem(k: string, v: string): void {
    if (this.failSet) throw domError(this.failSet);
    const used = [...this.map.entries()].reduce((n, [key, val]) => n + (key === k ? 0 : key.length + val.length), 0);
    if (used + k.length + v.length > this.quota) throw domError("QuotaExceededError");
    this.setCalls.push([k, v]);
    this.map.set(k, String(v));
  }
  removeItem(k: string): void {
    if (this.failRemove) throw domError(this.failRemove);
    this.map.delete(k);
  }
  clear(): void {
    this.map.clear();
  }
  /** What a window opened with an opener may start with: a COPY of the opener's storage. */
  cloneForOpenedWindow(): FakeBrowserStorage {
    const c = new FakeBrowserStorage();
    for (const [k, v] of this.map) c.map.set(k, v);
    return c;
  }
  raw(k: string): string | null {
    return this.map.has(k) ? (this.map.get(k) as string) : null;
  }
}

function adapterFor(store: FakeBrowserStorage): { storage: RecoveryStorage; browser: FakeBrowserStorage } {
  return { storage: createSessionRecoveryStorage(() => store), browser: store };
}

function failureOf(fn: () => unknown): string {
  try {
    fn();
  } catch (err) {
    assert.ok(err instanceof RecoveryStorageError, String(err));
    assert.ok(!err.message.includes("browser detail"), "browser messages never surface");
    assert.ok(!err.message.includes(RID) && !err.message.includes(SESSION_1), "values never surface");
    return err.failure;
  }
  assert.fail("expected a RecoveryStorageError");
}

function reasonOf(s: MachineState): string | null {
  return s.phase === "recovery_required" ? s.reason : null;
}

describe("UI-04J sessionStorage adapter: boundary", () => {
  it("never touches window at import time and resolves storage lazily", () => {
    assert.equal(typeof (globalThis as { window?: unknown }).window, "undefined", "Node test runtime has no window");
    // The default provider is safe to call without a browser: no store, no throw.
    assert.equal(browserSessionStorage(), null);
    assert.equal(failureOf(() => createSessionRecoveryStorage().read()), "unavailable");
    let calls = 0;
    const store = new FakeBrowserStorage();
    const lazy = createSessionRecoveryStorage(() => {
      calls++;
      return store;
    });
    assert.equal(calls, 0, "creating the adapter does not reach the browser");
    lazy.read();
    lazy.write(JSON.stringify(VALID));
    lazy.remove();
    assert.equal(calls, 3, "resolved on every operation (no cached handle)");
  });

  it("uses one namespaced key that never collides with the lock marker or other data", () => {
    assert.equal(RECOVERY_STORAGE_KEY, "evc-staff-redemption-recovery:v2");
    assert.notEqual(RECOVERY_STORAGE_KEY, LOCK_MARKER_KEY);
    const { storage, browser } = adapterFor(new FakeBrowserStorage());
    browser.setItem(LOCK_MARKER_KEY, SESSION_1);
    browser.setItem("unrelated", "keep");
    storage.write(JSON.stringify(VALID));
    assert.equal(storage.read(), JSON.stringify(VALID));
    storage.remove();
    assert.equal(browser.raw(LOCK_MARKER_KEY), SESSION_1);
    assert.equal(browser.raw("unrelated"), "keep");
    assert.equal(browser.raw(RECOVERY_STORAGE_KEY), null);
    assert.equal(browser.length, 2);
  });

  it("stores only a valid v2 envelope (data minimisation enforced at the boundary)", () => {
    const { storage, browser } = adapterFor(new FakeBrowserStorage());
    const refused: unknown[] = [
      "{not json",
      JSON.stringify({ ...VALID, v: 1 }),
      JSON.stringify({ ...VALID, nonce: "0123456789" }),
      JSON.stringify({ ...VALID, qr_token: "evc_" + "ab".repeat(24) }),
      JSON.stringify({ ...VALID, lookup_ref: "lkp1.k1.x.y" }),
      JSON.stringify({ ...VALID, phone: "+306900000001" }),
      JSON.stringify({ ...VALID, member_name: "Μαρία" }),
      JSON.stringify({ ...VALID, manually_verified: true }),
      JSON.stringify({ ...VALID, payload: ESPRESSO }),
      JSON.stringify({ ...VALID, payload_sha256: "0123abcd" }),
      JSON.stringify({ ...VALID, member_id: "member-1" }),
      JSON.stringify({ ...VALID, session_ref: "not-a-ref" }),
      " ".repeat(MAX_RECOVERY_VALUE_LENGTH + 1),
      42,
    ];
    for (const value of refused) {
      assert.equal(failureOf(() => storage.write(value as string)), "refused", String(value).slice(0, 40));
    }
    assert.equal(browser.setCalls.length, 0, "nothing reached the browser");
    const ok = JSON.stringify({ ...VALID, stage: "handoff_sent", handoff_request_id: HID, claim: "handed_now" });
    storage.write(ok);
    assert.equal(browser.raw(RECOVERY_STORAGE_KEY), ok);
    assert.ok(ok.length < MAX_RECOVERY_VALUE_LENGTH, `${ok.length} chars`);
  });

  it("maps every browser failure to an error, never to 'nothing saved'", () => {
    assert.equal(failureOf(() => createSessionRecoveryStorage(() => null).read()), "unavailable");
    assert.equal(failureOf(() => createSessionRecoveryStorage(() => undefined).write(JSON.stringify(VALID))), "unavailable");
    assert.equal(failureOf(() => createSessionRecoveryStorage(() => ({}) as Storage).remove()), "unavailable");
    const security: StorageProvider = () => {
      throw domError("SecurityError");
    };
    assert.equal(failureOf(() => createSessionRecoveryStorage(security).read()), "security");
    assert.equal(failureOf(() => createSessionRecoveryStorage(() => {
      throw new TypeError("no storage");
    }).read()), "unavailable");

    const store = new FakeBrowserStorage();
    const { storage } = adapterFor(store);
    store.failGet = "SecurityError";
    assert.equal(failureOf(() => storage.read()), "security");
    store.failGet = "UnknownError";
    assert.equal(failureOf(() => storage.read()), "failed");
    store.failGet = null;
    store.quota = 50;
    assert.equal(failureOf(() => storage.write(JSON.stringify(VALID))), "quota");
    store.quota = Infinity;
    store.failSet = "NS_ERROR_DOM_QUOTA_REACHED";
    assert.equal(failureOf(() => storage.write(JSON.stringify(VALID))), "quota");
    store.failSet = null;
    store.failRemove = "InvalidStateError";
    assert.equal(failureOf(() => storage.remove()), "failed");
  });

  it("never logs", () => {
    const original = { log: console.log, info: console.info, warn: console.warn, error: console.error, debug: console.debug };
    let logged = 0;
    for (const k of Object.keys(original) as (keyof typeof original)[]) {
      console[k] = () => {
        logged++;
      };
    }
    try {
      const store = new FakeBrowserStorage();
      const { storage } = adapterFor(store);
      storage.write(JSON.stringify(VALID));
      storage.read();
      store.failGet = "SecurityError";
      store.failSet = "QuotaExceededError";
      store.failRemove = "UnknownError";
      for (const op of [() => storage.read(), () => storage.write(JSON.stringify(VALID)), () => storage.remove(), () => storage.write("{bad")]) {
        try {
          op();
        } catch {
          // expected
        }
      }
    } finally {
      Object.assign(console, original);
    }
    assert.equal(logged, 0);
  });
});

describe("UI-04J recovery through the browser adapter", () => {
  function browserTablet(server: FakeServer, store: FakeBrowserStorage, session = SESSION_1) {
    return tablet(server, { storage: createSessionRecoveryStorage(() => store), session });
  }

  it("same-tab reload re-reads the v2 context, asks the server first and never prepares again", async () => {
    const server = new FakeServer();
    const store = new FakeBrowserStorage();
    const t = browserTablet(server, store);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    assert.equal(shouldOfferPrepare(t.machine.getState()), true, "the fresh commit prepares exactly once");
    const rid = requestIdOf(t.machine);

    const reloaded = browserTablet(server, store);
    assert.equal(server.calls.filter((c) => c.op === "status").length, 0);
    await reloaded.machine.restore();
    assert.deepEqual(server.calls.map((c) => c.op), ["redeem", "status"], "status read before any action");
    assert.equal(phase(reloaded.machine), "previously_recorded_unconfirmed");
    neverPrepares(reloaded.machine.getState());
    assert.equal(requestIdOf(reloaded.machine), rid);
    assert.equal(reloaded.idsMinted(), 0, "no id is minted by recovery");
  });

  it("an unknown redemption outcome keeps the original id across a reload and never assumes failure", async () => {
    for (const [hook, expected] of [[commitThenLose, "previously_recorded_unconfirmed"], [loseBeforeCommit, "recovery_required"]] as const) {
      const server = new FakeServer();
      const store = new FakeBrowserStorage();
      const t = browserTablet(server, store);
      server.hooks.redeem.push(hook);
      await t.machine.startRedemption(MEMBER_A, ESPRESSO);
      assert.equal(phase(t.machine), "redemption_unresolved");
      const rid = requestIdOf(t.machine);

      const reloaded = browserTablet(server, store);
      await reloaded.machine.restore();
      assert.equal(phase(reloaded.machine), expected);
      assert.equal(requestIdOf(reloaded.machine), rid);
      neverPrepares(reloaded.machine.getState());
      if (expected === "recovery_required") {
        assert.equal(reasonOf(reloaded.machine.getState()), "not_recorded");
        // Only the SAME order may be resubmitted, under the SAME id.
        assert.equal(await reloaded.machine.resubmitSameIntent(CAPPUCCINO), "rejected");
        await reloaded.machine.resubmitSameIntent(ESPRESSO);
        assert.equal(requestIdOf(reloaded.machine), rid);
        assert.equal(phase(reloaded.machine), "recorded_now", "the first commit happens now, with the original id");
      }
      assert.equal(new Set(server.calls.filter((c) => c.op === "redeem").map((c) => c.id)).size, 1, "one request id ever");
      assert.equal(reloaded.idsMinted(), 0);
    }
  });

  it("an unknown handoff outcome keeps the original handoff id and never invents a handoff", async () => {
    for (const [hook, expected] of [[commitThenLose, "handoff_confirmed"], [loseBeforeCommit, "handoff_unresolved"]] as const) {
      const server = new FakeServer();
      const store = new FakeBrowserStorage();
      const t = browserTablet(server, store);
      await t.machine.startRedemption(MEMBER_A, ESPRESSO);
      t.machine.acknowledgePrepare();
      server.hooks.handoff.push(hook);
      await t.machine.confirmHandoff("handed_now");
      const s = t.machine.getState();
      assert.equal(s.phase, "handoff_unresolved");
      const hid = s.phase === "handoff_unresolved" ? s.handoffIntent.handoffRequestId : "";

      const reloaded = browserTablet(server, store);
      await reloaded.machine.restore();
      const r = reloaded.machine.getState();
      assert.equal(r.phase, expected);
      if (r.phase === "handoff_unresolved") {
        assert.equal(r.handoffIntent.handoffRequestId, hid);
        assert.equal(server.handoffCount(), 0, "nothing recorded, nothing invented");
        await reloaded.machine.retryHandoff();
        assert.equal(phase(reloaded.machine), "handoff_confirmed");
      }
      if (r.phase === "handoff_confirmed") assert.equal(r.handoff.handoffRequestId, hid);
      assert.deepEqual([...new Set(server.calls.filter((c) => c.op === "handoff").map((c) => c.id))], [hid]);
      assert.equal(server.handoffCount(), 1);
      neverPrepares(reloaded.machine.getState());
    }
  });

  it("manual verification is never persisted and resets on reload and on a fresh status read", async () => {
    const server = new FakeServer();
    const store = new FakeBrowserStorage();
    const t = browserTablet(server, store);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const reloaded = browserTablet(server, store);
    await reloaded.machine.restore();
    const before = store.raw(RECOVERY_STORAGE_KEY);
    assert.equal(reloaded.machine.confirmManualVerification(), "applied");
    assert.equal(store.raw(RECOVERY_STORAGE_KEY), before, "not written");
    assert.ok(!(before as string).includes("verif"));
    await reloaded.machine.checkStatus();
    const s = reloaded.machine.getState();
    assert.equal(s.phase === "previously_recorded_unconfirmed" && s.manuallyVerified, false);
    reloaded.machine.confirmManualVerification();
    const again = browserTablet(server, store);
    await again.machine.restore();
    const a = again.machine.getState();
    assert.equal(a.phase === "previously_recorded_unconfirmed" && a.manuallyVerified, false);
  });

  it("a different login session never adopts the context automatically", async () => {
    const server = new FakeServer();
    const store = new FakeBrowserStorage();
    const t = browserTablet(server, store);
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const rid = requestIdOf(t.machine);
    // Logout/lock: the ids stay for a later AUTHORISED recovery; nothing is discarded.
    t.machine.sessionEnded("logged_out");
    assert.ok(store.raw(RECOVERY_STORAGE_KEY)?.includes(rid));

    const relogged = browserTablet(server, store, SESSION_2);
    const calls = server.calls.length;
    await relogged.machine.restore();
    assert.equal(reasonOf(relogged.machine.getState()), "session_changed");
    assert.deepEqual(availableActions(relogged.machine.getState()), ["resume_recovery"]);
    assert.equal(server.calls.length, calls, "no request before the explicit resume");
    await relogged.machine.resumeRecovery();
    assert.equal(server.calls.at(-1)?.op, "status");
    assert.equal(phase(relogged.machine), "previously_recorded_unconfirmed");
    assert.equal(requestIdOf(relogged.machine), rid);
    neverPrepares(relogged.machine.getState());
    // Rebound to the CURRENT session only after the explicit resume.
    assert.equal(JSON.parse(store.raw(RECOVERY_STORAGE_KEY) as string).session_ref, SESSION_2);
  });

  it("a cross-member context never grants access to another member", async () => {
    const server = new FakeServer();
    const store = new FakeBrowserStorage();
    const t = browserTablet(server, store);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const env = JSON.parse(store.raw(RECOVERY_STORAGE_KEY) as string);
    store.setItem(RECOVERY_STORAGE_KEY, JSON.stringify({ ...env, member_id: MEMBER_B }));
    const forged = browserTablet(server, store);
    await forged.machine.restore();
    assert.equal(reasonOf(forged.machine.getState()), "not_recorded", "the server does not show A's redemption for B");
    await forged.machine.resubmitSameIntent(ESPRESSO);
    assert.equal(phase(forged.machine), "redemption_refused");
    const s = forged.machine.getState();
    assert.equal(s.phase === "redemption_refused" && s.code, "idempotency_key_reused");
    neverPrepares(s);
    assert.equal(server.redemptions.size, 1);
  });
});

describe("UI-04J cross-tab and shared-account safety", () => {
  function browserTablet(server: FakeServer, store: FakeBrowserStorage, session = SESSION_1) {
    return tablet(server, { storage: createSessionRecoveryStorage(() => store), session });
  }

  it("two independent tabs keep independent state and the server allows one coffee", async () => {
    const server = new FakeServer();
    const s1 = new FakeBrowserStorage();
    const s2 = new FakeBrowserStorage();
    const tab1 = browserTablet(server, s1);
    const tab2 = browserTablet(server, s2);
    await tab1.machine.startRedemption(MEMBER_A, ESPRESSO);
    await tab2.machine.startRedemption(MEMBER_A, CAPPUCCINO);
    assert.equal(phase(tab1.machine), "recorded_now");
    assert.equal(phase(tab2.machine), "redemption_refused");
    neverPrepares(tab2.machine.getState());
    assert.equal(s2.raw(RECOVERY_STORAGE_KEY), null, "a refusal clears only its own tab");
    assert.ok(s1.raw(RECOVERY_STORAGE_KEY), "tab 1 untouched");
  });

  it("a cloned opener context can never prepare another coffee", async () => {
    // (a) Cloned while the original request is still in flight.
    const server = new FakeServer();
    const s1 = new FakeBrowserStorage();
    const tab1 = browserTablet(server, s1);
    const held = deferred<void>();
    server.hooks.redeem.push(async (real) => {
      await held.promise;
      return real();
    });
    const pending = tab1.machine.startRedemption(MEMBER_A, ESPRESSO);
    await until(() => server.calls.length === 1);
    const opened = browserTablet(server, s1.cloneForOpenedWindow());
    await opened.machine.restore();
    assert.equal(reasonOf(opened.machine.getState()), "not_recorded", "not committed yet");
    held.resolve();
    await pending;
    // The clone resubmits the SAME intent: an idempotent replay, never a second "prepare".
    await opened.machine.resubmitSameIntent(ESPRESSO);
    const prepares = [tab1, opened].filter((t) => shouldOfferPrepare(t.machine.getState())).length;
    assert.equal(prepares, 1);
    assert.equal(phase(opened.machine), "previously_recorded_unconfirmed");
    assert.equal(server.redemptions.size, 1);

    // (b) Cloned after the commit.
    const server2 = new FakeServer();
    const s3 = new FakeBrowserStorage();
    const tab3 = browserTablet(server2, s3);
    await tab3.machine.startRedemption(MEMBER_A, ESPRESSO);
    const clone = browserTablet(server2, s3.cloneForOpenedWindow());
    await clone.machine.restore();
    neverPrepares(clone.machine.getState());
    assert.equal(phase(clone.machine), "previously_recorded_unconfirmed");
    // A NEW intent in the clone is refused by the server's one-coffee-per-day rule.
    clone.machine.finish();
    await clone.machine.startRedemption(MEMBER_A, CAPPUCCINO);
    assert.equal(phase(clone.machine), "redemption_refused");
  });

  it("concurrent resumes of the same saved redemption record one handoff and never prepare", async () => {
    const server = new FakeServer();
    const origin = new FakeBrowserStorage();
    const t = browserTablet(server, origin);
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const a = browserTablet(server, origin.cloneForOpenedWindow());
    const b = browserTablet(server, origin.cloneForOpenedWindow());
    await Promise.all([a.machine.restore(), b.machine.restore()]);
    for (const x of [a, b]) {
      assert.equal(phase(x.machine), "previously_recorded_unconfirmed");
      neverPrepares(x.machine.getState());
      x.machine.confirmManualVerification();
    }
    await Promise.all([a.machine.confirmHandoff("handed_now"), b.machine.confirmHandoff("already_handed_earlier")]);
    const vias = [a, b].map((x) => {
      const s = x.machine.getState();
      return s.phase === "handoff_confirmed" ? s.via : s.phase;
    });
    assert.deepEqual(vias.sort(), ["already_confirmed", "confirmed"]);
    assert.equal(server.handoffCount(), 1);
  });

  it("two tablets on the shared account (different sessions) never adopt each other's intent", async () => {
    const server = new FakeServer();
    const tablet1 = browserTablet(server, new FakeBrowserStorage(), SESSION_1);
    const s2 = new FakeBrowserStorage();
    const tablet2 = browserTablet(server, s2, SESSION_2);
    server.hooks.redeem.push(commitThenLose);
    await tablet1.machine.startRedemption(MEMBER_A, ESPRESSO);
    await tablet2.machine.restore();
    assert.equal(phase(tablet2.machine), "idle", "its own empty tab store");
    // Even handed a copy of tablet 1's store, it needs the explicit, server-checked resume.
    const copied = browserTablet(server, new FakeBrowserStorage(), SESSION_2);
    const foreign = new FakeBrowserStorage();
    const anyStore = (tablet1.storage as RecoveryStorage).read() as string;
    foreign.setItem(RECOVERY_STORAGE_KEY, anyStore);
    const adopted = browserTablet(server, foreign, SESSION_2);
    await adopted.machine.restore();
    assert.equal(reasonOf(adopted.machine.getState()), "session_changed");
    assert.equal(phase(copied.machine), "idle");
  });

  it("stale replies never change a newer state", async () => {
    const server = new FakeServer();
    const store = new FakeBrowserStorage();
    const t = browserTablet(server, store);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const held = deferred<void>();
    server.hooks.status.push(async (real) => {
      await held.promise;
      return real();
    });
    const reloaded = browserTablet(server, store);
    const restoring = reloaded.machine.restore();
    await until(() => server.calls.at(-1)?.op === "status");
    reloaded.machine.sessionEnded("locked");
    held.resolve();
    assert.equal(await restoring, "stale");
    assert.equal(phase(reloaded.machine), "blocked_authorization");
    assert.ok(store.raw(RECOVERY_STORAGE_KEY), "the unresolved ids are kept");
  });
});

describe("UI-04J storage failures never produce a false recovery", () => {
  function browserTablet(server: FakeServer, store: FakeBrowserStorage | (() => Storage | null), session = SESSION_1) {
    const provider = typeof store === "function" ? store : () => store;
    return tablet(server, { storage: createSessionRecoveryStorage(provider), session });
  }

  it("an unreadable or unavailable store blocks (storage_unavailable) and never discards the saved intent", async () => {
    const server = new FakeServer();
    const store = new FakeBrowserStorage();
    const t = browserTablet(server, store);
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const saved = store.raw(RECOVERY_STORAGE_KEY);
    const rid = requestIdOf(t.machine);

    for (const failing of [
      () => {
        throw domError("SecurityError");
      },
      () => null,
      "read",
    ] as const) {
      const provider = failing === "read" ? () => {
        store.failGet = "UnknownError";
        return store;
      } : failing;
      const reloaded = browserTablet(server, provider as () => Storage | null);
      await reloaded.machine.restore();
      const s = reloaded.machine.getState();
      assert.equal(reasonOf(s), "storage_unavailable");
      assert.deepEqual(availableActions(s), ["retry_restore", "manager_review"]);
      assert.equal(reloaded.machine.abandonIntent(), "rejected");
      assert.equal(await reloaded.machine.startRedemption(MEMBER_A, CAPPUCCINO), "rejected", "no new intent over an unread one");
      store.failGet = null;
      assert.equal(store.raw(RECOVERY_STORAGE_KEY), saved, "never cleared");
    }
    // The store works again: the retried restore recovers the ORIGINAL intent through the server.
    let available = false;
    const flaky = browserTablet(server, () => (available ? store : null));
    await flaky.machine.restore();
    available = true;
    assert.equal(await flaky.machine.restore(), "applied");
    assert.equal(phase(flaky.machine), "previously_recorded_unconfirmed");
    assert.equal(requestIdOf(flaky.machine), rid);
    neverPrepares(flaky.machine.getState());
  });

  it("restore is only a boot step: it never overrides a live flow", async () => {
    const server = new FakeServer();
    const t = browserTablet(server, new FakeBrowserStorage());
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    assert.equal(await t.machine.restore(), "rejected");
    assert.equal(shouldOfferPrepare(t.machine.getState()), true);
  });

  it("malformed, truncated, outdated or foreign envelopes never recover anything", async () => {
    const cases: [string, string][] = [
      ["{not json", "storage_corrupt"],
      [JSON.stringify(VALID).slice(0, 60), "storage_corrupt"],
      [JSON.stringify({ ...VALID, v: 1 }), "storage_outdated"],
      [JSON.stringify({ ...VALID, payload_sha256: "f".repeat(63) }), "storage_corrupt"],
      [JSON.stringify({ ...VALID, payload_sha256: "F".repeat(64) }), "storage_corrupt"],
      [JSON.stringify({ ...VALID, member_id: "mem_X" }), "storage_corrupt"],
      [JSON.stringify({ ...VALID, session_ref: "zz" }), "storage_corrupt"],
      [JSON.stringify({ ...VALID, nonce: "0123456789" }), "storage_corrupt"],
      ["x".repeat(MAX_RECOVERY_VALUE_LENGTH * 4), "storage_corrupt"],
      [JSON.stringify({ ...VALID, session_ref: SESSION_2 }), "session_changed"],
    ];
    for (const [raw, reason] of cases) {
      const server = new FakeServer();
      const store = new FakeBrowserStorage();
      store.setItem(RECOVERY_STORAGE_KEY, raw);
      const t = browserTablet(server, store);
      await t.machine.restore();
      assert.equal(reasonOf(t.machine.getState()), reason, raw.slice(0, 50));
      assert.equal(server.calls.length, 0, "nothing sent from an untrusted envelope");
      neverPrepares(t.machine.getState());
    }
  });

  it("a failed write (quota) sends nothing; the SAME intent is sent once the store works (UI-04K closes the UI-04J durability gap)", async () => {
    const server = new FakeServer();
    const store = new FakeBrowserStorage();
    store.quota = 10;
    const t = browserTablet(server, store);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    assert.equal(phase(t.machine), "persistence_blocked");
    assert.equal(server.calls.length, 0, "no request without a durable recovery context");
    assert.equal(store.raw(RECOVERY_STORAGE_KEY), null);
    neverPrepares(t.machine.getState());
    const rid = requestIdOf(t.machine);
    store.quota = Infinity;
    await t.machine.retryPersistence();
    assert.equal(requestIdOf(t.machine), rid, "no new id because storage failed");
    assert.equal(phase(t.machine), "recorded_now");
    assert.equal(JSON.parse(store.raw(RECOVERY_STORAGE_KEY) as string).redemption_request_id, rid);
    // A reload now recovers the id and never prepares again.
    const reloaded = browserTablet(server, store);
    await reloaded.machine.restore();
    assert.equal(phase(reloaded.machine), "previously_recorded_unconfirmed");
    neverPrepares(reloaded.machine.getState());
    reloaded.machine.finish();
    await reloaded.machine.startRedemption(MEMBER_A, CAPPUCCINO);
    assert.equal(phase(reloaded.machine), "redemption_refused", "one coffee per Athens day stays server-authoritative");
  });

  it("a failed remove leaves only a settled context, which can never prepare", async () => {
    const server = new FakeServer();
    const store = new FakeBrowserStorage();
    const t = browserTablet(server, store);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    t.machine.acknowledgePrepare();
    await t.machine.confirmHandoff("handed_now");
    store.failRemove = "UnknownError";
    assert.equal(t.machine.finish(), "applied");
    assert.equal(phase(t.machine), "idle");
    store.failRemove = null;
    const reloaded = browserTablet(server, store);
    await reloaded.machine.restore();
    assert.equal(phase(reloaded.machine), "previously_recorded_confirmed");
    neverPrepares(reloaded.machine.getState());
  });

  it("everything ever written is a minimal v2 envelope with no secret or personal data", async () => {
    const server = new FakeServer();
    const store = new FakeBrowserStorage();
    const t = browserTablet(server, store);
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    await t.machine.checkStatus();
    t.machine.confirmManualVerification();
    await t.machine.confirmHandoff("already_handed_earlier");
    assert.ok(store.setCalls.length >= 3);
    for (const [key, value] of store.setCalls) {
      assert.equal(key, RECOVERY_STORAGE_KEY);
      const o = JSON.parse(value);
      assert.deepEqual(Object.keys(o).sort(), ["claim", "handoff_request_id", "member_id", "payload_sha256", "redemption_request_id", "session_ref", "stage", "v"]);
      for (const forbidden of ["espresso", "double", "takeaway", "nonce", "verif", "phone", "evc_ab", "lkp1"]) {
        assert.ok(!value.includes(forbidden), `${forbidden} in ${value}`);
      }
    }
  });
});
