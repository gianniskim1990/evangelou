import { useState } from "react";
import { CLUB_RULES, CUSTOMER, addDays, coffeeName, greekDate, memberHistory, type PreviewMembershipState } from "./previewData";
import { cx, type CustomerScreen } from "./screens";
import { ClubWelcome, MemberPass } from "./CustomerSignature";
import { Card, Eyebrow, GhostButton, Icon, PreviewOnly, PrimaryButton, ScreenTitle, StatusBadge } from "./ui";

/* UI-01 design preview — Customer Club (visual only, synthetic data). */

type Props = {
  screen: CustomerScreen;
  go: (screen: CustomerScreen) => void;
  state: PreviewMembershipState;
  coffeeUsedToday: boolean;
  today: string;
};

export function CustomerPreview(props: Props) {
  const { screen, go } = props;
  // UI-01.R1 signature screens (new design system, full-bleed).
  if (screen === "welcome") return <ClubWelcome go={go} />;
  if (screen === "card") return <MemberPass {...props} />;
  const inApp = !["welcome", "register", "newsletter", "offer"].includes(screen);
  return (
    <div className="mx-auto w-full max-w-[440px] md:my-6 md:overflow-hidden md:rounded-[34px] md:border md:border-espresso/15 md:shadow-[0_24px_60px_-30px_rgba(30,24,18,0.45)]">
      <div className="flex min-h-[720px] flex-col bg-cream">
        <div className="flex items-center justify-between px-5 pt-4 pb-2">
          <img src="/logo-evaggelou-color.png" alt="Ευαγγέλου" className="h-7 object-contain" />
          <span className="rounded-full bg-[#efe6d2] px-2.5 py-1 text-[11px] font-semibold text-bronze-dark">Club</span>
        </div>
        <div className="flex-1 px-5 pt-3 pb-6">
          {screen === "register" && <Register go={go} />}
          {screen === "newsletter" && <Newsletter go={go} />}
          {screen === "offer" && <Offer go={go} />}
          {screen === "dashboard" && <Dashboard {...props} />}
          {screen === "states" && <States today={props.today} />}
          {screen === "today" && <Today {...props} />}
          {screen === "history" && <History today={props.today} />}
          {screen === "profile" && <Profile {...props} />}
        </div>
        {inApp && <TabBar screen={screen} go={go} />}
      </div>
    </div>
  );
}

function TabBar({ screen, go }: { screen: CustomerScreen; go: (s: CustomerScreen) => void }) {
  const tabs: { id: CustomerScreen; label: string; icon: "home" | "card" | "coffee" | "history" | "user" }[] = [
    { id: "dashboard", label: "Αρχική", icon: "home" },
    { id: "card", label: "Κάρτα", icon: "card" },
    { id: "today", label: "Καφές", icon: "coffee" },
    { id: "history", label: "Ιστορικό", icon: "history" },
    { id: "profile", label: "Προφίλ", icon: "user" },
  ];
  return (
    <nav className="sticky bottom-0 grid grid-cols-5 border-t border-espresso/10 bg-surface px-2 pt-2 pb-3" aria-label="Μενού μέλους (δείγμα)">
      {tabs.map((t) => (
        <button key={t.id} type="button" onClick={() => go(t.id)} className={cx("flex flex-col items-center gap-1 rounded-lg py-1.5 text-[11px] font-medium", screen === t.id ? "text-bronze-dark" : "text-espresso/45")} aria-current={screen === t.id ? "page" : undefined}>
          <Icon name={t.icon} size={21} />
          {t.label}
        </button>
      ))}
    </nav>
  );
}

function Steps({ active }: { active: number }) {
  const steps = ["Λογαριασμός", "Ενημερώσεις", "Συνδρομή"];
  return (
    <ol className="mb-6 flex gap-2" aria-label="Βήματα εγγραφής">
      {steps.map((s, i) => (
        <li key={s} className="flex-1">
          <span className={cx("block h-1.5 rounded-full", i <= active ? "bg-bronze" : "bg-hairline")} />
          <span className={cx("mt-1.5 block text-[11.5px] font-medium", i === active ? "text-espresso" : "text-espresso/45")}>{s}</span>
        </li>
      ))}
    </ol>
  );
}

function Field({ label, value, type = "text" }: { label: string; value: string; type?: string }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-[13px] font-medium text-espresso/70">{label}</span>
      <input type={type} defaultValue={value} readOnly className="w-full rounded-xl border border-espresso/15 bg-surface px-4 py-3 text-[15px] text-espresso/80" />
    </label>
  );
}

function Register({ go }: { go: (s: CustomerScreen) => void }) {
  return (
    <div>
      <Steps active={0} />
      <ScreenTitle eyebrow="Εγγραφή" title="Δημιουργήστε τον λογαριασμό σας" lead="Χρειαζόμαστε μόνο τα βασικά για να σας αναγνωρίζουμε στο ταμείο." />
      <div className="space-y-4">
        <Field label="Ονοματεπώνυμο" value="Αλεξάνδρα Κωνσταντίνου" />
        <Field label="Κινητό τηλέφωνο" value="69•• ••• •14" type="tel" />
        <Field label="E-mail" value="alexandra.k@example.invalid" type="email" />
      </div>
      <PrimaryButton className="mt-6 w-full" onClick={() => go("newsletter")}>Συνέχεια</PrimaryButton>
      <PreviewOnly>Προεπισκόπηση σχεδίου: τα πεδία είναι δείγματα και δεν δημιουργείται λογαριασμός.</PreviewOnly>
    </div>
  );
}

function Toggle({ checked, onChange, title, body, required }: { checked: boolean; onChange: (v: boolean) => void; title: string; body: string; required?: boolean }) {
  return (
    <label className={cx("flex cursor-pointer gap-3.5 rounded-2xl border p-4", checked ? "border-bronze/60 bg-[#fbf6ea]" : "border-espresso/12 bg-surface")}>
      <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="mt-1 h-5 w-5 flex-none accent-[#5f5335]" />
      <span>
        <span className="block text-[15px] font-semibold">{title} {required ? <span className="text-maroon">*</span> : <span className="text-[12px] font-medium text-espresso/45">(προαιρετικό)</span>}</span>
        <span className="mt-1 block text-[13.5px] leading-relaxed text-espresso/60">{body}</span>
      </span>
    </label>
  );
}

function Newsletter({ go }: { go: (s: CustomerScreen) => void }) {
  const [terms, setTerms] = useState(false);
  const [news, setNews] = useState(false);
  return (
    <div>
      <Steps active={1} />
      <ScreenTitle eyebrow="Ενημερώσεις & συναινέσεις" title="Εσείς επιλέγετε" lead="Η εγγραφή στο newsletter είναι ξεχωριστή από τη συνδρομή στο Club και δεν δίνει δωρεάν καφέ." />
      <div className="space-y-3">
        <Toggle checked={terms} onChange={setTerms} required title="Όροι Club & απόρρητο" body="Αποδέχομαι τους όρους του Evangelou Club και την πολιτική απορρήτου." />
        <Toggle checked={news} onChange={setNews} title="Newsletter" body="Θέλω να λαμβάνω νέα, προσφορές και εκδηλώσεις με e-mail. Μπορώ να διαγραφώ οποιαδήποτε στιγμή." />
      </div>
      <PrimaryButton className="mt-6 w-full" disabled={!terms} onClick={() => go("offer")}>Συνέχεια</PrimaryButton>
      <PreviewOnly>Οι επιλογές λειτουργούν μόνο τοπικά στη σελίδα· καμία συναίνεση δεν αποθηκεύεται.</PreviewOnly>
    </div>
  );
}

function Offer({ go }: { go: (s: CustomerScreen) => void }) {
  return (
    <div>
      <Steps active={2} />
      <ScreenTitle eyebrow="Συνδρομή" title="Evangelou Club" />
      <Card className="relative overflow-hidden border-bronze/40 p-6">
        <div className="absolute top-0 right-0 rounded-bl-2xl bg-bronze-dark px-3 py-1.5 text-[11px] font-semibold text-white">Μηνιαία</div>
        <p className="font-literata text-[44px] leading-none font-semibold">{CLUB_RULES.monthlyPriceEur} €<span className="ml-1 text-[16px] font-normal text-espresso/55">/ μήνα</span></p>
        <ul className="mt-5 space-y-2.5 text-[14.5px]">
          {["1 δωρεάν καφές κάθε ημέρα", "Ψηφιακή κάρτα μέλους με QR", "Ιστορικό καφέδων στον λογαριασμό σας", "Χειροκίνητη ανανέωση — χωρίς αυτόματη χρέωση"].map((t) => (
            <li key={t} className="flex items-start gap-2.5"><Icon name="check" size={18} className="mt-px flex-none text-bronze-dark" />{t}</li>
          ))}
        </ul>
      </Card>
      <p className="mt-4 text-[13px] leading-relaxed text-espresso/55">Η συνδρομή διαρκεί έναν ημερολογιακό μήνα από την επιβεβαίωση της πληρωμής. Για τους πρώτους μήνες η ανανέωση γίνεται χειροκίνητα.</p>
      <PrimaryButton className="mt-5 w-full" disabled title="Η πληρωμή δεν είναι διαθέσιμη στην προεπισκόπηση">Πληρωμή 20 €</PrimaryButton>
      <GhostButton className="mt-3 w-full" onClick={() => go("dashboard")}>Δείτε πώς θα είναι ο λογαριασμός σας</GhostButton>
      <PreviewOnly>Δεν υπάρχει πληρωμή ή συνδρομή στην προεπισκόπηση — καμία χρέωση δεν γίνεται.</PreviewOnly>
    </div>
  );
}

function paidUntil(state: PreviewMembershipState, today: string): string | null {
  if (state === "pending") return null;
  return addDays(today, state === "active" ? CUSTOMER.paidUntilOffsetDays : -3);
}

function MembershipCard({ state, today }: { state: PreviewMembershipState; today: string }) {
  const until = paidUntil(state, today);
  return (
    <Card className="p-5">
      <div className="flex items-start justify-between gap-3">
        <div>
          <Eyebrow>Συνδρομή</Eyebrow>
          <p className="font-literata text-[19px] font-semibold">Evangelou Club</p>
        </div>
        <StatusBadge state={state} />
      </div>
      <p className="mt-3 text-[14px] text-espresso/65">
        {state === "active" && <>Ισχύει έως <strong className="text-espresso">{greekDate(until!)}</strong></>}
        {state === "expired" && <>Έληξε στις <strong className="text-espresso">{greekDate(until!)}</strong>. Ανανεώστε για να συνεχίσετε.</>}
        {state === "pending" && <>Η πληρωμή σας δεν έχει επιβεβαιωθεί ακόμη. Η συνδρομή ενεργοποιείται μόλις επιβεβαιωθεί.</>}
      </p>
      {state !== "active" && <PrimaryButton className="mt-4 w-full" disabled title="Μη διαθέσιμο στην προεπισκόπηση">{state === "expired" ? "Ανανέωση 20 €" : "Ολοκλήρωση πληρωμής"}</PrimaryButton>}
    </Card>
  );
}

function CoffeeToday({ state, coffeeUsedToday, today, big = false }: { state: PreviewMembershipState; coffeeUsedToday: boolean; today: string; big?: boolean }) {
  const available = state === "active" && !coffeeUsedToday;
  const tone = available ? "bg-espresso text-cream" : "bg-surface text-espresso border border-espresso/12";
  return (
    <div className={cx("rounded-2xl p-5", tone)}>
      <div className="flex items-center gap-3">
        <span className={cx("flex h-11 w-11 items-center justify-center rounded-xl", available ? "bg-bronze/40 text-cream" : "bg-hairline text-espresso/50")}><Icon name="coffee" size={22} /></span>
        <div>
          <p className={cx("text-[12px] font-medium", available ? "text-cream/70" : "text-espresso/50")}>{greekDate(today, true)}</p>
          <p className={cx("font-literata font-semibold", big ? "text-[22px]" : "text-[18px]")}>
            {state !== "active" ? "Ο δωρεάν καφές δεν είναι διαθέσιμος" : coffeeUsedToday ? "Απολαύσατε τον σημερινό καφέ" : "Ο σημερινός καφές σας περιμένει"}
          </p>
        </div>
      </div>
      <p className={cx("mt-3 text-[13.5px] leading-relaxed", available ? "text-cream/75" : "text-espresso/60")}>
        {state !== "active" ? "Χρειάζεται ενεργή συνδρομή." : coffeeUsedToday ? "Ο επόμενος δωρεάν καφές είναι διαθέσιμος από τα μεσάνυχτα (ώρα Ελλάδας)." : "Δείξτε την κάρτα σας στο ταμείο. Ένας καφές ανά ημέρα."}
      </p>
    </div>
  );
}

function Dashboard({ state, coffeeUsedToday, today, go }: Props) {
  return (
    <div className="space-y-4">
      <div>
        <p className="text-[14px] text-espresso/55">Καλημέρα,</p>
        <h1 className="font-literata text-[26px] font-semibold">{CUSTOMER.name.split(" ")[0]}</h1>
      </div>
      <CoffeeToday state={state} coffeeUsedToday={coffeeUsedToday} today={today} />
      <MembershipCard state={state} today={today} />
      <div className="grid grid-cols-2 gap-3">
        <button type="button" onClick={() => go("card")} className="rounded-2xl border border-espresso/12 bg-surface p-4 text-left">
          <Icon name="qr" className="text-bronze-dark" />
          <span className="mt-2 block text-[14px] font-semibold">Η κάρτα μου</span>
          <span className="block text-[12px] text-espresso/50">Για το ταμείο</span>
        </button>
        <button type="button" onClick={() => go("history")} className="rounded-2xl border border-espresso/12 bg-surface p-4 text-left">
          <Icon name="history" className="text-bronze-dark" />
          <span className="mt-2 block text-[14px] font-semibold">Ιστορικό</span>
          <span className="block text-[12px] text-espresso/50">{CUSTOMER.visitsThisMonth} καφέδες τον μήνα</span>
        </button>
      </div>
    </div>
  );
}

function States({ today }: { today: string }) {
  return (
    <div>
      <ScreenTitle eyebrow="Καταστάσεις" title="Πώς φαίνεται η συνδρομή" lead="Οι τρεις καταστάσεις που θα βλέπει ένα μέλος." />
      <div className="space-y-3">
        {(["active", "pending", "expired"] as const).map((s) => <MembershipCard key={s} state={s} today={today} />)}
      </div>
    </div>
  );
}

function Today({ state, coffeeUsedToday, today }: Props) {
  return (
    <div>
      <ScreenTitle eyebrow="Δωρεάν καφές" title="Ο σημερινός καφές" />
      <CoffeeToday state={state} coffeeUsedToday={coffeeUsedToday} today={today} big />
      <Card className="mt-4">
        <p className="text-[14px] font-semibold">Πώς λειτουργεί</p>
        <ul className="mt-2.5 space-y-2 text-[13.5px] text-espresso/65">
          <li>• Ένας δωρεάν καφές ανά ημερολογιακή ημέρα (ώρα Ελλάδας).</li>
          <li>• Ο καφές της ημέρας δεν μεταφέρεται στην επόμενη.</li>
          <li>• Το προσωπικό επιβεβαιώνει την ημέρα σας στο ταμείο.</li>
        </ul>
      </Card>
    </div>
  );
}

function History({ today }: { today: string }) {
  const items = memberHistory(CUSTOMER.id, today).slice(0, 12);
  return (
    <div>
      <ScreenTitle eyebrow="Ιστορικό" title="Οι καφέδες σας" />
      <Card className="divide-y divide-espresso/8 p-0">
        {items.map((r) => (
          <div key={`${r.date}-${r.time}`} className="flex items-center gap-3 px-4 py-3.5">
            <span className="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-[#efe6d2] text-bronze-dark"><Icon name="coffee" size={18} /></span>
            <span className="flex-1">
              <span className="block text-[14.5px] font-semibold">{coffeeName(r.coffee)}</span>
              <span className="block text-[12.5px] text-espresso/50">{greekDate(r.date, true)}</span>
            </span>
            <span className="text-[13px] text-espresso/55 tabular-nums">{r.time}</span>
          </div>
        ))}
      </Card>
      <p className="mt-3 text-[12px] text-espresso/45">Δείγμα ιστορικού — καμία εξαργύρωση δεν έχει καταχωρηθεί.</p>
    </div>
  );
}

function Profile({ state, today }: Props) {
  const rows: [string, string][] = [
    ["Ονοματεπώνυμο", CUSTOMER.name],
    ["Κινητό", CUSTOMER.phoneMasked],
    ["E-mail", CUSTOMER.email],
    ["Μέλος από", greekDate(CUSTOMER.memberSince)],
    ["Αγαπημένος καφές", coffeeName(CUSTOMER.favourite)],
  ];
  return (
    <div>
      <ScreenTitle eyebrow="Λογαριασμός" title="Το προφίλ μου" />
      <Card className="p-0">
        {rows.map(([k, v]) => (
          <div key={k} className="flex justify-between gap-4 border-b border-espresso/8 px-4 py-3.5 last:border-0">
            <span className="text-[13.5px] text-espresso/55">{k}</span>
            <span className="text-right text-[14px] font-medium">{v}</span>
          </div>
        ))}
      </Card>
      <Card className="mt-4">
        <div className="flex items-center justify-between">
          <span className="text-[14px] font-semibold">Συνδρομή</span>
          <StatusBadge state={state} />
        </div>
        <p className="mt-2 text-[13px] text-espresso/60">{state === "active" ? `Ενεργή έως ${greekDate(addDays(today, CUSTOMER.paidUntilOffsetDays))} · χειροκίνητη ανανέωση` : "Δεν υπάρχει ενεργή συνδρομή."}</p>
      </Card>
      <Card className="mt-4">
        <div className="flex items-center justify-between">
          <span className="text-[14px] font-semibold">Newsletter</span>
          <span className="text-[13px] text-espresso/60">{CUSTOMER.newsletter ? "Εγγεγραμμένη" : "Όχι"}</span>
        </div>
        <p className="mt-1.5 text-[12.5px] text-espresso/50">Ξεχωριστό από τη συνδρομή Club.</p>
      </Card>
      <PreviewOnly>Δείγμα προφίλ με φανταστικά στοιχεία· καμία αλλαγή δεν αποθηκεύεται.</PreviewOnly>
    </div>
  );
}
