/**
 * UI-01 DESIGN PREVIEW — entirely synthetic data.
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

/** Fictional members (design preview only). */
export const MEMBERS: PreviewMember[] = [
  { id: "m01", name: "Αλεξάνδρα Κωνσταντίνου", initials: "ΑΚ", phoneMasked: "69•• ••• •14", email: "alexandra.k@example.invalid", memberSince: "2026-09-02", state: "active", paidUntilOffsetDays: 19, redeemedToday: false, favourite: "freddo-espresso", newsletter: true, visitsThisMonth: 9 },
  { id: "m02", name: "Νικόλαος Βασιλείου", initials: "ΝΒ", phoneMasked: "69•• ••• •27", email: "nikos.v@example.invalid", memberSince: "2026-09-10", state: "active", paidUntilOffsetDays: 4, redeemedToday: true, favourite: "espresso", newsletter: false, visitsThisMonth: 11 },
  { id: "m03", name: "Δήμητρα Ηλιοπούλου", initials: "ΔΗ", phoneMasked: "69•• ••• •33", email: "dimitra.i@example.invalid", memberSince: "2026-08-21", state: "expired", paidUntilOffsetDays: -3, redeemedToday: false, favourite: "cappuccino", newsletter: true, visitsThisMonth: 4 },
  { id: "m04", name: "Γιάννης Ραφαηλίδης", initials: "ΓΡ", phoneMasked: "69•• ••• •48", email: "giannis.r@example.invalid", memberSince: "2026-10-11", state: "pending", paidUntilOffsetDays: 0, redeemedToday: false, favourite: "greek-coffee", newsletter: false, visitsThisMonth: 0 },
  { id: "m05", name: "Σοφία Λαμπράκη", initials: "ΣΛ", phoneMasked: "69•• ••• •52", email: "sofia.l@example.invalid", memberSince: "2026-09-25", state: "active", paidUntilOffsetDays: 12, redeemedToday: false, favourite: "freddo-cappuccino", newsletter: true, visitsThisMonth: 7 },
  { id: "m06", name: "Παναγιώτης Μαυρίδης", initials: "ΠΜ", phoneMasked: "69•• ••• •61", email: "panos.m@example.invalid", memberSince: "2026-09-04", state: "active", paidUntilOffsetDays: 1, redeemedToday: false, favourite: "filter", newsletter: false, visitsThisMonth: 13 },
  { id: "m07", name: "Ειρήνη Θεοδωρίδου", initials: "ΕΘ", phoneMasked: "69•• ••• •75", email: "eirini.t@example.invalid", memberSince: "2026-07-30", state: "expired", paidUntilOffsetDays: -16, redeemedToday: false, favourite: "freddo-espresso", newsletter: true, visitsThisMonth: 0 },
  { id: "m08", name: "Κωνσταντίνος Αλεξίου", initials: "ΚΑ", phoneMasked: "69•• ••• •89", email: "kostas.a@example.invalid", memberSince: "2026-10-02", state: "active", paidUntilOffsetDays: 20, redeemedToday: true, favourite: "cappuccino", newsletter: true, visitsThisMonth: 8 },
];

/** The member used for the customer-side screens. */
export const CUSTOMER = MEMBERS[0];

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

const TIMES = ["08:12", "08:47", "09:05", "09:31", "10:18", "11:02", "12:40", "13:15", "16:22", "17:48"];

/** Deterministic synthetic history for the last N Athens days (at most one per member per day). */
export function redemptionHistory(today: string, days = 21): PreviewRedemption[] {
  const out: PreviewRedemption[] = [];
  for (let d = 1; d <= days; d++) {
    const date = addDays(today, -d);
    MEMBERS.forEach((m, i) => {
      if (m.state === "pending") return;
      if (m.state === "expired" && d < -m.paidUntilOffsetDays) return;
      if ((d * 7 + i * 3) % 5 >= 3) return; // ~60% of days
      const coffee = (d + i) % 4 === 0 ? COFFEES[(d + i) % COFFEES.length].code : m.favourite;
      out.push({ date, time: TIMES[(d + i * 2) % TIMES.length], coffee, memberId: m.id });
    });
  }
  return out;
}

export function memberHistory(memberId: string, today: string): PreviewRedemption[] {
  return redemptionHistory(today).filter((r) => r.memberId === memberId);
}

/** Today's (synthetic) redemptions, consistent with each member's `redeemedToday`. */
export function todaysRedemptions(today: string): PreviewRedemption[] {
  return MEMBERS.filter((m) => m.redeemedToday).map((m, i) => ({ date: today, time: TIMES[i + 1], coffee: m.favourite, memberId: m.id }));
}

export function memberById(id: string): PreviewMember {
  return MEMBERS.find((m) => m.id === id) ?? MEMBERS[0];
}
