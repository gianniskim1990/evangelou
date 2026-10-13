/* UI-02 design preview: small display formatters shared by preview screens (pure). */

/** Greek number formatting with a fixed number of decimals. */
export const fmt = (n: number, digits = 0) => n.toLocaleString("el-GR", { minimumFractionDigits: digits, maximumFractionDigits: digits });

/** "σήμερα", "αύριο" or "σε N ημέρες" for a non-negative day count. */
export function daysWord(n: number): string {
  if (n === 0) return "σήμερα";
  if (n === 1) return "αύριο";
  return `σε ${n} ημέρες`;
}

/** Calendar-month arithmetic on YYYY-MM-DD, clamping to the last day (31 Jan + 1 month = 28/29 Feb). */
export function addCalendarMonths(isoDate: string, months: number): string {
  const [y, m, d] = isoDate.split("-").map(Number);
  const target = new Date(Date.UTC(y, m - 1 + months, 1, 12));
  const last = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0, 12)).getUTCDate();
  target.setUTCDate(Math.min(d, last));
  return target.toISOString().slice(0, 10);
}
