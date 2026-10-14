import {
  HANDOFF_CLAIMS,
  MEMBER_ID,
  UUID_V4,
  parseHandoffReply,
  parseRedemptionReply,
  parseStatusReply,
  type HandoffClaim,
  type HandoffRefusal,
  type HandoffView,
  type RecordedRedemption,
  type RedemptionRefusal,
} from "./wire";

/**
 * UI-03D.D — staff redemption and handoff RECOVERY state machine.
 *
 * Pure TypeScript core: no fetch, no sessionStorage, no clock, no DOM. The
 * transport, the per-tab persistence and the id generator are injected. It is
 * NOT wired to the staff app or to any REST endpoint (future task).
 *
 * Business facts it keeps apart:
 *  - a redemption COMMIT consumes the daily entitlement (server ledger);
 *  - a handoff CONFIRMATION is a separate statement recorded by staff;
 *  - neither is proof that a coffee physically changed hands.
 *
 * Safety invariants (tested and mutation-guarded):
 *  1. Only a fresh `redeemed` reply offers "prepare", and only once
 *     (acknowledgePrepare), never again after a reload or a replay.
 *  2. A replay, a status read, an unknown outcome or any refusal never
 *     offers "prepare".
 *  3. Recovery always reuses the ORIGINAL redemption request id and the
 *     ORIGINAL handoff request id; no new id is ever created automatically.
 *  4. After a reload or uncertain outcome the server status is read before
 *     any action is offered; if it cannot be established, the machine stays
 *     unresolved (fail closed). A lost response is never treated as failure.
 *  5. A response that belongs to an older operation is ignored, and a known
 *     confirmation is never downgraded by a later "unconfirmed" read.
 *  6. An earlier Athens business date (server-supplied) is never today's coffee.
 *  7. 401 / 403 / lock / logout block the machine; record integrity failures
 *     block it for management review.
 *  8. Only parsed server values set the fulfillment status.
 */

export interface PreparedOrderPayload {
  catalogueVersion: number;
  coffeeCode: string;
  preparation: { modifiers: { group: string; option: string }[]; quick_notes: string[] };
}

export type TransportFailureKind = "network" | "timeout" | "unauthorized" | "forbidden" | "unavailable" | "rate_limited";

/** Thrown by a transport when no server reply body is available. */
export class StaffTransportError extends Error {
  readonly kind: TransportFailureKind;

  constructor(kind: TransportFailureKind) {
    super(`staff transport: ${kind}`);
    this.kind = kind;
  }
}

/** The FUTURE read/write API, modelled only. Implementations return the raw JSON reply. */
export interface StaffRedemptionTransport {
  redeem(call: { memberId: string; requestId: string; payload: PreparedOrderPayload }): Promise<unknown>;
  confirmHandoff(call: { memberId: string; handoffRequestId: string; redemptionRequestId: string; claim: HandoffClaim; deviceRef: string }): Promise<unknown>;
  redemptionStatus(call: { memberId: string; redemptionRequestId: string }): Promise<unknown>;
}

/** Minimal per-tab persistence. A future sessionStorage adapter needs separate review. */
export interface RecoveryStorage {
  read(): string | null;
  write(value: string): void;
  remove(): void;
}

export interface MachineDeps {
  transport: StaffRedemptionTransport;
  storage: RecoveryStorage;
  newId: () => string;
  /** Non-secret reference of the current staff login session (binding only). */
  sessionRef: string;
  /** Audit-only tablet reference. Never an authentication or decision input. */
  deviceRef: string;
  /**
   * SHA-256 of a UTF-8 string as 64 lowercase hex characters (UI-04B). A
   * future browser adapter would use crypto.subtle.digest; tests use Node's
   * crypto. Binds a retry to the exact order; the server's fingerprint v2
   * stays the authority.
   */
  sha256Hex: (text: string) => Promise<string>;
}

export interface RedemptionIntent {
  memberId: string;
  requestId: string;
  /** SHA-256 of canonicalPayload(): exact identity of the order this intent was created for. */
  payloadSha256: string;
}

export interface HandoffIntent {
  handoffRequestId: string;
  claim: HandoffClaim;
}

export type UnresolvedReason =
  | "outcome_unknown"
  | "server_error"
  | "network"
  | "timeout"
  | "unavailable"
  | "rate_limited"
  | "invalid_response"
  | "unexpected_error"
  | "status_unavailable"
  | "status_unconfirmed";

export type RecoveryReason =
  /** The server authoritatively has no such redemption: resubmit the SAME intent or abandon it. */
  | "not_recorded"
  /** The handoff refers to a redemption the server does not show for this member. */
  | "redemption_missing"
  /** Persisted context belongs to another login session: explicit resume needed. */
  | "session_changed"
  /** Persisted context unreadable: nothing can be recovered by id. */
  | "storage_corrupt"
  /** Persisted context written by an older, weaker format: never trusted (UI-04B). */
  | "storage_outdated"
  /** The server status could not be read; the original ids are kept (fail closed). */
  | "status_unavailable";

export type BlockReason = "unauthorized" | "forbidden" | "locked" | "logged_out" | "expired";

export type MachineState =
  | { phase: "idle" }
  | { phase: "submitting_redemption"; intent: RedemptionIntent }
  | { phase: "redemption_unresolved"; intent: RedemptionIntent; reason: UnresolvedReason }
  | { phase: "checking_status"; intent: RedemptionIntent; handoffIntent: HandoffIntent | null }
  | { phase: "recorded_now"; intent: RedemptionIntent; record: RecordedRedemption; prepareAcknowledged: boolean }
  /**
   * Recorded earlier, no confirmation recorded. `manuallyVerified` is LOCAL
   * workflow state only: staff consciously checked with the customer and/or
   * the counter. It is never persisted, never sent and never proof of a
   * physical handoff; any status read, reload, session end or new machine
   * resets it.
   */
  | { phase: "previously_recorded_unconfirmed"; intent: RedemptionIntent; record: RecordedRedemption; manuallyVerified: boolean }
  | { phase: "previously_recorded_confirmed"; intent: RedemptionIntent; record: RecordedRedemption; handoff: HandoffView }
  | { phase: "previous_business_date"; intent: RedemptionIntent; record: RecordedRedemption; handoff: HandoffView | null }
  | { phase: "redemption_refused"; intent: RedemptionIntent; code: RedemptionRefusal }
  | { phase: "handoff_confirmation_pending"; intent: RedemptionIntent; record: RecordedRedemption; handoffIntent: HandoffIntent }
  | { phase: "handoff_unresolved"; intent: RedemptionIntent; record: RecordedRedemption; handoffIntent: HandoffIntent; reason: UnresolvedReason }
  | { phase: "handoff_confirmed"; intent: RedemptionIntent; record: RecordedRedemption; handoff: HandoffView; via: "confirmed" | "replayed" | "already_confirmed" | "status" }
  | { phase: "handoff_refused"; intent: RedemptionIntent; record: RecordedRedemption; code: HandoffRefusal }
  | { phase: "blocked_integrity"; redemptionRequestId: string | null }
  | { phase: "blocked_authorization"; reason: BlockReason }
  | { phase: "recovery_required"; intent: RedemptionIntent | null; handoffIntent: HandoffIntent | null; reason: RecoveryReason };

export type Phase = MachineState["phase"];

export type Action =
  | "start_redemption"
  | "acknowledge_prepare"
  | "confirm_manual_verification"
  | "retry_redemption"
  | "resubmit_same_intent"
  | "check_status"
  | "resume_recovery"
  | "confirm_handed_now"
  | "confirm_handed_earlier"
  | "retry_handoff"
  | "finish"
  | "abandon_intent"
  | "reauthenticate"
  | "manager_review";

/** Persisted per-tab context: ids and stage only (no names, phones, nonces, tokens or payloads). */
interface PersistedContext {
  v: 2;
  session_ref: string;
  member_id: string;
  redemption_request_id: string;
  payload_sha256: string;
  stage: "redemption_sent" | "recorded" | "handoff_sent";
  handoff_request_id: string | null;
  claim: HandoffClaim | null;
}
/** v2 (UI-04B): SHA-256 payload identity. v1 (32-bit FNV-1a) records are never trusted. */
const PERSISTED_VERSION = 2;
const PERSISTED_KEYS = ["v", "session_ref", "member_id", "redemption_request_id", "payload_sha256", "stage", "handoff_request_id", "claim"];
const SESSION_REF = /^[0-9a-f]{32}$/;
const SHA256_HEX = /^[0-9a-f]{64}$/;
const CODE = /^[a-z][a-z0-9_]{1,31}$/;

/**
 * Validated, frozen deep copy of an order, or null. Every code must match the
 * canonical id pattern (so ":" "=" and newlines can never occur and the
 * canonical text below is unambiguous); the catalogue version is a positive
 * integer. Later changes to the caller's object never reach a retry.
 */
export function freezePayload(p: unknown): PreparedOrderPayload | null {
  if (typeof p !== "object" || p === null) return null;
  const o = p as Partial<PreparedOrderPayload>;
  const prep = o.preparation as Partial<PreparedOrderPayload["preparation"]> | undefined;
  if (!Number.isInteger(o.catalogueVersion) || (o.catalogueVersion as number) < 1 || typeof o.coffeeCode !== "string" || !CODE.test(o.coffeeCode)) return null;
  if (!prep || !Array.isArray(prep.modifiers) || !Array.isArray(prep.quick_notes)) return null;
  const modifiers: { group: string; option: string }[] = [];
  for (const m of prep.modifiers) {
    if (typeof m !== "object" || m === null || typeof m.group !== "string" || typeof m.option !== "string" || !CODE.test(m.group) || !CODE.test(m.option)) return null;
    modifiers.push(Object.freeze({ group: m.group, option: m.option }));
  }
  const notes: string[] = [];
  for (const n of prep.quick_notes) {
    if (typeof n !== "string" || !CODE.test(n)) return null;
    notes.push(n);
  }
  return Object.freeze({
    catalogueVersion: o.catalogueVersion as number,
    coffeeCode: o.coffeeCode,
    preparation: Object.freeze({ modifiers: Object.freeze(modifiers) as unknown as { group: string; option: string }[], quick_notes: Object.freeze(notes) as unknown as string[] }),
  });
}

/**
 * Canonical text of a VALIDATED order (order-independent, injective for
 * canonical codes): the exact identity hashed with SHA-256.
 */
export function canonicalPayload(p: PreparedOrderPayload): string {
  const mods = [...p.preparation.modifiers].map((m) => `${m.group}:${m.option}`).sort();
  const notes = [...p.preparation.quick_notes].sort();
  return ["evc-staff-intent-v2", `v=${p.catalogueVersion}`, `c=${p.coffeeCode}`, ...mods.map((m) => `m=${m}`), ...notes.map((n) => `n=${n}`)].join("\n");
}

/** Actions the UI may offer in a state. "prepare" exists ONLY as acknowledge_prepare in a fresh recorded_now. */
export function availableActions(s: MachineState): Action[] {
  switch (s.phase) {
    case "idle":
      return ["start_redemption"];
    case "submitting_redemption":
    case "checking_status":
    case "handoff_confirmation_pending":
      return [];
    case "redemption_unresolved":
      return ["retry_redemption", "check_status"];
    case "recorded_now":
      // "finish" without a confirmation is allowed: whether confirming is mandatory is an OPEN owner decision.
      return s.prepareAcknowledged ? ["confirm_handed_now", "confirm_handed_earlier", "finish"] : ["acknowledge_prepare"];
    case "previously_recorded_unconfirmed":
      // Never "prepare". A handoff can be recorded only after an explicit
      // manual verification (local workflow state, not proof of delivery).
      return s.manuallyVerified
        ? ["confirm_handed_now", "confirm_handed_earlier", "check_status", "finish"]
        : ["confirm_manual_verification", "check_status", "finish"];
    case "previously_recorded_confirmed":
    case "handoff_confirmed":
      return ["finish", "check_status"];
    case "redemption_refused":
      return ["finish"];
    case "previous_business_date":
      // Not today's coffee: today's entitlement needs a NEW intent after finishing this one.
      return ["finish"];
    case "handoff_unresolved":
      return ["retry_handoff", "check_status"];
    case "handoff_refused":
      return ["check_status", "manager_review"];
    case "blocked_integrity":
      return ["manager_review"];
    case "blocked_authorization":
      return ["reauthenticate"];
    case "recovery_required":
      if (s.reason === "not_recorded") return ["resubmit_same_intent", "abandon_intent"];
      if (s.reason === "session_changed") return ["resume_recovery"];
      if (s.reason === "storage_corrupt" || s.reason === "storage_outdated") return ["abandon_intent", "manager_review"];
      return ["check_status", "manager_review"];
  }
}

/** True only while a fresh commit's single "prepare" prompt is still due. */
export function shouldOfferPrepare(s: MachineState): boolean {
  return s.phase === "recorded_now" && !s.prepareAcknowledged;
}

export type Result = "applied" | "ignored" | "stale" | "rejected";

export class StaffRedemptionMachine {
  private state: MachineState = { phase: "idle" };
  private readonly deps: MachineDeps;
  /** Operation sequence: only the newest operation may apply its reply. */
  private seq = 0;
  private busy = false;
  /** The current intent's payload, in memory only (needed to retry the SAME request). */
  private payload: PreparedOrderPayload | null = null;
  private readonly listeners = new Set<() => void>();

  constructor(deps: MachineDeps) {
    this.deps = deps;
  }

  /** SHA-256 of the canonical order, or null when it cannot be computed or is malformed (fail closed). */
  private async identityOf(payload: PreparedOrderPayload): Promise<string | null> {
    try {
      const digest = await this.deps.sha256Hex(canonicalPayload(payload));
      return typeof digest === "string" && SHA256_HEX.test(digest) ? digest : null;
    } catch {
      return null;
    }
  }

  getState = (): MachineState => this.state;

  subscribe(listener: () => void): () => void {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  private set(next: MachineState): void {
    this.state = next;
    for (const l of this.listeners) l();
  }

  // ---- persistence (fail-safe: storage errors never change a decision) ---------------------

  private persist(ctx: Omit<PersistedContext, "v" | "session_ref">): void {
    const value: PersistedContext = { v: PERSISTED_VERSION, session_ref: this.deps.sessionRef, ...ctx };
    try {
      this.deps.storage.write(JSON.stringify(value));
    } catch {
      // Recovery after a reload will not be possible; the in-memory flow still works.
    }
  }

  private forget(): void {
    try {
      this.deps.storage.remove();
    } catch {
      // nothing to do
    }
  }

  private persistIntent(intent: RedemptionIntent, stage: PersistedContext["stage"], handoff: HandoffIntent | null): void {
    this.persist({
      member_id: intent.memberId,
      redemption_request_id: intent.requestId,
      payload_sha256: intent.payloadSha256,
      stage,
      handoff_request_id: handoff ? handoff.handoffRequestId : null,
      claim: handoff ? handoff.claim : null,
    });
  }

  /**
   * Boot after a reload or on a new tablet session. Reads the persisted
   * context and, when it belongs to this session, asks the server for the
   * authoritative status BEFORE offering any action.
   */
  async restore(): Promise<Result> {
    let raw: string | null;
    try {
      raw = this.deps.storage.read();
    } catch {
      raw = "";
    }
    if (raw === null) {
      this.set({ phase: "idle" });
      return "applied";
    }
    const ctx = parsePersisted(raw);
    if (ctx === "outdated") {
      this.set({ phase: "recovery_required", intent: null, handoffIntent: null, reason: "storage_outdated" });
      return "applied";
    }
    if (!ctx) {
      this.set({ phase: "recovery_required", intent: null, handoffIntent: null, reason: "storage_corrupt" });
      return "applied";
    }
    const intent: RedemptionIntent = { memberId: ctx.member_id, requestId: ctx.redemption_request_id, payloadSha256: ctx.payload_sha256 };
    const handoffIntent = ctx.handoff_request_id && ctx.claim ? { handoffRequestId: ctx.handoff_request_id, claim: ctx.claim } : null;
    if (ctx.session_ref !== this.deps.sessionRef) {
      this.set({ phase: "recovery_required", intent, handoffIntent, reason: "session_changed" });
      return "applied";
    }
    return this.queryStatus(intent, handoffIntent);
  }

  /** Explicit staff action after a session change: read the server status of the persisted intent. */
  async resumeRecovery(): Promise<Result> {
    const s = this.state;
    if (s.phase !== "recovery_required" || s.reason !== "session_changed" || !s.intent) return "rejected";
    this.persistIntent(s.intent, s.handoffIntent ? "handoff_sent" : "redemption_sent", s.handoffIntent);
    return this.queryStatus(s.intent, s.handoffIntent);
  }

  // ---- redemption ------------------------------------------------------------------------

  /** A NEW logical intent: only from idle (an open intent must be resolved or finished first). */
  async startRedemption(memberId: string, payload: PreparedOrderPayload): Promise<Result> {
    if (this.state.phase !== "idle" || this.busy || !MEMBER_ID.test(memberId)) return "rejected";
    const frozen = freezePayload(payload);
    if (!frozen) return "rejected";
    // Hashing is asynchronous: hold the machine so nothing else starts meanwhile.
    this.busy = true;
    const guard = this.seq;
    const digest = await this.identityOf(frozen);
    this.busy = false;
    if (guard !== this.seq || this.state.phase !== "idle") return "stale";
    if (digest === null) return "rejected";
    const requestId = this.deps.newId();
    if (!UUID_V4.test(requestId)) return "rejected";
    this.payload = frozen;
    return this.submitRedemption({ memberId, requestId, payloadSha256: digest });
  }

  /** Repeat the SAME request (same id, same payload) after an unresolved outcome. */
  async retryRedemption(): Promise<Result> {
    const s = this.state;
    if (s.phase !== "redemption_unresolved" || this.busy || !this.payload) return "rejected";
    return this.submitRedemption(s.intent);
  }

  /**
   * After the server authoritatively reported "not recorded": re-send the SAME
   * intent. The payload must be identical (hash-bound) and keeps the original id.
   */
  async resubmitSameIntent(payload: PreparedOrderPayload): Promise<Result> {
    const s = this.state;
    if (s.phase !== "recovery_required" || s.reason !== "not_recorded" || !s.intent || this.busy) return "rejected";
    const frozen = freezePayload(payload);
    if (!frozen) return "rejected";
    this.busy = true;
    const guard = this.seq;
    const digest = await this.identityOf(frozen);
    this.busy = false;
    if (guard !== this.seq || this.state !== s) return "stale";
    // Exact identity: SHA-256 of the canonical order must equal the original intent's.
    if (digest === null || digest !== s.intent.payloadSha256) return "rejected";
    this.payload = frozen;
    return this.submitRedemption(s.intent);
  }

  private async submitRedemption(intent: RedemptionIntent): Promise<Result> {
    const payload = this.payload as PreparedOrderPayload;
    this.persistIntent(intent, "redemption_sent", null);
    this.set({ phase: "submitting_redemption", intent });
    const op = this.begin();
    let raw: unknown;
    try {
      raw = await this.deps.transport.redeem({ memberId: intent.memberId, requestId: intent.requestId, payload });
    } catch (err) {
      if (!this.current(op)) return "stale";
      this.end();
      return this.failRedemption(intent, err);
    }
    if (!this.current(op)) return "stale";
    this.end();
    const reply = parseRedemptionReply(raw, { requestId: intent.requestId, memberId: intent.memberId });
    if (!reply) {
      this.set({ phase: "redemption_unresolved", intent, reason: "invalid_response" });
      return "applied";
    }
    switch (reply.kind) {
      case "redeemed":
        this.persistIntent(intent, "recorded", null);
        this.set({ phase: "recorded_now", intent, record: reply.record, prepareAcknowledged: false });
        return "applied";
      case "replayed":
        this.persistIntent(intent, "recorded", null);
        this.set(recordedState(intent, reply.record, reply.instruction === "do_not_prepare_for_today", reply.handoff));
        return "applied";
      case "refused":
        this.payload = null;
        this.forget();
        this.set({ phase: "redemption_refused", intent, code: reply.code });
        return "applied";
      case "integrity_failure":
        this.payload = null;
        this.set({ phase: "blocked_integrity", redemptionRequestId: intent.requestId });
        return "applied";
      case "unresolved":
        this.set({ phase: "redemption_unresolved", intent, reason: reply.code });
        return "applied";
    }
  }

  private failRedemption(intent: RedemptionIntent, err: unknown): Result {
    const block = blockOf(err);
    if (block) {
      this.block(block);
      return "applied";
    }
    // A lost or failed response is NOT a failed transaction: the intent stays open.
    this.set({ phase: "redemption_unresolved", intent, reason: unresolvedOf(err) });
    return "applied";
  }

  /**
   * Staff state they consciously checked the situation with the customer
   * and/or the counter (UI-04B). Local only: it unlocks recording a handoff
   * claim for a replayed/recovered redemption; it never prepares, redeems,
   * restores an entitlement or proves a handoff. Reset by any status read,
   * reload, new machine, session end or member change.
   */
  confirmManualVerification(): Result {
    const s = this.state;
    if (s.phase !== "previously_recorded_unconfirmed" || s.manuallyVerified || this.busy) return "rejected";
    this.set({ ...s, manuallyVerified: true });
    return "applied";
  }

  /** The single "prepare" prompt of a fresh commit was shown and acted upon. */
  acknowledgePrepare(): Result {
    const s = this.state;
    if (s.phase !== "recorded_now" || s.prepareAcknowledged) return "rejected";
    this.set({ ...s, prepareAcknowledged: true });
    return "applied";
  }

  // ---- status ------------------------------------------------------------------------------

  /** Reads the authoritative server status of the current intent before anything else is offered. */
  async checkStatus(): Promise<Result> {
    const s = this.state;
    if (this.busy) return "rejected";
    if (
      s.phase === "redemption_unresolved" ||
      s.phase === "previously_recorded_unconfirmed" ||
      s.phase === "previously_recorded_confirmed" ||
      s.phase === "handoff_confirmed" ||
      s.phase === "handoff_refused"
    ) {
      return this.queryStatus(s.intent, null);
    }
    if (s.phase === "handoff_unresolved") return this.queryStatus(s.intent, s.handoffIntent);
    if (s.phase === "recovery_required" && s.intent && (s.reason === "redemption_missing" || s.reason === "not_recorded" || s.reason === "status_unavailable")) {
      return this.queryStatus(s.intent, s.handoffIntent);
    }
    return "rejected";
  }

  private async queryStatus(intent: RedemptionIntent, handoffIntent: HandoffIntent | null): Promise<Result> {
    const before = this.state;
    this.set({ phase: "checking_status", intent, handoffIntent });
    const op = this.begin();
    let raw: unknown;
    try {
      raw = await this.deps.transport.redemptionStatus({ memberId: intent.memberId, redemptionRequestId: intent.requestId });
    } catch (err) {
      if (!this.current(op)) return "stale";
      this.end();
      const block = blockOf(err);
      if (block) {
        this.block(block);
        return "applied";
      }
      this.set(unresolvedAfterStatus(intent, handoffIntent, before, "status_unavailable"));
      return "applied";
    }
    if (!this.current(op)) return "stale";
    this.end();
    const status = parseStatusReply(raw, { redemptionRequestId: intent.requestId, memberId: intent.memberId });
    switch (status.kind) {
      case "unavailable":
        this.set(unresolvedAfterStatus(intent, handoffIntent, before, "status_unavailable"));
        return "applied";
      case "forbidden":
        this.block("forbidden");
        return "applied";
      case "integrity_failure":
        this.payload = null;
        this.set({ phase: "blocked_integrity", redemptionRequestId: intent.requestId });
        return "applied";
      case "not_found":
        // Authoritative: nothing is recorded for this id. Keep the SAME intent.
        this.set({ phase: "recovery_required", intent, handoffIntent, reason: handoffIntent ? "redemption_missing" : "not_recorded" });
        return "applied";
      case "found": {
        // A known confirmation is never downgraded by a later "unconfirmed" read.
        const known = knownHandoff(before);
        const handoff = status.handoff ?? (known && known.redemptionRequestId === intent.requestId ? known : null);
        if (!status.isCurrentBusinessDate) {
          this.set({ phase: "previous_business_date", intent, record: status.record, handoff });
          return "applied";
        }
        this.persistIntent(intent, handoffIntent && !handoff ? "handoff_sent" : "recorded", handoff ? null : handoffIntent);
        if (handoff) {
          if (before.phase === "handoff_confirmed" && before.handoff.handoffRequestId === handoff.handoffRequestId) {
            this.set(before);
          } else if (handoffIntent) {
            this.set({ phase: "handoff_confirmed", intent, record: status.record, handoff, via: "status" });
          } else {
            this.set({ phase: "previously_recorded_confirmed", intent, record: status.record, handoff });
          }
          return "applied";
        }
        if (handoffIntent) {
          // Our confirmation is not recorded (yet): retry it with the SAME handoff id.
          this.set({ phase: "handoff_unresolved", intent, record: status.record, handoffIntent, reason: "status_unconfirmed" });
          return "applied";
        }
        // Recorded, no confirmation: manual verification, NEVER "prepare".
        this.set({ phase: "previously_recorded_unconfirmed", intent, record: status.record, manuallyVerified: false });
        return "applied";
      }
    }
  }

  // ---- handoff -------------------------------------------------------------------------------

  /** Records a staff statement about the handoff. Never creates, reverses or restores an entitlement. */
  async confirmHandoff(claim: HandoffClaim): Promise<Result> {
    const s = this.state;
    if (this.busy || !HANDOFF_CLAIMS.includes(claim)) return "rejected";
    const allowed = (s.phase === "recorded_now" && s.prepareAcknowledged) || (s.phase === "previously_recorded_unconfirmed" && s.manuallyVerified);
    if (!allowed) return "rejected";
    const handoffRequestId = this.deps.newId();
    if (!UUID_V4.test(handoffRequestId)) return "rejected";
    return this.submitHandoff(s.intent, s.record, { handoffRequestId, claim });
  }

  /** Repeat the SAME confirmation (same handoff id and claim) after an unresolved outcome. */
  async retryHandoff(): Promise<Result> {
    const s = this.state;
    if (s.phase !== "handoff_unresolved" || this.busy) return "rejected";
    return this.submitHandoff(s.intent, s.record, s.handoffIntent);
  }

  private async submitHandoff(intent: RedemptionIntent, record: RecordedRedemption, handoffIntent: HandoffIntent): Promise<Result> {
    this.persistIntent(intent, "handoff_sent", handoffIntent);
    this.set({ phase: "handoff_confirmation_pending", intent, record, handoffIntent });
    const op = this.begin();
    let raw: unknown;
    try {
      raw = await this.deps.transport.confirmHandoff({
        memberId: intent.memberId,
        handoffRequestId: handoffIntent.handoffRequestId,
        redemptionRequestId: intent.requestId,
        claim: handoffIntent.claim,
        deviceRef: this.deps.deviceRef,
      });
    } catch (err) {
      if (!this.current(op)) return "stale";
      this.end();
      const block = blockOf(err);
      if (block) {
        this.block(block);
        return "applied";
      }
      this.set({ phase: "handoff_unresolved", intent, record, handoffIntent, reason: unresolvedOf(err) });
      return "applied";
    }
    if (!this.current(op)) return "stale";
    this.end();
    const reply = parseHandoffReply(raw, { handoffRequestId: handoffIntent.handoffRequestId, redemptionRequestId: intent.requestId, claim: handoffIntent.claim });
    if (!reply) {
      this.set({ phase: "handoff_unresolved", intent, record, handoffIntent, reason: "invalid_response" });
      return "applied";
    }
    switch (reply.kind) {
      case "recorded":
        this.persistIntent(intent, "recorded", null);
        this.set({ phase: "handoff_confirmed", intent, record, handoff: reply.handoff, via: reply.how });
        return "applied";
      case "unresolved":
        this.set({ phase: "handoff_unresolved", intent, record, handoffIntent, reason: reply.code });
        return "applied";
      case "forbidden":
        this.block("forbidden");
        return "applied";
      case "integrity_failure":
        this.set({ phase: "blocked_integrity", redemptionRequestId: intent.requestId });
        return "applied";
      case "refused":
        if (reply.code === "redemption_not_found") {
          this.set({ phase: "recovery_required", intent, handoffIntent, reason: "redemption_missing" });
        } else {
          this.persistIntent(intent, "recorded", null);
          this.set({ phase: "handoff_refused", intent, record, code: reply.code });
        }
        return "applied";
    }
  }

  // ---- closing and session events ----------------------------------------------------------

  /**
   * Close a settled state and clear the persisted context. A recorded but
   * unconfirmed redemption may be closed too (it stays "unconfirmed" on the
   * server, visible to management review): making confirmation mandatory is
   * an open owner decision. Never available while anything is unresolved.
   */
  finish(): Result {
    const s = this.state;
    const settled =
      s.phase === "handoff_confirmed" ||
      s.phase === "previously_recorded_confirmed" ||
      s.phase === "previously_recorded_unconfirmed" ||
      s.phase === "redemption_refused" ||
      s.phase === "previous_business_date" ||
      (s.phase === "recorded_now" && s.prepareAcknowledged);
    if (!settled || this.busy) return "rejected";
    this.reset();
    return "applied";
  }

  /**
   * Explicit staff action, allowed ONLY when nothing can be pending: the
   * server authoritatively reported "not recorded", or the persisted context
   * is unreadable. Never available for an unresolved outcome.
   */
  abandonIntent(): Result {
    const s = this.state;
    if (s.phase !== "recovery_required" || (s.reason !== "not_recorded" && s.reason !== "storage_corrupt" && s.reason !== "storage_outdated") || this.busy) return "rejected";
    this.reset();
    return "applied";
  }

  /**
   * Logout, lock, expiry or revoked capability: every in-flight reply becomes
   * stale, in-memory data is dropped, and the persisted ids remain for a later
   * authorised recovery (they contain no personal or secret data).
   */
  sessionEnded(reason: BlockReason): void {
    this.block(reason);
  }

  private block(reason: BlockReason): void {
    this.seq++;
    this.busy = false;
    this.payload = null;
    this.set({ phase: "blocked_authorization", reason });
  }

  private reset(): void {
    this.seq++;
    this.busy = false;
    this.payload = null;
    this.forget();
    this.set({ phase: "idle" });
  }

  private begin(): number {
    this.busy = true;
    return ++this.seq;
  }

  private end(): void {
    this.busy = false;
  }

  private current(op: number): boolean {
    return op === this.seq;
  }
}

function recordedState(intent: RedemptionIntent, record: RecordedRedemption, earlierDay: boolean, handoff: HandoffView | null): MachineState {
  if (earlierDay) return { phase: "previous_business_date", intent, record, handoff };
  if (handoff) return { phase: "previously_recorded_confirmed", intent, record, handoff };
  return { phase: "previously_recorded_unconfirmed", intent, record, manuallyVerified: false };
}

function knownHandoff(s: MachineState): HandoffView | null {
  if (s.phase === "handoff_confirmed" || s.phase === "previously_recorded_confirmed") return s.handoff;
  if (s.phase === "previous_business_date") return s.handoff;
  return null;
}

function unresolvedAfterStatus(intent: RedemptionIntent, handoffIntent: HandoffIntent | null, before: MachineState, reason: UnresolvedReason): MachineState {
  // Keep a known recorded/confirmed state; otherwise stay unresolved (fail closed).
  if (before.phase === "handoff_confirmed" || before.phase === "previously_recorded_confirmed" || before.phase === "previous_business_date") return before;
  if (handoffIntent && "record" in before) return { phase: "handoff_unresolved", intent, record: before.record, handoffIntent, reason };
  // An unreadable status is not authoritative, but it still voids a manual verification.
  if (before.phase === "previously_recorded_unconfirmed") return { ...before, manuallyVerified: false };
  if (before.phase === "handoff_refused") return before;
  // Nothing is known yet (e.g. right after a reload): keep BOTH original ids.
  if (handoffIntent || before.phase === "recovery_required" || before.phase === "idle") {
    return { phase: "recovery_required", intent, handoffIntent, reason: "status_unavailable" };
  }
  return { phase: "redemption_unresolved", intent, reason };
}

function blockOf(err: unknown): BlockReason | null {
  if (err instanceof StaffTransportError) {
    if (err.kind === "unauthorized") return "unauthorized";
    if (err.kind === "forbidden") return "forbidden";
  }
  return null;
}

function unresolvedOf(err: unknown): UnresolvedReason {
  if (!(err instanceof StaffTransportError)) return "unexpected_error";
  if (err.kind === "network" || err.kind === "timeout" || err.kind === "unavailable" || err.kind === "rate_limited") return err.kind;
  return "unexpected_error";
}

function parsePersisted(raw: string): PersistedContext | "outdated" | null {
  let v: unknown;
  try {
    v = JSON.parse(raw);
  } catch {
    return null;
  }
  if (typeof v !== "object" || v === null || Array.isArray(v)) return null;
  const o = v as Record<string, unknown>;
  if (o.v === 1) return "outdated";
  const keys = Object.keys(o);
  if (keys.length !== PERSISTED_KEYS.length || !PERSISTED_KEYS.every((k) => keys.includes(k))) return null;
  if (o.v !== PERSISTED_VERSION || typeof o.session_ref !== "string" || !SESSION_REF.test(o.session_ref)) return null;
  if (typeof o.member_id !== "string" || !MEMBER_ID.test(o.member_id)) return null;
  if (typeof o.redemption_request_id !== "string" || !UUID_V4.test(o.redemption_request_id)) return null;
  if (typeof o.payload_sha256 !== "string" || !SHA256_HEX.test(o.payload_sha256)) return null;
  if (o.stage !== "redemption_sent" && o.stage !== "recorded" && o.stage !== "handoff_sent") return null;
  const hasHandoff = o.handoff_request_id !== null || o.claim !== null;
  if (hasHandoff) {
    if (typeof o.handoff_request_id !== "string" || !UUID_V4.test(o.handoff_request_id) || !HANDOFF_CLAIMS.includes(o.claim as HandoffClaim)) return null;
    if (o.stage !== "handoff_sent") return null;
  } else if (o.stage === "handoff_sent") {
    return null;
  }
  return o as unknown as PersistedContext;
}
