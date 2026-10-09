/**
 * Domain types the Club screens consume, in idiomatic camelCase. These are
 * shaped to match — but are not identical to — the `evangelou-club/v1` REST
 * API described in docs/evangelou-club-api.md. restClubService.ts is the ONLY
 * place that sees snake_case wire JSON; it validates it and maps it onto
 * exactly these types. Screens never see raw REST field names.
 */

/**
 * Backend-authoritative membership status (Paid Memberships Pro is the
 * source of truth in production). Only "active" may redeem a benefit.
 *
 * This is deliberately richer than what the cashier needs to *see* —
 * "cashier UI state" is a presentation-level simplification of this value
 * (see `membershipBadgeLabel` in format.ts), not a separate type.
 */
export type MembershipStatus = "active" | "expired" | "cancelled" | "inactive";

export const MEMBERSHIP_STATUSES: readonly MembershipStatus[] = ["active", "expired", "cancelled", "inactive"];

/**
 * A club member as resolved by `POST /members/lookup` (that endpoint's
 * `member` + `membership` response objects, flattened for the UI).
 * `phoneMasked` is exactly what the API returns — the full phone number
 * never reaches the browser.
 */
export interface ClubMember {
  /** Opaque, stable public member id. Never parse it. */
  id: string;
  name: string;
  phoneMasked: string;
  status: MembershipStatus;
  /** Null when the membership has no end date to show — always handle it. */
  validUntil: string | null;
}

/** Mirrors the REST response's nested `membership` object one-to-one. */
export interface MembershipInfo {
  status: MembershipStatus;
  startedAt: string | null;
  validUntil: string | null;
}

/** Every API shape is keyed by benefit type so a second benefit later
 * doesn't change the contract's shape. */
export type BenefitType = "free_coffee";

export type BenefitState = "available" | "used" | "unavailable";

/** Closed set of reasons a benefit can be unavailable. */
export type BenefitUnavailableReason = "membership_inactive";

/**
 * Today's status for one benefit type. `businessDate` is the Europe/Athens
 * calendar day (YYYY-MM-DD). `redeemedAt` is an ISO 8601 instant WITH an
 * explicit offset, or null when unknown/not used — the UI must never invent
 * one.
 */
export interface MemberBenefitStatus {
  state: BenefitState;
  businessDate: string;
  redeemedAt: string | null;
  reason?: BenefitUnavailableReason;
  /** Coffee recorded with the redemption, when the server supplied it. */
  coffeeCode?: string | null;
}

export interface MemberLookup {
  member: ClubMember | null;
  benefit: MemberBenefitStatus | null;
}

/**
 * One selectable coffee. The list comes from the service (mock: DEMO data;
 * production: server-provided allowlist, owner decision D3). The backend
 * remains authoritative and rejects unknown codes with `invalid_coffee`.
 */
export interface CoffeeOption {
  code: string;
  label: string;
}

/**
 * One redemption attempt. `requestId` is OWNED BY THE CALLER: it identifies
 * one logical redemption intent and must be reused, unchanged, for every
 * retry of that intent (see redemptionIntent.ts).
 */
export interface RedeemBenefitRequest {
  memberId: string;
  benefitType: BenefitType;
  coffeeCode: string;
  requestId: string;
}

/** Successful redemption. `replayed` is true when the server recognised a
 * retry of an already-committed request and returned the original result. */
export interface RedemptionOutcome {
  benefit: MemberBenefitStatus;
  requestId: string;
  replayed: boolean;
}

/** `error.code` values the API can return — docs/evangelou-club-api.md §8. */
export type ApiErrorCode =
  | "invalid_request"
  | "invalid_phone"
  | "invalid_qr"
  | "invalid_coffee"
  | "member_not_found"
  | "membership_inactive"
  | "benefit_already_redeemed"
  | "benefit_not_available"
  | "idempotency_key_reused"
  | "unauthorized"
  | "forbidden"
  | "rate_limited"
  | "server_error";

export const API_ERROR_CODES: readonly ApiErrorCode[] = [
  "invalid_request",
  "invalid_phone",
  "invalid_qr",
  "invalid_coffee",
  "member_not_found",
  "membership_inactive",
  "benefit_already_redeemed",
  "benefit_not_available",
  "idempotency_key_reused",
  "unauthorized",
  "forbidden",
  "rate_limited",
  "server_error",
];

/**
 * Client-side failures that never come from the server's error envelope:
 * - network_error: the request may or may not have reached the server.
 * - invalid_response: the server answered with something that is not a
 *   valid v1 response (non-JSON, wrong shape). The outcome is UNKNOWN.
 */
export type ClientErrorCode = "network_error" | "invalid_response";

export type ClubErrorCode = ApiErrorCode | ClientErrorCode;

/** Error details, already mapped to camelCase and validated. Unknown or
 * malformed wire fields are dropped, never passed through. */
export interface ClubErrorDetails {
  businessDate?: string;
  redeemedAt?: string;
  coffeeCode?: string;
  status?: MembershipStatus;
  retryAfterSeconds?: number;
}

/** The API's one error envelope shape, in domain form. */
export interface ApiError {
  code: ClubErrorCode;
  message: string;
  details?: ClubErrorDetails;
}

/** Thrown by clubService implementations (mock and REST) for any failure,
 * so callers branch on `.code`, never on `.message` (which is safe Greek
 * staff-facing copy chosen by the client). */
export class ClubApiError extends Error implements ApiError {
  code: ClubErrorCode;
  details?: ClubErrorDetails;
  /** HTTP status when the error came from a server response. */
  httpStatus?: number;

  constructor(code: ClubErrorCode, message: string, details?: ClubErrorDetails, httpStatus?: number) {
    super(message);
    this.name = "ClubApiError";
    this.code = code;
    this.details = details;
    this.httpStatus = httpStatus;
  }
}
