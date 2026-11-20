import { createRestClubService } from "../../club/restClubService";
import type { ClubService } from "../../club/clubService";
import { parseCatalogue, type Catalogue } from "../../club/preparationContract";
import { StaffApiError, type StaffApi } from "../staffApi";
import { restRouteUrl } from "../../lib/restRoute";
import { parsePreparedReply, type PreparedReply } from "./preparedReply";
import type { OrderPrefill, PreparedBody } from "./preparedOrder";

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
 *
 * Owner decision D24 (round 9): the lookup sends "history": true, and a server that knows it answers
 * ALSO "prepared" (migration 004 + the approved catalogue) and "recent_coffees" (THIS member's last 3, newest
 * first; never a staff id). An older server answers the old body: the till then works exactly as before.
 * A malformed history never blocks the coffee: the member and status are still parsed strictly and only
 * the history is dropped (recent = null). GET /coffee-catalogue and POST …/prepared-redeem are the prepared
 * flow's two requests (preparedReply.ts parses the redeem reply).
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

/** One of the member's own recent coffees, as the lookup reply carries it (D24). */
export interface RecentCoffee {
  businessDate: string;
  coffeeLabel: string;
  details: string[];
  /** The staff note of that coffee (shown to staff only). */
  note: string | null;
  /** What the current catalogue still offers of it (null: the coffee itself is not offered any more). */
  prefill: OrderPrefill | null;
  /** Something of that order is not offered any more: staff choose it again. */
  prefillPartial: boolean;
}

export interface LookupHistory {
  /** The server records the preparation (schema 4 + approved catalogue); false = coffee type only (v1). */
  prepared: boolean;
  /** null = the server sent none we could read (the scan still works). */
  recent: RecentCoffee[] | null;
}

export type LookupOutcome =
  | { kind: "found"; member: FoundMember; status: MemberStatus; history?: LookupHistory }
  | { kind: "not_found" }
  | { kind: "invalid_qr" }
  | { kind: "rate_limited"; retryAfterSeconds: number }
  | { kind: "unavailable" };

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === "object" && v !== null && !Array.isArray(v);
const keysAre = (o: Record<string, unknown>, keys: string[]) => Object.keys(o).sort().join(",") === [...keys].sort().join(",");

const DAY_RE = /^\d{4}-\d{2}-\d{2}$/;
const ID_RE = /^[a-z][a-z0-9_]{1,31}$/;
const isText = (v: unknown, max: number): v is string => typeof v === "string" && v.trim() !== "" && [...v].length <= max;

function parsePrefill(v: unknown): OrderPrefill | null | undefined {
  if (v === null) return null;
  if (!isRecord(v) || !keysAre(v, ["catalogue_version", "coffee_code", "modifiers", "quick_notes", "note"])) return undefined;
  const { catalogue_version: version, coffee_code: code, modifiers, quick_notes: quick, note } = v;
  if (typeof version !== "number" || !Number.isInteger(version) || version < 1 || typeof code !== "string" || !ID_RE.test(code)) return undefined;
  if (!Array.isArray(modifiers) || modifiers.length > 12 || !Array.isArray(quick) || quick.length > 8) return undefined;
  const mods: { group: string; option: string }[] = [];
  for (const m of modifiers) {
    if (!isRecord(m) || !keysAre(m, ["group", "option"]) || typeof m.group !== "string" || !ID_RE.test(m.group) || typeof m.option !== "string" || !ID_RE.test(m.option)) return undefined;
    mods.push({ group: m.group, option: m.option });
  }
  if (!quick.every((q) => typeof q === "string" && ID_RE.test(q))) return undefined;
  if (note !== null && !isText(note, 80)) return undefined;
  return { catalogueVersion: version, coffeeCode: code, modifiers: mods, quickNotes: quick as string[], note: note as string | null };
}

/** D24: the lookup's `recent_coffees` (at most 3); null = not readable (the history alone is dropped). */
export function parseRecentCoffees(v: unknown): RecentCoffee[] | null {
  if (!Array.isArray(v) || v.length > 3) return null;
  const out: RecentCoffee[] = [];
  for (const item of v) {
    if (!isRecord(item) || !keysAre(item, ["business_date", "coffee_label", "details", "note", "prefill", "prefill_partial"])) return null;
    const { business_date: day, coffee_label: label, details, note, prefill_partial: partial } = item;
    if (typeof day !== "string" || !DAY_RE.test(day) || !isText(label, 64) || typeof partial !== "boolean") return null;
    if (!Array.isArray(details) || details.length > 20 || !details.every((d) => isText(d, 64))) return null;
    if (note !== null && !isText(note, 80)) return null;
    const prefill = parsePrefill(item.prefill);
    if (prefill === undefined) return null;
    out.push({ businessDate: day, coffeeLabel: label, details: details as string[], note: note as string | null, prefill, prefillPartial: partial });
  }
  return out;
}

/** Strict parse of a lookup 200 body; null = not the contract. */
export function parseLookupFound(body: unknown): { member: FoundMember; status: MemberStatus; history?: LookupHistory } | null {
  const extended = isRecord(body) && keysAre(body, ["member", "status", "prepared", "recent_coffees"]);
  if (!isRecord(body) || (!keysAre(body, ["member", "status"]) && !extended) || !isRecord(body.member)) return null;
  const { member, status } = body;
  if (!keysAre(member, ["member_id", "first_name"])) return null;
  if (typeof member.member_id !== "string" || !MEMBER_ID_RE.test(member.member_id)) return null;
  const name = member.first_name;
  if (name !== null && (typeof name !== "string" || name.trim() === "" || [...name].length > 40)) return null;
  if (status !== "active" && status !== "used_today" && status !== "inactive") return null;
  const found = { member: { memberId: member.member_id, firstName: name }, status: status as MemberStatus };
  if (!extended) return found;
  if (typeof body.prepared !== "boolean") return null;
  return { ...found, history: { prepared: body.prepared, recent: parseRecentCoffees(body.recent_coffees) } };
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

/** D24: what POST …/prepared-redeem told the till (or that the server cannot record a preparation yet). */
export type PreparedRedeemResult = PreparedReply | { kind: "prepared_unavailable" };

export interface MemberApi {
  lookupQr(token: string, signal: AbortSignal): Promise<LookupOutcome>;
  /** The reviewed v1 redeem client on the staff transport. */
  redeem: Pick<ClubService, "redeemBenefit">;
  /** D24: the approved catalogue (null when it cannot be read or is not the approved one: the till uses v1). */
  catalogue(signal: AbortSignal): Promise<Catalogue | null>;
  /**
   * D24: records the prepared coffee. A reply that cannot be trusted is { kind: "unresolved" } (the intent stays
   * open: «Δοκιμή ξανά» resends the SAME request id). 401 / 403 throw (the controller ends the session).
   */
  redeemPrepared(memberId: string, body: PreparedBody, signal: AbortSignal): Promise<PreparedRedeemResult>;
}

/** A GET / POST on the staff transport, bounded; network failures throw StaffApiError (network / timeout / aborted). */
async function requestJson(api: StaffApi, url: string, init: RequestInit, signal: AbortSignal, timeoutMs: number): Promise<{ status: number; body: unknown }> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  const onAbort = () => controller.abort();
  if (signal.aborted) controller.abort();
  else signal.addEventListener("abort", onAbort, { once: true });
  try {
    const res = await api.staffFetch(url, { ...init, signal: controller.signal });
    const text = await res.text();
    let body: unknown = null;
    try {
      body = text === "" ? null : (JSON.parse(text) as unknown);
    } catch {
      body = null;
    }
    return { status: res.status, body };
  } catch (err) {
    if (err instanceof StaffApiError) throw err;
    throw new StaffApiError(signal.aborted ? "aborted" : controller.signal.aborted ? "timeout" : "network");
  } finally {
    clearTimeout(timer);
    signal.removeEventListener("abort", onAbort);
  }
}

/** The approved catalogue out of a GET /coffee-catalogue 200 body; null = anything else. */
export function parseCatalogueReply(body: unknown): Catalogue | null {
  if (!isRecord(body) || !keysAre(body, ["catalogue_version", "version_label", "catalogue"]) || typeof body.catalogue_version !== "number") return null;
  try {
    const catalogue = parseCatalogue(body.catalogue);
    return catalogue.status === "approved" && catalogue.version === body.catalogue_version ? catalogue : null;
  } catch {
    return null;
  }
}

export function createMemberApi(api: StaffApi, restBase: string, timeoutMs = LOOKUP_TIMEOUT_MS): MemberApi {
  const base = restBase.replace(/\/+$/, "");
  const lookupUrl = restRouteUrl(base, "/members/lookup");
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
        const res = await api.staffFetch(lookupUrl, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ method: "qr", qr_token: scanned, history: true }),
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
    async catalogue(signal) {
      try {
        const reply = await requestJson(api, restRouteUrl(base, "/coffee-catalogue"), { method: "GET" }, signal, timeoutMs);
        if (reply.status === 401) throw new StaffApiError("unauthorized", 401);
        if (reply.status === 403) throw new StaffApiError("forbidden", 403);
        return reply.status === 200 ? parseCatalogueReply(reply.body) : null;
      } catch (err) {
        if (err instanceof StaffApiError && (err.kind === "unauthorized" || err.kind === "forbidden")) throw err;
        return null;
      }
    },
    async redeemPrepared(memberId, body, signal) {
      if (!MEMBER_ID_RE.test(memberId)) return { kind: "unresolved" };
      let reply: { status: number; body: unknown };
      try {
        reply = await requestJson(
          api,
          restRouteUrl(base, `/members/${memberId}/benefits/free_coffee/prepared-redeem`),
          { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) },
          signal,
          timeoutMs,
        );
      } catch (err) {
        if (err instanceof StaffApiError && err.kind === "aborted") throw err;
        return { kind: "unresolved" };
      }
      if (reply.status === 401) throw new StaffApiError("unauthorized", 401);
      if (reply.status === 403) throw new StaffApiError("forbidden", 403);
      if (reply.status === 409 && envelopeCode(reply.body) === "prepared_unavailable") return { kind: "prepared_unavailable" };
      if (reply.status !== 200) return { kind: "unresolved" };
      return parsePreparedReply(reply.body, { requestId: body.request_id, memberId }) ?? { kind: "unresolved" };
    },
  };
}
