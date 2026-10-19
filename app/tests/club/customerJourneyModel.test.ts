import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { MEMBERS } from "../../src/preview/previewData";
import { FakeCustomerServer } from "../../src/customerPreview/customerServer";
import { addCalendarMonth, athensDate, athensInstant, athensTime, nextAthensMidnight, nextPeriod } from "../../src/customerPreview/membershipCalendar";
import { MESSAGES, canonicalGreekMobile, firstInvalid, maskMobile, validateRegistration } from "../../src/customerPreview/registration";
import { SCENARIOS, SCENARIO_START, derivedUsual, toCustomerView } from "../../src/customerPreview/scenarios";

/*
 * CUSTOMER-01 — the LOCAL-ONLY customer journey's model: the owner-approved
 * membership time rules (D1/D2/D5, mirrored from the PHP
 * EVC_Membership_Calendar), the simulated server's eligibility rules, the
 * registration form rules and the deterministic scenarios. The tests run
 * under TZ=UTC+14, so nothing may depend on the device time zone.
 */

const A = (y: number, m: number, d: number, hh = 0, mm = 0, ss = 0) => athensInstant({ y, m, d, hh, mm, ss });
const iso = (ms: number) => new Date(ms).toISOString();
const MINUTE = 60_000;
const member = { ...MEMBERS[0], id: "m90" };

function freshServer(start = A(2026, 10, 19, 10, 15)) {
  let t = start;
  const clock = { now: () => t, set: (v: number) => (t = v), advance: (ms: number) => (t += ms) };
  return { clock, server: new FakeCustomerServer(clock.now) };
}

function registered(start?: number) {
  const w = freshServer(start);
  assert.equal(w.server.register({ member, emailGiven: true, newsletter: false, termsAccepted: true }).ok, true);
  return w;
}

describe("CUSTOMER-01 membership calendar (D1, D2, D5)", () => {
  it("Athens dates and times never depend on the device time zone", () => {
    assert.equal(athensDate(Date.parse("2026-10-19T20:59:59Z")), "2026-10-19");
    assert.equal(athensDate(Date.parse("2026-10-19T21:00:00Z")), "2026-10-20", "summer: Athens midnight is 21:00 UTC");
    assert.equal(athensDate(Date.parse("2026-12-01T22:00:00Z")), "2026-12-02", "winter: Athens midnight is 22:00 UTC");
    assert.equal(athensTime(Date.parse("2026-10-19T07:15:00Z")), "10:15");
    assert.equal(iso(nextAthensMidnight(Date.parse("2026-10-19T07:15:00Z"))), "2026-10-19T21:00:00.000Z");
    assert.equal(iso(nextAthensMidnight(Date.parse("2026-10-24T22:00:00Z"))), "2026-10-25T22:00:00.000Z", "the DST day is 25 hours long");
  });

  it("one month is one Athens calendar month at the same wall-clock time, never 30 days (D1, D5)", () => {
    assert.equal(iso(addCalendarMonth(A(2026, 10, 15, 12, 0))), iso(A(2026, 11, 15, 12, 0)));
    assert.equal(athensTime(addCalendarMonth(A(2026, 10, 8, 10, 42))), "10:42", "the wall-clock time survives the October DST change");
    assert.notEqual(addCalendarMonth(A(2026, 10, 8, 10, 42)) - A(2026, 10, 8, 10, 42), 30 * 86_400_000);
  });

  it("clamps to the last day of a shorter month and chains from the previous end (D5)", () => {
    assert.equal(iso(addCalendarMonth(A(2027, 1, 31, 9, 0))), iso(A(2027, 2, 28, 9, 0)));
    assert.equal(iso(addCalendarMonth(A(2028, 1, 31, 9, 0))), iso(A(2028, 2, 29, 9, 0)), "leap year");
    assert.equal(iso(addCalendarMonth(addCalendarMonth(A(2027, 1, 31, 9, 0)))), iso(A(2027, 3, 28, 9, 0)), "31 Jan → 28 Feb → 28 Mar (approved drift)");
    assert.equal(iso(addCalendarMonth(A(2026, 12, 31, 23, 30))), iso(A(2027, 1, 31, 23, 30)), "year boundary");
  });

  it("DST: a repeated end time resolves to its first occurrence, a skipped one to the transition", () => {
    // 25 Oct 2026 03:30 happens twice in Athens (00:30Z and 01:30Z): the earlier one.
    assert.equal(iso(addCalendarMonth(A(2026, 9, 25, 3, 30))), "2026-10-25T00:30:00.000Z");
    // 28 Mar 2027 03:30 does not exist (03:00 → 04:00 at 01:00Z): the transition instant.
    assert.equal(iso(addCalendarMonth(A(2027, 2, 28, 3, 30))), "2027-03-28T01:00:00.000Z");
  });

  it("D2: early renewal starts at the current paid end, late renewal at the payment confirmation", () => {
    const end = A(2026, 10, 20, 9, 0);
    const early = nextPeriod(end, end - MINUTE);
    assert.deepEqual([early.renewal, iso(early.start), iso(early.end)], ["early", iso(end), iso(A(2026, 11, 20, 9, 0))]);
    const late = nextPeriod(end, end + 3 * 86_400_000);
    assert.deepEqual([late.renewal, iso(late.start)], ["late", iso(end + 3 * 86_400_000)]);
    assert.equal(nextPeriod(end, end).renewal, "late", "the end is exclusive: equality is late");
    assert.equal(nextPeriod(null, end).renewal, "initial");
  });
});

describe("CUSTOMER-01 simulated server: only a confirmed, running paid period gives a coffee", () => {
  it("registration needs the Club terms, creates an UNPAID request and grants nothing", () => {
    const { server } = freshServer();
    assert.equal(server.register({ member, emailGiven: true, newsletter: true, termsAccepted: false }).ok, false);
    assert.equal(server.snapshot(), null);
    assert.equal(server.register({ member, emailGiven: true, newsletter: false, termsAccepted: true }).ok, true);
    const s = server.snapshot()!;
    assert.equal(s.state, "pending");
    assert.equal(s.canTakeCoffeeToday, false);
    assert.equal(s.pendingPayment?.kind, "initial");
    assert.equal(server.recordCoffee("espresso").ok, false, "pending payment never redeems");
    assert.equal(server.requestRenewal().ok, false, "nothing to renew before the first payment");
  });

  it("the newsletter consent never changes the membership", () => {
    const a = freshServer();
    const b = freshServer();
    a.server.register({ member, emailGiven: true, newsletter: true, termsAccepted: true });
    b.server.register({ member, emailGiven: true, newsletter: false, termsAccepted: true });
    const [sa, sb] = [a.server.snapshot()!, b.server.snapshot()!];
    assert.deepEqual([sa.state, sa.canTakeCoffeeToday, sa.pendingPayment], [sb.state, sb.canTakeCoffeeToday, sb.pendingPayment]);
    assert.deepEqual([sa.account.newsletter, sb.account.newsletter], [true, false]);
  });

  it("the shop's confirmation starts exactly one calendar month from that instant", () => {
    const { server, clock } = registered();
    clock.advance(2 * 3_600_000);
    const confirmedAt = clock.now();
    assert.equal(server.confirmPayment().ok, true);
    const s = server.snapshot()!;
    assert.equal(s.state, "active");
    assert.equal(s.paidUntil, addCalendarMonth(confirmedAt));
    assert.equal(s.account.member.memberSince, athensDate(confirmedAt));
    assert.equal(server.confirmPayment().ok, false, "nothing left to confirm");
  });

  it("one coffee per Athens calendar day, renewed at Athens midnight (not 24 hours later)", () => {
    const { server, clock } = registered(A(2026, 10, 19, 23, 50));
    server.confirmPayment();
    assert.equal(server.recordCoffee("espresso").ok, true);
    assert.equal(server.recordCoffee("cappuccino").ok, false, "second coffee the same Athens day");
    clock.set(A(2026, 10, 20, 0, 0) - 1000);
    assert.equal(server.recordCoffee("cappuccino").ok, false, "still the same Athens day one second before midnight");
    clock.set(A(2026, 10, 20, 0, 0));
    assert.equal(server.snapshot()!.canTakeCoffeeToday, true);
    assert.equal(server.recordCoffee("cappuccino").ok, true, "a new Athens day, 10 minutes after the previous coffee");
    assert.equal(server.snapshot()!.history.length, 2);
  });

  it("expires at the exact instant (exclusive end) and then refuses", () => {
    const { server, clock } = registered();
    server.confirmPayment();
    const end = server.snapshot()!.paidUntil!;
    clock.set(end - 1000);
    assert.equal(server.snapshot()!.state, "active");
    clock.set(end);
    assert.equal(server.snapshot()!.state, "expired");
    assert.equal(server.recordCoffee("espresso").ok, false);
  });

  it("a pending renewal grants nothing; a late renewal starts at the confirmation, an early one at the current end", () => {
    const { server, clock } = registered();
    server.confirmPayment();
    const end1 = server.snapshot()!.paidUntil!;
    clock.set(end1 - 86_400_000);
    assert.equal(server.requestRenewal().ok, true);
    assert.equal(server.snapshot()!.paidUntil, end1, "the request alone does not extend anything");
    server.confirmPayment();
    const s = server.snapshot()!;
    assert.equal(s.periods[1].renewal, "early");
    assert.equal(s.periods[1].start, end1);
    const end2 = s.paidUntil!;
    clock.set(end2 + 5 * 86_400_000);
    assert.equal(server.snapshot()!.state, "expired");
    server.requestRenewal();
    assert.equal(server.snapshot()!.state, "expired", "expired + pending renewal is still expired");
    assert.equal(server.recordCoffee("espresso").ok, false);
    clock.advance(3_600_000);
    const confirmedAt = clock.now();
    server.confirmPayment();
    const late = server.snapshot()!.periods[2];
    assert.deepEqual([late.renewal, late.start], ["late", confirmedAt]);
  });

  it("a cancelled membership never gives a coffee and cannot be renewed or confirmed (D10 open)", () => {
    const { server } = registered();
    server.confirmPayment();
    assert.equal(server.cancel().ok, true);
    assert.equal(server.snapshot()!.state, "cancelled");
    assert.equal(server.recordCoffee("espresso").ok, false);
    assert.equal(server.requestRenewal().ok, false);
    assert.equal(server.confirmPayment().ok, false);
    assert.equal(server.cancel().ok, false);
  });
});

describe("CUSTOMER-01 registration form rules", () => {
  it("Greek mobiles in any common form become +3069…; everything else is refused (foreign numbers are an open decision)", () => {
    for (const raw of ["6941234567", "694 123 4567", "694-123-45-67", "+30 694 123 4567", "0030 6941234567", "(694) 1234567"]) assert.equal(canonicalGreekMobile(raw), "+306941234567", raw);
    for (const raw of ["2101234567", "694123456", "69412345678", "+44 7700 900123", "69a1234567", ""]) assert.equal(canonicalGreekMobile(raw), null, raw);
    assert.equal(maskMobile("+306941234567"), "69•• ••• •67");
  });

  it("validates name, phone and e-mail with clear Greek messages", () => {
    assert.deepEqual(validateRegistration({ name: "", phone: "", email: "" }, true), { name: MESSAGES.nameMissing, phone: MESSAGES.phoneMissing, email: MESSAGES.emailMissing });
    assert.equal(validateRegistration({ name: "Ελένη", phone: "6941234567", email: "a@b.gr" }, true).name, MESSAGES.nameOneWord);
    assert.equal(validateRegistration({ name: "Ελένη 2", phone: "6941234567", email: "a@b.gr" }, true).name, MESSAGES.nameChars);
    assert.equal(validateRegistration({ name: `Α ${"β".repeat(90)}`, phone: "6941234567", email: "a@b.gr" }, true).name, MESSAGES.nameLong);
    assert.equal(validateRegistration({ name: "Ελένη Μ", phone: "123", email: "a@b.gr" }, true).phone, MESSAGES.phoneInvalid);
    assert.equal(validateRegistration({ name: "Ελένη Μ", phone: "6941234567", email: "a@b" }, true).email, MESSAGES.emailInvalid);
    assert.deepEqual(validateRegistration({ name: "  Ελένη   Μαρκοπούλου ", phone: "+30 694 123 4567", email: "eleni@example.gr" }, true), {});
    assert.deepEqual(validateRegistration({ name: "Jean-Luc O'Brien", phone: "6941234567", email: "" }, false), {}, "D7 switch: e-mail optional");
    assert.equal(validateRegistration({ name: "Ελένη Μ", phone: "6941234567", email: "not-an-email" }, false).email, MESSAGES.emailInvalid, "an optional e-mail is still checked when given");
    assert.equal(firstInvalid({ email: "x", phone: "y" }), "phone");
  });
});

describe("CUSTOMER-01 scenarios", () => {
  it("every scenario is deterministic and its history respects the rules", () => {
    for (const sc of SCENARIOS) {
      const a = JSON.stringify(sc.seed());
      assert.equal(JSON.stringify(sc.seed()), a, `${sc.id} is deterministic`);
      const seed = sc.seed();
      const dates = (seed.redemptions ?? []).map((r) => r.date);
      assert.equal(new Set(dates).size, dates.length, `${sc.id}: at most one coffee per Athens day`);
      for (const r of seed.redemptions ?? []) {
        const [y, m, d] = r.date.split("-").map(Number);
        const [hh, mm] = r.time.split(":").map(Number);
        const at = A(y, m, d, hh, mm);
        assert.ok((seed.periods ?? []).some((p) => p.start <= at && at < p.end), `${sc.id}: ${r.date} ${r.time} inside a paid period`);
        if (seed.cancelledAt) assert.ok(at < seed.cancelledAt, `${sc.id}: no coffee after the cancellation`);
      }
    }
  });

  it("each scenario starts in the state it promises", () => {
    const expected: Record<string, string | null> = { visitor: null, pending: "pending", active: "active", used: "active", expiring: "active", expired: "expired", cancelled: "cancelled" };
    for (const sc of SCENARIOS) {
      const server = new FakeCustomerServer(() => SCENARIO_START, sc.seed());
      assert.equal(server.snapshot()?.state ?? null, expected[sc.id], sc.id);
    }
    const used = new FakeCustomerServer(() => SCENARIO_START, SCENARIOS.find((s) => s.id === "used")!.seed());
    assert.equal(used.snapshot()!.coffeeUsedToday, true);
  });

  it("the customer view shows the exact paid end and no usual coffee without enough history (D-USUAL)", () => {
    const server = new FakeCustomerServer(() => SCENARIO_START, SCENARIOS.find((s) => s.id === "active")!.seed());
    const view = toCustomerView(server.snapshot()!, SCENARIO_START);
    assert.deepEqual([view.paidUntil, view.paidUntilTime, view.daysLeft], ["2026-11-08", "10:42", 20]);
    assert.equal(view.minutesToMidnight, 13 * 60 + 45);
    assert.equal(derivedUsual([]), null);
    assert.equal(derivedUsual(view.history.slice(0, 2).map((h) => ({ ...h, preparation: h.preparation! }))), null);
    assert.ok(view.usual !== null);
  });
});
