import type { CustomerHistoryApi, HistoryCoffee } from "./historyApi";
import { QR_RETRY_DELAYS_MS, QR_WAKE_MIN_INTERVAL_MS } from "./memberQr";

/*
 * Owner decision D24 (round 9): the member card's own last coffees
 * («Ιστορικό»), asked from GET /me/history (historyApi.ts). Pure logic, no DOM.
 * The same rules as the D23 figures flow (memberCoffees.ts):
 *
 * Fail soft: anything but a valid answer leaves the history unknown, and the
 * card keeps «Το ιστορικό δεν είναι ακόμη διαθέσιμο»; nothing is guessed.
 * There is no automatic retry: the next open or wake asks again.
 *
 * Never hammering the endpoint: QR_WAKE_MIN_INTERVAL_MS after an answer,
 * QR_RETRY_DELAYS_MS[0] after a failure, and its own hard cap of
 * HISTORY_MAX_REQUESTS_PER_MINUTE in any rolling minute. A route that does
 * not exist (404, "off": an older plugin), a signed-out session and a
 * refused account are never asked again in this page; "not a member" and
 * "member disabled" are answers, so they wait the normal 30 s.
 */

/** Hard cap: GET /me/history requests in any rolling minute. */
export const HISTORY_MAX_REQUESTS_PER_MINUTE = 3;
const WINDOW_MS = 60_000;

export interface HistoryClock {
  now(): number;
}

export class MemberHistoryFlow {
  private state: HistoryCoffee[] | null = null;
  private readonly listeners = new Set<() => void>();
  private generation = 0;
  private account: string | null = null;
  private off = false;
  private paused = false;
  private inFlight = false;
  private failed = false;
  private lastAsk = Number.NEGATIVE_INFINITY;
  private sent: number[] = [];

  private readonly api: CustomerHistoryApi;
  private readonly clock: HistoryClock;

  constructor(api: CustomerHistoryApi, clock: HistoryClock = { now: () => Date.now() }) {
    this.api = api;
    this.clock = clock;
  }

  /** The member's last coffees (newest first), or null (unknown: «δεν είναι ακόμη διαθέσιμο»). */
  getState = (): HistoryCoffee[] | null => this.state;

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  };

  private set(next: HistoryCoffee[] | null): void {
    if (next === this.state) return;
    this.state = next;
    for (const l of this.listeners) l();
  }

  /** The member area is shown for `account` (its e-mail): ask now, unless an answer is fresh. */
  open(account: string): void {
    if (account !== this.account) {
      // Another account in this page: nothing of the previous one is ever shown.
      this.generation++;
      this.account = account;
      this.inFlight = false;
      this.failed = false;
      this.lastAsk = Number.NEGATIVE_INFINITY;
      this.set(null);
    }
    this.paused = false;
    this.wake();
  }

  /**
   * The member area went away (unmount): wakes do nothing until open() again. An answer in flight still
   * lands (it is this member's own history; React's StrictMode re-runs the effect at once). Another account
   * never sees it: open() with a new account voids it.
   */
  pause(): void {
    this.paused = true;
  }

  /** The card became visible again (visibilitychange / focus / online, or opened from another screen). */
  wake(): void {
    if (this.account === null || this.paused || this.off || this.inFlight) return;
    const wait = this.failed ? QR_RETRY_DELAYS_MS[0] : QR_WAKE_MIN_INTERVAL_MS;
    if (this.clock.now() - this.lastAsk < wait) return;
    void this.ask();
  }

  /** Takes one request from the rolling one-minute budget; false = spent. */
  private take(): boolean {
    const now = this.clock.now();
    this.sent = this.sent.filter((at) => now - at < WINDOW_MS);
    if (this.sent.length >= HISTORY_MAX_REQUESTS_PER_MINUTE) return false;
    this.sent.push(now);
    return true;
  }

  private async ask(): Promise<void> {
    if (!this.take()) return;
    const mine = this.generation;
    this.inFlight = true;
    this.lastAsk = this.clock.now();
    let result;
    try {
      result = await this.api.history();
    } catch {
      result = { kind: "error" } as const;
    }
    if (mine !== this.generation) return;
    this.inFlight = false;
    this.lastAsk = this.clock.now();
    if (result.kind === "ok") {
      this.failed = false;
      this.set(result.items);
      return;
    }
    if (result.kind === "off" || result.kind === "signed_out" || result.kind === "forbidden") this.off = true;
    this.failed = result.kind !== "not_member" && result.kind !== "member_disabled";
    this.set(null);
  }
}
