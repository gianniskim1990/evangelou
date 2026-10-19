import { historyPreparation, type CoffeeOrder } from "../preview/coffeeConfig";
import type { CustomerMembershipState } from "../preview/customerView";
import type { CoffeeCode, PreviewMember } from "../preview/previewData";
import { athensDate, athensTime, nextPeriod, type PaidPeriod } from "./membershipCalendar";

/*
 * CUSTOMER-01 LOCAL-ONLY customer preview: a SIMULATED membership server.
 *
 * It stands in for the future WordPress / WooCommerce / PMPro side so the
 * customer screens can be explored end to end. Everything lives in memory,
 * on a simulated clock; nothing is sent anywhere, stored, charged or
 * redeemed. Like the real server it is the only place that decides:
 *  - a membership is ACTIVE only while a CONFIRMED paid period runs
 *    (pending payment, expired and cancelled can never take a coffee);
 *  - one free coffee per Europe/Athens calendar day;
 *  - payments are confirmed only by the shop (here: the preview tools), never
 *    by the customer's own screen.
 */

export type ClubRedemption = { date: string; time: string; coffee: CoffeeCode; memberId: string; preparation: CoffeeOrder };

export type Account = {
  /** Synthetic profile shown on the customer screens. */
  member: PreviewMember;
  emailGiven: boolean;
  termsAcceptedAt: number;
  newsletter: boolean;
  registeredAt: number;
};

export type PaymentRequest = { kind: "initial" | "renewal"; createdAt: number };

export type ServerEvent = { seq: number; at: number; text: string; tone: "ok" | "refused" | "info" };

export type MemberSnapshot = {
  account: Account;
  state: CustomerMembershipState;
  today: string;
  coffeeUsedToday: boolean;
  canTakeCoffeeToday: boolean;
  /** Exclusive end of the latest confirmed paid period (null: never paid). */
  paidUntil: number | null;
  periods: PaidPeriod[];
  pendingPayment: PaymentRequest | null;
  cancelledAt: number | null;
  /** Newest first. */
  history: ClubRedemption[];
};

export type ServerSeed = {
  account: Account | null;
  periods?: PaidPeriod[];
  pendingPayment?: PaymentRequest | null;
  cancelledAt?: number | null;
  redemptions?: ClubRedemption[];
};

export type Outcome = { ok: true; text: string } | { ok: false; text: string };

export class FakeCustomerServer {
  private account: Account | null;
  private periods: PaidPeriod[];
  private pending: PaymentRequest | null;
  private cancelledAt: number | null;
  private redemptions: ClubRedemption[];
  private readonly journal: ServerEvent[] = [];
  private readonly listeners = new Set<() => void>();
  private version = 0;
  private readonly now: () => number;

  constructor(now: () => number, seed: ServerSeed = { account: null }) {
    this.now = now;
    this.account = seed.account;
    this.periods = [...(seed.periods ?? [])].sort((a, b) => a.start - b.start);
    this.pending = seed.pendingPayment ?? null;
    this.cancelledAt = seed.cancelledAt ?? null;
    this.redemptions = [...(seed.redemptions ?? [])];
  }

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  };

  getVersion = (): number => this.version;

  /** Call after the simulated clock moved: states derive from the time. */
  touch(): void {
    this.changed();
  }

  events(): readonly ServerEvent[] {
    return this.journal;
  }

  hasAccount(): boolean {
    return this.account !== null;
  }

  /** The membership as only the server may state it, at the current simulated instant. */
  snapshot(): MemberSnapshot | null {
    if (!this.account) return null;
    const now = this.now();
    const today = athensDate(now);
    const state = this.stateAt(now);
    const paidUntil = this.periods.length > 0 ? Math.max(...this.periods.map((p) => p.end)) : null;
    const coffeeUsedToday = this.redemptions.some((r) => r.date === today);
    const history = [...this.redemptions].sort((a, b) => (a.date === b.date ? b.time.localeCompare(a.time) : b.date.localeCompare(a.date)));
    return {
      account: this.account,
      state,
      today,
      coffeeUsedToday,
      canTakeCoffeeToday: state === "active" && !coffeeUsedToday,
      paidUntil,
      periods: [...this.periods],
      pendingPayment: this.pending,
      cancelledAt: this.cancelledAt,
      history,
    };
  }

  private stateAt(now: number): CustomerMembershipState {
    if (this.cancelledAt !== null && this.cancelledAt <= now) return "cancelled";
    // The end instant is exclusive (D1).
    if (this.periods.some((p) => p.start <= now && now < p.end)) return "active";
    return this.periods.length === 0 ? "pending" : "expired";
  }

  // ---- customer side ------------------------------------------------------------------

  /**
   * Registration as the customer submits it before paying. Creates the
   * synthetic account and an UNPAID initial payment request; grants nothing.
   */
  register(input: { member: PreviewMember; emailGiven: boolean; newsletter: boolean; termsAccepted: boolean }): Outcome {
    if (this.account) return this.refuse("Υπάρχει ήδη λογαριασμός σε αυτή την προσομοίωση.");
    if (!input.termsAccepted) return this.refuse("Η εγγραφή απορρίφθηκε: δεν έγινε αποδοχή των όρων του Club.");
    const now = this.now();
    this.account = { member: input.member, emailGiven: input.emailGiven, termsAcceptedAt: now, newsletter: input.newsletter, registeredAt: now };
    this.pending = { kind: "initial", createdAt: now };
    return this.ok(`Εγγραφή ${input.member.name}: συνδρομή σε αναμονή πληρωμής (καμία χρέωση).${input.newsletter ? " Newsletter: ναι." : " Newsletter: όχι."}`, "info");
  }

  /** The customer asks to renew: an UNPAID renewal request. Nothing changes until the shop confirms the payment. */
  requestRenewal(): Outcome {
    const now = this.now();
    if (!this.account) return this.refuse("Δεν υπάρχει λογαριασμός.");
    if (this.stateAt(now) === "cancelled") return this.refuse("Η ανανέωση ακυρωμένης συνδρομής δεν έχει αποφασιστεί (ανοιχτή απόφαση D10).");
    if (this.periods.length === 0) return this.refuse("Δεν υπάρχει συνδρομή για ανανέωση.");
    if (this.pending) return this.ok("Υπάρχει ήδη αίτημα πληρωμής σε αναμονή.", "info");
    this.pending = { kind: "renewal", createdAt: now };
    return this.ok("Αίτημα ανανέωσης: σε αναμονή πληρωμής (καμία χρέωση, καμία νέα περίοδος ακόμη).", "info");
  }

  // ---- shop side (preview tools only) ----------------------------------------------------

  /** The shop confirms the pending payment: the only way a paid period starts (D2 early/late rule). */
  confirmPayment(): Outcome {
    const now = this.now();
    if (!this.account) return this.refuse("Δεν υπάρχει λογαριασμός.");
    if (!this.pending) return this.refuse("Δεν υπάρχει πληρωμή σε αναμονή.");
    if (this.stateAt(now) === "cancelled") return this.refuse("Η συνδρομή είναι ακυρωμένη (ανοιχτή απόφαση D10).");
    const currentEnd = this.periods.length > 0 ? Math.max(...this.periods.map((p) => p.end)) : null;
    const period = nextPeriod(currentEnd, now);
    this.periods.push(period);
    this.pending = null;
    if (period.renewal === "initial") this.account = { ...this.account, member: { ...this.account.member, memberSince: athensDate(period.start) } };
    const how = period.renewal === "early" ? "πρόωρη ανανέωση: ξεκινά στη λήξη της τρέχουσας" : period.renewal === "late" ? "ανανέωση μετά τη λήξη: ξεκινά τώρα" : "πρώτη περίοδος";
    return this.ok(`Πληρωμή επιβεβαιώθηκε από το κατάστημα (${how}). Ισχύει ${athensDate(period.start)} ${athensTime(period.start)} → ${athensDate(period.end)} ${athensTime(period.end)}.`);
  }

  /** The counter records today's coffee: refused unless the membership is active and today's coffee is unused. */
  recordCoffee(coffee: CoffeeCode): Outcome {
    const now = this.now();
    if (!this.account) return this.refuse("Δεν υπάρχει λογαριασμός.");
    const state = this.stateAt(now);
    if (state !== "active") return this.refuse(`Ο καφές απορρίφθηκε: η συνδρομή είναι «${STATE_WORD[state]}».`);
    const date = athensDate(now);
    if (this.redemptions.some((r) => r.date === date)) return this.refuse(`Ο καφές απορρίφθηκε: ο σημερινός καφές (${date}, ώρα Ελλάδας) έχει ήδη καταχωρηθεί.`);
    const member = this.account.member;
    this.redemptions.push({ date, time: athensTime(now), coffee, memberId: member.id, preparation: historyPreparation(member, coffee, date) });
    return this.ok(`Το ταμείο καταχώρησε τον καφέ της ${date} στις ${athensTime(now)}.`);
  }

  /** The shop cancels the membership (what happens to the remaining paid time is open decision D10). */
  cancel(): Outcome {
    const now = this.now();
    if (!this.account) return this.refuse("Δεν υπάρχει λογαριασμός.");
    if (this.cancelledAt !== null) return this.refuse("Η συνδρομή είναι ήδη ακυρωμένη.");
    this.cancelledAt = now;
    this.pending = null;
    return this.ok("Το κατάστημα ακύρωσε τη συνδρομή. Από τώρα δεν δίνεται καφές (ανοιχτή απόφαση D10 για το υπόλοιπο της περιόδου).");
  }

  private ok(text: string, tone: "ok" | "info" = "ok"): Outcome {
    this.log(text, tone);
    return { ok: true, text };
  }

  private refuse(text: string): Outcome {
    this.log(text, "refused");
    return { ok: false, text };
  }

  private log(text: string, tone: ServerEvent["tone"]): void {
    this.journal.push({ seq: this.journal.length + 1, at: this.now(), text, tone });
    this.changed();
  }

  private changed(): void {
    this.version++;
    for (const l of this.listeners) l();
  }
}

const STATE_WORD: Record<CustomerMembershipState, string> = { active: "ενεργή", pending: "σε αναμονή πληρωμής", expired: "ληγμένη", cancelled: "ακυρωμένη" };
