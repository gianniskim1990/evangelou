/*
 * CUSTOMER-01 LOCAL-ONLY customer preview: the owner-approved membership time
 * rules (D1, D2, D5 of 2026-10-10) in TypeScript, mirroring
 * wordpress/evangelou-club/includes/class-evc-membership-calendar.php so the
 * synthetic preview behaves like the future server. Simulation only: in
 * production the server alone decides paid membership and redemption.
 *
 *  D1  A paid period ends at the EXACT Europe/Athens wall-clock time one
 *      calendar month after it starts; the end instant is EXCLUSIVE.
 *  D5  One month = one Athens CALENDAR month, clamped to the last valid day
 *      (31 Jan -> 28/29 Feb), never a fixed 30 days.
 *  D2  Early renewal (payment confirmed while the paid period is running)
 *      starts at the current paid end; late renewal starts at the payment
 *      confirmation instant. A pending (unconfirmed) renewal grants nothing.
 *
 * DST, as on the server: a computed end time that occurs twice resolves to
 * its FIRST occurrence; one that does not exist resolves to the transition
 * instant. Both only ever shorten a period. Instants are whole seconds.
 */

export const ATHENS_TIME_ZONE = "Europe/Athens";

export type AthensWall = { y: number; m: number; d: number; hh: number; mm: number; ss: number };

const WALL = new Intl.DateTimeFormat("en-GB", {
  timeZone: ATHENS_TIME_ZONE,
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
  hourCycle: "h23",
});

export function athensWall(ms: number): AthensWall {
  const parts = WALL.formatToParts(new Date(ms));
  const get = (t: string) => Number(parts.find((p) => p.type === t)?.value ?? NaN);
  return { y: get("year"), m: get("month"), d: get("day"), hh: get("hour"), mm: get("minute"), ss: get("second") };
}

const pad = (n: number) => String(n).padStart(2, "0");

/** The Athens calendar date (YYYY-MM-DD): the free coffee resets at Athens midnight. */
export function athensDate(ms: number): string {
  const w = athensWall(ms);
  return `${w.y}-${pad(w.m)}-${pad(w.d)}`;
}

/** The Athens wall-clock time as HH:MM. */
export function athensTime(ms: number): string {
  const w = athensWall(ms);
  return `${pad(w.hh)}:${pad(w.mm)}`;
}

export function daysInMonth(year: number, month: number): number {
  return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

/** Minutes the Athens clock is ahead of UTC at `ms` (120 in winter, 180 in summer). */
export function athensOffsetMinutes(ms: number): number {
  const w = athensWall(ms);
  return Math.round((Date.UTC(w.y, w.m - 1, w.d, w.hh, w.mm, w.ss) - Math.floor(ms / 1000) * 1000) / 60_000);
}

const same = (a: AthensWall, b: AthensWall) => a.y === b.y && a.m === b.m && a.d === b.d && a.hh === b.hh && a.mm === b.mm && a.ss === b.ss;

/** An Athens wall-clock time as a UTC instant: the first instant at which the Athens clock shows it (or later, across a gap). */
export function athensInstant(w: AthensWall): number {
  if (w.m < 1 || w.m > 12 || w.d < 1 || w.d > daysInMonth(w.y, w.m) || w.hh < 0 || w.hh > 23 || w.mm < 0 || w.mm > 59 || w.ss < 0 || w.ss > 59) {
    throw new RangeError("invalid Athens wall-clock time");
  }
  const naive = Date.UTC(w.y, w.m - 1, w.d, w.hh, w.mm, w.ss);
  const candidates = [naive - 3 * 3_600_000, naive - 2 * 3_600_000];
  const valid = candidates.filter((c) => same(athensWall(c), w));
  if (valid.length > 0) return Math.min(...valid);
  // Skipped by the spring transition: the instant the clock jumps over it.
  let lo = candidates[0];
  let hi = candidates[1];
  const before = athensOffsetMinutes(lo);
  while (hi - lo > 1000) {
    const mid = lo + Math.floor((hi - lo) / 2000) * 1000;
    if (athensOffsetMinutes(mid) === before) lo = mid;
    else hi = mid;
  }
  return hi;
}

/** D1 + D5: the exclusive end of a paid period that starts at `start`. */
export function addCalendarMonth(start: number): number {
  const w = athensWall(start);
  const y = w.m === 12 ? w.y + 1 : w.y;
  const m = w.m === 12 ? 1 : w.m + 1;
  return athensInstant({ ...w, y, m, d: Math.min(w.d, daysInMonth(y, m)) });
}

export type PaidPeriod = { start: number; end: number; renewal: "initial" | "early" | "late" };

/** D2: the period a payment confirmed at `confirmedAt` funds, given the current paid end (null for the first one). */
export function nextPeriod(currentPaidEnd: number | null, confirmedAt: number): PaidPeriod {
  if (currentPaidEnd === null) return { start: confirmedAt, end: addCalendarMonth(confirmedAt), renewal: "initial" };
  // Expired exactly at its end (exclusive), so equality is late.
  const start = confirmedAt < currentPaidEnd ? currentPaidEnd : confirmedAt;
  return { start, end: addCalendarMonth(start), renewal: confirmedAt < currentPaidEnd ? "early" : "late" };
}

/** Whole calendar days from Athens date `from` to Athens date `to` (YYYY-MM-DD). */
export function calendarDaysBetween(from: string, to: string): number {
  return Math.round((Date.parse(`${to}T12:00:00Z`) - Date.parse(`${from}T12:00:00Z`)) / 86_400_000);
}

/** The first instant of the next Athens calendar day after `ms`. */
export function nextAthensMidnight(ms: number): number {
  const w = athensWall(ms);
  const next = new Date(Date.UTC(w.y, w.m - 1, w.d + 1, 12));
  return athensInstant({ y: next.getUTCFullYear(), m: next.getUTCMonth() + 1, d: next.getUTCDate(), hh: 0, mm: 0, ss: 0 });
}
