import { useSyncExternalStore, type ReactNode } from "react";
import { ClubShell } from "../club/ClubShell";
import { formatClockTime } from "../club/format";
import logoUrl from "./assets/logo-evaggelou-color.png";
import type { StaffController, StaffState } from "./staffController";

/**
 * REAL staff app UI. No demo data, no mock service: everything shown comes
 * from the WordPress session endpoint. Member functions are not connected
 * yet and are rendered as clearly disabled, non-interactive cards.
 */

export const NOT_READY_MESSAGE = "Η λειτουργία θα ενεργοποιηθεί μετά τη σύνδεση του Club.";

function Icon({ path }: { path: string }) {
  return (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d={path} />
    </svg>
  );
}

const ICONS = {
  qr: "M3.5 3.5h6.5v6.5H3.5zM14 3.5h6.5v6.5H14zM3.5 14h6.5v6.5H3.5zM14 14h2.8M14 17.5h6.5M20.5 14v3.5M14 20.5h6.5",
  phone: "M6.6 3.5h3l1.4 4-2 1.3a11 11 0 0 0 6.2 6.2l1.3-2 4 1.4v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.6 5.7a2 2 0 0 1 2-2.2Z",
  coffee: "M5 9h11v5.5A4.5 4.5 0 0 1 11.5 19H9.5A4.5 4.5 0 0 1 5 14.5V9ZM16 10.5h1.5a2.25 2.25 0 0 1 0 4.5H16M8 6c0-1 .8-1.3.8-2.2M11.5 6c0-1 .8-1.3.8-2.2",
  history: "M12 7v5l3 2M3.5 12a8.5 8.5 0 1 0 2.5-6M3.5 4v4h4",
  lock: "M7 11V8a5 5 0 0 1 10 0v3M5.5 11h13v9.5h-13zM12 15v2.5",
  shield: "M12 3 4 6.5V11c0 4.5 3.2 8.3 8 9.5 4.8-1.2 8-5 8-9.5V6.5L12 3ZM9 12l2 2 4-4",
  wifi: "M3 9a13 13 0 0 1 18 0M6 12.5a8.5 8.5 0 0 1 12 0M9.5 16a3.5 3.5 0 0 1 5 0M12 19.5h.01M4 4l16 16",
};

const ACTIONS: { key: string; title: string; icon: string }[] = [
  { key: "qr", title: "Σάρωση QR μέλους", icon: ICONS.qr },
  { key: "phone", title: "Αναζήτηση με τηλέφωνο", icon: ICONS.phone },
  { key: "coffee", title: "Καταχώρηση δωρεάν καφέ", icon: ICONS.coffee },
  { key: "history", title: "Ιστορικό μέλους", icon: ICONS.history },
];

function Centered({ icon, title, children, tone = "neutral" }: { icon: string; title: string; children: ReactNode; tone?: "neutral" | "warn" }) {
  return (
    <main className="mx-auto flex min-h-[70vh] max-w-[480px] flex-col items-center justify-center px-5 py-10 text-center">
      <span
        className={`mb-5 flex h-14 w-14 items-center justify-center rounded-full ${
          tone === "warn" ? "bg-maroon/10 text-maroon" : "bg-bronze/12 text-bronze-dark"
        }`}
      >
        <Icon path={icon} />
      </span>
      <h1 className="font-literata mb-2 text-xl font-semibold">{title}</h1>
      {children}
    </main>
  );
}

function PrimaryButton({ onClick, children, disabled }: { onClick: () => void; children: ReactNode; disabled?: boolean }) {
  return (
    <button
      onClick={onClick}
      disabled={disabled}
      className="mt-6 min-h-12 w-full max-w-[320px] rounded-xl border-none bg-bronze-dark px-6 py-3.5 text-sm font-semibold text-white disabled:opacity-50"
    >
      {children}
    </button>
  );
}

function Dashboard({ state, controller }: { state: Extract<StaffState, { phase: "ready" }>; controller: StaffController }) {
  return (
    <main className="mx-auto max-w-[560px] px-5 pt-8 pb-12 md:max-w-[880px] md:px-8 md:pt-12">
      <h1 className="font-literata mb-1.5 text-[24px] font-semibold">Καλώς ήρθατε</h1>
      <p className="mb-7 text-sm text-espresso/60">Περιβάλλον προσωπικού του Ευαγγέλου Club.</p>

      <div className="mb-6 grid gap-4 md:grid-cols-2">
        <section className="rounded-2xl bg-surface p-5 shadow-[0_4px_18px_rgba(30,24,18,0.06)]" aria-label="Κατάσταση σύνδεσης">
          <div className="mb-2 flex items-center gap-2.5 text-bronze-dark">
            <Icon path={ICONS.shield} />
            <h2 className="text-[15px] font-semibold text-espresso">Σύνδεση προσωπικού</h2>
          </div>
          <p className="text-[13.5px] text-espresso/70">
            Ενεργή έως <span className="font-semibold text-espresso">{formatClockTime(state.session.expiresAt)}</span>
          </p>
          <p className="mt-1 text-[12.5px] text-espresso/50">Η οθόνη κλειδώνει αυτόματα μετά από 5 λεπτά αδράνειας.</p>
        </section>

        <section className="rounded-2xl border border-espresso/10 bg-surface p-5" aria-label="Κατάσταση Club">
          <h2 className="mb-2 text-[15px] font-semibold">Σύνδεση με τα μέλη του Club</h2>
          <p className="text-[13.5px] text-espresso/70">
            <span className="mr-2 inline-block h-2 w-2 rounded-full bg-disabled align-middle" aria-hidden="true" />
            Δεν έχει ενεργοποιηθεί ακόμη.
          </p>
          <p className="mt-1 text-[12.5px] text-espresso/50">Καμία καταχώρηση καφέ δεν είναι δυνατή μέχρι τότε.</p>
        </section>
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {ACTIONS.map((action) => (
          <div
            key={action.key}
            role="group"
            aria-disabled="true"
            aria-label={`${action.title} — μη διαθέσιμη`}
            className="flex items-start gap-3.5 rounded-2xl border border-dashed border-espresso/20 bg-surface/60 p-5 text-espresso/55"
          >
            <span className="flex h-11 w-11 flex-none items-center justify-center rounded-xl bg-hairline text-espresso/45">
              <Icon path={action.icon} />
            </span>
            <span>
              <span className="block text-[15px] font-semibold text-espresso/70">{action.title}</span>
              <span className="mt-0.5 block text-[12.5px]">{NOT_READY_MESSAGE}</span>
            </span>
          </div>
        ))}
      </div>

      <div className="mt-8 flex flex-col gap-2.5 sm:flex-row">
        <button
          onClick={() => void controller.refreshStatus()}
          className="min-h-12 flex-1 rounded-xl border border-espresso/20 bg-surface px-5 py-3 text-sm font-semibold"
        >
          Έλεγχος σύνδεσης
        </button>
        <button
          onClick={() => void controller.lock()}
          className="min-h-12 flex-1 rounded-xl border border-espresso/20 bg-surface px-5 py-3 text-sm font-semibold"
        >
          Κλείδωμα οθόνης
        </button>
      </div>
    </main>
  );
}

export function StaffApp({ controller }: { controller: StaffController }) {
  const state = useSyncExternalStore(controller.subscribe, controller.getState);
  const login = () => void controller.reauthenticate();

  let content: ReactNode;
  switch (state.phase) {
    case "loading":
      content = (
        <Centered icon={ICONS.shield} title="Έλεγχος σύνδεσης…">
          <p className="text-sm text-espresso/60">Παρακαλώ περιμένετε.</p>
        </Centered>
      );
      break;
    case "ready":
      content = <Dashboard state={state} controller={controller} />;
      break;
    case "locked":
      content = (
        <Centered icon={ICONS.lock} title="Η οθόνη κλειδώθηκε">
          <p className="text-sm text-espresso/65">
            Για λόγους ασφαλείας η οθόνη κλειδώνει μετά από 5 λεπτά αδράνειας. Συνδεθείτε ξανά για να συνεχίσετε.
          </p>
          {!state.ending && !state.serverEnded && (
            <p className="mt-3 text-[12.5px] text-maroon">
              Δεν ήταν δυνατή η επικοινωνία με τον διακομιστή. Η συνεδρία θα λήξει αυτόματα.
            </p>
          )}
          <PrimaryButton onClick={login} disabled={state.ending}>
            {state.ending ? "Τερματισμός συνεδρίας…" : "Σύνδεση ξανά"}
          </PrimaryButton>
        </Centered>
      );
      break;
    case "expired":
      content = (
        <Centered icon={ICONS.lock} title="Η σύνδεση έληξε">
          <p className="text-sm text-espresso/65">Η συνεδρία τερματίστηκε (λήξη χρόνου, αδράνεια ή αποσύνδεση από διαχειριστή).</p>
          <PrimaryButton onClick={login}>Σύνδεση ξανά</PrimaryButton>
        </Centered>
      );
      break;
    case "forbidden":
      content = (
        <Centered icon={ICONS.shield} title="Δεν επιτρέπεται η πρόσβαση" tone="warn">
          <p className="text-sm text-espresso/65">Ο λογαριασμός δεν έχει δικαίωμα χρήσης του Club.</p>
          <PrimaryButton onClick={login}>Σύνδεση με άλλο λογαριασμό</PrimaryButton>
        </Centered>
      );
      break;
    case "offline":
      content = (
        <Centered icon={ICONS.wifi} title="Χωρίς σύνδεση" tone="warn">
          <p className="text-sm text-espresso/65">Δεν υπάρχει επικοινωνία με τον διακομιστή. Ελέγξτε το δίκτυο.</p>
          <PrimaryButton onClick={() => void controller.refreshStatus()}>Δοκιμή ξανά</PrimaryButton>
        </Centered>
      );
      break;
    case "error":
      content = (
        <Centered icon={ICONS.shield} title="Προσωρινό σφάλμα" tone="warn">
          <p className="text-sm text-espresso/65">Η κατάσταση της σύνδεσης δεν ήταν δυνατό να ελεγχθεί.</p>
          <PrimaryButton onClick={() => void controller.refreshStatus()}>Δοκιμή ξανά</PrimaryButton>
        </Centered>
      );
      break;
  }

  return (
    <ClubShell logoSrc={logoUrl} exitLabel="Αποσύνδεση" onExit={() => void controller.logout()}>
      {content}
    </ClubShell>
  );
}

export function ConfigErrorScreen() {
  return (
    <ClubShell logoSrc={logoUrl} exitLabel="Σύνδεση" onExit={() => window.location.reload()}>
      <Centered icon={ICONS.shield} title="Η εφαρμογή δεν είναι διαθέσιμη" tone="warn">
        <p className="text-sm text-espresso/65">Η σελίδα δεν φορτώθηκε σωστά. Ανανεώστε τη σελίδα ή συνδεθείτε ξανά.</p>
      </Centered>
    </ClubShell>
  );
}
