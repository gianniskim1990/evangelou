import { useEffect, useRef, useState, type ReactNode } from "react";
import { formatClockTime } from "../../club/format";
import { greekDate } from "../../preview/previewData";
import { DefRows, Glyph, PreviewNote, StateMark } from "../../preview/ds";
import { CoffeeConfigurator, OrderSummary } from "../../preview/StaffOrder";
import { StaffFrame } from "../../preview/StaffSignature";
import { cx } from "../../preview/screens";
import { shouldOfferPrepare } from "../redemptionRecovery/machine";
import { describeRecovery } from "../redemptionRecovery/viewModel";
import { RecoveryScreen } from "../recoveryScreen/RecoveryScreen";
import type { CounterFlow, CounterFlowState, LookupNotice } from "./counterFlow";
import { ROTATED_QR_TOKEN, entryById, entryByPreviewId } from "./fakeDirectory";
import type { FakeClubServer } from "./fakeServer";

/*
 * UI-04O LOCAL-ONLY integrated staff counter screens. Visual language and
 * components are the owner-approved UI-02 staff preview (StaffFrame,
 * CoffeeConfigurator, OrderSummary, DefRows, StateMark) and the approved
 * UI-04N RecoveryScreen; the behaviour is the real flow (counterFlow.ts).
 * Greek staff-facing text. Nothing here talks to WordPress.
 */

const BTN_PRIMARY = "press min-h-16 rounded-[14px] bg-[var(--roast)] px-8 text-[18px] font-semibold text-[var(--milk)] hover:bg-[var(--roast-3)]";
const BTN_LINE = "press min-h-16 rounded-[14px] border border-[var(--rule-strong)] text-[17px] font-semibold text-[var(--ink)] hover:border-[var(--ink)]";

/** Moves focus to the screen heading whenever the screen changes (never to a control). */
function useScreenHeading(key: string) {
  const ref = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    ref.current?.focus({ preventScroll: true });
  }, [key]);
  return ref;
}

function FlowBar({ back, onBack, title }: { back?: string; onBack?: () => void; title: string }) {
  return (
    <div className="border-b border-[var(--rule)]">
      <div className="mx-auto flex max-w-[1280px] items-center gap-4 px-3 py-2 md:px-6">
        {back && onBack ? (
          <button type="button" onClick={onBack} className="press t-label flex min-h-12 items-center gap-1 rounded-full pr-4 pl-2 text-[var(--ink)] hover:bg-[rgba(139,118,80,0.1)]">
            <Glyph name="back" size={22} />
            {back}
          </button>
        ) : (
          <span className="flex min-h-12 items-center" />
        )}
        <span className="t-small truncate text-[var(--ink-2)]">{title}</span>
      </div>
    </div>
  );
}

function Page({ children, className }: { children: ReactNode; className?: string }) {
  return <main className={cx("mx-auto max-w-[1280px] px-5 py-6 md:px-8 md:py-8", className)}>{children}</main>;
}

/** Server UTC stamp "YYYY-MM-DD HH:MM:SS.ffffff" → Athens clock time. */
function athensTime(stamp: string): string {
  return formatClockTime(`${stamp.slice(0, 10)}T${stamp.slice(11, 19)}Z`);
}

const LOOKUP_COPY: Record<LookupNotice, string> = {
  not_found: "Δεν βρέθηκε μέλος. Ο κωδικός ή ο αριθμός μπορεί να είναι παλιός ή λάθος.",
  ambiguous: "Ο αριθμός αντιστοιχεί σε περισσότερα από ένα μέλη. Ζητήστε από τον πελάτη να δείξει την κάρτα του.",
  invalid_phone: "Μη έγκυρος αριθμός. Πληκτρολογήστε ελληνικό κινητό (69…).",
  forbidden: "Ο λογαριασμός δεν έχει δικαίωμα αναζήτησης μελών.",
  rate_limited: "Πολλές αναζητήσεις σε λίγο χρόνο. Περιμένετε λίγο και δοκιμάστε ξανά.",
  unavailable: "Η αναζήτηση δεν ήταν δυνατή αυτή τη στιγμή. Δοκιμάστε ξανά.",
  network: "Δεν υπάρχει επικοινωνία με τον διακομιστή. Δοκιμάστε ξανά.",
  timeout: "Ο διακομιστής δεν απάντησε εγκαίρως. Δοκιμάστε ξανά.",
};

function LookupStatus({ state }: { state: CounterFlowState }) {
  return (
    <p role="status" aria-live="polite" className="t-body mt-4 min-h-5 text-[var(--ink-2)]">
      {state.busy ? "Αναζήτηση…" : state.lookupNotice ? <span className="font-semibold text-[var(--stop)]">{LOOKUP_COPY[state.lookupNotice]}</span> : ""}
    </p>
  );
}

// ---- Dashboard ----------------------------------------------------------------------------------

function Dashboard({ flow, state, server }: { flow: CounterFlow; state: CounterFlowState; server: FakeClubServer }) {
  const heading = useScreenHeading(`dashboard-${state.generation}`);
  const ledger = server.todaysLedger();
  const session = state.session.phase === "ready" ? state.session.session : null;
  return (
    <main className="mx-auto grid max-w-[1280px] grid-cols-[minmax(0,1fr)] gap-6 px-5 py-6 md:px-8 md:py-8 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)] lg:gap-8">
      <h1 ref={heading} tabIndex={-1} className="sr-only">Ταμείο</h1>
      <div className="flex flex-col gap-6">
        <button type="button" onClick={() => flow.open("scan")} className="press on-dark group relative flex min-h-[340px] flex-col justify-between overflow-hidden rounded-[18px] bg-[var(--roast)] p-7 text-left text-[var(--milk)] md:min-h-[380px] md:p-9 lg:min-h-[480px]">
          <span className="flex items-start justify-between gap-6">
            <span>
              <span className="t-headline block">Σάρωση κάρτας</span>
              <span className="t-body mt-2 block max-w-[30ch] text-[var(--milk)]/70">Ο πελάτης δείχνει το QR της κάρτας του στην κάμερα.</span>
            </span>
          </span>
          <span className="relative mx-auto my-8 block h-40 w-40 md:h-48 md:w-48" aria-hidden="true">
            {["top-0 left-0 border-t-2 border-l-2 rounded-tl-[10px]", "top-0 right-0 border-t-2 border-r-2 rounded-tr-[10px]", "bottom-0 left-0 border-b-2 border-l-2 rounded-bl-[10px]", "right-0 bottom-0 border-r-2 border-b-2 rounded-br-[10px]"].map((c) => (
              <span key={c} className={cx("absolute h-10 w-10 border-[var(--brass)]", c)} />
            ))}
            <span className="absolute inset-x-5 top-1/2 h-px bg-[var(--brass)]/60" />
          </span>
          <span className="flex items-center justify-end gap-4">
            <span className="inline-flex min-h-14 items-center rounded-full bg-[var(--brass)] px-7 text-[17px] font-semibold text-[var(--roast)] group-hover:bg-[#d6c291]">Άνοιγμα κάμερας</span>
          </span>
        </button>

        <section className="rounded-[18px] border border-[var(--rule-strong)] bg-[var(--paper)] p-5 md:p-6">
          <h2 className="t-title block text-[var(--ink)]">Χωρίς κάρτα; Αναζήτηση με τηλέφωνο</h2>
          <button type="button" onClick={() => flow.open("phone")} className="press mt-4 flex min-h-14 w-full items-center gap-3 rounded-[12px] border border-[var(--rule-strong)] bg-[var(--milk)] px-4 text-left text-[18px] text-[var(--ink-3)] hover:border-[var(--ink)]">
            <Glyph name="phone" className="flex-none text-[var(--ink-3)]" />
            Πληκτρολόγηση κινητού
          </button>
        </section>
      </div>

      <section aria-labelledby="evc-cf-today" className="flex flex-col">
        <div className="rounded-[18px] border border-[var(--rule-strong)] bg-[var(--paper)] p-5">
          <p className="t-label text-[var(--ink)]">Σύνδεση προσωπικού</p>
          <p className="t-small mt-1 text-[var(--ink-2)]">{session ? `Ενεργή έως ${formatClockTime(session.expiresAt)} · κλειδώνει μετά από 5 λεπτά αδράνειας` : "Έλεγχος σύνδεσης…"}</p>
          <button type="button" onClick={() => void flow.lock()} className="press t-label mt-3 min-h-12 rounded-full border border-[var(--rule-strong)] px-5 text-[var(--ink)] hover:border-[var(--ink)]">
            Κλείδωμα οθόνης
          </button>
        </div>
        <div className="mt-8 flex items-baseline justify-between gap-4 border-b border-[var(--rule-strong)] pb-3 pl-3">
          <h2 id="evc-cf-today" className="t-title text-[var(--ink)]">Σήμερα στο ταμείο</h2>
          <p className="t-small t-num text-[var(--ink-2)]">{ledger.length} καταχωρήσεις</p>
        </div>
        <ul className="m-0 list-none p-0">
          {ledger.map((row) => {
            const entry = entryById(row.memberId);
            return (
              <li key={row.requestId} className="grid min-h-16 grid-cols-[3.25rem_minmax(0,1fr)_auto] items-center gap-3 border-b border-[var(--rule)] py-3 pr-1 pl-3">
                <span className="t-small t-num text-[var(--ink-3)]">{athensTime(row.redeemedAtUtc)}</span>
                <span className="min-w-0">
                  <span className="t-body block truncate font-medium text-[var(--ink)]">{entry?.person.name ?? "Μέλος"}</span>
                  <span className="t-small block truncate text-[var(--ink-2)]">{row.coffeeLabel}</span>
                </span>
                <span className="t-small text-right text-[var(--ink-2)]">{row.handoff ? "Δήλωση παράδοσης" : "Χωρίς δήλωση"}</span>
              </li>
            );
          })}
        </ul>
        <PreviewNote className="mt-6">Η λίστα δείχνει μόνο καταχωρήσεις που επιβεβαίωσε ο (προσομοιωμένος) διακομιστής. Η καταχώρηση δεν αποδεικνύει ότι ο καφές παραδόθηκε.</PreviewNote>
      </section>
    </main>
  );
}

// ---- Lookup ------------------------------------------------------------------------------------

const SCAN_CARDS: { label: string; previewId: string | null; hint: string }[] = [
  { label: "Ενεργή κάρτα", previewId: "m01", hint: "Αλεξάνδρα" },
  { label: "Ενεργή κάρτα", previewId: "m05", hint: "Σοφία" },
  { label: "Καφές ήδη σήμερα", previewId: "m02", hint: "Νικόλαος" },
  { label: "Ληγμένη κάρτα", previewId: "m03", hint: "Δήμητρα" },
  { label: "Πληρωμή σε αναμονή", previewId: "m04", hint: "Γιάννης" },
  { label: "Παλιός κωδικός QR", previewId: null, hint: "ακυρωμένη κάρτα" },
];

function Scan({ flow, state }: { flow: CounterFlow; state: CounterFlowState }) {
  const heading = useScreenHeading("scan");
  return (
    <>
      <FlowBar back="Ταμείο" onBack={() => flow.nextCustomer()} title="Σάρωση κάρτας μέλους" />
      <Page className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)] lg:gap-10">
        <div className="on-dark relative flex min-h-[380px] flex-col items-center justify-center overflow-hidden rounded-[18px] bg-[var(--roast)] p-6 text-[var(--milk)] md:min-h-[480px] lg:min-h-[560px]">
          <span className="relative block h-52 w-52 md:h-64 md:w-64" aria-hidden="true">
            {["top-0 left-0 border-t-2 border-l-2 rounded-tl-[12px]", "top-0 right-0 border-t-2 border-r-2 rounded-tr-[12px]", "bottom-0 left-0 border-b-2 border-l-2 rounded-bl-[12px]", "right-0 bottom-0 border-r-2 border-b-2 rounded-br-[12px]"].map((c) => (
              <span key={c} className={cx("absolute h-12 w-12 border-[var(--brass)]", c)} />
            ))}
            <span className="absolute inset-x-6 top-1/2 h-px bg-[var(--brass)]/60" />
          </span>
          <p className="t-title mt-8 text-center">Κρατήστε το QR μέσα στο πλαίσιο</p>
          <p className="t-small mt-2 text-center text-[var(--milk)]/60">Η κάμερα δεν ενεργοποιείται στην προεπισκόπηση.</p>
        </div>
        <section>
          <h1 ref={heading} tabIndex={-1} className="t-headline text-[var(--ink)]">Προσομοίωση σάρωσης</h1>
          <p className="t-body mt-2 max-w-[40ch] text-[var(--ink-2)]">Διαλέξτε ποια κάρτα «σαρώνεται». Ο κωδικός πηγαίνει στον προσομοιωμένο διακομιστή.</p>
          <div className="mt-6 grid gap-3">
            {SCAN_CARDS.map((c) => (
              <button
                key={`${c.label}-${c.hint}`}
                type="button"
                disabled={state.busy}
                onClick={() => void flow.lookup({ qrToken: c.previewId ? entryByPreviewId(c.previewId).qrToken : ROTATED_QR_TOKEN })}
                className="press grid min-h-16 grid-cols-[minmax(0,1fr)_auto] items-center gap-4 rounded-[14px] border border-[var(--rule-strong)] bg-[var(--paper)] px-5 text-left hover:border-[var(--ink)] disabled:cursor-not-allowed disabled:opacity-40"
              >
                <span className="t-title text-[var(--ink)]">{c.label}</span>
                <span className="t-small text-[var(--ink-2)]">{c.hint}</span>
              </button>
            ))}
          </div>
          <LookupStatus state={state} />
          <div className="mt-6 border-t border-[var(--rule-strong)] pt-6">
            <p className="t-body text-[var(--ink)]">Ο πελάτης δεν έχει την κάρτα;</p>
            <button type="button" onClick={() => flow.open("phone")} className={cx(BTN_LINE, "mt-3 px-7")}>Αναζήτηση με τηλέφωνο</button>
          </div>
        </section>
      </Page>
    </>
  );
}

const PHONE_SAMPLES: { digits: string; label: string }[] = [
  { digits: "6910000052", label: "Σοφία" },
  { digits: "6910000014", label: "Αλεξάνδρα" },
  { digits: "6910000099", label: "κοινός αριθμός" },
  { digits: "6910000000", label: "χωρίς μέλος" },
  { digits: "2101234567", label: "σταθερό" },
];

function Phone({ flow, state }: { flow: CounterFlow; state: CounterFlowState }) {
  const heading = useScreenHeading("phone");
  const [digits, setDigits] = useState("");
  const press = (k: string) => setDigits((v) => (k === "del" ? v.slice(0, -1) : k === "clear" ? "" : (v + k).slice(0, 10)));
  const pretty = digits.replace(/^(\d{0,3})(\d{0,3})(\d{0,4}).*$/, (_, a, b, c) => [a, b, c].filter(Boolean).join(" "));
  const keys = ["1", "2", "3", "4", "5", "6", "7", "8", "9", "clear", "0", "del"];
  return (
    <>
      <FlowBar back="Ταμείο" onBack={() => flow.nextCustomer()} title="Αναζήτηση με κινητό τηλέφωνο" />
      <Page className="grid grid-cols-[minmax(0,1fr)] gap-8 md:grid-cols-[minmax(0,340px)_minmax(0,1fr)] lg:grid-cols-[minmax(0,400px)_minmax(0,1fr)] lg:gap-12">
        <section>
          <h1 ref={heading} tabIndex={-1} className="t-title text-[var(--ink)]" id="evc-cf-phone-label">Κινητό τηλέφωνο</h1>
          <output aria-labelledby="evc-cf-phone-label" className="t-num mt-2 flex min-h-18 items-center rounded-[14px] border border-[var(--rule-strong)] bg-[var(--paper)] px-5 text-[30px] font-medium tracking-[0.04em] text-[var(--ink)]">
            {pretty || <span className="text-[20px] text-[var(--ink-3)]">69…</span>}
          </output>
          <div className="mt-4 grid grid-cols-3 gap-2.5">
            {keys.map((k) => (
              <button
                key={k}
                type="button"
                onClick={() => press(k)}
                aria-label={k === "del" ? "Διαγραφή ψηφίου" : k === "clear" ? "Καθαρισμός" : undefined}
                className={cx("press t-num min-h-16 rounded-[12px] text-[22px] font-medium", k === "del" || k === "clear" ? "text-[16px] text-[var(--ink-2)] hover:bg-[rgba(139,118,80,0.1)]" : "border border-[var(--rule-strong)] bg-[var(--paper)] text-[var(--ink)] hover:border-[var(--ink)]")}
              >
                {k === "del" ? "⌫" : k === "clear" ? "Καθαρ." : k}
              </button>
            ))}
          </div>
          <button type="button" disabled={digits.length !== 10 || state.busy} onClick={() => void flow.lookup({ phone: digits })} className={cx(BTN_PRIMARY, "mt-4 w-full disabled:cursor-not-allowed disabled:opacity-40")}>
            Αναζήτηση
          </button>
          <LookupStatus state={state} />
        </section>
        <section>
          <h2 className="t-title border-b border-[var(--rule-strong)] pb-3 text-[var(--ink)]">Δείγματα αριθμών</h2>
          <p className="t-small mt-3 text-[var(--ink-2)]">Ψεύτικοι αριθμοί για την προεπισκόπηση. Πατήστε για να συμπληρωθεί το πεδίο.</p>
          <div className="mt-4 flex flex-wrap gap-2">
            {PHONE_SAMPLES.map((p) => (
              <button key={p.digits} type="button" onClick={() => setDigits(p.digits)} className="press t-label inline-flex min-h-12 items-center gap-2 rounded-full border border-[var(--rule-strong)] bg-[var(--paper)] px-4 text-[var(--ink)] hover:border-[var(--ink)]">
                <span className="t-num">{p.digits.replace(/^(\d{3})(\d{3})(\d{4})$/, "$1 $2 $3")}</span>
                <span className="text-[var(--ink-3)]">{p.label}</span>
              </button>
            ))}
          </div>
          <PreviewNote className="mt-8">Η αναζήτηση επιστρέφει μόνο ποιο μέλος είναι και μια προσωρινή απόδειξη αναζήτησης. Το πλήρες τηλέφωνο δεν εμφανίζεται ποτέ.</PreviewNote>
        </section>
      </Page>
    </>
  );
}

// ---- Member -------------------------------------------------------------------------------------

type Tone = "ok" | "stop" | "wait";
const TONE_BG: Record<Tone, string> = { ok: "bg-[var(--ok-tint)]", stop: "bg-[var(--stop-tint)]", wait: "bg-[var(--wait-tint)]" };
const TONE_FG: Record<Tone, string> = { ok: "text-[var(--ok)]", stop: "text-[var(--stop)]", wait: "text-[var(--wait)]" };
const TONE_SOLID: Record<Tone, string> = { ok: "bg-[var(--ok)]", stop: "bg-[var(--stop)]", wait: "bg-[var(--wait)]" };

function verdictOf(state: CounterFlowState): { tone: Tone; title: string; body: string } {
  const { summary, person } = state;
  const benefit = summary!.benefit;
  if (benefit.state === "available") return { tone: "ok", title: "Δικαιούται καφέ", body: "Η συνδρομή είναι ενεργή και δεν έχει καταχωρηθεί καφές σήμερα (ώρα Ελλάδας)." };
  if (benefit.state === "used") {
    const at = benefit.redeemedAt ? ` στις ${formatClockTime(benefit.redeemedAt)}` : "";
    return { tone: "stop", title: "Όχι καφές σήμερα", body: `Ο καφές της ημέρας έχει ήδη καταχωρηθεί${at}. Νέο δικαίωμα από τα μεσάνυχτα, ώρα Ελλάδας.` };
  }
  if (person?.state === "pending") return { tone: "wait", title: "Πληρωμή σε αναμονή", body: "Η συνδρομή δεν έχει ενεργοποιηθεί ακόμη. Δεν δικαιούται δωρεάν καφέ." };
  return { tone: "stop", title: "Η συνδρομή δεν είναι ενεργή", body: "Δεν δικαιούται δωρεάν καφέ. Το μέλος μπορεί να ανανεώσει από τον λογαριασμό του." };
}

/** Shown when the customer is NOT eligible: a clear reason and one tap to the next customer. */
function Member({ flow, state, today }: { flow: CounterFlow; state: CounterFlowState; today: string }) {
  const heading = useScreenHeading(`member-${state.summary?.member.id}`);
  const v = verdictOf(state);
  const { member } = state.summary!;
  const available = state.summary!.benefit.state === "available";
  return (
    <>
      <FlowBar back="Ταμείο" onBack={() => flow.nextCustomer()} title="Έλεγχος δικαιώματος" />
      <Page>
        <div role="status" className={cx("flex items-center gap-5 rounded-[18px] p-6 md:p-8", TONE_BG[v.tone])}>
          <span className={cx("flex h-16 w-16 flex-none items-center justify-center rounded-full text-white md:h-20 md:w-20", TONE_SOLID[v.tone])}>
            <Glyph name={v.tone === "ok" ? "check" : v.tone === "wait" ? "clock" : "stop"} size={34} />
          </span>
          <span className="min-w-0">
            <span className={cx("t-headline block", TONE_FG[v.tone])}>{v.title}</span>
            <span className="t-body mt-1 block text-[var(--ink)]">{v.body}</span>
          </span>
        </div>
        <div className="mt-8 grid grid-cols-[minmax(0,1fr)] gap-x-12 gap-y-8 md:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
          <section>
            <h1 ref={heading} tabIndex={-1} className="t-headline text-[var(--ink)]">{member.name}</h1>
            <p className="t-body t-num mt-1 text-[var(--ink-2)]">{member.phoneMasked}</p>
            <DefRows
              className="mt-5"
              rows={[
                ["Συνδρομή", state.person ? <StateMark key="s" state={state.person.state} /> : "—"],
                ["Σημερινός καφές", available ? "Δεν έχει καταχωρηθεί" : state.summary!.benefit.state === "used" ? "Έχει καταχωρηθεί" : "Δεν ισχύει"],
                ["Ημέρα", `${greekDate(today, true)} (ώρα Ελλάδας)`],
              ]}
            />
          </section>
          <section className="flex flex-col gap-3 md:pt-2">
            <button type="button" onClick={() => flow.nextCustomer()} className="press min-h-18 rounded-[14px] bg-[var(--roast)] px-6 text-[19px] font-semibold text-[var(--milk)] hover:bg-[var(--roast-3)]">Επόμενος πελάτης</button>
            <PreviewNote>Η σύνοψη είναι ενδεικτική: δεν υπάρχει ακόμη αντίστοιχη διαδρομή v2. Την τελική απόφαση την παίρνει ο διακομιστής τη στιγμή της καταχώρησης.</PreviewNote>
          </section>
        </div>
      </Page>
    </>
  );
}

// ---- Coffee (the final step) -----------------------------------------------------------------

/** "Καταχώρηση καφέ" — the single, deliberate final submission (owner decision UI-04O). */
export const SUBMIT_LABEL = "Καταχώρηση καφέ";
export const SUBMITTING_LABEL = "Καταχώρηση…";

function Coffee({ flow, state }: { flow: CounterFlow; state: CounterFlowState }) {
  const heading = useScreenHeading("coffee");
  const { member } = state.summary!;
  return (
    <>
      <FlowBar back="Ταμείο" onBack={() => flow.nextCustomer()} title={`Καφές για: ${member.name}`} />
      <h1 ref={heading} tabIndex={-1} className="sr-only">Επιλογή καφέ</h1>
      <section aria-label="Πελάτης" className="evc-cf-who" data-evc-cf="who">
        <div className={cx("evc-cf-who__card", TONE_BG.ok)}>
          <span className="evc-cf-who__name">{member.name}</span>
          <span className="evc-cf-who__meta t-num">{member.phoneMasked}</span>
          <span className="evc-cf-who__verdict">
            <Glyph name="check" size={22} />
            Δικαιούται καφέ σήμερα
          </span>
        </div>
      </section>
      <CoffeeConfigurator
        member={state.person!}
        order={state.order}
        dispatch={(a) => flow.dispatchOrder(a)}
        onContinue={() => void flow.submit()}
        continueLabel={state.submitting ? SUBMITTING_LABEL : SUBMIT_LABEL}
        busy={state.submitting}
      />
    </>
  );
}

/** Brief, non-blocking notice after a server-confirmed FRESH commit; it dismisses itself. */
export const SUCCESS_VISIBLE_MS = 2500;

function SuccessToast({ flow, state }: { flow: CounterFlow; state: CounterFlowState }) {
  const success = state.success;
  useEffect(() => {
    if (!success) return;
    const t = setTimeout(() => flow.dismissSuccess(success.seq), SUCCESS_VISIBLE_MS);
    return () => clearTimeout(t);
  }, [flow, success]);
  return (
    <div role="status" aria-live="polite" data-evc-cf="success">
      {success ? (
        <span className="evc-cf-toast">
          <Glyph name="check" size={24} />
          {success.message}
        </span>
      ) : null}
    </div>
  );
}

// ---- Redemption lifecycle (the approved RecoveryScreen) ----------------------------------------

function Redemption({ flow, state, today }: { flow: CounterFlow; state: CounterFlowState; today: string }) {
  const view = describeRecovery(state.machine);
  // The order is shown ONLY while the single "prepare" prompt of a fresh commit is due.
  const prepare = shouldOfferPrepare(state.machine) && state.person !== null;
  return (
    <>
      <FlowBar title="Καταχώρηση καφέ" />
      <Page className="max-w-[880px]">
        <RecoveryScreen key={state.generation} view={view} onAction={(a) => void flow.act(a)} />
        <p role="status" aria-live="polite" className="t-body mx-auto mt-4 max-w-[720px] text-[var(--ink-2)]">{state.notice ?? ""}</p>
        {prepare && (
          <section aria-labelledby="evc-cf-prepare" data-evc-cf="prepare-order" className="mx-auto mt-6 max-w-[720px] rounded-[18px] border border-[var(--rule-strong)] bg-[var(--paper)] px-6 py-4">
            <h2 id="evc-cf-prepare" className="t-title text-[var(--ink)]">Τι ετοιμάζεται</h2>
            <OrderSummary member={state.person!} order={state.order} today={today} />
          </section>
        )}
      </Page>
    </>
  );
}

function Loading() {
  const heading = useScreenHeading("loading");
  return (
    <Page className="max-w-[560px] py-10 text-center md:py-14">
      <h1 ref={heading} tabIndex={-1} className="t-headline text-[var(--ink)]">Έλεγχος σύνδεσης…</h1>
      <p className="t-body mt-2 text-[var(--ink-2)]">Παρακαλώ περιμένετε.</p>
    </Page>
  );
}

export function CounterApp({ flow, state, server, today }: { flow: CounterFlow; state: CounterFlowState; server: FakeClubServer; today: string }) {
  let content: ReactNode;
  if (state.session.phase === "loading") content = <Loading />;
  else if (state.screen === "redemption") content = <Redemption flow={flow} state={state} today={today} />;
  else if (state.screen === "scan") content = <Scan flow={flow} state={state} />;
  else if (state.screen === "phone") content = <Phone flow={flow} state={state} />;
  else if (state.screen === "member" && state.summary) content = <Member flow={flow} state={state} today={today} />;
  else if (state.screen === "coffee" && state.summary && state.person) content = <Coffee flow={flow} state={state} />;
  else content = <Dashboard flow={flow} state={state} server={server} />;
  return (
    <StaffFrame today={today}>
      {content}
      <SuccessToast flow={flow} state={state} />
    </StaffFrame>
  );
}

