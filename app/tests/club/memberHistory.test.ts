import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement } from "react";
import type { CoffeesResult, CustomerCoffeesApi } from "../../src/customer/coffeesApi";
import type { CustomerConfig } from "../../src/customer/config";
import { CustomerApp, type MidnightTimers } from "../../src/customer/CustomerApp";
import { createNonceBox, type CustomerApi, type Me } from "../../src/customer/customerApi";
import { createCustomerHistoryApi, parseHistory, type CustomerHistoryApi, type HistoryCoffee, type HistoryResult } from "../../src/customer/historyApi";
import { msToNextAthensMidnight, toLiveView } from "../../src/customer/liveView";
import { MemberCoffeesFlow } from "../../src/customer/memberCoffees";
import { HISTORY_MAX_REQUESTS_PER_MINUTE, MemberHistoryFlow } from "../../src/customer/memberHistory";
import { createDom, type DomHarness } from "../staff/support/dom";

/*
 * Owner decision D24 (round 9): the member card's «Ιστορικό» shows the member's OWN last coffees from
 * GET /me/history (date, coffee, preparation; never the staff note or anything about staff). Strict parsing,
 * the «δεν είναι ακόμη διαθέσιμο» fallback, the throttled flow, the history page / dashboard rendering, and
 * round 8 LOW-2: the member area re-renders just after the Athens midnight.
 */

const appDir = process.env.EVC_APP_DIR as string;
const CONFIG: CustomerConfig = { restBase: "https://shop.example/wp-json/evangelou-club-customer/v1", nonce: "0123456789", loginUrl: "https://shop.example/wp-login.php", logoutUrl: "https://shop.example/wp-login.php?action=logout&_wpnonce=abc", appVersion: "t", homePath: "/" } as CustomerConfig;
// 2026-11-20 10:00 Athens (UTC+2).
const NOW = Date.parse("2026-11-20T08:00:00Z");
const ME: Me = { displayName: "Ελένη Μαρκοπούλου", email: "eleni@example.org", state: "active", paidUntilUtc: "2026-12-15T10:00:00+00:00" };
const ITEMS = [
  { business_date: "2026-11-19", coffee: "Freddo Espresso", details: ["Μεγάλο μέγεθος", "Διπλός", "Σκέτος", "Φυτικό γάλα"] },
  { business_date: "2026-11-17", coffee: "Ελληνικός", details: [] },
];
const body = (history: Record<string, unknown>) => ({ result: "ok", history });
const member = (items: unknown[] = ITEMS) => body({ state: "member", items });

describe("D24: parsing GET /me/history", () => {
  it("maps the member's coffees (newest first) and the two refusals", () => {
    assert.deepEqual(parseHistory(member()), {
      kind: "ok",
      items: [
        { businessDate: "2026-11-19", coffee: "Freddo Espresso", details: ["Μεγάλο μέγεθος", "Διπλός", "Σκέτος", "Φυτικό γάλα"] },
        { businessDate: "2026-11-17", coffee: "Ελληνικός", details: [] },
      ],
    });
    assert.deepEqual(parseHistory(member([])), { kind: "ok", items: [] });
    assert.deepEqual(parseHistory(body({ state: "not_member" })), { kind: "not_member" });
    assert.deepEqual(parseHistory(body({ state: "member_disabled" })), { kind: "member_disabled" });
  });

  it("refuses anything else: a note, a staff field, an impossible or repeated day, too many items", () => {
    const six = Array.from({ length: 6 }, (_, i) => ({ business_date: `2026-11-${String(19 - i).padStart(2, "0")}`, coffee: "Espresso", details: [] }));
    const bad: unknown[] = [
      null,
      { ...member(), extra: 1 },
      body({ state: "member" }),
      member([{ ...ITEMS[0], note: "ιδιωτική σημείωση" }]),
      member([{ ...ITEMS[0], staff: "Γιώργος" }]),
      member([{ ...ITEMS[0], business_date: "2026-02-30" }]),
      member([{ ...ITEMS[0], coffee: "" }]),
      member([{ ...ITEMS[0], details: ["x".repeat(65)] }]),
      member([ITEMS[1], ITEMS[0]]),
      member([ITEMS[0], ITEMS[0]]),
      member(six),
      body({ state: "not_member", items: [] }),
    ];
    for (const b of bad) assert.equal(parseHistory(b), null, JSON.stringify(b));
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
const json = (status: number, b: unknown) => () => new Response(JSON.stringify(b), { status, headers: { "Content-Type": "application/json" } });

describe("D24: the GET /me/history client", () => {
  it("one same-origin, no-store GET with the nonce; every status maps to one result", async () => {
    const cases: [() => Response | Promise<Response>, HistoryResult][] = [
      [json(200, member([ITEMS[1]])), { kind: "ok", items: [{ businessDate: "2026-11-17", coffee: "Ελληνικός", details: [] }] }],
      [json(200, body({ state: "not_member" })), { kind: "not_member" }],
      [json(404, { code: "rest_no_route" }), { kind: "off" }],
      [json(401, { error: { code: "unauthorized" } }), { kind: "signed_out" }],
      [json(403, { error: { code: "forbidden" } }), { kind: "forbidden" }],
      [json(503, { result: "unavailable" }), { kind: "unavailable" }],
      [json(200, { result: "ok" }), { kind: "error" }],
      [() => Promise.reject(new TypeError("offline")), { kind: "error" }],
    ];
    for (const [reply, want] of cases) {
      const { impl, seen } = fakeFetch([reply]);
      assert.deepEqual(await createCustomerHistoryApi(CONFIG, createNonceBox(CONFIG.nonce), impl).history(), want);
      assert.equal(seen[0].url, "https://shop.example/wp-json/evangelou-club-customer/v1/me/history");
      assert.equal(seen[0].init.method, "GET");
      assert.equal(seen[0].init.cache, "no-store");
      assert.equal(seen[0].init.redirect, "error");
      assert.equal((seen[0].init.headers as Record<string, string>)["X-WP-Nonce"], "0123456789");
    }
    const { impl, seen } = fakeFetch([json(200, member())]);
    assert.deepEqual(await createCustomerHistoryApi({ ...CONFIG, restBase: "/wp-json/evangelou-club-customer/v1" }, createNonceBox(CONFIG.nonce), impl).history(), { kind: "error" });
    assert.equal(seen.length, 0, "a base that is not absolute and same-origin never reaches fetch");
  });

  it("only historyApi.ts talks to /me/history, with GET only; the flow uses no storage", () => {
    const client = readFileSync(join(appDir, "src/customer/historyApi.ts"), "utf8");
    assert.match(client, /endpointUrl\(config\.restBase, "\/me\/history"\)/);
    assert.doesNotMatch(client, /method: "(POST|PUT|PATCH|DELETE)"/);
    const flow = readFileSync(join(appDir, "src/customer/memberHistory.ts"), "utf8").replace(/\/\*[\s\S]*?\*\//g, "");
    for (const api of ["fetch(", "localStorage", "sessionStorage", "indexedDB", "console."]) assert.ok(!flow.includes(api), `memberHistory.ts must not use ${api}`);
  });
});

function fakeApi(results: HistoryResult[]) {
  let calls = 0;
  const api: CustomerHistoryApi = { history: async () => results[Math.min(calls++, results.length - 1)] };
  return { api, calls: () => calls };
}
const tick = () => new Promise((r) => setTimeout(r, 0));
const H1: HistoryResult = { kind: "ok", items: [{ businessDate: "2026-11-19", coffee: "Freddo Espresso", details: ["Διπλός"] }] };
const H2: HistoryResult = { kind: "ok", items: [{ businessDate: "2026-11-20", coffee: "Espresso", details: [] }, { businessDate: "2026-11-19", coffee: "Freddo Espresso", details: ["Διπλός"] }] };

describe("D24: the history flow (throttled, fail soft)", () => {
  it("asks on open; throttled wakes; a failure clears it and is asked again after 5 s; a hard cap per minute", async () => {
    let now = 0;
    const f = fakeApi([H1, { kind: "unavailable" }, H2, H2, H2, H2]);
    const flow = new MemberHistoryFlow(f.api, { now: () => now });
    flow.open("eleni@example.org");
    await tick();
    assert.equal(flow.getState()?.length, 1);
    flow.wake();
    await tick();
    assert.equal(f.calls(), 1, "within 30 s: nothing sent");
    now = 30_000;
    flow.wake();
    await tick();
    assert.equal(flow.getState(), null, "a failure: unknown again, never the stale list");
    now = 34_999;
    flow.wake();
    await tick();
    assert.equal(f.calls(), 2);
    now = 35_000;
    flow.wake();
    await tick();
    assert.equal(flow.getState()?.length, 2);
    for (let t = 65_000; t < 90_000; t += 5_000) {
      now = t;
      flow.wake();
      await tick();
    }
    assert.ok(f.calls() <= 2 + HISTORY_MAX_REQUESTS_PER_MINUTE);
  });

  it("an older plugin (404): never asked again; another account never sees the previous list", async () => {
    let now = 0;
    const off = fakeApi([{ kind: "off" }]);
    const flow = new MemberHistoryFlow(off.api, { now: () => now });
    flow.open("eleni@example.org");
    await tick();
    now = 600_000;
    flow.wake();
    await tick();
    assert.equal(off.calls(), 1);
    const f = fakeApi([H1, { kind: "not_member" }]);
    const other = new MemberHistoryFlow(f.api, { now: () => now });
    other.open("eleni@example.org");
    await tick();
    assert.equal(other.getState()?.length, 1);
    other.open("nikos@example.org");
    assert.equal(other.getState(), null, "cleared at once");
  });
});

describe("D24: what the card shows (toLiveView)", () => {
  it("with the history: the member's own coffees, labelled as recorded; without it: unavailable", () => {
    const items: HistoryCoffee[] = [{ businessDate: "2026-11-19", coffee: "Freddo Espresso", details: ["Διπλός", "Σκέτος"] }];
    const v = toLiveView(ME, NOW, null, items);
    assert.equal(v.historyData, "recent");
    assert.deepEqual(v.history, [{ date: "2026-11-19", time: "", coffee: "espresso", memberId: "me", label: "Freddo Espresso", details: ["Διπλός", "Σκέτος"] }]);
    const w = toLiveView(ME, NOW);
    assert.equal(w.historyData, "unavailable");
    assert.deepEqual(w.history, []);
    assert.equal(toLiveView(ME, NOW, null, []).historyData, "recent", "an empty list is an answer: «no coffees yet»");
  });

  it("round 8 LOW-2: the time to the next Athens midnight, DST days included", () => {
    assert.equal(msToNextAthensMidnight(Date.parse("2026-11-20T21:59:00Z")), 60_000, "23:59 Athens (UTC+2)");
    assert.equal(msToNextAthensMidnight(Date.parse("2026-07-20T20:59:30Z")), 30_000, "23:59:30 Athens (UTC+3)");
    assert.equal(msToNextAthensMidnight(Date.parse("2026-10-24T21:00:00Z")), 25 * 3_600_000, "the 25-hour day: 00:00 to 00:00 across the change");
    assert.equal(msToNextAthensMidnight(Date.parse("2026-11-20T22:00:00Z")), 24 * 3_600_000, "exactly midnight: the next one");
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
const nav = (label: string) => {
  const menu = app().querySelector('nav[aria-label="Μενού μέλους"]');
  // The card (the first screen of an active member) has no menu: its «Αρχική» leads to it.
  const scope: ParentNode = menu ?? app();
  const b = [...scope.querySelectorAll("button")].find((x) => x.textContent?.trim() === (menu ? label : "Αρχική"));
  assert.ok(b, `menu «${label}»`);
  return b as HTMLButtonElement;
};
async function goTo(label: string) {
  if (!app().querySelector('nav[aria-label="Μενού μέλους"]')) await act(async () => void nav(label).click());
  await act(async () => void nav(label).click());
}

function fakeTimers() {
  const live = new Map<number, { fn: () => void; ms: number }>();
  let next = 1;
  const timers: MidnightTimers = {
    setTimeout: (fn, ms) => (live.set(next, { fn, ms }), next++),
    clearTimeout: (id) => void live.delete(id as number),
  };
  return { timers, live, fire: () => [...live.values()].forEach((t) => t.fn()) };
}

async function mount(history: HistoryResult[], opts: { coffees?: CoffeesResult[]; now?: { at: number }; timers?: MidnightTimers } = {}) {
  const h = fakeApi(history);
  const historyFlow = new MemberHistoryFlow(h.api, { now: () => 0 });
  let coffeeCalls = 0;
  const coffeesApi: CustomerCoffeesApi = { coffees: async () => (opts.coffees ?? [{ kind: "unavailable" }])[Math.min(coffeeCalls++, (opts.coffees ?? [1]).length - 1)] };
  const coffeesFlow = new MemberCoffeesFlow(coffeesApi, { now: () => 0 });
  const api: CustomerApi = { me: async () => ({ kind: "ok", me: ME }) };
  const clock = opts.now ?? { at: NOW };
  await dom.render(createElement(CustomerApp, { config: CONFIG, api, now: () => clock.at, navigate: () => {}, coffeesFlow, historyFlow, ...(opts.timers ? { timers: opts.timers } : {}) }));
  await act(async () => {});
  await act(async () => {});
  return { ...h, historyFlow, coffeeCalls: () => coffeeCalls };
}

describe("D24: the member card history", () => {
  it("the history page and the dashboard list the member's own coffees with their preparation; no note, no 7-day strip", async () => {
    await mount([H2], { coffees: [{ kind: "ok", coffees: { today: "2026-11-20", takenToday: true, thisMonth: 2 } }] });
    await goTo("Ιστορικό");
    assert.match(text(), /Οι τελευταίοι σας καφέδες Club, όπως καταχωρήθηκαν στο ταμείο/);
    assert.match(text(), /Freddo Espresso/);
    assert.match(text(), /Διπλός/);
    assert.match(text(), /Espresso/);
    assert.doesNotMatch(text(), /\d+ καφέδες/, "no per-month count from a short list");
    assert.doesNotMatch(text(), /Το ιστορικό δεν είναι ακόμη διαθέσιμο/);
    await goTo("Αρχική");
    assert.match(text(), /Τελευταίοι καφέδες/);
    assert.match(text(), /Freddo Espresso/);
    assert.doesNotMatch(text(), /Το ιστορικό καφέδων δεν είναι ακόμη διαθέσιμο/);
    await goTo("Σήμερα");
    assert.match(text(), /Νέος καφές σε/, "sanity: the Club figures are known on this screen");
    assert.doesNotMatch(text(), /Οι τελευταίες 7 ημέρες/, "never drawn from the short list");
  });

  it("an empty answer says «no coffees yet»; no answer keeps «not yet available»", async () => {
    await mount([{ kind: "ok", items: [] }]);
    await goTo("Ιστορικό");
    assert.match(text(), /Δεν υπάρχουν ακόμη καφέδες/);
    await dom.reset();
    await mount([{ kind: "unavailable" }]);
    await goTo("Ιστορικό");
    assert.match(text(), /Το ιστορικό δεν είναι ακόμη διαθέσιμο/);
  });

  it("round 8 LOW-2: just after the Athens midnight the card re-renders for the new day and asks again", async () => {
    const t = fakeTimers();
    const clock = { at: Date.parse("2026-11-20T21:59:00Z") }; // 23:59 Athens
    const m = await mount([H1, H2], { coffees: [{ kind: "ok", coffees: { today: "2026-11-20", takenToday: true, thisMonth: 3 } }, { kind: "ok", coffees: { today: "2026-11-21", takenToday: false, thisMonth: 3 } }], now: clock, timers: t.timers });
    assert.match(text(), /Τον απολαύσατε σήμερα/);
    const pending = [...t.live.values()];
    assert.equal(pending.length, 1, "one midnight timer");
    assert.equal(pending[0].ms, 60_000);
    clock.at = Date.parse("2026-11-20T22:00:30Z"); // 00:00:30 Athens, the 21st
    await act(async () => t.fire());
    await act(async () => {});
    assert.doesNotMatch(text(), /Τον απολαύσατε σήμερα/, "yesterday's coffee is not today's");
    assert.match(text(), /Ο σημερινός καφές σας περιμένει|Η συνδρομή σας είναι ενεργή/);
    assert.ok(m.calls() >= 1);
    assert.equal(t.live.size, 1, "the next midnight is scheduled again");
    await dom.reset();
    assert.equal(t.live.size, 0, "unmounted: no timer left");
  });
});
