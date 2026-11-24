import { CLUB_RULES, COFFEES } from "./previewData";
import type { CustomerScreen } from "./screens";
import { Btn, Logo, PreviewNote } from "./ds";

/*
 * UI-01.R1 signature screen, Customer Club: the welcome page of the DESIGN PREVIEW and the local journey preview.
 * STAGE-08 round 18 (round-17 review, Low 3): moved out of CustomerSignature.tsx (which the real member app imports
 * for the pass) because it carries the preview's own words (the illustrative coffee list, «Προεπισκόπηση σχεδίου»);
 * a preview-only module (scripts/sample-guard.mjs SAMPLE_ONLY_MODULES). Unchanged otherwise.
 */
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
