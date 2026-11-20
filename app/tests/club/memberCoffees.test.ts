import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement } from "react";
import { createCustomerCoffeesApi, parseCoffees, type CoffeesResult, type CustomerCoffeesApi, type MemberCoffees } from "../../src/customer/coffeesApi";
import type { CustomerConfig } from "../../src/customer/config";
import { CustomerApp } from "../../src/customer/CustomerApp";
import { createNonceBox, type CustomerApi, type Me } from "../../src/customer/customerApi";
import { toLiveView } from "../../src/customer/liveView";
import { COFFEES_MAX_REQUESTS_PER_MINUTE, MemberCoffeesFlow } from "../../src/customer/memberCoffees";
import { createDom, type DomHarness } from "../staff/support/dom";

/*
 * Owner decision D23 (2026-11-19): the member card shows the member's REAL
 * coffee figures from GET /me/coffees: today's coffee taken or not (Athens
 * day) and the coffees of this Athens month. Strict parsing, the «—»
 * fallback when anything is unknown, the Athens-midnight guard, the
 * throttled refresh and the taken-today headline on the real card.
 */

const appDir = process.env.EVC_APP_DIR as string;
const CONFIG: CustomerConfig = { restBase: "https://shop.example/wp-json/evangelou-club-customer/v1", nonce: "0123456789", loginUrl: "https://shop.example/wp-login.php", logoutUrl: "https://shop.example/wp-login.php?action=logout&_wpnonce=abc", appVersion: "t", homePath: "/" } as CustomerConfig;
// 2026-11-19 10:00 Athens (UTC+2).
const NOW = Date.parse("2026-11-19T08:00:00Z");
const ME: Me = { displayName: "Ελένη Μαρκοπούλου", email: "eleni@example.org", state: "active", paidUntilUtc: "2026-12-15T10:00:00+00:00" };
const body = (coffees: Record<string, unknown>) => ({ result: "ok", coffees });
const MEMBER = { state: "member", today: "2026-11-19", taken_today: true, this_month: 3 };

describe("D23: parsing GET /me/coffees", () => {
  it("maps a member answer and the two refusals", () => {
    assert.deepEqual(parseCoffees(body(MEMBER)), { kind: "ok", coffees: { today: "2026-11-19", takenToday: true, thisMonth: 3 } });
    assert.deepEqual(parseCoffees(body({ ...MEMBER, taken_today: false, this_month: 0 })), { kind: "ok", coffees: { today: "2026-11-19", takenToday: false, thisMonth: 0 } });
    assert.deepEqual(parseCoffees(body({ ...MEMBER, this_month: 31 })), { kind: "ok", coffees: { today: "2026-11-19", takenToday: true, thisMonth: 31 } });
    assert.deepEqual(parseCoffees(body({ state: "not_member" })), { kind: "not_member" });
    assert.deepEqual(parseCoffees(body({ state: "member_disabled" })), { kind: "member_disabled" });
  });

  it("refuses anything else (never a guessed figure)", () => {
    const bad: unknown[] = [
      null,
      { result: "ok" },
      { ...body(MEMBER), extra: 1 },
      { result: "unavailable", coffees: MEMBER },
      body({ ...MEMBER, staff: "Μαρία" }),
      body({ state: "member", today: "2026-11-19", taken_today: true }),
      body({ ...MEMBER, today: "2026-02-30" }),
      body({ ...MEMBER, today: "19/11/2026" }),
      body({ ...MEMBER, today: "2026-11-19T00:00:00Z" }),
      body({ ...MEMBER, taken_today: "yes" }),
      body({ ...MEMBER, taken_today: 1 }),
      body({ ...MEMBER, this_month: "3" }),
      body({ ...MEMBER, this_month: 2.5 }),
      body({ ...MEMBER, this_month: -1 }),
      body({ ...MEMBER, this_month: 32 }),
      body({ ...MEMBER, taken_today: true, this_month: 0 }),
      body({ state: "not_member", today: "2026-11-19" }),
      body({ state: "active" }),
    ];
    for (const b of bad) assert.equal(parseCoffees(b), null, JSON.stringify(b));
  });
});

function fakeFetch(replies: (() => Response | Promise<Response>)[]) {
  const seen: { url: string; init: RequestInit }[] = [];
  let i = 0;
  const impl = (async (url: string, init: RequestInit) => {
    seen.push({ url, init });
    return replies[Math.min(i++, replies.length - 1)]();
  }) as unknown as typeof fetch;
  return { impl, seen };
}
const json = (status: number, b: unknown, headers: Record<string, string> = {}) => () => new Response(JSON.stringify(b), { status, headers: { "Content-Type": "application/json", ...headers } });

describe("D23: the GET /me/coffees client", () => {
  it("one same-origin, no-store GET with the nonce; every status maps to one result", async () => {
    const cases: [() => Response | Promise<Response>, CoffeesResult][] = [
      [json(200, body(MEMBER)), { kind: "ok", coffees: { today: "2026-11-19", takenToday: true, thisMonth: 3 } }],
      [json(200, body({ state: "not_member" })), { kind: "not_member" }],
      [json(200, { result: "ok" }), { kind: "error" }],
      [json(404, { error: { code: "not_found" } }), { kind: "off" }],
      [json(401, { error: { code: "unauthorized" } }), { kind: "signed_out" }],
      [json(403, { error: { code: "forbidden" } }), { kind: "forbidden" }],
      [json(503, { result: "unavailable" }), { kind: "unavailable" }],
      [json(503, { result: "unavailable", why: "x" }), { kind: "error" }],
      [json(500, { error: { code: "server_error" } }), { kind: "error" }],
      [() => new Response("<html>", { status: 200 }), { kind: "error" }],
      [() => Promise.reject(new TypeError("offline")), { kind: "error" }],
    ];
    for (const [reply, want] of cases) {
      const { impl, seen } = fakeFetch([reply]);
      const api = createCustomerCoffeesApi(CONFIG, createNonceBox(CONFIG.nonce), impl);
      assert.deepEqual(await api.coffees(), want);
      assert.equal(seen.length, 1);
      assert.equal(seen[0].url, "https://shop.example/wp-json/evangelou-club-customer/v1/me/coffees");
      assert.equal(seen[0].init.method, "GET");
      assert.equal(seen[0].init.cache, "no-store");
      assert.equal(seen[0].init.credentials, "same-origin");
      assert.equal(seen[0].init.redirect, "error");
      assert.equal(seen[0].init.body, undefined);
      assert.equal((seen[0].init.headers as Record<string, string>)["X-WP-Nonce"], "0123456789");
    }
  });

  it("a hung server is cut off; a refreshed nonce is kept; plain permalinks use rest_route", async () => {
    const hung = (async () => new Promise<Response>(() => {})) as unknown as typeof fetch;
    assert.deepEqual(await createCustomerCoffeesApi(CONFIG, createNonceBox(CONFIG.nonce), hung, 20).coffees(), { kind: "error" });
    const box = createNonceBox(CONFIG.nonce);
    const { impl, seen } = fakeFetch([json(200, body(MEMBER), { "X-WP-Nonce": "abcdef0123" })]);
    await createCustomerCoffeesApi({ ...CONFIG, restBase: "https://shop.example/?rest_route=/evangelou-club-customer/v1" }, box, impl).coffees();
    assert.equal(box.get(), "abcdef0123");
    assert.equal(new URL(seen[0].url).searchParams.get("rest_route"), "/evangelou-club-customer/v1/me/coffees");
  });

  it("round 8 NIT-3: a REST base that is not an absolute same-origin URL never reaches fetch", async () => {
    for (const restBase of ["/wp-json/evangelou-club-customer/v1", "wp-json/evangelou-club-customer/v1", "https://shop.example/wp-json/evangelou-club-customer/v1\n"]) {
      const { impl, seen } = fakeFetch([json(200, body(MEMBER))]);
      assert.deepEqual(await createCustomerCoffeesApi({ ...CONFIG, restBase }, createNonceBox(CONFIG.nonce), impl).coffees(), { kind: "error" }, restBase);
      assert.equal(seen.length, 0, `${restBase}: nothing sent`);
    }
  });

  it("only coffeesApi.ts talks to /me/coffees, with GET only", () => {
    const client = readFileSync(join(appDir, "src/customer/coffeesApi.ts"), "utf8");
    assert.match(client, /endpointUrl\(config\.restBase, "\/me\/coffees"\)/);
    assert.equal((client.match(/fetchImpl\(/g) ?? []).length, 1, "one request site");
    assert.doesNotMatch(client, /method: "(POST|PUT|PATCH|DELETE)"/);
    const flow = readFileSync(join(appDir, "src/customer/memberCoffees.ts"), "utf8").replace(/\/\*[\s\S]*?\*\//g, "");
    for (const api of ["fetch(", "localStorage", "sessionStorage", "indexedDB", "console."]) assert.ok(!flow.includes(api), `memberCoffees.ts must not use ${api}`);
  });
});

const figures = (today: string, takenToday: boolean, thisMonth: number): MemberCoffees => ({ today, takenToday, thisMonth });

describe("D23: what the card shows for the figures (toLiveView)", () => {
  it("figures of today are real: taken today and the month count; the history stays unavailable", () => {
    const v = toLiveView(ME, NOW, figures("2026-11-19", true, 3));
    assert.equal(v.clubData, undefined);
    assert.equal(v.coffeeUsedToday, true);
    assert.equal(v.visitsThisMonth, 3);
    assert.equal(v.historyData, "unavailable");
    assert.deepEqual(v.history, []);
    const w = toLiveView(ME, NOW, figures("2026-11-19", false, 0));
    assert.equal(w.coffeeUsedToday, false);
    assert.equal(w.visitsThisMonth, 0);
    assert.equal(w.clubData, undefined);
  });

  it("no figures: the «—» fallback, exactly as before D23", () => {
    const v = toLiveView(ME, NOW, null);
    assert.equal(v.clubData, "unavailable");
    assert.equal(v.coffeeUsedToday, false);
    assert.equal(v.visitsThisMonth, 0);
    assert.equal(v.historyData, "unavailable");
    assert.deepEqual(toLiveView(ME, NOW), v);
  });

  it("Athens midnight: figures of yesterday are never shown as today's", () => {
    // 2026-11-19 23:59 Athens = 21:59Z; 2026-11-20 00:01 Athens = 22:01Z (the UTC date is still the 19th).
    const before = Date.parse("2026-11-19T21:59:00Z");
    const after = Date.parse("2026-11-19T22:01:00Z");
    const taken = figures("2026-11-19", true, 3);
    assert.equal(toLiveView(ME, before, taken).coffeeUsedToday, true);
    const next = toLiveView(ME, after, taken);
    assert.equal(next.today, "2026-11-20");
    assert.equal(next.clubData, "unavailable", "yesterday's figures are unknown today");
    assert.equal(next.coffeeUsedToday, false);
    assert.equal(next.visitsThisMonth, 0);
    // A new month: 2026-11-30 23:30 Athens figures, shown at 2026-12-01 00:10 Athens.
    assert.equal(toLiveView(ME, Date.parse("2026-11-30T22:10:00Z"), figures("2026-11-30", true, 20)).clubData, "unavailable");
    assert.equal(toLiveView(ME, Date.parse("2026-11-30T22:10:00Z"), figures("2026-12-01", false, 0)).clubData, undefined);
  });
});

function fakeApi(results: CoffeesResult[]) {
  let calls = 0;
  const pending: (() => void)[] = [];
  let hold = false;
  const api: CustomerCoffeesApi = {
    coffees: async () => {
      const r = results[Math.min(calls++, results.length - 1)];
      if (hold) await new Promise<void>((resolve) => pending.push(resolve));
      return r;
    },
  };
  return {
    api,
    calls: () => calls,
    hold(on: boolean) {
      hold = on;
    },
    release() {
      for (const p of pending.splice(0)) p();
    },
  };
}
const tick = () => new Promise((r) => setTimeout(r, 0));
const OK3: CoffeesResult = { kind: "ok", coffees: figures("2026-11-19", true, 3) };
const OK4: CoffeesResult = { kind: "ok", coffees: figures("2026-11-19", true, 4) };

describe("D23: the refresh flow (throttled, fail soft)", () => {
  it("asks once on open; a wake within 30 s asks nothing; after 30 s it asks again", async () => {
    let now = 0;
    const f = fakeApi([OK3, OK4]);
    const flow = new MemberCoffeesFlow(f.api, { now: () => now });
    flow.open("eleni@example.org");
    await tick();
    assert.equal(f.calls(), 1);
    assert.equal(flow.getState()?.thisMonth, 3);
    now = 29_999;
    flow.wake();
    flow.open("eleni@example.org");
    await tick();
    assert.equal(f.calls(), 1, "throttled");
    now = 30_000;
    flow.wake();
    await tick();
    assert.equal(f.calls(), 2);
    assert.equal(flow.getState()?.thisMonth, 4, "the new answer is shown");
  });

  it("a failure leaves the figures unknown («—») and is asked again after 5 s, never earlier", async () => {
    let now = 0;
    const f = fakeApi([OK3, { kind: "unavailable" }, { kind: "error" }, OK4]);
    const flow = new MemberCoffeesFlow(f.api, { now: () => now });
    flow.open("eleni@example.org");
    await tick();
    assert.equal(flow.getState()?.thisMonth, 3);
    now = 30_000;
    flow.wake();
    await tick();
    assert.equal(flow.getState(), null, "fail soft: unknown, never the stale figure");
    now = 34_999;
    flow.wake();
    await tick();
    assert.equal(f.calls(), 2);
    now = 35_000;
    flow.wake();
    await tick();
    assert.equal(f.calls(), 3);
    assert.equal(flow.getState(), null);
    now = 100_000;
    flow.wake();
    await tick();
    assert.equal(flow.getState()?.thisMonth, 4);
  });

  it("a hard cap per rolling minute, whatever the wakes", async () => {
    let now = 0;
    const f = fakeApi([{ kind: "error" }]);
    const flow = new MemberCoffeesFlow(f.api, { now: () => now });
    flow.open("eleni@example.org");
    for (let t = 0; t < 60_000; t += 5_000) {
      now = t;
      flow.wake();
      await tick();
    }
    assert.equal(f.calls(), COFFEES_MAX_REQUESTS_PER_MINUTE);
    assert.equal(COFFEES_MAX_REQUESTS_PER_MINUTE, 3);
  });

  it("no route (off): never asked again in this page; while a request is out, wakes send nothing", async () => {
    let now = 0;
    const off = fakeApi([{ kind: "off" }]);
    const flow = new MemberCoffeesFlow(off.api, { now: () => now });
    flow.open("eleni@example.org");
    await tick();
    now = 600_000;
    flow.wake();
    flow.open("eleni@example.org");
    await tick();
    assert.equal(off.calls(), 1);

    const slow = fakeApi([OK3]);
    slow.hold(true);
    const busy = new MemberCoffeesFlow(slow.api, { now: () => now });
    busy.open("eleni@example.org");
    now += 60_000;
    busy.wake();
    busy.wake();
    assert.equal(slow.calls(), 1, "one request at a time");
    slow.release();
    await tick();
    assert.equal(busy.getState()?.thisMonth, 3);
  });

  it("signed out or refused: never asked again; not a member / disabled: answers, asked again only after 30 s", async () => {
    for (const final of [{ kind: "signed_out" }, { kind: "forbidden" }] as CoffeesResult[]) {
      let now = 0;
      const f = fakeApi([final, OK3]);
      const flow = new MemberCoffeesFlow(f.api, { now: () => now });
      flow.open("eleni@example.org");
      await tick();
      now = 600_000;
      flow.wake();
      await tick();
      assert.equal(f.calls(), 1, final.kind);
      assert.equal(flow.getState(), null, final.kind);
    }
    for (const answer of [{ kind: "not_member" }, { kind: "member_disabled" }] as CoffeesResult[]) {
      let now = 0;
      const f = fakeApi([answer, OK3]);
      const flow = new MemberCoffeesFlow(f.api, { now: () => now });
      flow.open("eleni@example.org");
      await tick();
      assert.equal(flow.getState(), null, answer.kind);
      now = 29_999;
      flow.wake();
      await tick();
      assert.equal(f.calls(), 1, `${answer.kind}: not a failure, no 5 s re-ask`);
      now = 30_000;
      flow.wake();
      await tick();
      assert.equal(f.calls(), 2, answer.kind);
      assert.equal(flow.getState()?.thisMonth, 3, answer.kind);
    }
  });

  it("round 8 LOW-1: an answer for the previous account that lands after a switch is dropped (generation guard)", async () => {
    const f = fakeApi([OK3, OK4]);
    const flow = new MemberCoffeesFlow(f.api, { now: () => 0 });
    f.hold(true);
    flow.open("eleni@example.org");
    flow.open("nikos@example.org");
    assert.equal(f.calls(), 2, "the new account asks at once");
    f.release();
    await tick();
    // Both answers landed in order: Eleni's (3) first, then Nikos's (4). Only Nikos's may ever be shown.
    assert.equal(flow.getState()?.thisMonth, 4);
    const seen: (number | undefined)[] = [];
    const g = fakeApi([OK3, OK4]);
    const other = new MemberCoffeesFlow(g.api, { now: () => 0 });
    other.subscribe(() => seen.push(other.getState()?.thisMonth));
    g.hold(true);
    other.open("eleni@example.org");
    other.open("nikos@example.org");
    g.release();
    await tick();
    assert.deepEqual(seen, [4], "Eleni's figures never reached Nikos's card, not even for a moment");
  });

  it("another account never sees the previous figures; pause stops wakes until the next open", async () => {
    let now = 0;
    const f = fakeApi([OK3]);
    const flow = new MemberCoffeesFlow(f.api, { now: () => now });
    flow.open("eleni@example.org");
    await tick();
    assert.equal(flow.getState()?.thisMonth, 3);
    f.hold(true);
    flow.open("nikos@example.org");
    assert.equal(flow.getState(), null, "cleared at once");
    flow.open("eleni@example.org");
    f.release();
    await tick();
    assert.equal(f.calls(), 3);
    now = 200_000;
    flow.pause();
    flow.wake();
    await tick();
    assert.equal(f.calls(), 3, "paused: nothing sent");
  });
});

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

const app = () => dom.document.querySelector('[data-evc-customer="app"]') as HTMLElement;
const text = () => app().textContent ?? "";
const button = (label: string, root: ParentNode = dom.document) => {
  const b = [...root.querySelectorAll("button")].find((x) => x.textContent?.trim() === label);
  assert.ok(b, `button «${label}» exists`);
  return b as HTMLButtonElement;
};
const monthRow = () => {
  const dt = [...app().querySelectorAll("dt")].find((d) => d.textContent === "Καφέδες αυτόν τον μήνα");
  assert.ok(dt, "the «Καφέδες αυτόν τον μήνα» row");
  return dt!.nextElementSibling!.textContent;
};

async function mountCard(results: CoffeesResult[], clock = { now: 0 }) {
  const f = fakeApi(results);
  const flow = new MemberCoffeesFlow(f.api, { now: () => clock.now });
  const api: CustomerApi = { me: async () => ({ kind: "ok", me: ME }) };
  await dom.render(createElement(CustomerApp, { config: CONFIG, api, now: () => NOW, navigate: () => {}, coffeesFlow: flow }));
  await act(async () => {});
  await act(async () => {});
  return { ...f, flow };
}

describe("D23: the real member card", () => {
  it("today's coffee taken: «Τον απολαύσατε σήμερα» and the real month count", async () => {
    await mountCard([OK3]);
    assert.match(text(), /Τον απολαύσατε σήμερα/);
    assert.match(text(), /Ο επόμενος καφές σας περιμένει από τα μεσάνυχτα/);
    assert.doesNotMatch(text(), /Ο σημερινός καφές σας περιμένει|Η συνδρομή σας είναι ενεργή/);
    assert.equal(monthRow(), "3");
  });

  it("not taken yet: «Ο σημερινός καφές σας περιμένει»; the dashboard says «1 καφές» and still claims no history", async () => {
    await mountCard([{ kind: "ok", coffees: figures("2026-11-19", false, 1) }]);
    assert.match(text(), /Ο σημερινός καφές σας περιμένει/);
    assert.doesNotMatch(text(), /Τον απολαύσατε σήμερα/);
    assert.equal(monthRow(), "1");
    await dom.click(button("Αρχική"));
    assert.match(text(), /Αυτόν τον μήνα1 καφές/);
    assert.match(text(), /Το ιστορικό καφέδων δεν είναι ακόμη διαθέσιμο/, "the list is not sent: never «no coffees yet»");
    assert.doesNotMatch(text(), /Δεν υπάρχουν ακόμη καφέδες/);
    await dom.click(button("Σήμερα", app().querySelector('nav[aria-label="Μενού μέλους"]')!));
    assert.match(text(), /Ισχύει για \d+ ώρες/, "the countdown is real now");
    assert.doesNotMatch(text(), /Οι τελευταίες 7 ημέρες/, "no 7-day strip without the history");
    await dom.click(button("Ιστορικό", app().querySelector('nav[aria-label="Μενού μέλους"]')!));
    assert.match(text(), /Το ιστορικό δεν είναι ακόμη διαθέσιμο/);
  });

  it("the Club database unreachable: the card keeps «—» and «Η συνδρομή σας είναι ενεργή»", async () => {
    for (const r of [{ kind: "unavailable" }, { kind: "error" }, { kind: "off" }, { kind: "not_member" }] as CoffeesResult[]) {
      await dom.reset();
      await mountCard([r]);
      assert.equal(monthRow(), "—", r.kind);
      assert.match(text(), /Η συνδρομή σας είναι ενεργή/, r.kind);
      assert.doesNotMatch(text(), /Τον απολαύσατε σήμερα|Ο σημερινός καφές σας περιμένει/, r.kind);
    }
  });

  it("the figures are asked again when the page becomes visible (after the throttle) and the card follows", async () => {
    const clock = { now: 0 };
    const f = await mountCard([{ kind: "ok", coffees: figures("2026-11-19", false, 2) }, OK3, OK4], clock);
    assert.match(text(), /Ο σημερινός καφές σας περιμένει/);
    assert.equal(f.calls(), 1);
    await act(async () => void dom.document.dispatchEvent(new dom.window.Event("visibilitychange")));
    assert.equal(f.calls(), 1, "within 30 s: nothing sent");
    clock.now = 30_000;
    await act(async () => void dom.document.dispatchEvent(new dom.window.Event("visibilitychange")));
    await act(async () => {});
    assert.equal(f.calls(), 2);
    assert.match(text(), /Τον απολαύσατε σήμερα/, "taken in the meantime: the card says so");
    assert.equal(monthRow(), "3");
    // Opened again from another screen: asked again (throttled).
    await dom.click(button("Αρχική"));
    clock.now = 60_000;
    await dom.click(button("Άνοιγμα κάρτας"));
    await act(async () => {});
    assert.equal(f.calls(), 3, "opening the card asks again");
    assert.equal(monthRow(), "4");
    await dom.reset();
    let wakes = 0;
    f.flow.wake = () => void wakes++;
    clock.now = 120_000;
    await act(async () => void dom.document.dispatchEvent(new dom.window.Event("visibilitychange")));
    assert.equal(wakes, 0, "unmounted: the listener is gone");
  });
});
