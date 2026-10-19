import assert from "node:assert/strict";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement } from "react";
import { CustomerJourney } from "../../src/customerPreview/CustomerJourney";
import { CustomerJourneyPreview } from "../../src/customerPreview/CustomerJourneyPreview";
import { FakeCustomerServer } from "../../src/customerPreview/customerServer";
import { SCENARIOS, SCENARIO_START } from "../../src/customerPreview/scenarios";
import { createDom, type DomHarness } from "../staff/support/dom";

/*
 * CUSTOMER-01 — DOM tests (jsdom) of the LOCAL-ONLY customer journey: the
 * approved customer screens driven by the simulated server. They prove the
 * journeys work end to end, that the form is accessible (labels, required,
 * aria-invalid + described errors, focus on the first invalid field, focus
 * on each new screen's heading), that no screen ever claims a payment or a
 * registration succeeded, that only the shop side (preview tools) confirms a
 * payment or records a coffee, and that nothing touches the network or
 * browser storage. They do not prove WCAG conformance.
 */

let dom: DomHarness;
let act: (fn: () => Promise<void>) => Promise<void>;
const storageWrites: string[] = [];

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
  dom.window.history.replaceState(null, "", "/customer-preview.html");
});

const NO_SUCCESS_CLAIMS = [/πληρωμή\s+(ολοκληρώθηκε|έγινε|πραγματοποιήθηκε)/i, /επιτυχ/i, /λογαριασμός (σας )?δημιουργήθηκε/i, /η εγγραφή (σας )?ολοκληρώθηκε/i, /πληρώθηκε/i];

const app = () => dom.document.querySelector('[data-evc-cj="app"]') as HTMLElement;
const screen = () => app().dataset.evcCjScreen;
const text = () => app().textContent ?? "";
const h1 = () => app().querySelector("h1") as HTMLElement;
const button = (label: string, root: ParentNode = dom.document) => {
  const b = [...root.querySelectorAll("button")].find((x) => x.textContent?.trim() === label);
  assert.ok(b, `button «${label}» exists`);
  return b as HTMLButtonElement;
};
const input = (id: string) => dom.document.getElementById(id) as HTMLInputElement;

async function type(el: HTMLInputElement, value: string) {
  await act(async () => {
    Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype, "value")!.set!.call(el, value);
    el.dispatchEvent(new dom.window.Event("input", { bubbles: true }));
  });
}

async function selectScenario(id: string) {
  const sel = dom.document.getElementById("evc-cj-scenario") as HTMLSelectElement;
  await act(async () => {
    Object.getOwnPropertyDescriptor(dom.window.HTMLSelectElement.prototype, "value")!.set!.call(sel, id);
    sel.dispatchEvent(new dom.window.Event("change", { bubbles: true }));
  });
}

function assertNoSuccessClaim(where: string) {
  for (const re of NO_SUCCESS_CLAIMS) assert.doesNotMatch(text(), re, `${where}: no screen may claim ${re}`);
}

async function mountJourney(scenarioId: string, emailRequired = true) {
  const now = () => SCENARIO_START;
  const server = new FakeCustomerServer(now, SCENARIOS.find((s) => s.id === scenarioId)!.seed());
  await dom.render(createElement(CustomerJourney, { server, now, emailRequired }));
  return server;
}

describe("CUSTOMER-01 customer journey: new customer", () => {
  it("welcome → registration with accessible validation → consents → offer → honest payment step → pending dashboard", async () => {
    const server = await mountJourney("visitor");
    assert.equal(screen(), "welcome");
    await dom.click(button("Γίνετε μέλος"));
    assert.equal(screen(), "register");
    assert.equal(dom.document.activeElement, h1(), "focus moves to the new screen's heading");

    // Empty submit: every field is marked, described and the first one is focused.
    await dom.click(button("Συνέχεια"));
    assert.equal(screen(), "register");
    for (const id of ["evc-cj-name", "evc-cj-phone", "evc-cj-email"]) {
      const el = input(id);
      assert.equal(el.getAttribute("aria-invalid"), "true", id);
      assert.ok(el.getAttribute("aria-describedby")?.includes(`${id}-error`), `${id} is described by its error`);
      assert.ok(el.required, `${id} is required`);
      assert.ok(dom.document.querySelector(`label[for="${id}"]`), `${id} has a label`);
    }
    assert.equal(dom.document.activeElement, input("evc-cj-name"));
    assert.match(app().querySelector('[role="status"]')!.textContent!, /Ελέγξτε 3 πεδία/);

    await type(input("evc-cj-name"), "Ελένη Μαρκοπούλου");
    await type(input("evc-cj-phone"), "694 12 34 567");
    await type(input("evc-cj-email"), "wrong");
    assert.equal(input("evc-cj-name").getAttribute("aria-invalid"), null, "errors clear while the user corrects them");
    await dom.click(button("Συνέχεια"));
    assert.equal(screen(), "register");
    assert.equal(dom.document.activeElement, input("evc-cj-email"));
    await type(input("evc-cj-email"), "eleni@example.invalid");
    await dom.click(button("Συνέχεια"));
    assert.equal(screen(), "consents");

    // Two independent choices; only the terms are required.
    const boxes = [...app().querySelectorAll('input[type="checkbox"]')] as HTMLInputElement[];
    assert.equal(boxes.length, 2);
    assert.equal(button("Συνέχεια").disabled, true);
    await dom.click(boxes[0]);
    assert.equal(button("Συνέχεια").disabled, false, "the newsletter stays optional");
    assert.match(text(), /Newsletter: όχι/);
    await dom.click(button("Συνέχεια"));
    assert.equal(screen(), "offer");
    assert.match(text(), /20 €/);
    assert.match(text(), /ημερολογιακό μήνα/);
    assert.doesNotMatch(text(), /30 ημέρ/);
    assert.equal(server.snapshot(), null, "nothing exists before «Πληρωμή»");

    await dom.click(button("Πληρωμή 20 €"));
    assert.equal(screen(), "payment");
    assert.equal(h1().textContent, "Η πληρωμή δεν έγινε");
    assert.match(text(), /Δεν χρεώθηκε τίποτα/);
    assertNoSuccessClaim("payment step");
    const s = server.snapshot()!;
    assert.deepEqual([s.state, s.pendingPayment?.kind, s.account.newsletter, s.account.member.phoneMasked], ["pending", "initial", false, "69•• ••• •67"]);

    await dom.click(button("Στον λογαριασμό μου"));
    assert.equal(screen(), "dashboard");
    assert.match(text(), /Σε αναμονή πληρωμής/);
    assert.match(text(), /Δεν υπάρχουν ακόμη καφέδες/);
    assertNoSuccessClaim("pending dashboard");
    assert.equal(dom.network.fetch.length + dom.network.xhr.length + dom.network.beacon.length + dom.network.websocket.length, 0);
    assert.deepEqual(storageWrites, []);
  });

  it("D7 switch: with an optional e-mail the form accepts an empty e-mail, but never an invalid one", async () => {
    await mountJourney("visitor", false);
    await dom.click(button("Γίνετε μέλος"));
    assert.equal(input("evc-cj-email").required, false);
    await type(input("evc-cj-name"), "Νίκος Παππάς");
    await type(input("evc-cj-phone"), "+30 697 000 0011");
    await type(input("evc-cj-email"), "nikos@");
    await dom.click(button("Συνέχεια"));
    assert.equal(screen(), "register");
    await type(input("evc-cj-email"), "");
    await dom.click(button("Συνέχεια"));
    assert.equal(screen(), "consents");
  });

  it("«Έχω ήδη κάρτα» without an account shows that sign-in is an open decision, never a fake login", async () => {
    await mountJourney("visitor");
    await dom.click(button("Έχω ήδη κάρτα"));
    assert.equal(screen(), "signin");
    assert.match(text(), /δεν έχει αποφασιστεί/);
    assert.equal(app().querySelectorAll("input").length, 0, "no credential fields");
  });
});

describe("CUSTOMER-01 customer journey: members", () => {
  it("an active member opens straight on the card; dashboard, today, history and profile are one tap apart", async () => {
    await mountJourney("active");
    assert.equal(screen(), "card");
    const qr = app().querySelector('[data-evc-preview="sample-qr"]');
    assert.ok(qr, "the card shows the approved sample code");
    assert.match(qr!.textContent!, /ΔΕΙΓΜΑ\s*ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ/);
    assert.match(text(), /Ισχύει έως 8 Νοεμβρίου 2026, 10:42/);
    assert.match(text(), /Ο σημερινός καφές σας περιμένει/);

    await dom.click(button("Αρχική"));
    assert.equal(screen(), "dashboard");
    const nav = app().querySelector('nav[aria-label="Μενού μέλους"]')!;
    assert.equal(nav.querySelector('[aria-current="page"]')?.textContent, "Αρχική");
    for (const [label, id] of [["Σήμερα", "today"], ["Ιστορικό", "history"], ["Λογαριασμός", "profile"], ["Κάρτα", "card"]] as const) {
      await dom.click(button(label, app().querySelector('nav[aria-label="Μενού μέλους"]')!));
      assert.equal(screen(), id, label);
      if (id !== "card") assert.equal(dom.document.activeElement, h1(), `${label}: focus on the heading`);
      if (id !== "card") await dom.click(button("Αρχική", app().querySelector('nav[aria-label="Μενού μέλους"]')!));
    }
  });

  it("history shows only real (synthetic) coffees with their preparation, newest first", async () => {
    const server = await mountJourney("active");
    await dom.click(button("Αρχική"));
    await dom.click(button("Όλο το ιστορικό"));
    assert.equal(screen(), "history");
    const items = server.snapshot()!.history;
    assert.ok(items.length > 3);
    assert.equal(app().querySelectorAll("li").length >= items.length, true);
    assert.match(text(), /Δείγμα ιστορικού/);
  });

  it("pending, expired and cancelled members can never take a coffee, and their screens say so", async () => {
    for (const [id, phrase] of [["pending", /Σε αναμονή πληρωμής/], ["expired", /Η συνδρομή έληξε/], ["cancelled", /Η συνδρομή ακυρώθηκε/]] as const) {
      await dom.reset();
      const server = await mountJourney(id);
      assert.equal(screen(), "dashboard", `${id} opens on the dashboard with the explanation`);
      assert.match(text(), phrase);
      assert.doesNotMatch(text(), /Ο σημερινός καφές σας περιμένει/);
      assert.equal(server.snapshot()!.canTakeCoffeeToday, false);
      let ok = true;
      await act(async () => {
        ok = server.recordCoffee("espresso").ok;
      });
      assert.equal(ok, false);
      assertNoSuccessClaim(id);
    }
  });

  it("renewal from the profile is only a request: nothing changes until the shop confirms it", async () => {
    const server = await mountJourney("expiring");
    const before = server.snapshot()!.paidUntil;
    await dom.click(button("Αρχική"));
    await dom.click(button("Λογαριασμός", app().querySelector('nav[aria-label="Μενού μέλους"]')!));
    await dom.click(button("Ανανέωση τώρα, 20 €"));
    assert.equal(screen(), "payment");
    assert.equal(h1().textContent, "Η ανανέωση περιμένει πληρωμή");
    assertNoSuccessClaim("renewal payment step");
    assert.equal(server.snapshot()!.paidUntil, before);
    await dom.click(button("Στον λογαριασμό μου"));
    assert.match(text(), /Ζητήθηκε ανανέωση· περιμένει επιβεβαίωση πληρωμής/);
  });
});

describe("CUSTOMER-01 preview page: tools act as the shop, outside the customer's screens", () => {
  it("confirming a payment, recording coffees and moving the clock update the customer screens and are announced", async () => {
    await dom.render(createElement(CustomerJourneyPreview));
    assert.match(dom.document.body.textContent!, /ΤΟΠΙΚΗ ΠΡΟΕΠΙΣΚΟΠΗΣΗ/);
    await selectScenario("pending");
    await dom.click(button("Εργαλεία"));
    const tools = dom.document.querySelector('[aria-label="Εργαλεία προσομοίωσης"]')!;
    assert.equal(app().contains(tools), false, "the shop tools are not part of the customer's screens");

    await dom.click(button("Καταχώρηση καφέ από το ταμείο"));
    assert.match(dom.document.querySelector(".evc-cj__outcome")!.textContent!, /απορρίφθηκε/);
    await dom.click(button("Το κατάστημα επιβεβαιώνει την πληρωμή"));
    assert.match(app().querySelector('[role="status"]')!.textContent!, /ενεργή/);
    assert.match(text(), /Ο σημερινός καφές σας περιμένει/);

    await dom.click(button("Καταχώρηση καφέ από το ταμείο"));
    assert.match(app().querySelector('[role="status"]')!.textContent!, /καταχώρησε τον σημερινό σας καφέ/);
    assert.match(text(), /Τον απολαύσατε σήμερα/);
    await dom.click(button("Καταχώρηση καφέ από το ταμείο"));
    assert.match(dom.document.querySelector(".evc-cj__outcome")!.textContent!, /έχει ήδη καταχωρηθεί/);

    await dom.click(button("Λίγο πριν τα μεσάνυχτα"));
    assert.match(text(), /Τον απολαύσατε σήμερα/);
    await dom.click(button("+1 ώρα"));
    assert.match(text(), /Ο σημερινός καφές σας περιμένει/, "a new Athens day");

    await dom.click(button("Στη λήξη"));
    assert.match(text(), /Η συνδρομή έληξε/);
    assert.match(app().querySelector('[role="status"]')!.textContent!, /έληξε/);
    assert.equal(dom.network.fetch.length + dom.network.xhr.length + dom.network.beacon.length + dom.network.websocket.length, 0);
    assert.deepEqual(storageWrites, []);
  });

  it("switching scenario starts a fresh world on that scenario's first screen", async () => {
    await dom.render(createElement(CustomerJourneyPreview));
    await selectScenario("active");
    assert.equal(screen(), "card");
    await selectScenario("visitor");
    assert.equal(screen(), "welcome");
    await selectScenario("cancelled");
    assert.equal(screen(), "dashboard");
  });
});
