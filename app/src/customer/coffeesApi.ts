import { endpointUrl, type CustomerConfig } from "./config";
import { REQUEST_TIMEOUT_MS, type NonceBox } from "./customerApi";

/*
 * Owner decision D23 (2026-11-19): client for the member card's own coffee
 * figures, GET evangelou-club-customer/v1/me/coffees (read only). Same
 * transport rules as customerApi.ts / qrApi.ts: same origin, cookie session +
 * in-memory wp_rest nonce (shared NonceBox), no-store, no redirects, every
 * call bounded. The reply is parsed strictly; anything unexpected is "error",
 * and the card then shows «—» (never a guessed figure).
 *
 *   200 {"result":"ok","coffees":{"state":"member","today":"YYYY-MM-DD",
 *        "taken_today":bool,"this_month":int}}         -> ok
 *   200 {"result":"ok","coffees":{"state":"not_member" | "member_disabled"}}
 *   404 (no such route: the Club database is not switched on) -> off
 *   401 signed_out · 403 forbidden · 503 {"result":"unavailable"} -> unavailable
 */

export interface MemberCoffees {
  /** The Europe/Athens business day the figures belong to (the server's clock). */
  today: string;
  /** Today's free coffee was already given at the till. */
  takenToday: boolean;
  /** Free coffees in today's Athens calendar month (today included). */
  thisMonth: number;
}

export type CoffeesResult =
  | { kind: "ok"; coffees: MemberCoffees }
  | { kind: "not_member" }
  | { kind: "member_disabled" }
  | { kind: "off" }
  | { kind: "signed_out" }
  | { kind: "forbidden" }
  | { kind: "unavailable" }
  | { kind: "error" };

export interface CustomerCoffeesApi {
  coffees(): Promise<CoffeesResult>;
}

const DAY_RE = /^(\d{4})-(\d{2})-(\d{2})$/;
const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === "object" && v !== null && !Array.isArray(v);
const keysAre = (o: Record<string, unknown>, keys: string[]) => Object.keys(o).sort().join(",") === [...keys].sort().join(",");

/** A real calendar day «YYYY-MM-DD». */
function isDay(v: unknown): v is string {
  if (typeof v !== "string") return false;
  const m = DAY_RE.exec(v);
  if (!m) return false;
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  return d.toISOString().slice(0, 10) === v;
}

/** Strict parse of a 200 body; null = not the contract. */
export function parseCoffees(body: unknown): CoffeesResult | null {
  if (!isRecord(body) || !keysAre(body, ["result", "coffees"]) || body.result !== "ok" || !isRecord(body.coffees)) return null;
  const c = body.coffees;
  if (c.state === "not_member" || c.state === "member_disabled") return keysAre(c, ["state"]) ? { kind: c.state } : null;
  if (c.state !== "member" || !keysAre(c, ["state", "today", "taken_today", "this_month"])) return null;
  const month = c.this_month;
  if (!isDay(c.today) || typeof c.taken_today !== "boolean" || typeof month !== "number" || !Number.isInteger(month)) return null;
  // One free coffee per Athens day: at most 31 in a month, and today's (if taken) is one of them.
  if (month < 0 || month > 31 || (c.taken_today && month < 1)) return null;
  return { kind: "ok", coffees: { today: c.today, takenToday: c.taken_today, thisMonth: month } };
}

export function createCustomerCoffeesApi(
  config: CustomerConfig,
  nonceBox: NonceBox,
  fetchImpl: typeof fetch = (...args) => fetch(...args),
  timeoutMs = REQUEST_TIMEOUT_MS,
): CustomerCoffeesApi {
  return {
    async coffees() {
      let url: string;
      try {
        url = endpointUrl(config.restBase, "/me/coffees");
        if (new URL(url).origin !== new URL(config.restBase).origin) return { kind: "error" };
      } catch {
        return { kind: "error" };
      }
      const controller = new AbortController();
      let timer: ReturnType<typeof setTimeout> | undefined;
      const timeout = new Promise<null>((resolve) => {
        timer = setTimeout(() => {
          controller.abort();
          resolve(null);
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
              headers: { Accept: "application/json", "X-WP-Nonce": nonceBox.get() },
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
        if (reply === null) return { kind: "error" };
        if (reply.status === 200) return parseCoffees(reply.body) ?? { kind: "error" };
        if (reply.status === 404) return { kind: "off" };
        if (reply.status === 401) return { kind: "signed_out" };
        if (reply.status === 403) return { kind: "forbidden" };
        if (reply.status === 503 && isRecord(reply.body) && keysAre(reply.body, ["result"]) && reply.body.result === "unavailable") return { kind: "unavailable" };
        return { kind: "error" };
      } catch {
        return { kind: "error" };
      } finally {
        clearTimeout(timer);
      }
    },
  };
}
