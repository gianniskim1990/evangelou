import { endpointUrl, type CustomerConfig } from "./config";
import { REQUEST_TIMEOUT_MS, type NonceBox } from "./customerApi";

/*
 * Owner decision D24 (round 9): client for the member card's own coffee
 * history («Ιστορικό»), GET evangelou-club-customer/v1/me/history (read only).
 * Same transport rules as coffeesApi.ts: same origin, cookie session +
 * in-memory wp_rest nonce (shared NonceBox), no-store, no redirects, every
 * call bounded. The reply is parsed strictly; anything unexpected is "error",
 * and the card keeps «δεν είναι ακόμη διαθέσιμο» (never a guessed coffee).
 *
 *   200 {"result":"ok","history":{"state":"member","items":[
 *        {"business_date":"YYYY-MM-DD","coffee":"<label>","details":["<label>",…]}, …]}}  newest first, ≤ 5
 *   200 {"result":"ok","history":{"state":"not_member" | "member_disabled"}}
 *   404 (no such route: an older plugin or the Club database not switched on) -> off
 *   401 signed_out · 403 forbidden · 503 {"result":"unavailable"} -> unavailable
 * The server never sends the staff note or anything about staff; this parser
 * would refuse any extra key anyway.
 */

export interface HistoryCoffee {
  /** The Europe/Athens business day of the coffee. */
  businessDate: string;
  coffee: string;
  details: string[];
}

export type HistoryResult =
  | { kind: "ok"; items: HistoryCoffee[] }
  | { kind: "not_member" }
  | { kind: "member_disabled" }
  | { kind: "off" }
  | { kind: "signed_out" }
  | { kind: "forbidden" }
  | { kind: "unavailable" }
  | { kind: "error" };

export interface CustomerHistoryApi {
  history(): Promise<HistoryResult>;
}

/** At most this many coffees are ever shown (the server sends at most 5). */
export const HISTORY_MAX_ITEMS = 5;

const DAY_RE = /^(\d{4})-(\d{2})-(\d{2})$/;
const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === "object" && v !== null && !Array.isArray(v);
const keysAre = (o: Record<string, unknown>, keys: string[]) => Object.keys(o).sort().join(",") === [...keys].sort().join(",");
const isText = (v: unknown): v is string => typeof v === "string" && v.trim() !== "" && [...v].length <= 64;

/** A real calendar day «YYYY-MM-DD». */
function isDay(v: unknown): v is string {
  if (typeof v !== "string") return false;
  const m = DAY_RE.exec(v);
  if (!m) return false;
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  return d.toISOString().slice(0, 10) === v;
}

/** Strict parse of a 200 body; null = not the contract. */
export function parseHistory(body: unknown): HistoryResult | null {
  if (!isRecord(body) || !keysAre(body, ["result", "history"]) || body.result !== "ok" || !isRecord(body.history)) return null;
  const h = body.history;
  if (h.state === "not_member" || h.state === "member_disabled") return keysAre(h, ["state"]) ? { kind: h.state } : null;
  if (h.state !== "member" || !keysAre(h, ["state", "items"]) || !Array.isArray(h.items) || h.items.length > HISTORY_MAX_ITEMS) return null;
  const items: HistoryCoffee[] = [];
  let previous: string | null = null;
  for (const item of h.items) {
    if (!isRecord(item) || !keysAre(item, ["business_date", "coffee", "details"])) return null;
    const { business_date: day, coffee, details } = item;
    if (!isDay(day) || !isText(coffee) || !Array.isArray(details) || details.length > 20 || !details.every(isText)) return null;
    // Newest first, at most one free coffee per Athens day.
    if (previous !== null && day >= previous) return null;
    previous = day;
    items.push({ businessDate: day, coffee, details: details as string[] });
  }
  return { kind: "ok", items };
}

export function createCustomerHistoryApi(
  config: CustomerConfig,
  nonceBox: NonceBox,
  fetchImpl: typeof fetch = (...args) => fetch(...args),
  timeoutMs = REQUEST_TIMEOUT_MS,
): CustomerHistoryApi {
  return {
    async history() {
      let url: string;
      try {
        url = endpointUrl(config.restBase, "/me/history");
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
        if (reply.status === 200) return parseHistory(reply.body) ?? { kind: "error" };
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
