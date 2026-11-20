import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, it } from "node:test";
import { parseCustomerConfig } from "../../src/customer/config";
import { bootMode } from "../../src/customer/joinConfig";
import { INSTALL_COPY, InstallHintFlow, installHintKind, isIosSafari, isStandalone, type DeferredPrompt } from "../../src/customer/pwa/installHint";
import { parseServiceWorker, registerMemberWorker } from "../../src/customer/pwa/register";
import { CACHE_PREFIX, OFFLINE_CSP, cacheName, decide, offlineShell, parseSwConfig, staleCaches, storable, type SwConfig } from "../../src/customer/pwa/swRules";
import { createDeviceFlag, INSTALL_HINT_KEY, QR_STORE_PREFIX } from "../../src/customer/qrStore";

/*
 * STAGE-08 round 11 (owner decision D26 part 1): the member page as a home-screen app. The service worker's rules
 * (above all: REST is never touched), its configuration, its registration (pretty permalinks only, scope
 * /club-member/), the «Εγκατάσταση στο κινητό» hint and the icons. No push (round 12).
 */

const ORIGIN = "https://shop.example";
const APP_DIR = process.env.EVC_APP_DIR ?? process.cwd();
const ASSETS = `${ORIGIN}/wp-content/plugins/evangelou-club/customer-app/assets/`;
const JS = `${ASSETS}customer-Pm2qN4Fw.js`;
const CSS = `${ASSETS}customer-BDoG6FiH.css`;
const RAW_CFG = {
  version: "0.1.0-0123456789abcdef",
  scope: "/club-member/",
  assetBase: ASSETS,
  precache: [JS, CSS],
  restPrefixes: ["/wp-json/"],
  offline: { scripts: [JS], styles: [CSS], appVersion: "0.1.0" },
};
const CFG = parseSwConfig(RAW_CFG, ORIGIN) as SwConfig;

const get = (url: string, mode = "cors") => ({ url, method: "GET", mode });

describe("round 11 service worker rules: what is never touched", () => {
  it("parses the configuration PHP puts in front of the worker", () => {
    assert.ok(CFG, "the shipped shape is accepted");
    assert.equal(CFG.scope, "/club-member/");
    const bad: [string, Record<string, unknown>][] = [
      ["scope of the whole site", { scope: "/" }],
      ["scope of the staff app", { scope: "/club-admin/" }],
      ["assets of another site", { assetBase: "https://cdn.evil.example/customer-app/assets/" }],
      ["assets outside the app", { assetBase: `${ORIGIN}/wp-content/uploads/` }],
      ["a precached file outside the app", { precache: [JS, CSS, `${ORIGIN}/wp-json/evangelou-club-customer/v1/me`] }],
      ["no REST prefix", { restPrefixes: [] }],
      ["a REST prefix that is not a path", { restPrefixes: ["wp-json"] }],
      ["an offline script that is not precached", { offline: { scripts: [`${ASSETS}other-AAAAAAAA.js`], styles: [CSS] } }],
      ["a strange version", { version: "a b" }],
    ];
    for (const [label, over] of bad) assert.equal(parseSwConfig({ ...RAW_CFG, ...over }, ORIGIN), null, label);
    assert.equal(parseSwConfig(null, ORIGIN), null);
    assert.equal(parseSwConfig("x", ORIGIN), null);
  });

  it("REST is network-only in every form, checked before anything else", () => {
    const rest = [
      `${ORIGIN}/wp-json/evangelou-club-customer/v1/me`,
      `${ORIGIN}/wp-json/evangelou-club-customer/v1/me/qr`,
      `${ORIGIN}/wp-json/evangelou-club-customer/v1/me/coffees`,
      `${ORIGIN}/WP-JSON/evangelou-club-customer/v1/me`,
      `${ORIGIN}/?rest_route=/evangelou-club-customer/v1/me`,
      `${ORIGIN}/index.php?rest_route=/evangelou-club-customer/v1/me/history`,
      `${ORIGIN}/club-member/?rest_route=/x`,
      // A REST URL that looks like an app file or the member page is still REST.
      `${ASSETS}customer-Pm2qN4Fw.js?rest_route=/x`,
    ];
    for (const url of rest) {
      assert.equal(decide(get(url), ORIGIN, CFG), "network", url);
      assert.equal(decide(get(url, "navigate"), ORIGIN, CFG), "network", `${url} (navigation)`);
    }
    const sub = parseSwConfig({ ...RAW_CFG, scope: "/shop/club-member/", restPrefixes: ["/shop/wp-json/"] }, ORIGIN)!;
    assert.equal(decide(get(`${ORIGIN}/shop/wp-json/evangelou-club-customer/v1/me`), ORIGIN, sub), "network", "a site in a sub-folder");
  });

  it("anything not GET, another origin, or a URL with a nonce is never answered by the worker", () => {
    for (const method of ["POST", "PUT", "DELETE", "PATCH"]) assert.equal(decide({ url: JS, method, mode: "cors" }, ORIGIN, CFG), "network", method);
    assert.equal(decide(get("https://cdn.example/customer-Pm2qN4Fw.js"), ORIGIN, CFG), "network");
    for (const p of ["_wpnonce=abc", "nonce=1", "_wp_http_referer=x"]) assert.equal(decide(get(`${ORIGIN}/club-member/?${p}`, "navigate"), ORIGIN, CFG), "network", p);
    assert.equal(decide(get("not a url"), ORIGIN, CFG), "network");
  });

  it("only the member page itself is a page navigation; the staff app, the join flow and the shop are not touched", () => {
    assert.equal(decide(get(`${ORIGIN}/club-member/`, "navigate"), ORIGIN, CFG), "page");
    for (const url of [`${ORIGIN}/club-member/?x=1`, `${ORIGIN}/club-admin/`, `${ORIGIN}/club/join/`, `${ORIGIN}/checkout/`, `${ORIGIN}/wp-admin/`, `${ORIGIN}/club-member/sw.js`, `${ORIGIN}/`]) {
      assert.equal(decide(get(url, "navigate"), ORIGIN, CFG), "ignore", url);
    }
  });

  it("only the app's own hashed build files are cached", () => {
    for (const url of [JS, CSS, `${ASSETS}logo-evaggelou-color-8WeF70gP.png`]) assert.equal(decide(get(url), ORIGIN, CFG), "asset", url);
    for (const url of [`${JS}?v=1`, `${ASSETS}index.php`, `${ASSETS}customer.js`, `${ORIGIN}/wp-content/plugins/evangelou-club/staff-app/assets/staff-CUbNQ7PZ.js`, `${ORIGIN}/wp-content/plugins/evangelou-club/customer-app/manifest.json`, `${ORIGIN}/club-member/manifest.webmanifest`, `${ORIGIN}/wp-content/plugins/evangelou-club/customer-app/pwa/icon-192.png`]) {
      assert.equal(decide(get(url), ORIGIN, CFG), "ignore", url);
    }
  });

  it("stores only complete same-origin answers without no-store / private", () => {
    const r = (status: number, type: string, cc: string | null) => ({ ok: status >= 200 && status < 300, status, type, headers: { get: (n: string) => (n === "Cache-Control" ? cc : null) } });
    assert.equal(storable(r(200, "basic", null)), true);
    assert.equal(storable(r(200, "basic", "public, max-age=31536000")), true);
    assert.equal(storable(r(200, "basic", "no-store")), false);
    assert.equal(storable(r(200, "basic", "private, max-age=0")), false);
    assert.equal(storable(r(206, "basic", null)), false);
    assert.equal(storable(r(404, "basic", null)), false);
    assert.equal(storable(r(200, "opaque", null)), false);
    assert.equal(storable(r(200, "cors", null)), false);
  });

  it("a new build gets a new cache; on activate only this app's older caches are deleted", () => {
    assert.equal(cacheName(CFG), `${CACHE_PREFIX}0.1.0-0123456789abcdef`);
    assert.deepEqual(staleCaches([cacheName(CFG), `${CACHE_PREFIX}0.1.0-old`, `${CACHE_PREFIX}0.0.9-x`, "woocommerce-cache", "other-plugin"], CFG), [`${CACHE_PREFIX}0.1.0-old`, `${CACHE_PREFIX}0.0.9-x`]);
  });

  it("the offline page carries no nonce and nothing about the member: the app files and mode «offline» only", () => {
    const html = offlineShell({ ...CFG, offline: { ...CFG.offline, appVersion: '</script><b>"' } });
    assert.match(html, /<script type="module" src="https:\/\/shop\.example\/wp-content\/plugins\/evangelou-club\/customer-app\/assets\/customer-Pm2qN4Fw\.js"><\/script>/);
    assert.match(html, /<link rel="stylesheet" href="[^"]+customer-BDoG6FiH\.css">/);
    const config = html.match(/<script type="application\/json" id="evc-customer-config">(.*?)<\/script>/s)![1];
    assert.deepEqual(JSON.parse(config), { mode: "offline", appVersion: '</script><b>"' });
    assert.doesNotMatch(config, /</, "cannot break out of its element");
    assert.doesNotMatch(html, /nonce|logout|wp-login|rest_route|wp-json/i);
    assert.equal(bootMode(config), "offline");
    const php = readFileSync(join(APP_DIR, "..", "wordpress", "evangelou-club", "includes", "staff", "class-evc-rest-security.php"), "utf8");
    assert.ok(php.includes(`const SHELL_CSP = "${OFFLINE_CSP}";`), "the offline page has the member page's CSP");
  });
});

/** A fake service worker scope around the real sw.ts (loaded once with this environment). */
async function workerEnv() {
  const handlers: Record<string, (e: unknown) => void> = {};
  const store = new Map<string, Map<string, Response>>([[`${CACHE_PREFIX}old`, new Map()], ["other-plugin", new Map()]]);
  const puts: string[] = [];
  const matches: string[] = [];
  const caches = {
    open: async (name: string) => {
      if (!store.has(name)) store.set(name, new Map());
      const m = store.get(name)!;
      return {
        match: async (u: string) => (matches.push(u), m.get(u)?.clone()),
        put: async (u: string, r: Response) => {
          puts.push(u);
          m.set(u, r);
        },
      };
    },
    keys: async () => [...store.keys()],
    delete: async (n: string) => store.delete(n),
  };
  const fetched: string[] = [];
  let online = true;
  const realFetch = globalThis.fetch;
  globalThis.fetch = (async (input: unknown) => {
    const url = typeof input === "string" ? input : (input as { url: string }).url;
    fetched.push(url);
    if (!online) throw new TypeError("Failed to fetch");
    return new Response(`body of ${url}`, { status: 200, headers: { "Content-Type": "text/plain" } });
  }) as typeof fetch;
  (globalThis as Record<string, unknown>).self = { __EVC_SW__: RAW_CFG, location: { origin: ORIGIN }, caches, clients: { claim: async () => {} }, skipWaiting: async () => {}, addEventListener: (t: string, f: (e: unknown) => void) => (handlers[t] = f) };
  await import("../../src/customer/pwa/sw");
  // Responses made by `new Response()` have type "default"; the worker only stores "basic" ones (a real fetch).
  const asBasic = async () => {
    globalThis.fetch = (async (input: unknown) => {
      const url = typeof input === "string" ? input : (input as { url: string }).url;
      fetched.push(url);
      if (!online) throw new TypeError("Failed to fetch");
      const r = new Response(`body of ${url}`, { status: 200 });
      Object.defineProperty(r, "type", { value: "basic" });
      Object.defineProperty(r, "clone", { value: () => Object.defineProperty(new Response(`body of ${url}`, { status: 200 }), "type", { value: "basic" }) });
      return r;
    }) as typeof fetch;
  };
  await asBasic();
  const fire = async (request: { url: string; method: string; mode: string }) => {
    let answer: Promise<Response> | null = null;
    handlers.fetch({ request, respondWith: (p: Promise<Response>) => (answer = p) });
    return answer === null ? null : await (answer as Promise<Response>);
  };
  const lifecycle = async (type: "install" | "activate") => {
    const waits: Promise<unknown>[] = [];
    handlers[type]({ waitUntil: (p: Promise<unknown>) => waits.push(p) });
    await Promise.all(waits);
  };
  return { handlers, store, puts, matches, fetched, fire, lifecycle, setOnline: (v: boolean) => (online = v), restore: () => (globalThis.fetch = realFetch) };
}

describe("round 11 service worker in action (sw.ts over a fake worker scope)", () => {
  it("installs the app files, cleans up on activate, never answers REST, never stores the member page", async () => {
    const env = await workerEnv();
    try {
      await env.lifecycle("install");
      assert.deepEqual(env.puts.sort(), [CSS, JS].sort(), "the entry files are precached");
      await env.lifecycle("activate");
      assert.deepEqual([...env.store.keys()].sort(), [cacheName(CFG), "other-plugin"].sort(), "the old app cache is gone, other caches stay");
      env.puts.length = 0;
      env.matches.length = 0;

      for (const url of [`${ORIGIN}/wp-json/evangelou-club-customer/v1/me`, `${ORIGIN}/?rest_route=/evangelou-club-customer/v1/me/qr`, `${ORIGIN}/wp-json/evangelou-club-customer/v1/me/coffees`]) {
        assert.equal(await env.fire(get(url)), null, `${url}: not answered by the worker`);
        assert.equal(await env.fire({ url, method: "POST", mode: "cors" }), null);
      }
      assert.deepEqual(env.matches, [], "REST never looked up in the cache");
      assert.deepEqual(env.puts, [], "REST never stored");

      const page = await env.fire(get(`${ORIGIN}/club-member/`, "navigate"));
      assert.equal(await page!.text(), `body of ${ORIGIN}/club-member/`, "the member page comes from the network");
      assert.deepEqual(env.puts, [], "and is never stored (it carries the nonce)");

      env.setOnline(false);
      const offline = await env.fire(get(`${ORIGIN}/club-member/`, "navigate"));
      assert.equal(offline!.headers.get("Content-Type"), "text/html; charset=utf-8");
      assert.equal(offline!.headers.get("Cache-Control"), "no-store");
      assert.equal(offline!.headers.get("Content-Security-Policy"), OFFLINE_CSP);
      const html = await offline!.text();
      assert.match(html, /"mode":"offline"/);
      assert.doesNotMatch(html, /nonce/);
      const cached = await env.fire(get(JS));
      assert.equal(await cached!.text(), `body of ${JS}`, "offline: the app files come from the cache");
      assert.equal(await env.fire(get(`${ORIGIN}/club-admin/`, "navigate")), null, "the staff app is never answered");
      env.setOnline(true);

      const logo = `${ASSETS}logo-evaggelou-color-8WeF70gP.png`;
      await env.fire(get(logo));
      assert.deepEqual(env.puts, [logo], "an app file met later is stored once");
      env.fetched.length = 0;
      await env.fire(get(logo));
      assert.deepEqual(env.fetched, [], "then served from the cache");
    } finally {
      env.restore();
    }
  });
});

describe("round 11 registration: pretty permalinks only, scope /club-member/", () => {
  it("accepts only this site's /club-member/sw.js with its own scope", () => {
    assert.deepEqual(parseServiceWorker(`${ORIGIN}/club-member/sw.js`, "/club-member/", ORIGIN), { url: `${ORIGIN}/club-member/sw.js`, scope: "/club-member/" });
    assert.deepEqual(parseServiceWorker(`${ORIGIN}/shop/club-member/sw.js`, "/shop/club-member/", ORIGIN), { url: `${ORIGIN}/shop/club-member/sw.js`, scope: "/shop/club-member/" });
    const bad: [unknown, unknown][] = [
      [`${ORIGIN}/club-member/sw.js`, "/"],
      [`${ORIGIN}/sw.js`, "/club-member/"],
      [`${ORIGIN}/club-admin/sw.js`, "/club-admin/"],
      ["https://evil.example/club-member/sw.js", "/club-member/"],
      [`${ORIGIN}/club-member/sw.js?x=1`, "/club-member/"],
      [`${ORIGIN}/club-member/sw.js#x`, "/club-member/"],
      [`${ORIGIN}/?evc_club_member=sw`, "/"],
      [`${ORIGIN}/club-member/../sw.js`, "/club-member/../"],
      [undefined, undefined],
      [7, "/club-member/"],
    ];
    for (const [url, scope] of bad) assert.equal(parseServiceWorker(url, scope, ORIGIN), null, `${String(url)} ${String(scope)}`);
  });

  it("registers with the scope and no HTTP cache for the script; never outside the scope; never throws", async () => {
    const calls: unknown[][] = [];
    const nav = { serviceWorker: { register: async (...a: unknown[]) => void calls.push(a) } };
    const setting = parseServiceWorker(`${ORIGIN}/club-member/sw.js`, "/club-member/", ORIGIN);
    assert.equal(await registerMemberWorker(setting, nav, "/club-member/"), true);
    assert.deepEqual(calls, [[`${ORIGIN}/club-member/sw.js`, { scope: "/club-member/", updateViaCache: "none" }]]);
    assert.equal(await registerMemberWorker(setting, nav, "/"), false, "plain permalinks page (/?evc_club_member=1)");
    assert.equal(await registerMemberWorker(null, nav, "/club-member/"), false);
    assert.equal(await registerMemberWorker(setting, {}, "/club-member/"), false, "no service worker support");
    assert.equal(await registerMemberWorker(setting, { serviceWorker: { register: async () => Promise.reject(new Error("SecurityError")) } }, "/club-member/"), false);
    assert.equal(calls.length, 1);
  });

  it("the page's worker setting is optional: a bad value is dropped, never a reason to refuse the page", () => {
    const base = { restBase: `${ORIGIN}/wp-json/evangelou-club-customer/v1`, homePath: "/", nonce: "0123456789", loginUrl: `${ORIGIN}/wp-login.php`, logoutUrl: `${ORIGIN}/wp-login.php?action=logout`, appVersion: "t" };
    const parsed = (over: Record<string, unknown>) => parseCustomerConfig(JSON.stringify({ ...base, ...over }), ORIGIN);
    const none = parsed({});
    assert.ok(none.ok);
    assert.equal(none.config.serviceWorker, null);
    const good = parsed({ serviceWorkerUrl: `${ORIGIN}/club-member/sw.js`, serviceWorkerScope: "/club-member/" });
    assert.ok(good.ok);
    assert.deepEqual(good.config.serviceWorker, { url: `${ORIGIN}/club-member/sw.js`, scope: "/club-member/" });
    const bad = parsed({ serviceWorkerUrl: "https://evil.example/club-member/sw.js", serviceWorkerScope: "/" });
    assert.ok(bad.ok, "the member app still starts");
    assert.equal(bad.config.serviceWorker, null);
  });
});

class FakeTarget {
  readonly listeners = new Map<string, ((e: Event) => void)[]>();
  addEventListener(type: string, fn: (e: Event) => void) {
    this.listeners.set(type, [...(this.listeners.get(type) ?? []), fn]);
  }
  emit(type: string, e: unknown) {
    for (const fn of this.listeners.get(type) ?? []) fn(e as Event);
  }
}

function promptEvent(outcome: "accepted" | "dismissed" | Error) {
  const ev = { prevented: 0, prompted: 0 } as { prevented: number; prompted: number } & DeferredPrompt;
  ev.preventDefault = () => void ev.prevented++;
  ev.prompt = async () => {
    ev.prompted++;
    if (outcome instanceof Error) throw outcome;
  };
  ev.userChoice = outcome instanceof Error ? Promise.resolve({}) : Promise.resolve({ outcome });
  return ev;
}

function memoryFlag() {
  const data = new Map<string, string>();
  const storage = { getItem: (k: string) => data.get(k) ?? null, setItem: (k: string, v: string) => void data.set(k, v), removeItem: (k: string) => void data.delete(k), key: () => null, length: 0 };
  return { data, flag: createDeviceFlag(storage)! };
}

describe("round 11 «Εγκατάσταση στο κινητό»: helpful once, never nagging", () => {
  it("decides which hint, if any", () => {
    const base = { standalone: false, promptReady: false, ios: false, dismissed: false, installed: false };
    assert.equal(installHintKind(base), null, "nothing to offer");
    assert.equal(installHintKind({ ...base, promptReady: true }), "prompt");
    assert.equal(installHintKind({ ...base, ios: true }), "ios");
    for (const off of ["standalone", "dismissed", "installed"] as const) {
      assert.equal(installHintKind({ ...base, promptReady: true, ios: true, [off]: true }), null, off);
    }
  });

  it("knows Safari on an iPhone or iPad from the other iOS browsers", () => {
    const safari = "Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1";
    const ipad = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15";
    assert.equal(isIosSafari(safari, 5), true);
    assert.equal(isIosSafari(ipad, 5), true, "iPadOS presents itself as a Mac with touch");
    assert.equal(isIosSafari(ipad, 0), false, "a real Mac");
    assert.equal(isIosSafari(safari.replace("Version/18.1 ", "CriOS/131.0 "), 5), false, "Chrome on iOS");
    assert.equal(isIosSafari(safari.replace("Safari/604.1", "Safari/604.1 FxiOS/133"), 5), false, "Firefox on iOS");
    assert.equal(isIosSafari("Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36", 5), false, "Android");
  });

  it("knows when it already runs from the home screen", () => {
    assert.equal(isStandalone({ matchMedia: (q) => ({ matches: q === "(display-mode: standalone)" }) }), true);
    assert.equal(isStandalone({ matchMedia: () => ({ matches: false }), navigator: { standalone: true } }), true, "iOS");
    assert.equal(isStandalone({ matchMedia: () => ({ matches: false }), navigator: { standalone: false } }), false);
    assert.equal(isStandalone({ matchMedia: () => { throw new Error("x"); } }), false);
    assert.equal(isStandalone({}), false);
  });

  it("Android / Chrome: keeps the browser's prompt and shows it only on «Εγκατάσταση»; declined = never again", async () => {
    const t = new FakeTarget();
    const { data, flag } = memoryFlag();
    const flow = new InstallHintFlow({ standalone: () => false, ios: false, flag }, t);
    assert.equal(flow.getState().kind, null, "nothing before the browser offers it");
    const ev = promptEvent("dismissed");
    t.emit("beforeinstallprompt", ev);
    assert.equal(ev.prevented, 1, "the browser's own mini bar is replaced by the hint");
    assert.equal(flow.getState().kind, "prompt");
    await flow.install();
    assert.equal(ev.prompted, 1);
    assert.equal(flow.getState().kind, null);
    assert.equal(data.get(INSTALL_HINT_KEY), "1", "declined: remembered on this device");
    t.emit("beforeinstallprompt", promptEvent("accepted"));
    assert.equal(flow.getState().kind, null, "never asked again");
    const again = new InstallHintFlow({ standalone: () => false, ios: false, flag }, new FakeTarget());
    assert.equal(again.getState().kind, null, "also after a reload");
  });

  it("accepted: gone, without marking it closed; installed elsewhere: gone", async () => {
    const t = new FakeTarget();
    const { data, flag } = memoryFlag();
    const flow = new InstallHintFlow({ standalone: () => false, ios: false, flag }, t);
    t.emit("beforeinstallprompt", promptEvent("accepted"));
    await flow.install();
    assert.equal(flow.getState().kind, null);
    assert.equal(data.has(INSTALL_HINT_KEY), false);
    const t2 = new FakeTarget();
    const flow2 = new InstallHintFlow({ standalone: () => false, ios: false, flag }, t2);
    t2.emit("beforeinstallprompt", promptEvent("accepted"));
    t2.emit("appinstalled", {});
    assert.equal(flow2.getState().kind, null);
    const t3 = new FakeTarget();
    const flow3 = new InstallHintFlow({ standalone: () => false, ios: false, flag }, t3);
    t3.emit("beforeinstallprompt", promptEvent(new Error("NotAllowedError")));
    await flow3.install();
    assert.equal(flow3.getState().kind, null, "a prompt that fails counts as declined");
  });

  it("iPhone Safari: the two steps in Apple's Greek labels; «Εντάξει» = never again on this device", () => {
    const { data, flag } = memoryFlag();
    const flow = new InstallHintFlow({ standalone: () => false, ios: true, flag }, new FakeTarget());
    assert.equal(flow.getState().kind, "ios");
    assert.match(INSTALL_COPY.ios, /«Κοινή χρήση»/);
    assert.match(INSTALL_COPY.ios, /«Προσθήκη στην οθόνη Αφετηρίας»/);
    flow.dismiss();
    assert.equal(flow.getState().kind, null);
    assert.equal(data.get(INSTALL_HINT_KEY), "1");
    assert.equal(new InstallHintFlow({ standalone: () => true, ios: true, flag: null }, null).getState().kind, null, "standalone");
  });

  it("the device flag is one fixed key outside the QR records; storage failures never throw", () => {
    assert.equal(INSTALL_HINT_KEY.startsWith(QR_STORE_PREFIX), false, "a QR write (which clears every QR record) never touches it");
    const throwing = { getItem: () => { throw new Error("SecurityError"); }, setItem: () => { throw new Error("QuotaExceededError"); }, removeItem: () => {}, key: () => null, length: 0 };
    const f = createDeviceFlag(throwing)!;
    assert.equal(f.get(), false);
    assert.doesNotThrow(() => f.set());
    assert.equal(createDeviceFlag(null), null);
    const flow = new InstallHintFlow({ standalone: () => false, ios: true, flag: f }, null);
    flow.dismiss();
    assert.equal(flow.getState().kind, null, "closed for this page even when storage fails");
  });
});

describe("round 11 home-screen icons (made from the bundled logo)", () => {
  it("are PNG files of the sizes the manifest and iOS name", () => {
    const sizes: Record<string, number> = { "icon-192.png": 192, "icon-512.png": 512, "icon-maskable-512.png": 512, "apple-touch-icon.png": 180 };
    for (const [file, size] of Object.entries(sizes)) {
      const png = readFileSync(join(APP_DIR, "src", "customer", "pwa", "icons", file));
      assert.deepEqual([...png.subarray(0, 8)], [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a], file);
      assert.equal(png.readUInt32BE(16), size, `${file} width`);
      assert.equal(png.readUInt32BE(20), size, `${file} height`);
      assert.equal(png[25], 2, `${file}: RGB without transparency (iOS fills transparency with black)`);
    }
    const php = readFileSync(join(APP_DIR, "..", "wordpress", "evangelou-club", "includes", "customer", "class-evc-customer-pwa.php"), "utf8");
    for (const file of Object.keys(sizes)) assert.ok(php.includes(`'${file}'`), `${file} is named by EVC_Customer_Pwa`);
    assert.ok(php.includes("const THEME_COLOR = '#1e1713';"), "--roast of the design tokens");
    const css = readFileSync(join(APP_DIR, "src", "preview", "preview.css"), "utf8");
    assert.ok(css.includes("--roast: #1e1713;"));
  });
});
