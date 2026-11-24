import { useContext, type ReactNode } from "react";
import { CLUB_RULES, greekDate } from "./previewData";
import type { CustomerScreen } from "./screens";
import { joinAction, paymentAction, paymentNote, type CustomerActions, type CustomerMembershipState, type CustomerView } from "./customerView";
import { Btn, Glyph, Logo, PreviewNote, WingLines } from "./ds";
import { PreviewCopyContext } from "./previewCopyContext";

/* UI-01.R1 signature screen, Customer Club: the membership pass (round 18: the welcome page moved to clubWelcome.tsx, a preview-only module). */


function todayLine(state: CustomerMembershipState, used: boolean, clubDataUnavailable = false): { headline: string; detail: string; tone: "ready" | "done" | "blocked" } {
  if (state === "none") return { headline: "Δεν έχετε συνδρομή", detail: "Η κάρτα δεν δίνει καφέ χωρίς ενεργή συνδρομή.", tone: "blocked" };
  if (state === "unverified") return { headline: "Η συνδρομή δεν επιβεβαιώθηκε", detail: "Αν έχετε πληρώσει, μιλήστε με το κατάστημα.", tone: "blocked" };
  if (state === "active" && clubDataUnavailable) return { headline: "Η συνδρομή σας είναι ενεργή", detail: "Δείξτε αυτή την κάρτα στο ταμείο· εκεί ελέγχεται ο σημερινός καφές.", tone: "ready" };
  if (state === "pending") return { headline: "Σε αναμονή πληρωμής", detail: "Η κάρτα ενεργοποιείται μόλις επιβεβαιωθεί η πληρωμή.", tone: "blocked" };
  if (state === "expired") return { headline: "Η συνδρομή έληξε", detail: "Ανανεώστε για να ξαναβρείτε τον καφέ της ημέρας.", tone: "blocked" };
  if (state === "cancelled") return { headline: "Η συνδρομή ακυρώθηκε", detail: "Η κάρτα δεν δίνει καφέ. Για ερωτήσεις, μιλήστε με το κατάστημα.", tone: "blocked" };
  if (used) return { headline: "Τον απολαύσατε σήμερα", detail: "Ο επόμενος καφές σας περιμένει από τα μεσάνυχτα.", tone: "done" };
  return { headline: "Ο σημερινός καφές σας περιμένει", detail: "Δείξτε αυτή την κάρτα στο ταμείο.", tone: "ready" };
}

function untilLine(view: CustomerView): string {
  const at = view.paidUntilTime ? `, ${view.paidUntilTime}` : "";
  if (view.state === "cancelled") return "Ακυρώθηκε";
  if (!view.paidUntil) return "Δεν έχει ενεργοποιηθεί";
  return view.state === "active" ? `Ισχύει έως ${greekDate(view.paidUntil)}${at}` : `Έληξε ${greekDate(view.paidUntil)}${at}`;
}

function daysLeftWord(n: number): string {
  if (n <= 0) return "Λήγει σήμερα";
  return n === 1 ? "1 ημέρα" : `${n} ημέρες`;
}

/**
 * The approved pass for a given view; imports no sample data (used directly by the STAGE-01 live app).
 * STAGE-08 round 17 (owner-found bug, staging 2026-11-23: an active member saw the design preview's SAMPLE QR for
 * about 2 seconds before the real one): the QR slot and the note under the steps are REQUIRED and this component has
 * no fallback of its own any more. The live app passes the member's real QR panel (or a neutral «Φόρτωση…» /
 * not-available state, never a pattern); only the preview wrapper (customerSampleScreens.tsx MemberPass) passes
 * the sample code, its sample label and its sample note.
 */
export function MemberPassView({
  go,
  view,
  actions,
  qr,
  qrNote,
  label = "Κάρτα μέλους Evangelou Club",
}: {
  go: (screen: CustomerScreen) => void;
  view: CustomerView;
  actions?: CustomerActions;
  /** What stands in the QR place of the pass (round 17: required, no sample fallback here). */
  qr: ReactNode;
  /** The note under the steps (round 17: required, no sample text here). */
  qrNote: string;
  /** The pass's accessible name (the preview wrapper passes its own sample label). */
  label?: string;
}) {
  const { state, today, member } = view;
  const line = todayLine(state, view.coffeeUsedToday, view.clubData === "unavailable");
  const pay = paymentAction(view, actions);
  const join = joinAction(view, actions);
  const note = paymentNote(view, actions);
  const copy = useContext(PreviewCopyContext);

  return (
    <div className="on-dark min-h-[calc(100svh-52px)] bg-[var(--roast)] text-[var(--milk)]">
      <header className="mx-auto flex max-w-[1080px] items-center justify-between px-5 py-4 md:px-10 md:py-6">
        <button type="button" onClick={() => go("dashboard")} className="press t-label -my-1 -ml-2 flex items-center gap-1 rounded-full py-3 pr-3 pl-1 text-[var(--milk)]/75 hover:text-[var(--milk)]">
          <Glyph name="back" size={20} />
          Αρχική
        </button>
      </header>

      <main className="mx-auto grid max-w-[1080px] grid-cols-[minmax(0,1fr)] gap-y-12 px-5 pb-16 md:px-10 lg:grid-cols-[400px_minmax(0,1fr)] lg:gap-x-24 lg:pt-4">
        <div className="[perspective:1200px]">
          <article className="pass-arrive pass-sheen relative mx-auto max-w-[400px] overflow-hidden rounded-[22px] bg-[var(--roast-2)] shadow-[0_40px_80px_-40px_rgba(0,0,0,0.75)] ring-1 ring-[var(--brass-soft)]" aria-label={label}>
            <WingLines className="pointer-events-none absolute -top-3 -right-4 h-[148px] text-[var(--brass)] opacity-40" />
            <div className="relative px-6 pt-6 pb-7">
              <div className="flex items-center gap-3">
                <Logo className="h-9 brightness-[1.7] saturate-[0.9]" />
                <span className="t-label border-l border-[var(--brass-soft)] pl-3 text-[var(--brass)]">Club</span>
              </div>
              <p className="t-title mt-16 text-[var(--milk)]">{member.name}</p>
              {member.memberSince && <p className="t-small mt-1 text-[var(--milk)]/55">Μέλος από τις {greekDate(member.memberSince)}</p>}

              <div className="mt-8 border-t border-[var(--brass-soft)] pt-5">
                <p className="t-small text-[var(--milk)]/55">{greekDate(today, true)}</p>
                <p className={line.tone === "ready" ? "t-headline mt-1.5 text-[var(--brass)]" : "t-headline mt-1.5 text-[var(--milk)]"}>{line.headline}</p>
                <p className="t-small mt-2 text-[var(--milk)]/70">{line.detail}</p>
              </div>
            </div>

            <div className="relative bg-[var(--paper)] px-6 pt-7 pb-6 text-[var(--ink)]">
              <span className="absolute top-0 -left-3 h-6 w-6 -translate-y-1/2 rounded-full bg-[var(--roast)]" aria-hidden="true" />
              <span className="absolute top-0 -right-3 h-6 w-6 -translate-y-1/2 rounded-full bg-[var(--roast)]" aria-hidden="true" />
              <span className="absolute inset-x-6 top-0 border-t border-dashed border-[var(--rule-strong)]" aria-hidden="true" />
              <div className="flex justify-center">{qr}</div>
              <div className="t-small t-num mt-5 flex items-baseline justify-between gap-4 text-[var(--ink-2)]">
                <span>{untilLine(view)}</span>
                {state === "active" && <span className="font-semibold text-[var(--ink)]">{daysLeftWord(view.daysLeft)}</span>}
              </div>
            </div>
          </article>
        </div>

        <section className="lg:pt-10">
          <h1 className="t-headline max-w-[16ch] text-[var(--milk)]">Η κάρτα σας, στο ταμείο.</h1>
          <ol className="mt-8 max-w-[44ch] border-t border-[var(--brass-soft)]">
            {["Ανοίξτε την κάρτα όταν φτάσετε στο ταμείο.", "Το προσωπικό σαρώνει τον κωδικό με το tablet.", "Διαλέγετε τον καφέ σας. Τέλος."].map((s, i) => (
              <li key={s} className="t-body grid grid-cols-[2rem_minmax(0,1fr)] border-b border-[var(--brass-soft)] py-4 text-[var(--milk)]/85">
                <span className="t-num text-[var(--brass)]">{i + 1}</span>
                {s}
              </li>
            ))}
          </ol>
          <dl className="t-small mt-10 grid max-w-[44ch] grid-cols-[minmax(0,1fr)_auto] gap-x-6 gap-y-3 [&>dt]:whitespace-nowrap text-[var(--milk)]/60">
            <dt>Ανανέωση</dt>
            <dd className="m-0 text-right text-[var(--milk)]">Χειροκίνητη, {CLUB_RULES.monthlyPriceEur} € / ημερολογιακό μήνα</dd>
            <dt>Καφέδες αυτόν τον μήνα</dt>
            <dd className="t-num m-0 text-right text-[var(--milk)]">{view.clubData === "unavailable" ? "—" : view.visitsThisMonth}</dd>
          </dl>
          {join && (
            <Btn tone="brass" onClick={join.run} className="mt-8 w-full min-h-14 px-8 text-[17px] shadow-[0_6px_18px_rgba(0,0,0,0.35)] ring-1 ring-[var(--brass)] sm:w-auto" data-evc-action="join">
              {join.label}
            </Btn>
          )}
          {pay && (
            <Btn tone="brass" disabled={!pay.run} title={pay.run || view.live ? undefined : copy?.unavailable} onClick={pay.run} className={pay.run ? "mt-8 w-full min-h-14 px-8 text-[17px] sm:w-auto" : "mt-8"} data-evc-action={pay.run ? pay.kind : undefined}>
              {pay.label}
            </Btn>
          )}
          {note && (
            <p role="status" className="t-body mt-8 max-w-[44ch] border-t border-[var(--brass-soft)] pt-5 text-[var(--milk)]/85" data-evc-note="payment">
              {note}
            </p>
          )}
          <PreviewNote dark className="mt-10">{qrNote}</PreviewNote>
        </section>
      </main>
    </div>
  );
}
