import type { StaffConfig } from "./config";
import { StaffApiError, type SessionStatus, type StaffApi } from "./staffApi";

/**
 * State machine of the real staff app.
 *
 * Lock / logout rules:
 * - lock() bumps an epoch, aborts every in-flight request, clears sensitive
 *   state, records a per-session lock marker in sessionStorage (so a reload
 *   of the same, still-valid WordPress session re-locks instead of opening),
 *   and asks WordPress to DESTROY the session server-side (/session/end).
 * - Unlocking is only possible through a real WordPress login: the app
 *   navigates to wp-login.php?reauth=1, which clears the auth cookie and
 *   shows the login form even if a cookie were still valid. A click on the
 *   lock screen never unlocks by itself.
 * - Responses from an older epoch are discarded, so a request that was in
 *   flight when the tablet locked can never repopulate private data.
 * - The marker contains only the non-secret sessionRef, never the nonce.
 */

export const LOCK_MARKER_KEY = "evc-staff-locked-session";

export type StaffState =
  | { phase: "loading" }
  | { phase: "ready"; session: SessionStatus }
  | { phase: "locked"; serverEnded: boolean; ending: boolean }
  | { phase: "expired" }
  | { phase: "forbidden" }
  | { phase: "offline" }
  | { phase: "error" };

export type RunResult<T> =
  | { status: "ok"; value: T }
  | { status: "error"; error: StaffApiError }
  | { status: "stale" };

type Storage = Pick<globalThis.Storage, "getItem" | "setItem" | "removeItem">;

export interface StaffControllerDeps {
  api: StaffApi;
  config: StaffConfig;
  navigate: (url: string) => void;
  storage: Storage | null;
}

export class StaffController {
  private state: StaffState = { phase: "loading" };
  private readonly listeners = new Set<() => void>();
  private epoch = 0;
  private readonly inflight = new Set<AbortController>();
  private sensitive: unknown = null;
  private readonly deps: StaffControllerDeps;

  constructor(deps: StaffControllerDeps) {
    this.deps = deps;
  }

  getState = (): StaffState => this.state;

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  };

  private setState(next: StaffState): void {
    this.state = next;
    for (const l of this.listeners) l();
  }

  private get blocked(): boolean {
    return this.state.phase === "locked" || this.state.phase === "expired";
  }

  /** Sensitive data (future member details) — refused while locked/expired. */
  setSensitive(data: unknown): boolean {
    if (this.blocked) return false;
    this.sensitive = data;
    return true;
  }

  getSensitive(): unknown {
    return this.blocked ? null : this.sensitive;
  }

  private safeStorage<T>(fn: (s: Storage) => T): T | null {
    try {
      return this.deps.storage ? fn(this.deps.storage) : null;
    } catch {
      return null;
    }
  }

  async boot(): Promise<void> {
    const ref = this.deps.config.sessionRef;
    const marker = this.safeStorage((s) => s.getItem(LOCK_MARKER_KEY));
    if (ref !== "" && marker === ref) {
      // Reload of a session that was locked: stay locked and end it again.
      await this.lock();
      return;
    }
    this.safeStorage((s) => s.removeItem(LOCK_MARKER_KEY));
    await this.refreshStatus();
  }

  /** Status check. Never counts as activity (client or server side). */
  async refreshStatus(): Promise<void> {
    if (this.blocked) return;
    const result = await this.run((signal) => this.deps.api.getSession(signal));
    if (result.status === "ok") {
      this.setState({ phase: "ready", session: result.value });
    } else if (result.status === "error") {
      this.handleError(result.error);
    }
  }

  /** Runs a request bound to the current epoch; stale results are discarded. */
  async run<T>(fn: (signal: AbortSignal) => Promise<T>): Promise<RunResult<T>> {
    if (this.blocked) return { status: "stale" };
    const epoch = this.epoch;
    const controller = new AbortController();
    this.inflight.add(controller);
    try {
      const value = await fn(controller.signal);
      if (epoch !== this.epoch || this.blocked) return { status: "stale" };
      return { status: "ok", value };
    } catch (err) {
      if (epoch !== this.epoch || this.blocked) return { status: "stale" };
      return { status: "error", error: err instanceof StaffApiError ? err : new StaffApiError("network") };
    } finally {
      this.inflight.delete(controller);
    }
  }

  private handleError(error: StaffApiError): void {
    if (error.kind === "unauthorized") {
      this.invalidate();
      this.setState({ phase: "expired" });
    } else if (error.kind === "forbidden") {
      this.invalidate();
      this.setState({ phase: "forbidden" });
    } else if (error.kind === "network") {
      this.setState({ phase: "offline" });
    } else if (error.kind !== "aborted") {
      this.setState({ phase: "error" });
    }
  }

  /** Drops everything bound to the current epoch. */
  private invalidate(): void {
    this.epoch++;
    for (const c of this.inflight) c.abort();
    this.inflight.clear();
    this.sensitive = null;
  }

  async lock(): Promise<void> {
    if (this.state.phase === "locked") return;
    this.invalidate();
    const ref = this.deps.config.sessionRef;
    if (ref !== "") this.safeStorage((s) => s.setItem(LOCK_MARKER_KEY, ref));
    this.setState({ phase: "locked", serverEnded: false, ending: true });
    const ended = await this.endServerSession();
    if (this.getState().phase === "locked") this.setState({ phase: "locked", serverEnded: ended, ending: false });
  }

  /** true when WordPress confirmed (or the session was already gone). */
  private async endServerSession(): Promise<boolean> {
    try {
      await this.deps.api.endSession();
      return true;
    } catch (err) {
      return err instanceof StaffApiError && (err.kind === "unauthorized" || err.kind === "forbidden");
    }
  }

  /**
   * Real re-authentication: ends the server session (best effort) and goes
   * to wp-login.php?reauth=1. Never unlocks in place.
   */
  async reauthenticate(): Promise<void> {
    if (this.state.phase === "locked" && !this.state.serverEnded) {
      this.setState({ phase: "locked", serverEnded: false, ending: true });
      const ended = await this.endServerSession();
      this.setState({ phase: "locked", serverEnded: ended, ending: false });
    }
    this.deps.navigate(this.deps.config.loginUrl);
  }

  async logout(): Promise<void> {
    await this.lock();
    await this.reauthenticate();
  }
}
