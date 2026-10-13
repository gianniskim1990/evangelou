import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import {
  EMPTY_ORDER,
  NOTE_MAX,
  entitlementKey,
  extrasFor,
  historyPreparation,
  isComplete,
  missing,
  noteProblem,
  optionsFor,
  orderReducer,
  preparationLine,
  preparationRows,
  selectCoffee,
  setChoice,
  setNote,
  confirmNote,
  isColdCoffee,
  quickNotesFor,
  toggleQuickNote,
  QUICK_NOTES,
  toSnapshot,
  toggleExtra,
  usualOrder,
  visibleGroups,
  type CoffeeOrder,
} from "../../src/preview/coffeeConfig";
import { CoffeeConfigurator, OrderSummary } from "../../src/preview/StaffOrder";
import { COFFEES, MEMBERS, memberById, redemptionHistory, todaysRedemptions } from "../../src/preview/previewData";

/** UI-02.R1: staff coffee configuration model (preview-only, illustrative catalogue). */

const TODAY = "2026-10-13";
const text = (html: string) => html.replace(/<!-- -->/g, "").replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();

function order(coffee: CoffeeOrder["coffee"], picks: Record<string, string> = {}): CoffeeOrder {
  let o = coffee ? selectCoffee(EMPTY_ORDER, coffee) : EMPTY_ORDER;
  for (const [g, v] of Object.entries(picks)) o = setChoice(o, g as never, v);
  return o;
}

describe("coffee type and conditional groups", () => {
  it("shows nothing until a coffee is chosen, and assumes nothing afterwards", () => {
    assert.deepEqual(visibleGroups(EMPTY_ORDER), []);
    assert.deepEqual(missing(EMPTY_ORDER), ["Καφές"]);
    const fc = selectCoffee(EMPTY_ORDER, "freddo-cappuccino");
    assert.deepEqual(fc.choices, {}, "no sweetness, milk or shots are preselected");
    assert.deepEqual(fc.extras, []);
    assert.deepEqual(missing(fc), ["Δόση", "Ζάχαρη", "Γάλα"]);
    assert.equal(isComplete(fc), false);
  });

  it("sweetness is chosen explicitly; the sweetener appears only when there is some sweetness", () => {
    const e = order("espresso", { shots: "single" });
    assert.ok(!visibleGroups(e).includes("sweetener"));
    const plain = setChoice(e, "sweetness", "none");
    assert.ok(!visibleGroups(plain).includes("sweetener"), "σκέτος needs no sweetener");
    assert.equal(isComplete(plain), true);
    const sweet = setChoice(e, "sweetness", "medium");
    assert.ok(visibleGroups(sweet).includes("sweetener"));
    assert.deepEqual(missing(sweet), ["Γλυκαντικό"]);
    assert.equal(isComplete(setChoice(sweet, "sweetener", "stevia")), true);
  });

  it("setting a sweetener before any sweetness is ignored", () => {
    assert.equal(setChoice(order("espresso"), "sweetener", "white").choices.sweetener, undefined);
  });

  it("going back to σκέτος removes the previously chosen sweetener", () => {
    const o = setChoice(order("filter", { sweetness: "sweet", sweetener: "brown" }), "sweetness", "none");
    assert.equal(o.choices.sweetener, undefined);
  });

  it("milk is offered only where relevant, with coffee-specific options", () => {
    assert.ok(!visibleGroups(order("espresso")).includes("milk"));
    assert.ok(!visibleGroups(order("greek-coffee")).includes("milk"));
    assert.deepEqual(optionsFor("cappuccino", "milk").map((o) => o.id), ["regular", "lactose_free", "plant"], "a cappuccino always has milk");
    assert.deepEqual(optionsFor("freddo-espresso", "milk").map((o) => o.id), ["none", "regular", "lactose_free", "plant"]);
    assert.equal(setChoice(order("cappuccino"), "milk", "none").choices.milk, undefined, "unsupported option is not accepted");
  });

  it("coffee-specific modifiers: shots, ice, foam and decaf only where they apply", () => {
    assert.ok(visibleGroups(order("espresso")).includes("shots"));
    assert.ok(!visibleGroups(order("filter")).includes("shots"));
    assert.ok(visibleGroups(order("freddo-espresso")).includes("ice"));
    assert.ok(!visibleGroups(order("cappuccino")).includes("ice"));
    assert.ok(visibleGroups(order("cappuccino")).includes("foam"));
    assert.ok(!visibleGroups(order("espresso")).includes("foam"));
    assert.deepEqual(extrasFor("greek-coffee"), []);
    assert.equal(toggleExtra(order("greek-coffee"), "decaf").extras.length, 0);
    assert.deepEqual(toggleExtra(order("espresso"), "decaf").extras, ["decaf"]);
  });

  it("optional groups (ice, foam) are not required and can be cleared again", () => {
    const fe = order("freddo-espresso", { shots: "double", sweetness: "none", milk: "none" });
    assert.equal(isComplete(fe), true);
    const little = setChoice(fe, "ice", "little");
    assert.equal(little.choices.ice, "little");
    assert.equal(setChoice(little, "ice", null).choices.ice, undefined);
  });
});

describe("changing the coffee removes incompatible options", () => {
  const full = toggleExtra(order("freddo-cappuccino", { shots: "double", sweetness: "medium", sweetener: "brown", milk: "lactose_free", ice: "little", foam: "extra" }), "decaf");

  it("to espresso: milk, ice and foam go; compatible choices and decaf stay", () => {
    const e = selectCoffee(full, "espresso");
    assert.deepEqual(e.choices, { shots: "double", sweetness: "medium", sweetener: "brown" });
    assert.deepEqual(e.extras, ["decaf"]);
  });

  it("to greek coffee: decaf goes too", () => {
    assert.deepEqual(selectCoffee(full, "greek-coffee").extras, []);
  });

  it("to filter: shots go, milk survives because filter supports it", () => {
    const f = selectCoffee(full, "filter");
    assert.equal(f.choices.shots, undefined);
    assert.equal(f.choices.milk, "lactose_free");
  });

  it("switching back never restores removed choices silently", () => {
    const back = selectCoffee(selectCoffee(full, "espresso"), "freddo-cappuccino");
    assert.equal(back.choices.milk, undefined);
    assert.deepEqual(missing(back), ["Γάλα"]);
  });

  it("an incomplete order is not completed by any default", () => {
    const tryCappuccino = selectCoffee(order("espresso", { shots: "single", sweetness: "none" }), "cappuccino");
    assert.equal(isComplete(tryCappuccino), false);
  });
});

describe("preparation notes", () => {
  it("are single-line and limited to NOTE_MAX characters", () => {
    const o = setNote(order("espresso"), "λίγο\n\nπάγο " + "α".repeat(200));
    assert.ok(!o.note.includes("\n"));
    assert.equal(o.note.length, NOTE_MAX);
  });

  it("refuse phone, card or e-mail looking content and block completion", () => {
    const base = order("espresso", { shots: "single", sweetness: "none" });
    assert.equal(noteProblem("έξτρα αφρό"), null);
    for (const bad of ["καλέστε 6912345678", "κάρτα 4111 1111 1111 1111", "x@example.invalid"]) {
      assert.ok(noteProblem(bad), bad);
      assert.equal(isComplete(setNote(base, bad)), false);
    }
  });
});

describe("order state across the staff flow", () => {
  const start = { memberId: "m01", order: EMPTY_ORDER };

  it("the configuration survives moving between coffee and confirmation (no action touches it)", () => {
    let s = orderReducer(start, { type: "coffee", coffee: "espresso" });
    s = orderReducer(s, { type: "choice", group: "shots", value: "double" });
    s = orderReducer(s, { type: "open-member", memberId: "m01" }); // re-opening the same member
    assert.equal(s.order.choices.shots, "double");
  });

  it("a different member starts with an empty order", () => {
    let s = orderReducer(start, { type: "coffee", coffee: "espresso" });
    s = orderReducer(s, { type: "open-member", memberId: "m05" });
    assert.deepEqual(s, { memberId: "m05", order: EMPTY_ORDER });
  });

  it("the usual preference prefills a copy; editing today's order leaves the usual untouched", () => {
    const member = memberById("m01");
    const usual = usualOrder(member);
    const snapshot = JSON.stringify(usual);
    let s = orderReducer(start, { type: "prefill", order: usual });
    assert.deepEqual(s.order, usual);
    s = orderReducer(s, { type: "choice", group: "sweetness", value: "none" });
    assert.equal(JSON.stringify(usualOrder(member)), snapshot);
    assert.equal(JSON.stringify(usual), snapshot);
    assert.equal(s.order.choices.sweetness, "none");
    assert.notEqual(usual.choices.sweetness, "none", "fixture: m01 usually takes some sugar");
  });

  it("reset clears today's order after a (simulated) confirmation", () => {
    const s = orderReducer(orderReducer(start, { type: "coffee", coffee: "filter" }), { type: "reset" });
    assert.deepEqual(s.order, EMPTY_ORDER);
  });
});

describe("synthetic preferences and history snapshots", () => {
  it("every member's usual preparation is complete and valid for its coffee", () => {
    for (const m of MEMBERS) assert.equal(isComplete(usualOrder(m)), true, m.id);
  });

  it("every synthetic history entry carries a complete preparation snapshot", () => {
    for (const r of redemptionHistory(TODAY, 30)) {
      const p = historyPreparation(memberById(r.memberId), r.coffee, r.date);
      assert.equal(p.coffee, r.coffee);
      assert.equal(isComplete(p), true, `${r.memberId} ${r.date}`);
    }
  });

  it("synthetic notes fit the drink: every history quick note is valid for its coffee", () => {
    let withNotes = 0;
    for (const r of redemptionHistory(TODAY, 30)) {
      const p = historyPreparation(memberById(r.memberId), r.coffee, r.date);
      const allowed = quickNotesFor(r.coffee).map((q) => q.id);
      for (const q of p.quickNotes) assert.ok(allowed.includes(q), `${r.memberId} ${r.date}: ${q} on ${r.coffee}`);
      if (isColdCoffee(r.coffee)) assert.ok(!p.quickNotes.includes("very_hot"), `${r.memberId} ${r.date}`);
      withNotes += p.quickNotes.length;
    }
    assert.ok(withNotes > 0, "the synthetic history includes some quick notes");
  });

  it("history can differ from the usual preference (they are separate things)", () => {
    const m = memberById("m01");
    const lines = new Set(redemptionHistory(TODAY, 30).filter((r) => r.memberId === m.id).map((r) => preparationLine(historyPreparation(m, r.coffee, r.date))));
    assert.ok(lines.size > 1);
  });
});

describe("future redemption snapshot (design only)", () => {
  const meta = { redemptionId: "preview-1", memberId: "m01", athensDate: TODAY, athensTime: "09:05" };

  it("captures the exact drink as one record with modifiers and note", () => {
    const o = setNote(toggleExtra(order("cappuccino", { shots: "double", sweetness: "little", sweetener: "white", milk: "plant", foam: "extra" }), "decaf"), "έξτρα αφρό");
    const s = toSnapshot(o, meta);
    assert.equal(s.coffee.code, "cappuccino");
    assert.deepEqual(s.modifiers.map((m) => `${m.group}=${m.option}`), ["shots=double", "sweetness=little", "sweetener=white", "milk=plant", "foam=extra", "decaf=yes"]);
    assert.equal(s.note, "έξτρα αφρό");
  });

  it("refuses incomplete orders", () => {
    assert.throws(() => toSnapshot(order("cappuccino"), meta));
  });

  it("the preparation never changes the one-coffee-per-Athens-day entitlement key", () => {
    const a = toSnapshot(order("espresso", { shots: "single", sweetness: "none" }), meta);
    const b = toSnapshot(order("filter", { sweetness: "sweet", sweetener: "brown", milk: "regular" }), { ...meta, redemptionId: "preview-2", athensTime: "17:48" });
    assert.equal(entitlementKey(a), entitlementKey(b));
    // The synthetic data keeps at most one redemption per member per day.
    const all = [...redemptionHistory(TODAY, 30), ...todaysRedemptions(TODAY)];
    assert.equal(new Set(all.map((r) => entitlementKey({ memberId: r.memberId, athensDate: r.date }))).size, all.length);
  });
});

describe("configurator and summary rendering", () => {
  const member = memberById("m01");
  const noop = () => {};

  it("renders only the groups of the selected coffee", () => {
    const html = text(renderToStaticMarkup(createElement(CoffeeConfigurator, { member, order: order("greek-coffee"), dispatch: noop, onContinue: noop })));
    assert.match(html, /Μέγεθος/);
    assert.match(html, /Ζάχαρη/);
    assert.doesNotMatch(html, /Γάλα|Αφρόγαλα|Πάγος|Χωρίς καφεΐνη/);
    assert.match(html, /Ενδεικτικός κατάλογος/);
    assert.match(html, /Λείπει: Μέγεθος, Ζάχαρη/);
  });

  it("keeps Continue disabled until the order is complete", () => {
    const btn = (o: CoffeeOrder) => renderToStaticMarkup(createElement(CoffeeConfigurator, { member, order: o, dispatch: noop, onContinue: noop })).match(/<button[^>]*>Συνέχεια<\/button>/)![0];
    assert.match(btn(order("cappuccino")), /disabled=""/);
    assert.doesNotMatch(btn(order("cappuccino", { shots: "single", sweetness: "none", milk: "regular" })), /disabled=""/);
  });

  it("the confirmation summary lists every selection, the note and the Athens date", () => {
    const o = setNote(toggleExtra(order("freddo-cappuccino", { shots: "double", sweetness: "medium", sweetener: "brown", milk: "lactose_free", foam: "extra" }), "decaf"), "λίγο πάγο");
    const html = text(renderToStaticMarkup(createElement(OrderSummary, { member, order: o, today: TODAY })));
    for (const want of [member.name, "Freddo Cappuccino", "Διπλός", "Μέτριος", "Καστανή ζάχαρη", "Χωρίς λακτόζη", "Έξτρα αφρός", "Χωρίς καφεΐνη", "λίγο πάγο", "Τρίτη 13 Οκτωβρίου 2026", "ώρα Ελλάδας"]) {
      assert.ok(html.includes(want), want);
    }
    assert.equal(preparationRows(o).length, 8);
  });

  it("covers the whole illustrative catalogue", () => {
    for (const c of COFFEES) assert.ok(visibleGroups(order(c.code)).includes("sweetness"), c.code);
  });
});

describe("UI-02.R1a coffee-specific quick notes", () => {
  const ids = (c: Parameters<typeof quickNotesFor>[0]) => quickNotesFor(c).map((q) => q.id);
  const cold = COFFEES.map((c) => c.code).filter((c) => isColdCoffee(c));
  const hot = COFFEES.map((c) => c.code).filter((c) => !isColdCoffee(c));

  it("classifies the illustrative catalogue by temperature from its ice options", () => {
    assert.deepEqual(cold, ["freddo-espresso", "freddo-cappuccino"]);
    assert.deepEqual(hot, ["espresso", "cappuccino", "greek-coffee", "filter"]);
  });

  it("cold drinks never suggest «πολύ ζεστός» or «χλιαρός»", () => {
    for (const c of cold) {
      assert.ok(!ids(c).includes("very_hot"), c);
      assert.ok(!ids(c).includes("lukewarm"), c);
    }
  });

  it("hot drinks never suggest ice notes (the catalogue gives them no ice options)", () => {
    for (const c of hot) {
      assert.ok(!ids(c).includes("ice_side"), c);
      assert.ok(!ids(c).includes("no_straw"), c);
      assert.ok(!visibleGroups(order(c)).includes("ice"), c);
    }
  });

  it("foam notes appear only on drinks with foam", () => {
    for (const c of COFFEES.map((x) => x.code)) {
      const hasFoam = visibleGroups(order(c)).includes("foam");
      assert.equal(ids(c).includes("cinnamon"), hasFoam, c);
      assert.equal(ids(c).includes("cocoa"), hasFoam, c);
    }
  });

  it("the generic takeaway note is available for every coffee, and nothing before a coffee is chosen", () => {
    for (const c of COFFEES.map((x) => x.code)) assert.ok(ids(c).includes("takeaway"), c);
    assert.deepEqual(quickNotesFor(null), []);
  });

  it("a quick note that does not fit the coffee cannot be added", () => {
    assert.deepEqual(toggleQuickNote(order("freddo-espresso"), "very_hot").quickNotes, []);
    assert.deepEqual(toggleQuickNote(order("espresso"), "cinnamon").quickNotes, []);
    assert.deepEqual(toggleQuickNote(order("espresso"), "very_hot").quickNotes, ["very_hot"]);
    assert.deepEqual(toggleQuickNote(toggleQuickNote(order("espresso"), "very_hot"), "very_hot").quickNotes, []);
  });

  it("changing the coffee drops incompatible quick notes and keeps compatible ones", () => {
    let o = order("cappuccino");
    for (const q of ["very_hot", "cinnamon", "takeaway"] as const) o = toggleQuickNote(o, q);
    assert.deepEqual(o.quickNotes, ["very_hot", "cinnamon", "takeaway"]);
    assert.deepEqual(selectCoffee(o, "freddo-espresso").quickNotes, ["takeaway"]);
    assert.deepEqual(selectCoffee(o, "freddo-cappuccino").quickNotes, ["cinnamon", "takeaway"]);
    assert.deepEqual(selectCoffee(o, "espresso").quickNotes, ["very_hot", "takeaway"]);
  });

  it("free text is never silently carried to another coffee: it is kept but must be reviewed", () => {
    const complete = order("cappuccino", { shots: "single", sweetness: "none", milk: "regular" });
    const withText = setNote(complete, "πολύ αφρό από πάνω");
    assert.equal(withText.noteReview, false);
    const switched = selectCoffee(withText, "cappuccino");
    assert.equal(switched.noteReview, false, "re-selecting the same coffee is not a change");
    const other = selectCoffee(withText, "freddo-cappuccino");
    assert.equal(other.note, "πολύ αφρό από πάνω");
    assert.equal(other.noteReview, true);
    const ready = setChoice(other, "shots", "single");
    assert.ok(missing(ready).includes("Έλεγχος οδηγιών"));
    assert.equal(isComplete(ready), false);
    assert.equal(isComplete(confirmNote(ready)), true, "staff confirm the text still applies");
    assert.equal(setNote(ready, "χωρίς αφρό").noteReview, false, "editing the text counts as reviewing it");
    assert.equal(setNote(ready, "").noteReview, false, "clearing the text needs no review");
    assert.equal(selectCoffee(complete, "espresso").noteReview, false, "no free text, nothing to review");
  });

  it("the reducer exposes quick notes and review confirmation", () => {
    let s = orderReducer({ memberId: "m01", order: EMPTY_ORDER }, { type: "coffee", coffee: "espresso" });
    s = orderReducer(s, { type: "quick-note", id: "very_hot" });
    s = orderReducer(s, { type: "note", note: "σε μικρό φλιτζάνι" });
    s = orderReducer(s, { type: "coffee", coffee: "freddo-espresso" });
    assert.deepEqual(s.order.quickNotes, []);
    assert.equal(s.order.noteReview, true);
    s = orderReducer(s, { type: "note-ok" });
    assert.equal(s.order.noteReview, false);
  });

  it("summaries and the design-only snapshot list quick notes by label, then the free text", () => {
    let o = order("freddo-espresso", { shots: "double", sweetness: "none", milk: "none" });
    o = setNote(toggleQuickNote(o, "no_straw"), "σε ψηλό ποτήρι");
    assert.match(preparationLine(o), /«χωρίς καλαμάκι», «σε ψηλό ποτήρι»$/);
    assert.deepEqual(preparationRows(o).at(-1), ["Οδηγίες", "χωρίς καλαμάκι, σε ψηλό ποτήρι"]);
    const snap = toSnapshot(o, { redemptionId: "preview-q", memberId: "m01", athensDate: TODAY, athensTime: "10:00" });
    assert.deepEqual(snap.quickNotes, [{ id: "no_straw", label: "χωρίς καλαμάκι" }]);
    assert.equal(snap.note, "σε ψηλό ποτήρι");
  });

  it("the configurator renders only the quick notes of the selected coffee and the review prompt", () => {
    const member = memberById("m01");
    const noop = () => {};
    const render = (o: CoffeeOrder) => text(renderToStaticMarkup(createElement(CoffeeConfigurator, { member, order: o, dispatch: noop, onContinue: noop })));
    const cold = render(order("freddo-espresso"));
    assert.ok(cold.includes("πάγος στο πλάι") && cold.includes("σε ποτήρι για έξω"));
    assert.ok(!cold.includes("πολύ ζεστός") && !cold.includes("κανέλα στον αφρό"));
    const hotPlain = render(order("espresso"));
    assert.ok(hotPlain.includes("πολύ ζεστός"));
    assert.ok(!hotPlain.includes("πάγος στο πλάι") && !hotPlain.includes("κανέλα στον αφρό"));
    assert.ok(render(order("cappuccino")).includes("κανέλα στον αφρό"));
    const flagged = render(selectCoffee(setNote(order("espresso"), "σε μικρό φλιτζάνι"), "filter"));
    assert.match(flagged, /Οι οδηγίες γράφτηκαν για άλλο καφέ/);
    assert.match(flagged, /Έλεγχος οδηγιών/);
    assert.equal(QUICK_NOTES.length, 7);
  });
});

describe("UI-02.R1b mutually exclusive temperature notes", () => {
  const hot = (c: Parameters<typeof order>[0] = "espresso") => order(c);
  const both = (o: CoffeeOrder) => o.quickNotes.includes("very_hot") && o.quickNotes.includes("lukewarm");

  it("selecting «χλιαρός» replaces «πολύ ζεστός» and vice versa", () => {
    let o = toggleQuickNote(hot(), "very_hot");
    o = toggleQuickNote(o, "lukewarm");
    assert.deepEqual(o.quickNotes, ["lukewarm"]);
    o = toggleQuickNote(o, "very_hot");
    assert.deepEqual(o.quickNotes, ["very_hot"]);
  });

  it("other compatible notes stay independently selectable", () => {
    let o = toggleQuickNote(hot("cappuccino"), "takeaway");
    o = toggleQuickNote(o, "cinnamon");
    o = toggleQuickNote(o, "very_hot");
    o = toggleQuickNote(o, "lukewarm");
    assert.deepEqual(o.quickNotes, ["takeaway", "cinnamon", "lukewarm"]);
    assert.deepEqual(toggleQuickNote(o, "takeaway").quickNotes, ["cinnamon", "lukewarm"]);
  });

  it("a selected temperature note can still be cleared", () => {
    assert.deepEqual(toggleQuickNote(toggleQuickNote(hot(), "lukewarm"), "lukewarm").quickNotes, []);
  });

  it("no sequence of taps on any hot coffee ever yields both temperatures", () => {
    const taps = ["very_hot", "lukewarm", "takeaway"] as const;
    for (const c of COFFEES.map((x) => x.code).filter((x) => !isColdCoffee(x))) {
      const walk = (o: CoffeeOrder, depth: number) => {
        assert.ok(!both(o), `${c}: ${o.quickNotes.join("+")}`);
        if (depth === 0) return;
        for (const t of taps) walk(toggleQuickNote(o, t), depth - 1);
      };
      walk(hot(c), 4);
    }
  });

  it("contradictory input from any other path is resolved too (prefill, coffee change)", () => {
    const contradictory: CoffeeOrder = { ...order("espresso", { shots: "single", sweetness: "none" }), quickNotes: ["very_hot", "takeaway", "lukewarm"] };
    const s = orderReducer({ memberId: "m01", order: EMPTY_ORDER }, { type: "prefill", order: contradictory });
    assert.deepEqual(s.order.quickNotes, ["takeaway", "lukewarm"], "the later selection wins");
    assert.ok(!both(selectCoffee(contradictory, "cappuccino")));
    assert.ok(!both(selectCoffee(contradictory, "filter")));
  });

  it("only temperature notes are exclusive", () => {
    const exclusive = QUICK_NOTES.filter((q) => "exclusive" in q && q.exclusive).map((q) => q.id).sort();
    assert.deepEqual(exclusive, ["lukewarm", "very_hot"]);
  });

  it("the configurator shows exactly one temperature as selected", () => {
    const o = toggleQuickNote(toggleQuickNote(hot(), "very_hot"), "lukewarm");
    const html = renderToStaticMarkup(createElement(CoffeeConfigurator, { member: memberById("m01"), order: o, dispatch: () => {}, onContinue: () => {} })).replace(/<!-- -->/g, "");
    assert.match(html, /aria-checked="true"[^>]*>(<svg[\s\S]*?<\/svg>)?χλιαρός</);
    assert.doesNotMatch(html, /aria-checked="true"[^>]*>(<svg[\s\S]*?<\/svg>)?πολύ ζεστός</);
  });
});
