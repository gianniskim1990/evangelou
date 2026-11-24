import { endpointUrl, type CustomerConfig } from "./config";
import { REQUEST_TIMEOUT_MS, type NonceBox } from "./customerApi";

/*
 * STAGE-08 round 12 (owner decision D26 part 2): client for the member's own push notifications,
 * evangelou-club-customer/v1/me/push (wordpress/evangelou-club/docs/member-push.md). Same transport rules as
 * customerApi.ts / qrApi.ts: same origin, cookie session + in-memory wp_rest nonce, no-store, no redirects, every call
 * bounded. Replies are parsed strictly; anything unexpected is "error".
 *
 *   GET    /me/push  200 ready | not_member | member_disabled;  404 (push switched off) -> off;  503 -> unavailable
 *   POST   /me/push  {endpoint, keys: {p256dh, auth}, consent: true}  200 subscribed | 409 endpoint_in_use | ...
 *   DELETE /me/push  {endpoint}  200 stopped
 *   all              401 signed_out, other 403 forbidden
 *
 * The endpoint is a capability URL: it is never logged and never put in a URL; it goes only in the JSON body.
 */

export type PushStatus = { kind: "ready" } | { kind: "not_member" } | { kind: "member_disabled" } | { kind: "off" } | { kind: "signed_out" } | { kind: "forbidden" } | { kind: "unavailable" } | { kind: "error" };
export type PushWrite =
  | { kind: "subscribed" }
  | { kind: "stopped" }
  | { kind: "endpoint_in_use" }
  | { kind: "invalid" }
  | { kind: "not_member" }
  | { kind: "member_disabled" }
  | { kind: "off" }
  | { kind: "signed_out" }
  | { kind: "forbidden" }
  | { kind: "unavailable" }
  | { kind: "error" };

export interface PushSubscriptionJson {
  endpoint: string;
  keys: { p256dh: string; auth: string };
}

export interface CustomerPushApi {
  status(): Promise<PushStatus>;
  /** The member's explicit consent (the tap): store this browser's subscription. */
  subscribe(subscription: PushSubscriptionJson): Promise<PushWrite>;
  unsubscribe(endpoint: string): Promise<PushWrite>;
}

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === "object" && v !== null && !Array.isArray(v);
const keysAre = (o: Record<string, unknown>, keys: string[]) => Object.keys(o).sort().join(",") === [...keys].sort().join(",");

/** Strict parse of a 200 body: {"result":"ok","push":{"state": <one of states>}}. */
export function parsePushState(body: unknown, states: readonly string[]): string | null {
  if (!isRecord(body) || !keysAre(body, ["result", "push"]) || body.result !== "ok" || !isRecord(body.push)) return null;
  const push = body.push;
  return keysAre(push, ["state"]) && typeof push.state === "string" && states.includes(push.state) ? push.state : null;
}

function errorCode(body: unknown): string | null {
  return isRecord(body) && isRecord(body.error) && typeof body.error.code === "string" ? body.error.code : null;
}

function isUnavailable(body: unknown): boolean {
  return isRecord(body) && keysAre(body, ["result"]) && body.result === "unavailable";
}

export function createCustomerPushApi(config: CustomerConfig, nonceBox: NonceBox, fetchImpl: typeof fetch = (...args) => fetch(...args), timeoutMs = REQUEST_TIMEOUT_MS): CustomerPushApi {
  const url = endpointUrl(config.restBase, "/me/push");

  async function call(method: "GET" | "POST" | "DELETE", body?: string): Promise<{ status: number; body: unknown } | null> {
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

  function write(reply: { status: number; body: unknown } | null, ok: "subscribed" | "stopped"): PushWrite {
    if (reply === null) return { kind: "error" };
    const code = errorCode(reply.body);
    if (reply.status === 200) return parsePushState(reply.body, [ok]) === ok ? { kind: ok } : { kind: "error" };
    if (reply.status === 409 && code === "endpoint_in_use") return { kind: "endpoint_in_use" };
    if (reply.status === 400 && code === "invalid_request") return { kind: "invalid" };
    if (reply.status === 404 && code === "not_a_member") return { kind: "not_member" };
    if (reply.status === 404) return { kind: "off" };
    if (reply.status === 403 && code === "member_disabled") return { kind: "member_disabled" };
    if (reply.status === 401) return { kind: "signed_out" };
    if (reply.status === 403) return { kind: "forbidden" };
    if (reply.status === 503 && isUnavailable(reply.body)) return { kind: "unavailable" };
    return { kind: "error" };
  }

  return {
    async status() {
      const reply = await call("GET");
      if (reply === null) return { kind: "error" };
      if (reply.status === 200) {
        const state = parsePushState(reply.body, ["ready", "not_member", "member_disabled"]);
        return state === null ? { kind: "error" } : ({ kind: state } as PushStatus);
      }
      if (reply.status === 404) return { kind: "off" };
      if (reply.status === 401) return { kind: "signed_out" };
      if (reply.status === 403) return { kind: "forbidden" };
      if (reply.status === 503 && isUnavailable(reply.body)) return { kind: "unavailable" };
      return { kind: "error" };
    },
    async subscribe(subscription) {
      const body = JSON.stringify({ endpoint: subscription.endpoint, keys: { p256dh: subscription.keys.p256dh, auth: subscription.keys.auth }, consent: true });
      return write(await call("POST", body), "subscribed");
    },
    async unsubscribe(endpoint) {
      return write(await call("DELETE", JSON.stringify({ endpoint })), "stopped");
    },
  };
}
