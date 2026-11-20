/*
 * Owner decision D24 (round 9): strict parse of the prepared-redemption reply
 * (EVC_Redemption_Response::payload, wordpress/evangelou-club/docs/rest-contract-v2.md §A; the server always
 * answers HTTP 200 with it). The same rules as the reviewed, still unwired recovery parser
 * (src/staff/redemptionRecovery/wire.ts parseRedemptionReply), kept here so the shipped till does not import
 * the recovery module:
 *   - exactly the contract keys (+ retry_request_id only for outcome_unknown / server_error);
 *   - the sent request id echoed; a redemption record only for THIS request and member, on its Athens date;
 *   - ONLY a fresh commit may say "prepare"; a replay never does, and its instruction must follow from the
 *     facts (earlier day: do_not_prepare_for_today; a handoff: do_not_prepare; else verify_handoff);
 *   - outcome_unknown / server_error must carry retry_request_id = the SENT id (the only valid recovery).
 * Anything else is null: the till treats it as an unknown outcome and keeps the same request id.
 */

export type PreparedRefusal =
  | "invalid_request"
  | "invalid_preparation"
  | "invalid_coffee"
  | "catalogue_version_stale"
  | "catalogue_not_authorised"
  | "member_not_found"
  | "membership_inactive"
  | "benefit_already_redeemed"
  | "idempotency_key_reused";

const REFUSALS: readonly string[] = [
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

export type PreparedReply =
  | { kind: "redeemed"; businessDate: string }
  | { kind: "replayed"; businessDate: string; instruction: "verify_handoff" | "do_not_prepare" | "do_not_prepare_for_today" }
  | { kind: "refused"; code: PreparedRefusal }
  | { kind: "integrity_failure" }
  | { kind: "unresolved" };

const KEYS = ["entitlement_consumed", "fulfillment", "handoff", "instruction", "outcome", "recording", "redemption", "request_id", "server_business_date"];
const DAY = /^\d{4}-\d{2}-\d{2}$/;
const STAMP = /^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2}):(\d{2})\.\d{6}$/;
const ATHENS = new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Athens", year: "numeric", month: "2-digit", day: "2-digit" });

const isObj = (v: unknown): v is Record<string, unknown> => typeof v === "object" && v !== null && !Array.isArray(v);

function hasKeys(o: Record<string, unknown>, optional: string[]): boolean {
  const keys = Object.keys(o);
  return KEYS.every((k) => keys.includes(k)) && keys.every((k) => KEYS.includes(k) || optional.includes(k));
}

/** The Athens calendar day of a canonical UTC DATETIME(6) stamp ("" when it is not one). */
export function athensDayOfStamp(stamp: string): string {
  const m = STAMP.exec(stamp);
  if (!m) return "";
  const ms = Date.parse(`${m[1]}T${m[2]}:${m[3]}:${m[4]}Z`);
  return Number.isNaN(ms) ? "" : ATHENS.format(new Date(ms));
}

/** The record of THIS request and member, on its own Athens date; else null. */
function record(v: unknown, requestId: string, memberId: string): { businessDate: string } | null {
  if (!isObj(v) || Object.keys(v).sort().join(",") !== "business_date,member_id,redeemed_at_utc,request_id") return null;
  if (v.request_id !== requestId || v.member_id !== memberId || typeof v.business_date !== "string" || !DAY.test(v.business_date)) return null;
  if (typeof v.redeemed_at_utc !== "string" || athensDayOfStamp(v.redeemed_at_utc) !== v.business_date) return null;
  return { businessDate: v.business_date };
}

export function parsePreparedReply(raw: unknown, sent: { requestId: string; memberId: string }): PreparedReply | null {
  if (!isObj(raw) || !hasKeys(raw, ["retry_request_id"])) return null;
  if (raw.request_id !== sent.requestId || typeof raw.server_business_date !== "string" || !DAY.test(raw.server_business_date)) return null;
  const today = raw.server_business_date;
  const { outcome, recording, fulfillment, instruction, entitlement_consumed: consumed } = raw;
  const nothing = recording === null && fulfillment === null && instruction === "do_not_prepare" && raw.redemption === null && raw.handoff === null;

  if (outcome === "redeemed") {
    if ("retry_request_id" in raw || recording !== "recorded_now" || fulfillment !== "unconfirmed" || instruction !== "prepare" || consumed !== true || raw.handoff !== null) return null;
    const r = record(raw.redemption, sent.requestId, sent.memberId);
    return r && r.businessDate === today ? { kind: "redeemed", businessDate: r.businessDate } : null;
  }
  if (outcome === "replayed") {
    // No handoff table is wired yet, so a replay never carries a handoff (fail closed on one).
    if ("retry_request_id" in raw || recording !== "previously_recorded" || consumed !== true || raw.handoff !== null || fulfillment !== "unconfirmed") return null;
    const r = record(raw.redemption, sent.requestId, sent.memberId);
    if (!r || r.businessDate > today) return null;
    const expected = r.businessDate < today ? "do_not_prepare_for_today" : "verify_handoff";
    return instruction === expected ? { kind: "replayed", businessDate: r.businessDate, instruction: expected } : null;
  }
  if (typeof outcome === "string" && REFUSALS.includes(outcome)) {
    return !("retry_request_id" in raw) && nothing && consumed === false ? { kind: "refused", code: outcome as PreparedRefusal } : null;
  }
  if (outcome === "record_integrity_failure") {
    return !("retry_request_id" in raw) && nothing && consumed === null ? { kind: "integrity_failure" } : null;
  }
  if (outcome === "outcome_unknown" || outcome === "server_error") {
    return nothing && consumed === null && raw.retry_request_id === sent.requestId ? { kind: "unresolved" } : null;
  }
  return null;
}
