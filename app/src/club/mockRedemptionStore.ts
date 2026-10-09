/**
 * MOCK-ONLY persistence for the sales demo. Nothing here is used by the REST
 * adapter or represents production behaviour.
 *
 * localStorage is per-browser and NOT concurrency-safe: two tabs/tablets do
 * not share it and simultaneous writes can lose data. Production enforces
 * one-coffee-per-day and idempotency with database unique constraints
 * (Task 1B engine), not with anything in this file.
 */

export interface MockRedemptionRecord {
  memberId: string;
  benefitType: string;
  businessDate: string;
  /** ISO 8601 instant with offset. */
  redeemedAt: string;
  coffeeCode: string | null;
  /** Null for legacy/seeded records that were not created by a request. */
  requestId: string | null;
  /** member|benefit|coffee — what a retry of requestId must match. */
  fingerprint: string | null;
}

export interface MockStoreData {
  version: 2;
  /** key: memberId|benefitType|businessDate */
  byDay: Record<string, MockRedemptionRecord>;
  /** key: requestId */
  byRequest: Record<string, MockRedemptionRecord>;
}

export type MockStorage = Pick<Storage, "getItem" | "setItem" | "removeItem">;

export const MOCK_STORE_KEY = "evaggelou-club-redemptions-v2";
/** v1 shape: { [memberId]: { dateKey, redeemedAtISO } } (pre-Task 1C-A demo). */
export const LEGACY_MOCK_STORE_KEY = "evaggelou-club-redemptions";

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const KEEP_DAYS = 14;

export function dayKey(memberId: string, benefitType: string, businessDate: string): string {
  return `${memberId}|${benefitType}|${businessDate}`;
}

export function mockFingerprint(memberId: string, benefitType: string, coffeeCode: string): string {
  return `${memberId}|${benefitType}|${coffeeCode}`;
}

function emptyStore(): MockStoreData {
  return { version: 2, byDay: {}, byRequest: {} };
}

function isRecord(v: unknown): v is MockRedemptionRecord {
  if (typeof v !== "object" || v === null) return false;
  const r = v as Record<string, unknown>;
  return (
    typeof r.memberId === "string" &&
    typeof r.benefitType === "string" &&
    typeof r.businessDate === "string" &&
    DATE_RE.test(r.businessDate) &&
    typeof r.redeemedAt === "string" &&
    !Number.isNaN(Date.parse(r.redeemedAt)) &&
    (r.coffeeCode === null || typeof r.coffeeCode === "string") &&
    (r.requestId === null || typeof r.requestId === "string") &&
    (r.fingerprint === null || typeof r.fingerprint === "string")
  );
}

function cleanMap(raw: unknown): Record<string, MockRedemptionRecord> {
  const out: Record<string, MockRedemptionRecord> = {};
  if (typeof raw !== "object" || raw === null) return out;
  for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
    if (isRecord(value)) out[key] = value;
  }
  return out;
}

function parseJson(text: string | null): unknown {
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

/** Reads the store, dropping anything malformed and importing valid v1 data once. */
export function readMockStore(storage: MockStorage | null): MockStoreData {
  if (!storage) return emptyStore();
  try {
    const parsed = parseJson(storage.getItem(MOCK_STORE_KEY)) as Partial<MockStoreData> | null;
    const store =
      parsed && parsed.version === 2
        ? { version: 2 as const, byDay: cleanMap(parsed.byDay), byRequest: cleanMap(parsed.byRequest) }
        : emptyStore();

    const legacy = parseJson(storage.getItem(LEGACY_MOCK_STORE_KEY));
    if (legacy && typeof legacy === "object") {
      for (const [memberId, value] of Object.entries(legacy as Record<string, unknown>)) {
        const v = value as { dateKey?: unknown; redeemedAtISO?: unknown } | null;
        if (
          v &&
          typeof v.dateKey === "string" &&
          DATE_RE.test(v.dateKey) &&
          typeof v.redeemedAtISO === "string" &&
          !Number.isNaN(Date.parse(v.redeemedAtISO))
        ) {
          const key = dayKey(memberId, "free_coffee", v.dateKey);
          store.byDay[key] ??= {
            memberId,
            benefitType: "free_coffee",
            businessDate: v.dateKey,
            redeemedAt: v.redeemedAtISO,
            coffeeCode: null,
            requestId: null,
            fingerprint: null,
          };
        }
      }
      storage.removeItem(LEGACY_MOCK_STORE_KEY);
    }
    return store;
  } catch {
    return emptyStore();
  }
}

/** Writes the store, pruning records older than KEEP_DAYS. Failures are ignored (demo only). */
export function writeMockStore(storage: MockStorage | null, store: MockStoreData, today: string): void {
  if (!storage) return;
  const cutoff = new Date(`${today}T00:00:00Z`);
  cutoff.setUTCDate(cutoff.getUTCDate() - KEEP_DAYS);
  const cutoffKey = cutoff.toISOString().slice(0, 10);
  const keep = (r: MockRedemptionRecord) => r.businessDate >= cutoffKey;
  const pruned: MockStoreData = {
    version: 2,
    byDay: Object.fromEntries(Object.entries(store.byDay).filter(([, r]) => keep(r))),
    byRequest: Object.fromEntries(Object.entries(store.byRequest).filter(([, r]) => keep(r))),
  };
  try {
    storage.setItem(MOCK_STORE_KEY, JSON.stringify(pruned));
  } catch {
    // Private mode / quota: the demo simply won't persist across reloads.
  }
}

/** Browser localStorage if usable, else null (SSR, Node tests, blocked storage). */
export function browserStorage(): MockStorage | null {
  try {
    return typeof globalThis.localStorage === "undefined" ? null : globalThis.localStorage;
  } catch {
    return null;
  }
}
