import { createContext } from "react";

/*
 * STAGE-08 round 18 (round-17 review, Low 3): the design preview's own words (sample notes, «not available in the
 * preview» tooltips, the illustrative coffee list) are no longer written inside the shared member screens, which the
 * real member app also renders. The screens read them from this context; ONLY the preview roots provide it
 * (previewCopy.ts, a preview-only module the member app never imports: scripts/sample-guard.mjs). In the member app the
 * context is null, so those words are not in its bundle at all (the packager refuses «Δείγμα» and «προεπισκόπηση»).
 */
export interface PreviewCopy {
  /** Tooltip of a button the design preview leaves disabled. */
  unavailable: string;
  /** Tooltip of the offer's payment button in the design preview. */
  paymentUnavailable: string;
  /** The note under the offer in the design preview (without / with the journey's payment step). */
  offerNote: string;
  offerNoteOnPay: string;
  /** The notes under the sample history and the sample profile. */
  historyNote: string;
  profileNote: string;
  /** «Σήμερα»: the illustrative coffee list of the design preview. */
  coffeesTitle: string;
  coffeesLine: (labels: string) => string;
}

export const PreviewCopyContext = createContext<PreviewCopy | null>(null);
