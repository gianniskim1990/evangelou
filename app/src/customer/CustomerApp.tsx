import { useCallback, useEffect, useRef, useState, useSyncExternalStore, type ReactNode } from "react";
import { MemberPassView } from "../preview/CustomerSignature";
import { Dashboard, History, MemberChrome, Profile, Today } from "../preview/CustomerPreview";
import type { CustomerActions } from "../preview/customerView";
import { Btn, Logo } from "../preview/ds";
import type { CustomerScreen } from "../preview/screens";
import type { CustomerConfig } from "./config";
import type { CustomerApi, MeResult } from "./customerApi";
import { toLiveView } from "./liveView";
import type { MemberQrFlow, MemberQrState } from "./memberQr";
import { MemberQrPanel } from "./MemberQrPanel";

/*
 * STAGE-01 REAL customer app (served by WordPress at /club-member/, flag-gated).
 * The member area is the approved UI-02 customer screens, fed by the
 * protected GET /me. STAGE-05D: when the server's QR route exists
 * (GET /me/qr is not 404) the card shows the member's real Club QR
 * (MemberQrPanel / memberQr.ts); otherwise the approved non-redeemable sample.
 * Signed out, not a customer, membership source unavailable and errors get
 * their own plain pages: nothing is ever shown as paid unless /me says so.
 */

type MemberScreen = "card" | "dashboard" | "today" | "history" | "profile";
type Navigate = (url: string) => void;

const MEMBER_SCREENS: readonly MemberScreen[] = ["card", "dashboard", "today", "history", "profile"];

function StatusPage({ title, lede, children }: { title: string; lede: string; children?: ReactNode }) {
  return (
    <div className="min-h-[calc(100svh-52px)] bg-[var(--stone)]">
      <header className="mx-auto flex max-w-[1180px] items-center justify-between px-5 py-5 md:px-10 md:py-7">
        <Logo className="h-8 md:h-10" />
      </header>
      <main className="mx-auto max-w-[1180px] px-5 pb-16 md:px-10">
        <h1 className="t-headline mt-10 max-w-[14ch] text-[var(--roast)]">{title}</h1>
        <p className="t-lede mt-4 max-w-[42ch] text-[var(--ink-2)]">{lede}</p>
        {children && <div className="mt-9 flex flex-wrap items-center gap-x-6 gap-y-4">{children}</div>}
      </main>
    </div>
  );
}

const NO_QR: MemberQrState = { phase: "off" };
const noSubscribe = () => () => {};

export function CustomerApp({
  config,
  api,
  now = () => Date.now(),
  navigate,
  qrFlow,
}: {
  config: CustomerConfig;
  api: CustomerApi;
  now?: () => number;
  navigate: Navigate;
  /** STAGE-05D: the member's real QR (absent = always the sample code). */
  qrFlow?: MemberQrFlow;
}) {
  const [result, setResult] = useState<MeResult | null>(null);
  const qrState = useSyncExternalStore(qrFlow?.subscribe ?? noSubscribe, qrFlow?.getState ?? (() => NO_QR));
  const [screen, setScreen] = useState<MemberScreen | null>(null);
  const root = useRef<HTMLDivElement>(null);
  const navigated = useRef(false);
  const generation = useRef(0);

  const fetchMe = useCallback(async () => {
    const mine = ++generation.current;
    const r = await api.me();
    if (mine === generation.current) setResult(r);
  }, [api]);

  useEffect(() => {
    void fetchMe();
  }, [fetchMe]);

  const retry = () => {
    setResult(null);
    void fetchMe();
  };

  // A new screen moves keyboard and screen-reader focus to its main heading.
  useEffect(() => {
    if (!navigated.current) return;
    const heading = root.current?.querySelector("h1");
    if (heading instanceof HTMLElement) {
      heading.setAttribute("tabindex", "-1");
      heading.focus();
    }
    window.scrollTo?.({ top: 0 });
  }, [screen, result]);

  const signOut = () => {
    // The phone's QR record and the in-memory copy go BEFORE WordPress signs the account out.
    void (async () => {
      await qrFlow?.forget();
      navigate(config.logoutUrl);
    })();
  };

  const account = result?.kind === "ok" ? result.me.email : null;
  const onCard = result?.kind === "ok" && (screen ?? (toLiveView(result.me, now()).state === "active" ? "card" : "dashboard")) === "card";
  // Each time the card is opened, the QR is resolved again from GET /me/qr (docs/member-qr.md rules).
  useEffect(() => {
    if (onCard && account !== null) void qrFlow?.open(account);
  }, [onCard, account, qrFlow]);
  // The real panel replaces the sample only once the route is known to exist (never «Φόρτωση…» when it is off).
  const showQr = qrFlow !== undefined && qrState.phase !== "off" && (qrState.phase !== "loading" || qrFlow.seenRoute());

  let body: ReactNode;
  if (result === null) {
    body = <StatusPage title="Ευαγγέλου Club" lede="Φόρτωση του λογαριασμού σας…" />;
  } else if (result.kind === "signed_out") {
    body = (
      <StatusPage title="Συνδεθείτε" lede="Συνδεθείτε με το e-mail και τον κωδικό του λογαριασμού σας για να δείτε τη συνδρομή σας.">
        <Btn onClick={() => navigate(config.loginUrl)} className="px-8">Σύνδεση</Btn>
      </StatusPage>
    );
  } else if (result.kind === "forbidden") {
    body = (
      <StatusPage title="Δεν είναι λογαριασμός μέλους" lede="Με αυτόν τον λογαριασμό δεν εμφανίζεται συνδρομή Club. Συνδεθείτε με τον προσωπικό σας λογαριασμό πελάτη.">
        <Btn onClick={signOut} className="px-8">Αποσύνδεση</Btn>
      </StatusPage>
    );
  } else if (result.kind === "unavailable") {
    body = (
      <StatusPage title="Η συνδρομή δεν είναι διαθέσιμη" lede="Δεν μπορούμε να ελέγξουμε τη συνδρομή σας αυτή τη στιγμή. Μέχρι να επιβεβαιωθεί, ο καφές της ημέρας δεν δίνεται.">
        <Btn onClick={retry} className="px-8">Δοκιμάστε ξανά</Btn>
        <Btn tone="line" onClick={signOut}>Αποσύνδεση</Btn>
      </StatusPage>
    );
  } else if (result.kind === "error") {
    body = (
      <StatusPage title="Κάτι δεν πήγε καλά" lede="Δεν φορτώθηκε ο λογαριασμός σας. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.">
        <Btn onClick={retry} className="px-8">Δοκιμάστε ξανά</Btn>
      </StatusPage>
    );
  } else {
    const view = toLiveView(result.me, now());
    const current: MemberScreen = screen ?? (view.state === "active" ? "card" : "dashboard");
    const go = (s: CustomerScreen) => {
      navigated.current = true;
      setScreen((MEMBER_SCREENS as readonly string[]).includes(s) ? (s as MemberScreen) : "dashboard");
    };
    const actions: CustomerActions = { signOut };
    body =
      current === "card" ? (
        <MemberPassView
          go={go}
          view={view}
          actions={actions}
          {...(qrFlow && showQr
            ? { qr: <MemberQrPanel flow={qrFlow} />, qrNote: "Δείξτε τον κωδικό στο ταμείο. Το προσωπικό βλέπει μόνο το μικρό σας όνομα και αν δικαιούστε καφέ σήμερα." }
            : {})}
        />
      ) : (
        <MemberChrome screen={current} go={go}>
          {current === "dashboard" && <Dashboard view={view} go={go} actions={actions} />}
          {current === "today" && <Today view={view} go={go} actions={actions} />}
          {current === "history" && <History view={view} go={go} actions={actions} />}
          {current === "profile" && <Profile view={view} go={go} actions={actions} />}
        </MemberChrome>
      );
  }

  return (
    <div ref={root} className="evc-ds" data-evc-customer="app" data-evc-customer-state={result?.kind === "ok" ? result.me.state : (result?.kind ?? "loading")}>
      {body}
    </div>
  );
}
