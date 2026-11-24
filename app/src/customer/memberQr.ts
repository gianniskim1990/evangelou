import type { CustomerQrApi, QrIssue, QrStatus } from "./qrApi";

/*
 * STAGE-05D: what the member's card does with the Club QR (pure logic, no
 * DOM). It follows the decision rules of wordpress/evangelou-club/docs/
 * member-qr.md, as changed by owner decisions D21 (2026-11-19: the QR is
 * ALWAYS shown when the membership is active, no «Νέο QR» button) and D22
 * (2026-11-19: the QR is the SAME on every device and never changes when
 * the card is opened; the server derives it, so it can hand it out again).
 *
 *   server says                         the card
 *   active, same issued_at kept         shows the kept QR (this visit or an
 *                                        earlier one: the phone remembers it)
 *   active, nothing (or older) kept     the older record is deleted, then
 *                                        POST {} -> the member's own stable QR
 *                                        (the same one every other device shows)
 *   none                                POST {} -> shows and keeps it
 *   not_member / member_disabled        explained; the account's record is deleted
 *   off (no route)                      «not available yet» (round 17: never a sample code); everything deleted
 *   signed_out                          everything deleted (the plaintext too)
 *   unavailable / error                 short message, automatic retry with
 *                                        backoff, a «Δοκιμάστε ξανά» link
 *
 * D22: this client NEVER asks for a replacement (there is no `replace` in
 * CustomerQrApi any more); only an administrator's revocation gives a member
 * a new code. A QR has no age limit (D6) and a screenshot works (D7).
 *
 * Round 7 (weak Wi-Fi): a re-check that cannot reach the server (error,
 * unavailable, or the request budget spent) never takes a shown QR off the
 * screen: it stays shown and is checked again by the next wake or a silent
 * retry. And a normal open that cannot reach the server shows the QR this
 * phone keeps for the signed-in member, if any, as «unchecked» (no claim that
 * it was verified), and checks it again when the network returns.
 *
 * Round 7: pause() (the member left the card) cancels every pending retry and
 * voids requests in flight; nothing is sent until the card is opened again.
 *
 * Never hammering the endpoint: every request of this flow goes through a
 * rolling one-minute budget (QR_MAX_REQUESTS_PER_MINUTE in all, of which
 * QR_MAX_ISSUES_PER_MINUTE POSTs); failures wait QR_RETRY_DELAYS_MS (5 s,
 * 15 s, 30 s, then every 60 s), or longer when the budget is spent.
 *
 * Owner decision 2026-11-04 «Ναι, να τον θυμάται»: the phone remembers the QR
 * between visits. Where: qrStore.ts, the ONLY customer file allowed to touch
 * browser storage (scripts/storage-guard.mjs). This file only sees the
 * QrKeeper interface.
 */

/** Keeps the member's QR per account, for the exact issued_at only. */
export interface QrKeeper {
  /** The token kept for exactly (account, issuedAt), or null; any OTHER record of the account is deleted. */
  read(account: string, issuedAt: string): Promise<string | null>;
  /** Keeps the token for (account, issuedAt), replacing every earlier record of the account. */
  write(account: string, issuedAt: string, token: string): Promise<void>;
  /** Deletes every record of the account. */
  forget(account: string): Promise<void>;
  /** Deletes every record (sign-out, signed_out, route off). */
  forgetAll(): Promise<void>;
  /** Round 4: another tab removed the phone's record (its sign-out or replace). */
  onExternalChange?(listener: () => void): void;
  /** Round 7: the token kept for the account, whatever its issued_at, WITHOUT deleting anything (offline only). */
  peek?(account: string): Promise<string | null>;
}

interface Kept {
  account: string;
  issuedAt: string;
  token: string;
}

/**
 * The flow's keeper: an in-memory copy (this page) in front of the optional
 * persistent one (qrStore.ts). Without a persistent keeper the QR lives in
 * memory only, as in STAGE-05D round 1.
 */
export function createQrKeeper(persistent: QrKeeper | null = null): QrKeeper & { memory(): Kept | null } {
  let kept: Kept | null = null;
  /** Bumped by every forget: a read still in flight then never puts a token back into memory. */
  let epoch = 0;
  const external = new Set<() => void>();
  const norm = (a: string) => a.trim().toLowerCase();
  persistent?.onExternalChange?.(() => {
    // Another tab signed out (or replaced the QR): drop this tab's copy at once, then tell the flow.
    epoch++;
    kept = null;
    for (const l of external) l();
  });
  return {
    memory: () => kept,
    onExternalChange(listener) {
      external.add(listener);
    },
    async read(account, issuedAt) {
      const a = norm(account);
      const mine = epoch;
      // The phone's store first: it deletes every other record.
      const persisted = persistent ? await persistent.read(a, issuedAt) : null;
      if (mine !== epoch) return null;
      if (kept && kept.account === a && kept.issuedAt === issuedAt) return kept.token;
      // An older QR of this account in memory is dead too.
      if (kept && kept.account === a) kept = null;
      if (persisted !== null) kept = { account: a, issuedAt, token: persisted };
      return persisted;
    },
    async peek(account) {
      const a = norm(account);
      if (kept && kept.account === a) return kept.token;
      const mine = epoch;
      const persisted = persistent?.peek ? await persistent.peek(a) : null;
      return mine === epoch ? persisted : null;
    },
    async write(account, issuedAt, token) {
      kept = { account: norm(account), issuedAt, token };
      if (persistent) await persistent.write(norm(account), issuedAt, token);
    },
    async forget(account) {
      epoch++;
      if (kept?.account === norm(account)) kept = null;
      if (persistent) await persistent.forget(norm(account));
    },
    async forgetAll() {
      epoch++;
      kept = null;
      if (persistent) await persistent.forgetAll();
    },
  };
}

export type MemberQrState =
  | { phase: "loading" }
  | { phase: "shown"; token: string; issuedAt: string; fresh: boolean }
  /** Round 7: the server could not be reached; the QR this phone keeps for the member, not verified now. */
  | { phase: "unchecked"; token: string }
  /**
   * Round 18 (round-17 review, UX): a cold start while the phone keeps THIS member's code: it is shown at once with a
   * quiet «checking» line while GET /me/qr runs (instead of «Φόρτωση…»); the answer then replaces it.
   */
  | { phase: "checking"; token: string }
  | { phase: "not_member" }
  | { phase: "member_disabled" }
  | { phase: "off" }
  | { phase: "signed_out" }
  | { phase: "forbidden" }
  | { phase: "unavailable" }
  | { phase: "error" };

/** Timers and time for the flow (tests pass a fake one). */
export interface QrFlowClock {
  now(): number;
  setTimer(fn: () => void, ms: number): unknown;
  clearTimer(handle: unknown): void;
}

const systemClock: QrFlowClock = {
  now: () => Date.now(),
  setTimer(fn, ms) {
    const t = setTimeout(fn, ms);
    // Node only (tests): a pending retry never keeps the process alive. Browsers have no unref.
    (t as unknown as { unref?: () => void }).unref?.();
    return t;
  },
  clearTimer: (h) => clearTimeout(h as ReturnType<typeof setTimeout>),
};

/** Waits before the 1st, 2nd, 3rd and every later automatic retry after a failure. */
export const QR_RETRY_DELAYS_MS = [5_000, 15_000, 30_000, 60_000] as const;
/** Hard cap: requests (GET + POST) this flow sends in any rolling minute. */
export const QR_MAX_REQUESTS_PER_MINUTE = 8;
/** Hard cap: POST /me/qr this flow sends in any rolling minute. */
export const QR_MAX_ISSUES_PER_MINUTE = 3;
/** A visible-again tab asks the server again at most this often (an error state: QR_RETRY_DELAYS_MS[0]). */
export const QR_WAKE_MIN_INTERVAL_MS = 30_000;
const WINDOW_MS = 60_000;

export class MemberQrFlow {
  private state: MemberQrState = { phase: "loading" };
  private readonly listeners = new Set<() => void>();
  private generation = 0;
  private account: string | null = null;
  /** Requests sent in the last minute (`issue`: a POST). */
  private sent: { at: number; issue: boolean }[] = [];
  /** Failures in a row (the backoff step); reset when a QR is shown. */
  private failures = 0;
  private retryTimer: unknown = null;
  private lastOpen = Number.NEGATIVE_INFINITY;
  /** Round 7: the last check of a shown / unchecked QR could not reach the server (wake() then re-checks after 5 s). */
  private unsure = false;
  /** Round 7: the member left the card (pause()); nothing is sent until open() again. */
  private paused = false;

  private readonly api: CustomerQrApi;
  private readonly keeper: QrKeeper;
  private readonly clock: QrFlowClock;

  constructor(api: CustomerQrApi, keeper: QrKeeper, clock: QrFlowClock = systemClock) {
    this.api = api;
    this.keeper = keeper;
    this.clock = clock;
    // Round 4: another tab signed out or replaced the QR: never keep showing the old code here.
    keeper.onExternalChange?.(() => {
      if (this.paused) return;
      if (this.state.phase === "shown" || this.state.phase === "unchecked" || this.state.phase === "checking") void this.open();
    });
  }

  getState = (): MemberQrState => this.state;

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  };

  private set(next: MemberQrState): void {
    this.state = next;
    for (const l of this.listeners) l();
  }

  /** A QR is on screen (verified earlier, or the phone's own copy while the server cannot be reached). */
  private showing(): boolean {
    return this.state.phase === "shown" || this.state.phase === "unchecked";
  }

  /**
   * Each open (and each retry) starts from GET /me/qr for the signed-in `account` (its e-mail). `quiet`: a QR on
   * screen stays there while the server is asked (the visible-again check, a silent retry), instead of «Φόρτωση…».
   */
  async open(account: string | null = this.account, quiet = false): Promise<void> {
    if (account === null) return;
    const mine = ++this.generation;
    this.account = account;
    this.paused = false;
    this.cancelRetry();
    this.lastOpen = this.clock.now();
    if (!(quiet && this.showing())) {
      this.set({ phase: "loading" });
      // Round 18: the code this phone keeps for THIS account (peek: by the account, never another one's) is shown at
      // once while the server is asked; the server's answer always replaces it.
      void this.showKept(mine, account);
    }
    await this.resolve(mine, account, 0);
  }

  /** Round 18: «checking» with the kept code, only while this open is still waiting for its first answer. */
  private async showKept(mine: number, account: string): Promise<void> {
    const kept = this.keeper.peek ? await this.keeper.peek(account) : null;
    if (mine !== this.generation || kept === null || this.state.phase !== "loading" || account !== this.account) return;
    this.set({ phase: "checking", token: kept });
  }

  /**
   * Round 7: the member left the card. Every pending retry is cancelled and requests in flight are voided (their
   * answers are ignored, so no POST follows them); the state on screen is kept. open() resumes.
   */
  pause(): void {
    this.generation++;
    this.paused = true;
    this.cancelRetry();
  }

  /** The tab became visible again (visibilitychange / focus / online): ask the server again, within the limits. */
  wake(): void {
    if (this.account === null || this.paused) return;
    const phase = this.state.phase;
    if (phase === "loading" || phase === "checking" || phase === "off") return;
    const since = this.clock.now() - this.lastOpen;
    if (phase === "error" || phase === "unavailable") {
      if (since >= QR_RETRY_DELAYS_MS[0]) void this.open();
      return;
    }
    const wait = this.unsure || phase === "unchecked" ? QR_RETRY_DELAYS_MS[0] : QR_WAKE_MIN_INTERVAL_MS;
    if (since >= wait) void this.open(this.account, true);
  }

  /** «Δοκιμάστε ξανά» (error states only): now, but still within the one-minute budget. */
  retry(): void {
    if (this.paused) return;
    const phase = this.state.phase;
    if (phase === "error" || phase === "unavailable") void this.open();
  }

  /** Takes one request from the rolling one-minute budget; false = spent (nothing may be sent). */
  private take(issue: boolean): boolean {
    const now = this.clock.now();
    this.sent = this.sent.filter((r) => now - r.at < WINDOW_MS);
    if (this.sent.length >= QR_MAX_REQUESTS_PER_MINUTE) return false;
    if (issue && this.sent.filter((r) => r.issue).length >= QR_MAX_ISSUES_PER_MINUTE) return false;
    this.sent.push({ at: now, issue });
    return true;
  }

  /** How long until both a GET and a POST fit in the budget again. */
  private budgetWait(): number {
    const now = this.clock.now();
    const live = this.sent.filter((r) => now - r.at < WINDOW_MS);
    const issues = live.filter((r) => r.issue);
    let wait = 0;
    if (live.length >= QR_MAX_REQUESTS_PER_MINUTE - 1) wait = Math.max(wait, live[live.length - (QR_MAX_REQUESTS_PER_MINUTE - 1)].at + WINDOW_MS - now);
    if (issues.length >= QR_MAX_ISSUES_PER_MINUTE) wait = Math.max(wait, issues[issues.length - QR_MAX_ISSUES_PER_MINUTE].at + WINDOW_MS - now);
    return wait;
  }

  private cancelRetry(): void {
    if (this.retryTimer !== null) this.clock.clearTimer(this.retryTimer);
    this.retryTimer = null;
  }

  /** Shows `next` for the current open only; a failure schedules the next automatic try. */
  private settle(mine: number, next: MemberQrState): void {
    if (mine !== this.generation) return;
    this.unsure = false;
    this.set(next);
    if (next.phase === "shown") {
      this.failures = 0;
      return;
    }
    if (next.phase !== "error" && next.phase !== "unavailable") return;
    this.schedule(mine, false);
  }

  /** The next automatic try after a failure (backoff, never earlier than the budget allows). */
  private schedule(mine: number, quiet: boolean): void {
    const step = QR_RETRY_DELAYS_MS[Math.min(this.failures, QR_RETRY_DELAYS_MS.length - 1)];
    this.failures++;
    this.cancelRetry();
    this.retryTimer = this.clock.setTimer(() => {
      this.retryTimer = null;
      if (mine === this.generation) void this.open(this.account, quiet);
    }, Math.max(step, this.budgetWait()));
  }

  /**
   * Round 7, MAJOR-1: the server could not be asked (error / unavailable / budget spent). A QR on screen stays
   * (quiet re-check); a normal open shows the phone's own copy for this member as «unchecked», if there is one.
   * Either way a silent retry is scheduled. Only for the FIRST question of an open: after a 409 the shown code is
   * known to be stale, so the failure is shown as it is.
   */
  private async unreachable(mine: number, account: string, failure: MemberQrState, first: boolean): Promise<void> {
    if (mine !== this.generation) return;
    if (first && this.showing()) {
      this.unsure = true;
      return this.schedule(mine, true);
    }
    const kept = first && this.keeper.peek ? await this.keeper.peek(account) : null;
    if (mine !== this.generation) return;
    if (kept === null) return this.settle(mine, failure);
    this.set({ phase: "unchecked", token: kept });
    this.unsure = true;
    this.schedule(mine, true);
  }

  /** Applies the delete rules of a plain answer; returns the state to show, or null. */
  private async plain(r: QrStatus | QrIssue, account: string): Promise<MemberQrState | null> {
    switch (r.kind) {
      case "signed_out":
        await this.keeper.forgetAll();
        return { phase: "signed_out" };
      case "not_member":
      case "member_disabled":
        await this.keeper.forget(account);
        return { phase: r.kind };
      case "forbidden":
        await this.keeper.forget(account);
        return { phase: "forbidden" };
      case "unavailable":
      case "error":
        // A passing failure says nothing about the QR: keep the record, show no code.
        return { phase: r.kind };
      default:
        return null;
    }
  }

  private async resolve(mine: number, account: string, concurrentRetries: number): Promise<void> {
    const first = concurrentRetries === 0;
    if (!this.take(false)) return this.unreachable(mine, account, { phase: "error" }, first);
    const status = await this.api.status();
    if (mine !== this.generation) return;
    if (status.kind === "error" || status.kind === "unavailable") return this.unreachable(mine, account, { phase: status.kind }, first);
    const plain = await this.plain(status, account);
    if (mine !== this.generation) return;
    if (plain) return this.settle(mine, plain);
    if (status.kind === "off") {
      await this.keeper.forgetAll();
      if (mine !== this.generation) return;
      return this.settle(mine, { phase: "off" });
    }
    if (status.kind === "active") {
      // Shown ONLY for the exact issued_at the server reports now; read() deletes any other record.
      const token = await this.keeper.read(account, status.issuedAt);
      if (mine !== this.generation) return;
      if (token !== null) return this.settle(mine, { phase: "shown", token, issuedAt: status.issuedAt, fresh: false });
    }
    // D22: nothing valid kept on this device (or none on the server): POST {} gives the member's own stable QR.
    // Round 18: the kept code is not the server's any more: it leaves the screen before the POST.
    if (this.state.phase === "checking") this.set({ phase: "loading" });
    return this.issue(mine, account, concurrentRetries);
  }

  private async issue(mine: number, account: string, concurrentRetries: number): Promise<void> {
    if (!this.take(true)) return this.settle(mine, { phase: "error" });
    // The old record goes first, so a dead code is never kept even if the new one does not arrive.
    await this.keeper.forget(account);
    if (mine !== this.generation) return;
    const issued = await this.api.issue();
    if (mine !== this.generation) return;
    if (issued.kind === "issued") return this.show(mine, account, issued);
    // Another device or tab wrote at the same moment: read the server again once, then follow the rules.
    if (issued.kind === "qr_exists" || issued.kind === "concurrent_change") {
      if (concurrentRetries >= 1) return this.settle(mine, { phase: "error" });
      return this.resolve(mine, account, concurrentRetries + 1);
    }
    const shown = await this.plain(issued, account);
    if (mine !== this.generation) return;
    return this.settle(mine, shown ?? { phase: "error" });
  }

  private async show(mine: number, account: string, issued: { token: string; issuedAt: string }): Promise<void> {
    await this.keeper.write(account, issued.issuedAt, issued.token);
    if (mine !== this.generation) return;
    this.settle(mine, { phase: "shown", token: issued.token, issuedAt: issued.issuedAt, fresh: true });
  }

  /** Sign-out: drop the plaintext everywhere (memory and the phone's record); no retry stays scheduled. */
  async forget(): Promise<void> {
    this.generation++;
    this.cancelRetry();
    this.account = null;
    this.unsure = false;
    this.set({ phase: "loading" });
    await this.keeper.forgetAll();
  }
}
