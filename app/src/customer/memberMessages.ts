import { isMessageId, type CustomerMessagesApi, type MessageEntry, type MessageFull } from "./messagesApi";

/*
 * STAGE-08 round 18, owner decision D30 (2026-11-23): what the member app does with its in-app messages (pure logic,
 * no DOM). The list «Μηνύματα» is asked once the member area is open (and again when the page becomes visible, at
 * most every MESSAGES_REFRESH_MS); one message is asked when the member opens it from the list or arrives from a
 * notification tap (#message=<id>). Opening a message moves the member's read mark on the server to it (the newest
 * message opened; it and every older one count as read); the list follows locally.
 *
 *   list     hidden (no route, not a member, disabled member, signed out, refused) · loading · ready · error
 *   view     closed · loading · shown · not_found (no such message, or not this member's: the same answer) · error
 * Nothing is stored on the phone. A newer request always wins over an older one (generation counters).
 */

export type MessagesListState = { phase: "hidden" } | { phase: "loading" } | { phase: "ready"; messages: MessageEntry[]; unread: number } | { phase: "error" };
export type MessageViewState = { phase: "closed" } | { phase: "loading"; id: number } | { phase: "shown"; message: MessageFull } | { phase: "not_found"; id: number } | { phase: "error"; id: number };
export interface MemberMessagesState {
  list: MessagesListState;
  view: MessageViewState;
}

export const MESSAGES_REFRESH_MS = 60_000;

export class MemberMessagesFlow {
  private state: MemberMessagesState = { list: { phase: "loading" }, view: { phase: "closed" } };
  private readonly listeners = new Set<() => void>();
  private listGeneration = 0;
  private viewGeneration = 0;
  private lastList = Number.NEGATIVE_INFINITY;
  private readonly api: CustomerMessagesApi;
  private readonly now: () => number;

  constructor(api: CustomerMessagesApi, now: () => number = () => Date.now()) {
    this.api = api;
    this.now = now;
  }

  getState = (): MemberMessagesState => this.state;

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  };

  private set(next: Partial<MemberMessagesState>): void {
    this.state = { ...this.state, ...next };
    for (const l of this.listeners) l();
  }

  /** Asks for the list now (the member area opened, or «Δοκιμάστε ξανά»). */
  async load(): Promise<void> {
    const mine = ++this.listGeneration;
    this.lastList = this.now();
    if (this.state.list.phase !== "ready") this.set({ list: { phase: "loading" } });
    const r = await this.api.list();
    if (mine !== this.listGeneration) return;
    if (r.kind === "ok") return this.set({ list: { phase: "ready", messages: r.messages, unread: r.unread } });
    if (r.kind === "error" || r.kind === "unavailable") {
      // A passing failure keeps a list already on screen.
      if (this.state.list.phase !== "ready") this.set({ list: { phase: "error" } });
      return;
    }
    this.set({ list: { phase: "hidden" } });
  }

  /** The page became visible again: the list is asked again, at most every MESSAGES_REFRESH_MS. */
  wake(): void {
    if (this.state.list.phase === "hidden") return;
    if (this.now() - this.lastList >= MESSAGES_REFRESH_MS) void this.load();
  }

  /** Opens one message (from the list, or from a notification tap). An id that is not a message number is refused. */
  async open(id: number): Promise<void> {
    const mine = ++this.viewGeneration;
    if (!isMessageId(id)) return this.set({ view: { phase: "not_found", id: 0 } });
    this.set({ view: { phase: "loading", id } });
    const r = await this.api.get(id);
    if (mine !== this.viewGeneration) return;
    if (r.kind === "ok") {
      this.set({ view: { phase: "shown", message: r.message } });
      this.markRead(id);
      return;
    }
    if (r.kind === "not_found" || r.kind === "not_member" || r.kind === "member_disabled" || r.kind === "off" || r.kind === "forbidden") return this.set({ view: { phase: "not_found", id } });
    this.set({ view: { phase: "error", id } });
  }

  /** Back to the list. */
  close(): void {
    this.viewGeneration++;
    this.set({ view: { phase: "closed" } });
  }

  /** Like the server: the read mark is the newest message opened, so it and every older one count as read. */
  private markRead(id: number): void {
    const list = this.state.list;
    if (list.phase !== "ready") return;
    if (!list.messages.some((m) => m.id <= id && m.unread)) return;
    const messages = list.messages.map((m) => (m.id <= id ? { ...m, unread: false } : m));
    this.set({ list: { phase: "ready", messages, unread: messages.filter((m) => m.unread).length } });
  }
}
