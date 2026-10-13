import { useState, type ReactNode } from "react";
import { BUSINESS_TIME_ZONE, CLUB_RULES, COFFEES, CUSTOMER, addDays, coffeeName, greekDate, memberHistory, type PreviewMembershipState, type PreviewRedemption } from "./previewData";
import { cx, type CustomerScreen } from "./screens";
import { ClubWelcome, MemberPass } from "./CustomerSignature";
import { Btn, ConsentRow, DefRows, Field, Logo, PreviewNote, StateMark, Steps } from "./ds";

/*
 * UI-02 design preview, Customer Club (visual only, synthetic data). Uses the
 * approved "contemporary coffee club" system; welcome and card are the UI-01.R1
 * reference screens. Nothing here registers, pays, stores or redeems anything.
 */

type Props = {
  screen: CustomerScreen;
  go: (screen: CustomerScreen) => void;
  state: PreviewMembershipState;
  coffeeUsedToday: boolean;
  today: string;
};

const ONBOARDING_STEPS = ["Στοιχεία", "Ενημερώσεις", "Συνδρομή"];

export function CustomerPreview(props: Props) {
  const { screen, go } = props;
  if (screen === "welcome") return <ClubWelcome go={go} />;
  if (screen === "card") return <MemberPass {...props} />;
  if (screen === "register") return <Register go={go} />;
  if (screen === "newsletter") return <Newsletter go={go} />;
  if (screen === "offer") return <Offer go={go} />;
  return (
    <MemberChrome screen={screen} go={go}>
      {screen === "dashboard" && <Dashboard {...props} />}
      {screen === "states" && <States today={props.today} />}
      {screen === "today" && <Today {...props} />}
      {screen === "history" && <History today={props.today} state={props.state} />}
      {screen === "profile" && <Profile {...props} />}
    </MemberChrome>
  );
}

// ---- Shared chrome ------------------------------------------------------------------

function paidUntil(state: PreviewMembershipState, today: string): string | null {
  if (state === "pending") return null;
  return addDays(today, state === "active" ? CUSTOMER.paidUntilOffsetDays : -3);
}

function Onboarding({ step, title, lede, aside, children, go }: { step: number; title: string; lede: string; aside?: ReactNode; children: ReactNode; go: (s: CustomerScreen) => void }) {
  return (
    <div className="min-h-[calc(100svh-52px)] bg-[var(--stone)]">
      <header className="mx-auto flex max-w-[1180px] items-center justify-between px-5 py-5 md:px-10 md:py-7">
        <button type="button" onClick={() => go("welcome")} aria-label="Evangelou Club, αρχική σελίδα" className="press -my-1.5 rounded-[4px] py-1.5">
          <Logo className="h-8 md:h-10" />
        </button>
        <span className="t-small t-num text-[var(--ink-2)]">Βήμα {step + 1} από {ONBOARDING_STEPS.length}</span>
      </header>
      <main className="mx-auto grid max-w-[1180px] grid-cols-[minmax(0,1fr)] gap-x-20 gap-y-8 px-5 pb-16 md:px-10 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] lg:pt-6">
        <section>
          <Steps steps={ONBOARDING_STEPS} active={step} />
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

function MemberChrome({ screen, go, children }: { screen: CustomerScreen; go: (s: CustomerScreen) => void; children: ReactNode }) {
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

function PageTitle({ title, lede }: { title: string; lede?: string }) {
  return (
    <header className="mb-8 md:mb-10">
      <h1 className="t-headline text-[var(--roast)]">{title}</h1>
      {lede && <p className="t-lede mt-3 max-w-[48ch] text-[var(--ink-2)]">{lede}</p>}
    </header>
  );
}

// ---- Onboarding -------------------------------------------------------------------------

function Register({ go }: { go: (s: CustomerScreen) => void }) {
  return (
    <Onboarding step={0} go={go} title="Τα στοιχεία σας" lede="Μόνο όσα χρειάζεται το ταμείο για να σας αναγνωρίζει. Το κινητό βοηθά όταν δεν έχετε την κάρτα μαζί σας.">
      <div className="grid gap-6">
        <Field label="Ονοματεπώνυμο" value={CUSTOMER.name} />
        <Field label="Κινητό τηλέφωνο" value={CUSTOMER.phoneMasked} type="tel" hint="Για αναζήτηση στο ταμείο. Δεν το χρησιμοποιούμε για διαφημίσεις." />
        <Field label="E-mail" value={CUSTOMER.email} type="email" hint="Για την επιβεβαίωση της συνδρομής και την απόδειξη πληρωμής." />
      </div>
      <div className="mt-9 flex flex-wrap items-center gap-x-6 gap-y-4">
        <Btn onClick={() => go("newsletter")} className="px-8">Συνέχεια</Btn>
        <button type="button" onClick={() => go("welcome")} className="t-label -my-1 py-3 text-[var(--ink-2)] underline decoration-[var(--rule-strong)] underline-offset-[6px] hover:text-[var(--ink)]">Πίσω</button>
      </div>
      <PreviewNote className="mt-8">Προεπισκόπηση σχεδίου: τα πεδία είναι δείγματα και δεν δημιουργείται λογαριασμός.</PreviewNote>
    </Onboarding>
  );
}

function Newsletter({ go }: { go: (s: CustomerScreen) => void }) {
  const [terms, setTerms] = useState(false);
  const [news, setNews] = useState(false);
  return (
    <Onboarding step={1} go={go} title="Εσείς επιλέγετε" lede="Δύο ξεχωριστές επιλογές. Το newsletter δεν είναι συνδρομή στο Club και δεν δίνει δωρεάν καφέ.">
      <div className="border-t border-[var(--rule-strong)]">
        <ConsentRow checked={terms} onChange={setTerms} required title="Όροι Club και απόρρητο" body="Αποδέχομαι τους όρους του Evangelou Club και την πολιτική απορρήτου." />
        <ConsentRow checked={news} onChange={setNews} title="Newsletter" body="Θέλω να λαμβάνω νέα, προσφορές και εκδηλώσεις με e-mail. Μπορώ να διαγραφώ οποιαδήποτε στιγμή, χωρίς να επηρεαστεί η συνδρομή μου." />
      </div>
      <p className="t-small mt-4 text-[var(--ink-2)]" aria-live="polite">
        {terms ? (news ? "Όροι αποδεκτοί. Newsletter: ναι." : "Όροι αποδεκτοί. Newsletter: όχι.") : "Για να συνεχίσετε χρειάζεται η αποδοχή των όρων."}
      </p>
      <div className="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
        <Btn onClick={() => go("offer")} disabled={!terms} className="px-8">Συνέχεια</Btn>
        <button type="button" onClick={() => go("register")} className="t-label -my-1 py-3 text-[var(--ink-2)] underline decoration-[var(--rule-strong)] underline-offset-[6px] hover:text-[var(--ink)]">Πίσω</button>
      </div>
      <PreviewNote className="mt-8">Οι επιλογές ισχύουν μόνο σε αυτή τη σελίδα· καμία συναίνεση δεν αποθηκεύεται.</PreviewNote>
    </Onboarding>
  );
}

function Offer({ go }: { go: (s: CustomerScreen) => void }) {
  const included = [
    "Ένας δωρεάν καφές κάθε ημερολογιακή ημέρα, ώρα Ελλάδας.",
    "Ψηφιακή κάρτα μέλους για το ταμείο.",
    "Το ιστορικό των καφέδων σας στον λογαριασμό σας.",
    "Χειροκίνητη ανανέωση, χωρίς αυτόματη χρέωση.",
  ];
  return (
    <Onboarding step={2} go={go} title="Η συνδρομή σας" lede="Μία τιμή, ένας μήνας, ένας καφές την ημέρα. Ανανεώνετε μόνο όταν το θέλετε.">
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
        <p className="t-small mt-5 max-w-[52ch] text-[var(--milk)]/60">Η συνδρομή διαρκεί έναν ημερολογιακό μήνα από την επιβεβαίωση της πληρωμής και λήγει με ώρα Ελλάδας. Στους πρώτους μήνες η ανανέωση γίνεται χειροκίνητα.</p>
        <div className="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
          <Btn tone="brass" disabled title="Η πληρωμή δεν είναι διαθέσιμη στην προεπισκόπηση" className="px-8">Πληρωμή {CLUB_RULES.monthlyPriceEur} €</Btn>
          <button type="button" onClick={() => go("dashboard")} className="t-label -my-1 py-3 text-[var(--milk)]/75 underline decoration-[var(--brass-soft)] underline-offset-[6px] hover:text-[var(--milk)]">Δείτε τον λογαριασμό μέλους</button>
        </div>
      </div>
      <PreviewNote className="mt-6">Δεν υπάρχει πληρωμή στην προεπισκόπηση: το κουμπί είναι ανενεργό και καμία χρέωση δεν γίνεται.</PreviewNote>
    </Onboarding>
  );
}

// ---- Member area --------------------------------------------------------------------------

function entitlement(state: PreviewMembershipState, used: boolean): { headline: string; detail: string; ready: boolean } {
  if (state === "pending") return { headline: "Σε αναμονή πληρωμής", detail: "Ο καφές της ημέρας ξεκινά μόλις επιβεβαιωθεί η πληρωμή.", ready: false };
  if (state === "expired") return { headline: "Η συνδρομή έληξε", detail: "Ανανεώστε για να ξαναβρείτε τον καφέ της ημέρας.", ready: false };
  if (used) return { headline: "Τον απολαύσατε σήμερα", detail: "Ο επόμενος καφές σας περιμένει από τα μεσάνυχτα, ώρα Ελλάδας.", ready: false };
  return { headline: "Ο σημερινός καφές σας περιμένει", detail: "Δείξτε την κάρτα σας στο ταμείο.", ready: true };
}

function membershipLine(state: PreviewMembershipState, today: string): string {
  const until = paidUntil(state, today);
  if (state === "active") return `Ενεργή έως ${greekDate(until!)}`;
  if (state === "expired") return `Έληξε στις ${greekDate(until!)}`;
  return "Αναμονή επιβεβαίωσης πληρωμής";
}

function Dashboard({ state, coffeeUsedToday, today, go }: Props) {
  const e = entitlement(state, coffeeUsedToday);
  const recent = memberHistory(CUSTOMER.id, today).slice(0, 3);
  return (
    <div className="grid grid-cols-[minmax(0,1fr)] gap-x-16 gap-y-10 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
      <section>
        <p className="t-body text-[var(--ink-2)]">Καλημέρα,</p>
        <h1 className="t-headline text-[var(--roast)]">{CUSTOMER.name.split(" ")[0]}</h1>
        <div className="on-dark mt-8 rounded-[22px] bg-[var(--roast)] px-6 py-7 text-[var(--milk)] md:px-8">
          <p className="t-small text-[var(--milk)]/60">{greekDate(today, true)}</p>
          <p className={cx("t-headline mt-2", e.ready ? "text-[var(--brass)]" : "text-[var(--milk)]")}>{e.headline}</p>
          <p className="t-body mt-2 text-[var(--milk)]/75">{e.detail}</p>
          <div className="mt-7 flex flex-wrap gap-3">
            <Btn tone={state === "active" ? "brass" : "ghost-dark"} onClick={() => go("card")}>Άνοιγμα κάρτας</Btn>
            {state !== "active" && <Btn tone="brass" disabled title="Μη διαθέσιμο στην προεπισκόπηση">{state === "expired" ? `Ανανέωση ${CLUB_RULES.monthlyPriceEur} €` : "Ολοκλήρωση πληρωμής"}</Btn>}
          </div>
        </div>
      </section>
      <section className="lg:pt-[4.6rem]">
        <h2 className="t-title border-b border-[var(--rule-strong)] pb-3 text-[var(--ink)]">Η συνδρομή σας</h2>
        <DefRows
          rows={[
            ["Κατάσταση", <StateMark key="s" state={state} />],
            ["Περίοδος", membershipLine(state, today)],
            ["Ανανέωση", `Χειροκίνητη, ${CLUB_RULES.monthlyPriceEur} € για έναν ημερολογιακό μήνα`],
            ["Αυτόν τον μήνα", `${CUSTOMER.visitsThisMonth} καφέδες`],
          ]}
        />
        <div className="mt-10 flex items-baseline justify-between border-b border-[var(--rule-strong)] pb-3">
          <h2 className="t-title text-[var(--ink)]">Τελευταίοι καφέδες</h2>
          <button type="button" onClick={() => go("history")} className="t-label -my-3 py-3 text-[var(--bronze-deep)] underline-offset-4 hover:underline">Όλο το ιστορικό</button>
        </div>
        <ul className="m-0 list-none p-0">
          {recent.map((r) => (
            <li key={r.date} className="t-body grid grid-cols-[minmax(0,1fr)_auto] gap-4 border-b border-[var(--rule)] py-3.5">
              <span className="text-[var(--ink)]">{coffeeName(r.coffee)}</span>
              <span className="t-small t-num text-[var(--ink-2)]">{greekDate(r.date)}</span>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}

function States({ today }: { today: string }) {
  const specimens: { state: PreviewMembershipState; used: boolean; caption: string }[] = [
    { state: "active", used: false, caption: "Ενεργή, ο καφές της ημέρας είναι διαθέσιμος" },
    { state: "active", used: true, caption: "Ενεργή, ο καφές της ημέρας δόθηκε" },
    { state: "pending", used: false, caption: "Η πληρωμή δεν έχει επιβεβαιωθεί" },
    { state: "expired", used: false, caption: "Η συνδρομή έληξε" },
  ];
  return (
    <div>
      <PageTitle title="Η συνδρομή σε κάθε κατάσταση" lede="Τι βλέπει ένα μέλος στην αρχική του, ανάλογα με την κατάσταση της συνδρομής και τον σημερινό καφέ." />
      <div className="grid grid-cols-[minmax(0,1fr)] gap-x-6 gap-y-8 sm:grid-cols-2 lg:grid-cols-4">
        {specimens.map((s) => {
          const e = entitlement(s.state, s.used);
          return (
            <figure key={s.caption} className="m-0">
              <div className="on-dark flex min-h-[220px] flex-col rounded-[18px] bg-[var(--roast)] p-5 text-[var(--milk)]">
                <StateMark state={s.state} className="t-small text-[var(--milk)]/80" />
                <p className={cx("t-title mt-auto pt-8", e.ready ? "text-[var(--brass)]" : "text-[var(--milk)]")}>{e.headline}</p>
                <p className="t-small mt-2 text-[var(--milk)]/65">{e.detail}</p>
              </div>
              <figcaption className="t-small mt-3 text-[var(--ink-2)]">
                <span className="block font-semibold text-[var(--ink)]">{s.caption}</span>
                {membershipLine(s.state, today)}
              </figcaption>
            </figure>
          );
        })}
      </div>
      <PreviewNote className="mt-10">Οι τέσσερις καταστάσεις είναι δείγματα σχεδίου. Η ανανέωση και η πληρωμή δεν λειτουργούν στην προεπισκόπηση.</PreviewNote>
    </div>
  );
}

/** Minutes until the next Athens midnight, read once when the screen opens. */
function minutesToAthensMidnight(): number {
  const parts = new Intl.DateTimeFormat("en-GB", { timeZone: BUSINESS_TIME_ZONE, hour: "2-digit", minute: "2-digit", hourCycle: "h23" }).formatToParts(new Date());
  const get = (t: string) => Number(parts.find((p) => p.type === t)?.value ?? 0);
  return 24 * 60 - (get("hour") * 60 + get("minute"));
}

function Today({ state, coffeeUsedToday, today, go }: Props) {
  const [left] = useState(minutesToAthensMidnight);
  const e = entitlement(state, coffeeUsedToday);
  const taken = new Set(memberHistory(CUSTOMER.id, today).map((r) => r.date));
  if (coffeeUsedToday && state === "active") taken.add(today);
  const week = Array.from({ length: 7 }, (_, i) => addDays(today, i - 6));
  return (
    <div className="grid grid-cols-[minmax(0,1fr)] gap-x-16 gap-y-12 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
      <section>
        <p className="t-label text-[var(--ink-2)]">{greekDate(today, true)}</p>
        <h1 className={cx("t-display mt-4 max-w-[12ch]", e.ready ? "text-[var(--roast)]" : "text-[var(--ink-2)]")}>{e.headline}</h1>
        <p className="t-lede mt-5 max-w-[40ch] text-[var(--ink-2)]">{e.detail}</p>
        {state === "active" && (
          <p className="t-body t-num mt-6 text-[var(--ink)]">
            {coffeeUsedToday ? "Νέος καφές σε" : "Ισχύει για"} {Math.floor(left / 60)} ώρες και {left % 60} λεπτά, ως τα μεσάνυχτα ώρας Ελλάδας.
          </p>
        )}
        <div className="mt-8">
          <Btn onClick={() => go("card")} className="px-8">Άνοιγμα κάρτας</Btn>
        </div>

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
        <h2 className="t-title mt-10 text-[var(--ink)]">Ενδεικτικές επιλογές</h2>
        <p className="t-body mt-2 text-[var(--ink-2)]">Για παράδειγμα: {COFFEES.map((c) => c.label).join(", ")}. Ο τελικός κατάλογος δεν έχει οριστικοποιηθεί.</p>
      </section>
    </div>
  );
}

function History({ today, state }: { today: string; state: PreviewMembershipState }) {
  const items = memberHistory(CUSTOMER.id, today);
  const months = new Map<string, PreviewRedemption[]>();
  for (const r of items) months.set(r.date.slice(0, 7), [...(months.get(r.date.slice(0, 7)) ?? []), r]);
  const monthName = (ym: string) => new Date(`${ym}-15T12:00:00Z`).toLocaleDateString("el-GR", { timeZone: "UTC", month: "long", year: "numeric" });
  return (
    <div className="max-w-[720px]">
      <PageTitle title="Οι καφέδες σας" lede={state === "active" ? "Κάθε καφές Club των τελευταίων τριών εβδομάδων." : "Το ιστορικό μένει διαθέσιμο ακόμη κι όταν η συνδρομή δεν είναι ενεργή."} />
      {items.length === 0 && (
        <div className="border-y border-[var(--rule)] py-12 text-center">
          <p className="t-title text-[var(--ink)]">Δεν υπάρχουν ακόμη καφέδες</p>
          <p className="t-body mt-2 text-[var(--ink-2)]">Ο πρώτος σας καφές θα εμφανιστεί εδώ μόλις τον πάρετε στο ταμείο.</p>
        </div>
      )}
      {[...months.entries()].map(([ym, rows]) => (
        <section key={ym} className="mb-10">
          <h2 className="flex items-baseline justify-between border-b border-[var(--rule-strong)] pb-3">
            <span className="t-title text-[var(--ink)] first-letter:uppercase">{monthName(ym)}</span>
            <span className="t-small t-num text-[var(--ink-2)]">{rows.length} καφέδες</span>
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
                    <span className="t-body block font-medium text-[var(--ink)]">{coffeeName(r.coffee)}</span>
                    <span className="t-small block text-[var(--ink-2)] first-letter:uppercase">{day}</span>
                  </span>
                  <span className="t-small t-num text-[var(--ink-3)]">{r.time}</span>
                </li>
              );
            })}
          </ol>
        </section>
      ))}
      <PreviewNote>Δείγμα ιστορικού με φανταστικά δεδομένα· καμία εξαργύρωση δεν έχει καταχωρηθεί.</PreviewNote>
    </div>
  );
}

function Profile({ state, today }: Props) {
  return (
    <div className="grid grid-cols-[minmax(0,1fr)] gap-x-16 gap-y-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
      <section>
        <h1 className="t-headline text-[var(--roast)]">{CUSTOMER.name}</h1>
        <p className="t-body mt-2 text-[var(--ink-2)]">Μέλος από τις {greekDate(CUSTOMER.memberSince)}</p>
        <div className="mt-8 flex flex-wrap gap-3">
          <Btn tone="line" disabled title="Μη διαθέσιμο στην προεπισκόπηση">Επεξεργασία στοιχείων</Btn>
          <Btn tone="line" disabled title="Μη διαθέσιμο στην προεπισκόπηση">Αποσύνδεση</Btn>
        </div>
      </section>
      <section>
        <h2 className="t-title border-b border-[var(--rule-strong)] pb-3 text-[var(--ink)]">Στοιχεία</h2>
        <DefRows
          rows={[
            ["Κινητό", <span key="p" className="t-num">{CUSTOMER.phoneMasked}</span>],
            ["E-mail", CUSTOMER.email],
            ["Συνήθως πίνει", coffeeName(CUSTOMER.favourite)],
          ]}
        />
        <h2 className="t-title mt-10 border-b border-[var(--rule-strong)] pb-3 text-[var(--ink)]">Συνδρομή</h2>
        <DefRows
          rows={[
            ["Κατάσταση", <StateMark key="s" state={state} />],
            ["Περίοδος", membershipLine(state, today)],
            ["Ανανέωση", "Χειροκίνητη, χωρίς αυτόματη χρέωση"],
          ]}
        />
        <h2 className="t-title mt-10 border-b border-[var(--rule-strong)] pb-3 text-[var(--ink)]">Επικοινωνία</h2>
        <DefRows rows={[["Newsletter", CUSTOMER.newsletter ? "Ναι, ξεχωριστή συναίνεση από τη συνδρομή" : "Όχι"]]} />
        <PreviewNote className="mt-8">Δείγμα προφίλ με φανταστικά στοιχεία· καμία αλλαγή δεν αποθηκεύεται.</PreviewNote>
      </section>
    </div>
  );
}
