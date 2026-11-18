/**
 * STAGE-08 round 4: WordPress REST URLs in BOTH permalink forms, shared by the staff and the customer apps.
 *
 *   pretty permalinks: https://shop.example/sub/wp-json/<namespace>            -> …/wp-json/<namespace><route>
 *   plain permalinks:  https://shop.example/sub/index.php?rest_route=<namespace> (rest_url() adds index.php; a bare
 *                      …/sub/?rest_route=<namespace> is accepted too)        -> rest_route=<namespace><route>
 *
 * Staging ran with Settings → Permalinks = «Plain» (2026-11-18); the staff app then refused its own REST base and
 * showed «Η εφαρμογή δεν είναι διαθέσιμη». Both forms are now accepted, and nothing else: the base must be on the
 * page's origin, a plain base must sit at the site (or sub-folder) root or its index.php and carry exactly one
 * rest_route equal to the namespace, and a pretty base must end with the namespace.
 *
 * Routes appended here are made of URL-safe segments (fixed words, encodeURIComponent'ed IDs of [0-9a-z_]); in the
 * plain form URLSearchParams encodes the whole rest_route value, and keeps every other query parameter.
 */

/** The validated base URL (href), or null. `namespace` is e.g. "/evangelou-club/v1". */
export function sameOriginRestBase(value: unknown, origin: string, namespace: string): string | null {
  if (typeof value !== "string" || value === "") return null;
  let url: URL;
  try {
    url = new URL(value, origin);
  } catch {
    return null;
  }
  if (url.origin !== origin || (url.protocol !== "https:" && url.protocol !== "http:")) return null;
  if (url.hash !== "" || url.username !== "" || url.password !== "") return null;
  const routes = url.searchParams.getAll("rest_route");
  if (routes.length > 0) {
    if (routes.length !== 1 || routes[0] !== namespace) return null;
    // The site root of WordPress (or of its sub-folder), or its index.php: never another script or page.
    if (!/\/(?:index\.php)?$/.test(url.pathname)) return null;
    return url.href;
  }
  const path = url.pathname.replace(/\/+$/, "");
  if (!path.endsWith(namespace)) return null;
  url.pathname = path;
  return url.href;
}

/** The URL of `route` (e.g. "/session") under a REST base of either form. A relative base (the Vercel demo's
 *  "/wp-json/…") is joined as text, as before. */
export function restRouteUrl(base: string, route: string): string {
  let url: URL;
  try {
    url = new URL(base);
  } catch {
    return `${base.replace(/\/+$/, "")}${route}`;
  }
  const current = url.searchParams.get("rest_route");
  if (current !== null) {
    url.searchParams.set("rest_route", `${current.replace(/\/+$/, "")}${route}`);
    return url.href;
  }
  url.pathname = `${url.pathname.replace(/\/+$/, "")}${route}`;
  return url.href;
}
