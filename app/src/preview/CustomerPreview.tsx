import { useContext, useState, type ReactNode } from "react";
import { BUSINESS_TIME_ZONE, CLUB_RULES, COFFEES, addDays, coffeeName, greekDate } from "./previewData";
import { cx, type CustomerScreen } from "./screens";
import { historyPreparation, preparationLine } from "./coffeeConfig";
import { joinAction, membershipLine, paymentAction, paymentNote, renewalLine, type CustomerActions, type CustomerHistoryItem, type CustomerMembershipState, type CustomerView } from "./customerView";
import { Btn, DefRows, Logo, PreviewNote, StateMark, Steps } from "./ds";
import { ONBOARDING_COPY, ONBOARDING_STEPS } from "./onboardingCopy";
import { PreviewCopyContext } from "./previewCopyContext";

/*
 * UI-02 design preview, Customer Club (visual only, synthetic data). Uses the
 * approved "contemporary coffee club" system; welcome and card are the UI-01.R1
 * reference screens. Nothing here registers, pays, stores or redeems anything.
 *
 * CUSTOMER-01: the member screens render a CustomerView. The design preview
 * (customerSampleScreens.tsx since STAGE-08 round 17, so the real member app
 * never imports the sample) passes the approved sample; the
 * LOCAL-ONLY customer journey preview (src/customerPreview/) passes one built
 * from its simulated server, plus optional actions.
 */

/** What every member-area screen receives. */
export type MemberScreenProps = { view: CustomerView; go: (screen: CustomerScreen) => void; actions?: CustomerActions };

// ---- Shared chrome ------------------------------------------------------------------

/** STAGE-07: `steps` (optional) names the steps of the in-store join flow; the approved default is unchanged. */
export function Onboarding({ step, title, lede, aside, children, go, steps = ONBOARDING_STEPS }: { step: number; title: string; lede: string; aside?: ReactNode; children: ReactNode; go: (s: CustomerScreen) => void; steps?: readonly string[] }) {
  return (
    <div className="min-h-[calc(100svh-52px)] bg-[var(--stone)]">
      <header className="mx-auto flex max-w-[1180px] items-center justify-between px-5 py-5 md:px-10 md:py-7">
        <button type="button" onClick={() => go("welcome")} aria-label="Evangelou Club, αρχική σελίδα" className="press -my-1.5 rounded-[4px] py-1.5">
          <Logo className="h-8 md:h-10" />
        </button>
        <span className="t-small t-num text-[var(--ink-2)]">Βήμα {step + 1} από {steps.length}</span>
      </header>
      <main className="mx-auto grid max-w-[1180px] grid-cols-[minmax(0,1fr)] gap-x-20 gap-y-8 px-5 pb-16 md:px-10 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] lg:pt-6">
        <section>
          <Steps steps={[...steps]} active={step} />
          <h1 className="t-headline mt-10 max-w-[14ch] text-[var(--roast)]">{title}</h1>
          <p className="t-lede mt-4 max-w-[42ch] text-[var(--ink-2)]">{lede}</p>
          {aside}
        </section>
        <section className="lg:pt-2">{children}</section>
      </main>
    </div>
  );
}

const MEMBER_NAV: { id: CustomerScreen; label: string; short: string }[] = [
  { id: "dashboard", label: "Αρχική", short: "Αρχική" },
  { id: "card", label: "Κάρτα", short: "Κάρτα" },
  { id: "today", label: "Σήμερα", short: "Σήμερα" },
  { id: "history", label: "Ιστορικό", short: "Ιστορικό" },
  { id: "profile", label: "Λογαριασμός", short: "Προφίλ" },
];

export function MemberChrome({ screen, go, children }: { screen: CustomerScreen; go: (s: CustomerScreen) => void; children: ReactNode }) {
  return (
    <div className="flex min-h-[calc(100svh-52px)] flex-col bg-[var(--milk)]">
      <header className="border-b border-[var(--rule)] bg-[var(--paper)]">
        <div className="mx-auto flex max-w-[1080px] items-center justify-between gap-8 px-5 py-4 md:px-10 md:py-2.5">
          <Logo className="h-8" />
          <nav aria-label="Μενού μέλους" className="hidden gap-7 md:flex">
            {MEMBER_NAV.map((n) => (
              <button key={n.id} type="button" onClick={() => go(n.id)} aria-current={screen === n.id ? "page" : undefined} className={cx("t-label min-h-11 border-b-2 pt-1.5 pb-1", screen === n.id ? "border-[var(--roast)] text-[var(--ink)]" : "border-transparent text-[var(--ink-3)] hover:text-[var(--ink)]")}>
                {n.label}
              </button>
            ))}
          </nav>
        </div>
      </header>
      <main className="mx-auto w-full max-w-[1080px] flex-1 px-5 pt-8 pb-28 md:px-10 md:pt-12 md:pb-16">{children}</main>
      <nav aria-label="Μενού μέλους" className="sticky bottom-0 z-10 grid grid-cols-[repeat(5,minmax(0,1fr))] border-t border-[var(--rule)] bg-[var(--paper)] md:hidden">
        {MEMBER_NAV.map((n) => (
          <button key={n.id} type="button" onClick={() => go(n.id)} aria-current={screen === n.id ? "page" : undefined} className={cx("t-small relative min-h-14 px-1", screen === n.id ? "font-semibold text-[var(--ink)]" : "text-[var(--ink-3)]")}>
            {screen === n.id && <span className="absolute inset-x-4 top-0 h-0.5 bg-[var(--roast)]" aria-hidden="true" />}
            {n.short}
          </button>
        ))}
      </nav>
    </div>
  );
}

export function PageTitle({ title, lede }: { title: string; lede?: string }) {
  return (
    <header className="mb-8 md:mb-10">
      <h1 className="t-headline text-[var(--roast)]">{title}</h1>
      {lede && <p className="t-lede mt-3 max-w-[48ch] text-[var(--ink-2)]">{lede}</p>}
    </header>
  );
}

// ---- Onboarding -------------------------------------------------------------------------

/**
 * The approved offer. CUSTOMER-01: the customer journey preview passes `onPay`
 * (which only leads to its "payment is not connected" step, never to a payment)
 * and its own secondary link; without them the approved, disabled button stays.
 */
export function Offer({ go, onPay, secondary, steps, live }: { go: (s: CustomerScreen) => void; onPay?: () => void; secondary?: { label: string; onClick: () => void }; steps?: readonly string[]; live?: { note: string } }) {
  const included = ONBOARDING_COPY.offer.included;
  const copy = useContext(PreviewCopyContext);
  return (
    <Onboarding step={2} go={go} title={ONBOARDING_COPY.offer.title} lede={ONBOARDING_COPY.offer.lede} steps={steps}>
      <div className="on-dark rounded-[22px] bg-[var(--roast)] px-6 pt-7 pb-8 text-[var(--milk)] md:px-9 md:pt-9">
        <p className="t-label text-[var(--brass)]">Evangelou Club</p>
        <p className="mt-5 flex items-baseline gap-3">
          <span className="t-display t-num text-[var(--milk)]">{CLUB_RULES.monthlyPriceEur} €</span>
          <span className="t-body text-[var(--milk)]/70">για έναν ημερολογιακό μήνα</span>
        </p>
        <ul className="mt-8 list-none border-t border-[var(--brass-soft)] p-0">
          {included.map((t) => (
            <li key={t} className="t-body border-b border-[var(--brass-soft)] py-3.5 text-[var(--milk)]/85">{t}</li>
          ))}
        </ul>
        <p className="t-small mt-5 max-w-[52ch] text-[var(--milk)]/60">{ONBOARDING_COPY.offer.terms}</p>
        {/* STAGE-08 round 4 (owner's staging run on a phone): the payment is THE action of this step, a full-width
            filled button with a clear edge and a shadow; the account link below it is small and quiet. */}
        <div className="mt-8 flex flex-col items-stretch gap-y-3 sm:max-w-[420px]">
          <Btn tone="brass" disabled={!onPay} title={onPay || live ? undefined : copy?.paymentUnavailable} onClick={onPay} className="w-full min-h-14 px-8 text-[17px] shadow-[0_6px_18px_rgba(0,0,0,0.35)] ring-1 ring-[var(--brass)]" data-evc-action="pay">
            Πληρωμή {CLUB_RULES.monthlyPriceEur} €
          </Btn>
          <button type="button" data-evc-action="secondary" onClick={secondary ? secondary.onClick : () => go("dashboard")} className="t-small self-center py-3 text-[var(--milk)]/60 underline decoration-[var(--brass-soft)] underline-offset-4 hover:text-[var(--milk)]">{secondary ? secondary.label : "Δείτε τον λογαριασμό μέλους"}</button>
        </div>
      </div>
      {live ? (
        <p className="t-small mt-6 max-w-[52ch] text-[var(--ink-2)]">{live.note}</p>
      ) : (
      copy && <PreviewNote className="mt-6">{onPay ? copy.offerNoteOnPay : copy.offerNote}</PreviewNote>
      )}
    </Onboarding>
  );
}

// ---- Member area --------------------------------------------------------------------------

export function entitlement(state: CustomerMembershipState, used: boolean, clubDataUnavailable = false): { headline: string; detail: string; ready: boolean } {
  if (state === "none") return { headline: "Δεν έχετε συνδρομή", detail: "Σε αυτόν τον λογαριασμό δεν υπάρχει συνδρομή στο Club.", ready: false };
  if (state === "unverified") return { headline: "Η συνδρομή δεν επιβεβαιώθηκε", detail: "Δεν μπορούμε να επιβεβαιώσουμε την πληρωμή σας αυτή τη στιγμή. Αν έχετε πληρώσει, μιλήστε με το κατάστημα.", ready: false };
  if (state === "active" && clubDataUnavailable) return { headline: "Η συνδρομή σας είναι ενεργή", detail: "Δείξτε την κάρτα σας στο ταμείο· εκεί ελέγχεται ο σημερινός καφές.", ready: true };
  if (state === "pending") return { headline: "Σε αναμονή πληρωμής", detail: "Ο καφές της ημέρας ξεκινά μόλις επιβεβαιωθεί η πληρωμή.", ready: false };
  if (state === "expired") return { headline: "Η συνδρομή έληξε", detail: "Ανανεώστε για να ξαναβρείτε τον καφέ της ημέρας.", ready: false };
  if (state === "cancelled") return { headline: "Η συνδρομή ακυρώθηκε", detail: "Ο καφές της ημέρας δεν είναι διαθέσιμος. Για ερωτήσεις, μιλήστε με το κατάστημα.", ready: false };
  if (used) return { headline: "Τον απολαύσατε σήμερα", detail: "Ο επόμενος καφές σας περιμένει από τα μεσάνυχτα, ώρα Ελλάδας.", ready: false };
  return { headline: "Ο σημερινός καφές σας περιμένει", detail: "Δείξτε την κάρτα σας στο ταμείο.", ready: true };
}

/**
 * The coffee list is unknown (never «no coffees yet»): the live app has no history answer, or (D23) the Club
 * figures are unknown and no D24 history arrived either. A D24 history answer is authoritative on its own.
 */
function historyUnknown(view: CustomerView): boolean {
  return view.historyData === "unavailable" || (view.clubData === "unavailable" && view.historyData !== "recent");
}

export function Dashboard({ view, go, actions }: MemberScreenProps) {
  const { state, today } = view;
  const e = entitlement(state, view.coffeeUsedToday, view.clubData === "unavailable");
  const recent = view.history.slice(0, 3);
  const pay = paymentAction(view, actions);
  const join = joinAction(view, actions);
  const note = paymentNote(view, actions);
  const copy = useContext(PreviewCopyContext);
  return (
    <div className="grid grid-cols-[minmax(0,1fr)] gap-x-16 gap-y-10 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
      <section>
        <p className="t-body text-[var(--ink-2)]">Καλημέρα,</p>
        <h1 className="t-headline text-[var(--roast)]">{view.member.name.split(" ")[0]}</h1>
        <div className="on-dark mt-8 rounded-[22px] bg-[var(--roast)] px-6 py-7 text-[var(--milk)] md:px-8">
          <p className="t-small text-[var(--milk)]/60">{greekDate(today, true)}</p>
          <p className={cx("t-headline mt-2", e.ready ? "text-[var(--brass)]" : "text-[var(--milk)]")}>{e.headline}</p>
          <p className="t-body mt-2 text-[var(--milk)]/75">{e.detail}</p>
          <div className="mt-7 flex flex-wrap gap-3">
            {join && (
              <Btn tone="brass" onClick={join.run} className="min-h-14 px-8 text-[17px] shadow-[0_6px_18px_rgba(0,0,0,0.35)] ring-1 ring-[var(--brass)]" data-evc-action="join">
                {join.label}
              </Btn>
            )}
            <Btn tone={state === "active" ? "brass" : "ghost-dark"} onClick={() => go("card")}>Άνοιγμα κάρτας</Btn>
            {pay && (
              <Btn tone="brass" disabled={!pay.run} title={pay.run || view.live ? undefined : copy?.unavailable} onClick={pay.run} className={pay.run ? "min-h-14 px-8 text-[17px]" : undefined} data-evc-action={pay.run ? pay.kind : undefined}>
                {pay.label}
              </Btn>
            )}
          </div>
          {note && (
            <p role="status" className="t-body mt-6 max-w-[46ch] border-t border-[var(--milk)]/20 pt-5 text-[var(--milk)]/85" data-evc-note="payment">
              {note}
            </p>
          )}
        </div>
      </section>
      <section className="lg:pt-[4.6rem]">
        <h2 className="t-title border-b border-[var(--rule-strong)] pb-3 text-[var(--ink)]">Η συνδρομή σας</h2>
        <DefRows
          rows={[
            ["Κατάσταση", <StateMark key="s" state={state} />],
            ["Περίοδος", membershipLine(view)],
            ["Ανανέωση", renewalLine(view, `Χειροκίνητη, ${CLUB_RULES.monthlyPriceEur} € για έναν ημερολογιακό μήνα`)],
            ["Αυτόν τον μήνα", view.clubData === "unavailable" ? "Δεν είναι ακόμη διαθέσιμο" : view.visitsThisMonth === 1 ? "1 καφές" : `${view.visitsThisMonth} καφέδες`],
          ]}
        />
        <div className="mt-10 flex items-baseline justify-between border-b border-[var(--rule-strong)] pb-3">
          <h2 className="t-title text-[var(--ink)]">Τελευταίοι καφέδες</h2>
          <button type="button" onClick={() => go("history")} className="t-label -my-3 py-3 text-[var(--bronze-deep)] underline-offset-4 hover:underline">Όλο το ιστορικό</button>
        </div>
        {recent.length === 0 && <p className="t-body py-3.5 text-[var(--ink-2)]">{historyUnknown(view) ? "Το ιστορικό καφέδων δεν είναι ακόμη διαθέσιμο." : "Δεν υπάρχουν ακόμη καφέδες."}</p>}
        <ul className="m-0 list-none p-0">
          {recent.map((r) => (
            <li key={r.date} className="t-body grid grid-cols-[minmax(0,1fr)_auto] gap-4 border-b border-[var(--rule)] py-3.5">
              <span className="text-[var(--ink)]">{r.label ?? coffeeName(r.coffee)}</span>
              <span className="t-small t-num text-[var(--ink-2)]">{greekDate(r.date)}</span>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}

/** Minutes until the next Athens midnight, read once when the screen opens. */
function minutesToAthensMidnight(): number {
  const parts = new Intl.DateTimeFormat("en-GB", { timeZone: BUSINESS_TIME_ZONE, hour: "2-digit", minute: "2-digit", hourCycle: "h23" }).formatToParts(new Date());
  const get = (t: string) => Number(parts.find((p) => p.type === t)?.value ?? 0);
  return 24 * 60 - (get("hour") * 60 + get("minute"));
}

export function Today({ view, go }: MemberScreenProps) {
  const { state, coffeeUsedToday, today } = view;
  const copy = useContext(PreviewCopyContext);
  const [deviceLeft] = useState(minutesToAthensMidnight);
  const left = view.minutesToMidnight ?? deviceLeft;
  const clubDataUnavailable = view.clubData === "unavailable";
  const e = entitlement(state, coffeeUsedToday, clubDataUnavailable);
  const taken = new Set(view.history.map((r) => r.date));
  if (coffeeUsedToday && state === "active") taken.add(today);
  const week = Array.from({ length: 7 }, (_, i) => addDays(today, i - 6));
  return (
    <div className="grid grid-cols-[minmax(0,1fr)] gap-x-16 gap-y-12 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
      <section>
        <p className="t-label text-[var(--ink-2)]">{greekDate(today, true)}</p>
        <h1 className={cx("t-display mt-4 max-w-[12ch]", e.ready ? "text-[var(--roast)]" : "text-[var(--ink-2)]")}>{e.headline}</h1>
        <p className="t-lede mt-5 max-w-[40ch] text-[var(--ink-2)]">{e.detail}</p>
        {state === "active" && !clubDataUnavailable && (
          <p className="t-body t-num mt-6 text-[var(--ink)]">
            {coffeeUsedToday ? "Νέος καφές σε" : "Ισχύει για"} {Math.floor(left / 60)} ώρες και {left % 60} λεπτά, ως τα μεσάνυχτα ώρας Ελλάδας.
          </p>
        )}
        <div className="mt-8">
          <Btn onClick={() => go("card")} className="px-8">Άνοιγμα κάρτας</Btn>
        </div>

        {!clubDataUnavailable && view.historyData === undefined && (<>
        <h2 className="t-title mt-14 border-b border-[var(--rule-strong)] pb-3 text-[var(--ink)]">Οι τελευταίες 7 ημέρες</h2>
        <ol className="m-0 mt-5 grid list-none grid-cols-7 gap-2 p-0">
          {week.map((d) => {
            const has = taken.has(d);
            const label = new Date(`${d}T12:00:00Z`).toLocaleDateString("el-GR", { timeZone: "UTC", weekday: "short" });
            return (
              <li key={d} className="text-center">
                <span className={cx("mx-auto flex aspect-square w-full max-w-12 items-center justify-center rounded-full border", has ? "border-[var(--roast)] bg-[var(--roast)] text-[var(--milk)]" : "border-[var(--rule-strong)] text-[var(--ink-3)]", d === today && "ring-2 ring-[var(--bronze)] ring-offset-2 ring-offset-[var(--milk)]")}>
                  <span className="t-small t-num">{Number(d.slice(8))}</span>
                </span>
                <span className="t-small mt-1.5 block text-[var(--ink-2)]">{label}</span>
                <span className="sr-only">{has ? "καφές" : "χωρίς καφέ"}</span>
              </li>
            );
          })}
        </ol>
        <p className="t-small mt-3 text-[var(--ink-3)]">Γεμάτος κύκλος: ημέρα με καφέ Club. Ο καφές μιας ημέρας δεν μεταφέρεται στην επόμενη.</p>
        </>)}
      </section>

      <section className="lg:pt-10">
        <h2 className="t-title border-b border-[var(--rule-strong)] pb-3 text-[var(--ink)]">Πώς λειτουργεί</h2>
        <ol className="m-0 list-none p-0">
          {[
            "Ένας δωρεάν καφές ανά ημερολογιακή ημέρα, ώρα Ελλάδας.",
            "Δείχνετε την κάρτα σας ή λέτε το κινητό σας στο ταμείο.",
            "Το προσωπικό επιβεβαιώνει την ημέρα σας και διαλέγετε καφέ.",
          ].map((t, i) => (
            <li key={t} className="t-body grid grid-cols-[2rem_minmax(0,1fr)] border-b border-[var(--rule)] py-4 text-[var(--ink)]">
              <span className="t-num text-[var(--bronze-deep)]">{i + 1}</span>
              {t}
            </li>
          ))}
        </ol>
        {/* Round 18 (round-17 review, Low 3): the illustrative list is the design preview's only; the live app shows nothing here. */}
        {!view.live && copy && (<>
        <h2 className="t-title mt-10 text-[var(--ink)]">{copy.coffeesTitle}</h2>
        <p className="t-body mt-2 text-[var(--ink-2)]">{copy.coffeesLine(COFFEES.map((c) => c.label).join(", "))}</p>
        </>)}
      </section>
    </div>
  );
}

export function History({ view }: MemberScreenProps) {
  const { state } = view;
  const copy = useContext(PreviewCopyContext);
  const items = view.history;
  const months = new Map<string, CustomerHistoryItem[]>();
  for (const r of items) months.set(r.date.slice(0, 7), [...(months.get(r.date.slice(0, 7)) ?? []), r]);
  const monthName = (ym: string) => new Date(`${ym}-15T12:00:00Z`).toLocaleDateString("el-GR", { timeZone: "UTC", month: "long", year: "numeric" });
  return (
    <div className="max-w-[720px]">
      <PageTitle title="Οι καφέδες σας" lede={view.historyData === "recent" ? "Οι τελευταίοι σας καφέδες Club, όπως καταχωρήθηκαν στο ταμείο." : state === "active" ? "Κάθε καφές Club των τελευταίων τριών εβδομάδων." : "Το ιστορικό μένει διαθέσιμο ακόμη κι όταν η συνδρομή δεν είναι ενεργή."} />
      {items.length === 0 && (
        <div className="border-y border-[var(--rule)] py-12 text-center">
          <p className="t-title text-[var(--ink)]">{historyUnknown(view) ? "Το ιστορικό δεν είναι ακόμη διαθέσιμο" : "Δεν υπάρχουν ακόμη καφέδες"}</p>
          <p className="t-body mt-2 text-[var(--ink-2)]">{historyUnknown(view) ? "Οι καφέδες σας θα εμφανίζονται εδώ μόλις συνδεθεί το ταμείο." : "Ο πρώτος σας καφές θα εμφανιστεί εδώ μόλις τον πάρετε στο ταμείο."}</p>
        </div>
      )}
      {[...months.entries()].map(([ym, rows]) => (
        <section key={ym} className="mb-10">
          <h2 className="flex items-baseline justify-between border-b border-[var(--rule-strong)] pb-3">
            <span className="t-title text-[var(--ink)] first-letter:uppercase">{monthName(ym)}</span>
            {view.historyData !== "recent" && <span className="t-small t-num text-[var(--ink-2)]">{rows.length} καφέδες</span>}
          </h2>
          <ol className="relative m-0 list-none p-0 before:absolute before:top-0 before:bottom-0 before:left-[1.45rem] before:w-px before:bg-[var(--rule-strong)]">
            {rows.map((r) => {
              const day = new Date(`${r.date}T12:00:00Z`).toLocaleDateString("el-GR", { timeZone: "UTC", weekday: "long" });
              return (
                <li key={r.date} className="relative grid grid-cols-[3rem_minmax(0,1fr)_auto] items-center gap-4 py-3.5">
                  <span className="relative z-[1] mx-auto flex h-11 w-11 items-center justify-center rounded-full border border-[var(--rule-strong)] bg-[var(--milk)]">
                    <span className="t-body t-num font-semibold text-[var(--ink)]">{Number(r.date.slice(8))}</span>
                  </span>
                  <span className="min-w-0">
                    <span className="t-body block font-medium text-[var(--ink)]">{r.label ?? coffeeName(r.coffee)}</span>
                    <span className="t-small block text-[var(--ink-2)]">{r.details ? r.details.join(" · ") : preparationLine(r.preparation ?? historyPreparation(view.member, r.coffee, r.date))}</span>
                    <span className="t-small block text-[var(--ink-3)] first-letter:uppercase">{day}</span>
                  </span>
                  <span className="t-small t-num text-[var(--ink-3)]">{r.time}</span>
                </li>
              );
            })}
          </ol>
        </section>
      ))}
      {!view.live && copy && <PreviewNote>{copy.historyNote}</PreviewNote>}
    </div>
  );
}

export function Profile({ view, actions }: MemberScreenProps) {
  const { state, member } = view;
  // Early renewal of an active membership exists only in the design preview: the live app never offers it (D27).
  const renew = !view.live && state === "active" && !view.pendingRenewal ? actions?.renew : undefined;
  const copy = useContext(PreviewCopyContext);
  return (
    <div className="grid grid-cols-[minmax(0,1fr)] gap-x-16 gap-y-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
      <section>
        <h1 className="t-headline text-[var(--roast)]">{member.name}</h1>
        {member.memberSince && <p className="t-body mt-2 text-[var(--ink-2)]">Μέλος από τις {greekDate(member.memberSince)}</p>}
        <div className="mt-8 flex flex-wrap gap-3">
          {!view.live && (
            <Btn tone="line" disabled title={copy?.unavailable}>
              Επεξεργασία στοιχείων
            </Btn>
          )}
          <Btn tone="line" disabled={!actions?.signOut} title={actions?.signOut || view.live ? undefined : copy?.unavailable} onClick={actions?.signOut}>Αποσύνδεση</Btn>
        </div>
      </section>
      <section>
        <h2 className="t-title border-b border-[var(--rule-strong)] pb-3 text-[var(--ink)]">Στοιχεία</h2>
        <DefRows
          rows={[
            ["Κινητό", <span key="p" className="t-num">{member.phoneMasked || "Δεν δόθηκε"}</span>],
            ["E-mail", member.email || "Δεν δόθηκε"],
            ["Συνήθως πίνει", view.usual ? coffeeName(view.usual) : "Δεν υπάρχει ακόμη αρκετό ιστορικό"],
          ]}
        />
        <h2 className="t-title mt-10 border-b border-[var(--rule-strong)] pb-3 text-[var(--ink)]">Συνδρομή</h2>
        <DefRows
          rows={[
            ["Κατάσταση", <StateMark key="s" state={state} />],
            ["Περίοδος", membershipLine(view)],
            ["Ανανέωση", renewalLine(view, "Χειροκίνητη, χωρίς αυτόματη χρέωση")],
          ]}
        />
        {renew && (
          <div className="mt-6">
            <Btn tone="line" onClick={renew}>Ανανέωση τώρα, {CLUB_RULES.monthlyPriceEur} €</Btn>
            <p className="t-small mt-2 text-[var(--ink-3)]">Ο νέος μήνας ξεκινά από τη λήξη του τρέχοντος, χωρίς να χάνετε μέρες.</p>
          </div>
        )}
        {!view.live && (<>
        <h2 className="t-title mt-10 border-b border-[var(--rule-strong)] pb-3 text-[var(--ink)]">Επικοινωνία</h2>
        <DefRows rows={[["Newsletter", member.newsletter ? "Ναι, ξεχωριστή συναίνεση από τη συνδρομή" : "Όχι"]]} />
        {copy && <PreviewNote className="mt-8">{copy.profileNote}</PreviewNote>}
        </>)}
      </section>
    </div>
  );
}
