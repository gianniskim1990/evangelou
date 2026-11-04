import { createRestClubService } from "../../club/restClubService";
import type { ClubService } from "../../club/clubService";
import { StaffApiError, type StaffApi } from "../staffApi";

/*
 * STAGE-05D: the counter's two member requests, over the staff transport
 * (same origin, cookie session, in-memory X-WP-Nonce: StaffApi.staffFetch).
 *
 * POST /members/lookup {"method":"qr","qr_token":…}  (docs/evangelou-club-api.md §4.1,
 * wordpress/evangelou-club/docs/member-qr.md) — the owner-approved minimum (D8):
 *   200 {"member":{"member_id","first_name"},"status":"active"|"used_today"|"inactive"}
 *   400 invalid_qr       -> invalid_qr   (not a Club QR at all)
 *   404 member_not_found -> not_found    (ONE answer for unknown / replaced / revoked / disabled;
 *                                         only WITH the envelope: a bare 404 is invalid_response)
 *   429 rate_limited     -> rate_limited (Retry-After, D9: about 10 failed scans / 5 min / tablet)
 *   5xx                  -> unavailable
 *   401 / 403            -> thrown StaffApiError: the controller ends the session (fail closed)
 *   anything else        -> thrown StaffApiError("invalid_response")
 * The 200 body is parsed strictly: exactly these keys, nothing more is ever shown.
 *
 * Redemption reuses the reviewed v1 redeem client (restClubService) with the
 * same transport: POST /members/{id}/benefits/free_coffee/redeem.
 */

export const LOOKUP_TIMEOUT_MS = 10_000;
export const DEFAULT_RETRY_AFTER_SECONDS = 300;
const MAX_RETRY_AFTER_SECONDS = 3600;
const MEMBER_ID_RE = /^mem_[0-9a-f]{32}$/;
/** Longer than any QR the server accepts (256 bytes): refused locally, never sent. */
const MAX_SCAN_LENGTH = 256;

export type MemberStatus = "active" | "used_today" | "inactive";

export interface FoundMember {
  memberId: string;
  /** D8 «μικρό όνομα»: the first word of the first name, or null when the account has none. */
  firstName: string | null;
}

export type LookupOutcome =
  | { kind: "found"; member: FoundMember; status: MemberStatus }
  | { kind: "not_found" }
  | { kind: "invalid_qr" }
  | { kind: "rate_limited"; retryAfterSeconds: number }
  | { kind: "unavailable" };

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === "object" && v !== null && !Array.isArray(v);
const keysAre = (o: Record<string, unknown>, keys: string[]) => Object.keys(o).sort().join(",") === [...keys].sort().join(",");

/** Strict parse of a lookup 200 body; null = not the contract. */
export function parseLookupFound(body: unknown): { member: FoundMember; status: MemberStatus } | null {
  if (!isRecord(body) || !keysAre(body, ["member", "status"]) || !isRecord(body.member)) return null;
  const { member, status } = body;
  if (!keysAre(member, ["member_id", "first_name"])) return null;
  if (typeof member.member_id !== "string" || !MEMBER_ID_RE.test(member.member_id)) return null;
  const name = member.first_name;
  if (name !== null && (typeof name !== "string" || name.trim() === "" || [...name].length > 40)) return null;
  if (status !== "active" && status !== "used_today" && status !== "inactive") return null;
  return { member: { memberId: member.member_id, firstName: name }, status };
}

function envelopeCode(body: unknown): string | null {
  return isRecord(body) && isRecord(body.error) && typeof body.error.code === "string" ? body.error.code : null;
}

/** Retry-After header (seconds), else details.retry_after_seconds, else 300; bounded to 1..3600. */
export function retryAfterOf(header: string | null, body: unknown): number {
  let seconds: number | null = null;
  if (header && /^\d{1,6}$/.test(header.trim())) seconds = Number(header.trim());
  else if (isRecord(body) && isRecord(body.error) && isRecord(body.error.details)) {
    const d = body.error.details.retry_after_seconds;
    if (typeof d === "number" && Number.isInteger(d)) seconds = d;
  }
  if (seconds === null || seconds < 1) return DEFAULT_RETRY_AFTER_SECONDS;
  return Math.min(seconds, MAX_RETRY_AFTER_SECONDS);
}

export function mapLookupReply(status: number, body: unknown, retryAfter: string | null): LookupOutcome {
  if (status === 200) {
    const found = parseLookupFound(body);
    if (!found) throw new StaffApiError("invalid_response", status);
    return { kind: "found", ...found };
  }
  const code = envelopeCode(body);
  if (status === 401) throw new StaffApiError("unauthorized", 401);
  if (status === 403) throw new StaffApiError("forbidden", 403);
  if (status === 404 && code === "member_not_found") return { kind: "not_found" };
  if (status === 400 && code === "invalid_qr") return { kind: "invalid_qr" };
  if (status === 429) return { kind: "rate_limited", retryAfterSeconds: retryAfterOf(retryAfter, body) };
  if (status >= 500) return { kind: "unavailable" };
  throw new StaffApiError("invalid_response", status);
}

export interface MemberApi {
  lookupQr(token: string, signal: AbortSignal): Promise<LookupOutcome>;
  /** The reviewed v1 redeem client on the staff transport. */
  redeem: Pick<ClubService, "redeemBenefit">;
}

export function createMemberApi(api: StaffApi, restBase: string, timeoutMs = LOOKUP_TIMEOUT_MS): MemberApi {
  const base = restBase.replace(/\/+$/, "");
  return {
    async lookupQr(token, signal) {
      const scanned = token.trim();
      // Nothing that cannot be a Club QR leaves the tablet as a "scan"; the server would refuse it anyway.
      if (scanned === "" || scanned.length > MAX_SCAN_LENGTH) return { kind: "invalid_qr" };
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), timeoutMs);
      const onAbort = () => controller.abort();
      if (signal.aborted) controller.abort();
      else signal.addEventListener("abort", onAbort, { once: true });
      let status: number;
      let body: unknown = null;
      let retryAfter: string | null;
      try {
        const res = await api.staffFetch(`${base}/members/lookup`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ method: "qr", qr_token: scanned }),
          signal: controller.signal,
        });
        status = res.status;
        retryAfter = res.headers.get("Retry-After");
        const text = await res.text();
        try {
          body = text === "" ? null : (JSON.parse(text) as unknown);
        } catch {
          body = null;
        }
      } catch (err) {
        if (err instanceof StaffApiError) throw err;
        throw new StaffApiError(signal.aborted ? "aborted" : controller.signal.aborted ? "timeout" : "network");
      } finally {
        clearTimeout(timer);
        signal.removeEventListener("abort", onAbort);
      }
      return mapLookupReply(status, body, retryAfter);
    },
    redeem: createRestClubService({ baseUrl: base, fetchImpl: api.staffFetch }),
  };
}
