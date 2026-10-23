/**
 * Bootstrap configuration for the REAL customer app (STAGE-01), delivered by
 * the protected WordPress /club-member/ page as inert JSON:
 *   <script type="application/json" id="evc-customer-config">…</script>
 *
 * Fail closed: anything malformed or pointing at another origin is rejected
 * and the app shows a configuration error instead of running. The REST base
 * may use pretty permalinks (…/wp-json/evangelou-club-customer/v1) or plain
 * permalinks (…/?rest_route=/evangelou-club-customer/v1, the current staging).
 */

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

function isCustomerRestBase(url: URL): boolean {
  if (url.searchParams.has("rest_route")) return url.searchParams.get("rest_route")!.replace(/\/+$/, "") === CUSTOMER_NAMESPACE;
  return url.pathname.replace(/\/+$/, "").endsWith(CUSTOMER_NAMESPACE);
}

/** URL of a route under the REST base, for pretty and plain permalinks alike. */
export function endpointUrl(restBase: string, route: string): string {
  const url = new URL(restBase);
  if (url.searchParams.has("rest_route")) {
    url.searchParams.set("rest_route", `${url.searchParams.get("rest_route")!.replace(/\/+$/, "")}${route}`);
    return url.href;
  }
  url.pathname = `${url.pathname.replace(/\/+$/, "")}${route}`;
  return url.href;
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
  const restBase = sameOriginUrl(d.restBase, origin);
  if (!restBase || !isCustomerRestBase(restBase)) return { ok: false, reason: "rest_base" };
  if (typeof d.nonce !== "string" || !NONCE_RE.test(d.nonce)) return { ok: false, reason: "nonce" };
  const loginUrl = sameOriginUrl(d.loginUrl, origin);
  if (!loginUrl) return { ok: false, reason: "login_url" };
  const logoutUrl = sameOriginUrl(d.logoutUrl, origin);
  if (!logoutUrl) return { ok: false, reason: "logout_url" };
  return {
    ok: true,
    config: {
      restBase: restBase.href,
      nonce: d.nonce,
      loginUrl: loginUrl.href,
      logoutUrl: logoutUrl.href,
      appVersion: typeof d.appVersion === "string" ? d.appVersion : "",
    },
  };
}
