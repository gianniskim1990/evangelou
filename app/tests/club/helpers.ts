import type { MockStorage } from "../../src/club/mockRedemptionStore";

/** In-memory Storage stand-in (the browser's localStorage is mock-only). */
export class MemoryStorage implements MockStorage {
  readonly data = new Map<string, string>();
  failWrites = false;

  getItem(key: string): string | null {
    return this.data.has(key) ? (this.data.get(key) as string) : null;
  }

  setItem(key: string, value: string): void {
    if (this.failWrites) throw new Error("QuotaExceededError");
    this.data.set(key, value);
  }

  removeItem(key: string): void {
    this.data.delete(key);
  }
}

/** Mutable clock for deterministic business-date tests. */
export class FakeClock {
  current: Date;

  constructor(iso: string) {
    this.current = new Date(iso);
  }

  set(iso: string): void {
    this.current = new Date(iso);
  }

  now = (): Date => new Date(this.current.getTime());
}

export interface RecordedCall {
  url: string;
  init: RequestInit;
}

type Responder = (call: RecordedCall) => Response | Promise<Response>;

/** Fake fetch: records every call and answers from a queue of responders. */
export function fakeFetch(...responders: Responder[]): typeof fetch & { calls: RecordedCall[] } {
  const calls: RecordedCall[] = [];
  const fn = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const call = { url: String(input), init: init ?? {} };
    calls.push(call);
    const responder = responders[Math.min(calls.length - 1, responders.length - 1)];
    if (!responder) throw new Error("fakeFetch: no responder");
    return responder(call);
  }) as typeof fetch & { calls: RecordedCall[] };
  fn.calls = calls;
  return fn;
}

export function json(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json", ...headers } });
}

export function text(status: number, body: string, headers: Record<string, string> = {}): Response {
  return new Response(body, { status, headers });
}

export function apiError(status: number, code: string, details?: unknown, message = "server-side text"): Response {
  return json(status, { error: { code, message, ...(details === undefined ? {} : { details }) } });
}

export const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
