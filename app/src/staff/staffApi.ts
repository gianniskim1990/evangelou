import { isValidNonce, type StaffConfig } from "./config";

/**
 * Transport for the REAL staff app. Same origin only, WordPress cookie
 * session (credentials: "same-origin") + X-WP-Nonce header. The nonce lives
 * only in memory (NonceStore) — never in localStorage/sessionStorage/URLs —
 * and is replaced when WordPress returns a refreshed X-WP-Nonce header.
 *
 * `staffFetch` has the same signature as fetch, so the future member
 * endpoints can reuse restClubService via createRestClubService({ fetchImpl }).
 */

export class NonceStore {
  private value: string;

  constructor(initial: string) {
    if (!isValidNonce(initial)) throw new Error("invalid nonce");
    this.value = initial;
  }

  get(): string {
    return this.value;
  }

  /** Accepts only well-formed WordPress nonces; anything else is ignored. */
  update(candidate: string | null): boolean {
    if (!isValidNonce(candidate) || candidate === this.value) return false;
    this.value = candidate;
    return true;
  }
}

/**
 * timeout: the session request did not complete (headers AND body) within
 * SESSION_REQUEST_TIMEOUT_MS. The outcome on the server is UNKNOWN.
 */
export type StaffErrorKind = "unauthorized" | "forbidden" | "network" | "invalid_response" | "server" | "aborted" | "timeout";

/** Upper bound for one staff session request (status / end), headers + body. */
export const SESSION_REQUEST_TIMEOUT_MS = 10_000;

export interface StaffTimers {
  setTimeout(fn: () => void, ms: number): unknown;
  clearTimeout(id: unknown): void;
}

export interface StaffApiOptions {
  timeoutMs?: number;
  /** Injected for deterministic tests. */
  timers?: StaffTimers;
}

const defaultTimers: StaffTimers = {
  setTimeout: (fn, ms) => globalThis.setTimeout(fn, ms),
  clearTimeout: (id) => globalThis.clearTimeout(id as ReturnType<typeof setTimeout>),
};

/** Rejects as soon as `signal` aborts, even if `promise` never settles. */
function raceAbort<T>(promise: Promise<T>, signal: AbortSignal): Promise<T> {
  if (signal.aborted) return Promise.reject(new Error("aborted"));
  return new Promise<T>((resolve, reject) => {
    const onAbort = () => reject(new Error("aborted"));
    signal.addEventListener("abort", onAbort, { once: true });
    promise.then(
      (value) => {
        signal.removeEventListener("abort", onAbort);
        resolve(value);
      },
      (err) => {
        signal.removeEventListener("abort", onAbort);
        reject(err);
      },
    );
  });
}

export class StaffApiError extends Error {
  kind: StaffErrorKind;
  status?: number;

  constructor(kind: StaffErrorKind, status?: number) {
    super(kind);
    this.name = "StaffApiError";
    this.kind = kind;
    this.status = status;
  }
}

export interface SessionStatus {
  expiresAt: string;
  idleTimeoutSeconds: number;
  features: { qrLookup: boolean; phoneLookup: boolean; coffeeRedemption: boolean; history: boolean };
}

export interface StaffApi {
  /** fetch-compatible, nonce-carrying, same-origin transport. */
  staffFetch: typeof fetch;
  getSession(signal?: AbortSignal): Promise<SessionStatus>;
  endSession(signal?: AbortSignal): Promise<void>;
  currentNonce(): string;
}

const INSTANT_RE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/;

export function createStaffApi(
  config: StaffConfig,
  fetchImpl: typeof fetch = (i, init) => globalThis.fetch(i, init),
  options: StaffApiOptions = {},
): StaffApi {
  const timeoutMs = options.timeoutMs ?? SESSION_REQUEST_TIMEOUT_MS;
  const timers = options.timers ?? defaultTimers;
  const nonce = new NonceStore(config.nonce);
  const origin = new URL(config.restBase).origin;

  const staffFetch: typeof fetch = async (input, init) => {
    const url = new URL(typeof input === "string" || input instanceof URL ? String(input) : input.url, origin);
    if (url.origin !== origin) throw new StaffApiError("invalid_response");
    const headers = new Headers(init?.headers);
    headers.set("X-WP-Nonce", nonce.get());
    if (!headers.has("Accept")) headers.set("Accept", "application/json");
    const res = await fetchImpl(url.href, {
      ...init,
      headers,
      credentials: "same-origin",
      cache: "no-store",
      redirect: "error",
    });
    nonce.update(res.headers.get("X-WP-Nonce"));
    return res;
  };

  /**
   * One BOUNDED session request: a single internal AbortController is
   * aborted by the timeout or by the caller's signal; both the fetch and the
   * body read are raced against it, so neither can hang forever even if the
   * fetch implementation ignores the signal. Timer and listener are always
   * removed in finally.
   */
  async function call(path: string, method: "GET" | "POST", signal?: AbortSignal): Promise<unknown> {
    const controller = new AbortController();
    let timedOut = false;
    const timer = timers.setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, timeoutMs);
    const onCallerAbort = () => controller.abort();
    if (signal?.aborted) controller.abort();
    else signal?.addEventListener("abort", onCallerAbort, { once: true });

    let res: Response;
    let text: string;
    try {
      res = await raceAbort(staffFetch(`${config.restBase}${path}`, { method, signal: controller.signal }), controller.signal);
      text = await raceAbort(res.text(), controller.signal);
    } catch (err) {
      if (err instanceof StaffApiError) throw err;
      if (timedOut) throw new StaffApiError("timeout");
      if (signal?.aborted) throw new StaffApiError("aborted");
      throw new StaffApiError("network");
    } finally {
      timers.clearTimeout(timer);
      signal?.removeEventListener("abort", onCallerAbort);
    }
    if (res.status === 401) throw new StaffApiError("unauthorized", 401);
    if (res.status === 403) throw new StaffApiError("forbidden", 403);
    if (!res.ok) throw new StaffApiError(res.status >= 500 ? "server" : "invalid_response", res.status);
    try {
      return JSON.parse(text) as unknown;
    } catch {
      throw new StaffApiError("invalid_response", res.status);
    }
  }

  return {
    staffFetch,
    currentNonce: () => nonce.get(),

    async getSession(signal) {
      const raw = await call("/session", "GET", signal);
      const d = raw as { authenticated?: unknown; session?: Record<string, unknown>; features?: Record<string, unknown> };
      if (!d || d.authenticated !== true || typeof d.session !== "object" || d.session === null) {
        throw new StaffApiError("invalid_response");
      }
      const expiresAt = d.session.expires_at;
      const idle = d.session.idle_timeout_seconds;
      if (typeof expiresAt !== "string" || !INSTANT_RE.test(expiresAt) || typeof idle !== "number") {
        throw new StaffApiError("invalid_response");
      }
      const f = (d.features ?? {}) as Record<string, unknown>;
      return {
        expiresAt,
        idleTimeoutSeconds: idle,
        features: {
          qrLookup: f.qr_lookup === true,
          phoneLookup: f.phone_lookup === true,
          coffeeRedemption: f.coffee_redemption === true,
          history: f.history === true,
        },
      };
    },

    async endSession(signal) {
      const raw = await call("/session/end", "POST", signal);
      if (!raw || (raw as { ended?: unknown }).ended !== true) throw new StaffApiError("invalid_response");
    },
  };
}
