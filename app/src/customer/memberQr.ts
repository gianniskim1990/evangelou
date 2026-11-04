import type { CustomerQrApi, QrIssue, QrStatus } from "./qrApi";

/*
 * STAGE-05D: what the member's card does with the Club QR (pure logic, no
 * DOM). It follows the decision rules of wordpress/evangelou-club/docs/
 * member-qr.md:
 *
 *   server says                         the card
 *   active, same issued_at kept         shows the kept QR (this visit or, round 2,
 *                                        an earlier one: the phone remembers it)
 *   none                                POST {} -> shows and keeps the new QR
 *   active, nothing (or older) kept     "not on this device" + «Νέο QR»; the
 *                                        older record is deleted
 *   not_member / member_disabled        explained; the account's record is deleted
 *   off (no route)                      the approved sample card; everything deleted
 *   signed_out                          everything deleted (the plaintext too)
 *
 * A new QR replaces the old one (D6: it changes only when the member asks),
 * so «Νέο QR» always asks for an explicit confirmation first; the old record
 * is deleted BEFORE the request. A screenshot of the shown QR works (D7).
 *
 * Owner decision 2026-11-04 «Ναι, να τον θυμάται»: the phone remembers the QR
 * between visits. Where: qrStore.ts, the ONLY customer file allowed to touch
 * browser storage (scripts/storage-guard.mjs). This file only sees the
 * QrKeeper interface. The server never re-sends a token.
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
  /** Active on the server but not kept here (another device, a lost record). */
  | { phase: "elsewhere"; reason: "not_here" | "created_elsewhere" }
  | { phase: "confirm_replace"; back: "shown" | "elsewhere" }
  | { phase: "replacing" }
  | { phase: "not_member" }
  | { phase: "member_disabled" }
  | { phase: "off" }
  | { phase: "signed_out" }
  | { phase: "forbidden" }
  | { phase: "unavailable" }
  | { phase: "error" };

export class MemberQrFlow {
  private state: MemberQrState = { phase: "loading" };
  private readonly listeners = new Set<() => void>();
  private generation = 0;
  private previous: MemberQrState | null = null;
  private account: string | null = null;
  /** True once the server answered with anything but "no route" (the panel may then show «Φόρτωση…»). */
  private routeSeen = false;

  private readonly api: CustomerQrApi;
  private readonly keeper: QrKeeper;

  constructor(api: CustomerQrApi, keeper: QrKeeper) {
    this.api = api;
    this.keeper = keeper;
    // Round 4: another tab signed out or replaced the QR: never keep showing the old code here.
    keeper.onExternalChange?.(() => {
      const p = this.state.phase;
      if (p === "shown" || p === "confirm_replace") void this.open();
    });
  }

  getState = (): MemberQrState => this.state;

  /** Whether the QR route has been seen in this page (so a «Φόρτωση…» never hides the sample card when it is off). */
  seenRoute(): boolean {
    return this.routeSeen;
  }

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  };

  private set(next: MemberQrState): void {
    this.state = next;
    for (const l of this.listeners) l();
  }

  /** Each open (and each retry) starts from GET /me/qr for the signed-in `account` (its e-mail). */
  async open(account: string | null = this.account): Promise<void> {
    if (account === null) return;
    const mine = ++this.generation;
    this.account = account;
    this.set({ phase: "loading" });
    await this.resolve(mine, account, 0);
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
    const status = await this.api.status();
    if (mine !== this.generation) return;
    if (status.kind !== "off") this.routeSeen = true;
    const plain = await this.plain(status, account);
    if (mine !== this.generation) return;
    if (plain) return this.set(plain);
    if (status.kind === "off") {
      await this.keeper.forgetAll();
      if (mine !== this.generation) return;
      return this.set({ phase: "off" });
    }
    if (status.kind === "active") {
      // Shown ONLY for the exact issued_at the server reports now; read() deletes any other record.
      const token = await this.keeper.read(account, status.issuedAt);
      if (mine !== this.generation) return;
      if (token !== null) return this.set({ phase: "shown", token, issuedAt: status.issuedAt, fresh: false });
      return this.set({ phase: "elsewhere", reason: "not_here" });
    }
    // none: no active QR on the server, so nothing kept can be valid. POST {} never replaces one.
    await this.keeper.forget(account);
    if (mine !== this.generation) return;
    const issued = await this.api.issue(false);
    if (mine !== this.generation) return;
    if (issued.kind === "issued") return this.show(mine, account, issued);
    if (issued.kind === "qr_exists") return this.set({ phase: "elsewhere", reason: "created_elsewhere" });
    if (issued.kind === "concurrent_change") {
      if (concurrentRetries >= 1) return this.set({ phase: "error" });
      return this.resolve(mine, account, concurrentRetries + 1);
    }
    const shown = await this.plain(issued, account);
    if (mine !== this.generation) return;
    return this.set(shown ?? { phase: "error" });
  }

  private async show(mine: number, account: string, issued: { token: string; issuedAt: string }): Promise<void> {
    await this.keeper.write(account, issued.issuedAt, issued.token);
    if (mine !== this.generation) return;
    this.set({ phase: "shown", token: issued.token, issuedAt: issued.issuedAt, fresh: true });
  }

  /** «Νέο QR»: never replaces anything by itself, it only asks for confirmation. */
  askReplace(): void {
    const s = this.state;
    if (s.phase !== "shown" && s.phase !== "elsewhere") return;
    this.previous = s;
    this.set({ phase: "confirm_replace", back: s.phase });
  }

  cancelReplace(): void {
    if (this.state.phase !== "confirm_replace") return;
    const back = this.previous;
    this.previous = null;
    this.set(back ?? { phase: "elsewhere", reason: "not_here" });
  }

  /** The member confirmed: the old record goes first, then POST {"replace": true}. */
  async confirmReplace(): Promise<void> {
    if (this.state.phase !== "confirm_replace") return;
    const account = this.account;
    if (account === null) return;
    const mine = ++this.generation;
    this.previous = null;
    this.set({ phase: "replacing" });
    await this.keeper.forget(account);
    if (mine !== this.generation) return;
    const issued = await this.api.issue(true);
    if (mine !== this.generation) return;
    if (issued.kind === "issued") return this.show(mine, account, issued);
    // Another device changed it at the same moment: read the server again and follow the rules.
    if (issued.kind === "concurrent_change") return this.resolve(mine, account, 1);
    const shown = await this.plain(issued, account);
    if (mine !== this.generation) return;
    return this.set(shown ?? { phase: "error" });
  }

  /** Sign-out: drop the plaintext everywhere (memory and the phone's record). */
  async forget(): Promise<void> {
    this.generation++;
    this.previous = null;
    this.account = null;
    this.set({ phase: "loading" });
    await this.keeper.forgetAll();
  }
}
