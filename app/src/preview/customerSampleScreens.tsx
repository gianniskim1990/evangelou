import { useState } from "react";
import { type PreviewMembershipState } from "./previewData";
import { CUSTOMER } from "./previewRoster";
import { cx, type CustomerScreen } from "./screens";
import { ClubWelcome, MemberPassView } from "./CustomerSignature";
import { Dashboard, History, MemberChrome, Offer, Onboarding, PageTitle, Profile, Today, entitlement } from "./CustomerPreview";
import { membershipLine, type CustomerActions, type CustomerView } from "./customerView";
import { sampleCustomerView } from "./customerSample";
import { Btn, ConsentRow, Field, PreviewNote, StateMark } from "./ds";
import { ONBOARDING_COPY, consentsLine } from "./onboardingCopy";
import { PassQr } from "./sampleQr";

/*
 * STAGE-08 round 17 (owner-found bug, staging 2026-11-23): the design-preview-ONLY customer screens, moved unchanged
 * out of CustomerPreview.tsx / CustomerSignature.tsx so that the real member app (src/customer/main.tsx), which
 * reuses the approved member screens, can never reach the sample member, its figures or the sample QR:
 *   - MemberPass: the approved pass with the non-redeemable SAMPLE code (sampleQr.tsx) in its QR place;
 *   - CustomerPreview: the 10-screen design preview dispatcher (fed by sampleCustomerView, customerSample.ts);
 *   - Register / Newsletter / States: design-preview screens only.
 * tests/club/customerSampleGuard.test.ts and the packager (scripts/sample-guard.mjs) check that the member app's
 * import graph contains none of sampleQr.tsx, customerSample.ts and this file. The 26 preview screens render
 * byte-identically (tests/club/clubPreviewScreens.test.ts).
 */

/** Either the approved sample (state + switch) or a CustomerView from the customer journey preview (CUSTOMER-01). */
type PassProps = { go: (screen: CustomerScreen) => void; actions?: CustomerActions } & (
  | { view: CustomerView }
  | { state: PreviewMembershipState; coffeeUsedToday: boolean; today: string }
);

/** The approved pass with the non-redeemable SAMPLE code (design preview and the local journey preview only). */
export function MemberPass(props: PassProps) {
  const view = "view" in props ? props.view : sampleCustomerView(props.state, props.coffeeUsedToday, props.today);
  return (
    <MemberPassView
      go={props.go}
      view={view}
      actions={props.actions}
      qr={<PassQr seed={11} size={188} muted={view.state !== "active"} />}
      label="Κάρτα μέλους Evangelou Club (δείγμα)"
      qrNote={view.live ? "Ο κωδικός QR δεν είναι ακόμη ενεργός: δεν σαρώνεται και δεν εξαργυρώνεται." : "Ο κωδικός είναι δείγμα: δεν αντιστοιχεί σε μέλος, δεν σαρώνεται και δεν εξαργυρώνεται."}
    />
  );
}

type Props = {
  screen: CustomerScreen;
  go: (screen: CustomerScreen) => void;
  state: PreviewMembershipState;
  coffeeUsedToday: boolean;
  today: string;
};

export function CustomerPreview(props: Props) {
  const { screen, go } = props;
  const view = sampleCustomerView(props.state, props.coffeeUsedToday, props.today);
  if (screen === "welcome") return <ClubWelcome go={go} />;
  if (screen === "card") return <MemberPass go={go} view={view} />;
  if (screen === "register") return <Register go={go} />;
  if (screen === "newsletter") return <Newsletter go={go} />;
  if (screen === "offer") return <Offer go={go} />;
  return (
    <MemberChrome screen={screen} go={go}>
      {screen === "dashboard" && <Dashboard view={view} go={go} />}
      {screen === "states" && <States today={props.today} />}
      {screen === "today" && <Today view={view} go={go} />}
      {screen === "history" && <History view={view} go={go} />}
      {screen === "profile" && <Profile view={view} go={go} />}
    </MemberChrome>
  );
}

// ---- Onboarding (design preview) -------------------------------------------------------

function Register({ go }: { go: (s: CustomerScreen) => void }) {
  return (
    <Onboarding step={0} go={go} title={ONBOARDING_COPY.register.title} lede={ONBOARDING_COPY.register.lede}>
      <div className="grid gap-6">
        <Field label={ONBOARDING_COPY.register.nameLabel} value={CUSTOMER.name} />
        <Field label={ONBOARDING_COPY.register.phoneLabel} value={CUSTOMER.phoneMasked} type="tel" hint={ONBOARDING_COPY.register.phoneHint} />
        <Field label={ONBOARDING_COPY.register.emailLabel} value={CUSTOMER.email} type="email" hint={ONBOARDING_COPY.register.emailHint} />
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
    <Onboarding step={1} go={go} title={ONBOARDING_COPY.consents.title} lede={ONBOARDING_COPY.consents.lede}>
      <div className="border-t border-[var(--rule-strong)]">
        <ConsentRow checked={terms} onChange={setTerms} required title={ONBOARDING_COPY.consents.termsTitle} body={ONBOARDING_COPY.consents.termsBody} />
        <ConsentRow checked={news} onChange={setNews} title={ONBOARDING_COPY.consents.newsTitle} body={ONBOARDING_COPY.consents.newsBody} />
      </div>
      <p className="t-small mt-4 text-[var(--ink-2)]" aria-live="polite">
        {consentsLine(terms, news)}
      </p>
      <div className="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
        <Btn onClick={() => go("offer")} disabled={!terms} className="px-8">Συνέχεια</Btn>
        <button type="button" onClick={() => go("register")} className="t-label -my-1 py-3 text-[var(--ink-2)] underline decoration-[var(--rule-strong)] underline-offset-[6px] hover:text-[var(--ink)]">Πίσω</button>
      </div>
      <PreviewNote className="mt-8">Οι επιλογές ισχύουν μόνο σε αυτή τη σελίδα· καμία συναίνεση δεν αποθηκεύεται.</PreviewNote>
    </Onboarding>
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
                {membershipLine(sampleCustomerView(s.state, s.used, today))}
              </figcaption>
            </figure>
          );
        })}
      </div>
      <PreviewNote className="mt-10">Οι τέσσερις καταστάσεις είναι δείγματα σχεδίου. Η ανανέωση και η πληρωμή δεν λειτουργούν στην προεπισκόπηση.</PreviewNote>
    </div>
  );
}
