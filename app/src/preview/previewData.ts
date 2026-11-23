/**
 * UI-01 DESIGN PREVIEW — entirely synthetic data.
 *
 * STAGE-08 round 17: the fictional roster and its synthetic history moved to
 * previewRoster.ts (a module the real member app never imports); this file
 * keeps the shared rules, types and calendar helpers.
 *
 * Every name, phone, e-mail and number below is invented for the visual
 * preview. Nothing here comes from, or is sent to, WordPress, PMPro,
 * WooCommerce, a payment provider or the Club database. Phones are shown
 * masked and e-mails use the reserved `.invalid` domain.
 */

/** Marker carried by every preview screen (and checked by isolation tests). */
export const PREVIEW_MARKER = "evc-club-design-preview";

export const BUSINESS_TIME_ZONE = "Europe/Athens";

/** Club rules shown in the preview (owner-approved Phase 1 business rules). */
export const CLUB_RULES = {
  monthlyPriceEur: 20,
  coffeesPerAthensDay: 1,
  renewal: "manual" as const,
};

export type PreviewMembershipState = "active" | "expired" | "pending";

export type CoffeeCode = "espresso" | "freddo-espresso" | "cappuccino" | "freddo-cappuccino" | "greek-coffee" | "filter";

export const COFFEES: { code: CoffeeCode; label: string; note: string }[] = [
  { code: "espresso", label: "Espresso", note: "Μονός ή διπλός" },
  { code: "freddo-espresso", label: "Freddo Espresso", note: "Κρύος" },
  { code: "cappuccino", label: "Cappuccino", note: "Ζεστός" },
  { code: "freddo-cappuccino", label: "Freddo Cappuccino", note: "Κρύος" },
  { code: "greek-coffee", label: "Ελληνικός", note: "Μονός ή διπλός" },
  { code: "filter", label: "Φίλτρου", note: "Ζεστός" },
];

export function coffeeName(code: CoffeeCode): string {
  return COFFEES.find((c) => c.code === code)?.label ?? code;
}

export type PreviewMember = {
  id: string;
  name: string;
  initials: string;
  phoneMasked: string;
  email: string;
  memberSince: string; // Athens date YYYY-MM-DD
  state: PreviewMembershipState;
  paidUntilOffsetDays: number; // relative to today (Athens)
  redeemedToday: boolean;
  favourite: CoffeeCode;
  newsletter: boolean;
  visitsThisMonth: number;
};


// ---- Athens calendar helpers (display only) ----------------------------------

/** Today's calendar date in Athens as YYYY-MM-DD (the free coffee resets at Athens midnight). */
export function athensToday(now: Date = new Date()): string {
  const parts = new Intl.DateTimeFormat("en-CA", { timeZone: BUSINESS_TIME_ZONE, year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(now);
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? "";
  return `${get("year")}-${get("month")}-${get("day")}`;
}

/** Calendar-day arithmetic on YYYY-MM-DD strings (no timezone involved). */
export function addDays(isoDate: string, days: number): string {
  const d = new Date(`${isoDate}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

export function greekDate(isoDate: string, withWeekday = false): string {
  return new Date(`${isoDate}T12:00:00Z`).toLocaleDateString("el-GR", {
    timeZone: "UTC",
    day: "numeric",
    month: "long",
    year: "numeric",
    ...(withWeekday ? { weekday: "long" } : {}),
  });
}

export function shortGreekDate(isoDate: string): string {
  return new Date(`${isoDate}T12:00:00Z`).toLocaleDateString("el-GR", { timeZone: "UTC", day: "numeric", month: "short" });
}

// ---- Redemption history (synthetic) ----------------------------------------------

export type PreviewRedemption = { date: string; time: string; coffee: CoffeeCode; memberId: string };
