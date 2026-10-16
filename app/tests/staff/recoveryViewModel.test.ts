import assert from "node:assert/strict";
import { describe, it } from "node:test";
import {
  availableActions,
  shouldOfferPrepare,
  type Action,
  type BlockReason,
  type HandoffIntent,
  type MachineState,
  type RecoveryReason,
  type RedemptionIntent,
  type UnresolvedReason,
} from "../../src/staff/redemptionRecovery/machine";
import { ACTION_LABELS, ESCALATION_STEPS, describeRecovery, recoveryCaseKey, type RecoveryView } from "../../src/staff/redemptionRecovery/viewModel";
import type { HandoffRefusal, HandoffView, RecordedRedemption, RedemptionRefusal } from "../../src/staff/redemptionRecovery/wire";
import { ESPRESSO, FakeServer, MEMBER_A, TabStorage, commitThenLose, neverPrepares, tablet } from "./support/recoveryFixtures";

/*
 * UI-04L — exhaustive tests of the presentation contract. Every MachineState
 * phase, block reason, recovery reason, refusal code and handoff variant is
 * enumerated below; adding a new one without copy is a compile error (the
 * Record<...> tables) and a failing test (the case-key count).
 */

const RID = "3fa85f64-5717-4562-b3fc-2c963f66afa6";
const HID = "7c9e6679-7425-40de-944b-e07fc1f90ae7";
const HASH = "ab".repeat(32);
const intent: RedemptionIntent = { memberId: MEMBER_A, requestId: RID, payloadSha256: HASH };
const handoffIntent: HandoffIntent = { handoffRequestId: HID, claim: "handed_now" };
const record: RecordedRedemption = { requestId: RID, memberId: MEMBER_A, businessDate: "2026-10-14", redeemedAtUtc: "2026-10-14 07:30:00.000000" };
const handoff: HandoffView = {
  handoffRequestId: HID,
  redemptionRequestId: RID,
  claim: "handed_now",
  confirmationRecordedAtUtc: "2026-10-14 07:35:00.000000",
  handoffAtUtc: "2026-10-14 07:35:00.000000",
  handoffTime: "stated_at_recording",
};

const REDEMPTION_CODES: Record<RedemptionRefusal, true> = {
  invalid_request: true, invalid_preparation: true, invalid_coffee: true, catalogue_version_stale: true, catalogue_not_authorised: true,
  member_not_found: true, membership_inactive: true, benefit_already_redeemed: true, idempotency_key_reused: true,
};
const HANDOFF_CODES: Record<HandoffRefusal, true> = { invalid_request: true, redemption_not_found: true, late_confirmation_not_authorised: true, idempotency_key_reused: true };
const BLOCK_REASONS: Record<BlockReason, true> = { unauthorized: true, forbidden: true, locked: true, logged_out: true, expired: true };
const RECOVERY_REASONS: Record<RecoveryReason, true> = {
  not_recorded: true, redemption_missing: true, session_changed: true, storage_corrupt: true, storage_outdated: true, storage_unavailable: true, status_unavailable: true,
};
const UNRESOLVED: Record<UnresolvedReason, true> = {
  outcome_unknown: true, server_error: true, network: true, timeout: true, unavailable: true, rate_limited: true, invalid_response: true,
  unexpected_error: true, status_unavailable: true, status_unconfirmed: true,
};

/** One MachineState for EVERY phase and every reason/code/variant. */
function allStates(): MachineState[] {
  const s: MachineState[] = [
    { phase: "idle" },
    { phase: "submitting_redemption", intent },
    { phase: "checking_status", intent, handoffIntent: null },
    { phase: "checking_status", intent, handoffIntent },
    { phase: "recorded_now", intent, record, prepareAcknowledged: false },
    { phase: "recorded_now", intent, record, prepareAcknowledged: true },
    { phase: "previously_recorded_unconfirmed", intent, record, manuallyVerified: false },
    { phase: "previously_recorded_unconfirmed", intent, record, manuallyVerified: true },
    { phase: "previously_recorded_confirmed", intent, record, handoff },
    { phase: "previous_business_date", intent, record, handoff: null },
    { phase: "previous_business_date", intent, record, handoff },
    { phase: "handoff_confirmation_pending", intent, record, handoffIntent },
    { phase: "blocked_integrity", redemptionRequestId: RID },
    { phase: "blocked_integrity", redemptionRequestId: null },
    { phase: "persistence_blocked", operation: "redemption", intent, mayHaveBeenSent: false },
    { phase: "persistence_blocked", operation: "redemption", intent, mayHaveBeenSent: true },
    { phase: "persistence_blocked", operation: "handoff", intent, record, handoffIntent, mayHaveBeenSent: false },
    { phase: "persistence_blocked", operation: "handoff", intent, record, handoffIntent, mayHaveBeenSent: true },
  ];
  for (const reason of Object.keys(UNRESOLVED) as UnresolvedReason[]) {
    s.push({ phase: "redemption_unresolved", intent, reason });
    s.push({ phase: "handoff_unresolved", intent, record, handoffIntent, reason });
  }
  for (const code of Object.keys(REDEMPTION_CODES) as RedemptionRefusal[]) s.push({ phase: "redemption_refused", intent, code });
  for (const code of Object.keys(HANDOFF_CODES) as HandoffRefusal[]) s.push({ phase: "handoff_refused", intent, record, code });
  for (const via of ["confirmed", "replayed", "already_confirmed", "status"] as const) s.push({ phase: "handoff_confirmed", intent, record, handoff, via });
  for (const reason of Object.keys(BLOCK_REASONS) as BlockReason[]) s.push({ phase: "blocked_authorization", reason });
  for (const reason of Object.keys(RECOVERY_REASONS) as RecoveryReason[]) {
    s.push({ phase: "recovery_required", intent, handoffIntent: null, reason });
    s.push({ phase: "recovery_required", intent: null, handoffIntent: null, reason });
  }
  return s;
}

const GREEK = /[Α-Ωα-ωάέήίόύώΐΰϊϋ]/;
/** Words that claim success or failure of the transaction. */
const OUTCOME_WORDS = /επιτυχ|ολοκληρώθηκε|καταχωρήθηκε|απέτυχε|αποτυχ|έγινε η καταχώρηση|δεν έγινε/i;

function textOf(v: RecoveryView): string[] {
  return [v.headline, ...v.body, ...v.actions.map((a) => a.label), ...v.escalation.steps];
}

describe("UI-04L recovery view model: completeness and authority", () => {
  it("covers every phase, reason, code and variant with distinct, non-empty Greek copy", () => {
    const states = allStates();
    const keys = new Set<string>();
    for (const state of states) {
      const v = describeRecovery(state);
      keys.add(v.caseKey);
      assert.equal(v.caseKey, recoveryCaseKey(state));
      assert.ok(GREEK.test(v.headline) && v.headline.trim() === v.headline && v.headline.length > 3, v.caseKey);
      assert.ok(v.body.length >= 1 && v.body.every((l) => GREEK.test(l) && l.length > 8), v.caseKey);
      assert.ok(["neutral", "positive", "attention", "warning", "blocked"].includes(v.tone));
    }
    // 17 phase groups: idle, submitting, unresolved, checking, 2 recorded_now, 2 unconfirmed, confirmed, previous date,
    // 9 refusals, pending, handoff_unresolved, 4 handoff_confirmed, 4 handoff refusals, integrity, 5 blocks, 7 recoveries, 4 persistence.
    assert.equal(keys.size, 46);
  });

  it("actions are EXACTLY availableActions(state), in the machine's order, with no invented action", () => {
    for (const state of allStates()) {
      const v = describeRecovery(state);
      assert.deepEqual(v.actions.map((a) => a.action), availableActions(state), v.caseKey);
      for (const a of v.actions) assert.equal(a.label, ACTION_LABELS[a.action]);
    }
  });

  it("has one unique, non-empty Greek label per machine action", () => {
    const seen = new Set<string>();
    const all = Object.keys(ACTION_LABELS) as Action[];
    assert.equal(all.length, 16);
    for (const a of all) {
      const label = ACTION_LABELS[a];
      assert.ok(GREEK.test(label) && label.length > 3 && label.length <= 40, a);
      assert.ok(!seen.has(label), `duplicate label ${label}`);
      seen.add(label);
    }
    // Every action the machine can offer in any state has a label.
    for (const state of allStates()) for (const a of availableActions(state)) assert.ok(ACTION_LABELS[a], a);
    // The labels requested by the owner.
    assert.equal(ACTION_LABELS.retry_persistence, "Δοκιμή καταχώρησης ξανά");
    assert.equal(ACTION_LABELS.check_status, "Έλεγχος καταχώρησης");
    assert.equal(ACTION_LABELS.retry_restore, "Επανάληψη ανάκτησης");
    assert.equal(ACTION_LABELS.manager_review, "Ενημέρωση υπευθύνου");
    assert.equal(ACTION_LABELS.resume_recovery, "Ανάκτηση συναλλαγής");
  });

  it("emphasis: the first non-manager action is primary, manager review is always quiet", () => {
    for (const state of allStates()) {
      const v = describeRecovery(state);
      const loud = v.actions.filter((a) => a.action !== "manager_review");
      assert.deepEqual(v.actions.filter((a) => a.emphasis === "primary").map((a) => a.action), loud.length ? [loud[0].action] : [], v.caseKey);
      assert.ok(v.actions.filter((a) => a.action === "manager_review").every((a) => a.emphasis === "quiet"));
      assert.ok(loud.slice(1).every((a) => a.emphasis === "secondary"));
    }
  });

  it("escalation is derived from the machine's manager_review action and never invents authority", () => {
    for (const state of allStates()) {
      const v = describeRecovery(state);
      const offered = availableActions(state);
      assert.equal(v.escalation.level !== "none", offered.includes("manager_review"), v.caseKey);
      assert.equal(v.escalation.level === "required", offered.length === 1 && offered[0] === "manager_review", v.caseKey);
      assert.deepEqual(v.escalation.steps, v.escalation.level === "none" ? [] : ESCALATION_STEPS);
    }
    assert.equal(describeRecovery({ phase: "blocked_integrity", redemptionRequestId: RID }).escalation.level, "required");
    // The guidance promises nothing the shared staff role could do: no override, refund, new coffee or handoff.
    const steps = ESCALATION_STEPS.join(" ");
    assert.match(steps, /δεν δημιουργείται νέος καφές, παράκαμψη, επιστροφή ή δήλωση παράδοσης/);
    for (const label of Object.values(ACTION_LABELS)) assert.doesNotMatch(label, /παράκαμψη|επιστροφή|ακύρωση πληρωμής|επιστροφή χρημάτων/);
  });

  it("is pure and immutable: same input, same output, input untouched, outputs frozen", () => {
    for (const state of allStates()) {
      const before = JSON.stringify(state);
      const a = describeRecovery(state);
      const b = describeRecovery(state);
      assert.deepEqual(a, b);
      assert.equal(JSON.stringify(state), before);
      assert.ok(Object.isFrozen(a) && Object.isFrozen(a.body) && Object.isFrozen(a.actions) && Object.isFrozen(a.escalation));
      assert.throws(() => (a.body as string[]).push("x"));
    }
  });
});

describe("UI-04L safety of the messages", () => {
  it("never allows or words 'prepare' except for a fresh commit's single prompt", () => {
    for (const state of allStates()) {
      const v = describeRecovery(state);
      const fresh = state.phase === "recorded_now" && !state.prepareAcknowledged;
      assert.equal(v.prepareAllowed, shouldOfferPrepare(state), v.caseKey);
      assert.equal(v.prepareAllowed, fresh, v.caseKey);
      assert.equal(availableActions(state).includes("acknowledge_prepare"), fresh);
      assert.equal(/Ετοιμάστε/.test(v.body.join(" ")), fresh, `${v.caseKey}: the prepare instruction`);
      // Every other state, especially replays, tells staff NOT to prepare anything new where a coffee is recorded.
      if (!fresh) assert.doesNotMatch(v.headline + v.body.join(" "), /Ετοιμάστε|ετοιμάστε/);
    }
    for (const phase of ["previously_recorded_unconfirmed", "previously_recorded_confirmed"] as const) {
      const v = describeRecovery(phase === "previously_recorded_confirmed"
        ? { phase, intent, record, handoff }
        : { phase, intent, record, manuallyVerified: false });
      assert.match(v.body.join(" "), /Μην ετοιμάσετε νέο καφέ/);
      assert.equal(v.prepareAllowed, false);
    }
  });

  it("never words an unknown outcome as success or failure", () => {
    let uncertain = 0;
    for (const state of allStates()) {
      const v = describeRecovery(state);
      if (v.certainty !== "uncertain") continue;
      uncertain++;
      assert.doesNotMatch(v.headline + " " + v.body.join(" "), OUTCOME_WORDS, v.caseKey);
      assert.doesNotMatch(v.body.join(" "), /Δεν έχει καταχωρηθεί|δεν καταχωρήθηκε/, `${v.caseKey} must not claim nothing was recorded`);
      assert.notEqual(v.tone, "positive", v.caseKey);
      assert.ok(v.redemption !== "recorded" || v.handoff === "unknown" || v.handoff === "none", v.caseKey);
    }
    assert.ok(uncertain >= 20);
    for (const state of allStates()) {
      if (state.phase === "redemption_unresolved" || state.phase === "handoff_unresolved") {
        assert.equal(describeRecovery(state).certainty, "uncertain");
      }
    }
  });

  it("keeps 'not sent' (A), 'may have reached the server' (B), 'confirmed' (C) and 'staff statement' (D) apart", () => {
    const view = (s: MachineState) => describeRecovery(s);
    const neverSent = view({ phase: "persistence_blocked", operation: "redemption", intent, mayHaveBeenSent: false });
    const maybe = view({ phase: "persistence_blocked", operation: "redemption", intent, mayHaveBeenSent: true });
    const confirmed = view({ phase: "recorded_now", intent, record, prepareAcknowledged: false });
    // A
    assert.equal(neverSent.redemption, "not_sent");
    assert.equal(neverSent.certainty, "definite");
    assert.match(neverSent.headline, /δεν στάλθηκε/);
    assert.match(neverSent.body.join(" "), /Με το συγκεκριμένο αίτημα δεν καταχωρήθηκε καφές/);
    // B
    assert.equal(maybe.redemption, "unknown");
    assert.equal(maybe.certainty, "uncertain");
    assert.match(maybe.body.join(" "), /ίσως έχει φτάσει στον διακομιστή/);
    assert.doesNotMatch(maybe.body.join(" "), /Δεν έχει καταχωρηθεί|δεν καταχωρήθηκε/);
    // C
    assert.equal(confirmed.redemption, "recorded");
    assert.match(confirmed.body.join(" "), /επιβεβαίωσε/);
    // D
    const statement = view({ phase: "previously_recorded_confirmed", intent, record, handoff });
    assert.equal(statement.handoff, "staff_statement_recorded");
    assert.notEqual(statement.handoff, ("proof" as string));
    const redemptionFacts = new Set(allStates().map((s) => view(s).redemption));
    assert.deepEqual([...redemptionFacts].sort(), ["blocked", "none", "not_recorded", "not_sent", "recorded", "refused", "unknown"]);
    // The same distinction for the handoff statement.
    const hNever = view({ phase: "persistence_blocked", operation: "handoff", intent, record, handoffIntent, mayHaveBeenSent: false });
    const hMaybe = view({ phase: "persistence_blocked", operation: "handoff", intent, record, handoffIntent, mayHaveBeenSent: true });
    assert.equal(hNever.handoff, "not_sent");
    assert.equal(hMaybe.handoff, "unknown");
    assert.equal(hNever.redemption, "recorded");
    assert.equal(hNever.certainty, "definite");
    assert.equal(hMaybe.certainty, "uncertain");
  });

  it("never presents a handoff statement or manual verification as physical proof", () => {
    for (const state of allStates()) {
      const v = describeRecovery(state);
      const all = [v.headline, ...v.body, ...v.actions.map((a) => a.label)];
      for (const line of all) {
        // Any claim that something was handed over is phrased as a statement/declaration.
        if (/παραδόθηκε|παράδοση/.test(line)) assert.match(line, /[Δδ]ήλ|[Δδ]ηλ|απόδειξη|στάλθηκε|παράδοσης/, `${v.caseKey}: ${line}`);
        assert.doesNotMatch(line, /επιβεβαιώθηκε η παράδοση|αποδεικνύει|απόδειξη ότι παραδόθηκε|παραδόθηκε επιτυχώς/);
      }
      if (v.handoff === "staff_statement_recorded") {
        assert.match(v.body.join(" "), /δεν είναι ανεξάρτητη απόδειξη|Δεν αποτελεί ανεξάρτητη απόδειξη|δήλωση του προσωπικού|Η δήλωση δεν|δεν προστέθηκε/, v.caseKey);
      }
    }
    const verified = describeRecovery({ phase: "previously_recorded_unconfirmed", intent, record, manuallyVerified: true });
    assert.match(verified.body.join(" "), /δεν αποτελεί απόδειξη παράδοσης/);
    assert.equal(verified.handoff, "none", "the server has no statement yet");
    assert.ok(verified.actions.some((a) => a.action === "confirm_handed_now"));
    // Declaring labels say "Δήλωση", never "Επιβεβαίωση παράδοσης".
    assert.match(ACTION_LABELS.confirm_handed_now, /^Δήλωση/);
    assert.match(ACTION_LABELS.confirm_handed_earlier, /^Δήλωση/);
  });

  it("a session lock or logout offers nothing but a new login and keeps pending work recoverable", () => {
    for (const reason of Object.keys(BLOCK_REASONS) as BlockReason[]) {
      const v = describeRecovery({ phase: "blocked_authorization", reason });
      assert.deepEqual(v.actions.map((a) => a.action), ["reauthenticate"], reason);
      assert.equal(v.tone, "blocked");
      assert.equal(v.certainty, "uncertain");
      assert.equal(v.prepareAllowed, false);
      if (reason !== "forbidden") assert.match(v.body.join(" "), /θα ανακτηθεί μετά τη σύνδεση. Δεν ξεκινά νέα καταχώρηση αυτόματα/);
    }
  });

  it("corrupt, outdated or unavailable storage stays fail closed", () => {
    const view = (reason: RecoveryReason) => describeRecovery({ phase: "recovery_required", intent: null, handoffIntent: null, reason });
    assert.deepEqual(view("storage_unavailable").actions.map((a) => a.action), ["retry_restore", "manager_review"]);
    assert.deepEqual(view("storage_corrupt").actions.map((a) => a.action), ["abandon_intent", "manager_review"]);
    assert.deepEqual(view("storage_outdated").actions.map((a) => a.action), ["abandon_intent", "manager_review"]);
    for (const reason of ["storage_unavailable", "storage_corrupt", "storage_outdated"] as const) {
      const v = view(reason);
      assert.equal(v.certainty, "uncertain");
      assert.equal(v.escalation.level, "recommended");
      assert.equal(v.prepareAllowed, false);
      for (const forbidden of ["start_redemption", "resubmit_same_intent", "retry_redemption", "finish", "confirm_handed_now"] as const) {
        assert.ok(!v.actions.some((a) => a.action === forbidden), `${reason} offers ${forbidden}`);
      }
      assert.match(v.body.join(" "), /Μην ξεκινήσετε νέα|ζητήστε βοήθεια πριν ξεκινήσετε νέα/);
    }
    assert.match(view("storage_corrupt").body.join(" "), /διαγράφει μόνο την τοπική εγγραφή/);
  });

  it("a persistence block is explained without technical terms and offers only the machine's actions", () => {
    const never = describeRecovery({ phase: "persistence_blocked", operation: "redemption", intent, mayHaveBeenSent: false });
    assert.deepEqual(never.actions.map((a) => a.action), ["retry_persistence", "abandon_intent"]);
    assert.equal(never.actions[0].label, "Δοκιμή καταχώρησης ξανά");
    const maybe = describeRecovery({ phase: "persistence_blocked", operation: "redemption", intent, mayHaveBeenSent: true });
    assert.deepEqual(maybe.actions.map((a) => a.action), ["retry_persistence", "check_status"]);
    assert.ok(!maybe.actions.some((a) => a.action === "abandon_intent" || a.action === "finish"), "a possibly committed request is never closed");
    const handoffNever = describeRecovery({ phase: "persistence_blocked", operation: "handoff", intent, record, handoffIntent, mayHaveBeenSent: false });
    assert.deepEqual(handoffNever.actions.map((a) => a.action), ["retry_persistence", "check_status", "finish"]);
    assert.match(handoffNever.body.join(" "), /Ο καφές είναι ήδη καταχωρημένος/);
  });

  it("waiting states offer no action and never claim an outcome", () => {
    for (const state of allStates()) {
      const v = describeRecovery(state);
      if (v.waiting) {
        assert.equal(v.actions.length, 0, v.caseKey);
        assert.equal(v.certainty, "uncertain");
        assert.match(v.body.join(" "), /Περιμένετε/);
      }
      if (v.actions.length === 0) assert.equal(v.waiting, true, `${v.caseKey} has no action and must say so`);
    }
    assert.equal(describeRecovery({ phase: "submitting_redemption", intent }).waiting, true);
  });

  it("refusals say plainly that THIS request recorded no coffee (or that today's is used) and offer only 'finish'", () => {
    for (const code of Object.keys(REDEMPTION_CODES) as RedemptionRefusal[]) {
      const v = describeRecovery({ phase: "redemption_refused", intent, code });
      assert.deepEqual(v.actions.map((a) => a.action), ["finish"]);
      assert.equal(v.redemption, "refused");
      assert.equal(v.certainty, "definite");
      assert.match(v.body.join(" "), /Με το συγκεκριμένο αίτημα δεν καταχωρήθηκε καφές|Δεν δημιουργείται/, code);
    }
  });
});

describe("UI-04L request-scoped copy: one request id never speaks for the member's day", () => {
  /** Absence / availability claims about the MEMBER or the DAY, never allowed from one request's outcome. */
  const MEMBER_DAY_CLAIMS = [
    /Δεν έχει καταχωρηθεί καφές/, // "no coffee has been recorded" (for anyone)
    /Ο διακομιστής δεν έχει καταχώρηση/, // "the server has no record" (at all)
    /δεν στάλθηκε τίποτα/, // "nothing was sent" (by any tablet)
    /Δεν υπάρχει εκκρεμότητα\b/, // "nothing pending" (anywhere)
    /δεν έχει (?:ήδη )?(?:πάρει|χρησιμοποιήσει)/i, // "has not had / used it"
    /(?:δικαιούται|μπορεί να πάρει|είναι διαθέσιμη η παροχή|η παροχή είναι διαθέσιμη)/i, // eligibility claims
  ];
  const textOf = (v: RecoveryView) => [v.headline, ...v.body].join(" ");
  const sentences = (v: RecoveryView) => textOf(v).split(/(?<=\.)\s+/);

  it("no case claims the member or the day has no coffee, or that the benefit is available", () => {
    for (const state of allStates()) {
      const v = describeRecovery(state);
      for (const claim of MEMBER_DAY_CLAIMS) assert.doesNotMatch(textOf(v), claim, `${v.caseKey}: ${claim}`);
    }
  });

  it("every 'not recorded' sentence is explicitly about THIS request", () => {
    let found = 0;
    for (const state of allStates()) {
      const v = describeRecovery(state);
      for (const sentence of sentences(v)) {
        if (/δεν καταχωρήθηκε|δεν βρέθηκε|δεν στάλθηκε/i.test(sentence) && /αίτημα|καταχώρηση για/.test(sentence)) found++;
        if (/δεν καταχωρήθηκε καφές/.test(sentence)) {
          assert.match(sentence, /συγκεκριμένο αίτημα/, `${v.caseKey}: "${sentence}"`);
        }
      }
    }
    assert.ok(found >= 9, `request-scoped sentences found: ${found}`);
  });

  it("cases that only know this request's outcome are request-scoped", () => {
    const scoped: MachineState[] = [
      ...(Object.keys(REDEMPTION_CODES) as RedemptionRefusal[])
        .filter((code) => code !== "benefit_already_redeemed" && code !== "idempotency_key_reused")
        .map((code): MachineState => ({ phase: "redemption_refused", intent, code })),
      { phase: "recovery_required", intent, reason: "not_recorded" } as MachineState,
      { phase: "persistence_blocked", operation: "redemption", intent, mayHaveBeenSent: false } as MachineState,
    ];
    for (const state of scoped) {
      const v = describeRecovery(state);
      assert.match(textOf(v), /συγκεκριμένο αίτημα/, v.caseKey);
      assert.equal(v.certainty, "definite", v.caseKey);
    }
    const notRecorded = describeRecovery({ phase: "recovery_required", intent, reason: "not_recorded" } as MachineState);
    assert.equal(notRecorded.headline, "Δεν βρέθηκε καταχώρηση για το συγκεκριμένο αίτημα");
    assert.equal(notRecorded.redemption, "not_recorded");
  });

  it("the authoritative 'already used today' answer is still stated plainly", () => {
    const v = describeRecovery({ phase: "redemption_refused", intent, code: "benefit_already_redeemed" });
    assert.equal(v.headline, "Η σημερινή παροχή έχει ήδη χρησιμοποιηθεί");
    assert.match(v.body.join(" "), /Δεν δημιουργείται δεύτερος/);
    assert.doesNotMatch(textOf(v), /συγκεκριμένο αίτημα/, "a server answer about the day is not narrowed to the request");
  });

  it("the idle screen speaks only for this screen and leaves availability to the server", () => {
    const v = describeRecovery({ phase: "idle" } as MachineState);
    assert.equal(v.headline, "Δεν υπάρχει εκκρεμές αίτημα σε αυτή την οθόνη");
    assert.match(v.body.join(" "), /ελέγχει ο διακομιστής/);
  });
});

describe("UI-04L no sensitive or technical data in any copy", () => {
  it("contains no ids, hashes, numbers, tokens, phones, payment words or raw errors", () => {
    const forbidden = [/\d/, /[0-9a-f]{12,}/i, /mem_|lkp1|evc_|nonce|token|cookie|sha|hash|http|@|null|undefined|\bNaN\b/i, /Error|Exception|SecurityError|Quota|SQL|REST|JSON|API/, /τηλέφων|email|πληρωμ|κάρτα|IBAN|κωδικός/i];
    const strings = new Set<string>(Object.values(ACTION_LABELS).concat(ESCALATION_STEPS));
    for (const state of allStates()) for (const t of textOf(describeRecovery(state))) strings.add(t);
    assert.ok(strings.size > 60);
    for (const t of strings) for (const re of forbidden) assert.doesNotMatch(t, re, `"${t}" matches ${re}`);
  });

  it("never interpolates state: distinctive ids in the state never appear in the view", () => {
    const secret: MachineState = {
      phase: "handoff_unresolved",
      intent: { memberId: "mem_deadbeefdeadbeefdeadbeefdeadbeef", requestId: "11111111-2222-4333-8444-555555555555", payloadSha256: "c".repeat(64) },
      record: { requestId: "11111111-2222-4333-8444-555555555555", memberId: "mem_deadbeefdeadbeefdeadbeefdeadbeef", businessDate: "2026-12-31", redeemedAtUtc: "2026-12-31 07:30:00.000000" },
      handoffIntent: { handoffRequestId: "99999999-8888-4777-8666-555555555555", claim: "already_handed_earlier" },
      reason: "network",
    };
    const json = JSON.stringify(describeRecovery(secret));
    for (const leak of ["deadbeef", "11111111", "99999999", "cccccccc", "2026", "already_handed_earlier", "network"]) assert.ok(!json.includes(leak), leak);
  });
});

describe("UI-04L against the REAL machine", () => {
  it("a fresh commit prepares once; a reload and a replay never do", async () => {
    const server = new FakeServer();
    const first = tablet(server);
    await first.machine.startRedemption(MEMBER_A, ESPRESSO);
    const fresh = describeRecovery(first.machine.getState());
    assert.equal(fresh.caseKey, "recorded_now:prepare");
    assert.equal(fresh.prepareAllowed, true);
    assert.deepEqual(fresh.actions.map((a) => a.action), ["acknowledge_prepare"]);

    const reloaded = tablet(server, { storage: first.storage });
    await reloaded.machine.restore();
    const replay = describeRecovery(reloaded.machine.getState());
    assert.equal(replay.caseKey, "previously_recorded_unconfirmed");
    assert.equal(replay.prepareAllowed, false);
    neverPrepares(reloaded.machine.getState());
    assert.match(replay.body.join(" "), /Μην ετοιμάσετε νέο καφέ/);
    assert.deepEqual(replay.actions.map((a) => a.action), availableActions(reloaded.machine.getState()));
  });

  it("an unknown outcome is never shown as success or failure, and a persistence block is shown as 'not sent'", async () => {
    const server = new FakeServer();
    const t = tablet(server);
    server.hooks.redeem.push(commitThenLose);
    await t.machine.startRedemption(MEMBER_A, ESPRESSO);
    const unknown = describeRecovery(t.machine.getState());
    assert.equal(unknown.caseKey, "redemption_unresolved");
    assert.equal(unknown.certainty, "uncertain");
    assert.doesNotMatch(unknown.headline + unknown.body.join(" "), OUTCOME_WORDS);

    const storage = new TabStorage();
    storage.failWrites = true;
    const b = tablet(new FakeServer(), { storage });
    await b.machine.startRedemption(MEMBER_A, ESPRESSO);
    const blockedView = describeRecovery(b.machine.getState());
    assert.equal(blockedView.caseKey, "persistence_blocked:redemption:not_sent");
    assert.equal(blockedView.redemption, "not_sent");
    assert.equal(blockedView.prepareAllowed, false);
  });
});
