import { endpointUrl, type CustomerConfig } from "./config";

/*
 * Client for GET evangelou-club-customer/v1/me (STAGE-01, contract
 * "customer-me-v1"). Same-origin WordPress cookie session + wp_rest nonce
 * (in memory, refreshed from X-WP-Nonce). Every call is bounded. The reply is
 * parsed strictly; anything unexpected is an error, never a membership:
 *   200 {"result":"ok",...}        -> ok (parsed)
 *   503 {"result":"unavailable"}   -> unavailable (membership source not readable)
 *   401                            -> signed_out
 *   403                            -> forbidden (not a customer account)
 *   anything else / malformed      -> error
 */

export type MembershipState = "active" | "expired" | "cancelled" | "pending_payment" | "none" | "unverified";

export interface Me {
  displayName: string;
  email: string;
  state: MembershipState;
  /** Exact exclusive end of the paid period (active/expired only, when known). */
  paidUntilUtc: string | null;
}

export type MeResult = { kind: "ok"; me: Me } | { kind: "unavailable" } | { kind: "signed_out" } | { kind: "forbidden" } | { kind: "error" };

export interface CustomerApi {
  me(): Promise<MeResult>;
}

export const REQUEST_TIMEOUT_MS = 10_000;
const STATES: readonly MembershipState[] = ["active", "expired", "cancelled", "pending_payment", "none", "unverified"];
const RFC3339 = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/;
const NONCE_RE = /^[0-9a-f]{10}$/;

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === "object" && v !== null && !Array.isArray(v);

/** Strict parse of a 200 body; null = not the contract. */
export function parseMe(body: unknown): Me | null {
  if (!isRecord(body) || body.result !== "ok" || body.contract !== "customer-me-v1") return null;
  const { account, membership, club } = body;
  if (!isRecord(account) || !isRecord(membership) || !isRecord(club)) return null;
  if (typeof account.display_name !== "string" || typeof account.email !== "string") return null;
  const state = membership.state;
  if (typeof state !== "string" || !(STATES as readonly string[]).includes(state)) return null;
  const until = membership.paid_until_utc;
  if (until !== null && (typeof until !== "string" || !RFC3339.test(until) || Number.isNaN(Date.parse(until)))) return null;
  // Only active/expired may carry an end; an active membership MUST carry one.
  if (until !== null && state !== "active" && state !== "expired") return null;
  if (state === "active" && until === null) return null;
  // This client knows the Club database and QR are NOT connected; a server claiming otherwise needs a new client.
  if (club.coffee_today !== "not_connected" || club.card !== "sample_only") return null;
  return { displayName: account.display_name, email: account.email, state: state as MembershipState, paidUntilUtc: until };
}

export function createCustomerApi(config: CustomerConfig, fetchImpl: typeof fetch = (...args) => fetch(...args), timeoutMs = REQUEST_TIMEOUT_MS): CustomerApi {
  let nonce = config.nonce;
  const url = endpointUrl(config.restBase, "/me");
  return {
    async me() {
      if (new URL(url).origin !== new URL(config.restBase).origin) return { kind: "error" };
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
              method: "GET",
              credentials: "same-origin",
              cache: "no-store",
              redirect: "error",
              headers: { Accept: "application/json", "X-WP-Nonce": nonce },
              signal: controller.signal,
            });
            const fresh = res.headers.get("X-WP-Nonce");
            if (fresh && NONCE_RE.test(fresh)) nonce = fresh;
            let body: unknown = null;
            try {
              body = await res.json();
            } catch {
              body = null;
            }
            return { status: res.status, body };
          })(),
          timeout,
        ]);
        if (reply === "timeout") return { kind: "error" };
        if (reply.status === 200) {
          const me = parseMe(reply.body);
          return me ? { kind: "ok", me } : { kind: "error" };
        }
        if (reply.status === 503 && isRecord(reply.body) && reply.body.result === "unavailable" && Object.keys(reply.body).length === 1) return { kind: "unavailable" };
        if (reply.status === 401) return { kind: "signed_out" };
        if (reply.status === 403) return { kind: "forbidden" };
        return { kind: "error" };
      } catch {
        return { kind: "error" };
      } finally {
        clearTimeout(timer);
      }
    },
  };
}
