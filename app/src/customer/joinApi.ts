import type { NonceBox } from "./customerApi";
import { createNonceBox, REQUEST_TIMEOUT_MS } from "./customerApi";
import { endpointUrl } from "./config";
import { sameOrigin, type JoinConfig } from "./joinConfig";
import type { JoinForm } from "./joinRules";

/*
 * STAGE-07 client for the join routes (contract "join-v1", EVC_Rest_Join_Controller). Same-origin POSTs with the
 * page's wp_rest nonce (in memory only, refreshed from X-WP-Nonce), bounded in time, parsed strictly. Every URL the
 * server returns must be on this site, else the answer is an error: the client never navigates elsewhere.
 */

export type RegisterResult =
  | { kind: "ok" }
  | { kind: "invalid"; fields: Record<string, string> }
  | { kind: "check_account" }
  | { kind: "not_completed" }
  | { kind: "signed_in" }
  | { kind: "rate_limited" }
  | { kind: "try_later" }
  | { kind: "unavailable" }
  | { kind: "error" };

export type TermsResult = { kind: "ok" } | { kind: "invalid" } | { kind: "signed_out" } | { kind: "unavailable" } | { kind: "error" };
export type CheckoutResult = { kind: "checkout"; url: string } | { kind: "member"; url: string } | { kind: "terms_required" } | { kind: "signed_out" } | { kind: "unavailable" } | { kind: "error" };

export interface JoinApi {
  register(form: JoinForm, honeypot: string): Promise<RegisterResult>;
  acceptTerms(): Promise<TermsResult>;
  checkout(): Promise<CheckoutResult>;
}

const FIELD_CODE = /^[a-z_]{1,32}$/;
const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === "object" && v !== null && !Array.isArray(v);
const isEnvelope = (body: unknown, code: string) => isRecord(body) && isRecord(body.error) && body.error.code === code;

export function parseRegister(status: number, body: unknown): RegisterResult {
  if (status === 429 && isEnvelope(body, "rate_limited")) return { kind: "rate_limited" };
  if (status === 503 && isRecord(body) && body.result === "unavailable") return { kind: "unavailable" };
  if (status !== 200 || !isRecord(body)) return { kind: "error" };
  switch (body.result) {
    case "ok":
    case "check_account":
    case "not_completed":
    case "try_later":
    case "signed_in":
      return Object.keys(body).length === 1 ? { kind: body.result } : { kind: "error" };
    case "invalid": {
      if (!isRecord(body.fields)) return { kind: "error" };
      const fields: Record<string, string> = {};
      for (const [name, code] of Object.entries(body.fields)) {
        if (!["name", "phone", "email", "password"].includes(name) || typeof code !== "string" || !FIELD_CODE.test(code)) return { kind: "error" };
        fields[name] = code;
      }
      return { kind: "invalid", fields };
    }
    default:
      return { kind: "error" };
  }
}

export function parseTerms(status: number, body: unknown): TermsResult {
  if (status === 401) return { kind: "signed_out" };
  if (status === 503 && isRecord(body) && body.result === "unavailable") return { kind: "unavailable" };
  if (status === 200 && isRecord(body) && Object.keys(body).length === 1 && (body.result === "ok" || body.result === "invalid")) return { kind: body.result };
  return { kind: "error" };
}

export function parseCheckout(status: number, body: unknown, origin: string): CheckoutResult {
  if (status === 401) return { kind: "signed_out" };
  if (status === 503 && isRecord(body) && body.result === "unavailable") return { kind: "unavailable" };
  if (status !== 200 || !isRecord(body)) return { kind: "error" };
  if (body.result === "terms_required" && Object.keys(body).length === 1) return { kind: "terms_required" };
  if (body.result !== "ok" || (body.next !== "checkout" && body.next !== "member")) return { kind: "error" };
  const url = sameOrigin(body.url, origin);
  return url ? { kind: body.next, url } : { kind: "error" };
}

export function createJoinApi(
  config: JoinConfig,
  fetchImpl: typeof fetch = (...args) => fetch(...args),
  timeoutMs = REQUEST_TIMEOUT_MS,
  nonceBox: NonceBox = createNonceBox(config.nonce),
): JoinApi {
  const origin = new URL(config.restBase).origin;

  async function post(route: string, body: unknown): Promise<{ status: number; body: unknown } | null> {
    const url = endpointUrl(config.restBase, route);
    if (new URL(url).origin !== origin) return null;
    const controller = new AbortController();
    let timer: ReturnType<typeof setTimeout> | undefined;
    const timeout = new Promise<"timeout">((resolve) => {
      timer = setTimeout(() => {
        controller.abort();
        resolve("timeout");
      }, timeoutMs);
    });
    try {
      const reply = await Promise.race([
        (async () => {
          const res = await fetchImpl(url, {
            method: "POST",
            credentials: "same-origin",
            cache: "no-store",
            redirect: "error",
            headers: { Accept: "application/json", "Content-Type": "application/json", "X-WP-Nonce": nonceBox.get() },
            body: JSON.stringify(body),
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
      return reply === "timeout" ? null : reply;
    } catch {
      return null;
    } finally {
      clearTimeout(timer);
    }
  }

  return {
    async register(form, honeypot) {
      const reply = await post("/join/account", { name: form.name, phone: form.phone, email: form.email, password: form.password, evc_extra: honeypot });
      return reply ? parseRegister(reply.status, reply.body) : { kind: "error" };
    },
    async acceptTerms() {
      const reply = await post("/join/terms", { accept: true });
      return reply ? parseTerms(reply.status, reply.body) : { kind: "error" };
    },
    async checkout() {
      const reply = await post("/join/checkout", {});
      return reply ? parseCheckout(reply.status, reply.body, origin) : { kind: "error" };
    },
  };
}
