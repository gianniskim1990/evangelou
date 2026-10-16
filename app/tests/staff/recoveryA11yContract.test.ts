import assert from "node:assert/strict";
import { describe, it } from "node:test";
import {
  TONE_ICON,
  TOUCH_TARGET,
  UNCERTAIN_MARKER,
  announcementFor,
  focusFor,
  screenContract,
  type RecoveryScreenContract,
} from "../../src/staff/redemptionRecovery/a11yContract";
import { availableActions, shouldOfferPrepare, type MachineState } from "../../src/staff/redemptionRecovery/machine";
import { ACTION_LABELS, describeRecovery, type RecoveryView } from "../../src/staff/redemptionRecovery/viewModel";
import { ESPRESSO, FakeServer, MEMBER_A, TabStorage, commitThenLose, tablet } from "./support/recoveryFixtures";
import { HID, RID, allRecoveryStates, handoff, handoffIntent, intent, record } from "./support/recoveryStates";

/*
 * UI-04M — accessible presentation contract for the future Staff Recovery
 * Screen. These tests prove the CONTRACT only (structure, order, intentions,
 * announcements). They do not prove WCAG conformance: contrast, visible
 * focus, target spacing and real screen-reader output must be tested after
 * UI integration on the counter tablet.
 */

const view = (s: MachineState): RecoveryView => describeRecovery(s);
const contract = (s: MachineState): RecoveryScreenContract => screenContract(view(s));
const family = (caseKey: string): string => caseKey.split(":")[0];

/** Every family the task names, with one representative state each. */
const FAMILIES: Record<string, MachineState> = {
  waiting: { phase: "submitting_redemption", intent },
  persistence_blocked: { phase: "persistence_blocked", operation: "redemption", intent, mayHaveBeenSent: true },
  storage_unavailable: { phase: "recovery_required", intent, handoffIntent: null, reason: "storage_unavailable" },
  storage_corrupt: { phase: "recovery_required", intent: null, handoffIntent: null, reason: "storage_corrupt" },
  storage_outdated: { phase: "recovery_required", intent: null, handoffIntent: null, reason: "storage_outdated" },
  session_changed: { phase: "recovery_required", intent, handoffIntent: null, reason: "session_changed" },
  unauthorized: { phase: "blocked_authorization", reason: "unauthorized" },
  forbidden: { phase: "blocked_authorization", reason: "forbidden" },
  redemption_unresolved: { phase: "redemption_unresolved", intent, reason: "outcome_unknown" },
  handoff_unresolved: { phase: "handoff_unresolved", intent, record, handoffIntent, reason: "timeout" },
  previously_recorded: { phase: "previously_recorded_unconfirmed", intent, record, manuallyVerified: false },
  previous_business_date: { phase: "previous_business_date", intent, record, handoff: null },
  fresh_confirmed: { phase: "recorded_now", intent, record, prepareAcknowledged: false },
  handoff_confirmed: { phase: "handoff_confirmed", intent, record, handoff, via: "confirmed" },
  integrity_blocked: { phase: "blocked_integrity", redemptionRequestId: RID },
};

describe("UI-04M contract: actions come only from the view model", () => {
  it("every case: controls are exactly view.actions = availableActions(state), in order, with labels", () => {
    const states = allRecoveryStates();
    assert.ok(states.length >= 60);
    for (const state of states) {
      const v = view(state);
      const c = screenContract(v);
      assert.deepEqual(c.actions.map((a) => a.action), availableActions(state), v.caseKey);
      assert.deepEqual(c.actions.map((a) => a.action), v.actions.map((a) => a.action), v.caseKey);
      c.actions.forEach((a, i) => {
        assert.equal(a.order, i, `${v.caseKey}: keyboard order follows the machine`);
        assert.equal(a.label, ACTION_LABELS[a.action]);
        assert.equal(a.label, v.actions[i].label);
      });
    }
  });

  it("variant mirrors emphasis: one filled primary at most, manager review always a quiet text action and last", () => {
    for (const state of allRecoveryStates()) {
      const c = contract(state);
      assert.ok(c.actions.filter((a) => a.variant === "filled").length <= 1, c.caseKey);
      for (const a of c.actions) {
        if (a.action === "manager_review") {
          assert.equal(a.variant, "text", c.caseKey);
          assert.equal(a.order, c.actions.length - 1, `${c.caseKey}: manager review is last in keyboard order`);
        }
      }
      if (c.actions.length > 0 && c.actions[0].action !== "manager_review") assert.equal(c.actions[0].variant, "filled", c.caseKey);
    }
  });

  it("no unauthorized action after a lock or block: only re-authentication", () => {
    for (const reason of ["unauthorized", "forbidden", "locked", "logged_out", "expired"] as const) {
      const c = contract({ phase: "blocked_authorization", reason });
      assert.deepEqual(c.actions.map((a) => a.action), ["reauthenticate"], reason);
      assert.equal(c.showPrepare, false);
    }
    assert.deepEqual(contract({ phase: "blocked_integrity", redemptionRequestId: RID }).actions.map((a) => a.action), ["manager_review"]);
  });

  it("uncertain outcomes offer no new redemption, no prepare and no handoff statement", () => {
    for (const state of allRecoveryStates()) {
      const v = view(state);
      if (v.certainty !== "uncertain") continue;
      const c = screenContract(v);
      const actions = c.actions.map((a) => a.action);
      assert.ok(!actions.includes("start_redemption"), v.caseKey);
      assert.ok(!actions.includes("acknowledge_prepare"), v.caseKey);
      assert.equal(c.showPrepare, false, v.caseKey);
      if (v.redemption === "unknown") {
        assert.ok(!actions.includes("confirm_handed_now") && !actions.includes("confirm_handed_earlier"), `${v.caseKey}: no invented handoff`);
      }
    }
  });

  it("prepare is shown exactly for a fresh commit's single prompt", () => {
    for (const state of allRecoveryStates()) {
      assert.equal(contract(state).showPrepare, shouldOfferPrepare(state), view(state).caseKey);
    }
    assert.equal(contract({ phase: "recorded_now", intent, record, prepareAcknowledged: false }).showPrepare, true);
    assert.equal(contract({ phase: "recorded_now", intent, record, prepareAcknowledged: true }).showPrepare, false);
    assert.equal(contract({ phase: "previously_recorded_unconfirmed", intent, record, manuallyVerified: false }).showPrepare, false);
  });
});

describe("UI-04M contract: structure, busy state, tablet targets", () => {
  it("every case: labelled section with a level-2 heading equal to the headline", () => {
    for (const state of allRecoveryStates()) {
      const v = view(state);
      const c = screenContract(v);
      assert.deepEqual(c.region, { element: "section", labelledBy: "headline", headingLevel: 2 });
      assert.equal(c.heading, v.headline);
      assert.deepEqual(c.body, v.body);
    }
  });

  it("busy states lock input and expose no control", () => {
    for (const state of allRecoveryStates()) {
      const v = view(state);
      const c = screenContract(v);
      assert.equal(c.busy, v.waiting, v.caseKey);
      assert.equal(c.inputLocked, v.waiting, v.caseKey);
      if (c.busy) assert.equal(c.actions.length, 0, `${v.caseKey}: no control while a request is in flight`);
    }
    assert.equal(contract(FAMILIES.waiting).busy, true);
  });

  it("every control meets the touch-target contract (>= 44, preferred 48 CSS px)", () => {
    assert.ok(TOUCH_TARGET.minimumCssPx >= 44);
    assert.ok(TOUCH_TARGET.preferredCssPx >= 48);
    assert.ok(TOUCH_TARGET.minimumGapCssPx >= 8);
    for (const state of allRecoveryStates()) {
      for (const a of contract(state).actions) assert.ok(a.minTargetCssPx >= TOUCH_TARGET.preferredCssPx, a.action);
    }
  });

  it("meaning is never colour alone: every tone has an icon; uncertain settled outcomes carry a text marker", () => {
    for (const state of allRecoveryStates()) {
      const v = view(state);
      const c = screenContract(v);
      assert.equal(c.icon, TONE_ICON[v.tone], v.caseKey);
      assert.equal(c.uncertainMarker, v.certainty === "uncertain" && !v.waiting ? UNCERTAIN_MARKER : null, v.caseKey);
      if (v.tone === "positive") assert.equal(c.uncertainMarker, null, `${v.caseKey}: a confirmed state never looks uncertain`);
    }
    assert.equal(new Set(Object.values(TONE_ICON)).size, Object.keys(TONE_ICON).length, "icons are distinct per tone");
    assert.notEqual(contract(FAMILIES.redemption_unresolved).icon, contract(FAMILIES.fresh_confirmed).icon);
  });

  it("escalation: hidden, secondary panel or primary panel exactly as the view's level says", () => {
    for (const state of allRecoveryStates()) {
      const v = view(state);
      const c = screenContract(v);
      const expected = v.escalation.level === "none" ? "hidden" : v.escalation.level === "required" ? "panel_primary" : "panel_secondary";
      assert.equal(c.escalation.presentation, expected, v.caseKey);
      assert.equal(c.escalation.heading, expected === "hidden" ? null : ACTION_LABELS.manager_review);
      assert.deepEqual(c.escalation.steps, v.escalation.steps);
    }
    assert.equal(contract(FAMILIES.integrity_blocked).escalation.presentation, "panel_primary");
    assert.equal(contract(FAMILIES.storage_unavailable).escalation.presentation, "panel_secondary");
  });

  it("labels are accessible names: Greek, unique per screen, no symbols-only text", () => {
    for (const state of allRecoveryStates()) {
      const c = contract(state);
      const labels = c.actions.map((a) => a.label);
      assert.equal(new Set(labels).size, labels.length, c.caseKey);
      for (const l of labels) {
        assert.match(l, /[Α-Ωα-ωάέήίόύώ]{3,}/, l);
        assert.equal(l.trim(), l);
        assert.ok(l.length <= 40, `${l}: short enough for a counter button`);
      }
    }
  });
});

describe("UI-04M announcements", () => {
  it("priority by family: idle silent, urgent blocks assertive, everything else polite", () => {
    const expected: Record<string, string> = {
      waiting: "polite", persistence_blocked: "polite", storage_unavailable: "polite", storage_corrupt: "polite", storage_outdated: "polite",
      session_changed: "polite", unauthorized: "assertive", forbidden: "assertive", redemption_unresolved: "polite", handoff_unresolved: "polite",
      previously_recorded: "polite", previous_business_date: "polite", fresh_confirmed: "polite", handoff_confirmed: "polite", integrity_blocked: "assertive",
    };
    for (const [name, state] of Object.entries(FAMILIES)) {
      assert.equal(contract(state).priority, expected[name], name);
      const a = announcementFor(null, view(state));
      assert.ok(a !== null, name);
      assert.equal(a.priority, expected[name], name);
      assert.equal(a.role, expected[name] === "assertive" ? "alert" : "status", name);
      assert.equal(a.ariaLive, expected[name], name);
    }
    assert.equal(contract({ phase: "idle" }).priority, "none");
    assert.equal(announcementFor(null, view({ phase: "idle" })), null);
  });

  it("assertive alerts are reserved for authorization and integrity blocks", () => {
    for (const state of allRecoveryStates()) {
      const c = contract(state);
      const urgent = c.caseKey.startsWith("blocked_authorization:") || c.caseKey === "blocked_integrity";
      assert.equal(c.priority === "assertive", urgent, c.caseKey);
    }
  });

  it("an unknown outcome is announced as unconfirmed, never as success or failure", () => {
    const OUTCOME = /επιτυχ|ολοκληρώθηκε|απέτυχε|αποτυχ|έγινε η καταχώρηση|δεν έγινε|καταχωρήθηκε/i;
    for (const state of allRecoveryStates()) {
      const v = view(state);
      if (v.certainty !== "uncertain") continue;
      const a = announcementFor(null, v);
      assert.ok(a !== null, v.caseKey);
      assert.doesNotMatch(a.text, OUTCOME, v.caseKey);
      if (!v.waiting) assert.ok(a.text.startsWith(UNCERTAIN_MARKER), `${v.caseKey}: "${a.text}"`);
      const urgentBlock = v.caseKey.startsWith("blocked_authorization:") || v.caseKey === "blocked_integrity";
      assert.equal(a.priority, urgentBlock ? "assertive" : "polite", `${v.caseKey}: an unknown transaction outcome is not an alarm`);
    }
  });

  it("no repeated announcement without a meaningful transition (same case re-rendered or polled)", () => {
    for (const state of allRecoveryStates()) {
      const v = view(state);
      assert.equal(announcementFor(v, view(state)), null, v.caseKey);
    }
    // A real transition is announced again.
    const unresolved = view(FAMILIES.redemption_unresolved);
    const checking = view({ phase: "checking_status", intent, handoffIntent: null });
    assert.ok(announcementFor(unresolved, checking) !== null);
    assert.ok(announcementFor(checking, unresolved) !== null);
  });

  it("a success right after a lock or revocation is never announced", () => {
    for (const reason of ["unauthorized", "forbidden", "locked", "logged_out", "expired"] as const) {
      const blocked = view({ phase: "blocked_authorization", reason });
      for (const next of [FAMILIES.fresh_confirmed, FAMILIES.handoff_confirmed]) {
        assert.equal(announcementFor(blocked, view(next)), null, `${reason} -> ${view(next).caseKey}`);
        assert.deepEqual(focusFor(blocked, view(next), "reauthenticate"), { kind: "heading" });
      }
      // The legitimate path (block -> recovery) is still announced.
      assert.ok(announcementFor(blocked, view(FAMILIES.session_changed)) !== null);
    }
  });

  it("announcements contain only static copy: no ids, numbers, tokens or raw errors", () => {
    const forbidden = [/\d/, new RegExp(RID), new RegExp(HID), new RegExp(MEMBER_A), /[0-9a-f]{12,}/i, /mem_|nonce|token|hash|Error|Exception|null|undefined/i];
    for (const state of allRecoveryStates()) {
      const a = announcementFor(null, view(state));
      if (a === null) continue;
      for (const re of forbidden) assert.doesNotMatch(a.text, re, `${view(state).caseKey}: ${re}`);
      assert.ok(a.text.includes(view(state).headline.replace(/…$/, "")), view(state).caseKey);
    }
  });
});

describe("UI-04M focus intentions", () => {
  it("opening the screen (or returning from escalation): heading, or the busy status while waiting — never a button", () => {
    for (const state of allRecoveryStates()) {
      const v = view(state);
      assert.deepEqual(focusFor(null, v, null), v.waiting ? { kind: "status" } : { kind: "heading" }, v.caseKey);
    }
  });

  it("every meaningful transition moves focus to the heading/status, so a stray Enter cannot send anything", () => {
    const transitions: [MachineState, MachineState][] = [
      [FAMILIES.waiting, FAMILIES.redemption_unresolved], // unknown server outcome
      [FAMILIES.waiting, FAMILIES.fresh_confirmed], // confirmed redemption
      [{ phase: "handoff_confirmation_pending", intent, record, handoffIntent }, FAMILIES.handoff_confirmed],
      [FAMILIES.redemption_unresolved, { phase: "persistence_blocked", operation: "redemption", intent, mayHaveBeenSent: true }],
      [{ phase: "idle" }, FAMILIES.storage_unavailable],
      [FAMILIES.fresh_confirmed, FAMILIES.session_changed],
      [FAMILIES.handoff_unresolved, FAMILIES.unauthorized],
      [FAMILIES.previously_recorded, FAMILIES.forbidden],
      [FAMILIES.storage_unavailable, FAMILIES.integrity_blocked],
      [FAMILIES.redemption_unresolved, FAMILIES.waiting],
    ];
    for (const [from, to] of transitions) {
      const prev = view(from);
      const next = view(to);
      const prevFocus = prev.actions[0]?.action ?? null;
      const intent = focusFor(prev, next, prevFocus);
      assert.deepEqual(intent, next.waiting ? { kind: "status" } : { kind: "heading" }, `${prev.caseKey} -> ${next.caseKey}`);
    }
  });

  it("re-rendering or polling the same case keeps focus (no focus stealing)", () => {
    for (const state of allRecoveryStates()) {
      const v = view(state);
      assert.deepEqual(focusFor(v, view(state), null), { kind: "keep" }, v.caseKey);
      for (const a of v.actions) assert.deepEqual(focusFor(v, view(state), a.action), { kind: "keep" }, `${v.caseKey}/${a.action}`);
    }
  });

  it("if the focused action disappears, focus falls back to the heading, never to a hidden control", () => {
    const before = view({ phase: "previously_recorded_unconfirmed", intent, record, manuallyVerified: false });
    const after = view({ phase: "previously_recorded_unconfirmed", intent, record, manuallyVerified: true });
    // Different case keys -> heading.
    assert.deepEqual(focusFor(before, after, "confirm_manual_verification"), { kind: "heading" });
    // Same case key but a stale focused action that is not offered -> heading.
    const same = view(FAMILIES.redemption_unresolved);
    assert.deepEqual(focusFor(same, view(FAMILIES.redemption_unresolved), "start_redemption"), { kind: "heading" });
    assert.deepEqual(focusFor(same, view(FAMILIES.redemption_unresolved), "retry_redemption"), { kind: "keep" });
  });

  it("focus intentions never name a control: the contract has no 'focus this button' result", () => {
    for (const state of allRecoveryStates()) {
      for (const prev of [null, view(state), view({ phase: "idle" })]) {
        const f = focusFor(prev, view(state), null);
        assert.ok(["keep", "heading", "status"].includes(f.kind));
        assert.deepEqual(Object.keys(f), ["kind"]);
      }
    }
  });
});

describe("UI-04M against the REAL machine", () => {
  it("fresh commit -> prepare once; reload -> no prepare, polite announcement, heading focus", async () => {
    const server = new FakeServer();
    const first = tablet(server);
    await first.machine.startRedemption(MEMBER_A, ESPRESSO);
    const fresh = view(first.machine.getState());
    const c = screenContract(fresh);
    assert.equal(c.showPrepare, true);
    assert.deepEqual(c.actions.map((a) => a.action), ["acknowledge_prepare"]);
    assert.deepEqual(focusFor(null, fresh, null), { kind: "heading" }, "the prepare button is not auto-focused");

    const reloaded = tablet(server, { storage: first.storage });
    await reloaded.machine.restore();
    const replay = view(reloaded.machine.getState());
    assert.equal(screenContract(replay).showPrepare, false);
    assert.ok(!screenContract(replay).actions.some((a) => a.action === "acknowledge_prepare"));
    assert.equal(announcementFor(null, replay)?.priority, "polite");
  });

  it("an unknown outcome and a persistence block are announced politely and never as success", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const unknown = view(t.machine.getState());
    const a = announcementFor(null, unknown);
    assert.equal(a?.role, "status");
    assert.ok(a?.text.startsWith(UNCERTAIN_MARKER));
    assert.equal(screenContract(unknown).uncertainMarker, UNCERTAIN_MARKER);

    const storage = new TabStorage();
    storage.failWrites = true;
    const b = tablet(new FakeServer(), { storage });
    await b.machine.startRedemption(MEMBER_A, ESPRESSO);
    const blocked = screenContract(view(b.machine.getState()));
    assert.equal(blocked.caseKey, "persistence_blocked:redemption:not_sent");
    assert.equal(blocked.priority, "polite");
    assert.deepEqual(blocked.actions.map((x) => x.action), availableActions(b.machine.getState()));
  });
});

describe("UI-04M coverage of the requested case families", () => {
  it("every named family maps to the expected case-key family", () => {
    const expectedFamily: Record<string, string> = {
      waiting: "submitting_redemption", persistence_blocked: "persistence_blocked", storage_unavailable: "recovery_required",
      storage_corrupt: "recovery_required", storage_outdated: "recovery_required", session_changed: "recovery_required",
      unauthorized: "blocked_authorization", forbidden: "blocked_authorization", redemption_unresolved: "redemption_unresolved",
      handoff_unresolved: "handoff_unresolved", previously_recorded: "previously_recorded_unconfirmed",
      previous_business_date: "previous_business_date", fresh_confirmed: "recorded_now", handoff_confirmed: "handoff_confirmed",
      integrity_blocked: "blocked_integrity",
    };
    for (const [name, state] of Object.entries(FAMILIES)) assert.equal(family(view(state).caseKey), expectedFamily[name], name);
  });
});
