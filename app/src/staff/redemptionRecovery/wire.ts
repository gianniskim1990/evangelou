import { athensBusinessDate } from "../../club/format";
import type { TransportFailureKind } from "./machine";

/**
 * UI-03D.D — strict parsers for the FUTURE staff redemption, handoff and
 * status replies (pure TypeScript; no request is ever made here).
 *
 * The shapes mirror the PHP contracts on PR #14/#15:
 *   EVC_Prepared_Redemption_Outcome  (outcome, recording, fulfillment, instruction, entitlement_consumed)
 *   EVC_Handoff_Outcome              (handoff_confirmed | handoff_replayed | handoff_already_confirmed | ...)
 *   EVC_Fulfillment_Read_Model       (redemption_status)
 * in the snake_case JSON a future REST controller would emit. One field is a
 * REQUIRED addition for that controller: `server_business_date` (the server's
 * Europe/Athens business date at decision time) on every redemption reply, so
 * the client can check the instruction instead of trusting it blindly.
 *
 * Every parser fails closed: unknown outcomes, missing or extra keys, wrong
 * types, ids that do not echo the request, and CONTRADICTIONS (for example a
 * replay that says "prepare", "confirmed" without a handoff, a handoff time
 * invented for an earlier claim, or a redeemed_at_utc that is not on the
 * stated Athens business date) all yield null, which the machine treats as an
 * unknown outcome. Only a parsed server value can set the fulfillment status.
 */

export type Instruction = "prepare" | "verify_handoff" | "do_not_prepare" | "do_not_prepare_for_today";
export type Fulfillment = "unconfirmed" | "confirmed";
export type HandoffClaim = "handed_now" | "already_handed_earlier";
export const HANDOFF_CLAIMS: readonly HandoffClaim[] = ["handed_now", "already_handed_earlier"];

export const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
export const MEMBER_ID = /^mem_[0-9a-f]{32}$/;
const BUSINESS_DATE = /^\d{4}-\d{2}-\d{2}$/;
/** PHP DATETIME(6) in UTC: "2026-10-14 07:30:00.000000". */
const UTC_STAMP = /^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\.(\d{6})$/;

/** A committed redemption as the machine keeps it: no names, phones or preparation payload. */
export interface RecordedRedemption {
  requestId: string;
  memberId: string;
  businessDate: string;
  redeemedAtUtc: string;
}

/**
 * A handoff confirmation. `handoffAtUtc` is null for an earlier handoff: the
 * real time is unknown and is never invented; `confirmationRecordedAtUtc` is
 * only when the STATEMENT was recorded.
 */
export interface HandoffView {
  handoffRequestId: string;
  redemptionRequestId: string;
  claim: HandoffClaim;
  confirmationRecordedAtUtc: string;
  handoffAtUtc: string | null;
  handoffTime: "stated_at_recording" | "not_recorded";
}

export type RedemptionRefusal =
  | "invalid_request"
  | "invalid_preparation"
  | "invalid_coffee"
  | "catalogue_version_stale"
  | "catalogue_not_authorised"
  | "member_not_found"
  | "membership_inactive"
  | "benefit_already_redeemed"
  | "idempotency_key_reused";
const REDEMPTION_REFUSALS: readonly string[] = [
  "invalid_request",
  "invalid_preparation",
  "invalid_coffee",
  "catalogue_version_stale",
  "catalogue_not_authorised",
  "member_not_found",
  "membership_inactive",
  "benefit_already_redeemed",
  "idempotency_key_reused",
];

export type RedemptionReply =
  | { kind: "redeemed"; record: RecordedRedemption; serverBusinessDate: string }
  | {
      kind: "replayed";
      record: RecordedRedemption;
      serverBusinessDate: string;
      fulfillment: Fulfillment;
      instruction: "verify_handoff" | "do_not_prepare" | "do_not_prepare_for_today";
      handoff: HandoffView | null;
    }
  | { kind: "refused"; code: RedemptionRefusal }
  | { kind: "integrity_failure" }
  | { kind: "unresolved"; code: "outcome_unknown" | "server_error" };

export type HandoffRefusal = "invalid_request" | "redemption_not_found" | "late_confirmation_not_authorised" | "idempotency_key_reused";
const HANDOFF_REFUSALS: readonly string[] = ["invalid_request", "redemption_not_found", "late_confirmation_not_authorised", "idempotency_key_reused"];

export type HandoffReply =
  | { kind: "recorded"; how: "confirmed" | "replayed" | "already_confirmed"; handoff: HandoffView }
  | { kind: "refused"; code: HandoffRefusal }
  | { kind: "forbidden" }
  | { kind: "integrity_failure" }
  | { kind: "unresolved"; code: "outcome_unknown" | "server_error" };

export type StatusReply =
  | { kind: "found"; record: RecordedRedemption; isCurrentBusinessDate: boolean; fulfillment: Fulfillment; handoff: HandoffView | null }
  | { kind: "not_found" }
  | { kind: "forbidden" }
  | { kind: "integrity_failure" }
  | { kind: "unavailable" };

type Obj = Record<string, unknown>;

function isObj(v: unknown): v is Obj {
  return typeof v === "object" && v !== null && !Array.isArray(v);
}

/** Exactly these keys (plus optional ones), nothing else. */
function hasKeys(v: Obj, required: readonly string[], optional: readonly string[] = []): boolean {
  const keys = Object.keys(v);
  return required.every((k) => keys.includes(k)) && keys.every((k) => required.includes(k) || optional.includes(k));
}

export function isBusinessDate(v: unknown): v is string {
  if (typeof v !== "string" || !BUSINESS_DATE.test(v)) return false;
  const d = new Date(`${v}T00:00:00Z`);
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === v;
}

export function isUtcStamp(v: unknown): v is string {
  if (typeof v !== "string") return false;
  const m = UTC_STAMP.exec(v);
  if (!m || !isBusinessDate(m[1])) return false;
  const d = new Date(`${m[1]}T${m[2]}Z`);
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(11, 19) === m[2];
}

/** Europe/Athens business date of a server UTC stamp (DST-aware, device-timezone independent). */
export function athensDateOfStamp(stamp: string): string {
  const m = UTC_STAMP.exec(stamp) as RegExpExecArray;
  return athensBusinessDate(new Date(`${m[1]}T${m[2]}.${m[3].slice(0, 3)}Z`));
}

function parseRecord(v: unknown, requestId: string, memberId: string): RecordedRedemption | null {
  if (!isObj(v) || !hasKeys(v, ["request_id", "member_id", "business_date", "redeemed_at_utc"])) return null;
  if (v.request_id !== requestId || v.member_id !== memberId) return null;
  if (!isBusinessDate(v.business_date) || !isUtcStamp(v.redeemed_at_utc)) return null;
  // The stated business date must be the Athens day of the stated instant.
  if (athensDateOfStamp(v.redeemed_at_utc) !== v.business_date) return null;
  return { requestId, memberId, businessDate: v.business_date, redeemedAtUtc: v.redeemed_at_utc };
}

/** Handoff view of a given redemption; claim/time consistency is enforced (no invented time). */
export function parseHandoffView(v: unknown, redemptionRequestId: string): HandoffView | null {
  if (!isObj(v) || !hasKeys(v, ["handoff_request_id", "redemption_request_id", "claim", "confirmation_recorded_at_utc", "handoff_at_utc", "handoff_time"])) {
    return null;
  }
  const { handoff_request_id: hid, redemption_request_id: rid, claim, confirmation_recorded_at_utc: recorded, handoff_at_utc: at, handoff_time: time } = v;
  if (typeof hid !== "string" || !UUID_V4.test(hid) || rid !== redemptionRequestId) return null;
  if (!HANDOFF_CLAIMS.includes(claim as HandoffClaim) || !isUtcStamp(recorded)) return null;
  if (claim === "handed_now") {
    if (at !== recorded || time !== "stated_at_recording") return null;
  } else if (at !== null || time !== "not_recorded") {
    return null;
  }
  return {
    handoffRequestId: hid,
    redemptionRequestId: rid,
    claim: claim as HandoffClaim,
    confirmationRecordedAtUtc: recorded,
    handoffAtUtc: at as string | null,
    handoffTime: time as HandoffView["handoffTime"],
  };
}

const REDEMPTION_KEYS = ["outcome", "request_id", "recording", "fulfillment", "instruction", "entitlement_consumed", "server_business_date", "redemption", "handoff"];

/** Parses a redemption reply for the request that was sent; null = contradictory or malformed. */
export function parseRedemptionReply(raw: unknown, sent: { requestId: string; memberId: string }): RedemptionReply | null {
  if (!isObj(raw) || !hasKeys(raw, REDEMPTION_KEYS, ["retry_request_id"])) return null;
  if (raw.request_id !== sent.requestId || !isBusinessDate(raw.server_business_date)) return null;
  const today = raw.server_business_date;
  const { outcome, recording, fulfillment, instruction, entitlement_consumed: consumed } = raw;
  const nothingRecorded = recording === null && fulfillment === null && instruction === "do_not_prepare" && raw.redemption === null && raw.handoff === null;

  if (outcome === "redeemed") {
    if (raw.retry_request_id !== undefined) return null;
    if (recording !== "recorded_now" || fulfillment !== "unconfirmed" || instruction !== "prepare" || consumed !== true || raw.handoff !== null) return null;
    const record = parseRecord(raw.redemption, sent.requestId, sent.memberId);
    // A fresh commit is always on the server's current business date.
    if (!record || record.businessDate !== today) return null;
    return { kind: "redeemed", record, serverBusinessDate: today };
  }
  if (outcome === "replayed") {
    if (raw.retry_request_id !== undefined) return null;
    if (recording !== "previously_recorded" || consumed !== true) return null;
    const record = parseRecord(raw.redemption, sent.requestId, sent.memberId);
    if (!record || record.businessDate > today) return null;
    const handoff = raw.handoff === null ? null : parseHandoffView(raw.handoff, sent.requestId);
    if (raw.handoff !== null && handoff === null) return null;
    if (fulfillment !== (handoff ? "confirmed" : "unconfirmed")) return null;
    const expected = record.businessDate < today ? "do_not_prepare_for_today" : handoff ? "do_not_prepare" : "verify_handoff";
    // A replay can never say "prepare", and its instruction must follow from the facts.
    if (instruction !== expected) return null;
    return { kind: "replayed", record, serverBusinessDate: today, fulfillment: fulfillment as Fulfillment, instruction: expected, handoff };
  }
  if (typeof outcome === "string" && REDEMPTION_REFUSALS.includes(outcome)) {
    if (raw.retry_request_id !== undefined || !nothingRecorded || consumed !== false) return null;
    return { kind: "refused", code: outcome as RedemptionRefusal };
  }
  if (outcome === "record_integrity_failure") {
    if (raw.retry_request_id !== undefined || !nothingRecorded || consumed !== null) return null;
    return { kind: "integrity_failure" };
  }
  if (outcome === "outcome_unknown" || outcome === "server_error") {
    // Recovery must use the ORIGINAL request id.
    if (!nothingRecorded || consumed !== null || raw.retry_request_id !== sent.requestId) return null;
    return { kind: "unresolved", code: outcome };
  }
  return null;
}

const HANDOFF_KEYS = ["outcome", "handoff_request_id", "fulfillment", "handoff"];

/** Parses a handoff reply for the confirmation that was sent; null = contradictory or malformed. */
export function parseHandoffReply(
  raw: unknown,
  sent: { handoffRequestId: string; redemptionRequestId: string; claim: HandoffClaim },
): HandoffReply | null {
  if (!isObj(raw) || !hasKeys(raw, HANDOFF_KEYS, ["retry_handoff_request_id"]) || raw.handoff_request_id !== sent.handoffRequestId) return null;
  const { outcome } = raw;
  if (outcome === "handoff_confirmed" || outcome === "handoff_replayed" || outcome === "handoff_already_confirmed") {
    if (raw.retry_handoff_request_id !== undefined || raw.fulfillment !== "confirmed") return null;
    const handoff = parseHandoffView(raw.handoff, sent.redemptionRequestId);
    if (!handoff) return null;
    const ours = handoff.handoffRequestId === sent.handoffRequestId;
    if (outcome === "handoff_already_confirmed") {
      // Another statement was recorded first; it must NOT carry our id.
      if (ours) return null;
      return { kind: "recorded", how: "already_confirmed", handoff };
    }
    // Our own statement: same id and the claim we sent.
    if (!ours || handoff.claim !== sent.claim) return null;
    return { kind: "recorded", how: outcome === "handoff_confirmed" ? "confirmed" : "replayed", handoff };
  }
  const empty = raw.fulfillment === null && raw.handoff === null;
  if (outcome === "outcome_unknown" || outcome === "server_error") {
    if (!empty || raw.retry_handoff_request_id !== sent.handoffRequestId) return null;
    return { kind: "unresolved", code: outcome };
  }
  if (!empty || raw.retry_handoff_request_id !== undefined) return null;
  if (outcome === "forbidden") return { kind: "forbidden" };
  if (outcome === "record_integrity_failure") return { kind: "integrity_failure" };
  if (typeof outcome === "string" && HANDOFF_REFUSALS.includes(outcome)) return { kind: "refused", code: outcome as HandoffRefusal };
  return null;
}

const STATUS_DATA_KEYS = ["redemption_request_id", "business_date", "redeemed_at_utc", "is_current_business_date", "fulfillment", "handoff"];
/** Display-only fields of the read model, accepted but never kept by the machine. */
const STATUS_DISPLAY_KEYS = ["coffee_label", "modifiers", "quick_notes"];

/** Parses a redemption_status read; a damaged or contradictory reply counts as unavailable (fail closed). */
export function parseStatusReply(raw: unknown, query: { redemptionRequestId: string; memberId: string }): StatusReply {
  if (!isObj(raw) || !hasKeys(raw, ["result", "data"])) return { kind: "unavailable" };
  const { result, data } = raw;
  if (result !== "found") {
    if (data !== null) return { kind: "unavailable" };
    if (result === "not_found") return { kind: "not_found" };
    if (result === "forbidden") return { kind: "forbidden" };
    if (result === "record_integrity_failure") return { kind: "integrity_failure" };
    return { kind: "unavailable" };
  }
  if (!isObj(data) || !hasKeys(data, STATUS_DATA_KEYS, STATUS_DISPLAY_KEYS) || typeof data.is_current_business_date !== "boolean") return { kind: "unavailable" };
  const record = parseRecord(
    { request_id: data.redemption_request_id, member_id: query.memberId, business_date: data.business_date, redeemed_at_utc: data.redeemed_at_utc },
    query.redemptionRequestId,
    query.memberId,
  );
  if (!record) return { kind: "unavailable" };
  const handoff = data.handoff === null ? null : parseHandoffView(data.handoff, query.redemptionRequestId);
  if (data.handoff !== null && handoff === null) return { kind: "unavailable" };
  if (data.fulfillment !== (handoff ? "confirmed" : "unconfirmed")) return { kind: "unavailable" };
  return { kind: "found", record, isCurrentBusinessDate: data.is_current_business_date, fulfillment: data.fulfillment as Fulfillment, handoff };
}

// ---- UI-04G: member lookup, usual coffee and HTTP reply classification ----------------------

export type LookupReply =
  | { kind: "found"; memberPublicId: string; lookupRef: string; lookupRefExpiresAtUtc: string }
  | { kind: "not_found" | "ambiguous" | "invalid_phone" | "forbidden" | "rate_limited" | "unavailable" };
const LOOKUP_REFUSALS: readonly string[] = ["not_found", "ambiguous", "invalid_phone", "forbidden", "rate_limited", "unavailable"];
const LOOKUP_REF = /^lkp1\.[a-z0-9]{1,16}\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$/;

/** Member lookup = identity only: the member id and a short-lived proof, nothing else. */
export function parseLookupReply(raw: unknown): LookupReply | null {
  if (!isObj(raw) || !hasKeys(raw, ["result", "member"])) return null;
  const { result, member } = raw;
  if (result === "found") {
    if (!isObj(member) || !hasKeys(member, ["member_public_id", "lookup_ref", "lookup_ref_expires_at_utc"])) return null;
    const { member_public_id: id, lookup_ref: ref, lookup_ref_expires_at_utc: expires } = member;
    if (typeof id !== "string" || !MEMBER_ID.test(id) || typeof ref !== "string" || ref.length > 512 || !LOOKUP_REF.test(ref) || !isUtcStamp(expires)) return null;
    return { kind: "found", memberPublicId: id, lookupRef: ref, lookupRefExpiresAtUtc: expires };
  }
  if (member !== null || typeof result !== "string" || !LOOKUP_REFUSALS.includes(result)) return null;
  return { kind: result as Exclude<LookupReply["kind"], "found"> };
}

export interface UsualCoffeeSuggestion {
  catalogueVersion: number;
  coffeeCode: string;
  coffeeLabel: string;
  modifiers: { group: string; option: string; groupLabel: string; label: string }[];
  quickNotes: { id: string; label: string }[];
  basis: { occurrences: number; considered: number };
}

export type UsualCoffeeReply =
  | { kind: "suggested"; suggestion: UsualCoffeeSuggestion }
  | { kind: "no_suggestion"; reason: string }
  | { kind: "forbidden" | "invalid_request" | "member_not_found" | "server_error" };
const USUAL_REASONS: readonly string[] = ["insufficient_history", "no_clear_usual", "not_offerable_with_current_catalogue", "catalogue_not_authorised"];
const USUAL_REFUSALS: readonly string[] = ["forbidden", "invalid_request", "member_not_found", "server_error"];
const CODE = /^[a-z][a-z0-9_]{1,31}$/;

function isLabel(v: unknown): v is string {
  return typeof v === "string" && v.length > 0 && v.trim() === v;
}

/** A suggestion is NEVER a confirmed order (confirmed_order must be false). */
export function parseUsualCoffeeReply(raw: unknown): UsualCoffeeReply | null {
  if (!isObj(raw) || !hasKeys(raw, ["result", "reason", "suggestion", "confirmed_order"]) || raw.confirmed_order !== false) return null;
  const { result, reason, suggestion: s } = raw;
  if (result === "suggested") {
    if (reason !== null || !isObj(s) || !hasKeys(s, ["catalogue_version", "coffee_code", "coffee_label", "modifiers", "quick_notes", "basis"])) return null;
    if (!Number.isInteger(s.catalogue_version) || (s.catalogue_version as number) < 1 || typeof s.coffee_code !== "string" || !CODE.test(s.coffee_code) || !isLabel(s.coffee_label)) return null;
    if (!Array.isArray(s.modifiers) || !Array.isArray(s.quick_notes) || !isObj(s.basis) || !hasKeys(s.basis, ["occurrences", "considered"])) return null;
    const modifiers: UsualCoffeeSuggestion["modifiers"] = [];
    for (const m of s.modifiers) {
      if (!isObj(m) || !hasKeys(m, ["group", "option", "group_label", "label"])) return null;
      if (typeof m.group !== "string" || !CODE.test(m.group) || typeof m.option !== "string" || !CODE.test(m.option) || !isLabel(m.group_label) || !isLabel(m.label)) return null;
      modifiers.push({ group: m.group, option: m.option, groupLabel: m.group_label, label: m.label });
    }
    const quickNotes: UsualCoffeeSuggestion["quickNotes"] = [];
    for (const n of s.quick_notes) {
      if (!isObj(n) || !hasKeys(n, ["id", "label"]) || typeof n.id !== "string" || !CODE.test(n.id) || !isLabel(n.label)) return null;
      quickNotes.push({ id: n.id, label: n.label });
    }
    const { occurrences, considered } = s.basis;
    if (!Number.isInteger(occurrences) || !Number.isInteger(considered) || (occurrences as number) < 1 || (occurrences as number) > (considered as number)) return null;
    return {
      kind: "suggested",
      suggestion: { catalogueVersion: s.catalogue_version as number, coffeeCode: s.coffee_code, coffeeLabel: s.coffee_label, modifiers, quickNotes, basis: { occurrences: occurrences as number, considered: considered as number } },
    };
  }
  if (s !== null) return null;
  if (result === "no_suggestion") {
    return typeof reason === "string" && USUAL_REASONS.includes(reason) ? { kind: "no_suggestion", reason } : null;
  }
  if (reason !== null || typeof result !== "string" || !USUAL_REFUSALS.includes(result)) return null;
  return { kind: result as "forbidden" | "invalid_request" | "member_not_found" | "server_error" };
}

export type ReplySurface = "redemption" | "handoff" | "status" | "lookup" | "usual";

/**
 * The recommended status of every v2 domain body (mirror of PHP
 * EVC_Contract_Http; the contract test compares it with the map exported
 * into the PHP-generated vectors).
 */
export const CONTRACT_STATUS: Readonly<Record<ReplySurface, Readonly<Record<string, number>>>> = {
  redemption: {
    redeemed: 201, replayed: 200,
    invalid_request: 400, invalid_preparation: 400, invalid_coffee: 400,
    catalogue_version_stale: 409, catalogue_not_authorised: 503,
    member_not_found: 404, membership_inactive: 409, benefit_already_redeemed: 409, idempotency_key_reused: 409,
    record_integrity_failure: 500, outcome_unknown: 503, server_error: 503,
  },
  handoff: {
    handoff_confirmed: 201, handoff_replayed: 200, handoff_already_confirmed: 200,
    invalid_request: 400, forbidden: 403, redemption_not_found: 404,
    late_confirmation_not_authorised: 409, idempotency_key_reused: 409,
    record_integrity_failure: 500, outcome_unknown: 503, server_error: 503,
  },
  status: { found: 200, not_found: 404, forbidden: 403, invalid_request: 400, record_integrity_failure: 500, server_error: 503 },
  lookup: { found: 200, not_found: 404, ambiguous: 409, invalid_phone: 400, forbidden: 403, rate_limited: 429, unavailable: 503 },
  usual: { suggested: 200, no_suggestion: 200, forbidden: 403, invalid_request: 400, member_not_found: 404, server_error: 503 },
};

/**
 * Splits an HTTP reply for a request to `surface` into a v2 DOMAIN body or
 * a TRANSPORT failure. A body is DOMAIN only when its outcome/result belongs
 * to that surface AND the HTTP status is exactly the contract status for it
 * (a 503 outcome_unknown keeps its same-id retry; a 401 is NEVER a domain
 * body). A contradictory status/body pair, the v1 `{"error":…}` envelope
 * (used before any service ran) and non-JSON replies (proxy pages, JSONP,
 * `_envelope` wrappers) are transport failures, which never say whether
 * anything was committed.
 */
export function classifyHttpReply(surface: ReplySurface, status: number, body: unknown): { kind: "domain"; body: Obj } | { kind: "transport"; failure: TransportFailureKind } {
  if (isObj(body) && !("error" in body) && status !== 401) {
    const key = surface === "redemption" || surface === "handoff" ? body.outcome : body.result;
    const map = CONTRACT_STATUS[surface];
    if (typeof key === "string" && Object.prototype.hasOwnProperty.call(map, key)) {
      return map[key] === status ? { kind: "domain", body } : { kind: "transport", failure: "unavailable" };
    }
  }
  const code = isObj(body) && isObj(body.error) && typeof body.error.code === "string" ? body.error.code : null;
  if (code === "unauthorized" || (code === null && status === 401)) return { kind: "transport", failure: "unauthorized" };
  if (code === "forbidden" || (code === null && status === 403)) return { kind: "transport", failure: "forbidden" };
  if (code === "rate_limited" || (code === null && status === 429)) return { kind: "transport", failure: "rate_limited" };
  return { kind: "transport", failure: "unavailable" };
}
