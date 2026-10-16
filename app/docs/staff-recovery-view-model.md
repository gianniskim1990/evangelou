# Staff recovery view model and Greek action copy (UI-04L)

**Status: a presentation contract only. Not wired.**

- The code is `app/src/staff/redemptionRecovery/viewModel.ts`.
- `StaffApp`, `staffController`, the staff screens and the 26 approved
  customer previews are untouched.
- An import-graph test proves that:
  - the module depends only on `machine`, `wire` and `format`;
  - neither shipped entry reaches it;
  - none of its copy appears in either build.
- The staff bundle hash is unchanged (`staff-CrPA0l2H.js`).

## Interface

```ts
describeRecovery(state: MachineState): RecoveryView

RecoveryView {
  caseKey      // stable key of the chosen copy, e.g. "persistence_blocked:redemption:not_sent"
  headline     // Greek, one line
  body[]       // short Greek sentences, static text
  tone         // neutral | positive | attention | warning | blocked
  certainty    // definite | uncertain  (uncertain = outcome not known)
  redemption   // none | not_sent | unknown | not_recorded | recorded | refused | blocked
  handoff      // none | not_sent | unknown | staff_statement_recorded | refused
  prepareAllowed          // === shouldOfferPrepare(state)
  actions[]               // === availableActions(state), same order: {action, label, emphasis}
  escalation { level, steps[] }   // none | recommended | required
  waiting                 // a request is in flight; no action is offered
}
```

**No business authority is created here:**
- `actions` are exactly `availableActions(state)`, in the machine's order.
  The view model never adds, removes or reorders an action.
- `prepareAllowed` is exactly `shouldOfferPrepare(state)`.
- `escalation.level` is derived from the machine offering `manager_review`:
  - `required` when it is the only action offered;
  - `recommended` when offered together with other actions;
  - `none` otherwise.
- **No interpolation.** Every string is static: no id, hash, name, phone,
  date, payment data, token or raw error text can reach the copy.
- All output is frozen, and the function is pure.

## Four business facts that are never merged

| Fact | `redemption` / `handoff` | Meaning |
|---|---|---|
| A | `not_sent` | The request was **definitely not sent**. "Με το συγκεκριμένο αίτημα δεν καταχωρήθηκε καφές." (about THIS request only) |
| B | `unknown` | It **may have reached the server**. Never worded as success or failure ("Δεν γνωρίζουμε αν…"). |
| C | `recorded` | **The server confirmed** the redemption. |
| D | `staff_statement_recorded` | The server recorded a **statement by staff** that the coffee was handed over. Never independent physical proof. |

A manual verification ("Έλεγξα με τον πελάτη") is the staff member's own
check and is explicitly described as "δεν αποτελεί απόδειξη παράδοσης".

## Tone → existing design tokens (future integration reference)

| Tone | Token (src/index.css) |
|---|---|
| neutral | `--color-espresso` on `--color-surface` |
| positive | `--color-bronze` |
| attention | `--color-bronze-dark` |
| warning, blocked | `--color-maroon` (blocked adds the lock/shield icon family of `StaffApp`) |

Layout is not decided here; screens follow the approved Staff dashboard.
The view model contains no markup and no class names.

## Greek copy matrix

| Case key | Headline | Certainty | Actions (machine order) |
|---|---|---|---|
| `persistence_blocked:redemption:not_sent` | Το αίτημα δεν στάλθηκε | definite (A) | Δοκιμή καταχώρησης ξανά · Κλείσιμο αιτήματος |
| `persistence_blocked:redemption:may_have_been_sent` | Η επανάληψη δεν στάλθηκε | uncertain (B) | Δοκιμή καταχώρησης ξανά · Έλεγχος καταχώρησης |
| `persistence_blocked:handoff:not_sent` | Η δήλωση παράδοσης δεν στάλθηκε | definite; redemption recorded | Δοκιμή καταχώρησης ξανά · Έλεγχος καταχώρησης · Ολοκλήρωση |
| `persistence_blocked:handoff:may_have_been_sent` | Η επανάληψη της δήλωσης δεν στάλθηκε | uncertain | Δοκιμή καταχώρησης ξανά · Έλεγχος καταχώρησης |
| `recovery_required:storage_unavailable` | Η αποθήκευση δεν είναι διαθέσιμη | uncertain | Επανάληψη ανάκτησης · Ενημέρωση υπευθύνου |
| `recovery_required:storage_corrupt` | Η τοπική εγγραφή δεν είναι αναγνώσιμη | uncertain | Κλείσιμο αιτήματος · Ενημέρωση υπευθύνου |
| `recovery_required:storage_outdated` | Η τοπική εγγραφή είναι παλιάς έκδοσης | uncertain | Κλείσιμο αιτήματος · Ενημέρωση υπευθύνου |
| `recovery_required:session_changed` | Βρέθηκε εκκρεμής συναλλαγή | uncertain | Ανάκτηση συναλλαγής |
| `recovery_required:not_recorded` | Δεν βρέθηκε καταχώρηση για το συγκεκριμένο αίτημα | definite | Αποστολή του ίδιου αιτήματος · Κλείσιμο αιτήματος |
| `recovery_required:redemption_missing` / `status_unavailable` | Δεν βρέθηκε η καταχώρηση / Δεν ήταν δυνατός ο έλεγχος | uncertain | Έλεγχος καταχώρησης · Ενημέρωση υπευθύνου |
| `redemption_unresolved` | Δεν έχει επιβεβαιωθεί το αποτέλεσμα | uncertain (B) | Δοκιμή ξανά · Έλεγχος καταχώρησης |
| `handoff_unresolved` | Η δήλωση παράδοσης δεν έχει επιβεβαιωθεί | uncertain | Δοκιμή δήλωσης ξανά · Έλεγχος καταχώρησης |
| `recorded_now:prepare` | Η καταχώρηση έγινε | definite (C) | Ετοιμάζεται ο καφές (**the only case with `prepareAllowed`**) |
| `recorded_now:prepared` | Ο καφές έχει καταχωρηθεί | definite (C) | Δήλωση: παραδόθηκε τώρα · Δήλωση: παραδόθηκε νωρίτερα · Ολοκλήρωση |
| `previously_recorded_unconfirmed` | Υπάρχει ήδη καταχώρηση για σήμερα | definite (C); "Μην ετοιμάσετε νέο καφέ" | Έλεγξα με τον πελάτη · Έλεγχος καταχώρησης · Ολοκλήρωση |
| `…:verified` | Ελέγξατε με τον πελάτη | definite; not proof | Δήλωση: παραδόθηκε τώρα/νωρίτερα · Έλεγχος καταχώρησης · Ολοκλήρωση |
| `previously_recorded_confirmed` | Η παράδοση έχει δηλωθεί | definite (C + D) | Ολοκλήρωση · Έλεγχος καταχώρησης |
| `previous_business_date` | Καταχώρηση προηγούμενης ημέρας | definite | Ολοκλήρωση |
| `handoff_confirmed:*` | Η δήλωση παράδοσης καταγράφηκε / είναι ήδη καταγραμμένη / Υπήρχε ήδη δήλωση | definite (D) | Ολοκλήρωση · Έλεγχος καταχώρησης |
| `redemption_refused:*` (9 codes) | One headline per code | definite; "Με το συγκεκριμένο αίτημα δεν καταχωρήθηκε καφές" (already-redeemed: authoritative "έχει ήδη χρησιμοποιηθεί") | Ολοκλήρωση |
| `handoff_refused:*` (4 codes) | One headline per code | definite | Έλεγχος καταχώρησης · Ενημέρωση υπευθύνου |
| `blocked_integrity` | Απαιτείται έλεγχος από υπεύθυνο | uncertain | Ενημέρωση υπευθύνου (**required**) |
| `blocked_authorization:*` (5 reasons) | Απαιτείται νέα σύνδεση / Η οθόνη κλειδώθηκε / … | uncertain | Νέα σύνδεση (the only action) |
| `idle`, `submitting_redemption`, `checking_status`, `handoff_confirmation_pending` | Δεν υπάρχει εκκρεμές αίτημα σε αυτή την οθόνη / Αποστολή… / Έλεγχος… / Καταγραφή… | waiting states offer no action | — |

46 case keys in total. The tests enumerate every one.

## Action labels (one per machine action)

| Action | Label |
|---|---|
| `start_redemption` | Νέα καταχώρηση καφέ |
| `acknowledge_prepare` | Ετοιμάζεται ο καφές |
| `confirm_manual_verification` | Έλεγξα με τον πελάτη |
| `retry_redemption` | Δοκιμή ξανά |
| `resubmit_same_intent` | Αποστολή του ίδιου αιτήματος |
| `check_status` | Έλεγχος καταχώρησης |
| `resume_recovery` | Ανάκτηση συναλλαγής |
| `confirm_handed_now` | Δήλωση: παραδόθηκε τώρα |
| `confirm_handed_earlier` | Δήλωση: παραδόθηκε νωρίτερα |
| `retry_handoff` | Δοκιμή δήλωσης ξανά |
| `finish` | Ολοκλήρωση |
| `abandon_intent` | Κλείσιμο αιτήματος |
| `reauthenticate` | Νέα σύνδεση |
| `manager_review` | Ενημέρωση υπευθύνου |
| `retry_restore` | Επανάληψη ανάκτησης |
| `retry_persistence` | Δοκιμή καταχώρησης ξανά |

Handoff labels say **"Δήλωση"** (declaration), never "Επιβεβαίωση
παράδοσης". `Record<Action, string>` makes a missing label a compile error.

## Manager escalation

When the machine offers `manager_review`, the view carries three fixed
steps: don't start a new redemption; tell the manager when the attempt
happened; the manager checks the record — **"από αυτή την οθόνη δεν
δημιουργείται νέος καφές, παράκαμψη, επιστροφή ή δήλωση παράδοσης"**.

Escalation is guidance only. It creates no redemption, override, refund,
entitlement or handoff, and the manager-only capabilities
(`evc_manage_club`, `evc_review_fulfillment`) stay unavailable to the
shared staff role.

## Copy rules (enforced by tests)

- **No success words for uncertain states.** Views with
  `certainty: "uncertain"` never contain success or failure wording (the
  Greek for "succeeded", "failed", "completed", "was recorded" or "did not
  happen"), nor "δεν καταχωρήθηκε", and never use the `positive` tone.
- **"Prepare" only for a fresh commit.** The prepare instruction ("Ετοιμάστε
  …") appears only in `recorded_now:prepare`. Replays and reloads say "Μην
  ετοιμάσετε νέο καφέ".
- **Handoff wording.** A handoff is described only as a declaration or
  statement, with a "not independent proof" note wherever one is recorded.
  This includes previous-day records; the test found and fixed a missing
  note there.
- **Session lock.** Lock, logout, expiry or a rejected session offer only
  "Νέα σύνδεση", and say that pending work is recovered after login with no
  automatic new redemption.
- **Storage.** Unavailable, corrupt or outdated storage stays fail closed
  with "Μην ξεκινήσετε νέα καταχώρηση".
- **No technical or personal data in copy.** No digits, hex strings, ids,
  hashes, `mem_` or `evc_`, phone, e-mail, payment words, `Error` or
  `Exception`.
- **Unique labels.** Every label is Greek, unique, and at most 40
  characters.
