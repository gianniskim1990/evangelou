import { CLUB_ERROR_MESSAGES } from "../../club/errors";
import { RedemptionIntentTracker, submitRedemption, type RedemptionAttempt } from "../../club/redemptionIntent";
import { authLossOf, type StaffController } from "../staffController";
import { StaffApiError } from "../staffApi";
import { COUNTER_COFFEES, isCounterCoffee } from "./coffeeCatalogue";
import type { FoundMember, LookupOutcome, MemberApi, MemberStatus } from "./memberApi";

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
  | { screen: "member"; member: FoundMember; status: MemberStatus; coffee: string | null; redeem: RedeemPhase; message: string | null };

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
}

export const RETRY_MESSAGE = "Δεν επιβεβαιώθηκε αν καταχωρήθηκε ο καφές. Πατήστε «Δοκιμή ξανά»: το ίδιο αίτημα στέλνεται ξανά, οπότε δεν καταχωρείται δεύτερος καφές.";
export const ALREADY_MESSAGE = "Ο σημερινός καφές του μέλους έχει ήδη καταχωρηθεί.";
export const INACTIVE_MESSAGE = "Η συνδρομή δεν είναι ενεργή αυτή τη στιγμή. Δεν καταχωρήθηκε καφές.";

export class CounterFlow {
  private state: CounterState = { screen: "home" };
  private readonly listeners = new Set<() => void>();
  private readonly deps: CounterFlowDeps;
  private readonly now: () => number;
  readonly timers: CounterTimers;
  private readonly tracker: RedemptionIntentTracker;
  private blockedUntil: number | null = null;
  /** Bumped by every screen change: a reply for an older screen is ignored. */
  private generation = 0;
  private readonly unsubscribe: () => void;

  constructor(deps: CounterFlowDeps) {
    this.deps = deps;
    this.now = deps.now ?? (() => Date.now());
    this.timers = deps.timers ?? { setInterval: (fn, ms) => globalThis.setInterval(fn, ms), clearInterval: (id) => globalThis.clearInterval(id as ReturnType<typeof setInterval>) };
    this.tracker = deps.tracker ?? new RedemptionIntentTracker();
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
    if (next.screen !== "member") this.tracker.reset();
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

  openScan(notice: ScanNotice | null = null): void {
    if (!this.ready()) return;
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
    this.applyLookup(result.value);
  }

  private applyLookup(outcome: LookupOutcome): void {
    switch (outcome.kind) {
      case "found":
        this.go({ screen: "member", member: outcome.member, status: outcome.status, coffee: null, redeem: "idle", message: null });
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
    const s = this.state;
    if (s.screen !== "member" || s.status !== "active" || s.redeem !== "idle" || !isCounterCoffee(code)) return;
    this.patch({ ...s, coffee: code, message: null });
  }

  /** «Καταχώρηση καφέ» and «Δοκιμή ξανά» (the same open intent, the same request_id). */
  async redeem(): Promise<void> {
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
}
