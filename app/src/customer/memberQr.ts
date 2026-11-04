import type { CustomerQrApi, QrIssue, QrStatus } from "./qrApi";

/*
 * STAGE-05D: what the member's card does with the Club QR (pure logic, no
 * DOM). It follows the decision table of wordpress/evangelou-club/docs/
 * member-qr.md ("How the customer app keeps the QR"):
 *
 *   server says                         the card
 *   active, same issued_at kept here    shows the kept QR
 *   none                                POST {} -> shows the new QR
 *   active, nothing (or older) kept     "your QR is on another device / from
 *                                        an earlier visit" + «Νέο QR»
 *   not_member                          the card appears after the payment
 *                                        is confirmed
 *   member_disabled                     the card is not active
 *   off (no route)                      the approved sample card, unchanged
 *
 * A new QR replaces the old one (D6: it changes only when the member asks),
 * so «Νέο QR» always asks for an explicit confirmation first. A screenshot of
 * the shown QR works (D7) until a new one is requested.
 *
 * Where the plaintext lives: ONLY in memory (QrMemory below), never in
 * localStorage / sessionStorage / IndexedDB / cookies / URLs (the customer
 * bundle ban stays). The server returns it once, when it is issued; after a
 * reload the card can no longer show it and offers «Νέο QR» instead (or the
 * member uses the screenshot). Persistent storage on the phone needs a
 * separate decision (docs/member-qr.md, STAGE-05D).
 */

export interface KeptQr {
  token: string;
  issuedAt: string;
}

/** In-memory keeper of the one QR this page received. Cleared on sign-out. */
export interface QrMemory {
  get(): KeptQr | null;
  set(value: KeptQr): void;
  clear(): void;
}

export function createQrMemory(): QrMemory {
  let kept: KeptQr | null = null;
  return {
    get: () => kept,
    set(value) {
      kept = { token: value.token, issuedAt: value.issuedAt };
    },
    clear() {
      kept = null;
    },
  };
}

export type MemberQrState =
  | { phase: "loading" }
  | { phase: "shown"; token: string; issuedAt: string; fresh: boolean }
  /** Active on the server but not kept on this page (another device, an earlier visit, a reload). */
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

/** Plain failure states shared by GET and POST answers. */
function failure(r: QrStatus | QrIssue): MemberQrState | null {
  switch (r.kind) {
    case "not_member":
    case "member_disabled":
    case "signed_out":
    case "forbidden":
    case "unavailable":
    case "error":
      return { phase: r.kind };
    default:
      return null;
  }
}

export class MemberQrFlow {
  private state: MemberQrState = { phase: "loading" };
  private readonly listeners = new Set<() => void>();
  private generation = 0;
  private previous: MemberQrState | null = null;

  private readonly api: CustomerQrApi;
  private readonly memory: QrMemory;

  constructor(api: CustomerQrApi, memory: QrMemory) {
    this.api = api;
    this.memory = memory;
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

  /** Each open (and each retry) starts from GET /me/qr. A newer call makes older replies stale. */
  async open(): Promise<void> {
    const mine = ++this.generation;
    this.set({ phase: "loading" });
    await this.resolve(mine, 0);
  }

  private async resolve(mine: number, concurrentRetries: number): Promise<void> {
    const status = await this.api.status();
    if (mine !== this.generation) return;
    const plain = failure(status);
    if (plain) return this.set(plain);
    if (status.kind === "off") {
      this.memory.clear();
      return this.set({ phase: "off" });
    }
    if (status.kind === "active") {
      const kept = this.memory.get();
      if (kept && kept.issuedAt === status.issuedAt) return this.set({ phase: "shown", token: kept.token, issuedAt: kept.issuedAt, fresh: false });
      // What this page kept is no longer the active QR: forget it, never show a dead code.
      this.memory.clear();
      return this.set({ phase: "elsewhere", reason: "not_here" });
    }
    // none: the first QR of this member. POST {} never replaces one (server-side first-issue-only).
    this.memory.clear();
    const issued = await this.api.issue(false);
    if (mine !== this.generation) return;
    if (issued.kind === "issued") return this.show(issued);
    if (issued.kind === "qr_exists") return this.set({ phase: "elsewhere", reason: "created_elsewhere" });
    if (issued.kind === "concurrent_change") {
      if (concurrentRetries >= 1) return this.set({ phase: "error" });
      return this.resolve(mine, concurrentRetries + 1);
    }
    return this.set(failure(issued) ?? { phase: "error" });
  }

  private show(issued: { token: string; issuedAt: string }): void {
    this.memory.set({ token: issued.token, issuedAt: issued.issuedAt });
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

  /** The member confirmed: POST {"replace": true}. The old QR (and its screenshots) stop working. */
  async confirmReplace(): Promise<void> {
    if (this.state.phase !== "confirm_replace") return;
    const mine = ++this.generation;
    this.previous = null;
    this.memory.clear();
    this.set({ phase: "replacing" });
    const issued = await this.api.issue(true);
    if (mine !== this.generation) return;
    if (issued.kind === "issued") return this.show(issued);
    // Another device changed it at the same moment: read the server again and follow the table.
    if (issued.kind === "concurrent_change") return this.resolve(mine, 1);
    return this.set(failure(issued) ?? { phase: "error" });
  }

  /** Sign-out (or leaving the account): drop the plaintext. */
  forget(): void {
    this.generation++;
    this.memory.clear();
    this.previous = null;
    this.set({ phase: "loading" });
  }
}
