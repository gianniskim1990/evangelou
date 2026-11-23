import type { CoffeeOrder } from "./coffeeConfig";
import { CLUB_RULES, greekDate, type CoffeeCode, type PreviewMember, type PreviewMembershipState, type PreviewRedemption } from "./previewData";

/*
 * What the approved customer screens show, as one value (CUSTOMER-01).
 *
 * The UI-02 design preview builds it from the fixed sample member and the
 * preview's sample switch (sampleCustomerView in customerSample.ts, a module
 * the real member app never imports: STAGE-08 round 17). The
 * LOCAL-ONLY customer journey preview builds it from its simulated server
 * instead, so both render through the same approved components.
 */

/**
 * Membership as the customer sees it. "cancelled" exists only outside the UI-02
 * sample; "none" (no Club membership) and "unverified" (the server cannot
 * confirm a paid period, never shown as paid) only in the STAGE-01 live app.
 */
export type CustomerMembershipState = PreviewMembershipState | "cancelled" | "none" | "unverified";

/**
 * D24 live app: `label` (the coffee as the till recorded it) and `details` (its preparation labels) replace the
 * preview's code-based name and synthetic preparation; the live app never knows the time (`time` is "").
 */
export type CustomerHistoryItem = PreviewRedemption & { preparation?: CoffeeOrder; label?: string; details?: string[] };

export type CustomerView = {
  member: PreviewMember;
  state: CustomerMembershipState;
  coffeeUsedToday: boolean;
  /** Athens calendar date YYYY-MM-DD. */
  today: string;
  /** Athens date of the paid end (null while payment is pending). */
  paidUntil: string | null;
  /** Athens wall-clock time of the exact paid end, when known (journey preview only). */
  paidUntilTime: string | null;
  daysLeft: number;
  visitsThisMonth: number;
  /** Newest first. */
  history: CustomerHistoryItem[];
  /** Derived usual coffee (D-USUAL); null when the history is too short. */
  usual: CoffeeCode | null;
  /** Minutes to the next Athens midnight; null = read the device clock. */
  minutesToMidnight: number | null;
  /** A renewal was requested and its payment is not confirmed yet (grants nothing). */
  pendingRenewal: boolean;
  /** STAGE-01 live app: real customer data, so the preview notes about fictional data are not shown. */
  live?: boolean;
  /**
   * STAGE-01 live app: the Club database (today's coffee, coffee history, visits)
   * is not connected. Nothing about today's coffee or past coffees is claimed.
   */
  clubData?: "unavailable";
  /**
   * D23 live app: today's coffee and this month's count are real (clubData absent), but the coffee history
   * is not sent by the server, so the history lists and the 7-day strip are not claimed either.
   * D24 (round 9): "recent" = the member's own LAST coffees (at most 5) are real; the lists show them, but the
   * 7-day strip and the per-month counts are not claimed (a short list cannot prove a day without coffee).
   */
  historyData?: "unavailable" | "recent";
};

/**
 * Journey/live actions; without them the approved buttons stay disabled. `join` (STAGE-08 round 10, live app only)
 * opens the in-store join flow; it exists only when the server sent a join URL (EVC_CLUB_ONBOARDING_ENABLED).
 */
export type CustomerActions = { pay?: () => void; renew?: () => void; signOut?: () => void; join?: () => void };

/**
 * STAGE-08 round 11 (owner decision D27): the live member area's actions. Both «Γίνετε μέλος» (no membership) and
 * «Ανανέωση 20 €» (EXPIRED membership) open the join flow, which already leads an expired or new customer through
 * the terms to the same checkout as joining, and which never takes a second order while one is open
 * (EVC_Onboarding_Handoff::blocks_new_order). No action without a join URL; none for an active member (no renewal
 * before the end of the paid month), a pending payment, a cancelled or an unverified membership.
 */
export function liveActions(state: CustomerMembershipState, joinUrl: string, navigate: (url: string) => void, signOut: () => void): CustomerActions {
  if (joinUrl === "") return { signOut };
  if (state === "none") return { signOut, join: () => navigate(joinUrl) };
  if (state === "expired") return { signOut, renew: () => navigate(joinUrl) };
  return { signOut };
}

/**
 * STAGE-08 round 10 (owner's staging run, 2026-11-20): a signed-in customer with NO membership gets «Γίνετε μέλος»,
 * which opens the join flow (it skips the registration for a signed-in account and ends at the payment). Only for
 * the state «none» and only when the join flow exists (a `join` action); otherwise nothing new is shown. Pending,
 * expired, cancelled and unverified keep their existing buttons (paymentAction).
 */
export function joinAction(view: CustomerView, actions?: CustomerActions): { label: string; run: () => void } | null {
  if (view.state !== "none" || !actions?.join) return null;
  return { label: "Γίνετε μέλος", run: actions.join };
}

/** The tooltip of a button that only the design preview leaves disabled. Never on the live app (round 11). */
export const PREVIEW_ONLY = "Μη διαθέσιμο στην προεπισκόπηση";

/**
 * The payment button a non-active member sees; without a journey action it stays disabled (preview only).
 * STAGE-08 round 11, owner decision D27 (live app): «Ανανέωση 20 €» only for an EXPIRED membership and only with a
 * `renew` action (the join flow, the same payment as joining); no disabled button ever. A pending payment has no
 * button at all on the live app: it is a bank transfer the shop confirms, so paymentNote() says what to do.
 */
export function paymentAction(view: CustomerView, actions?: CustomerActions): { kind: "pay" | "renew"; label: string; run?: () => void } | null {
  if (view.state === "pending" || (view.state === "expired" && view.pendingRenewal)) return view.live ? null : { kind: "pay", label: "Ολοκλήρωση πληρωμής", run: actions?.pay };
  if (view.state === "expired") return view.live && !actions?.renew ? null : { kind: "renew", label: `Ανανέωση ${CLUB_RULES.monthlyPriceEur} €`, run: actions?.renew };
  return null;
}

/** Round 11 (D27): what a pending payment means, in place of the old disabled «Ολοκλήρωση πληρωμής». */
export const PENDING_PAYMENT_NOTE =
  "Η πληρωμή σας περιμένει την τραπεζική μεταφορά. Τα στοιχεία του λογαριασμού για τη μεταφορά υπάρχουν στο μήνυμα επιβεβαίωσης της παραγγελίας που λάβατε με ηλεκτρονικό ταχυδρομείο. Η συνδρομή ενεργοποιείται μόλις το κατάστημα επιβεβαιώσει τη μεταφορά.";
/** Round 11: an expired membership when the join flow is off on this site (no renewal link exists). */
export const RENEW_IN_STORE_NOTE = "Για να ανανεώσετε τη συνδρομή σας, μιλήστε με το κατάστημα.";

/** Round 11 (D27): plain information instead of a button (live app only; the preview keeps its sample buttons). */
export function paymentNote(view: CustomerView, actions?: CustomerActions): string | null {
  if (!view.live) return null;
  if (view.state === "pending" || (view.state === "expired" && view.pendingRenewal)) return PENDING_PAYMENT_NOTE;
  if (view.state === "expired" && !actions?.renew) return RENEW_IN_STORE_NOTE;
  return null;
}

export function renewalLine(view: CustomerView, standard: string): string {
  return view.pendingRenewal ? "Ζητήθηκε ανανέωση· περιμένει επιβεβαίωση πληρωμής" : standard;
}

/** "Ενεργή έως …", "Έληξε στις …", "Ακυρώθηκε" or the pending line. */
export function membershipLine(view: CustomerView): string {
  const at = view.paidUntilTime ? `, ${view.paidUntilTime}` : "";
  if (view.state === "active") return `Ενεργή έως ${greekDate(view.paidUntil!)}${at}`;
  if (view.state === "expired") return `Έληξε στις ${greekDate(view.paidUntil!)}${at}`;
  if (view.state === "cancelled") return "Ακυρώθηκε";
  if (view.state === "none") return "Δεν υπάρχει συνδρομή";
  if (view.state === "unverified") return "Η πληρωμή δεν έχει επιβεβαιωθεί";
  return "Αναμονή επιβεβαίωσης πληρωμής";
}
