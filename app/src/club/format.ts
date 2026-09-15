/** "2026-10-15" -> "15 Οκτωβρίου 2026" */
export function formatGreekLongDate(isoDate: string): string {
  return new Date(`${isoDate}T00:00:00`).toLocaleDateString("el-GR", {
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

/** ISO timestamp -> "10:42" in the viewer's local time. */
export function formatClockTime(isoTimestamp: string): string {
  return new Date(isoTimestamp).toLocaleTimeString("el-GR", { hour: "2-digit", minute: "2-digit" });
}

/** "6900000001" -> "69••••••01" — shows just enough to confirm identity. */
export function maskPhone(phone: string): string {
  if (phone.length <= 4) return phone;
  return phone.slice(0, 2) + "•".repeat(phone.length - 4) + phone.slice(-2);
}

/** Local calendar-day key (not UTC) — a redemption "expires" when this rolls over. */
export function todayKey(): string {
  return new Date().toLocaleDateString("sv-SE");
}

/**
 * Normalizes the Greek mobile formats a cashier (or WooCommerce/FluentCRM
 * data, eventually) might produce — spaces, a "+30" or "0030" country
 * prefix — down to the bare 10-digit canonical form ("69XXXXXXXX") that
 * mock (and future real) member records are keyed by. Returns whatever
 * digits remain if the input doesn't match a recognizable prefix, so the
 * caller can still judge completeness (e.g. "not yet 10 digits").
 */
export function normalizeGreekPhone(input: string): string {
  let digits = input.replace(/\D/g, "");
  if (digits.startsWith("0030")) digits = digits.slice(4);
  else if (digits.startsWith("30") && digits.length > 10) digits = digits.slice(2);
  return digits;
}
