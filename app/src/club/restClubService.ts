/**
 * ============================================================================
 * NOT ACTIVE — `clubService` (clubService.ts) still points at the mock.
 * ============================================================================
 *
 * REST-backed ClubService for the `evangelou-club/v1` contract
 * (docs/evangelou-club-api.md). This file is the ONLY place that sees
 * snake_case wire JSON. Every response is validated before it is mapped to
 * camelCase domain types; anything malformed becomes `invalid_response`.
 *
 * Deployment blockers (do NOT enable until resolved — docs §3):
 * - API_BASE is a relative /wp-json path, which the Vercel deployment
 *   rewrites to index.html. Same-origin hosting / routing is undecided (D1).
 * - WordPress cookie auth needs an X-WP-Nonce; staff auth does not exist yet.
 * No secret of any kind is, or may ever be, embedded here.
 */

import { CLUB_ERROR_MESSAGES, clubError } from "./errors";
import type { ClubService } from "./clubService";
import { isValidRequestId } from "./requestId";
import {
  API_ERROR_CODES,
  ClubApiError,
  MEMBERSHIP_STATUSES,
  type ApiErrorCode,
  type BenefitState,
  type ClubErrorDetails,
  type CoffeeOption,
  type MemberBenefitStatus,
  type MemberLookup,
  type MembershipStatus,
  type RedeemBenefitRequest,
  type RedemptionOutcome,
} from "./types";

export const DEFAULT_API_BASE = "/wp-json/evangelou-club/v1";
const DEFAULT_TIMEOUT_MS = 15_000;

// ---------------------------------------------------------------------------
// Wire-format validation helpers
// ---------------------------------------------------------------------------

type Json = Record<string, unknown>;

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
/** ISO 8601 / RFC 3339 instant with an EXPLICIT offset (Z or ±hh:mm). */
const INSTANT_RE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/;
const BENEFIT_STATES: readonly BenefitState[] = ["available", "used", "unavailable"];

function isObject(v: unknown): v is Json {
  return typeof v === "object" && v !== null && !Array.isArray(v);
}

export function isBusinessDate(v: unknown): v is string {
  return typeof v === "string" && DATE_RE.test(v);
}

/** Naive timestamps (no offset) are rejected: they are ambiguous across DST. */
export function isInstant(v: unknown): v is string {
  return typeof v === "string" && INSTANT_RE.test(v) && !Number.isNaN(Date.parse(v));
}

function isMembershipStatus(v: unknown): v is MembershipStatus {
  return typeof v === "string" && (MEMBERSHIP_STATUSES as readonly string[]).includes(v);
}

function isApiErrorCode(v: unknown): v is ApiErrorCode {
  return typeof v === "string" && (API_ERROR_CODES as readonly string[]).includes(v);
}

function invalid(): ClubApiError {
  return clubError("invalid_response");
}

// ---------------------------------------------------------------------------
// DTO -> domain mappers (exported for tests)
// ---------------------------------------------------------------------------

function mapBenefit(raw: unknown): MemberBenefitStatus {
  if (!isObject(raw)) throw invalid();
  const { state, business_date, redeemed_at, reason, coffee_code } = raw;
  if (typeof state !== "string" || !(BENEFIT_STATES as readonly string[]).includes(state)) throw invalid();
  if (!isBusinessDate(business_date)) throw invalid();
  if (redeemed_at !== null && !isInstant(redeemed_at)) throw invalid();
  if (reason !== undefined && reason !== "membership_inactive") throw invalid();
  if (coffee_code !== undefined && coffee_code !== null && typeof coffee_code !== "string") throw invalid();
  return {
    state: state as BenefitState,
    businessDate: business_date,
    redeemedAt: redeemed_at,
    ...(reason ? { reason: "membership_inactive" as const } : {}),
    coffeeCode: typeof coffee_code === "string" ? coffee_code : null,
  };
}

export function mapLookupResponse(raw: unknown): MemberLookup {
  if (!isObject(raw) || !isObject(raw.member) || !isObject(raw.membership) || !isObject(raw.benefits)) throw invalid();
  const { member_id, display_name, phone_masked } = raw.member;
  const { status, started_at, valid_until } = raw.membership;
  if (typeof member_id !== "string" || member_id === "") throw invalid();
  if (typeof display_name !== "string" || typeof phone_masked !== "string") throw invalid();
  if (!isMembershipStatus(status)) throw invalid();
  if (started_at !== undefined && started_at !== null && !isInstant(started_at)) throw invalid();
  if (valid_until !== null && !isInstant(valid_until)) throw invalid();
  return {
    member: { id: member_id, name: display_name, phoneMasked: phone_masked, status, validUntil: valid_until },
    benefit: mapBenefit(raw.benefits.free_coffee),
  };
}

/** Validates a redeem success against the request that was sent. */
export function mapRedeemResponse(raw: unknown, request: RedeemBenefitRequest): RedemptionOutcome {
  if (!isObject(raw)) throw invalid();
  const { member_id, benefit_type, state, business_date, redeemed_at, coffee_code, request_id, replayed } = raw;
  if (member_id !== request.memberId || benefit_type !== request.benefitType || request_id !== request.requestId) {
    throw invalid();
  }
  if (state !== "used" || !isBusinessDate(business_date) || !isInstant(redeemed_at)) throw invalid();
  if (typeof coffee_code !== "string" || typeof replayed !== "boolean") throw invalid();
  // On a replay the server returns the ORIGINAL redemption, whose coffee is
  // by definition the one this request carried (the fingerprint matched).
  if (coffee_code !== request.coffeeCode) throw invalid();
  return {
    benefit: { state: "used", businessDate: business_date, redeemedAt: redeemed_at, coffeeCode: coffee_code },
    requestId: request_id,
    replayed,
  };
}

export function mapCoffeeOptions(raw: unknown): CoffeeOption[] {
  if (!isObject(raw) || !Array.isArray(raw.coffee_options)) throw invalid();
  return raw.coffee_options.map((o) => {
    if (!isObject(o) || typeof o.code !== "string" || typeof o.label !== "string" || o.code === "" || o.label === "") {
      throw invalid();
    }
    return { code: o.code, label: o.label };
  });
}

/** snake_case wire details -> validated camelCase. Unknown/malformed fields are dropped. */
export function mapErrorDetails(raw: unknown): ClubErrorDetails | undefined {
  if (!isObject(raw)) return undefined;
  const details: ClubErrorDetails = {};
  if (isBusinessDate(raw.business_date)) details.businessDate = raw.business_date;
  if (isInstant(raw.redeemed_at)) details.redeemedAt = raw.redeemed_at;
  if (typeof raw.coffee_code === "string" && raw.coffee_code !== "") details.coffeeCode = raw.coffee_code;
  if (isMembershipStatus(raw.status)) details.status = raw.status;
  const retry = raw.retry_after_seconds;
  if (typeof retry === "number" && Number.isInteger(retry) && retry >= 0) details.retryAfterSeconds = retry;
  return Object.keys(details).length > 0 ? details : undefined;
}

/** HTTP status each envelope code must arrive with (§8). */
const EXPECTED_STATUS: Record<ApiErrorCode, (status: number) => boolean> = {
  invalid_request: (s) => s === 400,
  invalid_phone: (s) => s === 400,
  invalid_qr: (s) => s === 400,
  invalid_coffee: (s) => s === 400,
  unauthorized: (s) => s === 401,
  forbidden: (s) => s === 403,
  member_not_found: (s) => s === 404,
  membership_inactive: (s) => s === 409,
  benefit_already_redeemed: (s) => s === 409,
  benefit_not_available: (s) => s === 409,
  idempotency_key_reused: (s) => s === 409,
  rate_limited: (s) => s === 429,
  server_error: (s) => s >= 500,
};

/**
 * Turns a non-2xx response into a ClubApiError. The envelope's code is used
 * only when it is known AND consistent with the HTTP status; otherwise the
 * status alone decides. In particular a 404/409 WITHOUT a valid envelope
 * (e.g. a proxy or missing route) is never mistaken for "member not found"
 * or "already redeemed". Server message text is never shown.
 */
export function mapErrorResponse(status: number, bodyText: string, retryAfterHeader: string | null): ClubApiError {
  let body: unknown = null;
  try {
    body = bodyText ? JSON.parse(bodyText) : null;
  } catch {
    body = null;
  }
  const envelope = isObject(body) && isObject(body.error) ? body.error : null;

  let details = envelope ? mapErrorDetails(envelope.details) : undefined;
  if (status === 429 && details?.retryAfterSeconds === undefined && retryAfterHeader && /^\d+$/.test(retryAfterHeader)) {
    details = { ...details, retryAfterSeconds: Number(retryAfterHeader) };
  }

  if (envelope && isApiErrorCode(envelope.code) && EXPECTED_STATUS[envelope.code](status)) {
    return new ClubApiError(envelope.code, CLUB_ERROR_MESSAGES[envelope.code], details, status);
  }
  if (status === 401) return clubError("unauthorized", undefined, status);
  if (status === 403) return clubError("forbidden", undefined, status);
  if (status === 429) return clubError("rate_limited", details, status);
  if (status >= 500) return clubError("server_error", undefined, status);
  return clubError("invalid_response", undefined, status);
}

// ---------------------------------------------------------------------------
// Transport
// ---------------------------------------------------------------------------

export interface RestClubServiceOptions {
  baseUrl?: string;
  /** Injected for tests; defaults to the global fetch at CALL time. */
  fetchImpl?: typeof fetch;
  timeoutMs?: number;
}

export function createRestClubService(options: RestClubServiceOptions = {}): ClubService {
  const baseUrl = (options.baseUrl ?? DEFAULT_API_BASE).replace(/\/+$/, "");
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;
  const doFetch: typeof fetch = options.fetchImpl ?? ((input, init) => globalThis.fetch(input, init));

  async function call(method: "GET" | "POST", path: string, body?: unknown): Promise<unknown> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    let res: Response;
    let text: string;
    try {
      res = await doFetch(`${baseUrl}${path}`, {
        method,
        credentials: "include",
        cache: "no-store",
        redirect: "error",
        signal: controller.signal,
        headers: { Accept: "application/json", ...(body === undefined ? {} : { "Content-Type": "application/json" }) },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      });
      text = await res.text();
    } catch {
      // Offline, DNS, CORS, abort/timeout, body stream cut: the server may
      // or may not have processed the request.
      throw clubError("network_error");
    } finally {
      clearTimeout(timer);
    }

    if (!res.ok) throw mapErrorResponse(res.status, text, res.headers.get("Retry-After"));
    try {
      return JSON.parse(text) as unknown;
    } catch {
      throw invalid();
    }
  }

  async function lookup(body: unknown): Promise<MemberLookup> {
    try {
      return mapLookupResponse(await call("POST", "/members/lookup", body));
    } catch (err) {
      // A real 404 member_not_found is a normal outcome; the screens expect
      // `{ member: null }` exactly like the mock returns.
      if (err instanceof ClubApiError && err.code === "member_not_found") return { member: null, benefit: null };
      throw err;
    }
  }

  return {
    findMemberByPhone(phone) {
      return lookup({ method: "phone", phone });
    },

    findMemberByQrToken(qrToken) {
      return lookup({ method: "qr", qr_token: qrToken });
    },

    async getMemberStatus() {
      // No dedicated endpoint in v1 — re-running a lookup refreshes status.
      throw clubError("invalid_request");
    },

    async getCoffeeOptions() {
      return mapCoffeeOptions(await call("GET", "/coffee-options"));
    },

    async redeemBenefit(request) {
      if (!isValidRequestId(request.requestId)) throw clubError("invalid_request");
      const path = `/members/${encodeURIComponent(request.memberId)}/benefits/${encodeURIComponent(request.benefitType)}/redeem`;
      const raw = await call("POST", path, { request_id: request.requestId, coffee_code: request.coffeeCode });
      return mapRedeemResponse(raw, request);
    },
  };
}

/** Pre-built instance for when the switch is eventually flipped. Constructing
 * it performs no network activity. */
export const restClubService: ClubService = createRestClubService();
