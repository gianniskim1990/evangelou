import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { CustomerPreview } from "../../src/preview/CustomerPreview";
import { ManagementPreview } from "../../src/preview/ManagementPreview";
import { StaffPreview } from "../../src/preview/StaffPreview";
import { addCalendarMonths } from "../../src/preview/format";
import { PREVIEW_MARKER } from "../../src/preview/previewData";
import { CUSTOMER_SCREENS, MANAGEMENT_SCREENS, STAFF_SCREENS } from "../../src/preview/screens";

/**
 * UI-02: every one of the 26 preview screens server-renders with real
 * content, and the rendered copy keeps the approved business rules.
 */

const TODAY = "2026-10-13";
const noop = () => {};
const text = (html: string) => html.replace(/<[^>]+>/g, " ").replace(/&nbsp;/g, " ").replace(/\s+/g, " ").trim();

const customer = CUSTOMER_SCREENS.flatMap(({ id }) =>
  (["active", "pending", "expired"] as const).flatMap((state) =>
    [false, true].map((coffeeUsedToday) => ({ id, state, html: renderToStaticMarkup(createElement(CustomerPreview, { screen: id, go: noop, state, coffeeUsedToday, today: TODAY })) })),
  ),
);
const staff = STAFF_SCREENS.map(({ id }) => ({ id, html: renderToStaticMarkup(createElement(StaffPreview, { screen: id, go: noop, today: TODAY })) }));
const management = MANAGEMENT_SCREENS.map(({ id }) => ({ id, html: renderToStaticMarkup(createElement(ManagementPreview, { screen: id, go: noop, today: TODAY })) }));

describe("UI-02 preview screens render", () => {
  it("covers all 26 screens", () => {
    assert.equal(CUSTOMER_SCREENS.length + STAFF_SCREENS.length + MANAGEMENT_SCREENS.length, 26);
  });

  it("every screen renders real content with the shared design-system tokens", () => {
    for (const s of [...customer, ...staff, ...management]) {
      assert.ok(text(s.html).length > 150, `${s.id} renders content`);
      assert.ok(!s.html.includes(PREVIEW_MARKER), `${s.id}: the preview marker stays on the app shell, not inside screens`);
    }
    for (const s of [...customer, ...staff, ...management].filter((x) => x.id !== "welcome" && x.id !== "card")) {
      assert.match(s.html, /var\(--(roast|ink|milk|paper|stone)\)/, `${s.id} uses the approved design tokens`);
    }
  });
});

describe("UI-02 approved business rules in rendered copy", () => {
  const THIRTY_DAYS_MEMBERSHIP = /30\s*(ημέρ|ημερ|μέρ|days?)/i;

  it("customer screens never describe the membership as 30 days", () => {
    for (const s of customer) assert.doesNotMatch(text(s.html), THIRTY_DAYS_MEMBERSHIP, `${s.id}/${s.state}`);
  });

  it("customer screens state one calendar month and manual renewal where the price appears", () => {
    for (const s of customer.filter((x) => text(x.html).includes("20 €"))) {
      assert.match(text(s.html), /ημερολογιακό μήνα/, `${s.id}/${s.state} names the calendar month`);
    }
    const offer = text(customer.find((s) => s.id === "offer")!.html);
    assert.match(offer, /Χειροκίνητη ανανέωση, χωρίς αυτόματη χρέωση/);
    assert.match(offer, /ώρα Ελλάδας/);
  });

  it("the free coffee is one per Athens calendar day", () => {
    const today = text(customer.find((s) => s.id === "today")!.html);
    assert.match(today, /Ένας δωρεάν καφές ανά ημερολογιακή ημέρα, ώρα Ελλάδας/);
  });

  it("coffee lists are always presented as illustrative, never as the final catalogue", () => {
    const lists = [
      text(customer.find((s) => s.id === "today")!.html),
      text(staff.find((s) => s.id === "coffee")!.html),
      text(management.find((s) => s.id === "settings")!.html),
      text(management.find((s) => s.id === "preferences")!.html),
    ];
    for (const t of lists) assert.match(t, /[Εε]νδεικτικ/);
  });

  it("payment and renewal are never actionable in the preview", () => {
    let found = 0;
    for (const s of customer) {
      // React separates text fragments with <!-- -->; drop them so button labels read whole.
      const html = s.html.replace(/<!-- -->/g, "");
      for (const m of html.matchAll(/<button[^>]*>([^<]*(Πληρωμή|Ανανέωση|Ολοκλήρωση πληρωμής)[^<]*)<\/button>/g)) {
        found++;
        assert.match(m[0], /disabled=""/, `${s.id}/${s.state}: "${m[1]}" must be disabled`);
      }
    }
    // At least the offer (6 renders) plus the pending/expired dashboards and passes.
    assert.ok(found >= 10, `payment/renewal buttons found: ${found}`);
  });

  it("staff confirmation states that nothing is recorded", () => {
    assert.match(text(staff.find((s) => s.id === "confirm")!.html), /δεν καταχωρείται εξαργύρωση/);
  });

  it("management stays read-only: no forms or member-changing actions", () => {
    for (const s of management) {
      assert.ok(!s.html.includes("<form"), `${s.id} has no form`);
      assert.doesNotMatch(text(s.html), /Αποθήκευση|Ενεργοποίηση μέλους|Ακύρωση συνδρομής|Επεξεργασία/, `${s.id} offers no edit actions`);
    }
  });
});

describe("calendar-month arithmetic (membership timeline)", () => {
  it("adds and subtracts calendar months, clamping to the month end", () => {
    assert.equal(addCalendarMonths("2026-11-01", -1), "2026-10-01");
    assert.equal(addCalendarMonths("2026-01-31", 1), "2026-02-28");
    assert.equal(addCalendarMonths("2028-01-31", 1), "2028-02-29");
    assert.equal(addCalendarMonths("2026-03-31", -1), "2026-02-28");
    assert.equal(addCalendarMonths("2026-12-15", 1), "2027-01-15");
  });
});
