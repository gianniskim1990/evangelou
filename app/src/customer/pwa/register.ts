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

interface RegistrationSource {
  getRegistration(scope?: string): Promise<{ pushManager?: unknown } | undefined>;
  ready?: Promise<{ pushManager?: unknown; scope?: string }>;
}

/** How long a first visit waits for the worker the page has just registered. */
export const WORKER_READY_WAIT_MS = 5000;

/**
 * Round 12 (D26 part 2): the push manager of the member app's OWN worker registration (the round-11 worker, scope
 * /club-member/), or null (no worker setting, no service worker support, not registered, no Push). Never registers a
 * worker itself and never asks for permission.
 */
export async function pushManagerOf(setting: ServiceWorkerSetting | null, nav: { serviceWorker?: RegistrationSource } | undefined): Promise<import("./push").PushManagerLike | null> {
  if (!setting || !nav?.serviceWorker) return null;
  try {
    let registration: { pushManager?: unknown; scope?: string } | undefined = await nav.serviceWorker.getRegistration(setting.scope);
    if (!registration && nav.serviceWorker.ready) {
      // A first visit: the page registered the worker a moment ago (main.tsx); wait for it, never for ever.
      let timer: ReturnType<typeof setTimeout> | undefined;
      registration = await Promise.race([
        nav.serviceWorker.ready.then((r) => (typeof r.scope !== "string" || new URL(r.scope).pathname === setting.scope ? r : undefined)),
        new Promise<undefined>((resolve) => {
          timer = setTimeout(() => resolve(undefined), WORKER_READY_WAIT_MS);
        }),
      ]).finally(() => clearTimeout(timer));
    }
    const manager = registration?.pushManager as import("./push").PushManagerLike | undefined;
    return manager && typeof manager.subscribe === "function" && typeof manager.getSubscription === "function" ? manager : null;
  } catch {
    return null;
  }
}
