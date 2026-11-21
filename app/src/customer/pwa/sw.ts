/*
 * STAGE-08 round 11 (owner decision D26 part 1): the member app's service worker. Built by
 * scripts/package-club-plugin.mjs (esbuild, one classic script, not minified) into customer-app/pwa/sw.js; the
 * plugin serves it at <home>/club-member/sw.js with its configuration in front (self.__EVC_SW__,
 * EVC_Customer_Shell::service_worker()). The rules are in swRules.ts (tested there); this file only applies them.
 * No push, no background sync, no storage other than the versioned cache of the app's own build files.
 */
import { cacheName, decide, offlineShell, OFFLINE_CSP, parseSwConfig, staleCaches, storable, type SwConfig } from "./swRules";

type FetchEventLike = Event & { request: Request; respondWith(r: Promise<Response>): void };
type ExtendableEventLike = Event & { waitUntil(p: Promise<unknown>): void };
interface WorkerScope {
  __EVC_SW__?: unknown;
  location: { origin: string };
  caches: CacheStorage;
  clients: { claim(): Promise<void> };
  skipWaiting(): Promise<void>;
  addEventListener(type: string, fn: (e: Event) => void): void;
}

const sw = self as unknown as WorkerScope;
const cfg: SwConfig | null = parseSwConfig(sw.__EVC_SW__, sw.location.origin);

async function precache(c: SwConfig): Promise<void> {
  const cache = await sw.caches.open(cacheName(c));
  // One by one: a file that cannot be fetched now is simply fetched later (the asset rule), never a failed install.
  await Promise.all(
    c.precache.map(async (url) => {
      try {
        const response = await fetch(url, { credentials: "same-origin", cache: "no-cache" });
        if (storable(response)) await cache.put(url, response);
      } catch {
        // offline at install: nothing stored
      }
    }),
  );
}

async function cleanUp(c: SwConfig): Promise<void> {
  const names = await sw.caches.keys();
  await Promise.all(staleCaches(names, c).map((n) => sw.caches.delete(n)));
}

async function asset(c: SwConfig, request: Request): Promise<Response> {
  const cache = await sw.caches.open(cacheName(c));
  const hit = await cache.match(request.url);
  if (hit) return hit;
  const response = await fetch(request);
  if (storable(response)) await cache.put(request.url, response.clone());
  return response;
}

async function page(c: SwConfig, request: Request): Promise<Response> {
  try {
    // Never stored: the member page carries the REST nonce.
    return await fetch(request);
  } catch {
    return new Response(offlineShell(c), {
      status: 200,
      headers: { "Content-Type": "text/html; charset=utf-8", "Cache-Control": "no-store", "Content-Security-Policy": OFFLINE_CSP, "Referrer-Policy": "no-referrer", "X-Content-Type-Options": "nosniff" },
    });
  }
}

sw.addEventListener("install", (e) => {
  const event = e as ExtendableEventLike;
  event.waitUntil((cfg ? precache(cfg) : Promise.resolve()).then(() => sw.skipWaiting()));
});

sw.addEventListener("activate", (e) => {
  const event = e as ExtendableEventLike;
  event.waitUntil((cfg ? cleanUp(cfg) : Promise.resolve()).then(() => sw.clients.claim()));
});

sw.addEventListener("fetch", (e) => {
  const event = e as FetchEventLike;
  if (!cfg) return;
  const decision = decide({ url: event.request.url, method: event.request.method, mode: event.request.mode }, sw.location.origin, cfg);
  // "network" and "ignore": no answer from the worker at all; the browser fetches the request itself.
  if (decision === "asset") event.respondWith(asset(cfg, event.request));
  else if (decision === "page") event.respondWith(page(cfg, event.request));
});
