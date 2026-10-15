import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { createSessionRecoveryStorage, RECOVERY_STORAGE_KEY } from "../../src/staff/redemptionRecovery/browserStorage";
import { availableActions, parsePersisted, shouldOfferPrepare, type MachineState, type RecoveryStorage } from "../../src/staff/redemptionRecovery/machine";
import {
  CAPPUCCINO,
  ESPRESSO,
  FakeServer,
  MEMBER_A,
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
 * UI-04K — persistence BEFORE side effects. A redemption or handoff request
 * may reach the server only after its recovery context (original ids, order
 * identity, claim) was durably written to the per-tab store AND read back.
 * The first describe block reproduced the gap: all of its cases FAILED on
 * the unchanged machine (requests were sent although the write failed).
 */

/** A RecoveryStorage with scripted failure modes (per-tab, in memory). */
class ScriptedStorage implements RecoveryStorage {
  value: string | null = null;
  writes: string[] = [];
  /** write throws (nothing stored) */
  failWrite: string | null = null;
  /** write STORES the value, then throws */
  storeThenThrow: string | null = null;
  /** write reports success but stores nothing */
  dropWrites = false;
  /** write stores a different value */
  corruptWrites = false;
  /** read throws */
  failRead = false;
  failRemove = false;

  read(): string | null {
    if (this.failRead) throw domError("SecurityError");
    return this.value;
  }
  write(v: string): void {
    if (this.failWrite) throw domError(this.failWrite);
    if (this.dropWrites) return;
    this.writes.push(v);
    this.value = this.corruptWrites ? v.replace('"v":2', '"v":2 ') : v;
    if (this.storeThenThrow) throw domError(this.storeThenThrow);
  }
  remove(): void {
    if (this.failRemove) throw domError("UnknownError");
    this.value = null;
  }
  healthy(): void {
    this.failWrite = null;
    this.storeThenThrow = null;
    this.dropWrites = false;
    this.corruptWrites = false;
    this.failRead = false;
    this.failRemove = false;
  }
  clone(): ScriptedStorage {
    const c = new ScriptedStorage();
    c.value = this.value;
    return c;
  }
}

function domError(name: string): Error {
  return new DOMException("browser detail", name);
}

function sent(server: FakeServer, op: "redeem" | "handoff" | "status"): string[] {
  return server.calls.filter((c) => c.op === op).map((c) => c.id);
}

type Blocked = Extract<MachineState, { phase: "persistence_blocked" }>;

function blocked(s: MachineState): Blocked {
  assert.equal(s.phase, "persistence_blocked", JSON.stringify(s));
  return s as Blocked;
}

function stored(storage: ScriptedStorage) {
  return storage.value === null ? null : JSON.parse(storage.value);
}

const FAILURES: [string, (s: ScriptedStorage) => void][] = [
  ["write throws SecurityError", (s) => (s.failWrite = "SecurityError")],
  ["write throws QuotaExceededError", (s) => (s.failWrite = "QuotaExceededError")],
  ["write stored the value but then threw", (s) => (s.storeThenThrow = "UnknownError")],
  ["write appeared to succeed but stored nothing", (s) => (s.dropWrites = true)],
  ["write appeared to succeed but stored something else", (s) => (s.corruptWrites = true)],
  ["read-back throws", (s) => (s.failRead = true)],
];

describe("UI-04K the durability gap (reproduced first; failed before the fix)", () => {
  for (const [label, arm] of FAILURES) {
    it(`a NEW redemption is never sent when ${label}`, async () => {
      const server = new FakeServer();
      const storage = new ScriptedStorage();
      arm(storage);
      const t = tablet(server, { storage });
      await t.machine.startRedemption(MEMBER_A, ESPRESSO);
      assert.deepEqual(sent(server, "redeem"), [], "the request must not reach the server without a durable context");
      neverPrepares(t.machine.getState());
      assert.equal(server.redemptions.size, 0);
    });
  }

  it("a NEW handoff statement is never sent without a durable handoff id and claim", async () => {
    const server = new FakeServer();
    const storage = new ScriptedStorage();
    const t = tablet(server, { storage });
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    t.machine.acknowledgePrepare();
    storage.failWrite = "QuotaExceededError";
    await t.machine.confirmHandoff("handed_now");
    assert.deepEqual(sent(server, "handoff"), []);
    assert.equal(server.handoffCount(), 0);
  });
});

describe("UI-04K redemption: write-before-send gate", () => {
  for (const [label, arm] of FAILURES) {
    it(`${label}: blocked with the SAME id; recovers and sends only once the store works`, async () => {
      const server = new FakeServer();
      const storage = new ScriptedStorage();
      arm(storage);
      const t = tablet(server, { storage });
      await t.machine.startRedemption(MEMBER_A, ESPRESSO);
      const b = blocked(t.machine.getState());
      assert.equal(b.operation, "redemption");
      assert.equal(b.mayHaveBeenSent, false);
      assert.deepEqual(availableActions(b), ["retry_persistence", "abandon_intent"]);
      const rid = b.intent.requestId;

      // Still failing: stays blocked, same id, nothing sent, no new id minted.
      await t.machine.retryPersistence();
      assert.equal(blocked(t.machine.getState()).intent.requestId, rid);
      assert.deepEqual(sent(server, "redeem"), []);
      assert.equal(t.idsMinted(), 1, "no id is minted because storage failed");

      storage.healthy();
      await t.machine.retryPersistence();
      assert.equal(phase(t.machine), "recorded_now");
      assert.equal(shouldOfferPrepare(t.machine.getState()), true, "the genuine first commit prepares exactly once");
      assert.equal(requestIdOf(t.machine), rid);
      assert.deepEqual(sent(server, "redeem"), [rid]);
      assert.equal(stored(storage).redemption_request_id, rid);
      assert.equal(t.idsMinted(), 1);
    });
  }

  it("storage unavailable at the initial send (browser adapter without a store)", async () => {
    const server = new FakeServer();
    let store: Storage | null = null;
    const t = tablet(server, { storage: createSessionRecoveryStorage(() => store) });
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    blocked(t.machine.getState());
    assert.equal(server.calls.length, 0);
    // The store appears (e.g. a privacy setting was relaxed): the SAME intent is sent.
    const map = new Map<string, string>();
    store = {
      get length() {
        return map.size;
      },
      key: () => null,
      getItem: (k) => map.get(k) ?? null,
      setItem: (k, v) => void map.set(k, v),
      removeItem: (k) => void map.delete(k),
      clear: () => map.clear(),
    } as Storage;
    await t.machine.retryPersistence();
    assert.equal(phase(t.machine), "recorded_now");
    assert.ok(map.get(RECOVERY_STORAGE_KEY));
  });

  it("a value written before the exception is harmless: nothing was sent, a reload finds 'not recorded'", async () => {
    const server = new FakeServer();
    const storage = new ScriptedStorage();
    storage.storeThenThrow = "UnknownError";
    const t = tablet(server, { storage });
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const rid = blocked(t.machine.getState()).intent.requestId;
    assert.equal(stored(storage).redemption_request_id, rid, "the browser kept the value");
    storage.healthy();
    const reloaded = tablet(server, { storage });
    await reloaded.machine.restore();
    const s = reloaded.machine.getState();
    assert.equal(s.phase === "recovery_required" && s.reason, "not_recorded", "the server never received it");
    assert.equal(requestIdOf(reloaded.machine), rid);
    neverPrepares(s);
    // The SAME order under the SAME id may now be submitted (never a different order).
    assert.equal(await reloaded.machine.resubmitSameIntent(CAPPUCCINO), "rejected");
    await reloaded.machine.resubmitSameIntent(ESPRESSO);
    assert.equal(phase(reloaded.machine), "recorded_now");
    assert.deepEqual(sent(server, "redeem"), [rid]);
  });

  it("an explicitly abandoned never-sent intent is dropped; only an explicit new intent mints a new id", async () => {
    const server = new FakeServer();
    const storage = new ScriptedStorage();
    storage.storeThenThrow = "UnknownError";
    const t = tablet(server, { storage });
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const rid = blocked(t.machine.getState()).intent.requestId;
    assert.equal(t.machine.finish(), "rejected");
    assert.equal(t.machine.abandonIntent(), "applied");
    assert.equal(phase(t.machine), "idle");
    assert.equal(storage.value, null, "the never-sent context is cleared");
    storage.healthy();
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    assert.notEqual(requestIdOf(t.machine), rid);
    assert.equal(t.idsMinted(), 2);
    assert.equal(server.redemptions.size, 1);
  });

  it("a retry of the SAME intent after an unknown commit is blocked as 'may have been sent' and can only be retried or checked", async () => {
    const server = new FakeServer();
    const storage = new ScriptedStorage();
    const t = tablet(server, { storage });
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    assert.equal(phase(t.machine), "redemption_unresolved");
    const rid = requestIdOf(t.machine);
    const durable = storage.value;
    assert.equal(stored(storage).stage, "redemption_sent", "the first send was durable");

    storage.failWrite = "QuotaExceededError";
    await t.machine.retryRedemption();
    const b = blocked(t.machine.getState());
    assert.equal(b.mayHaveBeenSent, true);
    assert.equal(b.intent.requestId, rid);
    assert.deepEqual(availableActions(b), ["retry_persistence", "check_status"]);
    assert.equal(t.machine.abandonIntent(), "rejected", "a possibly committed intent is never abandoned");
    assert.equal(t.machine.finish(), "rejected");
    assert.equal(storage.value, durable, "the earlier durable context is untouched");
    assert.deepEqual(sent(server, "redeem"), [rid], "the retry was not sent");

    // Status is read-only: allowed, and it never prepares.
    await t.machine.checkStatus();
    assert.equal(phase(t.machine), "previously_recorded_unconfirmed");
    neverPrepares(t.machine.getState());
  });

  it("the blocked retry of a possibly committed intent is sent with the same id once the store works and never prepares", async () => {
    const server = new FakeServer();
    const storage = new ScriptedStorage();
    const t = tablet(server, { storage });
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const rid = requestIdOf(t.machine);
    storage.failRead = true;
    await t.machine.retryRedemption();
    blocked(t.machine.getState());
    storage.healthy();
    await t.machine.retryPersistence();
    assert.equal(phase(t.machine), "previously_recorded_unconfirmed", "an exact replay");
    neverPrepares(t.machine.getState());
    assert.deepEqual(sent(server, "redeem"), [rid, rid]);
    assert.equal(server.redemptions.size, 1);
  });

  it("a server timeout after a durable send keeps the same-id recovery (reload included)", async () => {
    for (const hook of [loseBeforeCommit, commitThenLose]) {
      const server = new FakeServer();
      const storage = new ScriptedStorage();
      const t = tablet(server, { storage });
      server.hooks.redeem.push(hook);
      await t.machine.startRedemption(MEMBER_A, ESPRESSO);
      assert.equal(phase(t.machine), "redemption_unresolved");
      const rid = requestIdOf(t.machine);
      assert.equal(stored(storage).redemption_request_id, rid);
      const reloaded = tablet(server, { storage });
      await reloaded.machine.restore();
      assert.equal(requestIdOf(reloaded.machine), rid);
      neverPrepares(reloaded.machine.getState());
      assert.equal(reloaded.idsMinted(), 0);
    }
  });

  it("a reload while blocked recovers the last DURABLE context (never the unsent one)", async () => {
    const server = new FakeServer();
    const storage = new ScriptedStorage();
    const t = tablet(server, { storage });
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const rid = requestIdOf(t.machine);
    storage.failWrite = "SecurityError";
    await t.machine.retryRedemption();
    blocked(t.machine.getState());
    // Reload with a store that can be read but still not written.
    const reloaded = tablet(server, { storage });
    await reloaded.machine.restore();
    assert.equal(phase(reloaded.machine), "previously_recorded_unconfirmed");
    assert.equal(requestIdOf(reloaded.machine), rid);
    neverPrepares(reloaded.machine.getState());
  });
});

describe("UI-04K handoff: write-before-send gate", () => {
  async function recordedNow(storage: ScriptedStorage) {
    const server = new FakeServer();
    const t = tablet(server, { storage });
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    t.machine.acknowledgePrepare();
    return { server, t };
  }

  for (const [label, arm] of FAILURES) {
    it(`${label}: no statement is sent, the committed redemption is kept, nothing is invented`, async () => {
      const storage = new ScriptedStorage();
      const { server, t } = await recordedNow(storage);
      const rid = requestIdOf(t.machine);
      arm(storage);
      await t.machine.confirmHandoff("already_handed_earlier");
      const b = blocked(t.machine.getState());
      assert.equal(b.operation, "handoff");
      assert.equal(b.mayHaveBeenSent, false);
      assert.equal(b.intent.requestId, rid, "the committed redemption identity is kept");
      assert.equal(b.operation === "handoff" && b.record.requestId, rid);
      assert.deepEqual(availableActions(b), ["retry_persistence", "check_status", "finish"]);
      assert.deepEqual(sent(server, "handoff"), []);
      assert.equal(server.handoffCount(), 0, "no handoff invented");
      assert.equal(server.redemptions.size, 1, "no other entitlement consumed");
      neverPrepares(b);
      const hid = b.operation === "handoff" ? b.handoffIntent.handoffRequestId : "";

      storage.healthy();
      await t.machine.retryPersistence();
      const s = t.machine.getState();
      assert.equal(s.phase, "handoff_confirmed");
      assert.equal(s.phase === "handoff_confirmed" && s.handoff.handoffRequestId, hid, "the ORIGINAL handoff id");
      assert.equal(s.phase === "handoff_confirmed" && s.handoff.claim, "already_handed_earlier", "the ORIGINAL claim");
      assert.deepEqual(sent(server, "handoff"), [hid]);
      assert.equal(t.idsMinted(), 2, "one redemption id + one handoff id, nothing minted by the failure");
    });
  }

  it("a never-sent statement may be finished without one (the redemption stays committed and unconfirmed)", async () => {
    const storage = new ScriptedStorage();
    const { server, t } = await recordedNow(storage);
    storage.failWrite = "QuotaExceededError";
    await t.machine.confirmHandoff("handed_now");
    assert.equal(t.machine.abandonIntent(), "rejected", "abandon is for never-sent REDEMPTIONS only");
    assert.equal(t.machine.finish(), "applied");
    assert.equal(phase(t.machine), "idle");
    assert.equal(server.handoffCount(), 0);
    assert.equal(server.redemptions.size, 1);
  });

  it("a blocked statement can be checked by status, and its retry keeps the same handoff id", async () => {
    const storage = new ScriptedStorage();
    const { server, t } = await recordedNow(storage);
    storage.failWrite = "QuotaExceededError";
    await t.machine.confirmHandoff("handed_now");
    const hid = (blocked(t.machine.getState()) as Extract<Blocked, { operation: "handoff" }>).handoffIntent.handoffRequestId;
    await t.machine.checkStatus();
    const s = t.machine.getState();
    assert.equal(s.phase, "handoff_unresolved", "the server shows no confirmation");
    assert.equal(s.phase === "handoff_unresolved" && s.handoffIntent.handoffRequestId, hid);
    // Still unwritable: the retry is blocked as 'may have been sent' (no finish any more).
    await t.machine.retryHandoff();
    const b = blocked(t.machine.getState());
    assert.equal(b.mayHaveBeenSent, true);
    assert.deepEqual(availableActions(b), ["retry_persistence", "check_status"]);
    assert.equal(t.machine.finish(), "rejected");
    storage.healthy();
    await t.machine.retryPersistence();
    assert.equal(phase(t.machine), "handoff_confirmed");
    assert.deepEqual(sent(server, "handoff"), [hid]);
  });

  it("an unknown handoff outcome after a durable send, then a blocked retry, ends in one recorded statement", async () => {
    const storage = new ScriptedStorage();
    const { server, t } = await recordedNow(storage);
    server.hooks.handoff.push(commitThenLose);
    await t.machine.confirmHandoff("handed_now");
    assert.equal(phase(t.machine), "handoff_unresolved");
    assert.equal(stored(storage).stage, "handoff_sent");
    storage.failWrite = "QuotaExceededError";
    await t.machine.retryHandoff();
    blocked(t.machine.getState());
    storage.healthy();
    // Reload: the durable handoff id is recovered and the server shows it recorded.
    const reloaded = tablet(server, { storage });
    await reloaded.machine.restore();
    assert.equal(phase(reloaded.machine), "handoff_confirmed");
    assert.equal(server.handoffCount(), 1);
  });

  it("a store that already holds EXACTLY the needed context is durable even if the new write is silently dropped", async () => {
    // The read-back is what matters: the recovery context for this send is verifiably in the store.
    const storage = new ScriptedStorage();
    const { server, t } = await recordedNow(storage);
    server.hooks.handoff.push(commitThenLose);
    await t.machine.confirmHandoff("handed_now");
    const durable = storage.value;
    storage.dropWrites = true;
    await t.machine.retryHandoff();
    assert.equal(storage.value, durable);
    assert.equal(phase(t.machine), "handoff_confirmed", "same ids, same claim: already recoverable");
    assert.equal(server.handoffCount(), 1);
  });

  it("a manual verification is never persisted and does not survive a blocked statement's status check", async () => {
    const storage = new ScriptedStorage();
    const server = new FakeServer();
    const first = tablet(server, { storage });
    server.hooks.redeem.push(commitThenLose);
    await first.machine.startRedemption(MEMBER_A, ESPRESSO);
    await first.machine.checkStatus();
    assert.equal(first.machine.confirmManualVerification(), "applied");
    const before = storage.value;
    storage.failWrite = "QuotaExceededError";
    await first.machine.confirmHandoff("handed_now");
    blocked(first.machine.getState());
    assert.equal(storage.value, before);
    assert.ok(!(before as string).includes("verif"));
    storage.healthy();
    await first.machine.checkStatus();
    const s = first.machine.getState();
    assert.equal(s.phase, "handoff_unresolved");
  });
});

describe("UI-04K sessions, tabs, tablets and ordering", () => {
  it("logout or lock while blocked drops the in-memory intent but never the durable one; a new session needs the explicit resume", async () => {
    const server = new FakeServer();
    const storage = new ScriptedStorage();
    const t = tablet(server, { storage });
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const rid = requestIdOf(t.machine);
    storage.failWrite = "SecurityError";
    await t.machine.retryRedemption();
    blocked(t.machine.getState());
    t.machine.sessionEnded("locked");
    assert.equal(phase(t.machine), "blocked_authorization");
    assert.equal(await t.machine.retryPersistence(), "rejected");
    assert.equal(stored(storage).redemption_request_id, rid, "never discarded");

    const relogged = tablet(server, { storage, session: SESSION_2 });
    await relogged.machine.restore();
    const s = relogged.machine.getState();
    assert.equal(s.phase === "recovery_required" && s.reason, "session_changed");
    assert.equal(server.calls.length, 1, "no request before the explicit resume");
    // The resume's rebind write still fails: the read-only status check is still allowed.
    await relogged.machine.resumeRecovery();
    assert.equal(phase(relogged.machine), "previously_recorded_unconfirmed");
    assert.equal(requestIdOf(relogged.machine), rid);
    neverPrepares(relogged.machine.getState());
  });

  it("a lock during a durable in-flight send makes the late reply stale", async () => {
    const server = new FakeServer();
    const storage = new ScriptedStorage();
    const t = tablet(server, { storage });
    const held = deferred<void>();
    server.hooks.redeem.push(async (real) => {
      await held.promise;
      return real();
    });
    const pending = t.machine.startRedemption(MEMBER_A, ESPRESSO);
    await until(() => server.calls.length === 1);
    assert.equal(stored(storage).stage, "redemption_sent", "durable BEFORE the request left");
    t.machine.sessionEnded("logged_out");
    held.resolve();
    assert.equal(await pending, "stale");
    assert.equal(phase(t.machine), "blocked_authorization");
    neverPrepares(t.machine.getState());
  });

  it("concurrent retries never send twice and out-of-order replies never win", async () => {
    const server = new FakeServer();
    const storage = new ScriptedStorage();
    storage.failWrite = "QuotaExceededError";
    const t = tablet(server, { storage });
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    storage.healthy();
    const held = deferred<void>();
    server.hooks.redeem.push(async (real) => {
      await held.promise;
      return real();
    });
    const first = t.machine.retryPersistence();
    await until(() => server.calls.length === 1);
    assert.equal(await t.machine.retryPersistence(), "rejected", "busy: no second send");
    assert.equal(t.machine.abandonIntent(), "rejected");
    held.resolve();
    await first;
    assert.equal(sent(server, "redeem").length, 1);
    assert.equal(phase(t.machine), "recorded_now");
  });

  it("two tabs with cloned storage: a blocked tab and its clone never yield two coffees", async () => {
    const server = new FakeServer();
    const s1 = new ScriptedStorage();
    const tab1 = tablet(server, { storage: s1 });
    s1.failWrite = "QuotaExceededError";
    await tab1.machine.startRedemption(MEMBER_A, ESPRESSO);
    blocked(tab1.machine.getState());
    const s2 = s1.clone();
    s2.healthy();
    const tab2 = tablet(server, { storage: s2 });
    await tab2.machine.restore();
    assert.equal(phase(tab2.machine), "idle", "nothing durable was cloned");
    await tab2.machine.startRedemption(MEMBER_A, CAPPUCCINO);
    assert.equal(phase(tab2.machine), "recorded_now");
    s1.healthy();
    await tab1.machine.retryPersistence();
    assert.equal(phase(tab1.machine), "redemption_refused", "one coffee per Athens day");
    neverPrepares(tab1.machine.getState());
    assert.equal([tab1, tab2].filter((t) => shouldOfferPrepare(t.machine.getState())).length, 1);
  });

  it("two tablets sharing the staff account: a blocked tablet never adopts or duplicates the other's redemption", async () => {
    const server = new FakeServer();
    const a = new ScriptedStorage();
    a.failWrite = "SecurityError";
    const tabletA = tablet(server, { storage: a });
    const tabletB = tablet(server, { storage: new ScriptedStorage(), session: SESSION_2 });
    await tabletA.machine.startRedemption(MEMBER_A, ESPRESSO);
    blocked(tabletA.machine.getState());
    await tabletB.machine.startRedemption(MEMBER_A, ESPRESSO);
    assert.equal(phase(tabletB.machine), "recorded_now");
    a.healthy();
    await tabletA.machine.retryPersistence();
    assert.equal(phase(tabletA.machine), "redemption_refused");
    assert.equal(server.redemptions.size, 1);
  });

  it("a failed removal of a settled context never prepares again", async () => {
    const server = new FakeServer();
    const storage = new ScriptedStorage();
    const t = tablet(server, { storage });
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    t.machine.acknowledgePrepare();
    await t.machine.confirmHandoff("handed_now");
    storage.failRemove = true;
    assert.equal(t.machine.finish(), "applied");
    storage.healthy();
    const reloaded = tablet(server, { storage });
    await reloaded.machine.restore();
    assert.equal(phase(reloaded.machine), "previously_recorded_confirmed");
    neverPrepares(reloaded.machine.getState());
  });

  it("everything written before a send is a minimal strict v2 envelope", async () => {
    const server = new FakeServer();
    const storage = new ScriptedStorage();
    const t = tablet(server, { storage });
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    await t.machine.retryRedemption();
    t.machine.confirmManualVerification();
    await t.machine.confirmHandoff("handed_now");
    assert.ok(storage.writes.length >= 3);
    for (const w of storage.writes) {
      const parsed = parsePersisted(w);
      assert.ok(parsed && parsed !== "outdated", w);
      for (const forbidden of ["espresso", "double", "takeaway", "nonce", "verif"]) assert.ok(!w.includes(forbidden), w);
    }
  });
});

describe("UI-04K persistence_blocked: every transition", () => {
  async function blockedRedemption(mayHaveBeenSent: boolean) {
    const server = new FakeServer();
    const storage = new ScriptedStorage();
    const t = tablet(server, { storage });
    if (mayHaveBeenSent) {
      server.hooks.redeem.push(commitThenLose);
      await t.machine.startRedemption(MEMBER_A, ESPRESSO);
      storage.failWrite = "QuotaExceededError";
      await t.machine.retryRedemption();
    } else {
      storage.failWrite = "QuotaExceededError";
      await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    }
    blocked(t.machine.getState());
    return { server, storage, t };
  }

  it("rejects every action that is not offered", async () => {
    for (const may of [false, true]) {
      const { t } = await blockedRedemption(may);
      const m = t.machine;
      const before = m.getState();
      assert.equal(await m.startRedemption(MEMBER_A, ESPRESSO), "rejected");
      assert.equal(m.acknowledgePrepare(), "rejected");
      assert.equal(m.confirmManualVerification(), "rejected");
      assert.equal(await m.retryRedemption(), "rejected");
      assert.equal(await m.resubmitSameIntent(ESPRESSO), "rejected");
      assert.equal(await m.confirmHandoff("handed_now"), "rejected");
      assert.equal(await m.retryHandoff(), "rejected");
      assert.equal(await m.resumeRecovery(), "rejected");
      assert.equal(await m.restore(), "rejected", "restore is boot-only");
      assert.equal(m.finish(), "rejected");
      if (may) {
        assert.equal(m.abandonIntent(), "rejected");
      } else {
        assert.equal(await m.checkStatus(), "rejected", "a never-sent redemption has no server status to read");
      }
      assert.equal(m.getState(), before, "unchanged");
    }
  });
});
