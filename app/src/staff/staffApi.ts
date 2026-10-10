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

export type StaffErrorKind = "unauthorized" | "forbidden" | "network" | "invalid_response" | "server" | "aborted";

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

export function createStaffApi(config: StaffConfig, fetchImpl: typeof fetch = (i, init) => globalThis.fetch(i, init)): StaffApi {
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

  async function call(path: string, method: "GET" | "POST", signal?: AbortSignal): Promise<unknown> {
    let res: Response;
    let text: string;
    try {
      res = await staffFetch(`${config.restBase}${path}`, { method, signal });
      text = await res.text();
    } catch (err) {
      if (err instanceof StaffApiError) throw err;
      if (signal?.aborted) throw new StaffApiError("aborted");
      throw new StaffApiError("network");
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
