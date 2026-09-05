import type { OpeningPeriod } from "../types";

/** Index matches JS Date#getDay() (0=Κυριακή..6=Σάββατο). */
export const WEEKDAY_LABELS_EL = ["Κυριακή", "Δευτέρα", "Τρίτη", "Τετάρτη", "Πέμπτη", "Παρασκευή", "Σάββατο"];

/** Monday-first display order, matching how the store lists its week. */
export const WEEKDAY_DISPLAY_ORDER = [1, 2, 3, 4, 5, 6, 0];

export function defaultHours(opensAt = "09:00", closesAt = "23:00"): OpeningPeriod[] {
  return Array.from({ length: 7 }, (_, weekday) => ({ weekday, opensAt, closesAt, isClosed: false }));
}

function minutesOf(hhmm: string): number {
  const [h, m] = hhmm.split(":").map(Number);
  return h * 60 + m;
}

export function periodForToday(hours: OpeningPeriod[]): OpeningPeriod {
  const today = new Date().getDay();
  return hours.find((h) => h.weekday === today) ?? defaultHours()[today];
}

export function isStoreClosedNow(hours: OpeningPeriod[]): boolean {
  const p = periodForToday(hours);
  if (p.isClosed) return true;
  const nowMinutes = new Date().getHours() * 60 + new Date().getMinutes();
  return nowMinutes < minutesOf(p.opensAt) || nowMinutes >= minutesOf(p.closesAt);
}

/** 15-minute pickup slots for today's opening period, starting no sooner
 * than `prepMinutes` from now (so a customer can't pick an unrealistically
 * soon slot the kitchen has no time to prepare for). */
export function pickupSlotsForToday(hours: OpeningPeriod[], prepMinutes = 0): string[] {
  const p = periodForToday(hours);
  if (p.isClosed) return [];
  const now = new Date();
  const earliest = Math.max(minutesOf(p.opensAt), now.getHours() * 60 + now.getMinutes() + prepMinutes);
  // Round up to the next 15-minute mark.
  let mins = Math.ceil(earliest / 15) * 15;
  const close = minutesOf(p.closesAt);
  const slots: string[] = [];
  while (mins < close) {
    slots.push(`${String(Math.floor(mins / 60)).padStart(2, "0")}:${String(mins % 60).padStart(2, "0")}`);
    mins += 15;
  }
  return slots;
}

/** One-line summary for customer-facing screens: collapses to a single
 * range when every day matches, otherwise lists each day. */
export function formatHoursSummary(hours: OpeningPeriod[]): string {
  const first = hours[0];
  const allSame = first && hours.every((h) => !h.isClosed && h.opensAt === first.opensAt && h.closesAt === first.closesAt);
  if (allSame) return `Δευτέρα–Κυριακή, ${first.opensAt}–${first.closesAt}`;
  return WEEKDAY_DISPLAY_ORDER.map((w) => {
    const p = hours.find((h) => h.weekday === w);
    if (!p) return null;
    return `${WEEKDAY_LABELS_EL[w]}: ${p.isClosed ? "Κλειστά" : `${p.opensAt}–${p.closesAt}`}`;
  })
    .filter(Boolean)
    .join(" · ");
}

export function isValidTime(hhmm: string): boolean {
  return /^([01]\d|2[0-3]):[0-5]\d$/.test(hhmm);
}
