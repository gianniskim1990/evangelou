/*
 * STAGE-08 round 11 (owner decision D26 part 1): the «Εγκατάσταση στο κινητό» hint of the member app.
 *
 *   Android / Chrome  the browser's own install prompt (beforeinstallprompt), kept and shown only when the member
 *                     taps «Εγκατάσταση».
 *   iPhone / iPad     Safari has no prompt: short Greek steps with Apple's own Greek labels
 *                     («Κοινή χρήση» → «Προσθήκη στην οθόνη Αφετηρίας»).
 *   anything else     nothing.
 *
 * Never nagging: hidden when the app already runs from the home screen (standalone), after it was installed,
 * and for good on this device once the member closes it («Όχι τώρα» / «Εντάξει») or declines the browser's
 * prompt. "For good" is one fixed flag in this device's storage (qrStore.ts createDeviceFlag, the only file
 * allowed to touch storage); if storage cannot be used it lasts until the page is closed.
 */

export type InstallHintKind = "prompt" | "ios" | null;

export interface InstallEnv {
  standalone: boolean;
  promptReady: boolean;
  ios: boolean;
  dismissed: boolean;
  installed: boolean;
}

export function installHintKind(env: InstallEnv): InstallHintKind {
  if (env.standalone || env.installed || env.dismissed) return null;
  if (env.promptReady) return "prompt";
  if (env.ios) return "ios";
  return null;
}

/**
 * Safari on an iPhone, iPod or iPad (iPadOS presents itself as a Mac with a touch screen). Chrome, Firefox, Edge,
 * Opera and the Google app on iOS name themselves (CriOS, FxiOS, EdgiOS, OPiOS, GSA): their share menus differ,
 * so the Safari steps are not shown there.
 */
export function isIosSafari(userAgent: string, maxTouchPoints: number): boolean {
  const apple = /\b(iPhone|iPod|iPad)\b/.test(userAgent) || (/\bMacintosh\b/.test(userAgent) && maxTouchPoints > 1);
  if (!apple) return false;
  if (/\b(CriOS|FxiOS|EdgiOS|OPiOS|GSA|YaBrowser|DuckDuckGo)\b/.test(userAgent)) return false;
  return /\bSafari\//.test(userAgent) && /\bVersion\//.test(userAgent);
}

/** Running from the home screen: display-mode standalone (Android, desktop) or navigator.standalone (iOS). */
export function isStandalone(win: { matchMedia?: (q: string) => { matches: boolean }; navigator?: { standalone?: unknown } }): boolean {
  try {
    if (win.matchMedia?.("(display-mode: standalone)").matches) return true;
  } catch {
    // no matchMedia: fall through
  }
  return win.navigator?.standalone === true;
}

/** The browser's deferred install prompt (Chrome's BeforeInstallPromptEvent). */
export interface DeferredPrompt {
  preventDefault(): void;
  prompt(): Promise<unknown> | void;
  userChoice?: Promise<{ outcome?: unknown }>;
}

/** A one-flag device memory (qrStore.ts createDeviceFlag); null = no storage. */
export interface DeviceFlag {
  get(): boolean;
  set(): void;
}

type Listener = () => void;

export interface InstallHintState {
  kind: InstallHintKind;
}

/**
 * The hint's state for the member app. `target` is window: the browser's events are listened to from the moment
 * the app starts (beforeinstallprompt can fire before React renders).
 */
export class InstallHintFlow {
  private deferred: DeferredPrompt | null = null;
  private installed = false;
  private dismissedNow = false;
  private state: InstallHintState = { kind: null };
  private readonly listeners = new Set<Listener>();

  private readonly env: { standalone: () => boolean; ios: boolean; flag: DeviceFlag | null };

  constructor(env: { standalone: () => boolean; ios: boolean; flag: DeviceFlag | null }, target: { addEventListener(type: string, fn: (e: Event) => void): void } | null) {
    this.env = env;
    target?.addEventListener("beforeinstallprompt", (e) => {
      const event = e as unknown as DeferredPrompt;
      // Our own hint replaces the browser's mini bar; the member still has the browser menu.
      event.preventDefault();
      this.deferred = event;
      this.update();
    });
    target?.addEventListener("appinstalled", () => {
      this.installed = true;
      this.deferred = null;
      this.update();
    });
    this.update();
  }

  private dismissed(): boolean {
    if (this.dismissedNow) return true;
    try {
      return this.env.flag?.get() === true;
    } catch {
      return false;
    }
  }

  private update(): void {
    const kind = installHintKind({ standalone: this.env.standalone(), promptReady: this.deferred !== null, ios: this.env.ios, dismissed: this.dismissed(), installed: this.installed });
    if (kind === this.state.kind) return;
    this.state = { kind };
    for (const l of this.listeners) l();
  }

  getState = (): InstallHintState => this.state;

  subscribe = (l: Listener): (() => void) => {
    this.listeners.add(l);
    return () => this.listeners.delete(l);
  };

  /** «Όχι τώρα» / «Εντάξει»: never shown again on this device. */
  dismiss(): void {
    this.dismissedNow = true;
    try {
      this.env.flag?.set();
    } catch {
      // kept in memory for this page
    }
    this.update();
  }

  /** «Εγκατάσταση»: the browser's own prompt. Declined = never asked again here (no nagging); accepted = gone. */
  async install(): Promise<void> {
    const event = this.deferred;
    if (!event) return;
    this.deferred = null; // a prompt can be shown only once
    let outcome: unknown;
    try {
      await event.prompt();
      outcome = (await event.userChoice)?.outcome;
    } catch {
      outcome = undefined;
    }
    if (outcome === "accepted") this.installed = true;
    else this.dismiss();
    this.update();
  }
}

/** Greek copy of the hint (no English words). */
export const INSTALL_COPY = {
  title: "Εγκατάσταση στο κινητό",
  prompt: "Βάλτε την κάρτα του Club στην αρχική οθόνη του κινητού σας, για να την ανοίγετε με ένα άγγιγμα στο ταμείο.",
  install: "Εγκατάσταση",
  later: "Όχι τώρα",
  ios: "Στο Safari πατήστε «Κοινή χρήση» και μετά «Προσθήκη στην οθόνη Αφετηρίας». Η κάρτα σας θα ανοίγει από εκεί με ένα άγγιγμα.",
  ok: "Εντάξει",
} as const;
