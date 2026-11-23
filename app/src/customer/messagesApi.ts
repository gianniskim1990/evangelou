import { endpointUrl, type CustomerConfig } from "./config";
import { REQUEST_TIMEOUT_MS, type NonceBox } from "./customerApi";

/*
 * STAGE-08 round 18, owner decision D30 (2026-11-23): client for the member's own in-app messages,
 * GET evangelou-club-customer/v1/me/messages and /me/messages/<id> (read only; wordpress/evangelou-club/docs/
 * member-push.md «D30»). Same transport rules as pushApi.ts / historyApi.ts: same origin, cookie session + in-memory
 * wp_rest nonce (shared NonceBox), no-store, no redirects, every call bounded. Replies are parsed strictly; anything
 * unexpected is "error".
 *
 *   GET /me/messages       200 {"result":"ok","messages":[{"id","title","summary","sent_at","unread"}…],"unread":N}
 *   GET /me/messages/<id>  200 {"result":"ok","message":{"id","title","summary","sent_at","html"}}
 *                          404 message_not_found -> not_found
 *   both                   404 not_a_member -> not_member · 403 member_disabled · 404 (no route) -> off
 *                          401 signed_out · other 403 forbidden · 503 {"result":"unavailable"} -> unavailable
 * The html is the server's sanitised body; it is only ever drawn through richText.tsx (an allowlist renderer).
 */

export interface MessageEntry {
  id: number;
  title: string;
  summary: string;
  /** ISO 8601 UTC instant. */
  sentAt: string;
  unread: boolean;
}

export interface MessageFull {
  id: number;
  title: string;
  summary: string;
  sentAt: string;
  html: string;
}

type Refusal = { kind: "not_member" } | { kind: "member_disabled" } | { kind: "off" } | { kind: "signed_out" } | { kind: "forbidden" } | { kind: "unavailable" } | { kind: "error" };
export type MessagesList = { kind: "ok"; messages: MessageEntry[]; unread: number } | Refusal;
export type MessageRead = { kind: "ok"; message: MessageFull } | { kind: "not_found" } | Refusal;

export interface CustomerMessagesApi {
  list(): Promise<MessagesList>;
  get(id: number): Promise<MessageRead>;
}

/** The server lists at most this many messages. */
export const MESSAGES_MAX = 50;
export const MAX_MESSAGE_ID = 2147483647;
const MAX_HTML = 20000;
const INSTANT = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === "object" && v !== null && !Array.isArray(v);
const keysAre = (o: Record<string, unknown>, keys: string[]) => Object.keys(o).sort().join(",") === [...keys].sort().join(",");
const isText = (v: unknown, max: number): v is string => typeof v === "string" && v.trim() !== "" && [...v].length <= max;

/** A message number: a positive whole number within the server's range (anything else is refused). */
export function isMessageId(v: unknown): v is number {
  return typeof v === "number" && Number.isInteger(v) && v >= 1 && v <= MAX_MESSAGE_ID;
}

/** «#message=<id>» (the notification tap) -> the id, or null for anything else. */
export function messageIdFromHash(hash: string | null | undefined): number | null {
  if (typeof hash !== "string") return null;
  const m = /^#message=([1-9][0-9]{0,9})$/.exec(hash);
  if (!m) return null;
  const id = Number(m[1]);
  return isMessageId(id) ? id : null;
}

function isInstant(v: unknown): v is string {
  return typeof v === "string" && INSTANT.test(v) && !Number.isNaN(Date.parse(v));
}

/** Strict parse of the list's 200 body; null = not the contract. */
export function parseMessageList(body: unknown): MessagesList | null {
  if (!isRecord(body) || !keysAre(body, ["result", "messages", "unread"]) || body.result !== "ok" || !Array.isArray(body.messages) || body.messages.length > MESSAGES_MAX) return null;
  const messages: MessageEntry[] = [];
  let previous = Number.POSITIVE_INFINITY;
  for (const m of body.messages) {
    if (!isRecord(m) || !keysAre(m, ["id", "title", "summary", "sent_at", "unread"])) return null;
    if (!isMessageId(m.id) || !isText(m.title, 50) || !isText(m.summary, 150) || !isInstant(m.sent_at) || typeof m.unread !== "boolean") return null;
    // Newest first, each message once.
    if (m.id >= previous) return null;
    previous = m.id;
    messages.push({ id: m.id, title: m.title, summary: m.summary, sentAt: m.sent_at, unread: m.unread });
  }
  const unread = body.unread;
  if (typeof unread !== "number" || unread !== messages.filter((m) => m.unread).length) return null;
  return { kind: "ok", messages, unread };
}

/** Strict parse of one message's 200 body (the id must be the one asked for); null = not the contract. */
export function parseMessage(body: unknown, id: number): MessageRead | null {
  if (!isRecord(body) || !keysAre(body, ["result", "message"]) || body.result !== "ok" || !isRecord(body.message)) return null;
  const m = body.message;
  if (!keysAre(m, ["id", "title", "summary", "sent_at", "html"]) || m.id !== id || !isMessageId(m.id)) return null;
  if (!isText(m.title, 50) || !isText(m.summary, 150) || !isInstant(m.sent_at) || typeof m.html !== "string" || m.html.length > MAX_HTML) return null;
  return { kind: "ok", message: { id: m.id, title: m.title, summary: m.summary, sentAt: m.sent_at, html: m.html } };
}

function errorCode(body: unknown): string | null {
  return isRecord(body) && isRecord(body.error) && typeof body.error.code === "string" ? body.error.code : null;
}

function refusal(status: number, body: unknown): Refusal {
  const code = errorCode(body);
  if (status === 404 && code === "not_a_member") return { kind: "not_member" };
  if (status === 403 && code === "member_disabled") return { kind: "member_disabled" };
  if (status === 404) return { kind: "off" };
  if (status === 401) return { kind: "signed_out" };
  if (status === 403) return { kind: "forbidden" };
  if (status === 503 && isRecord(body) && keysAre(body, ["result"]) && body.result === "unavailable") return { kind: "unavailable" };
  return { kind: "error" };
}

export function createCustomerMessagesApi(config: CustomerConfig, nonceBox: NonceBox, fetchImpl: typeof fetch = (...args) => fetch(...args), timeoutMs = REQUEST_TIMEOUT_MS): CustomerMessagesApi {
  async function call(route: string): Promise<{ status: number; body: unknown } | null> {
    const url = endpointUrl(config.restBase, route);
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
    } catch {
      return null;
    } finally {
      clearTimeout(timer);
    }
  }

  return {
    async list() {
      const reply = await call("/me/messages");
      if (reply === null) return { kind: "error" };
      if (reply.status === 200) return parseMessageList(reply.body) ?? { kind: "error" };
      return refusal(reply.status, reply.body);
    },
    async get(id) {
      if (!isMessageId(id)) return { kind: "not_found" };
      const reply = await call(`/me/messages/${id}`);
      if (reply === null) return { kind: "error" };
      if (reply.status === 200) return parseMessage(reply.body, id) ?? { kind: "error" };
      if (reply.status === 404 && errorCode(reply.body) === "message_not_found") return { kind: "not_found" };
      return refusal(reply.status, reply.body);
    },
  };
}
