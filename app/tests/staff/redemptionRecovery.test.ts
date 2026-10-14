import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, it } from "node:test";
import { athensBusinessDate } from "../../src/club/format";
import {
  StaffRedemptionMachine,
  StaffTransportError,
  availableActions,
  canonicalPayload,
  fnv1a,
  shouldOfferPrepare,
  type MachineState,
  type PreparedOrderPayload,
  type RecoveryStorage,
  type StaffRedemptionTransport,
} from "../../src/staff/redemptionRecovery/machine";
import { parseHandoffReply, parseRedemptionReply, parseStatusReply, type HandoffClaim } from "../../src/staff/redemptionRecovery/wire";

/*
 * UI-03D.D — staff redemption + handoff recovery state machine, driven by a
 * FAKE server that models the PHP contracts of PR #14/#15 (one coffee per
 * member per Athens day, exact replays, one handoff per redemption). No
 * network, no sessionStorage. Tests run under TZ=UTC+14 (runner).
 */

const MEMBER_A = "mem_0123456789abcdef0123456789abcdef";
const MEMBER_B = "mem_fedcba9876543210fedcba9876543210";
const SESSION_1 = "11111111111111111111111111111111";
const SESSION_2 = "22222222222222222222222222222222";
const DEVICE_1 = "dddddddddddddddddddddddddddddd01";
const DEVICE_2 = "dddddddddddddddddddddddddddddd02";
/** 10:30 Athens (EEST). */
const MORNING = "2026-10-14T07:30:00Z";

const ESPRESSO: PreparedOrderPayload = {
  catalogueVersion: 1,
  coffeeCode: "espresso",
  preparation: { modifiers: [{ group: "shots", option: "double" }, { group: "sweetness", option: "none" }], quick_notes: ["takeaway"] },
};
const CAPPUCCINO: PreparedOrderPayload = {
  catalogueVersion: 1,
  coffeeCode: "cappuccino",
  preparation: { modifiers: [{ group: "milk", option: "plant" }, { group: "shots", option: "single" }, { group: "sweetness", option: "none" }], quick_notes: [] },
};

// ---- helpers ---------------------------------------------------------------------------------

let idCounter = 0;
function uuid(): string {
  idCounter++;
  const hex = idCounter.toString(16).padStart(12, "0");
  return `00000000-0000-4000-8000-${hex}`;
}

function stampOf(d: Date): string {
  return `${d.toISOString().slice(0, 19).replace("T", " ")}.000000`;
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

class TabStorage implements RecoveryStorage {
  value: string | null = null;
  failWrites = false;
  failReads = false;
  writes: string[] = [];
  read(): string | null {
    if (this.failReads) throw new Error("SecurityError");
    return this.value;
  }
  write(v: string): void {
    if (this.failWrites) throw new Error("QuotaExceededError");
    this.writes.push(v);
    this.value = v;
  }
  remove(): void {
    this.value = null;
  }
}

type Op = "redeem" | "handoff" | "status";
type Hook = (real: () => unknown) => Promise<unknown>;

interface ServerRedemption {
  memberId: string;
  payloadHash: string;
  staff: string;
  businessDate: string;
  redeemedAtUtc: string;
  handoff: null | { handoffRequestId: string; claim: HandoffClaim; recordedAtUtc: string };
}

/** Fake server: the PHP rules, the wire format, scripted failures and held replies. */
class FakeServer implements StaffRedemptionTransport {
  now = new Date(MORNING);
  readonly redemptions = new Map<string, ServerRedemption>();
  readonly byDay = new Map<string, string>();
  readonly handoffById = new Map<string, string>();
  readonly calls: { op: Op; id: string; deviceRef?: string }[] = [];
  readonly hooks: Record<Op, Hook[]> = { redeem: [], handoff: [], status: [] };
  /** Replies of a lagging read replica for the next status reads (stale data). */
  staleStatus: unknown[] = [];

  today(): string {
    return athensBusinessDate(this.now);
  }

  private run(op: Op, real: () => unknown): Promise<unknown> {
    const hook = this.hooks[op].shift();
    return hook ? hook(real) : Promise.resolve().then(real);
  }

  redeem(call: { memberId: string; requestId: string; payload: PreparedOrderPayload }): Promise<unknown> {
    this.calls.push({ op: "redeem", id: call.requestId });
    return this.run("redeem", () => this.doRedeem(call.memberId, call.requestId, fnv1a(canonicalPayload(call.payload))));
  }

  confirmHandoff(call: { memberId: string; handoffRequestId: string; redemptionRequestId: string; claim: HandoffClaim; deviceRef: string }): Promise<unknown> {
    this.calls.push({ op: "handoff", id: call.handoffRequestId, deviceRef: call.deviceRef });
    return this.run("handoff", () => this.doHandoff(call.memberId, call.handoffRequestId, call.redemptionRequestId, call.claim));
  }

  redemptionStatus(call: { memberId: string; redemptionRequestId: string }): Promise<unknown> {
    this.calls.push({ op: "status", id: call.redemptionRequestId });
    if (this.staleStatus.length) {
      const stale = this.staleStatus.shift();
      return this.run("status", () => stale);
    }
    return this.run("status", () => this.doStatus(call.memberId, call.redemptionRequestId));
  }

  private handoffView(rid: string) {
    const r = this.redemptions.get(rid) as ServerRedemption;
    if (!r.handoff) return null;
    return {
      handoff_request_id: r.handoff.handoffRequestId,
      redemption_request_id: rid,
      claim: r.handoff.claim,
      confirmation_recorded_at_utc: r.handoff.recordedAtUtc,
      handoff_at_utc: r.handoff.claim === "handed_now" ? r.handoff.recordedAtUtc : null,
      handoff_time: r.handoff.claim === "handed_now" ? "stated_at_recording" : "not_recorded",
    };
  }

  private redemptionWire(rid: string) {
    const r = this.redemptions.get(rid) as ServerRedemption;
    return { request_id: rid, member_id: r.memberId, business_date: r.businessDate, redeemed_at_utc: r.redeemedAtUtc };
  }

  doRedeem(memberId: string, requestId: string, payloadHash: string): unknown {
    const today = this.today();
    const base = { request_id: requestId, server_business_date: today };
    const refused = (outcome: string) => ({ ...base, outcome, recording: null, fulfillment: null, instruction: "do_not_prepare", entitlement_consumed: false, redemption: null, handoff: null });
    const existing = this.redemptions.get(requestId);
    if (existing) {
      if (existing.memberId !== memberId || existing.payloadHash !== payloadHash) return refused("idempotency_key_reused");
      const handoff = this.handoffView(requestId);
      const instruction = existing.businessDate < today ? "do_not_prepare_for_today" : handoff ? "do_not_prepare" : "verify_handoff";
      return {
        ...base,
        outcome: "replayed",
        recording: "previously_recorded",
        fulfillment: handoff ? "confirmed" : "unconfirmed",
        instruction,
        entitlement_consumed: true,
        redemption: this.redemptionWire(requestId),
        handoff,
      };
    }
    if (this.byDay.has(`${memberId}|${today}`)) return refused("benefit_already_redeemed");
    this.redemptions.set(requestId, { memberId, payloadHash, staff: "7", businessDate: today, redeemedAtUtc: stampOf(this.now), handoff: null });
    this.byDay.set(`${memberId}|${today}`, requestId);
    return {
      ...base,
      outcome: "redeemed",
      recording: "recorded_now",
      fulfillment: "unconfirmed",
      instruction: "prepare",
      entitlement_consumed: true,
      redemption: this.redemptionWire(requestId),
      handoff: null,
    };
  }

  doHandoff(memberId: string, hid: string, rid: string, claim: HandoffClaim): unknown {
    const fail = (outcome: string) => ({ outcome, handoff_request_id: hid, fulfillment: null, handoff: null });
    const ok = (outcome: string, redemption: string) => ({ outcome, handoff_request_id: hid, fulfillment: "confirmed", handoff: this.handoffView(redemption) });
    const existingRid = this.handoffById.get(hid);
    if (existingRid !== undefined) {
      const r = this.redemptions.get(existingRid) as ServerRedemption;
      if (existingRid !== rid || r.memberId !== memberId || r.handoff?.claim !== claim) return fail("idempotency_key_reused");
      return ok("handoff_replayed", rid);
    }
    const r = this.redemptions.get(rid);
    if (!r || r.memberId !== memberId) return fail("redemption_not_found");
    if (r.handoff) return ok("handoff_already_confirmed", rid);
    if (r.businessDate !== this.today()) return fail("late_confirmation_not_authorised");
    r.handoff = { handoffRequestId: hid, claim, recordedAtUtc: stampOf(this.now) };
    this.handoffById.set(hid, rid);
    return ok("handoff_confirmed", rid);
  }

  doStatus(memberId: string, rid: string): unknown {
    const r = this.redemptions.get(rid);
    if (!r || r.memberId !== memberId) return { result: "not_found", data: null };
    const handoff = this.handoffView(rid);
    return {
      result: "found",
      data: {
        redemption_request_id: rid,
        business_date: r.businessDate,
        redeemed_at_utc: r.redeemedAtUtc,
        is_current_business_date: r.businessDate === this.today(),
        coffee_label: "Espresso",
        modifiers: { "Δόση": "Διπλός" },
        quick_notes: ["σε ποτήρι για έξω"],
        fulfillment: handoff ? "confirmed" : "unconfirmed",
        handoff,
      },
    };
  }

  handoffCount(): number {
    return [...this.redemptions.values()].filter((r) => r.handoff).length;
  }
}

const commitThenLose: Hook = async (real) => {
  real();
  throw new StaffTransportError("network");
};
const loseBeforeCommit: Hook = async () => {
  throw new StaffTransportError("timeout");
};
const replyWith = (raw: unknown): Hook => async () => raw;

function tablet(server: FakeServer, opts: { storage?: TabStorage; session?: string; device?: string; ids?: () => string } = {}) {
  const storage = opts.storage ?? new TabStorage();
  let ids = 0;
  const newId = () => {
    ids++;
    return (opts.ids ?? uuid)();
  };
  const machine = new StaffRedemptionMachine({
    transport: server,
    storage,
    newId,
    sessionRef: opts.session ?? SESSION_1,
    deviceRef: opts.device ?? DEVICE_1,
  });
  return { machine, storage, idsMinted: () => ids };
}

function phase(m: StaffRedemptionMachine): MachineState["phase"] {
  return m.getState().phase;
}

function neverPrepares(s: MachineState): void {
  assert.equal(shouldOfferPrepare(s), false, `${s.phase} must not offer prepare`);
  assert.ok(!availableActions(s).includes("acknowledge_prepare"), `${s.phase} must not list acknowledge_prepare`);
}

function requestIdOf(m: StaffRedemptionMachine): string {
  const s = m.getState() as { intent?: { requestId: string } };
  assert.ok(s.intent, "state carries the intent");
  return s.intent.requestId;
}

// ---- wire parsers ----------------------------------------------------------------------------

describe("UI-03D.D wire parsers reject malformed and contradictory replies", () => {
  const RID = "3fa85f64-5717-4562-b3fc-2c963f66afa6";
  const HID = "7c9e6679-7425-40de-944b-e07fc1f90ae7";
  const sent = { requestId: RID, memberId: MEMBER_A };
  const redemption = { request_id: RID, member_id: MEMBER_A, business_date: "2026-10-14", redeemed_at_utc: "2026-10-14 07:30:00.000000" };
  const handoffNow = {
    handoff_request_id: HID,
    redemption_request_id: RID,
    claim: "handed_now",
    confirmation_recorded_at_utc: "2026-10-14 07:35:00.000000",
    handoff_at_utc: "2026-10-14 07:35:00.000000",
    handoff_time: "stated_at_recording",
  };
  const redeemed = {
    outcome: "redeemed",
    request_id: RID,
    recording: "recorded_now",
    fulfillment: "unconfirmed",
    instruction: "prepare",
    entitlement_consumed: true,
    server_business_date: "2026-10-14",
    redemption,
    handoff: null,
  };
  const replayUnconfirmed = { ...redeemed, outcome: "replayed", recording: "previously_recorded", instruction: "verify_handoff" };
  const replayConfirmed = { ...replayUnconfirmed, fulfillment: "confirmed", instruction: "do_not_prepare", handoff: handoffNow };

  it("accepts the four consistent success shapes", () => {
    assert.equal(parseRedemptionReply(redeemed, sent)?.kind, "redeemed");
    assert.equal(parseRedemptionReply(replayUnconfirmed, sent)?.kind, "replayed");
    assert.equal(parseRedemptionReply(replayConfirmed, sent)?.kind, "replayed");
    const yesterday = { ...replayUnconfirmed, server_business_date: "2026-10-15", instruction: "do_not_prepare_for_today" };
    const parsed = parseRedemptionReply(yesterday, sent);
    assert.equal(parsed?.kind === "replayed" && parsed.instruction, "do_not_prepare_for_today");
  });

  const contradictions: [string, unknown][] = [
    ["a replay that says prepare", { ...replayUnconfirmed, instruction: "prepare" }],
    ["a fresh commit that is not on the server date", { ...redeemed, server_business_date: "2026-10-15" }],
    ["a fresh commit with a handoff", { ...redeemed, handoff: handoffNow }],
    ["a fresh commit that says verify", { ...redeemed, instruction: "verify_handoff" }],
    ["confirmed without a handoff", { ...replayUnconfirmed, fulfillment: "confirmed", instruction: "do_not_prepare" }],
    ["unconfirmed with a handoff", { ...replayConfirmed, fulfillment: "unconfirmed" }],
    ["confirmed today but verify", { ...replayConfirmed, instruction: "verify_handoff" }],
    ["an earlier day presented as today", { ...replayUnconfirmed, server_business_date: "2026-10-15" }],
    ["today presented as an earlier day", { ...replayUnconfirmed, instruction: "do_not_prepare_for_today" }],
    ["a redemption from the future", { ...replayUnconfirmed, server_business_date: "2026-10-13" }],
    ["another request id", { ...redeemed, request_id: "6f9619ff-8b86-4011-b42d-00c04fc964ff" }],
    ["another member", { ...redeemed, redemption: { ...redemption, member_id: MEMBER_B } }],
    ["redeemed_at outside the stated Athens day", { ...redeemed, redemption: { ...redemption, redeemed_at_utc: "2026-10-14 21:30:00.000000" } }],
    ["an impossible date", { ...redeemed, server_business_date: "2026-02-30", redemption: { ...redemption, business_date: "2026-02-30" } }],
    ["an ISO instant instead of the server stamp", { ...redeemed, redemption: { ...redemption, redeemed_at_utc: "2026-10-14T07:30:00Z" } }],
    ["an extra key", { ...redeemed, note: "x" }],
    ["a missing key", (({ handoff: _h, ...rest }) => rest)(redeemed)],
    ["an unknown outcome", { ...redeemed, outcome: "redeemed_maybe" }],
    ["a refusal that consumed the entitlement", { ...redeemed, outcome: "benefit_already_redeemed", recording: null, fulfillment: null, instruction: "do_not_prepare", redemption: null }],
    ["a refusal that says prepare", { ...redeemed, outcome: "membership_inactive", recording: null, fulfillment: null, entitlement_consumed: false, redemption: null }],
    ["an unknown outcome with another retry id", { ...redeemed, outcome: "outcome_unknown", recording: null, fulfillment: null, instruction: "do_not_prepare", entitlement_consumed: null, redemption: null, retry_request_id: "6f9619ff-8b86-4011-b42d-00c04fc964ff" }],
    ["an unknown outcome that claims consumption", { ...redeemed, outcome: "outcome_unknown", recording: null, fulfillment: null, instruction: "do_not_prepare", entitlement_consumed: true, redemption: null, retry_request_id: RID }],
    ["an invented time for an earlier claim", { ...replayConfirmed, handoff: { ...handoffNow, claim: "already_handed_earlier", handoff_time: "not_recorded" } }],
    ["a handed-now time that differs from the record time", { ...replayConfirmed, handoff: { ...handoffNow, handoff_at_utc: "2026-10-14 07:00:00.000000" } }],
    ["a handoff of another redemption", { ...replayConfirmed, handoff: { ...handoffNow, redemption_request_id: "6f9619ff-8b86-4011-b42d-00c04fc964ff" } }],
    ["not an object", "redeemed"],
    ["null", null],
  ];
  for (const [name, raw] of contradictions) {
    it(`rejects ${name}`, () => assert.equal(parseRedemptionReply(raw, sent), null));
  }

  it("accepts consistent refusal, integrity and unknown shapes", () => {
    const none = { recording: null, fulfillment: null, instruction: "do_not_prepare", redemption: null, handoff: null };
    assert.deepEqual(parseRedemptionReply({ ...redeemed, ...none, outcome: "benefit_already_redeemed", entitlement_consumed: false }, sent), { kind: "refused", code: "benefit_already_redeemed" });
    assert.deepEqual(parseRedemptionReply({ ...redeemed, ...none, outcome: "record_integrity_failure", entitlement_consumed: null }, sent), { kind: "integrity_failure" });
    assert.deepEqual(parseRedemptionReply({ ...redeemed, ...none, outcome: "outcome_unknown", entitlement_consumed: null, retry_request_id: RID }, sent), { kind: "unresolved", code: "outcome_unknown" });
  });

  it("handoff replies must echo our id, our claim, and never carry our id as 'already confirmed'", () => {
    const sentH = { handoffRequestId: HID, redemptionRequestId: RID, claim: "handed_now" as HandoffClaim };
    const ok = { outcome: "handoff_confirmed", handoff_request_id: HID, fulfillment: "confirmed", handoff: handoffNow };
    assert.equal(parseHandoffReply(ok, sentH)?.kind, "recorded");
    assert.equal(parseHandoffReply({ ...ok, outcome: "handoff_already_confirmed" }, sentH), null, "already confirmed with OUR id is a contradiction");
    assert.equal(parseHandoffReply({ ...ok, handoff: { ...handoffNow, claim: "already_handed_earlier", handoff_at_utc: null, handoff_time: "not_recorded" } }, sentH), null, "another claim");
    assert.equal(parseHandoffReply({ ...ok, fulfillment: null }, sentH), null);
    assert.equal(parseHandoffReply({ ...ok, handoff: { ...handoffNow, handoff_request_id: "a3bb189e-8bf9-4888-9912-ace4e6543002" } }, sentH), null, "a confirmation that is not ours");
    assert.equal(parseHandoffReply({ ...ok, handoff_request_id: "a3bb189e-8bf9-4888-9912-ace4e6543002" }, sentH), null);
    assert.equal(parseHandoffReply({ outcome: "outcome_unknown", handoff_request_id: HID, fulfillment: null, handoff: null, retry_handoff_request_id: "a3bb189e-8bf9-4888-9912-ace4e6543002" }, sentH), null);
    assert.equal(parseHandoffReply({ outcome: "outcome_unknown", handoff_request_id: HID, fulfillment: "confirmed", handoff: null, retry_handoff_request_id: HID }, sentH), null);
    assert.deepEqual(parseHandoffReply({ outcome: "outcome_unknown", handoff_request_id: HID, fulfillment: null, handoff: null, retry_handoff_request_id: HID }, sentH), { kind: "unresolved", code: "outcome_unknown" });
    const other = { ...handoffNow, handoff_request_id: "a3bb189e-8bf9-4888-9912-ace4e6543002", claim: "already_handed_earlier", handoff_at_utc: null, handoff_time: "not_recorded" };
    const already = parseHandoffReply({ outcome: "handoff_already_confirmed", handoff_request_id: HID, fulfillment: "confirmed", handoff: other }, sentH);
    assert.equal(already?.kind === "recorded" && already.how, "already_confirmed");
  });

  it("status reads fail closed on any inconsistency", () => {
    const q = { redemptionRequestId: RID, memberId: MEMBER_A };
    const data = { redemption_request_id: RID, business_date: "2026-10-14", redeemed_at_utc: "2026-10-14 07:30:00.000000", is_current_business_date: true, fulfillment: "unconfirmed", handoff: null };
    assert.equal(parseStatusReply({ result: "found", data }, q).kind, "found");
    assert.equal(parseStatusReply({ result: "found", data: { ...data, fulfillment: "confirmed" } }, q).kind, "unavailable");
    assert.equal(parseStatusReply({ result: "found", data: { ...data, handoff: handoffNow } }, q).kind, "unavailable");
    assert.equal(parseStatusReply({ result: "found", data: { ...data, redemption_request_id: "6f9619ff-8b86-4011-b42d-00c04fc964ff" } }, q).kind, "unavailable");
    assert.equal(parseStatusReply({ result: "found", data: { ...data, is_current_business_date: "yes" } }, q).kind, "unavailable");
    assert.equal(parseStatusReply({ result: "found", data: { ...data, staff_wp_user_id: 7 } }, q).kind, "unavailable");
    assert.equal(parseStatusReply({ result: "not_found", data }, q).kind, "unavailable");
    assert.equal(parseStatusReply({ result: "maybe", data: null }, q).kind, "unavailable");
    assert.equal(parseStatusReply({ result: "forbidden", data: null }, q).kind, "forbidden");
    assert.equal(parseStatusReply({ result: "record_integrity_failure", data: null }, q).kind, "integrity_failure");
  });

  it("Athens business dates follow summer and winter time, whatever the device zone", () => {
    const at = (stamp: string, date: string) => parseRedemptionReply({ ...redeemed, server_business_date: date, redemption: { ...redemption, business_date: date, redeemed_at_utc: stamp } }, sent)?.kind;
    assert.equal(at("2026-07-01 20:59:59.000000", "2026-07-01"), "redeemed", "23:59:59 EEST");
    assert.equal(at("2026-07-01 21:00:00.000000", "2026-07-02"), "redeemed", "00:00 EEST next day");
    assert.equal(at("2026-07-01 21:00:00.000000", "2026-07-01"), undefined);
    assert.equal(at("2026-10-25 21:59:59.000000", "2026-10-25"), "redeemed", "23:59:59 EET after the October change");
    assert.equal(at("2026-10-25 22:00:00.000000", "2026-10-26"), "redeemed");
    assert.equal(at("2026-10-25 21:00:00.000000", "2026-10-25"), "redeemed", "23:00 EET (would be the 26th under summer time)");
    assert.equal(at("2026-03-28 22:00:00.000000", "2026-03-29"), "redeemed", "00:00 EET on the March change day");
    assert.equal(at("2026-03-29 00:59:59.000000", "2026-03-29"), "redeemed", "02:59:59 just before clocks jump");
    assert.equal(at("2026-03-28 21:59:59.000000", "2026-03-28"), "redeemed");
  });
});

// ---- redemption flow -------------------------------------------------------------------------

describe("UI-03D.D redemption: prepare only once, from a fresh commit", () => {
  it("a fresh commit offers prepare exactly once, and never again after a reload", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    assert.equal(await t.machine.startRedemption(MEMBER_A, ESPRESSO), "applied");
    assert.equal(phase(t.machine), "recorded_now");
    assert.equal(shouldOfferPrepare(t.machine.getState()), true);
    assert.deepEqual(availableActions(t.machine.getState()), ["acknowledge_prepare"]);
    assert.equal(t.machine.finish(), "rejected", "the prepare prompt must be handled first");
    assert.equal(t.machine.acknowledgePrepare(), "applied");
    assert.equal(t.machine.acknowledgePrepare(), "rejected");
    assert.deepEqual(availableActions(t.machine.getState()), ["confirm_handed_now", "confirm_handed_earlier", "finish"], "confirmation is offered, not forced (open owner decision)");
    neverPrepares(t.machine.getState());

    // Reload of the same tab: the server status decides; no second prepare.
    const reloaded = tablet(server, { storage: t.storage });
    assert.equal(await reloaded.machine.restore(), "applied");
    assert.equal(phase(reloaded.machine), "previously_recorded_unconfirmed");
    neverPrepares(reloaded.machine.getState());
    assert.deepEqual(availableActions(reloaded.machine.getState()), ["confirm_handed_now", "confirm_handed_earlier", "check_status", "finish"]);
    assert.equal(reloaded.idsMinted(), 0, "recovery never mints a request id");
  });

  it("a reload right after the commit, before prepare was acknowledged, still never prepares", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const reloaded = tablet(server, { storage: t.storage });
    await reloaded.machine.restore();
    neverPrepares(reloaded.machine.getState());
  });

  it("an unknown COMMIT never prepares; the SAME id recovers it as a replay to verify", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    server.hooks.redeem.push(async (real) => {
      const committed = real() as { request_id: string; server_business_date: string };
      return {
        outcome: "outcome_unknown",
        request_id: committed.request_id,
        recording: null,
        fulfillment: null,
        instruction: "do_not_prepare",
        entitlement_consumed: null,
        server_business_date: committed.server_business_date,
        redemption: null,
        handoff: null,
        retry_request_id: committed.request_id,
      };
    });
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const s = t.machine.getState();
    assert.equal(s.phase, "redemption_unresolved");
    neverPrepares(s);
    const original = requestIdOf(t.machine);
    assert.equal(await t.machine.retryRedemption(), "applied");
    assert.equal(phase(t.machine), "previously_recorded_unconfirmed");
    neverPrepares(t.machine.getState());
    assert.deepEqual(server.calls.filter((c) => c.op === "redeem").map((c) => c.id), [original, original]);
    assert.equal(t.idsMinted(), 1);
  });

  it("a lost response is not a failure: the status read shows the commit, and nothing is prepared", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    assert.deepEqual(t.machine.getState().phase === "redemption_unresolved" && t.machine.getState(), { ...t.machine.getState(), reason: "network" });
    assert.equal(await t.machine.checkStatus(), "applied");
    assert.equal(phase(t.machine), "previously_recorded_unconfirmed");
    neverPrepares(t.machine.getState());
    assert.equal(server.redemptions.size, 1);
  });

  it("a response lost BEFORE any commit: the same-id retry is the first and only commit", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    server.hooks.redeem.push(loseBeforeCommit);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const id = requestIdOf(t.machine);
    assert.equal(server.redemptions.size, 0);
    await t.machine.retryRedemption();
    assert.equal(phase(t.machine), "recorded_now");
    assert.equal(requestIdOf(t.machine), id);
    assert.equal(server.redemptions.size, 1);
  });

  it("after a reload with nothing recorded, only the SAME intent may be resubmitted", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    server.hooks.redeem.push(loseBeforeCommit);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const id = requestIdOf(t.machine);

    const reloaded = tablet(server, { storage: t.storage });
    await reloaded.machine.restore();
    const s = reloaded.machine.getState();
    assert.equal(s.phase === "recovery_required" && s.reason, "not_recorded");
    assert.deepEqual(availableActions(s), ["resubmit_same_intent", "abandon_intent"]);
    assert.equal(await reloaded.machine.retryRedemption(), "rejected", "no payload in memory after a reload");
    assert.equal(await reloaded.machine.resubmitSameIntent(CAPPUCCINO), "rejected", "a different order is a different intent");
    assert.equal(await reloaded.machine.resubmitSameIntent({ ...ESPRESSO, preparation: { ...ESPRESSO.preparation, modifiers: [...ESPRESSO.preparation.modifiers].reverse() } }), "applied");
    assert.equal(phase(reloaded.machine), "recorded_now");
    assert.equal(requestIdOf(reloaded.machine), id);
    assert.equal(reloaded.idsMinted(), 0);
  });

  it("when the server status cannot be read, the machine stays unresolved and keeps both ids", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    for (const hook of [loseBeforeCommit, replyWith({ result: "found", data: { nonsense: true } }), replyWith("<html>502</html>")]) {
      server.hooks.status.push(hook);
      await t.machine.checkStatus();
      const s = t.machine.getState();
      assert.equal(s.phase, "redemption_unresolved");
      neverPrepares(s);
    }
    const reloaded = tablet(server, { storage: t.storage });
    server.hooks.status.push(loseBeforeCommit);
    await reloaded.machine.restore();
    const s = reloaded.machine.getState();
    assert.equal(s.phase === "recovery_required" && s.reason, "status_unavailable");
    neverPrepares(s);
    assert.deepEqual(availableActions(s), ["check_status", "manager_review"]);
    assert.equal(await reloaded.machine.checkStatus(), "applied");
    assert.equal(phase(reloaded.machine), "previously_recorded_unconfirmed");
  });

  it("invalid, contradictory or unexpected replies are unknown outcomes, never success", async () => {
    const server = new FakeServer();
    const replays: [string, ReturnType<typeof replyWith> | ((real: () => unknown) => Promise<unknown>)][] = [
      ["invalid_response", replyWith({ outcome: "redeemed" })],
      ["invalid_response", async (real) => ({ ...(real() as object), instruction: "verify_handoff" })],
      ["unexpected_error", async () => {
        throw new TypeError("boom");
      }],
      ["unavailable", async () => {
        throw new StaffTransportError("unavailable");
      }],
    ];
    for (const [reason, hook] of replays) {
      const t = tablet(server);
      server.hooks.redeem.push(hook);
      await t.machine.startRedemption(MEMBER_A, ESPRESSO);
      const s = t.machine.getState();
      assert.equal(s.phase === "redemption_unresolved" && s.reason, reason);
      neverPrepares(s);
    }
  });

  it("a definitive refusal closes the intent without preparing", async () => {
    const server = new FakeServer();
    const first = tablet(server);
    await first.machine.startRedemption(MEMBER_A, ESPRESSO);
    const second = tablet(server, { device: DEVICE_2, session: SESSION_2 });
    await second.machine.startRedemption(MEMBER_A, CAPPUCCINO);
    const s = second.machine.getState();
    assert.equal(s.phase === "redemption_refused" && s.code, "benefit_already_redeemed");
    neverPrepares(s);
    assert.equal(second.storage.value, null, "a refused intent leaves no recovery context");
    assert.equal(second.machine.finish(), "applied");
    assert.equal(phase(second.machine), "idle");
  });

  it("a record integrity failure blocks for management review", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    const none = { recording: null, fulfillment: null, instruction: "do_not_prepare", redemption: null, handoff: null };
    server.hooks.redeem.push(async (real) => ({ ...(real() as object), ...none, outcome: "record_integrity_failure", entitlement_consumed: null }));
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    assert.equal(phase(t.machine), "blocked_integrity");
    assert.deepEqual(availableActions(t.machine.getState()), ["manager_review"]);
    assert.equal(t.machine.finish(), "rejected");
    assert.equal(await t.machine.checkStatus(), "rejected");

    const viaStatus = tablet(server, { storage: t.storage });
    server.hooks.status.push(replyWith({ result: "record_integrity_failure", data: null }));
    await viaStatus.machine.restore();
    assert.equal(phase(viaStatus.machine), "blocked_integrity");
  });

  it("double taps never send a second request", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    const held = deferred<unknown>();
    server.hooks.redeem.push((real) => held.promise.then(real));
    const first = t.machine.startRedemption(MEMBER_A, ESPRESSO);
    assert.equal(await t.machine.startRedemption(MEMBER_A, ESPRESSO), "rejected");
    assert.equal(await t.machine.retryRedemption(), "rejected");
    assert.equal(await t.machine.checkStatus(), "rejected");
    held.resolve(undefined);
    assert.equal(await first, "applied");
    assert.equal(server.calls.filter((c) => c.op === "redeem").length, 1);
  });

  it("finishing an unconfirmed redemption leaves it unconfirmed on the server and needs no confirmation", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    t.machine.acknowledgePrepare();
    assert.equal(t.machine.finish(), "applied");
    assert.equal(phase(t.machine), "idle");
    assert.equal(t.storage.value, null);
    assert.equal(server.handoffCount(), 0);
    assert.equal(await t.machine.startRedemption(MEMBER_A, CAPPUCCINO), "applied");
    assert.equal(phase(t.machine), "redemption_refused", "finishing never restores the entitlement");
  });

  it("a new order is refused while an intent is open", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    assert.equal(await t.machine.startRedemption(MEMBER_A, CAPPUCCINO), "rejected");
    assert.equal(t.machine.abandonIntent(), "rejected", "an unresolved intent cannot be abandoned");
    assert.equal(t.machine.finish(), "rejected", "nor finished");
    assert.equal(t.idsMinted(), 1);
  });
});

// ---- handoff flow ----------------------------------------------------------------------------

describe("UI-03D.D handoff: separate statement, original ids, no invented time", () => {
  async function recorded(server: FakeServer, opts = {}) {
    const t = tablet(server, opts);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    t.machine.acknowledgePrepare();
    return t;
  }

  it("handed_now records the confirmation at the server time of the statement", async () => {
    const server = new FakeServer();
    const t = await recorded(server);
    server.now = new Date("2026-10-14T07:34:00Z");
    assert.equal(await t.machine.confirmHandoff("handed_now"), "applied");
    const s = t.machine.getState();
    assert.equal(s.phase, "handoff_confirmed");
    if (s.phase !== "handoff_confirmed") return;
    assert.equal(s.via, "confirmed");
    assert.equal(s.handoff.handoffAtUtc, "2026-10-14 07:34:00.000000");
    assert.equal(server.handoffCount(), 1);
    assert.equal(t.storage.value !== null && JSON.parse(t.storage.value).stage, "recorded");
    neverPrepares(s);
  });

  it("already_handed_earlier never invents a handoff time", async () => {
    const server = new FakeServer();
    const t = await recorded(server);
    await t.machine.confirmHandoff("already_handed_earlier");
    const s = t.machine.getState();
    assert.ok(s.phase === "handoff_confirmed");
    assert.equal(s.handoff.handoffAtUtc, null);
    assert.equal(s.handoff.handoffTime, "not_recorded");
    assert.equal(s.handoff.confirmationRecordedAtUtc, "2026-10-14 07:30:00.000000");
  });

  it("a second tap while a confirmation is pending sends nothing", async () => {
    const server = new FakeServer();
    const t = await recorded(server);
    const held = deferred<unknown>();
    server.hooks.handoff.push((real) => held.promise.then(real));
    const pending = t.machine.confirmHandoff("handed_now");
    assert.equal(await t.machine.confirmHandoff("handed_now"), "rejected");
    assert.equal(await t.machine.confirmHandoff("already_handed_earlier"), "rejected");
    assert.equal(await t.machine.retryHandoff(), "rejected");
    held.resolve(undefined);
    await pending;
    assert.equal(server.calls.filter((c) => c.op === "handoff").length, 1);
    assert.equal(server.handoffCount(), 1);
  });

  it("a handoff cannot be confirmed before the prepare prompt was handled, or from a refused intent", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    assert.equal(await t.machine.confirmHandoff("handed_now"), "rejected");
    const other = tablet(server, { session: SESSION_2 });
    await other.machine.startRedemption(MEMBER_A, CAPPUCCINO);
    assert.equal(await other.machine.confirmHandoff("handed_now"), "rejected");
    assert.equal(await t.machine.confirmHandoff("maybe" as HandoffClaim), "rejected");
    assert.equal(server.handoffCount(), 0);
  });

  it("an uncertain handoff reuses its ORIGINAL handoff id and never mints another", async () => {
    const server = new FakeServer();
    const t = await recorded(server);
    server.hooks.handoff.push(commitThenLose);
    await t.machine.confirmHandoff("handed_now");
    const s = t.machine.getState();
    assert.equal(s.phase, "handoff_unresolved");
    neverPrepares(s);
    const minted = t.idsMinted();
    assert.equal(await t.machine.retryHandoff(), "applied");
    const after = t.machine.getState();
    assert.ok(after.phase === "handoff_confirmed");
    assert.equal(after.via, "replayed");
    const ids = server.calls.filter((c) => c.op === "handoff").map((c) => c.id);
    assert.equal(ids.length, 2);
    assert.equal(ids[0], ids[1]);
    assert.equal(t.idsMinted(), minted);
    assert.equal(server.handoffCount(), 1);
  });

  it("an uncertain handoff that did not commit is resolved by status, then the same id", async () => {
    const server = new FakeServer();
    const t = await recorded(server);
    server.hooks.handoff.push(loseBeforeCommit);
    await t.machine.confirmHandoff("handed_now");
    await t.machine.checkStatus();
    const s = t.machine.getState();
    assert.equal(s.phase === "handoff_unresolved" && s.reason, "status_unconfirmed");
    await t.machine.retryHandoff();
    assert.equal(phase(t.machine), "handoff_confirmed");
    const ids = server.calls.filter((c) => c.op === "handoff").map((c) => c.id);
    assert.equal(new Set(ids).size, 1);
  });

  it("a reload during an uncertain handoff recovers it from storage and the server, with the same id", async () => {
    const server = new FakeServer();
    const t = await recorded(server);
    server.hooks.handoff.push(commitThenLose);
    await t.machine.confirmHandoff("already_handed_earlier");
    const reloaded = tablet(server, { storage: t.storage });
    await reloaded.machine.restore();
    const s = reloaded.machine.getState();
    assert.ok(s.phase === "handoff_confirmed");
    assert.equal(s.via, "status");
    assert.equal(s.handoff.handoffAtUtc, null);
    assert.equal(server.handoffCount(), 1);
  });

  it("a replay after a handoff says do not prepare, and offers no new confirmation", async () => {
    const server = new FakeServer();
    const t = await recorded(server);
    await t.machine.confirmHandoff("handed_now");
    const ids: string[] = [];
    const other = tablet(server, { session: SESSION_2, device: DEVICE_2, ids: () => (ids.push(requestIdOf(t.machine)), requestIdOf(t.machine)) });
    await other.machine.startRedemption(MEMBER_A, ESPRESSO); // duplicate submission, same request id
    const s = other.machine.getState();
    assert.equal(s.phase, "previously_recorded_confirmed");
    neverPrepares(s);
    assert.deepEqual(availableActions(s), ["finish", "check_status"]);
    assert.equal(await other.machine.confirmHandoff("handed_now"), "rejected");
  });

  it("late confirmation refusals and conflicts are surfaced, never turned into a coffee", async () => {
    const server = new FakeServer();
    const t = await recorded(server);
    server.now = new Date("2026-10-14T21:00:00Z"); // Athens midnight
    await t.machine.confirmHandoff("handed_now");
    const s = t.machine.getState();
    assert.equal(s.phase === "handoff_refused" && s.code, "late_confirmation_not_authorised");
    neverPrepares(s);
    assert.deepEqual(availableActions(s), ["check_status", "manager_review"]);
    await t.machine.checkStatus();
    assert.equal(phase(t.machine), "previous_business_date");
    neverPrepares(t.machine.getState());
  });
});

// ---- session and device ----------------------------------------------------------------------

describe("UI-03D.D session, storage and device safety", () => {
  it("logout, lock, expiry and revoked capability block the machine and drop late replies", async () => {
    for (const reason of ["logged_out", "locked", "expired", "forbidden"] as const) {
      const server = new FakeServer();
      const t = tablet(server);
      const held = deferred<unknown>();
      server.hooks.redeem.push((real) => held.promise.then(real));
      const pending = t.machine.startRedemption(MEMBER_A, ESPRESSO);
      t.machine.sessionEnded(reason);
      held.resolve(undefined);
      assert.equal(await pending, "stale");
      const s = t.machine.getState();
      assert.equal(s.phase === "blocked_authorization" && s.reason, reason);
      assert.deepEqual(availableActions(s), ["reauthenticate"]);
      assert.ok(t.storage.value, "the ids survive for an authorised recovery");
    }
  });

  it("401 and 403 from the transport, and a forbidden handoff reply, block the machine", async () => {
    const server = new FakeServer();
    const a = tablet(server);
    server.hooks.redeem.push(async () => {
      throw new StaffTransportError("unauthorized");
    });
    await a.machine.startRedemption(MEMBER_A, ESPRESSO);
    assert.equal(a.machine.getState().phase === "blocked_authorization" && (a.machine.getState() as { reason: string }).reason, "unauthorized");

    const b = tablet(server);
    await b.machine.startRedemption(MEMBER_A, ESPRESSO);
    b.machine.acknowledgePrepare();
    server.hooks.handoff.push(replyWith({ outcome: "forbidden", handoff_request_id: "x", fulfillment: null, handoff: null }));
    await b.machine.confirmHandoff("handed_now");
    assert.equal(phase(b.machine), "handoff_unresolved", "an id that does not echo ours is an invalid reply");
    server.hooks.handoff.push(async (real) => {
      void real;
      throw new StaffTransportError("forbidden");
    });
    await b.machine.retryHandoff();
    assert.equal(phase(b.machine), "blocked_authorization");

    const c = tablet(server, { storage: b.storage });
    server.hooks.status.push(replyWith({ result: "forbidden", data: null }));
    await c.machine.restore();
    assert.equal(phase(c.machine), "blocked_authorization");
  });

  it("an expired session is recovered after a fresh login only by an explicit resume", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    t.machine.sessionEnded("expired");
    const relogged = tablet(server, { storage: t.storage, session: SESSION_2 });
    await relogged.machine.restore();
    const s = relogged.machine.getState();
    assert.equal(s.phase === "recovery_required" && s.reason, "session_changed");
    assert.equal(server.calls.filter((c) => c.op === "status").length, 0, "no read before the explicit resume");
    assert.deepEqual(availableActions(s), ["resume_recovery"]);
    assert.equal(await relogged.machine.resumeRecovery(), "applied");
    assert.equal(phase(relogged.machine), "previously_recorded_unconfirmed");
    assert.equal(JSON.parse(relogged.storage.value as string).session_ref, SESSION_2, "rebound to the new session");
  });

  it("the persisted context holds ids and stage only", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    t.machine.acknowledgePrepare();
    server.hooks.handoff.push(loseBeforeCommit);
    await t.machine.confirmHandoff("handed_now");
    for (const written of t.storage.writes) {
      const o = JSON.parse(written);
      assert.deepEqual(Object.keys(o).sort(), ["claim", "handoff_request_id", "member_id", "payload_hash", "redemption_request_id", "session_ref", "stage", "v"]);
      for (const forbidden of ["espresso", "double", "takeaway", "Espresso", "nonce", DEVICE_1, "token", "phone"]) {
        assert.ok(!written.includes(forbidden), `persisted context must not contain "${forbidden}"`);
      }
    }
  });

  it("corrupted or unreadable storage fails closed and can only be abandoned explicitly", async () => {
    const valid = {
      v: 1,
      session_ref: SESSION_1,
      member_id: MEMBER_A,
      redemption_request_id: "3fa85f64-5717-4562-b3fc-2c963f66afa6",
      payload_hash: "0123abcd",
      stage: "redemption_sent",
      handoff_request_id: null,
      claim: null,
    };
    const corrupt = [
      "{not json",
      "[]",
      JSON.stringify({ ...valid, v: 2 }),
      JSON.stringify({ ...valid, member_name: "Μαρία" }),
      JSON.stringify({ ...valid, redemption_request_id: "123" }),
      JSON.stringify({ ...valid, member_id: "member-1" }),
      JSON.stringify({ ...valid, stage: "handoff_sent" }),
      JSON.stringify({ ...valid, handoff_request_id: "7c9e6679-7425-40de-944b-e07fc1f90ae7", claim: "handed_now" }),
      JSON.stringify({ ...valid, stage: "recorded", payload_hash: "zz" }),
    ];
    for (const raw of corrupt) {
      const storage = new TabStorage();
      storage.value = raw;
      const t = tablet(new FakeServer(), { storage });
      await t.machine.restore();
      const s = t.machine.getState();
      assert.equal(s.phase === "recovery_required" && s.reason, "storage_corrupt", raw);
      assert.deepEqual(availableActions(s), ["abandon_intent", "manager_review"]);
    }
    const unreadable = new TabStorage();
    unreadable.failReads = true;
    const u = tablet(new FakeServer(), { storage: unreadable });
    await u.machine.restore();
    assert.equal(phase(u.machine), "recovery_required");
    assert.equal(u.machine.abandonIntent(), "applied");
    assert.equal(phase(u.machine), "idle");
  });

  it("a failing storage write never changes a decision", async () => {
    const server = new FakeServer();
    const storage = new TabStorage();
    storage.failWrites = true;
    const t = tablet(server, { storage });
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    assert.equal(phase(t.machine), "recorded_now");
  });

  it("the device reference is audit-only and never decides anything", async () => {
    const results: string[] = [];
    for (const device of [DEVICE_1, DEVICE_2]) {
      idCounter = 1000;
      const server = new FakeServer();
      const t = tablet(server, { device });
      await t.machine.startRedemption(MEMBER_A, ESPRESSO);
      t.machine.acknowledgePrepare();
      await t.machine.confirmHandoff("handed_now");
      results.push(JSON.stringify(t.machine.getState()));
      assert.equal(server.calls.find((c) => c.op === "handoff")?.deviceRef, device);
      assert.ok(!(t.storage.value ?? "").includes(device));
    }
    assert.equal(results[0], results[1]);
  });
});

// ---- concurrent tablets (one shared WordPress staff account) ---------------------------------

describe("UI-03D.D two tablets on the shared staff account", () => {
  it("different request ids for one member: one coffee, the other tablet is refused", async () => {
    const server = new FakeServer();
    const a = tablet(server);
    const b = tablet(server, { session: SESSION_2, device: DEVICE_2 });
    const held = deferred<unknown>();
    server.hooks.redeem.push((real) => held.promise.then(real));
    const pendingA = a.machine.startRedemption(MEMBER_A, ESPRESSO);
    await b.machine.startRedemption(MEMBER_A, CAPPUCCINO); // B commits while A is in flight
    held.resolve(undefined);
    await pendingA;
    assert.equal(phase(b.machine), "recorded_now");
    assert.equal(phase(a.machine), "redemption_refused");
    neverPrepares(a.machine.getState());
    assert.equal(server.redemptions.size, 1);
  });

  it("the same request id on two tablets: one fresh commit, one replay to verify", async () => {
    const server = new FakeServer();
    const shared = "3fa85f64-5717-4562-b3fc-2c963f66afa6";
    const a = tablet(server, { ids: () => shared });
    const b = tablet(server, { ids: () => shared, session: SESSION_2, device: DEVICE_2 });
    await a.machine.startRedemption(MEMBER_A, ESPRESSO);
    await b.machine.startRedemption(MEMBER_A, ESPRESSO);
    assert.equal(phase(a.machine), "recorded_now");
    assert.equal(phase(b.machine), "previously_recorded_unconfirmed");
    neverPrepares(b.machine.getState());
  });

  it("two simultaneous handoff attempts record exactly one confirmation", async () => {
    const server = new FakeServer();
    const shared = "3fa85f64-5717-4562-b3fc-2c963f66afa6";
    const a = tablet(server, { ids: (() => { let n = 0; return () => (n++ === 0 ? shared : "7c9e6679-7425-40de-944b-e07fc1f90ae7"); })() });
    await a.machine.startRedemption(MEMBER_A, ESPRESSO);
    a.machine.acknowledgePrepare();
    const b = tablet(server, { ids: (() => { let n = 0; return () => (n++ === 0 ? shared : "a3bb189e-8bf9-4888-9912-ace4e6543002"); })(), session: SESSION_2, device: DEVICE_2 });
    await b.machine.startRedemption(MEMBER_A, ESPRESSO);
    const holdA = deferred<unknown>();
    const holdB = deferred<unknown>();
    server.hooks.handoff.push((real) => holdA.promise.then(real), (real) => holdB.promise.then(real));
    const pa = a.machine.confirmHandoff("handed_now");
    const pb = b.machine.confirmHandoff("already_handed_earlier");
    holdB.resolve(undefined);
    await pb;
    holdA.resolve(undefined);
    await pa;
    assert.equal(server.handoffCount(), 1);
    const sa = a.machine.getState();
    const sb = b.machine.getState();
    assert.ok(sa.phase === "handoff_confirmed" && sb.phase === "handoff_confirmed");
    assert.equal(sb.via, "confirmed");
    assert.equal(sa.via, "already_confirmed");
    assert.deepEqual(sa.handoff, sb.handoff, "both show the single recorded statement");
    assert.equal(sa.handoff.handoffAtUtc, null);
  });

  it("an old 'unconfirmed' read after a newer confirmation never downgrades it", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    t.machine.acknowledgePrepare();
    await t.machine.confirmHandoff("handed_now");
    const rid = requestIdOf(t.machine);
    server.staleStatus.push({
      result: "found",
      data: { redemption_request_id: rid, business_date: "2026-10-14", redeemed_at_utc: "2026-10-14 07:30:00.000000", is_current_business_date: true, fulfillment: "unconfirmed", handoff: null },
    });
    assert.equal(await t.machine.checkStatus(), "applied");
    const s = t.machine.getState();
    assert.ok(s.phase === "handoff_confirmed");
    neverPrepares(s);
  });

  it("a delayed status reply from an older operation is ignored", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const held = deferred<unknown>();
    server.hooks.status.push((real) => held.promise.then(real));
    const pending = t.machine.checkStatus();
    t.machine.sessionEnded("locked");
    held.resolve(undefined);
    assert.equal(await pending, "stale");
    assert.equal(phase(t.machine), "blocked_authorization");
  });

  it("device loss during a confirmation: another tablet never infers a new coffee", async () => {
    const server = new FakeServer();
    const lost = tablet(server);
    await lost.machine.startRedemption(MEMBER_A, ESPRESSO);
    lost.machine.acknowledgePrepare();
    server.hooks.handoff.push(commitThenLose);
    await lost.machine.confirmHandoff("handed_now"); // tablet then disappears

    const other = tablet(server, { session: SESSION_2, device: DEVICE_2 });
    await other.machine.startRedemption(MEMBER_A, ESPRESSO);
    const s = other.machine.getState();
    assert.equal(s.phase === "redemption_refused" && s.code, "benefit_already_redeemed");
    neverPrepares(s);
    assert.equal(server.handoffCount(), 1);
  });

  it("another tablet already confirmed: our confirmation reports the original statement", async () => {
    const server = new FakeServer();
    const shared = "3fa85f64-5717-4562-b3fc-2c963f66afa6";
    const a = tablet(server, { ids: (() => { let n = 0; return () => (n++ === 0 ? shared : "7c9e6679-7425-40de-944b-e07fc1f90ae7"); })() });
    await a.machine.startRedemption(MEMBER_A, ESPRESSO);
    const b = tablet(server, { ids: (() => { let n = 0; return () => (n++ === 0 ? shared : "a3bb189e-8bf9-4888-9912-ace4e6543002"); })(), session: SESSION_2 });
    await b.machine.startRedemption(MEMBER_A, ESPRESSO);
    a.machine.acknowledgePrepare();
    await a.machine.confirmHandoff("already_handed_earlier");
    await b.machine.confirmHandoff("handed_now");
    const s = b.machine.getState();
    assert.ok(s.phase === "handoff_confirmed");
    assert.equal(s.via, "already_confirmed");
    assert.equal(s.handoff.claim, "already_handed_earlier");
    assert.equal(server.handoffCount(), 1);
  });
});

// ---- Athens business date --------------------------------------------------------------------

describe("UI-03D.D Athens business-date boundary (server-authoritative)", () => {
  it("yesterday's replay is never today's coffee; today's coffee is a new intent", async () => {
    const server = new FakeServer();
    server.now = new Date("2026-10-14T20:59:59Z"); // 23:59:59 Athens
    const t = tablet(server);
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    server.now = new Date("2026-10-14T21:00:05Z"); // 00:00:05 Athens on the 15th
    await t.machine.retryRedemption();
    const s = t.machine.getState();
    assert.equal(s.phase, "previous_business_date");
    neverPrepares(s);
    assert.ok(s.phase === "previous_business_date" && s.record.businessDate === "2026-10-14");
    assert.deepEqual(availableActions(s), ["finish"]);
    assert.equal(await t.machine.startRedemption(MEMBER_A, ESPRESSO), "rejected", "finish first");
    t.machine.finish();
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const today = t.machine.getState();
    assert.ok(today.phase === "recorded_now" && today.record.businessDate === "2026-10-15");
  });

  it("a delayed reply that crosses midnight keeps the server's date of the commit", async () => {
    const server = new FakeServer();
    server.now = new Date("2026-10-14T20:59:59Z");
    const t = tablet(server);
    const held = deferred<unknown>();
    server.hooks.redeem.push(async (real) => {
      const reply = real();
      await held.promise;
      return reply;
    });
    const pending = t.machine.startRedemption(MEMBER_A, ESPRESSO);
    server.now = new Date("2026-10-14T21:00:30Z");
    held.resolve(undefined);
    await pending;
    const s = t.machine.getState();
    assert.ok(s.phase === "recorded_now" && s.record.businessDate === "2026-10-14", "the commit's own business date");
  });

  it("a status read the next day reports the earlier date", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    server.now = new Date("2026-10-15T08:00:00Z");
    const reloaded = tablet(server, { storage: t.storage });
    await reloaded.machine.restore();
    assert.equal(phase(reloaded.machine), "previous_business_date");
    neverPrepares(reloaded.machine.getState());
  });

  it("the winter-time change moves the day boundary to 22:00 UTC", async () => {
    const server = new FakeServer();
    server.now = new Date("2026-10-25T21:59:59Z"); // 23:59:59 EET
    const t = tablet(server);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    assert.ok(t.machine.getState().phase === "recorded_now");
    t.machine.acknowledgePrepare();
    t.machine.sessionEnded("locked");
    server.now = new Date("2026-10-25T22:00:00Z"); // 00:00 EET on the 26th
    const next = tablet(server, { session: SESSION_2 });
    await next.machine.startRedemption(MEMBER_A, CAPPUCCINO);
    const s = next.machine.getState();
    assert.ok(s.phase === "recorded_now" && s.record.businessDate === "2026-10-26");
  });
});

// ---- isolation -------------------------------------------------------------------------------

describe("UI-03D.D purity", () => {
  it("the core uses no network, browser storage, DOM or wall clock", () => {
    for (const f of ["machine.ts", "wire.ts"]) {
      const source = readFileSync(join(process.env.EVC_APP_DIR ?? ".", "src", "staff", "redemptionRecovery", f), "utf8");
      // Code only: comments may name what the core deliberately does NOT use.
      const text = source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
      for (const needle of ["fetch(", "XMLHttpRequest", "sessionStorage", "localStorage", "window.", "document.", "navigator.", "Date.now", "new Date()", "setTimeout", "crypto."]) {
        assert.ok(!text.includes(needle), `${f} must not use ${needle}`);
      }
    }
  });
});
