/*
 * STAGE-08 round 11 (owner decision D26 part 1): registers the member app's service worker (pwa/sw.ts) when the
 * member page asks for it. The page sends serviceWorkerUrl + serviceWorkerScope only with pretty permalinks
 * (EVC_Customer_Shell); with plain permalinks it sends neither and nothing is registered (the scope would be the
 * whole site, the staff app included: docs/member-pwa.md). A bad value is dropped, never an error: the app works
 * the same without a worker.
 */

export interface ServiceWorkerSetting {
  url: string;
  scope: string;
}

/** The worker's URL and scope from the bootstrap, or null (missing, another site, or not the member page path). */
export function parseServiceWorker(url: unknown, scope: unknown, origin: string): ServiceWorkerSetting | null {
  if (typeof url !== "string" || typeof scope !== "string" || url === "" || scope === "") return null;
  let u: URL;
  try {
    u = new URL(url);
  } catch {
    return null;
  }
  if (u.origin !== origin || u.username !== "" || u.password !== "" || u.search !== "" || u.hash !== "") return null;
  if (!scope.startsWith("/") || !scope.endsWith("/club-member/") || scope.includes("..") || scope.includes("?") || scope.includes("#")) return null;
  if (u.pathname !== `${scope}sw.js`) return null;
  return { url: u.href, scope };
}

interface Registrar {
  register(url: string, options: { scope: string; updateViaCache: "none" }): Promise<unknown>;
}

/** Registers the worker if the browser can and this page is inside its scope. Never throws. */
export async function registerMemberWorker(setting: ServiceWorkerSetting | null, nav: { serviceWorker?: Registrar } | undefined, pathname: string): Promise<boolean> {
  if (!setting || !nav?.serviceWorker || !pathname.startsWith(setting.scope)) return false;
  try {
    await nav.serviceWorker.register(setting.url, { scope: setting.scope, updateViaCache: "none" });
    return true;
  } catch {
    return false;
  }
}
