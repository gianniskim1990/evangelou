import { athensBusinessDate } from "../../club/format";
import { coffeeName } from "../../preview/previewData";
import type { ClubMember, MemberBenefitStatus } from "../../club/types";
import { parseCatalogue, parsePreparation, validatePreparation, type Catalogue } from "../../club/preparationContract";
import type { HandoffClaim } from "../redemptionRecovery/wire";
import { FAKE_DIRECTORY, SHARED_PHONE, canonicalGreekMobile, entryById, type DirectoryEntry } from "./fakeDirectory";
import { PREVIEW_CATALOGUE_DOCUMENT } from "./previewCatalogue";

/**
 * UI-04O LOCAL-ONLY fake Club server for the counter-flow preview.
 *
 * Deterministic, in memory, no network. It models the rules of the REAL PHP
 * services (UI-03D.A/C, UI-04C, UI-04G/H) closely enough to drive the REAL
 * client code (StaffRedemptionMachine, the strict wire parsers and
 * classifyHttpReply) through every counter situation:
 *  - one free coffee per member per Europe/Athens business day (the ledger
 *    key is member + business date, never the preparation);
 *  - request-id idempotency: a repeated id REPLAYS the original record (and
 *    never says "prepare"); the same id with a different order is refused;
 *  - a lookup proof is required for a redemption and bound to the staff
 *    session and the member; without it nothing is recorded (403 envelope);
 *  - handoff statements are idempotent by their own id and never invent a
 *    handoff time for an earlier handoff;
 *  - session and permission loss answer with the v1 transport envelope
 *    (401 / 403), exactly like the documented v2 boundary.
 * Replies are { status, body } pairs with the EXACT v2 bodies and the
 * contract HTTP statuses (CONTRACT_STATUS), so the preview's transport runs
 * them through the same classifier a real transport would.
 *
 * Scripted faults (`schedule`) reproduce what the network and the server can
 * do to a real request: the reply is lost after the commit, the request never
 * arrives, the outcome is unknown, the session expired, the permission was
 * withdrawn, the stored record is inconsistent, or another tablet redeemed
 * first.
 *
 * NOT a contract: `memberSummary` has NO v2 route. The real v2 lookup
 * returns identity only (member id + lookup proof); the preview shows the
 * member's name, membership and today's benefit with the existing v1 domain
 * types as a clearly marked stand-in. See docs/staff-counter-preview.md.
 */

export interface HttpReply {
  status: number;
  body: unknown;
}

/** The reply never reached the tablet (thrown instead of a reply). */
export class TransportLoss extends Error {
  readonly kind: "network" | "timeout";

  constructor(kind: "network" | "timeout") {
    super(`fake transport: ${kind}`);
    this.kind = kind;
  }
}

export type Operation = "lookup" | "summary" | "redeem" | "status" | "handoff";

export type Fault =
  /** The server commits, then the reply is lost on the way back. */
  | "lose_reply_after_commit"
  /** The request never reaches the server: nothing happens there. */
  | "lose_request"
  /** The server commits but cannot confirm it: 503 outcome_unknown with the same id to retry. */
  | "outcome_unknown_after_commit"
  /** The server fails before committing: 503 server_error with the same id to retry. */
  | "server_error_before_commit"
  /** The WordPress session is gone (expiry, idle timeout, revocation): 401 envelope. */
  | "session_expired"
  /** The account lost the Club capability: 403 envelope. */
  | "permission_revoked"
  /** The stored record contradicts itself: record_integrity_failure. */
  | "integrity_failure"
  /** The status read model is unavailable: 503. */
  | "status_unavailable"
  /** Just before this request, another tablet redeemed today's coffee for the same member. */
  | "another_tablet_first";

export interface ServerLogEntry {
  readonly seq: number;
  readonly at: string;
  readonly operation: Operation;
  /** Request ids involved (redemption id, handoff id), shown so that reuse of the ORIGINAL id is visible. */
  readonly ids: readonly string[];
  readonly status: number | "lost";
  readonly outcome: string;
  readonly fault: Fault | null;
}

interface Redemption {
  memberId: string;
  /** Canonical preparation (the stand-in for the server fingerprint). */
  identity: string;
  coffeeLabel: string;
  businessDate: string;
  redeemedAtUtc: string;
  sessionRef: string;
  handoff: { handoffRequestId: string; claim: HandoffClaim; recordedAtUtc: string } | null;
}

interface LookupProof {
  memberId: string;
  sessionRef: string;
  expiresAt: number;
}

/** Provisional (NOT approved) lookup-proof lifetime used only by the preview. */
export const PREVIEW_LOOKUP_PROOF_TTL_MS = 15 * 60_000;

export function utcStamp(d: Date): string {
  return `${d.toISOString().slice(0, 19).replace("T", " ")}.${String(d.getUTCMilliseconds()).padStart(3, "0")}000`;
}

const envelope = (status: number, code: string): HttpReply => ({ status, body: { error: { code, message: code } } });

export interface FakeServerOptions {
  /** Server clock (UTC instant). The business date is always derived from it in Europe/Athens. */
  now: () => Date;
}

export class FakeClubServer {
  private readonly catalogue: Catalogue = parseCatalogue(PREVIEW_CATALOGUE_DOCUMENT);
  private readonly redemptions = new Map<string, Redemption>();
  private readonly byDay = new Map<string, string>();
  private readonly handoffs = new Map<string, string>();
  private readonly proofs = new Map<string, LookupProof>();
  private readonly sessions = new Set<string>();
  private readonly faults: Record<Operation, Fault[]> = { lookup: [], summary: [], redeem: [], status: [], handoff: [] };
  private readonly entries: ServerLogEntry[] = [];
  private readonly listeners = new Set<() => void>();
  private proofCounter = 0;
  private seeded = 0;
  private readonly clock: () => Date;

  constructor(options: FakeServerOptions) {
    this.clock = options.now;
    this.seedToday();
  }

  // ---- observation (preview tooling only) ----------------------------------------------------

  subscribe(listener: () => void): () => void {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  log(): readonly ServerLogEntry[] {
    return this.entries;
  }

  businessDate(): string {
    return athensBusinessDate(this.clock());
  }

  /** Server-confirmed redemptions of the current Athens business day (for the dashboard). */
  todaysLedger(): { memberId: string; requestId: string; redeemedAtUtc: string; coffeeLabel: string; handoff: boolean }[] {
    const today = this.businessDate();
    return [...this.redemptions.entries()]
      .filter(([, r]) => r.businessDate === today)
      .map(([requestId, r]) => ({ memberId: r.memberId, requestId, redeemedAtUtc: r.redeemedAtUtc, coffeeLabel: r.coffeeLabel, handoff: r.handoff !== null }))
      .sort((a, b) => (a.redeemedAtUtc < b.redeemedAtUtc ? 1 : -1));
  }

  /** Number of redemptions recorded for a member on a business date (the invariant is: never more than one). */
  redemptionCount(memberId: string, businessDate: string): number {
    return [...this.redemptions.values()].filter((r) => r.memberId === memberId && r.businessDate === businessDate).length;
  }

  handoffCount(requestId: string): number {
    return [...this.handoffs.values()].filter((rid) => rid === requestId).length;
  }

  // ---- scripting -------------------------------------------------------------------------------

  schedule(operation: Operation, fault: Fault): void {
    this.faults[operation].push(fault);
    this.notify();
  }

  pendingFaults(): readonly { operation: Operation; fault: Fault }[] {
    return (Object.keys(this.faults) as Operation[]).flatMap((operation) => this.faults[operation].map((fault) => ({ operation, fault })));
  }

  clearFaults(): void {
    for (const op of Object.keys(this.faults) as Operation[]) this.faults[op].length = 0;
    this.notify();
  }

  startSession(sessionRef: string): void {
    this.sessions.add(sessionRef);
  }

  /** /session/end: the WordPress session is destroyed (lock, logout). */
  endSession(sessionRef: string): void {
    this.sessions.delete(sessionRef);
  }

  isSessionActive(sessionRef: string): boolean {
    return this.sessions.has(sessionRef);
  }

  // ---- routes ----------------------------------------------------------------------------------

  /** POST /lookup/qr and POST /lookup/phone (v2): identity + a short-lived proof, nothing else. */
  lookup(sessionRef: string, by: { qrToken: string } | { phone: string }): HttpReply {
    return this.handle("lookup", sessionRef, [], () => {
      let entry: DirectoryEntry | null | "ambiguous" = null;
      if ("qrToken" in by) {
        entry = FAKE_DIRECTORY.find((e) => e.qrToken === by.qrToken) ?? null;
      } else {
        const phone = canonicalGreekMobile(by.phone);
        if (!phone) return { status: 400, body: { result: "invalid_phone", member: null } };
        entry = phone === SHARED_PHONE ? "ambiguous" : (FAKE_DIRECTORY.find((e) => e.phone === phone) ?? null);
      }
      if (entry === "ambiguous") return { status: 409, body: { result: "ambiguous", member: null } };
      if (!entry) return { status: 404, body: { result: "not_found", member: null } };
      const ref = this.issueProof(entry.memberId, sessionRef);
      return {
        status: 200,
        body: { result: "found", member: { member_public_id: entry.memberId, lookup_ref: ref, lookup_ref_expires_at_utc: utcStamp(new Date(this.proofs.get(ref)!.expiresAt)) } },
      };
    });
  }

  /**
   * PREVIEW-ONLY stand-in (NO v2 route exists): name, masked phone,
   * membership and today's benefit in the existing v1 domain types. Needs
   * the same lookup proof; the redemption engine stays the only authority.
   */
  memberSummary(sessionRef: string, memberId: string, lookupRef: string): HttpReply {
    return this.handle("summary", sessionRef, [], () => {
      const entry = entryById(memberId);
      if (!entry || !this.validProof(lookupRef, memberId, sessionRef)) return envelope(403, "forbidden");
      const today = this.businessDate();
      const status: ClubMember["status"] = entry.person.state === "active" ? "active" : entry.person.state === "expired" ? "expired" : "inactive";
      const member: ClubMember = { id: memberId, name: entry.person.name, phoneMasked: entry.person.phoneMasked, status, validUntil: null };
      const rid = this.byDay.get(`${memberId}|${today}`);
      const used = rid ? this.redemptions.get(rid)! : null;
      const benefit: MemberBenefitStatus =
        status !== "active"
          ? { state: "unavailable", businessDate: today, redeemedAt: null, reason: "membership_inactive" }
          : used
            ? { state: "used", businessDate: today, redeemedAt: `${used.redeemedAtUtc.slice(0, 10)}T${used.redeemedAtUtc.slice(11, 19)}Z`, coffeeCode: null }
            : { state: "available", businessDate: today, redeemedAt: null };
      return { status: 200, body: { member, benefit } };
    });
  }

  /** POST /members/{member}/redemptions (v2). */
  redeem(sessionRef: string, memberId: string, body: { request_id: string; catalogue_version: number; coffee_code: string; preparation: unknown; lookup_ref: string }): HttpReply {
    return this.handle("redeem", sessionRef, [body.request_id], (fault) => {
      // A missing or invalid lookup proof: nothing recorded, v1 forbidden envelope (UI-04H boundary).
      if (!this.validProof(body.lookup_ref, memberId, sessionRef)) return envelope(403, "forbidden");
      if (fault === "another_tablet_first") this.redeemFromAnotherTablet(memberId);
      const today = this.businessDate();
      const base = { request_id: body.request_id, server_business_date: today };
      const nothing = { recording: null, fulfillment: null, instruction: "do_not_prepare", redemption: null, handoff: null };
      const refused = (outcome: string, status: number): HttpReply => ({ status, body: { ...base, outcome, ...nothing, entitlement_consumed: false } });
      if (fault === "server_error_before_commit") return { status: 503, body: { ...base, outcome: "server_error", ...nothing, entitlement_consumed: null, retry_request_id: body.request_id } };
      if (fault === "integrity_failure") return { status: 500, body: { ...base, outcome: "record_integrity_failure", ...nothing, entitlement_consumed: null } };

      const entry = entryById(memberId);
      const parsed = parsePreparation(body.preparation);
      const validated = parsed.ok && entry ? validatePreparation(this.catalogue, body.coffee_code, parsed.value) : null;
      const identity = validated && validated.ok ? `${memberId}\n${validated.value.canonical}` : null;

      const existing = this.redemptions.get(body.request_id);
      if (existing) {
        // Idempotency: the SAME id replays the original record; a different order under it is refused.
        if (existing.memberId !== memberId || existing.identity !== identity) return refused("idempotency_key_reused", 409);
        return this.replay(body.request_id, today);
      }
      if (!entry) return refused("member_not_found", 404);
      if (entry.person.state !== "active") return refused("membership_inactive", 409);
      if (body.catalogue_version !== this.catalogue.version) return refused("catalogue_version_stale", 409);
      if (!parsed.ok) return refused(parsed.kind, 400);
      if (!validated || !validated.ok) return refused(validated ? validated.kind : "invalid_preparation", 400);
      if (this.byDay.has(`${memberId}|${today}`)) return refused("benefit_already_redeemed", 409);

      const at = this.clock();
      this.redemptions.set(body.request_id, { memberId, identity: identity!, coffeeLabel: validated.value.coffeeLabel, businessDate: today, redeemedAtUtc: utcStamp(at), sessionRef, handoff: null });
      this.byDay.set(`${memberId}|${today}`, body.request_id);
      if (fault === "outcome_unknown_after_commit") return { status: 503, body: { ...base, outcome: "outcome_unknown", ...nothing, entitlement_consumed: null, retry_request_id: body.request_id } };
      return {
        status: 201,
        body: { ...base, outcome: "redeemed", recording: "recorded_now", fulfillment: "unconfirmed", instruction: "prepare", entitlement_consumed: true, redemption: this.redemptionWire(body.request_id), handoff: null },
      };
    });
  }

  /** GET /members/{member}/redemptions/{rid} (v2 read model). */
  status(sessionRef: string, memberId: string, rid: string): HttpReply {
    return this.handle("status", sessionRef, [rid], (fault) => {
      if (fault === "status_unavailable") return { status: 503, body: { result: "server_error", data: null } };
      if (fault === "integrity_failure") return { status: 500, body: { result: "record_integrity_failure", data: null } };
      const r = this.redemptions.get(rid);
      if (!r || r.memberId !== memberId) return { status: 404, body: { result: "not_found", data: null } };
      const handoff = this.handoffWire(rid);
      return {
        status: 200,
        body: {
          result: "found",
          data: {
            redemption_request_id: rid,
            business_date: r.businessDate,
            redeemed_at_utc: r.redeemedAtUtc,
            is_current_business_date: r.businessDate === this.businessDate(),
            fulfillment: handoff ? "confirmed" : "unconfirmed",
            handoff,
          },
        },
      };
    });
  }

  /** POST /members/{member}/redemptions/{rid}/handoff (v2): a staff STATEMENT, never proof of delivery. */
  handoff(sessionRef: string, memberId: string, rid: string, body: { handoff_request_id: string; claim: HandoffClaim }): HttpReply {
    const hid = body.handoff_request_id;
    return this.handle("handoff", sessionRef, [rid, hid], (fault) => {
      const fail = (outcome: string, status: number): HttpReply => ({ status, body: { outcome, handoff_request_id: hid, fulfillment: null, handoff: null } });
      const ok = (outcome: string, status: number): HttpReply => ({ status, body: { outcome, handoff_request_id: hid, fulfillment: "confirmed", handoff: this.handoffWire(rid) } });
      if (fault === "server_error_before_commit") return { status: 503, body: { outcome: "server_error", handoff_request_id: hid, fulfillment: null, handoff: null, retry_handoff_request_id: hid } };
      if (fault === "integrity_failure") return fail("record_integrity_failure", 500);
      const known = this.handoffs.get(hid);
      if (known !== undefined) {
        const r = this.redemptions.get(known)!;
        if (known !== rid || r.memberId !== memberId || r.handoff?.claim !== body.claim) return fail("idempotency_key_reused", 409);
        return ok("handoff_replayed", 200);
      }
      const r = this.redemptions.get(rid);
      if (!r || r.memberId !== memberId) return fail("redemption_not_found", 404);
      if (r.handoff) return ok("handoff_already_confirmed", 200);
      if (r.businessDate !== this.businessDate()) return fail("late_confirmation_not_authorised", 409);
      r.handoff = { handoffRequestId: hid, claim: body.claim, recordedAtUtc: utcStamp(this.clock()) };
      this.handoffs.set(hid, rid);
      if (fault === "outcome_unknown_after_commit") return { status: 503, body: { outcome: "outcome_unknown", handoff_request_id: hid, fulfillment: null, handoff: null, retry_handoff_request_id: hid } };
      return ok("handoff_confirmed", 201);
    });
  }

  // ---- internals -------------------------------------------------------------------------------

  /**
   * Common request handling: session check (401 envelope), scripted fault,
   * the route itself, and the log entry. Faults that lose the reply throw
   * TransportLoss AFTER the route ran (the commit happened) or BEFORE it
   * (nothing happened).
   */
  private handle(operation: Operation, sessionRef: string, ids: string[], route: (fault: Fault | null) => HttpReply): HttpReply {
    const fault = this.faults[operation].shift() ?? null;
    const record = (status: number | "lost", outcome: string) => {
      this.entries.push(Object.freeze({ seq: this.entries.length + 1, at: utcStamp(this.clock()), operation, ids: Object.freeze([...ids]), status, outcome, fault }));
      this.notify();
    };
    if (fault === "lose_request") {
      record("lost", "δεν έφτασε στον διακομιστή");
      throw new TransportLoss("timeout");
    }
    if (fault === "permission_revoked") this.sessions.delete(sessionRef);
    if (fault === "session_expired") this.sessions.delete(sessionRef);
    let reply: HttpReply;
    if (fault === "permission_revoked") reply = envelope(403, "forbidden");
    else if (!this.sessions.has(sessionRef)) reply = envelope(401, "unauthorized");
    else reply = route(fault);
    const outcome = outcomeOf(reply.body);
    if (fault === "lose_reply_after_commit") {
      record("lost", `${outcome} — η απάντηση χάθηκε`);
      throw new TransportLoss("network");
    }
    record(reply.status, outcome);
    return reply;
  }

  private issueProof(memberId: string, sessionRef: string): string {
    this.proofCounter++;
    const payload = `p${this.proofCounter.toString(36)}`;
    const mac = `preview${this.proofCounter.toString(36)}`.padEnd(43, "x");
    const ref = `lkp1.preview.${payload}.${mac}`;
    this.proofs.set(ref, { memberId, sessionRef, expiresAt: this.clock().getTime() + PREVIEW_LOOKUP_PROOF_TTL_MS });
    return ref;
  }

  private validProof(ref: string, memberId: string, sessionRef: string): boolean {
    const proof = this.proofs.get(ref);
    return !!proof && proof.memberId === memberId && proof.sessionRef === sessionRef && this.clock().getTime() < proof.expiresAt;
  }

  private replay(rid: string, today: string): HttpReply {
    const r = this.redemptions.get(rid)!;
    const handoff = this.handoffWire(rid);
    const instruction = r.businessDate < today ? "do_not_prepare_for_today" : handoff ? "do_not_prepare" : "verify_handoff";
    return {
      status: 200,
      body: {
        request_id: rid,
        server_business_date: today,
        outcome: "replayed",
        recording: "previously_recorded",
        fulfillment: handoff ? "confirmed" : "unconfirmed",
        instruction,
        entitlement_consumed: true,
        redemption: this.redemptionWire(rid),
        handoff,
      },
    };
  }

  private redemptionWire(rid: string) {
    const r = this.redemptions.get(rid)!;
    return { request_id: rid, member_id: r.memberId, business_date: r.businessDate, redeemed_at_utc: r.redeemedAtUtc };
  }

  private handoffWire(rid: string) {
    const h = this.redemptions.get(rid)?.handoff;
    if (!h) return null;
    return {
      handoff_request_id: h.handoffRequestId,
      redemption_request_id: rid,
      claim: h.claim,
      confirmation_recorded_at_utc: h.recordedAtUtc,
      handoff_at_utc: h.claim === "handed_now" ? h.recordedAtUtc : null,
      handoff_time: h.claim === "handed_now" ? "stated_at_recording" : "not_recorded",
    };
  }

  /** Another tablet (another staff session) records today's coffee for this member first. */
  private redeemFromAnotherTablet(memberId: string): void {
    const today = this.businessDate();
    if (this.byDay.has(`${memberId}|${today}`)) return;
    const rid = this.seedId();
    this.redemptions.set(rid, { memberId, identity: "other-tablet", coffeeLabel: "Espresso", businessDate: today, redeemedAtUtc: utcStamp(new Date(this.clock().getTime() - 20_000)), sessionRef: "other-tablet", handoff: null });
    this.byDay.set(`${memberId}|${today}`, rid);
  }

  /** Members marked "coffee already today" in the approved synthetic data get a ledger row on today's date. */
  private seedToday(): void {
    const today = this.businessDate();
    let earlier = 0;
    for (const entry of FAKE_DIRECTORY) {
      if (!entry.person.redeemedToday) continue;
      // Staggered earlier the same Athens day (never before its midnight).
      earlier++;
      const candidate = new Date(this.clock().getTime() - earlier * 17 * 60_000);
      const at = athensBusinessDate(candidate) === today ? candidate : this.clock();
      const rid = this.seedId();
      const hid = this.seedId();
      this.redemptions.set(rid, {
        memberId: entry.memberId,
        identity: "seed",
        coffeeLabel: coffeeName(entry.person.favourite),
        businessDate: today,
        redeemedAtUtc: utcStamp(at),
        sessionRef: "earlier-today",
        handoff: { handoffRequestId: hid, claim: "handed_now", recordedAtUtc: utcStamp(at) },
      });
      this.byDay.set(`${entry.memberId}|${today}`, rid);
      this.handoffs.set(hid, rid);
    }
  }

  private seedId(): string {
    this.seeded++;
    return `00000000-0000-4000-8000-${this.seeded.toString(16).padStart(12, "0")}`;
  }

  private notify(): void {
    for (const l of this.listeners) l();
  }
}

function outcomeOf(body: unknown): string {
  if (typeof body !== "object" || body === null) return "—";
  const b = body as Record<string, unknown>;
  if (typeof b.outcome === "string") return b.outcome;
  if (typeof b.result === "string") return b.result;
  if (typeof b.error === "object" && b.error !== null) return `error:${String((b.error as Record<string, unknown>).code)}`;
  if ("member" in b && "benefit" in b) return "summary";
  return "—";
}
