/**
 * ============================================================================
 * NOT ACTIVE — this file is not imported anywhere in the running app.
 * ============================================================================
 *
 * This is a skeleton for the REST-backed `ClubService` implementation that
 * will eventually replace `mockClubService` (see clubService.ts) once the
 * WordPress plugin described in docs/evangelou-club-api.md exists. It exists
 * purely so a future implementer doesn't have to guess the wire shapes or
 * where the camelCase/snake_case boundary sits — it makes no network calls
 * today and requires no environment variables.
 *
 * The live demo continues to use `mockClubService`. To go live later:
 *   1. Finish this file (fill in real error handling, auth, etc).
 *   2. In clubService.ts, change `export const clubService = mockClubService`
 *      to `export const clubService = restClubService`.
 *   3. Nothing else changes — every screen already talks to `clubService`
 *      through the same `ClubService` interface.
 *
 * Full contract: docs/evangelou-club-api.md
 */

import type {
  ApiErrorCode,
  BenefitState,
  BenefitType,
  BenefitUnavailableReason,
  ClubMember,
  MemberBenefitStatus,
  MembershipStatus,
  MemberLookup,
} from "./types";
import { ClubApiError } from "./types";
import type { ClubService } from "./clubService";

// ---------------------------------------------------------------------------
// Wire DTOs — these match the REST JSON exactly (snake_case), so a mapper
// below is the ONLY place that ever sees these shapes. Nothing outside this
// file should import from here.
// ---------------------------------------------------------------------------

interface MemberDto {
  member_id: string;
  display_name: string;
  phone_masked: string;
}

interface MembershipDto {
  status: MembershipStatus;
  started_at: string | null;
  valid_until: string | null;
}

interface BenefitStatusDto {
  state: BenefitState;
  business_date: string;
  redeemed_at: string | null;
  reason?: BenefitUnavailableReason;
}

interface LookupResponseDto {
  member: MemberDto;
  membership: MembershipDto;
  benefits: {
    free_coffee: BenefitStatusDto;
  };
}

interface RedeemResponseDto {
  member_id: string;
  benefit_type: BenefitType;
  state: BenefitState;
  business_date: string;
  redeemed_at: string | null;
}

interface ApiErrorResponseDto {
  error: {
    code: ApiErrorCode;
    message: string;
    details?: Record<string, unknown>;
  };
}

// ---------------------------------------------------------------------------
// DTO -> domain mappers (snake_case wire shape -> camelCase domain type)
// ---------------------------------------------------------------------------

function mapMember(member: MemberDto, membership: MembershipDto): ClubMember {
  return {
    id: member.member_id,
    name: member.display_name,
    phoneMasked: member.phone_masked,
    status: membership.status,
    validUntil: membership.valid_until,
  };
}

function mapBenefit(dto: BenefitStatusDto): MemberBenefitStatus {
  return {
    state: dto.state,
    businessDate: dto.business_date,
    redeemedAt: dto.redeemed_at,
    reason: dto.reason,
  };
}

function mapLookupResponse(dto: LookupResponseDto): MemberLookup {
  return { member: mapMember(dto.member, dto.membership), benefit: mapBenefit(dto.benefits.free_coffee) };
}

function mapRedeemResponse(dto: RedeemResponseDto): MemberBenefitStatus {
  return { state: dto.state, businessDate: dto.business_date, redeemedAt: dto.redeemed_at };
}

// ---------------------------------------------------------------------------
// Transport
// ---------------------------------------------------------------------------

/**
 * `evangelou-club/v1` lives on the same WordPress install as the rest of
 * the site's REST API. No secret ships in this bundle: auth is a staff
 * session (cookie) established by whatever login flow is built later, sent
 * automatically via `credentials: "include"` — never a static Application
 * Password or API key baked into frontend JS. See docs §15.
 */
const API_BASE = "/wp-json/evangelou-club/v1";

async function apiFetch<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, {
    ...init,
    credentials: "include",
    headers: { "Content-Type": "application/json", ...init?.headers },
  });

  if (!res.ok) {
    const body = (await res.json().catch(() => null)) as ApiErrorResponseDto | null;
    if (body?.error) throw new ClubApiError(body.error.code, body.error.message, body.error.details);
    // Transport-level failure (network down, non-JSON 5xx, etc) — never
    // show this raw to a cashier; docs §12/16 want a generic message here.
    throw new ClubApiError("server_error", "Δεν ήταν δυνατός ο έλεγχος του μέλους. Δοκιμάστε ξανά.");
  }

  return res.json() as Promise<T>;
}

/** Generates a v4 UUID for `redeemBenefit`'s idempotency key (docs §7).
 * `crypto.randomUUID` is available in every browser Vercel targets. */
function newRequestId(): string {
  return crypto.randomUUID();
}

// ---------------------------------------------------------------------------
// ClubService implementation
// ---------------------------------------------------------------------------

export const restClubService: ClubService = {
  async findMemberByPhone(phone) {
    try {
      const dto = await apiFetch<LookupResponseDto>("/members/lookup", {
        method: "POST",
        body: JSON.stringify({ method: "phone", phone }),
      });
      return mapLookupResponse(dto);
    } catch (err) {
      // member_not_found is a normal, expected outcome — the mock returns
      // {member: null} for it (docs §11 explains why REST differs: it uses
      // a real 404), so this adapter absorbs that difference right here,
      // keeping every screen's null-check unchanged either way.
      if (err instanceof ClubApiError && err.code === "member_not_found") {
        return { member: null, benefit: null };
      }
      throw err;
    }
  },

  async findMemberByQrToken(qrToken) {
    try {
      const dto = await apiFetch<LookupResponseDto>("/members/lookup", {
        method: "POST",
        body: JSON.stringify({ method: "qr", qr_token: qrToken }),
      });
      return mapLookupResponse(dto);
    } catch (err) {
      if (err instanceof ClubApiError && err.code === "member_not_found") {
        return { member: null, benefit: null };
      }
      throw err;
    }
  },

  async getMemberStatus(_memberId) {
    // No dedicated endpoint in v1 — re-running a lookup is what refreshes
    // benefit status in practice, so this is intentionally unused by the
    // current screens. Kept in the interface for forward compatibility
    // (see clubService.ts's ClubService doc comment).
    throw new ClubApiError("invalid_request", "Not implemented — re-run a lookup instead.");
  },

  async redeemBenefit(memberId, benefitType) {
    const dto = await apiFetch<RedeemResponseDto>(`/members/${memberId}/benefits/${benefitType}/redeem`, {
      method: "POST",
      body: JSON.stringify({ request_id: newRequestId() }),
    });
    return mapRedeemResponse(dto);
    // benefit_already_redeemed and membership_inactive surface as thrown
    // ClubApiError (via apiFetch above) exactly like the mock — see
    // ClubMemberResult.tsx's confirmRedeem for how the UI already handles
    // the former by syncing to the "used" state instead of showing an error.
  },
};
