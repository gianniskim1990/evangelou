import { addDays, type PreviewMembershipState } from "./previewData";
import { CUSTOMER, memberHistory } from "./previewRoster";
import type { CustomerView } from "./customerView";

/*
 * STAGE-08 round 17 (owner-found bug, staging 2026-11-23): the approved UI-02 SAMPLE member view (fixed preview
 * member, sample figures, synthetic history) lives ONLY here, so the real member app never imports it
 * (tests/club/customerSampleGuard.test.ts checks the import graph of src/customer/main.tsx; the packager runs the
 * same check, scripts/sample-guard.mjs). Moved unchanged from customerView.ts.
 */

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
