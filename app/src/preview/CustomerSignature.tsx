import { CLUB_RULES, CUSTOMER, COFFEES, addDays, greekDate, type PreviewMembershipState } from "./previewData";
import type { CustomerScreen } from "./screens";
import { Btn, Glyph, Logo, PassQr, PreviewNote, WingLines } from "./ds";

/* UI-01.R1 signature screens, Customer Club: welcome and membership pass. */

type Props = {
  go: (screen: CustomerScreen) => void;
  state: PreviewMembershipState;
  coffeeUsedToday: boolean;
  today: string;
};

export function ClubWelcome({ go }: { go: (screen: CustomerScreen) => void }) {
  const terms: { figure: string; title: string; body: string }[] = [
    { figure: `${CLUB_RULES.monthlyPriceEur} €`, title: "τον μήνα", body: "Η συνδρομή ισχύει έναν ημερολογιακό μήνα και λήγει με ώρα Ελλάδας. Δεν υπάρχει αυτόματη χρέωση· ανανεώνετε μόνο αν θέλετε." },
    { figure: String(CLUB_RULES.coffeesPerAthensDay), title: "καφές κάθε μέρα", body: "Ο καφές της ημέρας ανανεώνεται στις 12 τα μεσάνυχτα, ώρα Ελλάδας." },
    // The eligible coffee list is not owner-approved yet: shown as an example only.
    { figure: String(COFFEES.length), title: "ενδεικτικές επιλογές", body: `Για παράδειγμα: ${COFFEES.map((c) => c.label).join(", ")}. Ο τελικός κατάλογος δεν έχει οριστικοποιηθεί.` },
  ];
  return (
    <div className="min-h-[calc(100svh-52px)] bg-[var(--stone)]">
      <header className="mx-auto flex max-w-[1280px] items-center justify-between px-5 py-5 md:px-10 md:py-7">
        <Logo className="h-8 md:h-10" />
        <button type="button" onClick={() => go("card")} className="press t-label -my-1 rounded-full px-1 py-3 text-[var(--ink-2)] underline decoration-[var(--rule-strong)] underline-offset-[6px] hover:text-[var(--ink)] hover:decoration-[var(--ink)]">
          Έχω ήδη κάρτα
        </button>
      </header>

      <main className="mx-auto grid max-w-[1280px] grid-cols-[minmax(0,1fr)] gap-y-8 px-5 pb-14 md:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)] md:gap-x-10 md:px-10 lg:grid-cols-[minmax(0,1.08fr)_minmax(0,0.92fr)] lg:gap-x-20 lg:pb-20">
        <figure className="fade-in m-0 md:order-2 md:pt-8 lg:pt-2">
          <div className="relative overflow-hidden rounded-[2px] bg-[var(--roast)]">
            <img
              src="/images/products/freddo-espresso.webp"
              alt="Δύο ποτήρια espresso κάτω από τη μηχανή, την ώρα της εκχύλισης"
              className="aspect-[3/2] w-full object-cover object-[50%_42%] sm:aspect-[16/10] md:aspect-[3/4] md:object-[38%_50%] lg:aspect-[4/5]"
            />
          </div>
          <figcaption className="t-small mt-2.5 flex flex-col justify-between gap-x-4 gap-y-0.5 text-[var(--ink-3)] sm:flex-row md:flex-col lg:flex-row">
            <span>Η μέρα ξεκινά στο ταμείο.</span>
            <span>Φωτογραφία: Kei Scampa, Pexels</span>
          </figcaption>
        </figure>

        <section className="md:order-1 lg:pt-6">
          <p className="t-label text-[var(--bronze-deep)]">Evangelou Club</p>
          <h1 className="t-display mt-4 max-w-[11ch] text-[var(--roast)]">
            Ένας καφές.
            <br />
            Κάθε μέρα.
          </h1>
          <p className="t-lede mt-6 max-w-[46ch] text-[var(--ink-2)]">
            Η μηνιαία συνδρομή του ζαχαροπλαστείου Ευαγγέλου. Δείχνετε την κάρτα σας στο ταμείο και ο καφές της ημέρας είναι δικός μας.
          </p>

          <div className="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
            <Btn onClick={() => go("register")} className="px-8">Γίνετε μέλος</Btn>
            <button type="button" onClick={() => go("offer")} className="press t-label -my-1 py-3 text-[var(--ink-2)] underline decoration-[var(--rule-strong)] underline-offset-[6px] hover:text-[var(--ink)] hover:decoration-[var(--ink)]">
              Δείτε τους όρους
            </button>
          </div>

          <dl className="mt-12 border-t border-[var(--rule-strong)]">
            {terms.map((t) => (
              <div key={t.title} className="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-x-5 border-b border-[var(--rule-strong)] py-5 sm:grid-cols-[7rem_minmax(0,1fr)]">
                <dt className="t-figure text-[var(--roast)]">{t.figure}</dt>
                <dd className="m-0 self-center">
                  <span className="t-title block text-[var(--roast)]">{t.title}</span>
                  <span className="t-small mt-1 block max-w-[46ch] text-[var(--ink-2)]">{t.body}</span>
                </dd>
              </div>
            ))}
          </dl>

          <PreviewNote className="mt-8">Προεπισκόπηση σχεδίου: η εγγραφή και η πληρωμή δεν λειτουργούν και κανένα στοιχείο δεν αποθηκεύεται.</PreviewNote>
        </section>
      </main>
    </div>
  );
}

function paidUntil(state: PreviewMembershipState, today: string): string | null {
  if (state === "pending") return null;
  return addDays(today, state === "active" ? CUSTOMER.paidUntilOffsetDays : -3);
}

function todayLine(state: PreviewMembershipState, used: boolean): { headline: string; detail: string; tone: "ready" | "done" | "blocked" } {
  if (state === "pending") return { headline: "Σε αναμονή πληρωμής", detail: "Η κάρτα ενεργοποιείται μόλις επιβεβαιωθεί η πληρωμή.", tone: "blocked" };
  if (state === "expired") return { headline: "Η συνδρομή έληξε", detail: "Ανανεώστε για να ξαναβρείτε τον καφέ της ημέρας.", tone: "blocked" };
  if (used) return { headline: "Τον απολαύσατε σήμερα", detail: "Ο επόμενος καφές σας περιμένει από τα μεσάνυχτα.", tone: "done" };
  return { headline: "Ο σημερινός καφές σας περιμένει", detail: "Δείξτε αυτή την κάρτα στο ταμείο.", tone: "ready" };
}

export function MemberPass({ go, state, coffeeUsedToday, today }: Props) {
  const until = paidUntil(state, today);
  const line = todayLine(state, coffeeUsedToday);
  const daysLeft = state === "active" ? CUSTOMER.paidUntilOffsetDays : 0;

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
          <article className="pass-arrive pass-sheen relative mx-auto max-w-[400px] overflow-hidden rounded-[22px] bg-[var(--roast-2)] shadow-[0_40px_80px_-40px_rgba(0,0,0,0.75)] ring-1 ring-[var(--brass-soft)]" aria-label="Κάρτα μέλους Evangelou Club (δείγμα)">
            <WingLines className="pointer-events-none absolute -top-3 -right-4 h-[148px] text-[var(--brass)] opacity-40" />
            <div className="relative px-6 pt-6 pb-7">
              <div className="flex items-center gap-3">
                <Logo className="h-9 brightness-[1.7] saturate-[0.9]" />
                <span className="t-label border-l border-[var(--brass-soft)] pl-3 text-[var(--brass)]">Club</span>
              </div>
              <p className="t-title mt-16 text-[var(--milk)]">{CUSTOMER.name}</p>
              <p className="t-small mt-1 text-[var(--milk)]/55">Μέλος από τις {greekDate(CUSTOMER.memberSince)}</p>

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
              <div className="flex justify-center">
                <PassQr seed={11} size={188} muted={state !== "active"} />
              </div>
              <div className="t-small t-num mt-5 flex items-baseline justify-between gap-4 text-[var(--ink-2)]">
                <span>{until ? (state === "active" ? `Ισχύει έως ${greekDate(until)}` : `Έληξε ${greekDate(until)}`) : "Δεν έχει ενεργοποιηθεί"}</span>
                {state === "active" && <span className="font-semibold text-[var(--ink)]">{daysLeft} ημέρες</span>}
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
            <dd className="t-num m-0 text-right text-[var(--milk)]">{CUSTOMER.visitsThisMonth}</dd>
          </dl>
          {state !== "active" && (
            <Btn tone="brass" disabled title="Μη διαθέσιμο στην προεπισκόπηση" className="mt-8">
              {state === "expired" ? `Ανανέωση ${CLUB_RULES.monthlyPriceEur} €` : "Ολοκλήρωση πληρωμής"}
            </Btn>
          )}
          <PreviewNote dark className="mt-10">Ο κωδικός είναι δείγμα: δεν αντιστοιχεί σε μέλος, δεν σαρώνεται και δεν εξαργυρώνεται.</PreviewNote>
        </section>
      </main>
    </div>
  );
}
