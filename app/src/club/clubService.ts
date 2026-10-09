import { clubError } from "./errors";
import { maskPhone, normalizeGreekPhone, todayKey } from "./format";
import { DEMO_COFFEE_OPTIONS } from "./mockCoffeeCatalog";
import { DEMO_QR_TOKEN, SEEDED_USED_MEMBER_ID, SEEDED_USED_TIME, mockMembers, type MockMemberRecord } from "./mockMembers";
import {
  browserStorage,
  dayKey,
  mockFingerprint,
  readMockStore,
  writeMockStore,
  type MockRedemptionRecord,
  type MockStorage,
} from "./mockRedemptionStore";
import { isValidRequestId } from "./requestId";
import type {
  ClubMember,
  CoffeeOption,
  MemberBenefitStatus,
  MemberLookup,
  RedeemBenefitRequest,
  RedemptionOutcome,
} from "./types";

/**
 * The contract the UI talks to. The screens only ever import `clubService`
 * below, never an implementation directly. See docs/evangelou-club-api.md
 * for the REST contract this mirrors, and restClubService.ts for the
 * (not yet enabled) REST implementation.
 */
export interface ClubService {
  findMemberByPhone(phone: string): Promise<MemberLookup>;
  findMemberByQrToken(token: string): Promise<MemberLookup>;
  getMemberStatus(memberId: string): Promise<MemberBenefitStatus | null>;
  /** Coffees staff may record. The server stays authoritative (invalid_coffee). */
  getCoffeeOptions(): Promise<CoffeeOption[]>;
  /**
   * Redeems one benefit. `request.requestId` is owned by the CALLER and must
   * be reused for every retry of the same intent (redemptionIntent.ts).
   * Throws ClubApiError on any failure.
   */
  redeemBenefit(request: RedeemBenefitRequest): Promise<RedemptionOutcome>;
}

export interface MockClubServiceOptions {
  /** Defaults to browser localStorage (or none outside a browser). */
  storage?: MockStorage | null;
  now?: () => Date;
  /** Simulated latency so the demo shows loading states. */
  latencyMs?: number;
}

/**
 * Sales-demo implementation. Simulates the Task 1B engine's rules —
 * selected coffee, one per member per Athens day, same-request replay,
 * idempotency_key_reused, inactive membership — in localStorage.
 * MOCK ONLY: not concurrency-safe and not shared between devices.
 */
export function createMockClubService(options: MockClubServiceOptions = {}): ClubService {
  const storage = () => (options.storage === undefined ? browserStorage() : options.storage);
  const now = options.now ?? (() => new Date());
  const latencyMs = options.latencyMs ?? 350;

  function delay<T>(value: T): Promise<T> {
    if (latencyMs <= 0) return Promise.resolve(value);
    return new Promise((resolve) => setTimeout(() => resolve(value), latencyMs));
  }

  /** Member 2 always starts each new day already having used today's coffee. */
  function ensureSeedRedemption(): void {
    const today = todayKey(now());
    const store = readMockStore(storage());
    const key = dayKey(SEEDED_USED_MEMBER_ID, "free_coffee", today);
    if (store.byDay[key]) return;
    const [hours, minutes] = SEEDED_USED_TIME.split(":").map(Number);
    const seededAt = new Date(now().getTime());
    seededAt.setHours(hours, minutes, 0, 0);
    store.byDay[key] = {
      memberId: SEEDED_USED_MEMBER_ID,
      benefitType: "free_coffee",
      businessDate: today,
      redeemedAt: seededAt.toISOString(),
      coffeeCode: "freddo_espresso",
      requestId: null,
      fingerprint: null,
    };
    writeMockStore(storage(), store, today);
  }

  function benefitStatusFor(member: MockMemberRecord): MemberBenefitStatus {
    const businessDate = todayKey(now());
    if (member.status !== "active") {
      return { state: "unavailable", businessDate, redeemedAt: null, reason: "membership_inactive", coffeeCode: null };
    }
    const record = readMockStore(storage()).byDay[dayKey(member.id, "free_coffee", businessDate)];
    if (record) {
      return { state: "used", businessDate, redeemedAt: record.redeemedAt, coffeeCode: record.coffeeCode };
    }
    return { state: "available", businessDate, redeemedAt: null, coffeeCode: null };
  }

  /** Masks the phone and drops the QR token, exactly like the production API. */
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

  function outcomeFrom(record: MockRedemptionRecord, requestId: string, replayed: boolean): RedemptionOutcome {
    return {
      benefit: { state: "used", businessDate: record.businessDate, redeemedAt: record.redeemedAt, coffeeCode: record.coffeeCode },
      requestId,
      replayed,
    };
  }

  return {
    async findMemberByPhone(phone) {
      ensureSeedRedemption();
      const normalized = normalizeGreekPhone(phone);
      return delay(lookup(mockMembers.find((m) => m.phone === normalized)));
    },

    async findMemberByQrToken(token) {
      ensureSeedRedemption();
      return delay(lookup(mockMembers.find((m) => m.qrToken === token)));
    },

    async getMemberStatus(memberId) {
      ensureSeedRedemption();
      const record = mockMembers.find((m) => m.id === memberId);
      return delay(record ? benefitStatusFor(record) : null);
    },

    async getCoffeeOptions() {
      return delay(DEMO_COFFEE_OPTIONS.map((o) => ({ ...o })));
    },

    async redeemBenefit({ memberId, benefitType, coffeeCode, requestId }) {
      // Same order as the PHP engine: validate → coffee → idempotency →
      // member → membership → one-per-day insert.
      await delay(null);
      if (!isValidRequestId(requestId) || benefitType !== "free_coffee" || typeof memberId !== "string") {
        throw clubError("invalid_request");
      }
      if (!DEMO_COFFEE_OPTIONS.some((o) => o.code === coffeeCode)) {
        throw clubError("invalid_coffee");
      }

      const fingerprint = mockFingerprint(memberId, benefitType, coffeeCode);
      const store = readMockStore(storage());
      const previous = store.byRequest[requestId];
      if (previous) {
        if (previous.fingerprint === fingerprint) return outcomeFrom(previous, requestId, true);
        throw clubError("idempotency_key_reused");
      }

      const member = mockMembers.find((m) => m.id === memberId);
      if (!member) throw clubError("member_not_found");
      if (member.status !== "active") throw clubError("membership_inactive", { status: member.status });

      const instant = now();
      const businessDate = todayKey(instant);
      const key = dayKey(memberId, benefitType, businessDate);
      const winner = store.byDay[key];
      if (winner) {
        throw clubError("benefit_already_redeemed", {
          businessDate: winner.businessDate,
          redeemedAt: winner.redeemedAt,
          ...(winner.coffeeCode ? { coffeeCode: winner.coffeeCode } : {}),
        });
      }

      const record: MockRedemptionRecord = {
        memberId,
        benefitType,
        businessDate,
        redeemedAt: instant.toISOString(),
        coffeeCode,
        requestId,
        fingerprint,
      };
      store.byDay[key] = record;
      store.byRequest[requestId] = record;
      writeMockStore(storage(), store, businessDate);
      return outcomeFrom(record, requestId, false);
    },
  };
}

export const mockClubService: ClubService = createMockClubService();

/** The one switch point. Stays on the mock until auth, same-origin routing
 * and staging are in place (docs §3, deployment blockers). */
export const clubService: ClubService = mockClubService;

export { DEMO_QR_TOKEN };

/** Presentation-only aid for the sales demo. */
export interface DemoPhoneHint {
  phone: string;
  label: string;
}

export const DEMO_PHONE_HINTS: DemoPhoneHint[] = [
  { phone: mockMembers[0].phone, label: "Ενεργό · καφές διαθέσιμος" },
  { phone: mockMembers[1].phone, label: "Ενεργό · καφές χρησιμοποιημένος" },
  { phone: mockMembers[2].phone, label: "Ληγμένη συνδρομή" },
];

/** What the UI shows for any lookup failure — never a raw technical error. */
export const CLUB_LOOKUP_ERROR_MESSAGE = "Δεν ήταν δυνατός ο έλεγχος του μέλους. Δοκιμάστε ξανά.";
