import { todayKey } from "./format";
import { DEMO_QR_TOKEN, SEEDED_USED_MEMBER_ID, SEEDED_USED_TIME, mockMembers } from "./mockMembers";
import type { BenefitType, ClubMember, MemberBenefitStatus, MemberLookup } from "./types";

/**
 * The contract the UI talks to. Production will swap `mockClubService` for
 * an implementation that calls a custom Evangelou Club REST API (which in
 * turn talks to WordPress/Paid Memberships Pro/FluentCRM/WooCommerce) — the
 * screens only ever import `clubService` below, never the mock directly, so
 * that swap should not require touching any component.
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
    // persist across a reload, which is an acceptable demo fallback.
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

function benefitStatusFor(member: ClubMember): MemberBenefitStatus {
  if (member.status !== "active") return { state: "unavailable" };

  const record = readRedemptions()[member.id];
  if (record && record.dateKey === todayKey()) {
    return { state: "used", redeemedAt: record.redeemedAtISO };
  }
  return { state: "available" };
}

/** Simulates realistic network latency so the demo shows its loading states. */
function delay<T>(value: T, ms = 350): Promise<T> {
  return new Promise((resolve) => setTimeout(() => resolve(value), ms));
}

function normalizePhone(input: string): string {
  let digits = input.replace(/\D/g, "");
  if (digits.length > 10 && digits.startsWith("30")) digits = digits.slice(2);
  return digits;
}

function lookup(member: ClubMember | undefined): MemberLookup {
  if (!member) return { member: null, benefit: null };
  return { member, benefit: benefitStatusFor(member) };
}

export const mockClubService: ClubService = {
  async findMemberByPhone(phone) {
    ensureSeedRedemption();
    const normalized = normalizePhone(phone);
    const member = mockMembers.find((m) => m.phone === normalized);
    return delay(lookup(member));
  },

  async findMemberByQrToken(token) {
    ensureSeedRedemption();
    const member = mockMembers.find((m) => m.qrToken === token);
    return delay(lookup(member));
  },

  async getMemberStatus(memberId) {
    ensureSeedRedemption();
    const member = mockMembers.find((m) => m.id === memberId);
    return delay(member ? benefitStatusFor(member) : null);
  },

  async redeemBenefit(memberId, _benefitType) {
    const member = mockMembers.find((m) => m.id === memberId);
    if (!member) throw new Error("Το μέλος δεν βρέθηκε.");
    if (member.status !== "active") throw new Error("Η συνδρομή δεν είναι ενεργή.");

    const store = readRedemptions();
    const existing = store[memberId];
    if (existing && existing.dateKey === todayKey()) {
      return delay({ state: "used", redeemedAt: existing.redeemedAtISO });
    }

    const redeemedAtISO = new Date().toISOString();
    store[memberId] = { dateKey: todayKey(), redeemedAtISO };
    writeRedemptions(store);
    return delay({ state: "used", redeemedAt: redeemedAtISO });
  },
};

export const clubService: ClubService = mockClubService;

export { DEMO_QR_TOKEN };
