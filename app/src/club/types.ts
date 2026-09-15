/**
 * Domain types the Club screens consume, in idiomatic camelCase. These are
 * shaped to match — but are not identical to — the future
 * `evangelou-club/v1` REST API described in docs/evangelou-club-api.md.
 * A REST-backed `clubService` maps the API's snake_case JSON onto exactly
 * these types (see restClubService.ts for what that mapping looks like);
 * screens never see raw REST field names.
 */

/**
 * Backend-authoritative membership status (Paid Memberships Pro is the
 * source of truth in production). Only "active" may redeem a benefit.
 *
 * This is deliberately richer than what the cashier needs to *see* —
 * "cashier UI state" is a presentation-level simplification of this value
 * (see `membershipBadgeLabel`/`membershipDetailText` in format.ts), not a
 * separate type: today only "active" and "expired" render distinct
 * copy, "cancelled"/"inactive" both fall back to a generic "not active"
 * treatment since there's no demo scenario driving anything more specific
 * for them yet.
 */
export type MembershipStatus = "active" | "expired" | "cancelled" | "inactive";

/**
 * A club member as resolved by `POST /members/lookup` (that endpoint's
 * `member` + `membership` response objects, flattened for the UI).
 * `phoneMasked` is exactly what the API returns — the full phone number
 * never reaches the browser, so there is no client-side masking step.
 */
export interface ClubMember {
  id: string;
  name: string;
  phoneMasked: string;
  status: MembershipStatus;
  /** Null when the membership has no fixed end date (e.g. an active
   * recurring PMPro membership) — always handle this case, never assume
   * every active member has an expiry date to render. */
  validUntil: string | null;
}

/**
 * Mirrors the REST response's nested `membership` object one-to-one.
 * Used by the REST DTO/mapper (restClubService.ts) and the API docs —
 * not currently embedded in `ClubMember` itself, since every screen reads
 * status/validUntil flat off the member and restructuring that isn't
 * needed for this contract to be accurate.
 */
export interface MembershipInfo {
  status: MembershipStatus;
  startedAt: string | null;
  validUntil: string | null;
}

/** Kept to a single value for now, but every API shape below is keyed by
 * benefit type (`/benefits/{benefit_type}/redeem`, etc.) so adding a
 * second benefit later doesn't change the contract's shape. */
export type BenefitType = "free_coffee";

export type BenefitState = "available" | "used" | "unavailable";

/** Closed, documented set of reasons a benefit can be unavailable — the UI
 * must never invent a reason string itself (see docs §6). */
export type BenefitUnavailableReason = "membership_inactive";

/**
 * Today's status for one benefit type. `businessDate` and `redeemedAt`
 * are always present (never omitted) to match the REST shape exactly —
 * `redeemedAt` is `null` until the benefit is actually used. Business
 * dates are `YYYY-MM-DD` in the store's timezone (Europe/Athens in
 * production), not the viewer's local date.
 */
export interface MemberBenefitStatus {
  state: BenefitState;
  businessDate: string;
  redeemedAt: string | null;
  reason?: BenefitUnavailableReason;
}

export interface MemberLookup {
  member: ClubMember | null;
  benefit: MemberBenefitStatus | null;
}

/** The full set of `error.code` values the API can return — see
 * docs/evangelou-club-api.md §12. The UI switches on these, never on
 * `message` (which is just the Greek copy to show as-is). */
export type ApiErrorCode =
  | "invalid_request"
  | "invalid_phone"
  | "invalid_qr"
  | "member_not_found"
  | "membership_inactive"
  | "benefit_already_redeemed"
  | "benefit_not_available"
  | "unauthorized"
  | "forbidden"
  | "rate_limited"
  | "server_error";

/** The API's one error envelope shape (`{ "error": { code, message,
 * details } }`), used both to document the wire format and as the shape
 * `ClubApiError` below carries once thrown. */
export interface ApiError {
  code: ApiErrorCode;
  message: string;
  details?: Record<string, unknown>;
}

/** Thrown by clubService implementations (mock today, REST later) for any
 * domain-level failure, so callers can branch on `.code` instead of
 * parsing `.message` — e.g. `benefit_already_redeemed` should update the
 * UI to the "used" state rather than being shown as a generic error. */
export class ClubApiError extends Error implements ApiError {
  code: ApiErrorCode;
  details?: Record<string, unknown>;

  constructor(code: ApiErrorCode, message: string, details?: Record<string, unknown>) {
    super(message);
    this.name = "ClubApiError";
    this.code = code;
    this.details = details;
  }
}
