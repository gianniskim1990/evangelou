import type { CoffeeOrder } from "./coffeeConfig";
import { CLUB_RULES, CUSTOMER, addDays, greekDate, memberHistory, type CoffeeCode, type PreviewMember, type PreviewMembershipState, type PreviewRedemption } from "./previewData";

/*
 * What the approved customer screens show, as one value (CUSTOMER-01).
 *
 * The UI-02 design preview builds it from the fixed sample member and the
 * preview's sample switch (sampleCustomerView: unchanged behaviour). The
 * LOCAL-ONLY customer journey preview builds it from its simulated server
 * instead, so both render through the same approved components.
 */

/** Membership as the customer sees it. "cancelled" exists only in the customer journey preview. */
export type CustomerMembershipState = PreviewMembershipState | "cancelled";

export type CustomerHistoryItem = PreviewRedemption & { preparation?: CoffeeOrder };

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
};

/** Journey-only actions; without them the approved buttons stay disabled. */
export type CustomerActions = { pay?: () => void; renew?: () => void };

function samplePaidUntil(state: PreviewMembershipState, today: string): string | null {
  if (state === "pending") return null;
  return addDays(today, state === "active" ? CUSTOMER.paidUntilOffsetDays : -3);
}

/** The approved UI-02 sample: the fixed preview member in the chosen sample state. */
export function sampleCustomerView(state: PreviewMembershipState, coffeeUsedToday: boolean, today: string): CustomerView {
  return {
    member: CUSTOMER,
    state,
    coffeeUsedToday,
    today,
    paidUntil: samplePaidUntil(state, today),
    paidUntilTime: null,
    daysLeft: state === "active" ? CUSTOMER.paidUntilOffsetDays : 0,
    visitsThisMonth: CUSTOMER.visitsThisMonth,
    history: memberHistory(CUSTOMER.id, today),
    usual: CUSTOMER.favourite,
    minutesToMidnight: null,
    pendingRenewal: false,
  };
}

/** The payment button a non-active member sees; without a journey action it stays disabled. */
export function paymentAction(view: CustomerView, actions?: CustomerActions): { label: string; run?: () => void } | null {
  if (view.state === "pending" || (view.state === "expired" && view.pendingRenewal)) return { label: "Ολοκλήρωση πληρωμής", run: actions?.pay };
  if (view.state === "expired") return { label: `Ανανέωση ${CLUB_RULES.monthlyPriceEur} €`, run: actions?.renew };
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
  return "Αναμονή επιβεβαίωσης πληρωμής";
}
