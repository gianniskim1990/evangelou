import { useCallback, useEffect, useRef, useState, useSyncExternalStore, type ReactNode } from "react";
import { MemberPassView } from "../preview/CustomerSignature";
import { Dashboard, History, MemberChrome, Profile, Today } from "../preview/CustomerPreview";
import { liveActions, type CustomerActions } from "../preview/customerView";
import { Btn, Logo } from "../preview/ds";
import type { CustomerScreen } from "../preview/screens";
import type { CustomerConfig } from "./config";
import type { CustomerApi, MeResult } from "./customerApi";
import type { MemberCoffees } from "./coffeesApi";
import type { HistoryCoffee } from "./historyApi";
import { msToNextAthensMidnight, toLiveView } from "./liveView";
import type { MemberCoffeesFlow } from "./memberCoffees";
import type { MemberHistoryFlow } from "./memberHistory";
import type { MemberQrFlow, MemberQrState } from "./memberQr";
import { MemberQrPanel, QrNotAvailable } from "./MemberQrPanel";
import { InstallHint } from "./InstallHint";
import type { InstallHintFlow } from "./pwa/installHint";
import { PushControl } from "./PushControl";
import type { MemberPushFlow } from "./pwa/push";

/*
 * STAGE-01 REAL customer app (served by WordPress at /club-member/, flag-gated).
 * The member area is the approved UI-02 customer screens, fed by the
 * protected GET /me. STAGE-05D: the card shows the member's real Club QR
 * (MemberQrPanel / memberQr.ts). STAGE-08 round 17 (owner-found bug, staging
 * 2026-11-23: an active member saw the design preview's SAMPLE QR for about
 * 2 seconds after every cold start of the home-screen app, i.e. between the
 * GET /me answer and the first GET /me/qr answer): the card's QR place is the
 * panel from the first render on («Φόρτωση του κωδικού σας…» until the code is
 * known; «not available yet» without the route). The sample code, the sample
 * member and its figures are not in this app's import graph at all
 * (tests/club/customerSampleGuard.test.ts, scripts/sample-guard.mjs).
 * Signed out, not a customer, membership source unavailable and errors get
 * their own plain pages: nothing is ever shown as paid unless /me says so.
 * D23: with a coffees flow (GET /me/coffees) today's coffee and this month's
 * count are the member's real figures; without an answer they stay «—».
 * D24 (round 9): with a history flow (GET /me/history) the «Ιστορικό» lists show the member's own last
 * coffees. Round 8 LOW-2: the member area re-renders just after each Athens midnight (a timer), so
 * yesterday's «Τον απολαύσατε σήμερα» never stays on screen; the figures and history are asked again then.
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
/** Round 17: the notes under the steps of the live card (never the preview's sample notes). */
export const LIVE_QR_NOTE = "Δείξτε τον κωδικό στο ταμείο. Το προσωπικό βλέπει μόνο το μικρό σας όνομα και αν δικαιούστε καφέ σήμερα.";
export const LIVE_QR_OFF_NOTE = "Ο κωδικός QR θα εμφανιστεί σε αυτή την κάρτα μόλις ενεργοποιηθεί.";
const noSubscribe = () => () => {};
const noCoffees = (): MemberCoffees | null => null;
const noHistory = (): HistoryCoffee[] | null => null;

/** The timer the midnight re-render uses (injected in tests). */
export interface MidnightTimers {
  setTimeout(fn: () => void, ms: number): unknown;
  clearTimeout(id: unknown): void;
}
const REAL_TIMERS: MidnightTimers = {
  setTimeout: (fn, ms) => {
    const id = globalThis.setTimeout(fn, ms);
    // Never keeps a non-browser host (tests, server rendering) alive for hours.
    (id as { unref?: () => void }).unref?.();
    return id;
  },
  clearTimeout: (id) => globalThis.clearTimeout(id as ReturnType<typeof setTimeout>),
};

export function CustomerApp({
  config,
  api,
  now = () => Date.now(),
  navigate,
  qrFlow,
  coffeesFlow,
  historyFlow,
  timers = REAL_TIMERS,
  installHint,
  pushFlow,
}: {
  config: CustomerConfig;
  api: CustomerApi;
  now?: () => number;
  navigate: Navigate;
  /** STAGE-05D: the member's real QR (absent = «not available yet»; round 17: never the sample code). */
  qrFlow?: MemberQrFlow;
  /** D23: the member's own coffee figures (absent = «—», as before). */
  coffeesFlow?: MemberCoffeesFlow;
  /** D24: the member's own last coffees (absent = «δεν είναι ακόμη διαθέσιμο», as before). */
  historyFlow?: MemberHistoryFlow;
  /** LOW-2: the Athens-midnight re-render timer. */
  timers?: MidnightTimers;
  /** Round 11 (D26): «Εγκατάσταση στο κινητό», shown below the member area only. */
  installHint?: InstallHintFlow;
  /** Round 12 (D26 part 2): «Ειδοποιήσεις», shown below the member area only. */
  pushFlow?: MemberPushFlow;
}) {
  const [result, setResult] = useState<MeResult | null>(null);
  const qrState = useSyncExternalStore(qrFlow?.subscribe ?? noSubscribe, qrFlow?.getState ?? (() => NO_QR));
  const coffees = useSyncExternalStore(coffeesFlow?.subscribe ?? noSubscribe, coffeesFlow?.getState ?? noCoffees);
  const history = useSyncExternalStore(historyFlow?.subscribe ?? noSubscribe, historyFlow?.getState ?? noHistory);
  // LOW-2: bumped just after each Athens midnight, so the view is computed again for the new day.
  const [day, setDay] = useState(0);
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
      // Round 12: the next account on this phone never gets this member's notifications.
      await pushFlow?.signOut();
      navigate(config.logoutUrl);
    })();
  };

  const account = result?.kind === "ok" ? result.me.email : null;
  const onCard = result?.kind === "ok" && (screen ?? (toLiveView(result.me, now()).state === "active" ? "card" : "dashboard")) === "card";
  // D23: the figures are asked when the member area opens, and again (throttled, memberCoffees.ts) whenever the
  // page becomes visible, gets focus or comes back online, and when the card is opened from another screen.
  useEffect(() => {
    if (account === null || !coffeesFlow) return;
    coffeesFlow.open(account);
    const doc = globalThis.document;
    const onVisible = () => {
      if (!doc || doc.visibilityState !== "hidden") coffeesFlow.wake();
    };
    doc?.addEventListener("visibilitychange", onVisible);
    globalThis.addEventListener?.("focus", onVisible);
    globalThis.addEventListener?.("online", onVisible);
    return () => {
      doc?.removeEventListener("visibilitychange", onVisible);
      globalThis.removeEventListener?.("focus", onVisible);
      globalThis.removeEventListener?.("online", onVisible);
      coffeesFlow.pause();
    };
  }, [account, coffeesFlow]);
  useEffect(() => {
    if (onCard) coffeesFlow?.wake();
  }, [onCard, coffeesFlow]);
  // D24: the member's own last coffees, asked like the figures (memberHistory.ts throttles and caps).
  useEffect(() => {
    if (account === null || !historyFlow) return;
    historyFlow.open(account);
    const page = globalThis.document;
    const onShown = () => {
      if (!page || page.visibilityState !== "hidden") historyFlow.wake();
    };
    page?.addEventListener("visibilitychange", onShown);
    globalThis.addEventListener?.("focus", onShown);
    globalThis.addEventListener?.("online", onShown);
    return () => {
      page?.removeEventListener("visibilitychange", onShown);
      globalThis.removeEventListener?.("focus", onShown);
      globalThis.removeEventListener?.("online", onShown);
      historyFlow.pause();
    };
  }, [account, historyFlow]);
  // Round 12 (D26 part 2): what the notifications control shows is decided once the member area is open (no prompt).
  useEffect(() => {
    if (account !== null) void pushFlow?.open();
  }, [account, pushFlow]);
  // LOW-2: re-render just after the next Athens midnight (and ask for the new day's figures and history).
  useEffect(() => {
    if (account === null) return;
    const id = timers.setTimeout(() => {
      setDay((d) => d + 1);
      coffeesFlow?.wake();
      historyFlow?.wake();
    }, msToNextAthensMidnight(now()));
    return () => timers.clearTimeout(id);
  }, [account, day, now, timers, coffeesFlow, historyFlow]);
  // Each time the card is opened, the QR is resolved again from GET /me/qr (docs/member-qr.md rules).
  // Round 7: leaving the card (or unmounting) pauses the flow: no retry timer keeps running and no POST is sent
  // while the member is elsewhere; coming back opens it again.
  useEffect(() => {
    if (!onCard || account === null || !qrFlow) return;
    void qrFlow.open(account);
    return () => qrFlow.pause();
  }, [onCard, account, qrFlow]);
  // Round 17: the QR place of the live card is ALWAYS the real panel (or «not available» without a flow). The old
  // rule kept the design preview's sample code on screen until the first GET /me/qr answer (the owner's 2-second
  // sample QR on a cold start); there is no sample in this app any more.
  const qrOff = !qrFlow || qrState.phase === "off";

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
    const view = toLiveView(result.me, now(), coffees, history);
    const current: MemberScreen = screen ?? (view.state === "active" ? "card" : "dashboard");
    const go = (s: CustomerScreen) => {
      navigated.current = true;
      setScreen((MEMBER_SCREENS as readonly string[]).includes(s) ? (s as MemberScreen) : "dashboard");
    };
    // Round 10: «Γίνετε μέλος» for a customer without a membership; round 11 (D27): «Ανανέωση 20 €» for an expired
    // membership. Both only when the server sent the join URL (customerView.ts liveActions).
    const actions: CustomerActions = liveActions(view.state, config.joinUrl ?? "", navigate, signOut);
    body =
      current === "card" ? (
        <MemberPassView
          go={go}
          view={view}
          actions={actions}
          qr={qrFlow ? <MemberQrPanel flow={qrFlow} /> : <QrNotAvailable />}
          qrNote={qrOff ? LIVE_QR_OFF_NOTE : LIVE_QR_NOTE}
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
      {result?.kind === "ok" && pushFlow && <PushControl flow={pushFlow} />}
      {result?.kind === "ok" && installHint && <InstallHint flow={installHint} />}
    </div>
  );
}

/**
 * Round 11 (D26): the page the service worker builds when the member page cannot be fetched (pwa/swRules.ts
 * offlineShell). It knows nothing about the member (no nonce, no account), so it shows no card: only that there
 * is no connection, and a retry.
 */
export function OfflineApp({ reload }: { reload: () => void }) {
  return (
    <div className="evc-ds" data-evc-customer="app" data-evc-customer-state="offline">
      <StatusPage title="Δεν υπάρχει σύνδεση" lede="Η κάρτα σας εμφανίζεται μόλις το κινητό συνδεθεί ξανά στο διαδίκτυο.">
        <Btn onClick={reload} className="px-8">Δοκιμάστε ξανά</Btn>
      </StatusPage>
    </div>
  );
}
