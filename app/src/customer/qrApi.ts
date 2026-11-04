import { endpointUrl, type CustomerConfig } from "./config";
import { REQUEST_TIMEOUT_MS, type NonceBox } from "./customerApi";

/*
 * STAGE-05D: client for the member's own Club QR,
 * evangelou-club-customer/v1/me/qr (wordpress/evangelou-club/docs/member-qr.md).
 * Same transport rules as customerApi.ts: same origin, cookie session +
 * in-memory wp_rest nonce (shared NonceBox), no-store, no redirects, every
 * call bounded. Replies are parsed strictly; anything unexpected is "error".
 *
 *   GET  /me/qr  200 active (+ issued_at) | none | not_member | member_disabled
 *                404 (no such route: the QR feature is not switched on) -> off
 *   POST /me/qr  200 issued: the ONLY reply that carries the plaintext token
 *                409 qr_exists | concurrent_change, 404 not_a_member,
 *                403 member_disabled, 503 unavailable
 *   both         401 signed_out, other 403 forbidden
 *
 * The token is never logged, never put in a URL and never written to browser
 * storage (qrMemory.ts keeps it in memory only).
 */

export const QR_TOKEN_RE = /^evc_[0-9a-f]{48}$/;
const RFC3339 = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/;

export type QrStatus =
  | { kind: "active"; issuedAt: string }
  | { kind: "none" }
  | { kind: "not_member" }
  | { kind: "member_disabled" }
  | { kind: "off" }
  | { kind: "signed_out" }
  | { kind: "forbidden" }
  | { kind: "unavailable" }
  | { kind: "error" };

export type QrIssue =
  | { kind: "issued"; token: string; issuedAt: string }
  | { kind: "qr_exists" }
  | { kind: "concurrent_change" }
  | { kind: "not_member" }
  | { kind: "member_disabled" }
  | { kind: "signed_out" }
  | { kind: "forbidden" }
  | { kind: "unavailable" }
  | { kind: "error" };

export interface CustomerQrApi {
  status(): Promise<QrStatus>;
  /** `replace: true` only after the member confirmed a new QR (D6). */
  issue(replace: boolean): Promise<QrIssue>;
}

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === "object" && v !== null && !Array.isArray(v);
const isInstant = (v: unknown): v is string => typeof v === "string" && RFC3339.test(v) && !Number.isNaN(Date.parse(v));
const keysAre = (o: Record<string, unknown>, keys: string[]) => Object.keys(o).sort().join(",") === [...keys].sort().join(",");

function okQr(body: unknown): Record<string, unknown> | null {
  if (!isRecord(body) || !keysAre(body, ["result", "qr"]) || body.result !== "ok" || !isRecord(body.qr)) return null;
  return body.qr;
}

/** Strict parse of a GET 200 body; null = not the contract. */
export function parseQrStatus(body: unknown): QrStatus | null {
  const qr = okQr(body);
  if (!qr) return null;
  if (qr.state === "active") return keysAre(qr, ["state", "issued_at"]) && isInstant(qr.issued_at) ? { kind: "active", issuedAt: qr.issued_at } : null;
  if (qr.state === "none" || qr.state === "not_member" || qr.state === "member_disabled") return keysAre(qr, ["state"]) ? { kind: qr.state } : null;
  return null;
}

/** Strict parse of a POST 200 body; null = not the contract. */
export function parseQrIssued(body: unknown): { kind: "issued"; token: string; issuedAt: string } | null {
  const qr = okQr(body);
  if (!qr || !keysAre(qr, ["state", "token", "issued_at"]) || qr.state !== "active") return null;
  if (typeof qr.token !== "string" || !QR_TOKEN_RE.test(qr.token) || !isInstant(qr.issued_at)) return null;
  return { kind: "issued", token: qr.token, issuedAt: qr.issued_at };
}

function errorCode(body: unknown): string | null {
  return isRecord(body) && isRecord(body.error) && typeof body.error.code === "string" ? body.error.code : null;
}

function isUnavailable(body: unknown): boolean {
  return isRecord(body) && keysAre(body, ["result"]) && body.result === "unavailable";
}

export function createCustomerQrApi(
  config: CustomerConfig,
  nonceBox: NonceBox,
  fetchImpl: typeof fetch = (...args) => fetch(...args),
  timeoutMs = REQUEST_TIMEOUT_MS,
): CustomerQrApi {
  const url = endpointUrl(config.restBase, "/me/qr");

  async function call(method: "GET" | "POST", body?: string): Promise<{ status: number; body: unknown } | null> {
    if (new URL(url).origin !== new URL(config.restBase).origin) return null;
    const controller = new AbortController();
    let timer: ReturnType<typeof setTimeout> | undefined;
    const timeout = new Promise<null>((resolve) => {
      timer = setTimeout(() => {
        controller.abort();
        resolve(null);
      }, timeoutMs);
    });
    try {
      return await Promise.race([
        (async () => {
          const headers: Record<string, string> = { Accept: "application/json", "X-WP-Nonce": nonceBox.get() };
          if (body !== undefined) headers["Content-Type"] = "application/json";
          const res = await fetchImpl(url, {
            method,
            credentials: "same-origin",
            cache: "no-store",
            redirect: "error",
            headers,
            ...(body === undefined ? {} : { body }),
            signal: controller.signal,
          });
          nonceBox.update(res.headers.get("X-WP-Nonce"));
          let parsed: unknown = null;
          try {
            parsed = await res.json();
          } catch {
            parsed = null;
          }
          return { status: res.status, body: parsed };
        })(),
        timeout,
      ]);
    } catch {
      return null;
    } finally {
      clearTimeout(timer);
    }
  }

  return {
    async status() {
      const reply = await call("GET");
      if (reply === null) return { kind: "error" };
      if (reply.status === 200) return parseQrStatus(reply.body) ?? { kind: "error" };
      if (reply.status === 404) return { kind: "off" };
      if (reply.status === 401) return { kind: "signed_out" };
      if (reply.status === 403) return { kind: "forbidden" };
      if (reply.status === 503 && isUnavailable(reply.body)) return { kind: "unavailable" };
      return { kind: "error" };
    },

    async issue(replace) {
      const reply = await call("POST", JSON.stringify(replace ? { replace: true } : {}));
      if (reply === null) return { kind: "error" };
      const code = errorCode(reply.body);
      if (reply.status === 200) return parseQrIssued(reply.body) ?? { kind: "error" };
      if (reply.status === 409 && (code === "qr_exists" || code === "concurrent_change")) return { kind: code };
      if (reply.status === 404 && code === "not_a_member") return { kind: "not_member" };
      if (reply.status === 403 && code === "member_disabled") return { kind: "member_disabled" };
      if (reply.status === 401) return { kind: "signed_out" };
      if (reply.status === 403) return { kind: "forbidden" };
      if (reply.status === 503 && isUnavailable(reply.body)) return { kind: "unavailable" };
      return { kind: "error" };
    },
  };
}
