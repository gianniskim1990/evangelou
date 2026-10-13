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

  it("synthetic notes fit the drink: cold coffees are never noted as very hot", () => {
    for (const r of redemptionHistory(TODAY, 30)) {
      const p = historyPreparation(memberById(r.memberId), r.coffee, r.date);
      if (visibleGroups(p).includes("ice")) assert.notEqual(p.note, "πολύ ζεστός", `${r.memberId} ${r.date}`);
    }
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
