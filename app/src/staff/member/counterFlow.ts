import { CLUB_ERROR_MESSAGES } from "../../club/errors";
import { RedemptionIntentTracker, submitRedemption, type RedemptionAttempt } from "../../club/redemptionIntent";
import { authLossOf, type StaffController } from "../staffController";
import { StaffApiError } from "../staffApi";
import type { Catalogue } from "../../club/preparationContract";
import { COUNTER_COFFEES, isCounterCoffee } from "./coffeeCatalogue";
import type { FoundMember, LookupOutcome, MemberApi, MemberStatus, PreparedRedeemResult, RecentCoffee } from "./memberApi";
import { newRequestId } from "../../club/requestId";
import {
  EMPTY_ORDER,
  choose as chooseOption,
  clearOptional as clearOptionalGroup,
  fromPrefill,
  missingSteps,
  orderKey,
  preparedBody,
  selectCoffee,
  setNote as setOrderNote,
  summaryLine,
  toggleQuickNote as toggleOrderQuickNote,
  visibleOrderGroups,
  type PreparedOrder,
} from "./preparedOrder";

/*
 * STAGE-05D: the REAL counter flow (scan -> member -> coffee -> recorded).
 *
 * - Runs ONLY while the staff session is verified ready AND GET /session says
 *   features.qr_lookup === true (StaffApp shows nothing of it otherwise).
 * - Every request goes through StaffController.runProtected(): a trustworthy
 *   401 / 403 ends the session, and a reply that arrives after a lock, logout
 *   or expiry is dropped. Leaving "ready" clears the member from this flow.
 * - Staff see the first name and active / used_today / inactive only (D8).
 * - One request_id per "give this member this coffee" (RedemptionIntentTracker):
 *   an unknown outcome keeps it, and «Δοκιμή ξανά» resends the SAME id, so a
 *   retry can never record a second coffee. The coffee cannot be changed while
 *   such an outcome is unresolved.
 * - The tablet only scans: there is no revoke (D10).
 * - D9: after 429 the scan stays closed until Retry-After has passed.
 *
 * Owner decision D24 (round 9): when the lookup says `prepared` and the approved catalogue is loaded, the
 * coffee is chosen step by step (preparedOrder.ts) and recorded through POST …/prepared-redeem: one request
 * id per exact order (coffee, every choice, quick notes, note), kept while the outcome is unknown, so
 * «Δοκιμή ξανά» can never record a second coffee; any change of the order (possible only once the outcome is
 * known) gets a new id. The member's last coffees come with the lookup; «Το ίδιο με την τελευταία φορά»
 * pre-fills only what the catalogue still offers and lands on the next step or the review: staff always
 * confirm. Without `prepared` (migration 004 not applied, an older server, no catalogue) the v1 flow above
 * is used unchanged, and «Το ίδιο» picks the coffee only. A `409 prepared_unavailable` switches this member
 * to v1 at once (nothing was recorded).
 */

export type ScanNotice =
  | { kind: "not_found" }
  | { kind: "invalid_qr" }
  | { kind: "rate_limited" }
  | { kind: "unavailable" }
  | { kind: "offline" }
  | { kind: "error" }
  | { kind: "recorded"; firstName: string | null; coffeeLabel: string };

export type RedeemPhase = "idle" | "busy" | "retry";

/** m:ss until `until` (rounded up to the second): the D9 wait shown on the scan screen. */
export function countdown(until: number, now: number): string {
  const total = Math.max(0, Math.ceil((until - now) / 1000));
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, "0")}`;
}

export type CounterState =
  | { screen: "home" }
  | { screen: "scan"; busy: boolean; notice: ScanNotice | null; blockedUntil: number | null }
  | {
      screen: "member";
      member: FoundMember;
      status: MemberStatus;
      /** v1: the chosen coffee code. */
      coffee: string | null;
      redeem: RedeemPhase;
      message: string | null;
      /** D24: the coffee is chosen step by step and recorded with its preparation. */
      prepared: boolean;
      /** D24: the member's own last coffees (null: none received). */
      recent: RecentCoffee[] | null;
      /** D24: the order being built (prepared only). */
      order: PreparedOrder;
      /** D24: a group being changed from the review screen. */
      editing: string | null;
    };

/** D24: which screen of the prepared order is showing. */
export type OrderStage = { kind: "coffee" } | { kind: "step"; group: string } | { kind: "review" };

export interface CounterTimers {
  setInterval(fn: () => void, ms: number): unknown;
  clearInterval(id: unknown): void;
}

export interface CounterFlowDeps {
  controller: Pick<StaffController, "runProtected" | "subscribe" | "getState">;
  api: MemberApi;
  now?: () => number;
  /** The screens' one-second redraw during the D9 wait (injected in tests). */
  timers?: CounterTimers;
  tracker?: RedemptionIntentTracker;
  /** D24: request ids of prepared orders (injected in tests). */
  makeId?: () => string;
}

export const RETRY_MESSAGE = "Δεν επιβεβαιώθηκε αν καταχωρήθηκε ο καφές. Πατήστε «Δοκιμή ξανά»: το ίδιο αίτημα στέλνεται ξανά, οπότε δεν καταχωρείται δεύτερος καφές.";
export const ALREADY_MESSAGE = "Ο σημερινός καφές του μέλους έχει ήδη καταχωρηθεί.";
export const INACTIVE_MESSAGE = "Η συνδρομή δεν είναι ενεργή αυτή τη στιγμή. Δεν καταχωρήθηκε καφές.";
/** D24 */
export const REPLAYED_MESSAGE = "Αυτή η παραγγελία είχε ήδη καταχωρηθεί (η προηγούμενη προσπάθεια πέρασε). Ελέγξτε αν ο καφές δόθηκε στον πελάτη πριν φτιάξετε έναν. Δεν καταχωρείται δεύτερος.";
export const EARLIER_DAY_MESSAGE = "Αυτή η παραγγελία είχε καταχωρηθεί άλλη μέρα. Μη φτιάξετε καφέ με αυτήν· σαρώστε ξανά τον κωδικό του πελάτη.";
export const CATALOGUE_CHANGED_MESSAGE = "Ο κατάλογος καφέδων άλλαξε μόλις τώρα. Ελέγξτε την επιλογή και πατήστε ξανά «Καταχώρηση καφέ».";
export const NOT_ACCEPTED_MESSAGE = "Η επιλογή δεν έγινε δεκτή από τον διακομιστή. Διαλέξτε ξανά τον καφέ.";
export const INTEGRITY_MESSAGE = "Τα στοιχεία της καταχώρησης δεν είναι σωστά. Μη δώσετε καφέ με αυτή την παραγγελία και ενημερώστε τον υπεύθυνο.";
export const PREPARED_OFF_MESSAGE = "Η καταγραφή της προετοιμασίας δεν είναι διαθέσιμη αυτή τη στιγμή. Διαλέξτε μόνο τον καφέ.";

export class CounterFlow {
  private state: CounterState = { screen: "home" };
  private readonly listeners = new Set<() => void>();
  private readonly deps: CounterFlowDeps;
  private readonly now: () => number;
  readonly timers: CounterTimers;
  private readonly tracker: RedemptionIntentTracker;
  private blockedUntil: number | null = null;
  /** D24: the approved catalogue (loaded once per page; null = the till uses v1). */
  private catalogue: Catalogue | null = null;
  private catalogueTried = false;
  /** D24: the request id of the prepared order being recorded (kept while its outcome is unknown). */
  private preparedIntent: { key: string; requestId: string } | null = null;
  private readonly makeId: () => string;
  /** Bumped by every screen change: a reply for an older screen is ignored. */
  private generation = 0;
  private readonly unsubscribe: () => void;

  constructor(deps: CounterFlowDeps) {
    this.deps = deps;
    this.now = deps.now ?? (() => Date.now());
    this.timers = deps.timers ?? { setInterval: (fn, ms) => globalThis.setInterval(fn, ms), clearInterval: (id) => globalThis.clearInterval(id as ReturnType<typeof setInterval>) };
    this.tracker = deps.tracker ?? new RedemptionIntentTracker();
    this.makeId = deps.makeId ?? newRequestId;
    // Fail closed: the moment the session is not ready, the member is gone.
    // The same when a status check says the QR lookup is no longer ready.
    this.unsubscribe = deps.controller.subscribe(() => {
      if (!this.ready() && this.state.screen !== "home") this.go({ screen: "home" });
    });
  }

  dispose(): void {
    this.unsubscribe();
  }

  getState = (): CounterState => this.state;

  /** The flow's clock (the screens use it for the D9 wait, so tests and screen agree). */
  clock(): number {
    return this.now();
  }

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  };

  private go(next: CounterState): void {
    if (next.screen !== "member") {
      this.tracker.reset();
      this.preparedIntent = null;
    }
    this.generation++;
    this.state = next;
    this.emit();
  }

  private patch(next: CounterState): void {
    this.state = next;
    this.emit();
  }

  private emit(): void {
    for (const l of this.listeners) l();
  }

  /** A verified session AND the server's features.qr_lookup === true. */
  private ready(): boolean {
    const s = this.deps.controller.getState();
    return s.phase === "ready" && s.session.features.qrLookup === true;
  }

  private blocked(): number | null {
    return this.blockedUntil !== null && this.now() < this.blockedUntil ? this.blockedUntil : null;
  }

  /** D24: the catalogue the screens draw the steps from (null = v1). */
  getCatalogue(): Catalogue | null {
    return this.catalogue;
  }

  /** D24: loads the approved catalogue once (again only when a lookup says `prepared` and none is loaded). */
  private async loadCatalogue(force: boolean): Promise<void> {
    if (this.catalogue !== null || (this.catalogueTried && !force) || !this.ready()) return;
    this.catalogueTried = true;
    const result = await this.deps.controller.runProtected((signal) => this.deps.api.catalogue(signal));
    if (result.status === "ok" && result.value !== null) this.catalogue = result.value;
  }

  openScan(notice: ScanNotice | null = null): void {
    if (!this.ready()) return;
    void this.loadCatalogue(false);
    const blockedUntil = this.blocked();
    this.go({ screen: "scan", busy: false, notice: blockedUntil !== null && notice === null ? { kind: "rate_limited" } : notice, blockedUntil });
  }

  home(): void {
    this.go({ screen: "home" });
  }

  /** A code from the camera or the hand scanner. */
  async submitScan(code: string): Promise<void> {
    const s = this.state;
    if (s.screen !== "scan" || s.busy || !this.ready()) return;
    const blockedUntil = this.blocked();
    if (blockedUntil !== null) {
      this.patch({ ...s, notice: { kind: "rate_limited" }, blockedUntil });
      return;
    }
    const mine = this.generation;
    this.patch({ ...s, busy: true, notice: null });
    const result = await this.deps.controller.runProtected((signal) => this.deps.api.lookupQr(code, signal));
    if (mine !== this.generation || this.state.screen !== "scan") return;
    if (result.status === "stale" || result.status === "auth_lost") return;
    if (result.status === "error") {
      const kind = result.error instanceof StaffApiError ? result.error.kind : "invalid_response";
      // Aborted by a newer screen state: never leave the scan stuck on «Αναζήτηση…».
      if (kind === "aborted") {
        this.patch({ ...s, busy: false, notice: null });
        return;
      }
      this.patch({ screen: "scan", busy: false, notice: { kind: kind === "network" || kind === "timeout" ? "offline" : "error" }, blockedUntil: null });
      return;
    }
    if (result.value.kind === "found" && result.value.history?.prepared === true && this.catalogue === null) {
      await this.loadCatalogue(true);
      if (mine !== this.generation || this.state.screen !== "scan") return;
    }
    this.applyLookup(result.value);
  }

  private applyLookup(outcome: LookupOutcome): void {
    switch (outcome.kind) {
      case "found":
        this.go({
          screen: "member",
          member: outcome.member,
          status: outcome.status,
          coffee: null,
          redeem: "idle",
          message: null,
          prepared: outcome.history?.prepared === true && this.catalogue !== null,
          recent: outcome.history?.recent ?? null,
          order: EMPTY_ORDER,
          editing: null,
        });
        return;
      case "rate_limited":
        // Retry-After: 0 means "now": nothing to wait for.
        this.blockedUntil = outcome.retryAfterSeconds > 0 ? this.now() + outcome.retryAfterSeconds * 1000 : null;
        this.patch({ screen: "scan", busy: false, notice: { kind: "rate_limited" }, blockedUntil: this.blockedUntil });
        return;
      default:
        this.patch({ screen: "scan", busy: false, notice: { kind: outcome.kind }, blockedUntil: null });
    }
  }

  chooseCoffee(code: string): void {
    const p = this.editable();
    if (p) {
      this.patch({ ...p, order: selectCoffee(this.catalogue!, p.order, code), editing: null, message: null });
      return;
    }
    const s = this.state;
    if (s.screen !== "member" || s.status !== "active" || s.redeem !== "idle" || !isCounterCoffee(code)) return;
    if (s.prepared) return;
    this.patch({ ...s, coffee: code, message: null });
  }

  /** D24: the member screen the order is on (coffee, the next step, or the review). */
  stage(): OrderStage {
    const s = this.state;
    const catalogue = this.catalogue;
    if (s.screen !== "member" || catalogue === null || s.order.coffee === null) return { kind: "coffee" };
    if (s.editing !== null && visibleOrderGroups(catalogue, s.order).some((g) => g.id === s.editing)) return { kind: "step", group: s.editing };
    const next = missingSteps(catalogue, s.order)[0];
    return next ? { kind: "step", group: next.id } : { kind: "review" };
  }

  /** The prepared member screen, only while it may still be changed (active, nothing in flight or unresolved). */
  private editable(): (Extract<CounterState, { screen: "member" }> & { prepared: true }) | null {
    const s = this.state;
    return s.screen === "member" && s.prepared && s.status === "active" && s.redeem === "idle" && this.catalogue !== null ? (s as Extract<CounterState, { screen: "member" }> & { prepared: true }) : null;
  }

  /** D24: one tap on a step's option (the next step follows by itself). */
  choose(group: string, option: string): void {
    const s = this.editable();
    if (!s) return;
    const order = chooseOption(this.catalogue!, s.order, group, option);
    this.patch({ ...s, order, editing: s.editing === group ? null : s.editing, message: null });
  }

  /** D24: «Αλλαγή» on a review row. */
  edit(group: string): void {
    const s = this.editable();
    if (!s || !visibleOrderGroups(this.catalogue!, s.order).some((g) => g.id === group)) return;
    this.patch({ ...s, editing: group });
  }

  /** D24: «Πίσω»: leave a change, or undo the last step, or go back to the coffee choice. */
  back(): void {
    const s = this.editable();
    if (!s) return;
    if (s.editing !== null) {
      this.patch({ ...s, editing: null });
      return;
    }
    const answered = visibleOrderGroups(this.catalogue!, s.order).filter((g) => g.required && s.order.choices[g.id] !== undefined);
    const last = answered[answered.length - 1];
    if (!last) {
      this.patch({ ...s, order: EMPTY_ORDER, message: null });
      return;
    }
    const choices = { ...s.order.choices };
    delete choices[last.id];
    this.patch({ ...s, order: selectCoffee(this.catalogue!, { ...s.order, choices }, s.order.coffee!), message: null });
  }

  /** D24: start the order again («Άλλος καφές»). */
  startOver(): void {
    const s = this.editable();
    if (s) this.patch({ ...s, order: EMPTY_ORDER, editing: null, message: null });
  }

  clearOptional(group: string): void {
    const s = this.editable();
    if (s) this.patch({ ...s, order: clearOptionalGroup(this.catalogue!, s.order, group) });
  }

  toggleQuickNote(id: string): void {
    const s = this.editable();
    if (s) this.patch({ ...s, order: toggleOrderQuickNote(this.catalogue!, s.order, id) });
  }

  setNote(note: string): void {
    const s = this.editable();
    if (s) this.patch({ ...s, order: setOrderNote(this.catalogue!, s.order, note) });
  }

  /** D24 «Το ίδιο με την τελευταία φορά»: a pre-fill to review, never a recorded coffee. */
  sameAsLast(): void {
    const s = this.state;
    if (s.screen !== "member" || s.redeem !== "idle" || s.status !== "active") return;
    const prefill = s.recent?.[0]?.prefill ?? null;
    if (prefill === null) return;
    if (s.prepared && this.catalogue !== null) {
      this.patch({ ...s, order: fromPrefill(this.catalogue, prefill), editing: null, message: null });
      return;
    }
    if (isCounterCoffee(prefill.coffeeCode)) this.patch({ ...s, coffee: prefill.coffeeCode, message: null });
  }

  /** «Καταχώρηση καφέ» and «Δοκιμή ξανά» (the same open intent, the same request_id). */
  async redeem(): Promise<void> {
    const current = this.state;
    if (current.screen === "member" && current.prepared) {
      await this.redeemPrepared();
      return;
    }
    const s = this.state;
    if (s.screen !== "member" || s.status !== "active" || s.coffee === null || s.redeem === "busy" || !this.ready()) return;
    const mine = this.generation;
    const payload = { memberId: s.member.memberId, benefitType: "free_coffee" as const, coffeeCode: s.coffee };
    this.patch({ ...s, redeem: "busy", message: null });
    const result = await this.deps.controller.runProtected(async () => {
      const attempt = await submitRedemption(this.deps.api.redeem, this.tracker, payload);
      // A real 401 / 403 must end the session, not show up as a retry.
      if ((attempt.kind === "retry_needed" || attempt.kind === "rejected") && authLossOf(attempt.error) !== null) throw attempt.error;
      return attempt;
    });
    const now = this.state;
    if (mine !== this.generation || now.screen !== "member") return;
    if (result.status === "stale" || result.status === "auth_lost") return;
    if (result.status === "error") {
      this.patch({ ...now, redeem: "retry", message: RETRY_MESSAGE });
      return;
    }
    this.applyRedeem(now, result.value);
  }

  private applyRedeem(s: Extract<CounterState, { screen: "member" }>, attempt: RedemptionAttempt): void {
    switch (attempt.kind) {
      case "redeemed": {
        const label = COUNTER_COFFEES.find((c) => c.code === s.coffee)?.label ?? "";
        this.go({ screen: "scan", busy: false, notice: { kind: "recorded", firstName: s.member.firstName, coffeeLabel: label }, blockedUntil: this.blocked() });
        return;
      }
      case "already_redeemed":
        this.patch({ ...s, status: "used_today", coffee: null, redeem: "idle", message: ALREADY_MESSAGE });
        return;
      case "retry_needed":
        this.patch({ ...s, redeem: "retry", message: attempt.error.code === "rate_limited" ? CLUB_ERROR_MESSAGES.rate_limited + " " + RETRY_MESSAGE : RETRY_MESSAGE });
        return;
      case "busy":
        // Cannot happen (one attempt at a time is enforced above); never leave the button stuck.
        this.patch({ ...s, redeem: "idle" });
        return;
      case "rejected": {
        const code = attempt.error.code;
        if (code === "membership_inactive") {
          this.patch({ ...s, status: "inactive", coffee: null, redeem: "idle", message: INACTIVE_MESSAGE });
        } else if (code === "member_not_found") {
          this.go({ screen: "scan", busy: false, notice: { kind: "not_found" }, blockedUntil: this.blocked() });
        } else {
          this.patch({ ...s, coffee: code === "invalid_coffee" ? null : s.coffee, redeem: "idle", message: CLUB_ERROR_MESSAGES[code] });
        }
      }
    }
  }

  /** D24: records the prepared order; one request id per exact order, the same one for «Δοκιμή ξανά». */
  private async redeemPrepared(): Promise<void> {
    const s = this.state;
    const catalogue = this.catalogue;
    if (s.screen !== "member" || !s.prepared || catalogue === null || s.status !== "active" || s.redeem === "busy" || !this.ready()) return;
    const key = orderKey(catalogue, s.member.memberId, s.order);
    if (this.preparedIntent === null || this.preparedIntent.key !== key) this.preparedIntent = { key, requestId: this.makeId() };
    const intent = this.preparedIntent;
    const body = preparedBody(catalogue, s.order, intent.requestId);
    if (body === null) return;
    const mine = this.generation;
    this.patch({ ...s, redeem: "busy", message: null });
    const result = await this.deps.controller.runProtected((signal) => this.deps.api.redeemPrepared(s.member.memberId, body, signal));
    const now = this.state;
    if (mine !== this.generation || now.screen !== "member") return;
    if (result.status === "stale" || result.status === "auth_lost") return;
    if (result.status === "error") {
      this.patch({ ...now, redeem: "retry", message: RETRY_MESSAGE });
      return;
    }
    this.applyPrepared(now, result.value, intent.requestId, summaryLine(catalogue, now.order));
  }

  private applyPrepared(s: Extract<CounterState, { screen: "member" }>, reply: PreparedRedeemResult, requestId: string, summary: string): void {
    const close = () => {
      if (this.preparedIntent?.requestId === requestId) this.preparedIntent = null;
    };
    switch (reply.kind) {
      case "redeemed":
        close();
        this.go({ screen: "scan", busy: false, notice: { kind: "recorded", firstName: s.member.firstName, coffeeLabel: summary }, blockedUntil: this.blocked() });
        return;
      case "replayed":
        close();
        this.patch({ ...s, status: "used_today", redeem: "idle", message: reply.instruction === "do_not_prepare_for_today" ? EARLIER_DAY_MESSAGE : REPLAYED_MESSAGE });
        return;
      case "unresolved":
        this.patch({ ...s, redeem: "retry", message: RETRY_MESSAGE });
        return;
      case "integrity_failure":
        close();
        this.patch({ ...s, status: "used_today", redeem: "idle", message: INTEGRITY_MESSAGE });
        return;
      case "prepared_unavailable":
        // Nothing was read or written: this member continues with the coffee type only (v1).
        close();
        this.patch({ ...s, prepared: false, coffee: isCounterCoffee(s.order.coffee) ? s.order.coffee : null, redeem: "idle", message: PREPARED_OFF_MESSAGE });
        return;
      case "refused": {
        close();
        const code = reply.code;
        if (code === "benefit_already_redeemed") {
          this.patch({ ...s, status: "used_today", redeem: "idle", message: ALREADY_MESSAGE });
        } else if (code === "membership_inactive") {
          this.patch({ ...s, status: "inactive", redeem: "idle", message: INACTIVE_MESSAGE });
        } else if (code === "member_not_found") {
          this.go({ screen: "scan", busy: false, notice: { kind: "not_found" }, blockedUntil: this.blocked() });
        } else if (code === "catalogue_version_stale" || code === "catalogue_not_authorised") {
          // Ask for the catalogue again; the order is checked against it (anything retired must be chosen again).
          this.catalogue = null;
          this.patch({ ...s, redeem: "busy", message: null });
          void this.reloadCatalogueFor(s);
        } else {
          this.patch({ ...s, order: EMPTY_ORDER, editing: null, redeem: "idle", message: NOT_ACCEPTED_MESSAGE });
        }
      }
    }
  }

  private async reloadCatalogueFor(s: Extract<CounterState, { screen: "member" }>): Promise<void> {
    const mine = this.generation;
    await this.loadCatalogue(true);
    const now = this.state;
    if (mine !== this.generation || now.screen !== "member") return;
    const catalogue = this.catalogue;
    if (catalogue === null) {
      this.patch({ ...now, prepared: false, coffee: isCounterCoffee(s.order.coffee) ? s.order.coffee : null, redeem: "idle", message: PREPARED_OFF_MESSAGE });
      return;
    }
    const order = s.order.coffee === null ? EMPTY_ORDER : selectCoffee(catalogue, s.order, s.order.coffee);
    this.patch({ ...now, order, editing: null, redeem: "idle", message: CATALOGUE_CHANGED_MESSAGE });
  }
}
