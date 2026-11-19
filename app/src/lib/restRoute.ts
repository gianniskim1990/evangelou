/**
 * STAGE-08 round 4: WordPress REST URLs in BOTH permalink forms, shared by the staff and the customer apps.
 *
 *   pretty permalinks: https://shop.example/sub/wp-json/<namespace>            -> …/wp-json/<namespace><route>
 *   plain permalinks:  https://shop.example/sub/index.php?rest_route=<namespace> (rest_url() adds index.php; a bare
 *                      …/sub/?rest_route=<namespace> is accepted too)        -> rest_route=<namespace><route>
 *
 * Staging ran with Settings → Permalinks = «Plain» (2026-11-18); the staff app then refused its own REST base and
 * showed «Η εφαρμογή δεν είναι διαθέσιμη». Both forms are now accepted, and nothing else (fail closed):
 *   - the base is on the page's origin, with no credentials and no fragment (not even an empty «#»);
 *   - the raw path has no dot-segment («.», «..», «%2e», «%2e%2e»), checked BEFORE the URL parser removes them;
 *   - a plain base sits exactly at the site's home path (`homePath`, e.g. "/" or "/sub/", sent by the WordPress
 *     shell as wp_parse_url(home_url('/'), PHP_URL_PATH)) or at its index.php, and carries exactly one parameter that
 *     PHP reads as rest_route, equal to the namespace. Without a valid homePath a plain base is refused;
 *   - a pretty base ends with the namespace and carries NO parameter that PHP reads as rest_route; with a valid
 *     homePath its path must also start with that home path (round 6).
 *
 * Round 6: a tab, CR or LF anywhere in the base is refused: the WHATWG URL parser strips them, so «/.\t./» would
 * slip past the dot-segment check and then be normalised away. A relative base must be root-relative (exactly one
 * leading «/»): restRouteUrl() refuses anything else instead of resolving it.
 *
 * Round 5: PHP turns «.», « » and an unmatched «[» in a parameter NAME into «_» (and the part before a matched «[»
 * names an array), so `?rest_route=/evangelou-club/v1&rest.route=/wp/v2/users` reaches WordPress as
 * rest_route=/wp/v2/users (the later key wins). phpParamName() below applies those rules, and every such alias counts.
 *
 * Routes appended here are made of URL-safe segments (fixed words, encodeURIComponent'ed IDs of [0-9a-z_]); in the
 * plain form URLSearchParams encodes the whole rest_route value, and keeps every other query parameter. A
 * root-relative base (the Vercel demo's "/wp-json/…") goes through the same code, so both forms are encoded the same
 * way.
 */

const REST_ROUTE = "rest_route";

/** The name PHP registers for a decoded query parameter name (main/php_variables.c): the name ends at a NUL byte,
 *  leading spaces are dropped, the part before a matched «[» is the (array) name, and « », «.» and an unmatched «[»
 *  become «_». Characters after an unmatched «[» are left as they are (PHP 8; older PHP converted them too, which
 *  phpParamNames() also covers). */
function phpParamNames(decoded: string): string[] {
  let name = decoded;
  const nul = name.indexOf("\0");
  if (nul >= 0) name = name.slice(0, nul);
  name = name.replace(/^ +/, "");
  const open = name.indexOf("[");
  if (open < 0) return [name.replace(/[ .]/g, "_")];
  const head = name.slice(0, open).replace(/[ .]/g, "_");
  if (name.indexOf("]", open) > open) return [head];
  const tail = name.slice(open + 1);
  return [`${head}_${tail}`, `${head}_${tail.replace(/[ .[]/g, "_")}`];
}

/** How many query parameters of `url` WordPress would read as rest_route. */
function restRouteAliases(url: URL): number {
  let count = 0;
  for (const key of url.searchParams.keys()) {
    if (phpParamNames(key).includes(REST_ROUTE)) count += 1;
  }
  return count;
}

/** A dot-segment in the raw path (before the URL parser resolves it): «.», «..» or their %2e forms. */
function hasDotSegment(raw: string): boolean {
  const path = raw.split(/[?#]/, 1)[0];
  return /(?:^|[/\\])(?:\.|%2e){1,2}(?=[/\\]|$)/i.test(path);
}

/** Tab, CR, LF: removed by the WHATWG URL parser wherever they appear, so never allowed in a value it will parse. */
const STRIPPED_BY_URL_PARSER = /[\t\r\n]/;

/** "/" or "/sub/": an absolute path that ends with "/", in the URL parser's own (encoded) form. */
function validHomePath(homePath: unknown, origin: string): string | null {
  if (typeof homePath !== "string" || !homePath.startsWith("/") || homePath.startsWith("//") || !homePath.endsWith("/")) return null;
  if (STRIPPED_BY_URL_PARSER.test(homePath)) return null;
  if (/[?#\\]/.test(homePath) || hasDotSegment(homePath)) return null;
  try {
    const parsed = new URL(homePath, origin);
    return parsed.origin === origin && parsed.pathname === homePath ? homePath : null;
  } catch {
    return null;
  }
}

/** The validated base URL (href), or null. `namespace` is e.g. "/evangelou-club/v1"; `homePath` is the site's home
 *  path from the shell's config (required for a plain base). */
export function sameOriginRestBase(value: unknown, origin: string, namespace: string, homePath?: unknown): string | null {
  if (typeof value !== "string" || value === "") return null;
  if (STRIPPED_BY_URL_PARSER.test(value)) return null;
  if (value.includes("#") || hasDotSegment(value)) return null;
  let url: URL;
  try {
    url = new URL(value, origin);
  } catch {
    return null;
  }
  if (url.origin !== origin || (url.protocol !== "https:" && url.protocol !== "http:")) return null;
  if (url.username !== "" || url.password !== "") return null;
  const aliases = restRouteAliases(url);
  const routes = url.searchParams.getAll(REST_ROUTE);
  if (routes.length > 0) {
    if (aliases !== 1 || routes.length !== 1 || routes[0] !== namespace) return null;
    // Exactly the site's home path, or its index.php: never another script, page or folder.
    const home = validHomePath(homePath, origin);
    if (home === null || (url.pathname !== home && url.pathname !== `${home}index.php`)) return null;
    return url.href;
  }
  if (aliases !== 0) return null;
  const path = url.pathname.replace(/\/+$/, "");
  if (!path.endsWith(namespace)) return null;
  // Round 6: under the site's home path when the shell sent one (a subfolder install: /sub/wp-json/…).
  const home = homePath === undefined ? null : validHomePath(homePath, origin);
  if (home !== null && !path.startsWith(home)) return null;
  url.pathname = path;
  return url.href;
}

/** A placeholder origin for relative bases (the Vercel demo's "/wp-json/…"); it never leaves this function. */
const RELATIVE_ORIGIN = "http://relative.invalid";

/** The URL of `route` (e.g. "/session") under a REST base of either form. A relative base must be root-relative
 *  (exactly one leading "/"; never "//host", "\\host" or "wp-json/…"), keeps its relative form and is built by the
 *  same code as an absolute one. Anything else throws (callers report it as a network error). */
export function restRouteUrl(base: string, route: string): string {
  let url: URL;
  let relative = false;
  if (STRIPPED_BY_URL_PARSER.test(base)) throw new TypeError("REST base with a tab or line break");
  try {
    url = new URL(base);
  } catch {
    if (!/^\/(?![/\\])/.test(base)) throw new TypeError("REST base is neither absolute nor root-relative");
    url = new URL(base, RELATIVE_ORIGIN);
    relative = true;
  }
  const current = url.searchParams.get(REST_ROUTE);
  if (current !== null) {
    url.searchParams.set(REST_ROUTE, `${current.replace(/\/+$/, "")}${route}`);
  } else {
    url.pathname = `${url.pathname.replace(/\/+$/, "")}${route}`;
  }
  return relative ? `${url.pathname}${url.search}${url.hash}` : url.href;
}
