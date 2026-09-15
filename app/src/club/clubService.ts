import { maskPhone, normalizeGreekPhone, todayKey } from "./format";
import { DEMO_QR_TOKEN, SEEDED_USED_MEMBER_ID, SEEDED_USED_TIME, mockMembers, type MockMemberRecord } from "./mockMembers";
import { ClubApiError, type BenefitType, type ClubMember, type MemberBenefitStatus, type MemberLookup } from "./types";

/**
 * The contract the UI talks to. Production will swap `mockClubService` for
 * an implementation that calls a custom Evangelou Club REST API (which in
 * turn talks to WordPress/Paid Memberships Pro/FluentCRM/WooCommerce) — the
 * screens only ever import `clubService` below, never the mock directly, so
 * that swap should not require touching any component. See
 * docs/evangelou-club-api.md for the exact REST contract this mirrors, and
 * restClubService.ts for what the future implementation looks like.
 */
export interface ClubService {
  findMemberByPhone(phone: string): Promise<MemberLookup>;
  findMemberByQrToken(token: string): Promise<MemberLookup>;
  getMemberStatus(memberId: string): Promise<MemberBenefitStatus | null>;
  redeemBenefit(memberId: string, benefitType: BenefitType): Promise<MemberBenefitStatus>;
}

const REDEMPTIONS_KEY = "evaggelou-club-redemptions";

interface RedemptionRecord {
  dateKey: string;
  redeemedAtISO: string;
}

type RedemptionStore = Partial<Record<string, RedemptionRecord>>;

function readRedemptions(): RedemptionStore {
  try {
    const raw = localStorage.getItem(REDEMPTIONS_KEY);
    return raw ? (JSON.parse(raw) as RedemptionStore) : {};
  } catch {
    return {};
  }
}

function writeRedemptions(store: RedemptionStore): void {
  try {
    localStorage.setItem(REDEMPTIONS_KEY, JSON.stringify(store));
  } catch {
    // localStorage unavailable (private mode etc.) — redemption just won't
    // persist across a reload, which is an acceptable demo fallback. This
    // whole read/write pair disappears in production: the WordPress plugin
    // owns a real redemptions table instead (docs §20), enforced with a
    // unique (member_ref, benefit_type, business_date) constraint so two
    // tills redeeming at once can't both succeed.
  }
}

/** Member 2 always starts each new calendar day already having used today's
 * coffee, so the demo can show that state without a live redemption first. */
function ensureSeedRedemption(): void {
  const store = readRedemptions();
  const existing = store[SEEDED_USED_MEMBER_ID];
  if (existing && existing.dateKey === todayKey()) return;

  const [hours, minutes] = SEEDED_USED_TIME.split(":").map(Number);
  const seededAt = new Date();
  seededAt.setHours(hours, minutes, 0, 0);

  store[SEEDED_USED_MEMBER_ID] = { dateKey: todayKey(), redeemedAtISO: seededAt.toISOString() };
  writeRedemptions(store);
}

function benefitStatusFor(member: MockMemberRecord): MemberBenefitStatus {
  const businessDate = todayKey();
  if (member.status !== "active") {
    return { state: "unavailable", businessDate, redeemedAt: null, reason: "membership_inactive" };
  }

  const record = readRedemptions()[member.id];
  if (record && record.dateKey === businessDate) {
    return { state: "used", businessDate, redeemedAt: record.redeemedAtISO };
  }
  return { state: "available", businessDate, redeemedAt: null };
}

/** Simulates realistic network latency so the demo shows its loading states. */
function delay<T>(value: T, ms = 350): Promise<T> {
  return new Promise((resolve) => setTimeout(() => resolve(value), ms));
}

/** What `POST /members/lookup` would return as `member` — masks the phone
 * and drops the QR token, exactly like the production API would (see
 * docs/evangelou-club-api.md §2). This is the one place a raw
 * `MockMemberRecord` is allowed to become a public `ClubMember`. */
function toClubMember(record: MockMemberRecord): ClubMember {
  return {
    id: record.id,
    name: record.name,
    phoneMasked: maskPhone(record.phone),
    status: record.status,
    validUntil: record.validUntil,
  };
}

function lookup(record: MockMemberRecord | undefined): MemberLookup {
  if (!record) return { member: null, benefit: null };
  return { member: toClubMember(record), benefit: benefitStatusFor(record) };
}

export const mockClubService: ClubService = {
  async findMemberByPhone(phone) {
    ensureSeedRedemption();
    const normalized = normalizeGreekPhone(phone);
    const record = mockMembers.find((m) => m.phone === normalized);
    return delay(lookup(record));
  },

  async findMemberByQrToken(token) {
    ensureSeedRedemption();
    const record = mockMembers.find((m) => m.qrToken === token);
    return delay(lookup(record));
  },

  async getMemberStatus(memberId) {
    ensureSeedRedemption();
    const record = mockMembers.find((m) => m.id === memberId);
    return delay(record ? benefitStatusFor(record) : null);
  },

  async redeemBenefit(memberId, _benefitType) {
    const record = mockMembers.find((m) => m.id === memberId);
    if (!record) throw new ClubApiError("member_not_found", "Το μέλος δεν βρέθηκε.");
    if (record.status !== "active") {
      throw new ClubApiError("membership_inactive", "Η συνδρομή δεν είναι ενεργή.");
    }

    const store = readRedemptions();
    const existing = store[memberId];
    const businessDate = todayKey();
    if (existing && existing.dateKey === businessDate) {
      // Someone else (another till, or a retried request) already redeemed
      // this today — the same domain error production returns on a race
      // between two cash registers (docs §9). Carries the existing
      // redemption's details so the caller can sync the UI to "used"
      // instead of treating this as an unknown failure.
      throw new ClubApiError("benefit_already_redeemed", "Η σημερινή παροχή έχει ήδη χρησιμοποιηθεί.", {
        businessDate,
        redeemedAt: existing.redeemedAtISO,
      });
    }

    const redeemedAtISO = new Date().toISOString();
    store[memberId] = { dateKey: businessDate, redeemedAtISO };
    writeRedemptions(store);
    return delay({ state: "used", businessDate, redeemedAt: redeemedAtISO });
  },
};

export const clubService: ClubService = mockClubService;

export { DEMO_QR_TOKEN };

/** Presentation-only aid for the sales demo — a cheat sheet the presenter
 * can open on the lookup screen instead of memorizing numbers. Safe to
 * delete along with its one call site in ClubHome.tsx once this is no
 * longer a sales demo. */
export interface DemoPhoneHint {
  phone: string;
  label: string;
}

export const DEMO_PHONE_HINTS: DemoPhoneHint[] = [
  { phone: mockMembers[0].phone, label: "Ενεργό · καφές διαθέσιμος" },
  { phone: mockMembers[1].phone, label: "Ενεργό · καφές χρησιμοποιημένος" },
  { phone: mockMembers[2].phone, label: "Ληγμένη συνδρομή" },
];

/** What the UI shows for any lookup failure — mock mode never actually
 * throws here, but a future REST-backed service will, and the cashier
 * should never see a raw technical error. */
export const CLUB_LOOKUP_ERROR_MESSAGE = "Δεν ήταν δυνατός ο έλεγχος του μέλους. Δοκιμάστε ξανά.";
