/**
 * Bootstrap configuration for the REAL staff app, delivered by the protected
 * WordPress /club-admin/ shell as inert JSON:
 *   <script type="application/json" id="evc-staff-config">…</script>
 * (never executed; CSP-compatible; the server \u-escapes <, >, &, ', ").
 *
 * Fail closed: anything malformed or pointing at another origin is rejected
 * and the app shows a configuration error instead of running.
 */

import { sameOriginRestBase } from "../lib/restRoute";

export const CONFIG_ELEMENT_ID = "evc-staff-config";
/** The staff REST namespace (pretty: …/wp-json/evangelou-club/v1; plain: …/index.php?rest_route=/evangelou-club/v1). */
export const STAFF_NAMESPACE = "/evangelou-club/v1";
/** Owner-approved tablet idle lock. The client never waits LONGER than this. */
export const MAX_IDLE_LOCK_SECONDS = 300;
const NONCE_RE = /^[0-9a-f]{10}$/;
const SESSION_REF_RE = /^[0-9a-f]{32}$/;

export interface StaffFeatures {
  qrLookup: boolean;
  phoneLookup: boolean;
  coffeeRedemption: boolean;
  history: boolean;
}

export interface StaffConfig {
  /** Absolute same-origin URL of the evangelou-club/v1 REST namespace, pretty or plain permalinks (no trailing
   *  slash); build request URLs with restRouteUrl() (src/lib/restRoute.ts), never by string concatenation. */
  restBase: string;
  /** WordPress wp_rest nonce (in memory only; never persisted). */
  nonce: string;
  /** wp-login.php?reauth=1 URL that returns to the shell. */
  loginUrl: string;
  shellUrl: string;
  idleLockSeconds: number;
  sessionRef: string;
  appVersion: string;
  features: StaffFeatures;
  /** Round 18 (D31): «Χρήσιμοι σύνδεσμοι»; each one this site's address (a bad entry is dropped, never a reason to refuse). */
  links?: StaffLink[];
}

/** STAGE-08 round 18, owner decision D31: the useful Club addresses the till app may show (never an administrator page). */
export const STAFF_LINK_KEYS = ["join", "poster", "member", "member_login", "till"] as const;
export type StaffLinkKey = (typeof STAFF_LINK_KEYS)[number];
export interface StaffLink {
  key: StaffLinkKey;
  url: string;
}

export type ConfigResult = { ok: true; config: StaffConfig } | { ok: false; reason: string };

function sameOriginUrl(value: unknown, origin: string): string | null {
  if (typeof value !== "string" || value === "") return null;
  try {
    const url = new URL(value, origin);
    if (url.origin !== origin) return null;
    if (url.protocol !== "https:" && url.protocol !== "http:") return null;
    return url.href;
  } catch {
    return null;
  }
}

/**
 * Round 18 (D31): the links of the server's config: only the known keys, each once, each a same-origin http(s) address
 * with no user name, password or fragment. Anything else is dropped (the card then shows fewer links).
 */
export function parseStaffLinks(value: unknown, origin: string): StaffLink[] {
  if (!Array.isArray(value)) return [];
  const out: StaffLink[] = [];
  for (const item of value.slice(0, 20)) {
    if (typeof item !== "object" || item === null || Array.isArray(item)) continue;
    const { key, url } = item as Record<string, unknown>;
    if (!(STAFF_LINK_KEYS as readonly unknown[]).includes(key) || out.some((l) => l.key === key)) continue;
    const href = sameOriginUrl(url, origin);
    if (href === null) continue;
    const parsed = new URL(href);
    if (parsed.username !== "" || parsed.password !== "" || parsed.hash !== "") continue;
    out.push({ key: key as StaffLinkKey, url: href });
  }
  return out;
}

export function isValidNonce(value: unknown): value is string {
  return typeof value === "string" && NONCE_RE.test(value);
}

export function parseStaffConfig(raw: string | null | undefined, origin: string): ConfigResult {
  if (!raw) return { ok: false, reason: "missing" };
  let data: unknown;
  try {
    data = JSON.parse(raw);
  } catch {
    return { ok: false, reason: "not_json" };
  }
  if (typeof data !== "object" || data === null || Array.isArray(data)) return { ok: false, reason: "not_object" };
  const d = data as Record<string, unknown>;

  // STAGE-08 round 4: plain permalinks (staging, 2026-11-18) give …/index.php?rest_route=/evangelou-club/v1.
  const restBase = sameOriginRestBase(d.restBase, origin, STAFF_NAMESPACE, d.homePath);
  if (!restBase) return { ok: false, reason: "rest_base" };
  if (!isValidNonce(d.nonce)) return { ok: false, reason: "nonce" };
  const loginUrl = sameOriginUrl(d.loginUrl, origin);
  if (!loginUrl) return { ok: false, reason: "login_url" };
  const shellUrl = sameOriginUrl(d.shellUrl, origin);
  if (!shellUrl) return { ok: false, reason: "shell_url" };
  const idle = d.idleLockSeconds;
  if (typeof idle !== "number" || !Number.isInteger(idle) || idle < 30) return { ok: false, reason: "idle_lock" };
  if (typeof d.sessionRef !== "string" || (d.sessionRef !== "" && !SESSION_REF_RE.test(d.sessionRef))) {
    return { ok: false, reason: "session_ref" };
  }
  const f = (typeof d.features === "object" && d.features !== null ? d.features : {}) as Record<string, unknown>;

  return {
    ok: true,
    config: {
      restBase,
      nonce: d.nonce,
      loginUrl,
      shellUrl,
      idleLockSeconds: Math.min(idle, MAX_IDLE_LOCK_SECONDS),
      sessionRef: d.sessionRef,
      appVersion: typeof d.appVersion === "string" ? d.appVersion : "",
      features: {
        qrLookup: f.qrLookup === true,
        phoneLookup: f.phoneLookup === true,
        coffeeRedemption: f.coffeeRedemption === true,
        history: f.history === true,
      },
      links: parseStaffLinks(d.links, origin),
    },
  };
}
