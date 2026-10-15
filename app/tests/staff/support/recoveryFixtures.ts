import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { athensBusinessDate } from "../../../src/club/format";
import {
  StaffRedemptionMachine,
  StaffTransportError,
  availableActions,
  canonicalPayload,
  shouldOfferPrepare,
  type MachineState,
  type PreparedOrderPayload,
  type RecoveryStorage,
  type StaffRedemptionTransport,
} from "../../../src/staff/redemptionRecovery/machine";
import type { HandoffClaim } from "../../../src/staff/redemptionRecovery/wire";

/*
 * Shared TEST-ONLY fixtures for the staff recovery machine (moved unchanged
 * from redemptionRecovery.test.ts in UI-04J so the browser-storage adapter
 * tests drive the same fake server). Not a test file itself.
 */

export const MEMBER_A = "mem_0123456789abcdef0123456789abcdef";
export const MEMBER_B = "mem_fedcba9876543210fedcba9876543210";
export const SESSION_1 = "11111111111111111111111111111111";
export const SESSION_2 = "22222222222222222222222222222222";
export const DEVICE_1 = "dddddddddddddddddddddddddddddd01";
export const DEVICE_2 = "dddddddddddddddddddddddddddddd02";
/** 10:30 Athens (EEST). */
export const MORNING = "2026-10-14T07:30:00Z";

export const ESPRESSO: PreparedOrderPayload = {
  catalogueVersion: 1,
  coffeeCode: "espresso",
  preparation: { modifiers: [{ group: "shots", option: "double" }, { group: "sweetness", option: "none" }], quick_notes: ["takeaway"] },
};
export const CAPPUCCINO: PreparedOrderPayload = {
  catalogueVersion: 1,
  coffeeCode: "cappuccino",
  preparation: { modifiers: [{ group: "milk", option: "plant" }, { group: "shots", option: "single" }, { group: "sweetness", option: "none" }], quick_notes: [] },
};

// ---- helpers ---------------------------------------------------------------------------------

let idCounter = 0;
/** Sets the deterministic id counter (the device-reference test replays identical ids). */
export function resetIds(value: number): void {
  idCounter = value;
}
export function uuid(): string {
  idCounter++;
  const hex = idCounter.toString(16).padStart(12, "0");
  return `00000000-0000-4000-8000-${hex}`;
}

export function stampOf(d: Date): string {
  return `${d.toISOString().slice(0, 19).replace("T", " ")}.000000`;
}

/** Lets queued microtasks run until the condition holds (the async SHA-256 step precedes every request). */
export async function until(condition: () => boolean): Promise<void> {
  for (let i = 0; i < 100 && !condition(); i++) await new Promise((resolve) => setImmediate(resolve));
  assert.ok(condition(), "condition reached");
}

export function deferred<T>() {
  let resolve!: (v: T) => void;
  let reject!: (e: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

export class TabStorage implements RecoveryStorage {
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

export type Op = "redeem" | "handoff" | "status";
export type Hook = (real: () => unknown) => Promise<unknown>;

export interface ServerRedemption {
  memberId: string;
  payloadHash: string;
  staff: string;
  businessDate: string;
  redeemedAtUtc: string;
  handoff: null | { handoffRequestId: string; claim: HandoffClaim; recordedAtUtc: string };
}

/** Fake server: the PHP rules, the wire format, scripted failures and held replies. */
export class FakeServer implements StaffRedemptionTransport {
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
    // The server's own fingerprint stand-in: the exact canonical order (authoritative).
    return this.run("redeem", () => this.doRedeem(call.memberId, call.requestId, canonicalPayload(call.payload)));
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

export const commitThenLose: Hook = async (real) => {
  real();
  throw new StaffTransportError("network");
};
export const loseBeforeCommit: Hook = async () => {
  throw new StaffTransportError("timeout");
};
export const replyWith = (raw: unknown): Hook => async () => raw;

export const sha256Hex = async (text: string): Promise<string> => createHash("sha256").update(text, "utf8").digest("hex");

export function tablet<S extends RecoveryStorage = TabStorage>(server: FakeServer, opts: { storage?: S; session?: string; device?: string; ids?: () => string; sha?: (text: string) => Promise<string> } = {}) {
  const storage: S = opts.storage ?? (new TabStorage() as unknown as S);
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
    sha256Hex: opts.sha ?? sha256Hex,
  });
  return { machine, storage, idsMinted: () => ids };
}

export function phase(m: StaffRedemptionMachine): MachineState["phase"] {
  return m.getState().phase;
}

export function neverPrepares(s: MachineState): void {
  assert.equal(shouldOfferPrepare(s), false, `${s.phase} must not offer prepare`);
  assert.ok(!availableActions(s).includes("acknowledge_prepare"), `${s.phase} must not list acknowledge_prepare`);
}

export function requestIdOf(m: StaffRedemptionMachine): string {
  const s = m.getState() as { intent?: { requestId: string } };
  assert.ok(s.intent, "state carries the intent");
  return s.intent.requestId;
}

