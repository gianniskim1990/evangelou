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
/** EXACTLY EVC_Qr_Token::is_valid_format() (includes/class-evc-qr-token.php: /^evc_[0-9a-f]{48}$/D). */
export const QR_TOKEN_RE = /^evc_[0-9a-f]{48}$/;

/*
 * A USB / Bluetooth hand scanner "types" the code as key presses, so the
 * tablet's keyboard layout decides which characters arrive: with the Greek
 * layout "evc_0a…" arrives as "εωψ_0α…", with Caps Lock as "EVC_0A…" or
 * "ΕΩΨ_0Α…". Each Greek letter is mapped back to the Latin key it sits on
 * (standard Greek layout), then everything is lower-cased.
 */
const GREEK_LAYOUT: Record<string, string> = {
  ";": "q", ς: "w", ε: "e", ρ: "r", τ: "t", υ: "y", θ: "u", ι: "i", ο: "o", π: "p",
  α: "a", σ: "s", δ: "d", φ: "f", γ: "g", η: "h", ξ: "j", κ: "k", λ: "l",
  ζ: "z", χ: "x", ψ: "c", ω: "v", β: "b", ν: "n", μ: "m",
};

/** The scanned text as the Club token it was meant to be (layout fixed, lower case, trimmed). */
export function normalizeScannedCode(raw: string): string {
  let out = "";
  for (const ch of raw.trim().normalize("NFC")) {
    const lower = ch.toLocaleLowerCase("el-GR");
    out += GREEK_LAYOUT[lower] ?? lower.toLowerCase();
  }
  return out;
}

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

/**
 * Retry-After header (seconds), else details.retry_after_seconds, else 300 when neither is a valid
 * number; bounded to 0..3600. An explicit 0 means "now", never the 300 s default.
 */
export function retryAfterOf(header: string | null, body: unknown): number {
  let seconds: number | null = null;
  if (header && /^\d{1,6}$/.test(header.trim())) seconds = Number(header.trim());
  else if (isRecord(body) && isRecord(body.error) && isRecord(body.error.details)) {
    const d = body.error.details.retry_after_seconds;
    if (typeof d === "number" && Number.isInteger(d) && d >= 0) seconds = d;
  }
  if (seconds === null) return DEFAULT_RETRY_AFTER_SECONDS;
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
      // Nothing that is not exactly a Club token leaves the tablet: a bad scan (wrong layout leftovers,
      // another QR, a half read) is answered here, so it never uses up the ~10 failed scans / 5 min (D9).
      const scanned = normalizeScannedCode(token);
      if (!QR_TOKEN_RE.test(scanned)) return { kind: "invalid_qr" };
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
