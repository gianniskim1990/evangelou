import assert from "node:assert/strict";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement } from "react";
import { parseCustomerConfig, type CustomerConfig } from "../../src/customer/config";
import { CustomerApp } from "../../src/customer/CustomerApp";
import type { CustomerApi, MeResult } from "../../src/customer/customerApi";
import { createNonceBox } from "../../src/customer/customerApi";
import { isIosDevice } from "../../src/customer/pwa/installHint";
import { initialPhase, keyBytes, MemberPushFlow, PUSH_COPY, sameKey, subscriptionJson, type PushEnv, type PushManagerLike, type SubscriptionLike } from "../../src/customer/pwa/push";
import { pushManagerOf, WORKER_READY_WAIT_MS } from "../../src/customer/pwa/register";
import { clickTarget, notificationFor, parsePushPayload, PUSH_FALLBACK } from "../../src/customer/pwa/swPush";
import { createCustomerPushApi, parsePushState, type CustomerPushApi, type PushStatus, type PushWrite } from "../../src/customer/pushApi";
import { createDom, type DomHarness } from "../staff/support/dom";

/*
 * STAGE-08 round 12 (owner decision D26 part 2): «Θέλω ειδοποιήσεις» on the member card. The opt-in flow, NO permission
 * prompt without the member's tap, the iPhone rule (installed only), plain permalinks (explained, no push), the
 * /me/push client, and the service worker's push / notificationclick handlers (run over a fake worker scope).
 */

const ORIGIN = "https://shop.example";
const KEY = "BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8";
const OTHER_KEY = "BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4";
const P256 = OTHER_KEY;
const AUTH = "BTBZMqHH6r4Tts7J_aSIgg";
const ENDPOINT = "https://fcm.googleapis.com/fcm/send/abc:APA91-one";

class FakeSub implements SubscriptionLike {
  unsubscribed = 0;
  options: { applicationServerKey: ArrayBuffer | null };
  endpoint: string;
  constructor(endpoint: string, key: string = KEY) {
    this.endpoint = endpoint;
    this.options = { applicationServerKey: keyBytes(key)!.slice().buffer };
  }
  toJSON() {
    return { endpoint: this.endpoint, keys: { p256dh: P256, auth: AUTH } };
  }
  async unsubscribe() {
    this.unsubscribed++;
    return true;
  }
}

function harness(over: Partial<PushEnv> = {}, opts: { existing?: FakeSub | null; permission?: string; answer?: string; status?: PushStatus["kind"]; writes?: PushWrite["kind"][] } = {}) {
  const calls: string[] = [];
  let permission = opts.permission ?? "default";
  let current: FakeSub | null = opts.existing ?? null;
  let n = 0;
  const subscribed: FakeSub[] = [];
  const manager: PushManagerLike = {
    getSubscription: async () => (calls.push("getSubscription"), current),
    subscribe: async (o) => {
      calls.push("subscribe");
      assert.equal(o.userVisibleOnly, true);
      assert.deepEqual([...o.applicationServerKey], [...keyBytes(KEY)!]);
      current = new FakeSub(`${ENDPOINT}-${++n}`);
      subscribed.push(current);
      return current;
    },
  };
  const notification = {
    get permission() {
      return permission;
    },
    requestPermission: async () => {
      calls.push("requestPermission");
      permission = opts.answer ?? "granted";
      return permission;
    },
  };
  const writes = [...(opts.writes ?? [])];
  const posted: unknown[] = [];
  const deleted: string[] = [];
  const api: CustomerPushApi = {
    status: async () => (calls.push("status"), { kind: opts.status ?? "ready" }) as PushStatus,
    subscribe: async (s) => (calls.push("POST"), posted.push(s), { kind: writes.shift() ?? "subscribed" }) as PushWrite,
    unsubscribe: async (e) => (calls.push("DELETE"), deleted.push(e), { kind: "stopped" }),
  };
  const env: PushEnv = { publicKey: KEY, worker: true, standalone: () => false, ios: false, notification, pushManager: async () => manager, pushSupported: true, ...over };
  const flow = new MemberPushFlow(env, api);
  return { flow, calls, posted, deleted, subscribed, current: () => current, setPermission: (p: string) => (permission = p) };
}

describe("round 12 push: what the member sees, and never a prompt without a tap", () => {
  it("opening the card never asks the browser for permission, whatever the state", async () => {
    for (const [label, over, opts] of [
      ["no subscription", {}, {}],
      ["denied", {}, { permission: "denied" }],
      ["granted and subscribed", {}, { permission: "granted", existing: new FakeSub(ENDPOINT) }],
      ["iPhone in Safari", { ios: true }, {}],
      ["plain permalinks", { worker: false }, {}],
    ] as [string, Partial<PushEnv>, Parameters<typeof harness>[1]][]) {
      const h = harness(over, opts);
      await h.flow.open();
      assert.ok(!h.calls.includes("requestPermission"), `${label}: no prompt`);
      assert.ok(!h.calls.includes("subscribe"), `${label}: no new subscription`);
    }
  });

  it("the tap asks once, subscribes on the worker with the shop's key and sends the consent", async () => {
    const h = harness();
    await h.flow.open();
    assert.equal(h.flow.getState().phase, "idle");
    assert.deepEqual(h.calls, ["status", "getSubscription"]);
    await h.flow.enable();
    assert.equal(h.flow.getState().phase, "on");
    assert.deepEqual(h.calls.slice(2), ["requestPermission", "getSubscription", "subscribe", "POST"]);
    assert.deepEqual(h.posted, [{ endpoint: `${ENDPOINT}-1`, keys: { p256dh: P256, auth: AUTH } }]);
    await h.flow.enable();
    assert.equal(h.calls.filter((c) => c === "requestPermission").length, 1, "never asked again once on");
    assert.equal(h.posted.length, 1, "a tap while on does nothing");
  });

  it("a declined prompt shows the explanation; dismissing it leaves the button; nothing is stored", async () => {
    const declined = harness({}, { answer: "denied" });
    await declined.flow.open();
    await declined.flow.enable();
    assert.equal(declined.flow.getState().phase, "denied");
    assert.deepEqual(declined.posted, []);
    const dismissed = harness({}, { answer: "default" });
    await dismissed.flow.open();
    await dismissed.flow.enable();
    assert.equal(dismissed.flow.getState().phase, "idle");
    assert.ok(!dismissed.calls.includes("subscribe"));
  });

  it("«Διακοπή ειδοποιήσεων» deletes on the server and drops the browser's subscription", async () => {
    const h = harness({}, { permission: "granted", existing: new FakeSub(ENDPOINT) });
    await h.flow.open();
    assert.equal(h.flow.getState().phase, "on", "an existing subscription is shown as on (and re-sent)");
    assert.deepEqual(h.posted, [{ endpoint: ENDPOINT, keys: { p256dh: P256, auth: AUTH } }]);
    const sub = h.current()!;
    await h.flow.disable();
    assert.equal(h.flow.getState().phase, "idle");
    assert.deepEqual(h.deleted, [ENDPOINT]);
    assert.equal(sub.unsubscribed, 1);
  });

  it("signing out stops them, so the next account on the phone never gets this member's notifications", async () => {
    const h = harness({}, { permission: "granted", existing: new FakeSub(ENDPOINT) });
    await h.flow.open();
    await h.flow.signOut();
    assert.deepEqual(h.deleted, [ENDPOINT]);
    assert.equal(h.current()!.unsubscribed, 1);
    const idle = harness();
    await idle.flow.open();
    await idle.flow.signOut();
    assert.deepEqual(idle.deleted, [], "nothing to stop");
  });

  it("409: another account's row on this browser: a new subscription is made and sent once more", async () => {
    const h = harness({}, { writes: ["endpoint_in_use", "subscribed"] });
    await h.flow.open();
    await h.flow.enable();
    assert.equal(h.flow.getState().phase, "on");
    assert.deepEqual(h.subscribed.map((s) => s.unsubscribed), [1, 0], "the taken one dropped, the new one kept");
    assert.deepEqual(
      h.posted.map((p) => (p as { endpoint: string }).endpoint),
      [`${ENDPOINT}-1`, `${ENDPOINT}-2`],
    );
    const twice = harness({}, { writes: ["endpoint_in_use", "endpoint_in_use"] });
    await twice.flow.open();
    await twice.flow.enable();
    assert.equal(twice.flow.getState().phase, "error", "only one more try");
    assert.ok(twice.subscribed.every((s) => s.unsubscribed === 1), "a tap that did not work leaves nothing behind");
  });

  it("a failed tap leaves nothing behind; a passing failure on open keeps the subscription", async () => {
    const tap = harness({}, { writes: ["unavailable"] });
    await tap.flow.open();
    await tap.flow.enable();
    assert.equal(tap.flow.getState().phase, "error");
    assert.equal(tap.subscribed[0].unsubscribed, 1);
    const open = harness({}, { permission: "granted", existing: new FakeSub(ENDPOINT), writes: ["error"] });
    await open.flow.open();
    assert.equal(open.flow.getState().phase, "on");
    assert.equal(open.current()!.unsubscribed, 0, "sent again at the next open");
    const gone = harness({}, { permission: "granted", existing: new FakeSub(ENDPOINT), writes: ["not_member"] });
    await gone.flow.open();
    assert.equal(gone.flow.getState().phase, "idle");
    assert.equal(gone.current()!.unsubscribed, 1);
  });

  it("a subscription made for other keys is replaced without a prompt; a withdrawn permission drops it", async () => {
    const old = new FakeSub(ENDPOINT, OTHER_KEY);
    const h = harness({}, { permission: "granted", existing: old });
    await h.flow.open();
    assert.equal(old.unsubscribed, 1);
    assert.ok(!h.calls.includes("requestPermission"));
    assert.deepEqual(h.posted, [{ endpoint: `${ENDPOINT}-1`, keys: { p256dh: P256, auth: AUTH } }]);
    assert.equal(h.flow.getState().phase, "on");
    const withdrawn = harness({}, { permission: "default", existing: new FakeSub(ENDPOINT) });
    await withdrawn.flow.open();
    assert.equal(withdrawn.flow.getState().phase, "idle");
    assert.deepEqual(withdrawn.deleted, [ENDPOINT]);
    assert.ok(!withdrawn.calls.includes("requestPermission"));
  });

  it("iPhone: only installed; in Safari a hint to install first", async () => {
    const safari = harness({ ios: true, standalone: () => false, pushSupported: false, notification: null });
    await safari.flow.open();
    assert.equal(safari.flow.getState().phase, "ios_install");
    await safari.flow.enable();
    assert.ok(!safari.calls.includes("requestPermission"), "no button there to tap");
    const installed = harness({ ios: true, standalone: () => true });
    await installed.flow.open();
    assert.equal(installed.flow.getState().phase, "idle", "installed: the control");
    assert.equal(isIosDevice("Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0 Mobile/15E148 Safari/604.1", 5), true, "any browser on an iPhone");
    assert.equal(isIosDevice("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15", 5), true, "an iPad presents itself as a Mac");
    assert.equal(isIosDevice("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15", 0), false, "a real Mac");
    assert.equal(isIosDevice("Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile", 5), false);
  });

  it("plain permalinks: no worker, no push: the control is replaced by the explanation", async () => {
    const h = harness({ worker: false });
    await h.flow.open();
    assert.equal(h.flow.getState().phase, "plain");
    assert.ok(!h.calls.includes("getSubscription"));
  });

  it("hidden: no key, server not ready, not a member, no Push in the browser", async () => {
    for (const [label, over, opts] of [
      ["no key", { publicKey: "" }, {}],
      ["bad key", { publicKey: "abc" }, {}],
      ["schema below 5 / off", {}, { status: "unavailable" }],
      ["404", {}, { status: "off" }],
      ["not a member", {}, { status: "not_member" }],
      ["no Push", { pushSupported: false }, {}],
      ["no Notification", { notification: null }, {}],
      ["no worker registration", { pushManager: async () => null }, {}],
    ] as [string, Partial<PushEnv>, Parameters<typeof harness>[1]][]) {
      const h = harness(over, opts);
      await h.flow.open();
      assert.equal(h.flow.getState().phase, "hidden", label);
    }
    const noKey = harness({ publicKey: "" });
    await noKey.flow.open();
    assert.ok(!noKey.calls.includes("status"), "no key: the server is not even asked");
  });

  it("pure helpers: the key bytes, the key match, the subscription shape, the first phase", () => {
    assert.equal(keyBytes(KEY)!.length, 65);
    assert.equal(keyBytes(KEY)![0], 4);
    for (const bad of ["", KEY.slice(1), `${KEY}=`, KEY.replace("B", "+"), "A".repeat(87)]) assert.equal(keyBytes(bad), null, bad);
    assert.equal(sameKey(new FakeSub(ENDPOINT), keyBytes(KEY)!), true);
    assert.equal(sameKey(new FakeSub(ENDPOINT, OTHER_KEY), keyBytes(KEY)!), false);
    assert.equal(sameKey({ endpoint: ENDPOINT, options: {}, toJSON: () => ({}), unsubscribe: async () => true }, keyBytes(KEY)!), true, "unknown = yes");
    assert.deepEqual(subscriptionJson(new FakeSub(ENDPOINT)), { endpoint: ENDPOINT, keys: { p256dh: P256, auth: AUTH } });
    assert.equal(subscriptionJson({ endpoint: "http://x", toJSON: () => ({ endpoint: "http://x", keys: { p256dh: P256, auth: AUTH } }), unsubscribe: async () => true }), null);
    assert.equal(subscriptionJson({ endpoint: ENDPOINT, toJSON: () => ({ endpoint: ENDPOINT, keys: { p256dh: "a b", auth: AUTH } }), unsubscribe: async () => true }), null);
    const base = { publicKey: KEY, worker: true, standalone: () => false, ios: false, notification: { permission: "default", requestPermission: async () => "granted" }, pushSupported: true };
    assert.equal(initialPhase(base), "check");
    assert.equal(initialPhase({ ...base, notification: { ...base.notification, permission: "denied" } }), "denied");
    assert.equal(initialPhase({ ...base, worker: false, ios: true }), "plain", "plain permalinks first");
  });
});

describe("round 12 push: the /me/push client", () => {
  const CONFIG: CustomerConfig = { restBase: `${ORIGIN}/wp-json/evangelou-club-customer/v1`, nonce: "0123456789", loginUrl: `${ORIGIN}/l`, logoutUrl: `${ORIGIN}/o`, appVersion: "t" };
  function fake(replies: [number, unknown][]) {
    const seen: { url: string; init: RequestInit }[] = [];
    const impl = (async (url: string, init: RequestInit) => {
      seen.push({ url, init });
      const [status, body] = replies.shift() ?? [500, null];
      return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
    }) as unknown as typeof fetch;
    return { seen, api: createCustomerPushApi(CONFIG, createNonceBox("0123456789"), impl) };
  }

  it("sends the subscription and the consent in the body only, same origin, no-store, no redirects", async () => {
    const { seen, api } = fake([[200, { result: "ok", push: { state: "subscribed" } }], [200, { result: "ok", push: { state: "stopped" } }]]);
    assert.deepEqual(await api.subscribe({ endpoint: ENDPOINT, keys: { p256dh: P256, auth: AUTH } }), { kind: "subscribed" });
    assert.deepEqual(await api.unsubscribe(ENDPOINT), { kind: "stopped" });
    assert.equal(seen[0].url, `${ORIGIN}/wp-json/evangelou-club-customer/v1/me/push`);
    assert.ok(!seen[0].url.includes("fcm"), "the endpoint never goes in a URL");
    assert.equal(seen[0].init.method, "POST");
    assert.deepEqual(JSON.parse(String(seen[0].init.body)), { endpoint: ENDPOINT, keys: { p256dh: P256, auth: AUTH }, consent: true });
    assert.equal(seen[0].init.cache, "no-store");
    assert.equal(seen[0].init.redirect, "error");
    assert.equal(seen[0].init.credentials, "same-origin");
    assert.equal((seen[0].init.headers as Record<string, string>)["X-WP-Nonce"], "0123456789");
    assert.equal(seen[1].init.method, "DELETE");
    assert.deepEqual(JSON.parse(String(seen[1].init.body)), { endpoint: ENDPOINT });
  });

  it("parses every answer strictly", async () => {
    const cases: [[number, unknown], PushStatus["kind"]][] = [
      [[200, { result: "ok", push: { state: "ready" } }], "ready"],
      [[200, { result: "ok", push: { state: "not_member" } }], "not_member"],
      [[200, { result: "ok", push: { state: "ready", extra: 1 } }], "error"],
      [[200, { result: "ok", push: { state: "subscribed" } }], "error"],
      [[404, { code: "rest_no_route" }], "off"],
      [[401, { error: { code: "unauthorized" } }], "signed_out"],
      [[403, { error: { code: "forbidden" } }], "forbidden"],
      [[503, { result: "unavailable" }], "unavailable"],
      [[503, { result: "unavailable", x: 1 }], "error"],
      [[500, null], "error"],
    ];
    for (const [reply, kind] of cases) assert.equal((await fake([reply]).api.status()).kind, kind, JSON.stringify(reply));
    const writes: [[number, unknown], PushWrite["kind"]][] = [
      [[409, { error: { code: "endpoint_in_use" } }], "endpoint_in_use"],
      [[400, { error: { code: "invalid_request" } }], "invalid"],
      [[404, { error: { code: "not_a_member" } }], "not_member"],
      [[403, { error: { code: "member_disabled" } }], "member_disabled"],
      [[404, { code: "rest_no_route" }], "off"],
      [[200, { result: "ok", push: { state: "stopped" } }], "error"],
      [[409, { error: { code: "concurrent_change" } }], "error"],
      [[400, { error: { code: "other" } }], "error"],
    ];
    for (const [reply, kind] of writes) assert.equal((await fake([reply]).api.subscribe({ endpoint: ENDPOINT, keys: { p256dh: P256, auth: AUTH } })).kind, kind, JSON.stringify(reply));
    assert.equal(parsePushState({ result: "ok", push: { state: "ready" } }, ["ready"]), "ready");
    assert.equal(parsePushState({ result: "ok", push: { state: "ready" }, x: 1 }, ["ready"]), null);
  });

  it("the page's public key is optional: a bad one is dropped (no push), never a reason to refuse the page", () => {
    const base = { restBase: `${ORIGIN}/wp-json/evangelou-club-customer/v1`, homePath: "/", nonce: "0123456789", loginUrl: `${ORIGIN}/wp-login.php`, logoutUrl: `${ORIGIN}/wp-login.php?action=logout`, appVersion: "t" };
    const parsed = (over: Record<string, unknown>) => parseCustomerConfig(JSON.stringify({ ...base, ...over }), ORIGIN);
    const good = parsed({ pushPublicKey: KEY });
    assert.ok(good.ok && good.config.pushPublicKey === KEY);
    for (const bad of [undefined, "", "abc", 7, `${KEY}=`, KEY.slice(0, 86)]) {
      const r = parsed({ pushPublicKey: bad });
      assert.ok(r.ok, String(bad));
      assert.equal(r.config.pushPublicKey, "", String(bad));
    }
  });

  it("the push manager is the member app's own worker registration, waited for on a first visit, never for ever", async () => {
    const setting = { url: `${ORIGIN}/club-member/sw.js`, scope: "/club-member/" };
    const manager = { getSubscription: async () => null, subscribe: async () => null };
    const scopes: unknown[] = [];
    assert.equal(await pushManagerOf(setting, { serviceWorker: { getRegistration: async (s) => (scopes.push(s), { pushManager: manager }) } }), manager);
    assert.deepEqual(scopes, ["/club-member/"]);
    assert.equal(await pushManagerOf(null, { serviceWorker: { getRegistration: async () => ({ pushManager: manager }) } }), null, "plain permalinks");
    assert.equal(await pushManagerOf(setting, {}), null, "no service worker support");
    assert.equal(await pushManagerOf(setting, { serviceWorker: { getRegistration: async () => ({}) } }), null, "no Push");
    const ready = Promise.resolve({ pushManager: manager, scope: `${ORIGIN}/club-member/` });
    assert.equal(await pushManagerOf(setting, { serviceWorker: { getRegistration: async () => undefined, ready } }), manager, "a first visit");
    const otherScope = Promise.resolve({ pushManager: manager, scope: `${ORIGIN}/` });
    assert.equal(await pushManagerOf(setting, { serviceWorker: { getRegistration: async () => undefined, ready: otherScope } }), null, "another worker's registration is never used");
    assert.equal(WORKER_READY_WAIT_MS, 5000);
  });
});

describe("round 12 push: the control in the member app", () => {
  let dom: DomHarness;
  let act: (fn: () => Promise<void>) => Promise<void>;
  before(async () => {
    dom = await createDom();
    act = (await import("react")).act as unknown as typeof act;
    dom.window.scrollTo = () => {};
  });
  after(() => dom.dispose());
  beforeEach(async () => {
    await dom.reset();
  });
  const CONFIG: CustomerConfig = { restBase: `${ORIGIN}/wp-json/evangelou-club-customer/v1`, nonce: "0123456789", loginUrl: `${ORIGIN}/l`, logoutUrl: `${ORIGIN}/o`, appVersion: "t" };
  const ME: MeResult = { kind: "ok", me: { displayName: "Ελένη", email: "e@example.org", state: "active", paidUntilUtc: "2026-12-15T10:00:00+00:00" } };
  const mount = async (flow: MemberPushFlow, navigate: (u: string) => void = () => {}, me: MeResult = ME) => {
    const api: CustomerApi = { me: async () => me };
    await dom.render(createElement(CustomerApp, { config: CONFIG, api, now: () => Date.parse("2026-11-21T08:00:00Z"), navigate, pushFlow: flow }));
    await act(async () => {});
    await act(async () => {});
  };
  const control = () => dom.document.querySelector("[data-evc-push]") as HTMLElement | null;
  const button = (label: string) => [...dom.document.querySelectorAll("button")].find((b) => b.textContent?.trim() === label) as HTMLButtonElement | undefined;

  it("shows «Θέλω ειδοποιήσεις» with the explanation; only the tap prompts; then «Διακοπή ειδοποιήσεων»", async () => {
    const h = harness();
    await mount(h.flow);
    assert.equal(control()!.dataset.evcPush, "idle");
    assert.match(control()!.textContent!, /Τις σταματάτε όποτε θέλετε/);
    assert.ok(!h.calls.includes("requestPermission"), "rendering never prompts");
    await act(async () => {
      button(PUSH_COPY.enable)!.click();
    });
    await act(async () => {});
    assert.equal(h.calls.filter((c) => c === "requestPermission").length, 1);
    assert.equal(control()!.dataset.evcPush, "on");
    assert.match(control()!.textContent!, /ενεργές σε αυτή τη συσκευή/);
    assert.ok(button(PUSH_COPY.enable) === undefined, "no «Θέλω ειδοποιήσεις» while they are on");
    const app = dom.document.querySelector('[data-evc-customer="app"]')!;
    assert.ok(app.firstElementChild !== control(), "below the member area, never above the card");
    await act(async () => {
      button(PUSH_COPY.disable)!.click();
    });
    await act(async () => {});
    assert.equal(control()!.dataset.evcPush, "idle");
    assert.equal(h.deleted.length, 1);
  });

  it("iPhone in Safari: the install hint and no button; plain permalinks: the explanation and no button; hidden: nothing", async () => {
    const ios = harness({ ios: true, pushSupported: false, notification: null });
    await mount(ios.flow);
    assert.equal(control()!.dataset.evcPush, "ios_install");
    assert.match(control()!.textContent!, /Προσθήκη στην οθόνη Αφετηρίας/);
    assert.ok(button(PUSH_COPY.enable) === undefined);
    await dom.reset();
    const plain = harness({ worker: false });
    await mount(plain.flow);
    assert.equal(control()!.dataset.evcPush, "plain");
    assert.ok(button(PUSH_COPY.enable) === undefined);
    await dom.reset();
    const hidden = harness({ publicKey: "" });
    await mount(hidden.flow);
    assert.ok(control() === null);
  });

  it("signing out stops the notifications before WordPress signs the account out", async () => {
    const h = harness({}, { permission: "granted", existing: new FakeSub(ENDPOINT) });
    // An expired membership opens on the dashboard, whose menu has the profile screen.
    await mount(h.flow, () => h.calls.push("navigate"), { kind: "ok", me: { displayName: "Ελένη", email: "e@example.org", state: "expired", paidUntilUtc: "2026-11-15T10:00:00+00:00" } });
    assert.equal(control()!.dataset.evcPush, "on");
    const profile = [...dom.document.querySelectorAll("button, a")].find((b) => /^(Προφίλ|Λογαριασμός)$/.test(b.textContent?.trim() ?? "")) as HTMLElement;
    assert.ok(profile, "the profile screen");
    await act(async () => {
      profile.click();
    });
    await act(async () => {
      button("Αποσύνδεση")!.click();
    });
    await act(async () => {});
    await act(async () => {});
    assert.deepEqual(h.calls.filter((c) => c === "DELETE" || c === "navigate"), ["DELETE", "navigate"]);
    assert.equal(h.current()!.unsubscribed, 1);
  });

  it("the Greek copy has no English words", () => {
    // Brand names stay as written (Apple's own Greek labels use them too).
    for (const [k, v] of Object.entries(PUSH_COPY)) assert.doesNotMatch(v.replace(/iPhone|Safari|Club/g, ""), /[A-Za-z]/, k);
  });
});

describe("round 12 service worker: push and notificationclick", () => {
  it("shows the payload's title and body with the app icon; anything else shows the fixed Greek line", () => {
    assert.deepEqual(parsePushPayload('{"title":"Νέο γλυκό","body":"Δοκιμάστε το σήμερα."}'), { title: "Νέο γλυκό", body: "Δοκιμάστε το σήμερα." });
    for (const bad of [null, "", "x", "[]", '{"title":"a"}', '{"title":"","body":"b"}', `{"title":"${"α".repeat(51)}","body":"b"}`, `{"title":"a","body":"${"β".repeat(151)}"}`, '{"title":"a\\u202eb","body":"c"}', '{"title":1,"body":"b"}', "x".repeat(4001)]) {
      assert.deepEqual(parsePushPayload(bad), { ...PUSH_FALLBACK }, String(bad).slice(0, 40));
    }
    assert.deepEqual(parsePushPayload(`{"title":"${"α".repeat(50)}","body":"${"β".repeat(150)}"}`).title.length, 50);
    const icon = `${ORIGIN}/wp-content/plugins/evangelou-club/customer-app/pwa/icon-192.png`;
    assert.deepEqual(notificationFor({ title: "Τ", body: "Κ" }, icon), { title: "Τ", options: { body: "Κ", lang: "el", dir: "ltr", icon, badge: icon } });
    assert.deepEqual(notificationFor({ title: "Τ", body: "Κ" }, ""), { title: "Τ", options: { body: "Κ", lang: "el", dir: "ltr" } });
    assert.doesNotMatch((PUSH_FALLBACK.title + PUSH_FALLBACK.body).replace("Club", ""), /[A-Za-z]/, "the app's name «Ευαγγέλου Club» is the only Latin word");
  });

  it("a tap focuses an open member page, else opens the scope; never another address", () => {
    const scope = `${ORIGIN}/club-member/`;
    assert.deepEqual(clickTarget([`${ORIGIN}/shop/`, `${ORIGIN}/club-member/`], scope), { focus: 1 });
    assert.deepEqual(clickTarget([`${ORIGIN}/club-member/?x=1`], scope), { focus: 0 });
    assert.deepEqual(clickTarget([`${ORIGIN}/`, `https://evil.example/club-member/`, `${ORIGIN}/club-admin/`], scope), { open: scope });
    assert.deepEqual(clickTarget([], scope), { open: scope });
    assert.deepEqual(clickTarget(["not a url"], "not a url"), { open: "/" });
  });

  it("the real sw.ts handlers over a fake worker scope", async () => {
    const handlers: Record<string, (e: unknown) => void> = {};
    const shown: { title: string; options: Record<string, unknown> }[] = [];
    const opened: string[] = [];
    const focused: string[] = [];
    let windows: { url: string; focus(): Promise<unknown> }[] = [];
    const icon = `${ORIGIN}/wp-content/plugins/evangelou-club/customer-app/pwa/icon-192.png`;
    const ASSETS = `${ORIGIN}/wp-content/plugins/evangelou-club/customer-app/assets/`;
    const cfg = { version: "0.1.0-x", scope: "/club-member/", assetBase: ASSETS, precache: [`${ASSETS}customer-Pm2qN4Fw.js`], restPrefixes: ["/wp-json/"], offline: { scripts: [`${ASSETS}customer-Pm2qN4Fw.js`], styles: [], appVersion: "0.1.0" }, icon };
    (globalThis as Record<string, unknown>).self = {
      __EVC_SW__: cfg,
      location: { origin: ORIGIN },
      caches: { open: async () => ({}), keys: async () => [], delete: async () => true },
      clients: { claim: async () => {}, matchAll: async () => windows, openWindow: async (u: string) => void opened.push(u) },
      registration: { scope: `${ORIGIN}/club-member/`, showNotification: async (title: string, options: Record<string, unknown>) => void shown.push({ title, options }) },
      skipWaiting: async () => {},
      addEventListener: (t: string, f: (e: unknown) => void) => (handlers[t] = f),
    };
    await import("../../src/customer/pwa/sw");
    const fire = async (type: string, extra: Record<string, unknown>) => {
      const waits: Promise<unknown>[] = [];
      handlers[type]({ waitUntil: (p: Promise<unknown>) => waits.push(p), ...extra });
      await Promise.all(waits);
    };
    await fire("push", { data: { text: () => '{"title":"Καλημέρα","body":"Ο καφές σας περιμένει."}' } });
    assert.deepEqual(shown, [{ title: "Καλημέρα", options: { body: "Ο καφές σας περιμένει.", lang: "el", dir: "ltr", icon, badge: icon } }]);
    await fire("push", { data: null });
    assert.equal(shown[1].title, PUSH_FALLBACK.title, "a push without data still shows a notification");
    await fire("push", {
      data: {
        text: () => {
          throw new Error("x");
        },
      },
    });
    assert.equal(shown[2].title, PUSH_FALLBACK.title);
    let closed = 0;
    const notification = { close: () => void closed++, data: { url: "https://evil.example/" } };
    await fire("notificationclick", { notification });
    assert.deepEqual(opened, [`${ORIGIN}/club-member/`], "the scope, never the notification's data");
    assert.equal(closed, 1);
    windows = [{ url: `${ORIGIN}/club-member/`, focus: async () => void focused.push("member") }];
    await fire("notificationclick", { notification });
    assert.deepEqual(focused, ["member"], "an open member page is brought forward");
    assert.equal(opened.length, 1);
    assert.ok(handlers.fetch && handlers.install && handlers.activate, "the round-11 handlers are still there");
  });
});
