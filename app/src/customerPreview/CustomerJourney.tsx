import { useEffect, useRef, useState, useSyncExternalStore, type FormEvent } from "react";
import { ClubWelcome, MemberPass } from "../preview/CustomerSignature";
import { Dashboard, History, MemberChrome, Offer, Onboarding, PageTitle, Profile, Today } from "../preview/CustomerPreview";
import type { CustomerActions, CustomerMembershipState } from "../preview/customerView";
import { Btn, ConsentRow, DefRows, Field, Logo, PreviewNote } from "../preview/ds";
import { ONBOARDING_COPY, consentsLine } from "../preview/onboardingCopy";
import { CLUB_RULES, type PreviewMember } from "../preview/previewData";
import type { CustomerScreen } from "../preview/screens";
import type { FakeCustomerServer } from "./customerServer";
import { athensDate } from "./membershipCalendar";
import { EMPTY_FORM, canonicalGreekMobile, firstInvalid, maskMobile, normaliseName, validateRegistration, type RegistrationField, type RegistrationForm } from "./registration";
import { ACCOUNT_SCREENS, ALL_SCREENS, SCREEN_TITLES, startScreen, type JourneyScreen } from "./journey";
import { toCustomerView } from "./scenarios";

/*
 * CUSTOMER-01 LOCAL-ONLY customer journey: the approved UI-02 customer
 * screens, connected into one flow over the simulated server. The member
 * screens are the approved components themselves (rendered from a
 * CustomerView); only the steps the design preview never had are new here:
 * the real registration form, consents bound to the journey, the honest
 * "payment is not connected" step and the sign-in placeholder.
 *
 * It never says a payment or a registration succeeded: payments are
 * confirmed only by the shop (the preview tools), and every screen keeps the
 * approved preview notes.
 */

const STATE_SENTENCE: Record<CustomerMembershipState, string> = {
  active: "Η συνδρομή σας είναι ενεργή.",
  pending: "Η συνδρομή σας περιμένει επιβεβαίωση πληρωμής.",
  expired: "Η συνδρομή σας έληξε.",
  cancelled: "Η συνδρομή σας ακυρώθηκε.",
};

function screenFromHash(hash: string): JourneyScreen | null {
  const id = hash.replace(/^#\/?/, "");
  return (ALL_SCREENS as readonly string[]).includes(id) ? (id as JourneyScreen) : null;
}

/** The synthetic profile built from the form (never stored outside this page). */
function memberFromForm(form: RegistrationForm, newsletter: boolean, today: string): PreviewMember {
  const name = normaliseName(form.name);
  const initials = name
    .split(" ")
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toLocaleUpperCase("el-GR");
  return {
    id: "m90",
    name,
    initials,
    phoneMasked: maskMobile(canonicalGreekMobile(form.phone) ?? ""),
    email: form.email.trim(),
    memberSince: today,
    state: "pending",
    paidUntilOffsetDays: 0,
    redeemedToday: false,
    favourite: "espresso",
    newsletter,
    visitsThisMonth: 0,
  };
}

type Props = { server: FakeCustomerServer; now: () => number; emailRequired: boolean };

export function CustomerJourney({ server, now, emailRequired }: Props) {
  useSyncExternalStore(server.subscribe, server.getVersion);
  const [screen, setScreen] = useState<JourneyScreen>(() => startScreen(server));
  const [form, setForm] = useState<RegistrationForm>(EMPTY_FORM);
  const [attempted, setAttempted] = useState(false);
  const [terms, setTerms] = useState(false);
  const [news, setNews] = useState(false);
  const [paymentKind, setPaymentKind] = useState<"initial" | "renewal">("initial");
  const [announce, setAnnounce] = useState("");
  const root = useRef<HTMLDivElement>(null);
  const navigated = useRef(false);
  const nameRef = useRef<HTMLInputElement>(null);
  const phoneRef = useRef<HTMLInputElement>(null);
  const emailRef = useRef<HTMLInputElement>(null);
  const refs: Record<RegistrationField, typeof nameRef> = { name: nameRef, phone: phoneRef, email: emailRef };

  const snapshot = server.snapshot();
  const view = snapshot ? toCustomerView(snapshot, now()) : null;
  const current: JourneyScreen = !view && ACCOUNT_SCREENS.has(screen) ? "welcome" : screen;
  const errors = attempted ? validateRegistration(form, emailRequired) : {};
  const registrationComplete = Object.keys(validateRegistration(form, emailRequired)).length === 0;

  // Announce what the server changed (payment confirmed by the shop, coffee recorded, expiry at the exact instant).
  const state = snapshot?.state;
  const used = snapshot?.coffeeUsedToday;
  const lastState = useRef(state);
  const lastUsed = useRef(used);
  useEffect(() => {
    if (state !== undefined && lastState.current !== undefined && state !== lastState.current) setAnnounce(STATE_SENTENCE[state]);
    else if (lastUsed.current === false && used === true) setAnnounce("Το ταμείο καταχώρησε τον σημερινό σας καφέ.");
    lastState.current = state;
    lastUsed.current = used;
  }, [state, used]);

  useEffect(() => {
    const onPop = () => {
      const target = screenFromHash(window.location.hash);
      if (target) setScreen(target);
    };
    window.addEventListener("popstate", onPop);
    return () => window.removeEventListener("popstate", onPop);
  }, []);

  // A new screen moves keyboard and screen-reader focus to its main heading.
  useEffect(() => {
    document.title = `${SCREEN_TITLES[current]} · Evangelou Club (τοπική προεπισκόπηση)`;
    if (!navigated.current) return;
    const heading = root.current?.querySelector("h1");
    if (heading instanceof HTMLElement) {
      heading.setAttribute("tabindex", "-1");
      heading.focus();
    }
    window.scrollTo?.({ top: 0 });
  }, [current]);

  const go = (next: JourneyScreen) => {
    navigated.current = true;
    setScreen(next);
    window.history.pushState(null, "", `#/${next}`);
  };

  /** The approved components navigate with the design preview's screen ids. */
  const goApproved = (s: CustomerScreen) => {
    if (s === "newsletter") return go("consents");
    if (s === "states") return go("dashboard");
    if ((s === "card" || s === "dashboard") && !view) return go("signin");
    go(s);
  };

  const submitRegistration = (e: FormEvent) => {
    e.preventDefault();
    setAttempted(true);
    const found = validateRegistration(form, emailRequired);
    const first = firstInvalid(found);
    if (first) {
      const count = Object.keys(found).length;
      setAnnounce(count === 1 ? "Ελέγξτε 1 πεδίο." : `Ελέγξτε ${count} πεδία.`);
      refs[first].current?.focus();
      return;
    }
    setAnnounce("");
    go("consents");
  };

  const pay = () => {
    if (!view) {
      if (!registrationComplete) return go("register");
      if (!terms) return go("consents");
      server.register({ member: memberFromForm(form, news, athensDate(now())), emailGiven: form.email.trim() !== "", newsletter: news, termsAccepted: terms });
    }
    setPaymentKind(server.snapshot()?.pendingPayment?.kind ?? "initial");
    go("payment");
  };

  const actions: CustomerActions = {
    pay,
    renew: () => {
      server.requestRenewal();
      setPaymentKind("renewal");
      go("payment");
    },
  };

  const set = (field: RegistrationField) => (value: string) => setForm((f) => ({ ...f, [field]: value }));
  const back = (to: JourneyScreen) => (
    <button type="button" onClick={() => go(to)} className="t-label -my-1 py-3 text-[var(--ink-2)] underline decoration-[var(--rule-strong)] underline-offset-[6px] hover:text-[var(--ink)]">
      Πίσω
    </button>
  );

  let body;
  if (current === "welcome") body = <ClubWelcome go={goApproved} />;
  else if (current === "register") {
    const c = ONBOARDING_COPY.register;
    body = (
      <Onboarding step={0} go={goApproved} title={c.title} lede={c.lede}>
        <form noValidate onSubmit={submitRegistration} aria-label="Στοιχεία εγγραφής">
          <div className="grid gap-6">
            <Field label={c.nameLabel} value={form.name} edit={{ id: "evc-cj-name", name: "name", onChange: set("name"), error: errors.name, required: true, autoComplete: "name" }} inputRef={nameRef} />
            <Field label={c.phoneLabel} value={form.phone} type="tel" hint={c.phoneHint} edit={{ id: "evc-cj-phone", name: "phone", onChange: set("phone"), error: errors.phone, required: true, autoComplete: "tel", inputMode: "tel" }} inputRef={phoneRef} />
            <Field label={c.emailLabel} value={form.email} type="email" hint={c.emailHint} edit={{ id: "evc-cj-email", name: "email", onChange: set("email"), error: errors.email, required: emailRequired, autoComplete: "email", inputMode: "email" }} inputRef={emailRef} />
          </div>
          <div className="mt-9 flex flex-wrap items-center gap-x-6 gap-y-4">
            <Btn type="submit" className="px-8">Συνέχεια</Btn>
            {back("welcome")}
          </div>
        </form>
        <PreviewNote className="mt-8">Προεπισκόπηση: τα στοιχεία μένουν μόνο σε αυτή τη σελίδα και δεν δημιουργείται λογαριασμός.</PreviewNote>
      </Onboarding>
    );
  } else if (current === "consents") {
    const c = ONBOARDING_COPY.consents;
    body = (
      <Onboarding step={1} go={goApproved} title={c.title} lede={c.lede}>
        <div className="border-t border-[var(--rule-strong)]">
          <ConsentRow checked={terms} onChange={setTerms} required title={c.termsTitle} body={c.termsBody} />
          <ConsentRow checked={news} onChange={setNews} title={c.newsTitle} body={c.newsBody} />
        </div>
        <p className="t-small mt-4 text-[var(--ink-2)]" aria-live="polite">
          {consentsLine(terms, news)}
        </p>
        <div className="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
          <Btn onClick={() => go("offer")} disabled={!terms} className="px-8">Συνέχεια</Btn>
          {back("register")}
        </div>
        <PreviewNote className="mt-8">Οι επιλογές ισχύουν μόνο σε αυτή τη σελίδα· καμία συναίνεση δεν αποθηκεύεται.</PreviewNote>
      </Onboarding>
    );
  } else if (current === "offer") {
    body = <Offer go={goApproved} onPay={pay} secondary={{ label: "Πίσω", onClick: () => go(view ? "dashboard" : terms && registrationComplete ? "consents" : "welcome") }} />;
  } else if (current === "signin") {
    body = (
      <div className="min-h-[calc(100svh-52px)] bg-[var(--stone)]">
        <header className="mx-auto flex max-w-[1180px] items-center justify-between px-5 py-5 md:px-10 md:py-7">
          <button type="button" onClick={() => go("welcome")} aria-label="Evangelou Club, αρχική σελίδα" className="press -my-1.5 rounded-[4px] py-1.5">
            <Logo className="h-8 md:h-10" />
          </button>
        </header>
        <main className="mx-auto max-w-[1180px] px-5 pb-16 md:px-10">
          <h1 className="t-headline mt-10 max-w-[14ch] text-[var(--roast)]">Σύνδεση μέλους</h1>
          <p className="t-lede mt-4 max-w-[42ch] text-[var(--ink-2)]">Ο τρόπος σύνδεσης στον λογαριασμό (για παράδειγμα με e-mail ή με κινητό) δεν έχει αποφασιστεί ακόμη.</p>
          <PreviewNote className="mt-8">Στην προεπισκόπηση, διαλέξτε ένα δείγμα μέλους από το «Σενάριο» στη γραμμή εργαλείων.</PreviewNote>
          <div className="mt-9 flex flex-wrap items-center gap-x-6 gap-y-4">
            <Btn onClick={() => go("welcome")} className="px-8">Πίσω στην αρχή</Btn>
          </div>
        </main>
      </div>
    );
  } else if (view && current === "card") body = <MemberPass go={goApproved} view={view} actions={actions} />;
  else if (view && current === "payment") {
    const renewal = paymentKind === "renewal";
    const content = (
      <>
        <DefRows
          rows={[
            ["Ποσό", `${CLUB_RULES.monthlyPriceEur} € για έναν ημερολογιακό μήνα`],
            ["Κατάσταση", "Σε αναμονή πληρωμής"],
            [
              "Τι ακολουθεί",
              renewal
                ? "Η τρέχουσα συνδρομή σας δεν αλλάζει. Ο νέος μήνας προστίθεται μόνο όταν το κατάστημα επιβεβαιώσει την πληρωμή."
                : "Η συνδρομή ενεργοποιείται μόνο όταν το κατάστημα επιβεβαιώσει την πληρωμή. Ο μήνας μετρά από εκείνη τη στιγμή.",
            ],
          ]}
        />
        <PreviewNote className="mt-8">Ο τρόπος πληρωμής δεν έχει οριστικοποιηθεί. Στην προεπισκόπηση την πληρωμή την επιβεβαιώνει το κατάστημα, από τα εργαλεία προσομοίωσης.</PreviewNote>
        <div className="mt-9 flex flex-wrap items-center gap-x-6 gap-y-4">
          <Btn onClick={() => go("dashboard")} className="px-8">Στον λογαριασμό μου</Btn>
        </div>
      </>
    );
    body = renewal ? (
      <MemberChrome screen="profile" go={goApproved}>
        <div className="max-w-[720px]">
          <PageTitle title="Η ανανέωση περιμένει πληρωμή" lede="Σε αυτή την τοπική προεπισκόπηση δεν υπάρχει σύνδεση με πληρωμές. Δεν χρεώθηκε τίποτα." />
          {content}
        </div>
      </MemberChrome>
    ) : (
      <Onboarding step={2} go={goApproved} title="Η πληρωμή δεν έγινε" lede="Σε αυτή την τοπική προεπισκόπηση δεν υπάρχει σύνδεση με πληρωμές. Δεν χρεώθηκε τίποτα.">
        {content}
      </Onboarding>
    );
  } else if (view) {
    const memberScreen = current as Exclude<JourneyScreen, "welcome" | "register" | "consents" | "offer" | "payment" | "signin" | "card">;
    body = (
      <MemberChrome screen={memberScreen} go={goApproved}>
        {memberScreen === "dashboard" && <Dashboard view={view} go={goApproved} actions={actions} />}
        {memberScreen === "today" && <Today view={view} go={goApproved} actions={actions} />}
        {memberScreen === "history" && <History view={view} go={goApproved} actions={actions} />}
        {memberScreen === "profile" && <Profile view={view} go={goApproved} actions={actions} />}
      </MemberChrome>
    );
  }

  return (
    <div ref={root} data-evc-cj="app" data-evc-cj-screen={current}>
      {body}
      <p role="status" aria-live="polite" className="sr-only">
        {announce}
      </p>
    </div>
  );
}
