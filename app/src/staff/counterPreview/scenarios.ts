import type { Fault, FakeClubServer, Operation } from "./fakeServer";
import type { PreviewTabStorage } from "./previewSession";

/**
 * UI-04O LOCAL-ONLY scenario catalogue of the counter-flow preview. A
 * scenario only prepares the fake world (scripted server faults, clock, tab
 * store); every screen and decision after that is the real flow. The steps
 * tell the reviewer where to click. Fake members only.
 */

export interface ScenarioWorld {
  server: FakeClubServer;
  storage: PreviewTabStorage;
  /** Moves the preview clock to `seconds` before the next Athens midnight. */
  setBeforeMidnight(seconds: number): void;
}

export interface Scenario {
  readonly id: string;
  readonly group: "Βασική ροή" | "Αβέβαιη έκβαση" | "Σύνδεση και δικαιώματα" | "Υπεύθυνος και αποθήκευση";
  readonly title: string;
  readonly steps: readonly string[];
  /** What must be true at the end (the guarantee the scenario demonstrates). */
  readonly expect: string;
  readonly setup?: (w: ScenarioWorld) => void;
}

const fault = (operation: Operation, f: Fault) => (w: ScenarioWorld) => w.server.schedule(operation, f);

const ORDER = "Διαλέξτε καφέ και τις υποχρεωτικές επιλογές (ή «Όπως συνήθως») → Καταχώρηση καφέ";

export const SCENARIOS: readonly Scenario[] = [
  {
    id: "normal",
    group: "Βασική ροή",
    title: "Κανονική ροή με σάρωση QR (γρήγορο ταμείο)",
    steps: ["Σάρωση κάρτας → «Ενεργή κάρτα» (Αλεξάνδρα)", ORDER],
    expect: "Μετά την αναζήτηση ανοίγει αμέσως η επιλογή καφέ. Ένα πάτημα στέλνει ένα αίτημα· μόλις ο διακομιστής επιβεβαιώσει νέα καταχώρηση εμφανίζεται «Ο καφές καταχωρήθηκε» και η οθόνη επιστρέφει στο ταμείο. Καμία δήλωση παράδοσης δεν καταγράφεται.",
  },
  {
    id: "phone",
    group: "Βασική ροή",
    title: "Αναζήτηση με τηλέφωνο",
    steps: ["Χωρίς κάρτα; Αναζήτηση με τηλέφωνο", "Πατήστε το δείγμα «691 000 0052» (Σοφία) ή πληκτρολογήστε το", "Αναζήτηση", ORDER],
    expect: "Η αναζήτηση επιστρέφει μόνο ταυτότητα και προσωρινή απόδειξη αναζήτησης· στην οθόνη φαίνεται μόνο μασκαρισμένο τηλέφωνο.",
  },
  {
    id: "used",
    group: "Βασική ροή",
    title: "Έχει ήδη πάρει καφέ σήμερα",
    steps: ["Σάρωση κάρτας → «Καφές ήδη σήμερα» (Νικόλαος)"],
    expect: "Δεν ανοίγει επιλογή καφέ· φαίνεται ο λόγος και το «Επόμενος πελάτης». Ένα δικαίωμα ανά μέλος ανά ημέρα, ώρα Ελλάδας.",
  },
  {
    id: "inactive",
    group: "Βασική ροή",
    title: "Ληγμένη ή μη ενεργή συνδρομή",
    steps: ["Σάρωση κάρτας → «Ληγμένη κάρτα» (Δήμητρα)", "Επόμενος πελάτης → Σάρωση → «Πληρωμή σε αναμονή» (Γιάννης)"],
    expect: "Καμία επιλογή καφέ· ο λόγος φαίνεται με εικονίδιο και κείμενο.",
  },
  {
    id: "other-tablet",
    group: "Βασική ροή",
    title: "Άλλο tablet πρόλαβε",
    steps: ["Σάρωση κάρτας → «Ενεργή κάρτα» (Αλεξάνδρα)", ORDER],
    expect: "Η σύνοψη έδειχνε «δικαιούται», αλλά ο διακομιστής αποφασίζει: «ήδη καταχωρημένο σήμερα», χωρίς «ετοιμάστε».",
    setup: fault("redeem", "another_tablet_first"),
  },
  {
    id: "lost-after-commit",
    group: "Αβέβαιη έκβαση",
    title: "Χάθηκε η απάντηση (η καταχώρηση έγινε)",
    steps: ["Σάρωση → «Ενεργή κάρτα»", ORDER, "Δοκιμή ξανά", "Έλεγξα με τον πελάτη → «Δήλωση: παραδόθηκε νωρίτερα» ή «Ολοκλήρωση»"],
    expect: "Η επανάληψη στέλνει το ΙΔΙΟ αναγνωριστικό (Εργαλεία → αρχείο διακομιστή). Δεν εμφανίζεται ποτέ «ετοιμάστε» μετά από επανάληψη.",
    setup: fault("redeem", "lose_reply_after_commit"),
  },
  {
    id: "lost-before",
    group: "Αβέβαιη έκβαση",
    title: "Χάθηκε το αίτημα (δεν καταχωρήθηκε)",
    steps: ["Σάρωση → «Ενεργή κάρτα»", ORDER, "Έλεγχος καταχώρησης", "Αποστολή του ίδιου αιτήματος"],
    expect: "Πρώτα διαβάζεται η κατάσταση από τον διακομιστή. Το ίδιο αίτημα ξαναστέλνεται με το αρχικό αναγνωριστικό· η πρώτη πραγματική καταχώρηση κλείνει με «Ο καφές καταχωρήθηκε».",
    setup: fault("redeem", "lose_request"),
  },
  {
    id: "unknown",
    group: "Αβέβαιη έκβαση",
    title: "Ο διακομιστής δεν ξέρει την έκβαση",
    steps: ["Σάρωση → «Ενεργή κάρτα»", ORDER, "Έλεγχος καταχώρησης"],
    expect: "Η άγνωστη έκβαση μένει ρητά αβέβαιη μέχρι να διαβαστεί η κατάσταση. Ποτέ δεύτερη καταχώρηση.",
    setup: fault("redeem", "outcome_unknown_after_commit"),
  },
  {
    id: "reload",
    group: "Αβέβαιη έκβαση",
    title: "Ανανέωση σελίδας στη μέση",
    steps: ["Σάρωση → «Ενεργή κάρτα»", ORDER, "Εργαλεία: «Ανανέωση σελίδας»"],
    expect: "Μετά την ανανέωση δεν υπάρχουν στοιχεία μέλους στη μνήμη. Το πρώτο αίτημα είναι ανάγνωση κατάστασης· καμία νέα καταχώρηση.",
    setup: fault("redeem", "lose_reply_after_commit"),
  },
  {
    id: "handoff-lost",
    group: "Αβέβαιη έκβαση",
    title: "Χάθηκε η απάντηση της (προαιρετικής) δήλωσης παράδοσης",
    steps: ["Σάρωση → «Ενεργή κάρτα»", ORDER, "Δοκιμή ξανά", "Έλεγξα με τον πελάτη → Δήλωση: παραδόθηκε τώρα", "Δοκιμή δήλωσης ξανά"],
    expect: "Η δήλωση παράδοσης είναι προαιρετική και εμφανίζεται μόνο στην οθόνη ανάκτησης. Ξαναστέλνεται με το ίδιο αναγνωριστικό δήλωσης· καταγράφεται μία φορά.",
    setup: (w) => {
      w.server.schedule("redeem", "lose_reply_after_commit");
      w.server.schedule("handoff", "lose_reply_after_commit");
    },
  },
  {
    id: "midnight",
    group: "Αβέβαιη έκβαση",
    title: "Αλλαγή ημέρας στα μεσάνυχτα",
    steps: ["Σάρωση → «Ενεργή κάρτα»", ORDER, "Εργαλεία: «+1 λεπτό»", "Δοκιμή ξανά", "Ολοκλήρωση → νέα σάρωση και «Καταχώρηση καφέ» για τη νέα ημέρα"],
    expect: "Η χθεσινή καταχώρηση δεν είναι ο σημερινός καφές. Η νέα ημέρα (ώρα Ελλάδας) δίνει νέο δικαίωμα μόνο με νέο αίτημα.",
    setup: (w) => {
      w.setBeforeMidnight(75);
      w.server.schedule("redeem", "lose_reply_after_commit");
    },
  },
  {
    id: "expired",
    group: "Σύνδεση και δικαιώματα",
    title: "Λήξη συνεδρίας κατά την καταχώρηση",
    steps: ["Σάρωση → «Ενεργή κάρτα»", ORDER, "Νέα σύνδεση", "Ανάκτηση συναλλαγής", "Κλείσιμο αιτήματος (η παραγγελία δεν υπάρχει πια στη μνήμη)"],
    expect: "Τα στοιχεία του μέλους διαγράφονται αμέσως. Μετά τη νέα σύνδεση, μόνο ανάγνωση κατάστασης με το αρχικό αναγνωριστικό.",
    setup: fault("redeem", "session_expired"),
  },
  {
    id: "forbidden",
    group: "Σύνδεση και δικαιώματα",
    title: "Αφαιρέθηκε το δικαίωμα πρόσβασης",
    steps: ["Σάρωση → «Ενεργή κάρτα»", ORDER],
    expect: "Αποτυχία με ασφάλεια: η εφαρμογή σταματά και ζητά σύνδεση.",
    setup: fault("redeem", "permission_revoked"),
  },
  {
    id: "lock",
    group: "Σύνδεση και δικαιώματα",
    title: "Κλείδωμα οθόνης",
    steps: ["Σάρωση → «Ενεργή κάρτα»", "Κλείδωμα οθόνης (στον πίνακα ή στα εργαλεία)", "Νέα σύνδεση"],
    expect: "Η συνεδρία τερματίζεται στον διακομιστή· τα στοιχεία μέλους δεν ξαναεμφανίζονται χωρίς νέα αναζήτηση.",
  },
  {
    id: "manager",
    group: "Υπεύθυνος και αποθήκευση",
    title: "Χρειάζεται υπεύθυνος (ασυνέπεια εγγραφής)",
    steps: ["Σάρωση → «Ενεργή κάρτα»", ORDER, "Ενημέρωση υπευθύνου"],
    expect: "Μόνο η ενημέρωση υπευθύνου προσφέρεται. Καμία παράκαμψη, νέος καφές ή επιστροφή από αυτή την οθόνη.",
    setup: fault("redeem", "integrity_failure"),
  },
  {
    id: "storage",
    group: "Υπεύθυνος και αποθήκευση",
    title: "Η αποθήκευση της καρτέλας απέτυχε",
    steps: ["Σάρωση → «Ενεργή κάρτα»", ORDER, "Εργαλεία: «Αποθήκευση καρτέλας» → λειτουργεί", "Δοκιμή καταχώρησης ξανά"],
    expect: "Χωρίς αποθηκευμένο πλαίσιο ανάκτησης δεν στέλνεται κανένα αίτημα. Μετά στέλνεται με το ίδιο αναγνωριστικό.",
    setup: (w) => {
      w.storage.failRecoveryWrites = true;
    },
  },
];

export const FAULT_LABELS: Readonly<Record<Fault, string>> = {
  lose_reply_after_commit: "Η απάντηση χάνεται μετά την καταχώρηση",
  lose_request: "Το αίτημα δεν φτάνει στον διακομιστή",
  outcome_unknown_after_commit: "Άγνωστη έκβαση (καταχωρήθηκε)",
  server_error_before_commit: "Σφάλμα διακομιστή (δεν καταχωρήθηκε)",
  session_expired: "Η συνεδρία έληξε",
  permission_revoked: "Αφαιρέθηκε το δικαίωμα",
  integrity_failure: "Ασυνέπεια εγγραφής",
  status_unavailable: "Η κατάσταση δεν διαβάζεται",
  another_tablet_first: "Άλλο tablet καταχωρεί πρώτο",
};

export const OPERATION_LABELS: Readonly<Record<Operation, string>> = {
  lookup: "Αναζήτηση",
  summary: "Σύνοψη μέλους",
  redeem: "Καταχώρηση",
  status: "Κατάσταση",
  handoff: "Δήλωση παράδοσης",
};
