/**
 * Bootstrap configuration for the REAL customer app (STAGE-01), delivered by
 * the protected WordPress /club-member/ page as inert JSON:
 *   <script type="application/json" id="evc-customer-config">…</script>
 *
 * Fail closed: anything malformed or pointing at another origin is rejected
 * and the app shows a configuration error instead of running. The REST base
 * may use pretty permalinks (…/wp-json/evangelou-club-customer/v1) or plain
 * permalinks (…/index.php?rest_route=/evangelou-club-customer/v1, or …/?rest_route=…).
 */

import { restRouteUrl, sameOriginRestBase } from "../lib/restRoute";
import { parseServiceWorker, type ServiceWorkerSetting } from "./pwa/register";

export const CONFIG_ELEMENT_ID = "evc-customer-config";
export const CUSTOMER_NAMESPACE = "/evangelou-club-customer/v1";
const NONCE_RE = /^[0-9a-f]{10}$/;

export interface CustomerConfig {
  /** Absolute same-origin URL of the customer REST namespace. */
  restBase: string;
  /** WordPress wp_rest nonce (in memory only; never persisted). */
  nonce: string;
  /** Native WordPress login (e-mail + password) that returns to the page. */
  loginUrl: string;
  /** Native WordPress logout (carries WordPress's own logout nonce). */
  logoutUrl: string;
  /**
   * STAGE-08 round 10: the in-store join flow (/club/join/ or ?evc_club_join=1), where a signed-in customer without a
   * membership joins and pays. Sent by the server only while that flow is on (EVC_CLUB_ONBOARDING_ENABLED); "" = none.
   */
  joinUrl?: string;
  /**
   * STAGE-08 round 11 (D26): the member app's service worker, sent only with pretty permalinks; null = none.
   * Optional like joinUrl: a bad value is dropped, never a reason to refuse the page.
   */
  serviceWorker?: ServiceWorkerSetting | null;
  appVersion: string;
}

export type CustomerConfigResult = { ok: true; config: CustomerConfig } | { ok: false; reason: string };

function sameOriginUrl(value: unknown, origin: string): URL | null {
  if (typeof value !== "string" || value === "") return null;
  try {
    const url = new URL(value, origin);
    if (url.origin !== origin || (url.protocol !== "https:" && url.protocol !== "http:")) return null;
    return url;
  } catch {
    return null;
  }
}

/** URL of a route under the REST base, for pretty and plain permalinks alike (src/lib/restRoute.ts). */
export function endpointUrl(restBase: string, route: string): string {
  return restRouteUrl(restBase, route);
}

export function parseCustomerConfig(raw: string | null | undefined, origin: string): CustomerConfigResult {
  if (!raw) return { ok: false, reason: "missing" };
  let data: unknown;
  try {
    data = JSON.parse(raw);
  } catch {
    return { ok: false, reason: "not_json" };
  }
  if (typeof data !== "object" || data === null || Array.isArray(data)) return { ok: false, reason: "not_object" };
  const d = data as Record<string, unknown>;
  // STAGE-08 round 4: the same shared rule as the staff app (one rest_route, exactly the namespace, at the site root).
  const restBase = sameOriginRestBase(d.restBase, origin, CUSTOMER_NAMESPACE, d.homePath);
  if (!restBase) return { ok: false, reason: "rest_base" };
  if (typeof d.nonce !== "string" || !NONCE_RE.test(d.nonce)) return { ok: false, reason: "nonce" };
  const loginUrl = sameOriginUrl(d.loginUrl, origin);
  if (!loginUrl) return { ok: false, reason: "login_url" };
  const logoutUrl = sameOriginUrl(d.logoutUrl, origin);
  if (!logoutUrl) return { ok: false, reason: "logout_url" };
  // Round 10: optional; when sent it must be this site with no credentials and no fragment, like the other URLs.
  // Round 11 (C2): a bad join URL is DROPPED (no «Γίνετε μέλος» / «Ανανέωση» button), it never stops the member app.
  let joinUrl = "";
  if (d.joinUrl !== undefined) {
    const url = sameOriginUrl(d.joinUrl, origin);
    joinUrl = url && url.username === "" && url.password === "" && url.hash === "" ? url.href : "";
  }
  return {
    ok: true,
    config: {
      restBase,
      nonce: d.nonce,
      loginUrl: loginUrl.href,
      logoutUrl: logoutUrl.href,
      joinUrl,
      serviceWorker: parseServiceWorker(d.serviceWorkerUrl, d.serviceWorkerScope, origin),
      appVersion: typeof d.appVersion === "string" ? d.appVersion : "",
    },
  };
}
