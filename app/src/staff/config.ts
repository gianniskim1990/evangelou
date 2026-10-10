/**
 * Bootstrap configuration for the REAL staff app, delivered by the protected
 * WordPress /club-admin/ shell as inert JSON:
 *   <script type="application/json" id="evc-staff-config">…</script>
 * (never executed; CSP-compatible; the server \u-escapes <, >, &, ', ").
 *
 * Fail closed: anything malformed or pointing at another origin is rejected
 * and the app shows a configuration error instead of running.
 */

export const CONFIG_ELEMENT_ID = "evc-staff-config";
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
  /** Absolute same-origin URL of the evangelou-club/v1 REST namespace (no trailing slash). */
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

  const restBase = sameOriginUrl(d.restBase, origin);
  if (!restBase || !/\/evangelou-club\/v1\/?$/.test(new URL(restBase).pathname)) return { ok: false, reason: "rest_base" };
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
      restBase: restBase.replace(/\/+$/, ""),
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
    },
  };
}
