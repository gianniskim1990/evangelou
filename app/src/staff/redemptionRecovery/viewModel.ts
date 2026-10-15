import { availableActions, shouldOfferPrepare, type Action, type BlockReason, type MachineState, type RecoveryReason } from "./machine";
import type { HandoffRefusal, RedemptionRefusal } from "./wire";

/**
 * UI-04L — presentation contract for the staff recovery flow.
 *
 * PURE and presentation-only: it maps a MachineState to Greek copy, a visual
 * tone and the labels of the actions the MACHINE already allows. It creates
 * no business authority:
 *  - the action list is exactly `availableActions(state)` (order preserved);
 *  - `prepareAllowed` is exactly `shouldOfferPrepare(state)`;
 *  - escalation level is derived from whether the machine offers
 *    `manager_review` — the view model never adds an action;
 *  - no copy is interpolated from state, so no id, hash, phone, name,
 *    payment data, token or raw error can ever appear in it.
 *
 * It is NOT imported by the staff app (no wiring); the approved screens and
 * the real StaffApp are untouched.
 *
 * Four different business facts are kept apart and never merged:
 *  A. the request was definitely NOT sent        (redemption/handoff "not_sent")
 *  B. the request MAY have reached the server    ("unknown")
 *  C. the server confirmed the redemption        (redemption "recorded")
 *  D. staff ASSERTED a handoff                   (handoff "staff_statement_recorded":
 *     a statement recorded by staff, never independent proof of delivery)
 * A manual verification is the staff's own check, never proof of delivery.
 */

/** Visual tone. Future mapping to the existing tokens: see docs/staff-recovery-view-model.md. */
export type RecoveryTone = "neutral" | "positive" | "attention" | "warning" | "blocked";

export type RedemptionFact =
  | "none"
  /** A: the redemption request was definitely not sent. */
  | "not_sent"
  /** B: it may have reached the server; the outcome is not known. */
  | "unknown"
  /** The server authoritatively has no record for this id. */
  | "not_recorded"
  /** C: the server confirmed the redemption. */
  | "recorded"
  | "refused"
  | "blocked";

export type HandoffFact =
  | "none"
  /** A: the handoff statement was definitely not sent. */
  | "not_sent"
  /** B: the statement may have reached the server. */
  | "unknown"
  /** D: the server recorded a STATEMENT by staff — not independent physical proof. */
  | "staff_statement_recorded"
  | "refused";

export type EscalationLevel = "none" | "recommended" | "required";

export interface RecoveryActionView {
  readonly action: Action;
  readonly label: string;
  readonly emphasis: "primary" | "secondary" | "quiet";
}

export interface RecoveryView {
  readonly caseKey: string;
  readonly headline: string;
  /** Short plain sentences; static text only. */
  readonly body: readonly string[];
  readonly tone: RecoveryTone;
  /** "uncertain": the business outcome is not known; never worded as success or failure. */
  readonly certainty: "definite" | "uncertain";
  readonly redemption: RedemptionFact;
  readonly handoff: HandoffFact;
  /** Exactly shouldOfferPrepare(state): true only for a fresh commit's single prompt. */
  readonly prepareAllowed: boolean;
  /** Exactly availableActions(state), in the machine's order. */
  readonly actions: readonly RecoveryActionView[];
  readonly escalation: { readonly level: EscalationLevel; readonly steps: readonly string[] };
  /** True while a request is in flight: no action is offered and staff must wait. */
  readonly waiting: boolean;
}

/** One Greek label per machine action (a missing label is a compile error). */
export const ACTION_LABELS: Readonly<Record<Action, string>> = {
  start_redemption: "Νέα καταχώρηση καφέ",
  acknowledge_prepare: "Ετοιμάζεται ο καφές",
  confirm_manual_verification: "Έλεγξα με τον πελάτη",
  retry_redemption: "Δοκιμή ξανά",
  resubmit_same_intent: "Αποστολή του ίδιου αιτήματος",
  check_status: "Έλεγχος καταχώρησης",
  resume_recovery: "Ανάκτηση συναλλαγής",
  confirm_handed_now: "Δήλωση: παραδόθηκε τώρα",
  confirm_handed_earlier: "Δήλωση: παραδόθηκε νωρίτερα",
  retry_handoff: "Δοκιμή δήλωσης ξανά",
  finish: "Ολοκλήρωση",
  abandon_intent: "Κλείσιμο αιτήματος",
  reauthenticate: "Νέα σύνδεση",
  manager_review: "Ενημέρωση υπευθύνου",
  retry_restore: "Επανάληψη ανάκτησης",
  retry_persistence: "Δοκιμή καταχώρησης ξανά",
};

/** Shown only when the machine offers manager_review. Escalation creates nothing. */
export const ESCALATION_STEPS: readonly string[] = [
  "Μην ξεκινήσετε νέα καταχώρηση για αυτόν τον πελάτη.",
  "Ενημερώστε τον υπεύθυνο και πείτε του πότε έγινε η προσπάθεια.",
  "Ο υπεύθυνος ελέγχει την καταχώρηση. Από αυτή την οθόνη δεν δημιουργείται νέος καφές, παράκαμψη, επιστροφή ή δήλωση παράδοσης.",
];

interface Copy {
  headline: string;
  body: readonly string[];
  tone: RecoveryTone;
  uncertain: boolean;
  redemption: RedemptionFact;
  handoff: HandoffFact;
  waiting?: boolean;
}

const REDEMPTION_REFUSALS: Readonly<Record<RedemptionRefusal, { headline: string; body: string }>> = {
  invalid_request: { headline: "Το αίτημα δεν έγινε δεκτό", body: "Το αίτημα δεν είχε σωστή μορφή. Δεν έχει καταχωρηθεί καφές." },
  invalid_preparation: { headline: "Η παραγγελία δεν είναι έγκυρη", body: "Η επιλογή προετοιμασίας δεν έγινε δεκτή. Δεν έχει καταχωρηθεί καφές." },
  invalid_coffee: { headline: "Ο καφές δεν είναι διαθέσιμος", body: "Ο επιλεγμένος καφές δεν γίνεται δεκτός. Δεν έχει καταχωρηθεί καφές." },
  catalogue_version_stale: { headline: "Ο κατάλογος άλλαξε", body: "Φορτώστε ξανά τον κατάλογο και επιλέξτε ξανά. Δεν έχει καταχωρηθεί καφές." },
  catalogue_not_authorised: { headline: "Ο κατάλογος δεν έχει εγκριθεί ακόμη", body: "Η καταχώρηση δεν είναι δυνατή προς το παρόν. Δεν έχει καταχωρηθεί καφές." },
  member_not_found: { headline: "Δεν βρέθηκε μέλος", body: "Ελέγξτε το QR ή τον αριθμό που δώσατε. Δεν έχει καταχωρηθεί καφές." },
  membership_inactive: { headline: "Η συνδρομή δεν είναι ενεργή", body: "Η δωρεάν παροχή δεν είναι διαθέσιμη. Δεν έχει καταχωρηθεί καφές." },
  benefit_already_redeemed: { headline: "Η σημερινή παροχή έχει ήδη χρησιμοποιηθεί", body: "Ο πελάτης έχει ήδη καφέ για σήμερα. Δεν δημιουργείται δεύτερος." },
  idempotency_key_reused: { headline: "Το αίτημα δεν επαληθεύτηκε", body: "Ζητήστε βοήθεια από τον υπεύθυνο. Δεν δημιουργείται νέος καφές." },
};

const HANDOFF_REFUSALS: Readonly<Record<HandoffRefusal, { headline: string; body: string }>> = {
  invalid_request: { headline: "Η δήλωση παράδοσης δεν έγινε δεκτή", body: "Η δήλωση δεν είχε σωστή μορφή. Ελέγξτε την καταχώρηση." },
  redemption_not_found: { headline: "Δεν βρέθηκε η καταχώρηση", body: "Ο διακομιστής δεν βρήκε αυτή την καταχώρηση για τον πελάτη." },
  late_confirmation_not_authorised: { headline: "Η δήλωση παράδοσης δεν επιτρέπεται πλέον", body: "Η καταχώρηση ανήκει σε προηγούμενη ημέρα. Ζητήστε βοήθεια από τον υπεύθυνο." },
  idempotency_key_reused: { headline: "Η δήλωση παράδοσης δεν επαληθεύτηκε", body: "Ελέγξτε την καταχώρηση και ζητήστε βοήθεια από τον υπεύθυνο." },
};

const BLOCKED_AUTHORIZATION: Readonly<Record<BlockReason, { headline: string; body: string }>> = {
  unauthorized: { headline: "Απαιτείται νέα σύνδεση", body: "Η σύνδεσή σας δεν ισχύει πια." },
  forbidden: { headline: "Η ενέργεια δεν επιτρέπεται", body: "Ο λογαριασμός δεν έχει δικαίωμα για αυτή την ενέργεια. Συνδεθείτε ξανά ή ζητήστε βοήθεια από τον υπεύθυνο." },
  locked: { headline: "Η οθόνη κλειδώθηκε", body: "Συνδεθείτε ξανά για να συνεχίσετε." },
  logged_out: { headline: "Έχετε αποσυνδεθεί", body: "Συνδεθείτε ξανά για να συνεχίσετε." },
  expired: { headline: "Η σύνδεση έληξε", body: "Συνδεθείτε ξανά για να συνεχίσετε." },
};
const RECOVER_AFTER_LOGIN = "Αν υπήρχε εκκρεμής καταχώρηση, θα ανακτηθεί μετά τη σύνδεση. Δεν ξεκινά νέα καταχώρηση αυτόματα.";

const RECOVERY: Readonly<Record<RecoveryReason, Copy>> = {
  not_recorded: {
    headline: "Ο διακομιστής δεν έχει καταχώρηση",
    body: ["Το αίτημα δεν βρέθηκε στον διακομιστή. Δεν έχει καταχωρηθεί καφές.", "Μπορείτε να στείλετε το ίδιο αίτημα ή να το κλείσετε."],
    tone: "attention",
    uncertain: false,
    redemption: "not_recorded",
    handoff: "none",
  },
  redemption_missing: {
    headline: "Δεν βρέθηκε η καταχώρηση",
    body: ["Η δήλωση παράδοσης αναφέρεται σε καταχώρηση που ο διακομιστής δεν δείχνει.", "Ελέγξτε την καταχώρηση. Μην ξεκινήσετε νέα."],
    tone: "warning",
    uncertain: true,
    redemption: "unknown",
    handoff: "unknown",
  },
  session_changed: {
    headline: "Βρέθηκε εκκρεμής συναλλαγή",
    body: ["Ανήκει σε προηγούμενη σύνδεση και δεν συνεχίζεται αυτόματα.", "Επιλέξτε ανάκτηση για να ελεγχθεί η κατάστασή της στον διακομιστή."],
    tone: "attention",
    uncertain: true,
    redemption: "unknown",
    handoff: "none",
  },
  storage_corrupt: {
    headline: "Η τοπική εγγραφή δεν είναι αναγνώσιμη",
    body: ["Δεν μπορεί να ανακτηθεί εκκρεμής συναλλαγή από αυτή την οθόνη.", "Αν ο πελάτης είχε ήδη ξεκινήσει καταχώρηση, ζητήστε βοήθεια πριν ξεκινήσετε νέα. Το κλείσιμο διαγράφει μόνο την τοπική εγγραφή."],
    tone: "warning",
    uncertain: true,
    redemption: "unknown",
    handoff: "none",
  },
  storage_outdated: {
    headline: "Η τοπική εγγραφή είναι παλιάς έκδοσης",
    body: ["Δεν θεωρείται αξιόπιστη και δεν μπορεί να χρησιμοποιηθεί.", "Αν ο πελάτης είχε ήδη ξεκινήσει καταχώρηση, ζητήστε βοήθεια πριν ξεκινήσετε νέα. Το κλείσιμο διαγράφει μόνο την τοπική εγγραφή."],
    tone: "warning",
    uncertain: true,
    redemption: "unknown",
    handoff: "none",
  },
  storage_unavailable: {
    headline: "Η αποθήκευση δεν είναι διαθέσιμη",
    body: ["Δεν μπορεί να διαβαστεί η τοπική εγγραφή αυτής της καρτέλας. Μπορεί να υπάρχει εκκρεμής συναλλαγή.", "Μην ξεκινήσετε νέα καταχώρηση. Δοκιμάστε ξανά ή ζητήστε βοήθεια."],
    tone: "warning",
    uncertain: true,
    redemption: "unknown",
    handoff: "none",
  },
  status_unavailable: {
    headline: "Δεν ήταν δυνατός ο έλεγχος",
    body: ["Δεν λάβαμε απάντηση για την κατάσταση της καταχώρησης. Διατηρείται το ίδιο αίτημα.", "Δοκιμάστε ξανά τον έλεγχο. Μην ξεκινήσετε νέα καταχώρηση."],
    tone: "warning",
    uncertain: true,
    redemption: "unknown",
    handoff: "none",
  },
};

/** Stable key of the copy chosen for a state (tests enumerate it). */
export function recoveryCaseKey(s: MachineState): string {
  switch (s.phase) {
    case "idle":
    case "submitting_redemption":
    case "redemption_unresolved":
    case "checking_status":
    case "previously_recorded_confirmed":
    case "previous_business_date":
    case "handoff_confirmation_pending":
    case "handoff_unresolved":
    case "blocked_integrity":
      return s.phase;
    case "recorded_now":
      return s.prepareAcknowledged ? "recorded_now:prepared" : "recorded_now:prepare";
    case "previously_recorded_unconfirmed":
      return s.manuallyVerified ? "previously_recorded_unconfirmed:verified" : "previously_recorded_unconfirmed";
    case "redemption_refused":
      return `redemption_refused:${s.code}`;
    case "handoff_refused":
      return `handoff_refused:${s.code}`;
    case "handoff_confirmed":
      return `handoff_confirmed:${s.via}`;
    case "blocked_authorization":
      return `blocked_authorization:${s.reason}`;
    case "recovery_required":
      return `recovery_required:${s.reason}`;
    case "persistence_blocked":
      return `persistence_blocked:${s.operation}:${s.mayHaveBeenSent ? "may_have_been_sent" : "not_sent"}`;
  }
}

function copyOf(s: MachineState): Copy {
  switch (s.phase) {
    case "idle":
      return { headline: "Δεν υπάρχει εκκρεμότητα", body: ["Μπορείτε να ξεκινήσετε νέα καταχώρηση."], tone: "neutral", uncertain: false, redemption: "none", handoff: "none" };
    case "submitting_redemption":
      return { headline: "Αποστολή αιτήματος…", body: ["Περιμένετε την απάντηση. Μην πατήσετε ξανά."], tone: "neutral", uncertain: true, redemption: "unknown", handoff: "none", waiting: true };
    case "checking_status":
      return { headline: "Έλεγχος καταχώρησης…", body: ["Περιμένετε την απάντηση του διακομιστή. Μέχρι τότε δεν γίνεται καμία ενέργεια."], tone: "neutral", uncertain: true, redemption: "unknown", handoff: "none", waiting: true };
    case "handoff_confirmation_pending":
      return { headline: "Καταγραφή δήλωσης παράδοσης…", body: ["Περιμένετε την απάντηση. Μην πατήσετε ξανά."], tone: "neutral", uncertain: true, redemption: "recorded", handoff: "unknown", waiting: true };
    case "recorded_now":
      return s.prepareAcknowledged
        ? {
            headline: "Ο καφές έχει καταχωρηθεί",
            body: ["Μπορείτε να δηλώσετε ότι παραδόθηκε ή να ολοκληρώσετε.", "Η δήλωση παράδοσης είναι δήλωση του προσωπικού, όχι απόδειξη."],
            tone: "positive",
            uncertain: false,
            redemption: "recorded",
            handoff: "none",
          }
        : {
            headline: "Η καταχώρηση έγινε",
            body: ["Ο διακομιστής επιβεβαίωσε τη δωρεάν παροχή.", "Ετοιμάστε τον καφέ του πελάτη. Η οδηγία εμφανίζεται μία φορά."],
            tone: "positive",
            uncertain: false,
            redemption: "recorded",
            handoff: "none",
          };
    case "previously_recorded_unconfirmed":
      return s.manuallyVerified
        ? {
            headline: "Ελέγξατε με τον πελάτη",
            body: ["Ο δικός σας έλεγχος δεν αποτελεί απόδειξη παράδοσης.", "Μπορείτε να καταγράψετε δήλωση παράδοσης. Δεν δημιουργείται νέος καφές."],
            tone: "attention",
            uncertain: false,
            redemption: "recorded",
            handoff: "none",
          }
        : {
            headline: "Υπάρχει ήδη καταχώρηση για σήμερα",
            body: ["Ο καφές έχει καταχωρηθεί νωρίτερα. Μην ετοιμάσετε νέο καφέ.", "Δεν υπάρχει δήλωση παράδοσης. Ελέγξτε με τον πελάτη πριν δηλώσετε οτιδήποτε."],
            tone: "attention",
            uncertain: false,
            redemption: "recorded",
            handoff: "none",
          };
    case "previously_recorded_confirmed":
      return {
        headline: "Η παράδοση έχει δηλωθεί",
        body: ["Το προσωπικό έχει δηλώσει ότι ο καφές παραδόθηκε. Αυτό δεν είναι ανεξάρτητη απόδειξη.", "Μην ετοιμάσετε νέο καφέ."],
        tone: "neutral",
        uncertain: false,
        redemption: "recorded",
        handoff: "staff_statement_recorded",
      };
    case "previous_business_date":
      return {
        headline: "Καταχώρηση προηγούμενης ημέρας",
        body: [
          "Αυτή η καταχώρηση ανήκει σε προηγούμενη ημέρα και δεν αφορά τον σημερινό καφέ.",
          ...(s.handoff ? ["Το προσωπικό έχει δηλώσει παράδοση. Δεν αποτελεί ανεξάρτητη απόδειξη."] : []),
          "Μην ετοιμάσετε καφέ για αυτήν. Ολοκληρώστε την πρώτα.",
        ],
        tone: "attention",
        uncertain: false,
        redemption: "recorded",
        handoff: s.handoff ? "staff_statement_recorded" : "none",
      };
    case "redemption_unresolved":
      return {
        headline: "Δεν έχει επιβεβαιωθεί το αποτέλεσμα",
        body: ["Το αίτημα ίσως έφτασε στον διακομιστή. Δεν γνωρίζουμε αν έχει γίνει η καταχώρηση.", "Μην ξεκινήσετε νέα καταχώρηση. Ελέγξτε την καταχώρηση ή δοκιμάστε ξανά το ίδιο αίτημα."],
        tone: "warning",
        uncertain: true,
        redemption: "unknown",
        handoff: "none",
      };
    case "handoff_unresolved":
      return {
        headline: "Η δήλωση παράδοσης δεν έχει επιβεβαιωθεί",
        body: ["Ο καφές είναι ήδη καταχωρημένος. Δεν γνωρίζουμε αν καταγράφηκε η δήλωση παράδοσης.", "Ελέγξτε την καταχώρηση ή δοκιμάστε ξανά την ίδια δήλωση."],
        tone: "warning",
        uncertain: true,
        redemption: "recorded",
        handoff: "unknown",
      };
    case "handoff_confirmed": {
      const note = "Δεν αποτελεί ανεξάρτητη απόδειξη παράδοσης.";
      if (s.via === "already_confirmed") {
        return {
          headline: "Υπήρχε ήδη δήλωση παράδοσης",
          body: ["Για αυτή την καταχώρηση είχε ήδη καταγραφεί δήλωση. Η δική σας δεν προστέθηκε.", note],
          tone: "neutral",
          uncertain: false,
          redemption: "recorded",
          handoff: "staff_statement_recorded",
        };
      }
      return {
        headline: s.via === "status" || s.via === "replayed" ? "Η δήλωση παράδοσης είναι ήδη καταγραμμένη" : "Η δήλωση παράδοσης καταγράφηκε",
        body: ["Έχει καταγραφεί δήλωση του προσωπικού ότι ο καφές παραδόθηκε.", note],
        tone: "positive",
        uncertain: false,
        redemption: "recorded",
        handoff: "staff_statement_recorded",
      };
    }
    case "redemption_refused": {
      const c = REDEMPTION_REFUSALS[s.code];
      return { headline: c.headline, body: [c.body], tone: "blocked", uncertain: false, redemption: "refused", handoff: "none" };
    }
    case "handoff_refused": {
      const c = HANDOFF_REFUSALS[s.code];
      return { headline: c.headline, body: [c.body, "Ο καφές παραμένει καταχωρημένος."], tone: "warning", uncertain: false, redemption: "recorded", handoff: "refused" };
    }
    case "blocked_integrity":
      return {
        headline: "Απαιτείται έλεγχος από υπεύθυνο",
        body: ["Η καταχώρηση έχει ασυνέπεια και δεν μπορεί να συνεχιστεί από εδώ.", "Μην ξεκινήσετε νέα καταχώρηση για αυτόν τον πελάτη."],
        tone: "blocked",
        uncertain: true,
        redemption: "blocked",
        handoff: "none",
      };
    case "blocked_authorization": {
      const c = BLOCKED_AUTHORIZATION[s.reason];
      return { headline: c.headline, body: [c.body, RECOVER_AFTER_LOGIN], tone: "blocked", uncertain: true, redemption: "unknown", handoff: "none" };
    }
    case "recovery_required":
      return RECOVERY[s.reason];
    case "persistence_blocked":
      if (s.operation === "redemption") {
        return s.mayHaveBeenSent
          ? {
              headline: "Η επανάληψη δεν στάλθηκε",
              body: ["Δεν ήταν δυνατή η ασφαλής αποθήκευση, οπότε η επανάληψη δεν στάλθηκε. Το αρχικό αίτημα ίσως έχει φτάσει στον διακομιστή.", "Δεν γνωρίζουμε αν έχει γίνει η καταχώρηση. Ελέγξτε την καταχώρηση ή δοκιμάστε ξανά."],
              tone: "warning",
              uncertain: true,
              redemption: "unknown",
              handoff: "none",
            }
          : {
              headline: "Το αίτημα δεν στάλθηκε",
              body: ["Δεν ήταν δυνατή η ασφαλής αποθήκευση πριν την αποστολή, γι' αυτό δεν στάλθηκε τίποτα. Δεν έχει καταχωρηθεί καφές.", "Δοκιμάστε ξανά. Θα σταλεί το ίδιο αίτημα."],
              tone: "warning",
              uncertain: false,
              redemption: "not_sent",
              handoff: "none",
            };
      }
      return s.mayHaveBeenSent
        ? {
            headline: "Η επανάληψη της δήλωσης δεν στάλθηκε",
            body: ["Η προηγούμενη δήλωση ίσως έφτασε στον διακομιστή. Δεν γνωρίζουμε αν καταγράφηκε.", "Ελέγξτε την καταχώρηση ή δοκιμάστε ξανά την ίδια δήλωση."],
            tone: "warning",
            uncertain: true,
            redemption: "recorded",
            handoff: "unknown",
          }
        : {
            headline: "Η δήλωση παράδοσης δεν στάλθηκε",
            body: ["Ο καφές είναι ήδη καταχωρημένος. Η δήλωση δεν στάλθηκε, γιατί δεν ήταν δυνατή η ασφαλής αποθήκευση.", "Δοκιμάστε ξανά ή ολοκληρώστε χωρίς δήλωση παράδοσης."],
            tone: "warning",
            uncertain: false,
            redemption: "recorded",
            handoff: "not_sent",
          };
  }
}

/** Maps a machine state to its presentation. Pure; actions come only from availableActions(). */
export function describeRecovery(state: MachineState): RecoveryView {
  const copy = copyOf(state);
  const offered = availableActions(state);
  const actions: RecoveryActionView[] = [];
  let primaryTaken = false;
  for (const action of offered) {
    const quiet = action === "manager_review";
    const emphasis = quiet ? "quiet" : primaryTaken ? "secondary" : "primary";
    if (!quiet) primaryTaken = true;
    actions.push(Object.freeze({ action, label: ACTION_LABELS[action], emphasis }));
  }
  const hasManager = offered.includes("manager_review");
  // The machine decides whether a manager is involved; only its being the sole action makes it "required".
  const level: EscalationLevel = !hasManager ? "none" : offered.length === 1 ? "required" : "recommended";
  return Object.freeze({
    caseKey: recoveryCaseKey(state),
    headline: copy.headline,
    body: Object.freeze([...copy.body]),
    tone: copy.tone,
    certainty: copy.uncertain ? "uncertain" : "definite",
    redemption: copy.redemption,
    handoff: copy.handoff,
    prepareAllowed: shouldOfferPrepare(state),
    actions: Object.freeze(actions),
    escalation: Object.freeze({ level, steps: level === "none" ? Object.freeze([]) : ESCALATION_STEPS }),
    waiting: copy.waiting === true && offered.length === 0,
  });
}
