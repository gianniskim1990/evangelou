import assert from "node:assert/strict";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement } from "react";
import type { CustomerConfig } from "../../src/customer/config";
import { CustomerApp } from "../../src/customer/CustomerApp";
import type { CustomerApi, Me, MeResult } from "../../src/customer/customerApi";
import { createDom, type DomHarness } from "../staff/support/dom";

/*
 * STAGE-01 — DOM tests (jsdom) of the REAL customer app over a fake /me
 * client: every server answer renders through the approved screens or a
 * plain status page, nothing unknown is shown as paid or as today's coffee,
 * the QR stays the non-redeemable sample, and the preview's notes about
 * fictional data never appear. No network, no browser storage.
 */

let dom: DomHarness;
let act: (fn: () => Promise<void>) => Promise<void>;
const storageWrites: string[] = [];
const NOW = Date.parse("2026-10-20T06:00:00Z");
const CONFIG: CustomerConfig = { restBase: "https://shop.example/wp-json/evangelou-club-customer/v1", nonce: "0123456789", loginUrl: "https://shop.example/wp-login.php?redirect_to=x", logoutUrl: "https://shop.example/wp-login.php?action=logout&_wpnonce=abc", appVersion: "t" };

before(async () => {
  dom = await createDom();
  act = (await import("react")).act as unknown as typeof act;
  dom.window.scrollTo = () => {};
  for (const area of ["localStorage", "sessionStorage"] as const) {
    const s = dom.window[area];
    for (const m of ["setItem", "removeItem", "clear"] as const) {
      const original = s[m].bind(s);
      Object.defineProperty(s, m, { value: (...args: unknown[]) => (storageWrites.push(`${area}.${m}`), (original as (...a: unknown[]) => unknown)(...args)), configurable: true });
    }
  }
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

function me(state: Me["state"], paidUntilUtc: string | null = null): Me {
  return { displayName: "Ελένη Μαρκοπούλου", email: "eleni@example.org", state, paidUntilUtc };
}

async function mount(results: MeResult[]) {
  let calls = 0;
  const api: CustomerApi = { me: async () => results[Math.min(calls++, results.length - 1)] };
  const visited: string[] = [];
  await dom.render(createElement(CustomerApp, { config: CONFIG, api, now: () => NOW, navigate: (u: string) => visited.push(u) }));
  await act(async () => {});
  return { visited, calls: () => calls };
}

const FICTION = [/φανταστικ/i, /Δείγμα ιστορικού/, /Δείγμα προφίλ/, /Προεπισκόπηση σχεδίου/, /example\.invalid/];
function assertNoPreviewFiction(where: string) {
  for (const re of FICTION) assert.doesNotMatch(text(), re, `${where}: ${re}`);
}

describe("STAGE-01 customer app: membership states from GET /me", () => {
  it("an active member opens on the card: exact end, sample QR, no claim about today's coffee", async () => {
    await mount([{ kind: "ok", me: me("active", "2026-11-15T10:00:00+00:00") }]);
    assert.equal(app().dataset.evcCustomerState, "active");
    const qr = app().querySelector('[data-evc-preview="sample-qr"]');
    assert.ok(qr, "the approved sample code");
    assert.match(qr!.textContent!, /ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ/);
    assert.match(text(), /Ελένη Μαρκοπούλου/);
    assert.match(text(), /Ισχύει έως 15 Νοεμβρίου 2026, 12:00/);
    assert.match(text(), /Η συνδρομή σας είναι ενεργή/);
    assert.doesNotMatch(text(), /Ο σημερινός καφές σας περιμένει|Τον απολαύσατε σήμερα/, "today's coffee is unknown");
    assert.match(text(), /Ο κωδικός QR δεν είναι ακόμη ενεργός/);
    assert.doesNotMatch(text(), /Μέλος από τις/, "no invented member-since date");
    assertNoPreviewFiction("card");

    await dom.click(button("Αρχική"));
    assert.match(text(), /Το ιστορικό καφέδων δεν είναι ακόμη διαθέσιμο/);
    assert.match(text(), /Δεν είναι ακόμη διαθέσιμο/);
    for (const label of ["Σήμερα", "Ιστορικό", "Λογαριασμός"]) {
      await dom.click(button(label, app().querySelector('nav[aria-label="Μενού μέλους"]')!));
      assert.equal(dom.document.activeElement, app().querySelector("h1"), `${label}: focus on the heading`);
      assertNoPreviewFiction(label);
      assert.doesNotMatch(text(), /Ισχύει για \d+ ώρες/, `${label}: no countdown that implies an available coffee`);
    }
    assert.match(text(), /eleni@example\.org/);
    assert.match(text(), /Δεν δόθηκε/, "no invented phone number");
    assert.doesNotMatch(text(), /Newsletter/, "consent is not read, so not shown");
  });

  it("pending, expired, cancelled, none and unverified are explained and never shown as paid", async () => {
    const cases: [Me, RegExp][] = [
      [me("pending_payment"), /Σε αναμονή πληρωμής/],
      [me("expired", "2026-10-16T16:30:00+00:00"), /Έληξε στις 16 Οκτωβρίου 2026, 19:30/],
      [me("cancelled"), /Η συνδρομή ακυρώθηκε/],
      [me("none"), /Δεν έχετε συνδρομή/],
      [me("unverified"), /Η συνδρομή δεν επιβεβαιώθηκε/],
    ];
    for (const [m, phrase] of cases) {
      await dom.reset();
      await mount([{ kind: "ok", me: m }]);
      assert.match(text(), phrase, m.state);
      assert.doesNotMatch(text(), /Η συνδρομή σας είναι ενεργή|Ο σημερινός καφές σας περιμένει|Ισχύει έως/, `${m.state} is never active`);
      assert.ok(app().querySelector('nav[aria-label="Μενού μέλους"]'), `${m.state} opens on the dashboard`);
      assertNoPreviewFiction(m.state);
    }
  });
});

describe("STAGE-01 customer app: signed out, not a customer, unavailable, errors", () => {
  it("signed out: a native WordPress login button, no member data", async () => {
    const { visited } = await mount([{ kind: "signed_out" }]);
    assert.match(text(), /Συνδεθείτε με το e-mail και τον κωδικό/);
    assert.equal(app().querySelectorAll("input").length, 0, "no password field in the app: WordPress login only");
    await dom.click(button("Σύνδεση"));
    assert.deepEqual(visited, [CONFIG.loginUrl]);
  });

  it("not a customer account: explained, with sign-out", async () => {
    const { visited } = await mount([{ kind: "forbidden" }]);
    assert.match(text(), /Δεν είναι λογαριασμός μέλους/);
    await dom.click(button("Αποσύνδεση"));
    assert.deepEqual(visited, [CONFIG.logoutUrl]);
  });

  it("source unavailable: no membership is guessed; retry asks the server again", async () => {
    const { calls } = await mount([{ kind: "unavailable" }, { kind: "ok", me: me("active", "2026-11-15T10:00:00+00:00") }]);
    assert.equal(app().dataset.evcCustomerState, "unavailable");
    assert.match(text(), /Δεν μπορούμε να ελέγξουμε τη συνδρομή σας/);
    assert.doesNotMatch(text(), /ενεργή|Ελένη/);
    assert.equal(app().querySelector('[data-evc-preview="sample-qr"]'), null, "no card without a confirmed answer");
    await dom.click(button("Δοκιμάστε ξανά"));
    await act(async () => {});
    assert.equal(calls(), 2);
    assert.equal(app().dataset.evcCustomerState, "active");
  });

  it("errors: retry, never a membership", async () => {
    await mount([{ kind: "error" }]);
    assert.match(text(), /Δεν φορτώθηκε ο λογαριασμός σας/);
    assert.equal(app().querySelector('[data-evc-preview="sample-qr"]'), null);
  });

  it("sign-out from the profile uses WordPress's own logout", async () => {
    const { visited } = await mount([{ kind: "ok", me: me("active", "2026-11-15T10:00:00+00:00") }]);
    await dom.click(button("Αρχική"));
    await dom.click(button("Λογαριασμός", app().querySelector('nav[aria-label="Μενού μέλους"]')!));
    await dom.click(button("Αποσύνδεση"));
    assert.deepEqual(visited, [CONFIG.logoutUrl]);
    assert.equal(dom.network.fetch.length + dom.network.xhr.length + dom.network.beacon.length + dom.network.websocket.length, 0);
    assert.deepEqual(storageWrites, []);
  });
});
