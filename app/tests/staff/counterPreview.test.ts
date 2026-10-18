import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement, useSyncExternalStore } from "react";
import { CounterApp } from "../../src/staff/counterPreview/CounterApp";
import { CounterFlow } from "../../src/staff/counterPreview/counterFlow";
import { CounterPreview } from "../../src/staff/counterPreview/CounterPreview";
import { entryByPreviewId } from "../../src/staff/counterPreview/fakeDirectory";
import { FakeClubServer } from "../../src/staff/counterPreview/fakeServer";
import { PreviewTabStorage } from "../../src/staff/counterPreview/previewSession";
import { abortableSleep } from "../../src/staff/counterPreview/previewTransport";
import { SCENARIOS } from "../../src/staff/counterPreview/scenarios";
import { createDom, type DomHarness } from "./support/dom";

/*
 * UI-04O — DOM tests (jsdom) of the integrated counter screens driven by the
 * real flow over the fake server. They prove structure, headings and focus,
 * accessible names, touch-target classes, that the order to prepare appears
 * only for a fresh commit, that uncertain outcomes never read as success,
 * that member data leaves the DOM on session loss, and that NOTHING touches
 * the network. They do not prove WCAG conformance (real browsers and
 * assistive technology are still required).
 */

const MORNING = "2026-10-19T07:30:00Z";

let dom: DomHarness;
let act: (fn: () => Promise<void>) => Promise<void>;
before(async () => {
  dom = await createDom();
  act = (await import("react")).act as unknown as typeof act;
});
after(() => dom.dispose());
beforeEach(async () => {
  await dom.reset();
});

function world() {
  const now = () => new Date(MORNING);
  const server = new FakeClubServer({ now });
  const storage = new PreviewTabStorage();
  let ids = 0;
  let sessions = 0;
  const flow = new CounterFlow({
    server,
    storage,
    now,
    newId: () => `00000000-0000-4000-8000-${(0xb00000 + ++ids).toString(16).padStart(12, "0")}`,
    newSessionRef: () => (++sessions).toString(16).padStart(32, "7"),
    sha256Hex: async (text) => createHash("sha256").update(text, "utf8").digest("hex"),
    latencyMs: 0,
    sleep: abortableSleep,
    deviceRef: "d".repeat(32),
  });
  return { flow, server, storage };
}

function Host({ flow, server }: { flow: CounterFlow; server: FakeClubServer }) {
  const state = useSyncExternalStore(flow.subscribe, flow.getState);
  return createElement(CounterApp, { key: state.generation, flow, state, server, today: server.businessDate() });
}

/** Lets the flow's promise chains (SHA-256, fake round trips) finish inside act(). */
const settle = () =>
  act(async () => {
    for (let i = 0; i < 30; i++) await new Promise((r) => setImmediate(r));
  });

async function mount() {
  const w = world();
  await dom.render(createElement(Host, { flow: w.flow, server: w.server }));
  await act(async () => {
    await w.flow.start();
  });
  await settle();
  return w;
}

const qa = (sel: string) => [...dom.stage.querySelectorAll(sel)] as HTMLElement[];
const text = () => dom.stage.textContent ?? "";
const h1s = () => qa("h1");
const active = () => dom.document.activeElement as HTMLElement | null;

function button(label: string | RegExp): HTMLElement {
  const found = qa("button").find((b) => (typeof label === "string" ? b.textContent?.trim() === label || b.textContent?.includes(label) : label.test(b.textContent ?? "")));
  assert.ok(found, `button ${String(label)} present`);
  return found;
}

async function press(label: string | RegExp) {
  await dom.click(button(label));
  await settle();
}

/** Scan → member → coffee (usual sample) → continue → confirm. */
async function orderFor(previewId: string, hint: string) {
  await press("Σάρωση κάρτας");
  await press(hint);
  assert.equal(entryByPreviewId(previewId).person.name, h1s()[0]?.textContent);
  await press("Επιλογή καφέ");
  await press("Όπως συνήθως");
  await press("Συνέχεια");
  await press("Επιβεβαίωση");
}

describe("UI-04O counter screens: structure, headings, focus and names", () => {
  it("every screen of the normal flow has exactly one h1 and receives focus on it", async () => {
    await mount();
    const visited: string[] = [];
    const check = (label: string) => {
      const headings = h1s();
      assert.equal(headings.length, 1, `${label}: one h1`);
      assert.ok(headings[0] === active(), `${label}: focus is on the screen heading`);
      visited.push(headings[0].textContent ?? "");
    };
    check("dashboard");
    await press("Σάρωση κάρτας");
    check("scan");
    await press("Αλεξάνδρα");
    check("member");
    await press("Επιλογή καφέ");
    check("coffee");
    await press("Όπως συνήθως");
    await press("Συνέχεια");
    check("confirm");
    assert.deepEqual(visited, ["Ταμείο", "Προσομοίωση σάρωσης", "Αλεξάνδρα Κωνσταντίνου", "Επιλογή καφέ", "Επιβεβαιώστε τον καφέ"]);
  });

  it("every button has an accessible name and a touch-sized target class (48 px or larger)", async () => {
    await mount();
    const screens: (() => Promise<void>)[] = [
      async () => {},
      () => press("Σάρωση κάρτας"),
      () => press("Αλεξάνδρα"),
      () => press("Επιλογή καφέ"),
      async () => {
        await press("Όπως συνήθως");
        await press("Συνέχεια");
      },
      () => press("Επιβεβαίωση"),
    ];
    for (const go of screens) {
      await go();
      for (const b of qa("button")) {
        const name = (b.getAttribute("aria-label") ?? b.textContent ?? "").trim();
        assert.ok(name.length > 0, "button has a name");
        const big = /\bmin-h-(1[2-9]|[2-9]\d)\b|min-h-\[/.test(b.className) || b.className.includes("evc-rs__");
        assert.ok(big, `"${name}" has a 48px+ target class (${b.className})`);
      }
    }
  });

  it("the phone keypad: digits typed by buttons, search enabled only for ten digits", async () => {
    await mount();
    await press("Πληκτρολόγηση κινητού");
    assert.equal(h1s()[0].textContent, "Κινητό τηλέφωνο");
    const search = () => qa("button").find((b) => b.textContent === "Αναζήτηση") as HTMLButtonElement;
    assert.equal(search().disabled, true);
    for (const d of "6910000052") await press(new RegExp(`^${d}$`));
    assert.equal(search().disabled, false);
    await press(/^Αναζήτηση$/);
    assert.equal(h1s()[0].textContent, "Σοφία Λαμπράκη");
    assert.ok(text().includes("69•• ••• •52"), "masked phone only");
    assert.ok(!text().includes("6910000052"), "the full number is not shown once the member is open");
  });
});

describe("UI-04O counter screens: business guarantees visible on screen", () => {
  it("the order to prepare is shown only for a fresh commit, once; after acknowledging it disappears", async () => {
    await mount();
    await orderFor("m01", "Αλεξάνδρα");
    assert.equal(qa('[data-evc-cf="prepare-order"]').length, 1);
    await press("Ετοιμάζεται ο καφές");
    assert.equal(qa('[data-evc-cf="prepare-order"]').length, 0);
    assert.ok(!qa("button").some((b) => b.textContent === "Ετοιμάζεται ο καφές"));
  });

  it("a replay after a lost reply never shows the order to prepare and never reads as success", async () => {
    const w = await mount();
    w.server.schedule("redeem", "lose_reply_after_commit");
    await orderFor("m01", "Αλεξάνδρα");
    assert.equal(qa('[data-evc-cf="prepare-order"]').length, 0);
    assert.ok(text().includes("Δεν έχει επιβεβαιωθεί"), "the uncertain marker is shown");
    assert.ok(!/Η καταχώρηση έγινε/.test(text()));
    await press("Δοκιμή ξανά");
    assert.equal(w.flow.getState().machine.phase, "previously_recorded_unconfirmed");
    assert.equal(qa('[data-evc-cf="prepare-order"]').length, 0);
    assert.ok(!qa("button").some((b) => b.textContent === "Ετοιμάζεται ο καφές"));
  });

  it("while an intent is open there is no way back to the counter or to another member", async () => {
    const w = await mount();
    w.server.schedule("redeem", "lose_request");
    await orderFor("m01", "Αλεξάνδρα");
    const labels = qa("button").map((b) => b.textContent);
    for (const forbidden of ["Ταμείο", "Σάρωση κάρτας", "Επόμενος πελάτης", "Μέλος", "Καφές"]) assert.ok(!labels.includes(forbidden), `no "${forbidden}" button`);
  });

  it("an inactive membership offers no coffee selection; the reason has an icon and text", async () => {
    await mount();
    await press("Σάρωση κάρτας");
    await press("Δήμητρα");
    assert.ok(text().includes("Η συνδρομή δεν είναι ενεργή"));
    assert.ok(!qa("button").some((b) => b.textContent === "Επιλογή καφέ"));
    assert.ok(qa('[role="status"] svg').length >= 1, "an icon accompanies the colour");
  });

  it("session expiry: the member's name and order leave the DOM and only a new login is offered", async () => {
    const w = await mount();
    await press("Σάρωση κάρτας");
    await press("Αλεξάνδρα");
    await act(async () => {
      await w.flow.expireSession();
    });
    await settle();
    assert.ok(!text().includes("Αλεξάνδρα"), "no member data after the session ended");
    assert.deepEqual(qa("button").map((b) => b.textContent), ["Νέα σύνδεση"]);
  });

  it("the dashboard says 'recorded', never 'handed over', for server-confirmed redemptions", async () => {
    await mount();
    assert.ok(text().includes("Σήμερα στο ταμείο"));
    assert.ok(!/Δόθηκε\b/.test(text()));
    assert.ok(text().includes("Η καταχώρηση δεν αποδεικνύει ότι ο καφές παραδόθηκε."));
  });
});

describe("UI-04O counter preview: no network, local only", () => {
  it("a full counter visit and a recovery make zero fetch / XHR / beacon / WebSocket calls", async () => {
    const w = await mount();
    await orderFor("m01", "Αλεξάνδρα");
    await press("Ετοιμάζεται ο καφές");
    await press("Δήλωση: παραδόθηκε τώρα");
    await press("Ολοκλήρωση");
    w.server.schedule("redeem", "lose_reply_after_commit");
    await orderFor("m05", "Σοφία");
    await press("Δοκιμή ξανά");
    await press("Έλεγξα με τον πελάτη");
    await press("Ολοκλήρωση");
    assert.deepEqual(dom.network, { fetch: [], xhr: [], beacon: [], websocket: [] });
  });

  it("the preview page renders the tooling outside the staff chrome, with every scenario listed, and makes no request", async () => {
    await dom.render(createElement(CounterPreview));
    await settle();
    assert.ok(dom.stage.querySelector('[data-evc-preview="counter-flow-local"]'));
    assert.ok(text().includes("ΤΟΠΙΚΗ ΠΡΟΕΠΙΣΚΟΠΗΣΗ"));
    const options = qa("#evc-cf-scenario option").map((o) => o.textContent);
    assert.deepEqual(options, SCENARIOS.map((s) => s.title));
    assert.equal(new Set(SCENARIOS.map((s) => s.id)).size, SCENARIOS.length);
    assert.deepEqual(dom.network, { fetch: [], xhr: [], beacon: [], websocket: [] });
  });
});
