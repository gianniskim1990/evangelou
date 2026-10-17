import assert from "node:assert/strict";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement } from "react";
import { screenContract, TOUCH_TARGET } from "../../src/staff/redemptionRecovery/a11yContract";
import type { Action, MachineState } from "../../src/staff/redemptionRecovery/machine";
import { ACTION_LABELS, describeRecovery, type RecoveryView } from "../../src/staff/redemptionRecovery/viewModel";
import { RecoveryScreen } from "../../src/staff/recoveryScreen/RecoveryScreen";
import { PREVIEW_SCENARIOS } from "../../src/staff/recoveryScreen/previewScenarios";
import { createDom, type DomHarness } from "./support/dom";
import { HID, RID, allRecoveryStates, handoff, handoffIntent, intent, record } from "./support/recoveryStates";
import { MEMBER_A } from "./support/recoveryFixtures";

/*
 * UI-04N — DOM tests (jsdom) of the standalone Staff Recovery Screen. They
 * prove structure, action set and order, click wiring, focus, live regions
 * and the absence of network access. They do NOT prove WCAG conformance:
 * contrast, real focus rendering, target size on a tablet and screen-reader
 * output need real browsers and assistive technology after integration.
 */

const OUTCOME_WORDS = /επιτυχ|ολοκληρώθηκε|απέτυχε|αποτυχ|έγινε η καταχώρηση|δεν έγινε/i;

let dom: DomHarness;
before(async () => {
  dom = await createDom();
});
after(() => dom.dispose());
beforeEach(async () => {
  await dom.reset();
});

const view = (s: MachineState): RecoveryView => describeRecovery(s);

async function show(v: RecoveryView, calls: Action[] = []): Promise<void> {
  await dom.render(createElement(RecoveryScreen, { view: v, onAction: (a: Action) => calls.push(a) }));
}

const q = (sel: string) => dom.stage.querySelector(sel) as HTMLElement | null;
const qa = (sel: string) => [...dom.stage.querySelectorAll(sel)] as HTMLElement[];
const polite = () => (q('[data-live="polite"]') as HTMLElement).textContent ?? "";
const assertive = () => (q('[data-live="assertive"]') as HTMLElement).textContent ?? "";
const active = () => dom.document.activeElement as HTMLElement | null;

describe("UI-04N RecoveryScreen: structure and exact action set", () => {
  it("every state renders a labelled section, an h2 headline and exactly the contract's actions in order", async () => {
    const states = allRecoveryStates();
    assert.ok(states.length >= 60);
    for (const state of states) {
      const v = view(state);
      const c = screenContract(v);
      await dom.reset();
      await show(v);
      const section = q("section") as HTMLElement;
      const h2 = q("h2") as HTMLElement;
      assert.ok(section && h2, c.caseKey);
      assert.equal(section.getAttribute("aria-labelledby"), h2.id, c.caseKey);
      assert.equal(h2.textContent, v.headline, c.caseKey);
      assert.equal(section.getAttribute("aria-busy"), c.busy ? "true" : "false", c.caseKey);
      const buttons = qa("button");
      assert.deepEqual(buttons.map((b) => b.dataset.action), c.actions.map((a) => a.action), `${c.caseKey}: exact order`);
      assert.deepEqual(buttons.map((b) => b.textContent), c.actions.map((a) => a.label), `${c.caseKey}: exact labels`);
      assert.deepEqual(buttons.map((b) => b.dataset.action), availableOf(state), `${c.caseKey}: machine actions only`);
      assert.equal(qa("a, input, select, textarea, [role=button], [role=link]").length, 0, `${c.caseKey}: no other interactive element`);
      assert.equal(qa("[disabled], [aria-disabled=true]").length, 0, `${c.caseKey}: no disabled decoy controls`);
    }
  });

  it("body lines, uncertain marker, icon and escalation come from the contract", async () => {
    for (const state of allRecoveryStates()) {
      const v = view(state);
      const c = screenContract(v);
      await dom.reset();
      await show(v);
      assert.deepEqual(qa(".evc-rs__body p").map((p) => p.textContent), [...v.body], c.caseKey);
      assert.equal(q(".evc-rs__marker")?.textContent ?? null, c.uncertainMarker, c.caseKey);
      assert.equal(q("svg.evc-rs__icon") !== null, true, c.caseKey);
      assert.equal(q("section")?.classList.contains(`evc-rs--${v.tone}`), true, c.caseKey);
      const aside = q("aside");
      assert.equal(aside !== null, c.escalation.presentation !== "hidden", c.caseKey);
      if (aside) {
        assert.equal(aside.querySelector("h3")?.textContent, c.escalation.heading);
        assert.deepEqual([...aside.querySelectorAll("li")].map((li) => li.textContent), [...c.escalation.steps]);
        assert.equal(aside.classList.contains(c.escalation.presentation === "panel_primary" ? "evc-rs__escalation--primary" : "evc-rs__escalation--secondary"), true);
      }
    }
  });

  it("touch-target contract is carried on the section (>= 48px targets, >= 12px gaps)", async () => {
    await show(view({ phase: "redemption_unresolved", intent, reason: "timeout" }));
    const style = (q("section") as HTMLElement).style;
    assert.equal(style.getPropertyValue("--evc-rs-target"), `${TOUCH_TARGET.preferredCssPx}px`);
    assert.equal(style.getPropertyValue("--evc-rs-gap"), `${TOUCH_TARGET.minimumGapCssPx}px`);
    assert.ok(TOUCH_TARGET.preferredCssPx >= 48 && TOUCH_TARGET.minimumGapCssPx >= 12);
    assert.equal(qa("button").length, 2);
  });
});

function availableOf(state: MachineState): Action[] {
  return describeRecovery(state).actions.map((a) => a.action);
}

describe("UI-04N RecoveryScreen: clicks only call the injected callback", () => {
  it("clicking each action calls onAction once with exactly that name and does nothing else", async () => {
    for (const state of allRecoveryStates()) {
      const v = view(state);
      for (const [i, expected] of v.actions.map((a) => a.action).entries()) {
        await dom.reset();
        const calls: Action[] = [];
        await show(v, calls);
        const before = dom.stage.innerHTML;
        await dom.click(qa("button")[i]);
        assert.deepEqual(calls, [expected], `${v.caseKey}/${expected}`);
        assert.equal(dom.stage.innerHTML, before, `${v.caseKey}/${expected}: a click does not change the screen by itself`);
      }
    }
  });

  it("no network call of any kind during rendering, focus changes or clicks", async () => {
    for (const state of allRecoveryStates()) {
      await show(view(state));
      for (const b of qa("button")) await dom.click(b);
    }
    assert.deepEqual(dom.network, { fetch: [], xhr: [], beacon: [], websocket: [] });
  });

  it("no automatic action: rendering any state calls the callback zero times", async () => {
    for (const state of allRecoveryStates()) {
      const calls: Action[] = [];
      await dom.reset();
      await show(view(state), calls);
      await show(view(state), calls);
      assert.deepEqual(calls, [], view(state).caseKey);
    }
  });
});

describe("UI-04N RecoveryScreen: focus", () => {
  it("opening the screen focuses the heading, never a button", async () => {
    for (const state of allRecoveryStates()) {
      const v = view(state);
      await dom.reset();
      await show(v);
      const a = active();
      assert.ok(a, v.caseKey);
      assert.notEqual(a.tagName, "BUTTON", v.caseKey);
      if (v.waiting) assert.equal(a.className, "evc-rs__busy", `${v.caseKey}: busy status`);
      else assert.equal(a, q("h2"), `${v.caseKey}: heading`);
    }
  });

  it("a transition to another case moves focus to the heading (or busy status) even if a button had focus", async () => {
    const pairs: [MachineState, MachineState][] = [
      [{ phase: "redemption_unresolved", intent, reason: "timeout" }, { phase: "checking_status", intent, handoffIntent: null }],
      [{ phase: "checking_status", intent, handoffIntent: null }, { phase: "recorded_now", intent, record, prepareAcknowledged: false }],
      [{ phase: "redemption_unresolved", intent, reason: "timeout" }, { phase: "persistence_blocked", operation: "redemption", intent, mayHaveBeenSent: true }],
      [{ phase: "previously_recorded_unconfirmed", intent, record, manuallyVerified: false }, { phase: "blocked_authorization", reason: "expired" }],
    ];
    for (const [from, to] of pairs) {
      await dom.reset();
      await show(view(from));
      const firstButton = qa("button")[0];
      if (firstButton) await dom.focus(firstButton);
      await show(view(to));
      const next = view(to);
      const a = active();
      assert.ok(a);
      assert.notEqual(a.tagName, "BUTTON", `${view(from).caseKey} -> ${next.caseKey}`);
      if (next.waiting) assert.equal(a.className, "evc-rs__busy");
      else assert.equal(a, q("h2"));
    }
  });

  it("re-rendering the same case keeps focus on the focused action (no focus stealing)", async () => {
    const state: MachineState = { phase: "redemption_unresolved", intent, reason: "timeout" };
    await show(view(state));
    const second = qa("button")[1];
    await dom.focus(second);
    await show(view(state)); // new but equal view object, as a poll would produce
    await show(view(state));
    assert.equal(active(), qa("button")[1]);
    assert.equal(active()?.dataset.action, "check_status");
  });

  it("if the focused action is no longer offered, focus falls back to the heading, never to a hidden control", async () => {
    const before: MachineState = { phase: "previously_recorded_unconfirmed", intent, record, manuallyVerified: false };
    await show(view(before));
    await dom.focus(qa('[data-action="confirm_manual_verification"]')[0]);
    await show(view({ phase: "previously_recorded_unconfirmed", intent, record, manuallyVerified: true }));
    assert.equal(active(), q("h2"));
    assert.equal(qa('[data-action="confirm_manual_verification"]').length, 0);
  });

  it("a success right after a lock moves focus to the heading, not to any action", async () => {
    await show(view({ phase: "blocked_authorization", reason: "locked" }));
    await dom.focus(qa("button")[0]);
    await show(view({ phase: "recorded_now", intent, record, prepareAcknowledged: false }));
    assert.equal(active(), q("h2"));
  });

  it("headings and the busy status are focusable by script only (tabindex -1), buttons keep natural order", async () => {
    await show(view({ phase: "recovery_required", intent, handoffIntent: null, reason: "storage_unavailable" }));
    assert.equal(q("h2")?.getAttribute("tabindex"), "-1");
    assert.deepEqual(qa("[tabindex]").map((e) => e.tagName), ["H2"]);
    assert.equal(qa("button").every((b) => !b.hasAttribute("tabindex")), true, "no positive or custom tab order");
    await dom.reset();
    await show(view({ phase: "submitting_redemption", intent }));
    assert.deepEqual(qa("[tabindex]").map((e) => e.className), ["evc-rs__title", "evc-rs__busy"]);
    assert.equal(qa("button").length, 0, "no control while waiting: no focus trap");
  });
});

describe("UI-04N RecoveryScreen: live regions", () => {
  it("two persistent regions exist: polite role=status and assertive role=alert", async () => {
    await show(view({ phase: "idle" }));
    const status = q('[data-live="polite"]') as HTMLElement;
    const alertRegion = q('[data-live="assertive"]') as HTMLElement;
    assert.equal(status.getAttribute("role"), "status");
    assert.equal(status.getAttribute("aria-live"), "polite");
    assert.equal(alertRegion.getAttribute("role"), "alert");
    assert.equal(alertRegion.getAttribute("aria-live"), "assertive");
    assert.equal(polite(), "");
    assert.equal(assertive(), "", "idle is silent");
  });

  it("ordinary states announce politely; only authorization and integrity blocks use the alert region", async () => {
    for (const state of allRecoveryStates()) {
      const v = view(state);
      const urgent = v.caseKey.startsWith("blocked_authorization:") || v.caseKey === "blocked_integrity";
      await dom.reset();
      await show(v);
      if (v.caseKey === "idle") {
        assert.equal(polite() + assertive(), "");
        continue;
      }
      assert.equal(assertive() !== "", urgent, v.caseKey);
      assert.equal(polite() !== "", !urgent, v.caseKey);
      assert.ok((urgent ? assertive() : polite()).includes(v.headline.replace(/…$/, "")), v.caseKey);
    }
  });

  it("an unknown outcome is announced as unconfirmed and never as success or failure", async () => {
    for (const state of allRecoveryStates()) {
      const v = view(state);
      if (v.certainty !== "uncertain" || v.waiting) continue;
      await dom.reset();
      await show(v);
      const text = polite() + assertive();
      assert.ok(text.startsWith("Δεν έχει επιβεβαιωθεί"), `${v.caseKey}: ${text}`);
      assert.doesNotMatch(text, OUTCOME_WORDS, v.caseKey);
      assert.equal(q("section")?.classList.contains("evc-rs--positive"), false, v.caseKey);
      assert.equal(q(".evc-rs__marker") !== null, true, `${v.caseKey}: visible marker, not colour alone`);
    }
  });

  it("re-rendering the same case does not announce again (no DOM change in the live regions)", async () => {
    await show(view({ phase: "redemption_unresolved", intent, reason: "timeout" }));
    const regions = qa(".evc-rs__sr");
    const before = regions.map((r) => r.textContent);
    let mutations = 0;
    const observer = new dom.window.MutationObserver((records: MutationRecord[]) => {
      mutations += records.length;
    });
    for (const r of regions) observer.observe(r, { childList: true, characterData: true, subtree: true, attributes: true });
    for (let i = 0; i < 3; i++) await show(view({ phase: "redemption_unresolved", intent, reason: "timeout" }));
    await Promise.resolve();
    observer.disconnect();
    assert.equal(mutations, 0);
    assert.deepEqual(qa(".evc-rs__sr").map((r) => r.textContent), before);
  });

  it("a real transition announces the new case once", async () => {
    await show(view({ phase: "redemption_unresolved", intent, reason: "timeout" }));
    const first = polite();
    await show(view({ phase: "checking_status", intent, handoffIntent: null }));
    assert.notEqual(polite(), first);
    assert.ok(polite().includes("Έλεγχος καταχώρησης"));
  });

  it("a stale success after a lock is never announced", async () => {
    await show(view({ phase: "blocked_authorization", reason: "expired" }));
    const alertText = assertive();
    assert.ok(alertText.length > 0);
    await show(view({ phase: "recorded_now", intent, record, prepareAcknowledged: false }));
    assert.equal(polite(), "", "no polite success announcement");
    assert.equal(assertive(), alertText, "the alert still describes the block, not a success");
    assert.doesNotMatch(polite() + assertive(), /Η καταχώρηση έγινε|Ετοιμάστε/);
  });

  it("announcements and the visible screen contain no ids, hashes or raw errors", async () => {
    const forbidden = [new RegExp(RID), new RegExp(HID), new RegExp(MEMBER_A), /[0-9a-f]{16,}/i, /mem_|nonce|token|Error|Exception|undefined|null/i];
    for (const state of allRecoveryStates()) {
      await dom.reset();
      await show(view(state));
      const text = dom.stage.textContent ?? "";
      const html = dom.stage.innerHTML;
      for (const re of forbidden) {
        assert.doesNotMatch(text, re, `${view(state).caseKey}: ${re}`);
        assert.doesNotMatch(html.replace(/data-case="[a-z_:]+"/g, ""), re, `${view(state).caseKey}: markup ${re}`);
      }
    }
  });
});

describe("UI-04N RecoveryScreen: prepare, session lock, manager review", () => {
  const PREPARE = /Ετοιμάστε/;

  it("the prepare instruction appears for a fresh commit only: never after acknowledgement, reload or replay", async () => {
    for (const state of allRecoveryStates()) {
      const v = view(state);
      await dom.reset();
      await show(v);
      const shown = PREPARE.test(dom.stage.textContent ?? "");
      assert.equal(shown, v.caseKey === "recorded_now:prepare", v.caseKey);
      assert.equal(q(".evc-rs__body--prepare") !== null, v.prepareAllowed, v.caseKey);
    }
  });

  it("re-rendering a fresh commit keeps exactly one prepare prompt; moving on removes it for good", async () => {
    const fresh: MachineState = { phase: "recorded_now", intent, record, prepareAcknowledged: false };
    await show(view(fresh));
    await show(view(fresh));
    assert.equal(qa(".evc-rs__body--prepare").length, 1);
    assert.equal((dom.stage.textContent?.match(/Ετοιμάστε/g) ?? []).length, 1);
    await show(view({ phase: "recorded_now", intent, record, prepareAcknowledged: true }));
    assert.equal(PREPARE.test(dom.stage.textContent ?? ""), false);
    await show(view({ phase: "previously_recorded_unconfirmed", intent, record, manuallyVerified: false })); // reload
    assert.equal(PREPARE.test(dom.stage.textContent ?? ""), false);
  });

  it("a session block offers only re-authentication and announces it as an alert", async () => {
    for (const reason of ["unauthorized", "forbidden", "locked", "logged_out", "expired"] as const) {
      await dom.reset();
      await show(view({ phase: "blocked_authorization", reason }));
      assert.deepEqual(qa("button").map((b) => b.dataset.action), ["reauthenticate"], reason);
      assert.equal(qa("button")[0].textContent, ACTION_LABELS.reauthenticate);
      assert.notEqual(assertive(), "", reason);
      assert.equal(polite(), "", reason);
      assert.equal(q(".evc-rs__body--prepare"), null);
    }
  });

  it("manager review: required panel for integrity blocks, secondary panel when recommended, none otherwise", async () => {
    await show(view({ phase: "blocked_integrity", redemptionRequestId: RID }));
    assert.equal(q("aside")?.classList.contains("evc-rs__escalation--primary"), true);
    assert.equal(q("aside h3")?.textContent, ACTION_LABELS.manager_review);
    assert.deepEqual(qa("button").map((b) => b.dataset.action), ["manager_review"]);
    assert.equal(qa("button")[0].classList.contains("evc-rs__btn--text"), true, "quiet text action");

    await dom.reset();
    await show(view({ phase: "recovery_required", intent, handoffIntent: null, reason: "storage_unavailable" }));
    assert.equal(q("aside")?.classList.contains("evc-rs__escalation--secondary"), true);
    const actions = qa("button").map((b) => b.dataset.action);
    assert.equal(actions[actions.length - 1], "manager_review", "manager review is last in keyboard order");

    await dom.reset();
    await show(view({ phase: "handoff_confirmed", intent, record, handoff, via: "confirmed" }));
    assert.equal(q("aside"), null);
    assert.equal(qa("button").some((b) => b.dataset.action === "manager_review"), false);
  });

  it("the busy state is marked busy, shows no control and carries the wait instruction", async () => {
    await show(view({ phase: "submitting_redemption", intent }));
    assert.equal(q("section")?.getAttribute("aria-busy"), "true");
    assert.equal(qa("button").length, 0);
    assert.equal(q('[role="group"]'), null, "no empty actions group while there is nothing to press");
    assert.ok(q(".evc-rs__busy")?.textContent?.includes("Μην πατήσετε ξανά"));
    assert.equal(q(".evc-rs__spinner")?.getAttribute("aria-hidden"), "true");
  });

  it("handoff statement recorded is shown as a staff statement, not as proof", async () => {
    await show(view({ phase: "handoff_confirmed", intent, record, handoff, via: "confirmed" }));
    assert.match(dom.stage.textContent ?? "", /δήλωση του προσωπικού|Δεν αποτελεί ανεξάρτητη απόδειξη/);
    assert.equal(handoffIntent.claim, "handed_now");
  });
});

describe("UI-04N preview scenarios", () => {
  it("every preview scenario renders through the real component with fake, id-free output", async () => {
    assert.ok(PREVIEW_SCENARIOS.length >= 9);
    const ids = new Set<string>();
    for (const s of PREVIEW_SCENARIOS) {
      assert.equal(ids.has(s.id), false);
      ids.add(s.id);
      await dom.reset();
      await show(view(s.state));
      assert.ok(q("h2")?.textContent);
      assert.doesNotMatch(dom.stage.innerHTML.replace(/data-case="[a-z_:]+"/g, ""), /mem_|[0-9a-f]{8}-[0-9a-f]{4}/);
    }
    assert.deepEqual(dom.network, { fetch: [], xhr: [], beacon: [], websocket: [] });
  });
});
