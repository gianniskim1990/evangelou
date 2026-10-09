import type { MembershipStatus } from "./types";

/**
 * "2026-10-15" or an ISO instant such as "2026-10-15T23:59:59+03:00"
 * -> "15 Οκτωβρίου 2026". A plain date is shown as-is; an instant is shown
 * as its Europe/Athens calendar day (the REST API sends instants).
 */
export function formatGreekLongDate(isoDateOrInstant: string): string {
  const options: Intl.DateTimeFormatOptions = { day: "numeric", month: "long", year: "numeric" };
  if (/^\d{4}-\d{2}-\d{2}$/.test(isoDateOrInstant)) {
    return new Date(`${isoDateOrInstant}T12:00:00Z`).toLocaleDateString("el-GR", { ...options, timeZone: "UTC" });
  }
  return new Date(isoDateOrInstant).toLocaleDateString("el-GR", { ...options, timeZone: "Europe/Athens" });
}

/** The shop's business timezone: the daily benefit resets at Athens midnight. */
export const BUSINESS_TIME_ZONE = "Europe/Athens";

/** ISO timestamp (with offset) -> "10:42" in Athens time, whatever the device's zone. */
export function formatClockTime(isoTimestamp: string): string {
  return new Date(isoTimestamp).toLocaleTimeString("el-GR", {
    hour: "2-digit",
    minute: "2-digit",
    timeZone: BUSINESS_TIME_ZONE,
  });
}

/** "6900000001" -> "69••••••01" — shows just enough to confirm identity.
 * In production this runs server-side (the API only ever returns
 * `phone_masked`) — kept here too since the mock service plays that role
 * for now. */
export function maskPhone(phone: string): string {
  if (phone.length <= 4) return phone;
  return phone.slice(0, 2) + "•".repeat(phone.length - 4) + phone.slice(-2);
}

/** The cashier-facing status chip — collapses the 4 backend statuses down
 * to the 2 distinct treatments the UI actually has copy for today (see
 * ClubMemberResult's detail line for the fuller, date-aware version of
 * this same "backend status -> cashier UI state" collapse). */
export function membershipBadgeLabel(status: MembershipStatus): string {
  if (status === "active") return "Ενεργό μέλος";
  if (status === "expired") return "Η συνδρομή έχει λήξει";
  return "Μη ενεργό μέλος";
}

/** Europe/Athens calendar day (YYYY-MM-DD) of an instant — the benefit's
 * business date. Independent of the device's own timezone. */
export function athensBusinessDate(instant: Date): string {
  return new Intl.DateTimeFormat("sv-SE", {
    timeZone: BUSINESS_TIME_ZONE,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(instant);
}

/** Today's Athens business date — a redemption "expires" when this rolls over. */
export function todayKey(now: Date = new Date()): string {
  return athensBusinessDate(now);
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
