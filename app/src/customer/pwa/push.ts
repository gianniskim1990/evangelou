/*
 * STAGE-08 round 12 (owner decision D26 part 2): «Θέλω ειδοποιήσεις» on the member card.
 *
 * When the control is offered (otherwise: nothing, or a short explanation):
 *   - the page sent the VAPID public key (push switched on, keys usable) and GET /me/push says "ready";
 *   - plain permalinks: the page has no service worker, so no push: the control is replaced by a short explanation;
 *   - iPhone / iPad: only when the app runs from the home screen (standalone); in the browser a hint to install first;
 *   - the browser has a service worker, Push and Notifications; otherwise nothing.
 *
 * Consent: ONLY the member's tap (enable()) asks the browser for permission (Notification.requestPermission()); nothing
 * on load ever does. Then pushManager.subscribe() on the round-11 worker's registration, then POST /me/push with
 * consent: true (the server records the time). «Διακοπή ειδοποιήσεων» (disable()) and signing out (signOut()) drop the
 * browser's subscription and DELETE it on the server. The opt-in state is the browser's own subscription: nothing is
 * kept in browser storage.
 *
 * On open, an existing subscription (permission still granted) is sent again so the server row stays current; one made
 * for other keys (the shop changed its keys) is replaced without asking again, because the member's consent and the
 * browser's permission are unchanged. 409 endpoint_in_use (this browser's subscription is another account's active row):
 * the subscription is replaced by a new one and sent once more.
 */
import type { CustomerPushApi, PushWrite } from "../pushApi";

export type PushPhase = "hidden" | "plain" | "ios_install" | "idle" | "asking" | "on" | "denied" | "error" | "stopping";

export interface PushState {
  phase: PushPhase;
}

/** The parts of a browser PushSubscription the flow uses. */
export interface SubscriptionLike {
  endpoint: string;
  options?: { applicationServerKey?: ArrayBuffer | null };
  toJSON(): { endpoint?: string; keys?: Record<string, string> };
  unsubscribe(): Promise<boolean>;
}

export interface PushManagerLike {
  getSubscription(): Promise<SubscriptionLike | null>;
  subscribe(options: { userVisibleOnly: true; applicationServerKey: Uint8Array }): Promise<SubscriptionLike>;
}

export interface PushEnv {
  /** The VAPID public key from the page (base64url), or "" when push is off. */
  publicKey: string;
  /** Whether the page has a service worker at all (pretty permalinks). */
  worker: boolean;
  /** Running from the home screen. */
  standalone: () => boolean;
  /** iPhone / iPad (any browser). */
  ios: boolean;
  /** window.Notification, or null when the browser has none. */
  notification: { permission: string; requestPermission(): Promise<string> } | null;
  /** The worker registration's push manager, or null (no worker, no Push). */
  pushManager: () => Promise<PushManagerLike | null>;
  /** Whether the browser has Push at all (PushManager in window). */
  pushSupported: boolean;
}

const KEY_RE = /^[A-Za-z0-9_-]{87}$/;
const SUB_KEY_RE = /^[A-Za-z0-9_-]+={0,2}$/;

/** The VAPID public key as the bytes pushManager.subscribe() wants, or null when it is not a 65-byte P-256 point. */
export function keyBytes(key: string): Uint8Array | null {
  if (!KEY_RE.test(key)) return null;
  const b64 = key.replace(/-/g, "+").replace(/_/g, "/") + "=";
  let bin: string;
  try {
    bin = atob(b64);
  } catch {
    return null;
  }
  if (bin.length !== 65 || bin.charCodeAt(0) !== 4) return null;
  const out = new Uint8Array(65);
  for (let i = 0; i < 65; i++) out[i] = bin.charCodeAt(i);
  return out;
}

/** Was this subscription made for these key bytes? (Unknown = yes: older browsers do not expose it.) */
export function sameKey(sub: SubscriptionLike, key: Uint8Array): boolean {
  const own = sub.options?.applicationServerKey;
  if (!own) return true;
  const bytes = new Uint8Array(own);
  return bytes.length === key.length && bytes.every((b, i) => b === key[i]);
}

/** The subscription as POST /me/push wants it, or null when the browser gave something else. */
export function subscriptionJson(sub: SubscriptionLike): { endpoint: string; keys: { p256dh: string; auth: string } } | null {
  const json = sub.toJSON();
  const endpoint = typeof json.endpoint === "string" ? json.endpoint : sub.endpoint;
  const p256dh = json.keys?.p256dh;
  const auth = json.keys?.auth;
  if (typeof endpoint !== "string" || !endpoint.startsWith("https://") || typeof p256dh !== "string" || typeof auth !== "string") return null;
  if (!SUB_KEY_RE.test(p256dh) || !SUB_KEY_RE.test(auth)) return null;
  return { endpoint, keys: { p256dh, auth } };
}

/** What the control shows before the server is asked (pure). */
export function initialPhase(env: Pick<PushEnv, "publicKey" | "worker" | "standalone" | "ios" | "notification" | "pushSupported">): PushPhase | "check" {
  if (keyBytes(env.publicKey) === null) return "hidden";
  if (!env.worker) return "plain";
  if (env.ios && !env.standalone()) return "ios_install";
  if (!env.pushSupported || env.notification === null) return "hidden";
  if (env.notification.permission === "denied") return "denied";
  return "check";
}

type Listener = () => void;

export class MemberPushFlow {
  private state: PushState = { phase: "hidden" };
  private readonly listeners = new Set<Listener>();
  private opened = false;
  private busy = false;
  private readonly key: Uint8Array | null;
  private readonly env: PushEnv;
  private readonly api: CustomerPushApi;

  constructor(env: PushEnv, api: CustomerPushApi) {
    this.env = env;
    this.api = api;
    this.key = keyBytes(env.publicKey);
  }

  getState = (): PushState => this.state;

  subscribe = (l: Listener): (() => void) => {
    this.listeners.add(l);
    return () => this.listeners.delete(l);
  };

  private set(phase: PushPhase): void {
    if (this.state.phase === phase) return;
    this.state = { phase };
    for (const l of this.listeners) l();
  }

  /** The member area opened: decide what to show. Never asks for permission. */
  async open(): Promise<void> {
    if (this.opened) return;
    this.opened = true;
    const first = initialPhase(this.env);
    if (first === "hidden") return this.set("hidden");
    const status = await this.api.status();
    if (status.kind !== "ready") return this.set("hidden");
    if (first !== "check") return this.set(first);
    const manager = await this.manager();
    if (!manager) return this.set("hidden");
    let sub: SubscriptionLike | null = null;
    try {
      sub = await manager.getSubscription();
    } catch {
      sub = null;
    }
    if (!sub) return this.set("idle");
    if (this.env.notification?.permission !== "granted") {
      // The member took the permission back in the browser: the old subscription is dead weight.
      await this.drop(sub);
      return this.set("idle");
    }
    this.set("asking");
    let current: SubscriptionLike;
    try {
      current = sameKey(sub, this.key!) ? sub : await this.replace(manager, sub);
    } catch {
      return this.set("idle");
    }
    // A passing network or server problem keeps the subscription (it is sent again at the next open).
    const outcome = await this.store(manager, current, false);
    this.set(outcome === "subscribed" || outcome === "error" || outcome === "unavailable" ? "on" : "idle");
  }

  /** «Θέλω ειδοποιήσεις»: the member's tap. The ONLY place that asks the browser for permission. */
  async enable(): Promise<void> {
    if (this.busy || !this.key || (this.state.phase !== "idle" && this.state.phase !== "error")) return;
    const notification = this.env.notification;
    if (!notification) return;
    this.busy = true;
    this.set("asking");
    try {
      let permission = notification.permission;
      if (permission !== "granted") {
        try {
          permission = await notification.requestPermission();
        } catch {
          permission = "default";
        }
      }
      if (permission !== "granted") return this.set(permission === "denied" ? "denied" : "idle");
      const manager = await this.manager();
      if (!manager) return this.set("error");
      let sub: SubscriptionLike;
      try {
        const existing = await manager.getSubscription();
        sub = existing && sameKey(existing, this.key) ? existing : await this.replace(manager, existing);
      } catch {
        return this.set("error");
      }
      this.set((await this.store(manager, sub, true)) === "subscribed" ? "on" : "error");
    } finally {
      this.busy = false;
    }
  }

  /** «Διακοπή ειδοποιήσεων». */
  async disable(): Promise<void> {
    if (this.busy || this.state.phase !== "on") return;
    this.busy = true;
    this.set("stopping");
    try {
      const manager = await this.manager();
      const sub = manager ? await manager.getSubscription().catch(() => null) : null;
      if (sub) await this.drop(sub);
      this.set("idle");
    } finally {
      this.busy = false;
    }
  }

  /** Before WordPress signs the account out: the next account on this phone never gets this member's notifications. */
  async signOut(): Promise<void> {
    if (this.state.phase !== "on" && this.state.phase !== "stopping") return;
    const manager = await this.manager();
    const sub = manager ? await manager.getSubscription().catch(() => null) : null;
    if (sub) await this.drop(sub);
    this.set("idle");
  }

  private async manager(): Promise<PushManagerLike | null> {
    try {
      return await this.env.pushManager();
    } catch {
      return null;
    }
  }

  /** A new subscription for the shop's key (the old one, if any, is dropped first). */
  private async replace(manager: PushManagerLike, old: SubscriptionLike | null): Promise<SubscriptionLike> {
    if (old) await old.unsubscribe().catch(() => false);
    return manager.subscribe({ userVisibleOnly: true, applicationServerKey: this.key! });
  }

  /**
   * Sends the subscription; on 409 a new one is made and sent once more. Anything but "subscribed": the browser's
   * subscription is dropped (a tap that did not work leaves nothing behind), except for a passing network / server
   * problem when $dropOnPassingFailure is false (the re-sync of an open).
   */
  private async store(manager: PushManagerLike, sub: SubscriptionLike, dropOnPassingFailure: boolean): Promise<PushWrite["kind"]> {
    let current = sub;
    let kind: PushWrite["kind"] = "error";
    for (let attempt = 0; attempt < 2; attempt++) {
      const json = subscriptionJson(current);
      const reply: PushWrite = json ? await this.api.subscribe(json) : { kind: "invalid" };
      kind = reply.kind;
      if (kind === "subscribed") return kind;
      if (kind !== "endpoint_in_use" || attempt === 1) break;
      try {
        current = await this.replace(manager, current);
      } catch {
        return "error";
      }
    }
    if (dropOnPassingFailure || (kind !== "error" && kind !== "unavailable")) await current.unsubscribe().catch(() => false);
    return kind;
  }

  /** DELETE on the server (best effort), then drop the browser's subscription (a dropped one answers 410 to the shop). */
  private async drop(sub: SubscriptionLike): Promise<void> {
    const json = subscriptionJson(sub);
    if (json) await this.api.unsubscribe(json.endpoint);
    await sub.unsubscribe().catch(() => false);
  }
}

/** Greek copy of the control (no English words). */
export const PUSH_COPY = {
  title: "Ειδοποιήσεις",
  explain: "Θα λαμβάνετε σύντομα μηνύματα από το κατάστημα για τη συνδρομή σας και για νέα του Club. Τις σταματάτε όποτε θέλετε, με ένα άγγιγμα.",
  enable: "Θέλω ειδοποιήσεις",
  asking: "Περιμένουμε την απάντησή σας…",
  on: "Οι ειδοποιήσεις είναι ενεργές σε αυτή τη συσκευή.",
  disable: "Διακοπή ειδοποιήσεων",
  stopping: "Σταματάμε τις ειδοποιήσεις…",
  denied: "Έχετε αρνηθεί τις ειδοποιήσεις σε αυτόν τον περιηγητή. Για να τις ενεργοποιήσετε, επιτρέψτε τις από τις ρυθμίσεις του περιηγητή και ανοίξτε ξανά την κάρτα.",
  error: "Δεν ήταν δυνατή η ενεργοποίηση. Δοκιμάστε ξανά σε λίγο.",
  ios: "Στο iPhone οι ειδοποιήσεις λειτουργούν μόνο όταν η κάρτα είναι εγκατεστημένη. Στο Safari πατήστε «Κοινή χρήση» και μετά «Προσθήκη στην οθόνη Αφετηρίας», ανοίξτε την κάρτα από το εικονίδιο και ενεργοποιήστε τις από εκεί.",
  plain: "Οι ειδοποιήσεις δεν είναι ακόμη διαθέσιμες, γιατί ο ιστότοπος δεν έχει ρυθμιστεί γι' αυτές. Θα εμφανιστούν εδώ μόλις ενεργοποιηθούν.",
} as const;
