/*
 * STAGE-08 round 11 (owner decision D26 part 1): the rules of the member app's service worker, pure so that the
 * tests can check every one of them (tests/club/pwa.test.ts). sw.ts applies them in the browser.
 *
 * The worker is registered ONLY with pretty permalinks, at <home>/club-member/sw.js with the scope
 * <home>/club-member/ (the member page and nothing else of the site: never the staff app, the join flow, the
 * shop, the checkout or wp-admin). With plain permalinks the member page is <home>/?evc_club_member=1, whose
 * path is the home path itself; a worker there would control the WHOLE site, the staff app included, so none is
 * registered (docs/member-pwa.md).
 *
 * What it does with a request of a page it controls:
 *   network   REST (any path under a REST prefix such as /wp-json/, or any URL with a rest_route parameter),
 *             any request that is not GET, any other origin, any URL carrying a nonce: the worker does not answer
 *             at all (no respondWith), so the browser fetches it normally and nothing is ever stored. These rules
 *             are checked FIRST: nothing below can ever apply to them.
 *   page      a navigation to the member page itself (the exact scope path, no query): straight from the network,
 *             never stored (its HTML carries the REST nonce and the logout link). Only when the network fails, the
 *             worker answers with an offline page that it builds from the cached app files (offlineShell): it has
 *             no nonce, no name, no account, and the app shows «no connection» with a retry button.
 *   asset     a GET of the app's own build files (the hashed names under customer-app/assets/): from the cache,
 *             else from the network and then stored. The cache name carries the build version, so a new build
 *             uses a new cache and the old ones are deleted when the new worker activates.
 *   ignore    everything else: not answered, the browser fetches it normally.
 */

export const CACHE_PREFIX = "evc-club-member-";

export interface SwConfig {
  /** Build version (plugin version + a hash of the app files); part of the cache name. */
  version: string;
  /** The worker's scope = the member page path, e.g. "/club-member/" or "/shop/club-member/". */
  scope: string;
  /** Absolute same-origin URL prefix of the app's build files, e.g. "https://x.gr/wp-content/plugins/evangelou-club/customer-app/assets/". */
  assetBase: string;
  /** The app's entry files (script + styles), stored when the worker installs. */
  precache: string[];
  /** Absolute path prefixes of the REST API, e.g. ["/wp-json/"]. */
  restPrefixes: string[];
  /** The offline page: the entry script and styles (from precache). */
  offline: { scripts: string[]; styles: string[]; appVersion: string };
}

export type SwDecision = "network" | "page" | "asset" | "ignore";

/** A hashed Vite build file name (the only kind the asset rule takes). */
const HASHED_ASSET = /^[A-Za-z0-9_-]+-[A-Za-z0-9_-]{8}\.(?:js|css|png|svg|webp|woff2?)$/;
/** Query parameters that mean "this URL carries a secret or a REST route": never answered by the worker. */
const NETWORK_PARAMS = ["rest_route", "_wpnonce", "_wp_http_referer", "nonce"];

export function cacheName(cfg: Pick<SwConfig, "version">): string {
  return CACHE_PREFIX + cfg.version;
}

/** True for a REST request, in the pretty (/wp-json/…) and the plain (?rest_route=…) form alike. */
export function isRest(url: URL, cfg: Pick<SwConfig, "restPrefixes">): boolean {
  if (url.searchParams.has("rest_route")) return true;
  const path = url.pathname.toLowerCase();
  return cfg.restPrefixes.some((p) => p !== "" && path.startsWith(p.toLowerCase()));
}

export function decide(request: { url: string; method: string; mode: string }, origin: string, cfg: SwConfig): SwDecision {
  let url: URL;
  try {
    url = new URL(request.url);
  } catch {
    return "network";
  }
  // Critical rules first: REST, non-GET, another origin and any secret-looking parameter are never touched.
  if (isRest(url, cfg)) return "network";
  if (request.method !== "GET") return "network";
  if (url.origin !== origin) return "network";
  if (NETWORK_PARAMS.some((p) => url.searchParams.has(p))) return "network";
  if (request.mode === "navigate") {
    return url.pathname === cfg.scope && url.search === "" ? "page" : "ignore";
  }
  if (url.search === "" && url.href.startsWith(cfg.assetBase) && HASHED_ASSET.test(url.href.slice(cfg.assetBase.length))) return "asset";
  return "ignore";
}

/** May this response be stored for an asset request? Only a complete, same-origin, successful answer. */
export function storable(response: { ok: boolean; status: number; type: string; headers: { get(name: string): string | null } }): boolean {
  if (!response.ok || response.status !== 200 || response.type !== "basic") return false;
  const control = (response.headers.get("Cache-Control") ?? "").toLowerCase();
  return !control.includes("no-store") && !control.includes("private");
}

/** Old caches of THIS app to delete when a new worker activates (other caches of the site are never touched). */
export function staleCaches(names: string[], cfg: Pick<SwConfig, "version">): string[] {
  const current = cacheName(cfg);
  return names.filter((n) => n.startsWith(CACHE_PREFIX) && n !== current);
}

const escapeAttr = (s: string) => s.replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;").replace(/>/g, "&gt;");

/** The self-only CSP of the member page (EVC_Rest_Security::SHELL_CSP), sent with the offline page too. */
export const OFFLINE_CSP =
  "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; media-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

/**
 * The offline page: the same app files, with a bootstrap that says only {"mode":"offline"}. No nonce, no URL with
 * a nonce, nothing about the member: it is built from the worker's own configuration, never from a stored page.
 */
export function offlineShell(cfg: SwConfig): string {
  const config = JSON.stringify({ mode: "offline", appVersion: cfg.offline.appVersion }).replace(/</g, "\\u003c").replace(/>/g, "\\u003e").replace(/&/g, "\\u0026");
  const styles = cfg.offline.styles.map((h) => `<link rel="stylesheet" href="${escapeAttr(h)}">\n`).join("");
  const scripts = cfg.offline.scripts.map((h) => `<script type="module" src="${escapeAttr(h)}"></script>\n`).join("");
  return (
    '<!doctype html>\n<html lang="el">\n<head>\n<meta charset="utf-8">\n<meta name="viewport" content="width=device-width, initial-scale=1">\n' +
    '<meta name="robots" content="noindex, nofollow">\n<meta name="referrer" content="no-referrer">\n<title>Evangelou Club</title>\n' +
    styles +
    scripts +
    '</head>\n<body>\n<div id="root"></div>\n<script type="application/json" id="evc-customer-config">' +
    config +
    "</script>\n</body>\n</html>\n"
  );
}

/**
 * The configuration PHP puts in front of the worker script (self.__EVC_SW__), checked before use: anything
 * missing or malformed and the worker answers NOTHING (every request goes to the network as without a worker).
 */
export function parseSwConfig(raw: unknown, origin: string): SwConfig | null {
  if (typeof raw !== "object" || raw === null) return null;
  const d = raw as Record<string, unknown>;
  const str = (v: unknown): v is string => typeof v === "string" && v !== "";
  const urls = (v: unknown): string[] | null => (Array.isArray(v) && v.every((x) => str(x) && sameOrigin(x, origin)) ? (v as string[]) : null);
  if (!str(d.version) || !/^[A-Za-z0-9._-]{1,80}$/.test(d.version)) return null;
  if (!str(d.scope) || !d.scope.startsWith("/") || !d.scope.endsWith("/club-member/")) return null;
  if (!str(d.assetBase) || !sameOrigin(d.assetBase, origin) || !d.assetBase.endsWith("/customer-app/assets/")) return null;
  const precache = urls(d.precache);
  if (!precache || precache.some((u) => !u.startsWith(d.assetBase as string))) return null;
  if (!Array.isArray(d.restPrefixes) || d.restPrefixes.length === 0 || !d.restPrefixes.every((p) => str(p) && p.startsWith("/") && p.endsWith("/"))) return null;
  const off = d.offline as Record<string, unknown> | undefined;
  if (typeof off !== "object" || off === null) return null;
  const scripts = urls(off.scripts);
  const styles = urls(off.styles);
  if (!scripts || scripts.length !== 1 || !styles || ![...scripts, ...styles].every((u) => precache.includes(u))) return null;
  return {
    version: d.version,
    scope: d.scope,
    assetBase: d.assetBase,
    precache,
    restPrefixes: d.restPrefixes as string[],
    offline: { scripts, styles, appVersion: typeof off.appVersion === "string" ? off.appVersion : "" },
  };
}

function sameOrigin(value: string, origin: string): boolean {
  try {
    const u = new URL(value);
    return u.origin === origin && u.username === "" && u.password === "" && u.hash === "";
  } catch {
    return false;
  }
}
