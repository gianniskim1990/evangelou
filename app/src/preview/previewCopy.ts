import type { PreviewCopy } from "./previewCopyContext";

/*
 * STAGE-08 round 18 (round-17 review, Low 3): the design preview's own words for the shared member screens. A
 * PREVIEW-ONLY module (scripts/sample-guard.mjs SAMPLE_ONLY_MODULES): the real member app never imports it, so none of
 * these words reach its bundle. Provided by the preview roots through PreviewCopyContext (previewCopyContext.ts).
 */
export const PREVIEW_ONLY = "Μη διαθέσιμο στην προεπισκόπηση";

export const PREVIEW_COPY: PreviewCopy = {
  unavailable: PREVIEW_ONLY,
  paymentUnavailable: "Η πληρωμή δεν είναι διαθέσιμη στην προεπισκόπηση",
  offerNote: "Δεν υπάρχει πληρωμή στην προεπισκόπηση: το κουμπί είναι ανενεργό και καμία χρέωση δεν γίνεται.",
  offerNoteOnPay: "Δεν υπάρχει πληρωμή στην προεπισκόπηση: το κουμπί δεν χρεώνει τίποτα και η συνδρομή μένει σε αναμονή πληρωμής.",
  historyNote: "Δείγμα ιστορικού με φανταστικά δεδομένα· καμία εξαργύρωση δεν έχει καταχωρηθεί.",
  profileNote: "Δείγμα προφίλ με φανταστικά στοιχεία· καμία αλλαγή δεν αποθηκεύεται.",
  coffeesTitle: "Ενδεικτικές επιλογές",
  coffeesLine: (labels) => `Για παράδειγμα: ${labels}. Ο τελικός κατάλογος δεν έχει οριστικοποιηθεί.`,
};
