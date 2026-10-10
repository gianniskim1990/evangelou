import { ClubApiError } from "../club/types";
import type { StaffConfig } from "./config";
import { StaffApiError, type SessionStatus, type StaffApi } from "./staffApi";

/**
 * State machine of the real staff app.
 *
 * Two kinds of work, deliberately separated:
 * - SESSION requests (bootstrap, status re-check, recovery from offline /
 *   error): runSession(). Allowed in loading, ready, offline and error, so
 *   the app can boot and recover. Never touches sensitive data.
 * - PROTECTED operations (future member data): runProtected(). Allowed ONLY
 *   in a verified "ready" state; a result is applied only if the app is still
 *   ready in the same epoch. setSensitive/getSensitive likewise only in ready.
 *
 * Fail closed: EVERY transition out of "ready" bumps the epoch, aborts all
 * in-flight requests and clears sensitive state, so a late response can never
 * restore member details after loss of authorization, lock, expiry, offline
 * or error.
 *
 * Authorization loss on PROTECTED operations (Task 1D-C): a trustworthy
 * 401 / 403 (see authLossOf: only our own StaffApiError or a ClubApiError
 * built from a real HTTP response, with code AND status agreeing) moves the
 * app to "expired" / "forbidden" through the same invalidation, so every
 * other in-flight request and any late success is discarded and only a real
 * WordPress login can continue. Business answers (400, 404, 409 inactive /
 * already redeemed / idempotency conflict, 429), network failures, timeouts
 * and 5xx are returned to the caller unchanged and never end the session.
 * Nothing is retried automatically.
 *
 * Session status checks are latest-request-wins: a newer check supersedes
 * (and aborts) an older one, whose success or ordinary failure is then
 * ignored. A 401 / 403 is the exception: it always fails closed, even from
 * a superseded check, because wrongly locking costs only a re-login while
 * wrongly keeping a revoked session open could expose member data.
 *
 * Lock / logout:
 * - lock() invalidates as above, records a per-session marker in
 *   sessionStorage (a reload of the same, still-valid WordPress session
 *   re-locks) and asks WordPress to destroy the session (/session/end), a
 *   request bounded by SESSION_REQUEST_TIMEOUT_MS.
 * - revocation is "confirmed" ONLY when WordPress answered 200
 *   {"ended": true}. Everything else is "unconfirmed" and the UI says so:
 *   timeout/network/5xx (outcome unknown) and also 401/403, because a
 *   missing or stale nonce yields 401/403 while the session may still be
 *   alive. The lock never lifts because of a request outcome.
 * - Unlocking is only possible through a real WordPress login
 *   (wp-login.php?reauth=1). The re-login action is never disabled; an
 *   explicit "retry ending the session" action is offered when unconfirmed.
 * - The marker contains only the non-secret sessionRef, never the nonce.
 */

export const LOCK_MARKER_KEY = "evc-staff-locked-session";

export type Revocation = "pending" | "confirmed" | "unconfirmed";

export type StaffState =
  | { phase: "loading" }
  | { phase: "ready"; session: SessionStatus }
  | { phase: "locked"; revocation: Revocation }
  | { phase: "expired" }
  | { phase: "forbidden" }
  | { phase: "offline" }
  | { phase: "error" };

export type RunResult<T> =
  | { status: "ok"; value: T }
  | { status: "error"; error: StaffApiError }
  | { status: "stale" };

/** Errors a protected operation can report to its caller. */
export type ProtectedError = StaffApiError | ClubApiError;

export type AuthLoss = "unauthorized" | "forbidden";

export type ProtectedResult<T> =
  | { status: "ok"; value: T }
  | { status: "error"; error: ProtectedError }
  /** The session ended (401) or permission was withdrawn (403); the app already left "ready". */
  | { status: "auth_lost"; reason: AuthLoss }
  | { status: "stale" };

/**
 * The ONLY place that decides whether a failure means "this staff session is
 * no longer authorized". Narrow on purpose: arbitrary thrown objects (even
 * ones with a matching `code`/`status` field) are never trusted, and a
 * ClubApiError counts only when its code and HTTP status agree, which is how
 * restClubService.mapErrorResponse builds it from a real response.
 */
export function authLossOf(error: unknown): AuthLoss | null {
  if (error instanceof StaffApiError) {
    if (error.kind === "unauthorized" && error.status === 401) return "unauthorized";
    if (error.kind === "forbidden" && error.status === 403) return "forbidden";
    return null;
  }
  if (error instanceof ClubApiError) {
    if (error.code === "unauthorized" && error.httpStatus === 401) return "unauthorized";
    if (error.code === "forbidden" && error.httpStatus === 403) return "forbidden";
  }
  return null;
}

/** Keeps a known error as is; anything unexpected becomes a non-auth "network" error. */
function toProtectedError(error: unknown): ProtectedError {
  return error instanceof StaffApiError || error instanceof ClubApiError ? error : new StaffApiError("network");
}

type Storage = Pick<globalThis.Storage, "getItem" | "setItem" | "removeItem">;

export interface StaffControllerDeps {
  api: StaffApi;
  config: StaffConfig;
  navigate: (url: string) => void;
  storage: Storage | null;
}

/** Phases in which session bootstrap / status / recovery requests may run. */
const SESSION_PHASES: ReadonlySet<StaffState["phase"]> = new Set(["loading", "ready", "offline", "error"]);

export class StaffController {
  private state: StaffState = { phase: "loading" };
  private readonly listeners = new Set<() => void>();
  private epoch = 0;
  private readonly inflight = new Set<AbortController>();
  /** Sequence of session status checks; only the newest may apply its result. */
  private statusSeq = 0;
  private statusRequest: AbortController | null = null;
  private sensitive: unknown = null;
  private ending: Promise<boolean> | null = null;
  private readonly deps: StaffControllerDeps;

  constructor(deps: StaffControllerDeps) {
    this.deps = deps;
  }

  getState = (): StaffState => this.state;

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  };

  /** Single entry point for transitions: leaving "ready" always invalidates. */
  private setState(next: StaffState): void {
    if (this.state.phase === "ready" && next.phase !== "ready") this.invalidate();
    this.state = next;
    for (const l of this.listeners) l();
  }

  get isReady(): boolean {
    return this.state.phase === "ready";
  }

  /** Sensitive data (future member details) — accepted ONLY while ready. */
  setSensitive(data: unknown): boolean {
    if (!this.isReady) return false;
    this.sensitive = data;
    return true;
  }

  getSensitive(): unknown {
    return this.isReady ? this.sensitive : null;
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

  /**
   * Session status check (bootstrap and recovery). Never counts as activity
   * (client or server side). Returns to "ready" ONLY after WordPress
   * verified the session.
   */
  async refreshStatus(): Promise<void> {
    const seq = ++this.statusSeq;
    const result = await this.runSession(
      (signal) => this.deps.api.getSession(signal),
      (request) => {
        // A newer check supersedes the older one: stop waiting for it.
        this.statusRequest?.abort();
        this.statusRequest = request;
      },
    );
    const superseded = seq !== this.statusSeq;
    if (!superseded) this.statusRequest = null;
    if (result.status === "ok") {
      if (!superseded) this.setState({ phase: "ready", session: result.value });
    } else if (result.status === "error") {
      // Authorization loss always fails closed; anything else only from the newest check.
      if (!superseded || authLossOf(result.error) !== null) this.handleError(result.error);
    }
  }

  /** Session bootstrap / recovery request; discarded if the state moved on. */
  async runSession<T>(fn: (signal: AbortSignal) => Promise<T>, onStart?: (request: AbortController) => void): Promise<RunResult<T>> {
    if (!SESSION_PHASES.has(this.state.phase)) return { status: "stale" };
    const result = await this.track(fn, () => SESSION_PHASES.has(this.state.phase), onStart);
    if (result.status === "error") {
      return { status: "error", error: result.error instanceof StaffApiError ? result.error : new StaffApiError("network") };
    }
    return result;
  }

  /**
   * Protected (member-data) operation: ONLY in a verified ready state. A
   * trustworthy 401/403 ends the session here (fail closed); every other
   * failure is handed back unchanged. Never retried automatically.
   */
  async runProtected<T>(fn: (signal: AbortSignal) => Promise<T>): Promise<ProtectedResult<T>> {
    if (!this.isReady) return { status: "stale" };
    const result = await this.track(fn, () => this.isReady);
    if (result.status !== "error") return result;
    const loss = authLossOf(result.error);
    if (loss !== null) {
      this.loseAuthorization(loss);
      return { status: "auth_lost", reason: loss };
    }
    return { status: "error", error: toProtectedError(result.error) };
  }

  private async track<T>(
    fn: (signal: AbortSignal) => Promise<T>,
    stillValid: () => boolean,
    onStart?: (request: AbortController) => void,
  ): Promise<{ status: "ok"; value: T } | { status: "error"; error: unknown } | { status: "stale" }> {
    const epoch = this.epoch;
    const controller = new AbortController();
    this.inflight.add(controller);
    onStart?.(controller);
    try {
      const value = await fn(controller.signal);
      if (epoch !== this.epoch || !stillValid()) return { status: "stale" };
      return { status: "ok", value };
    } catch (err) {
      if (epoch !== this.epoch || !stillValid()) return { status: "stale" };
      return { status: "error", error: err };
    } finally {
      this.inflight.delete(controller);
    }
  }

  /** 401 -> expired, 403 -> forbidden; both clear data and drop every in-flight request. */
  private loseAuthorization(loss: AuthLoss): void {
    this.invalidate();
    this.setState({ phase: loss === "unauthorized" ? "expired" : "forbidden" });
  }

  private handleError(error: StaffApiError): void {
    if (error.kind === "unauthorized") {
      this.loseAuthorization("unauthorized");
    } else if (error.kind === "forbidden") {
      this.loseAuthorization("forbidden");
    } else if (error.kind === "network" || error.kind === "timeout") {
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
    await this.endServerSession();
  }

  /**
   * Ends the WordPress session (bounded). "confirmed" only on a verified
   * 200 {"ended": true}; any failure stays "unconfirmed". One attempt at a
   * time; the lock itself is unaffected by the outcome.
   */
  private endServerSession(): Promise<boolean> {
    if (this.ending) return this.ending;
    this.setState({ phase: "locked", revocation: "pending" });
    this.ending = (async () => {
      let confirmed = false;
      try {
        await this.deps.api.endSession();
        confirmed = true;
      } catch {
        // Outcome unknown (or session possibly still alive): never claim it.
      }
      this.ending = null;
      if (this.state.phase === "locked") this.setState({ phase: "locked", revocation: confirmed ? "confirmed" : "unconfirmed" });
      return confirmed;
    })();
    return this.ending;
  }

  /** Explicit staff action when revocation could not be confirmed. */
  async retryEndSession(): Promise<void> {
    if (this.state.phase !== "locked" || this.state.revocation === "confirmed") return;
    await this.endServerSession();
  }

  /**
   * Real re-authentication: always available, never waits on a pending
   * request and never unlocks in place. wp-login.php?reauth=1 clears the
   * browser's auth cookie and shows the login form.
   */
  reauthenticate(): void {
    this.deps.navigate(this.deps.config.loginUrl);
  }

  async logout(): Promise<void> {
    await this.lock();
    this.reauthenticate();
  }
}
