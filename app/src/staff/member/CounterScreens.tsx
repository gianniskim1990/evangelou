import { useEffect, useRef, useState, useSyncExternalStore, type FormEvent } from "react";
import { COUNTER_COFFEES } from "./coffeeCatalogue";
import type { CounterFlow, CounterState, ScanNotice } from "./counterFlow";
import { cameraSupported, startQrCamera, type CameraEnv, type CameraFailure, type CameraSession } from "./qrCamera";

/*
 * STAGE-05D: the real counter screens (Greek). Shown by StaffApp ONLY while
 * the session is ready and GET /session reports features.qr_lookup === true.
 * Staff see the first name and active / used_today / inactive only (D8);
 * there is no revoke button (D10). Every screen change moves focus to its
 * heading; results are announced through live regions.
 */

const STATUS_TEXT = {
  active: "Ενεργό μέλος · δικαιούται καφέ σήμερα",
  used_today: "Ο σημερινός καφές έχει ήδη δοθεί",
  inactive: "Η συνδρομή δεν είναι ενεργή",
} as const;

const STATUS_DOT = { active: "bg-[#2f7d4f]", used_today: "bg-bronze", inactive: "bg-maroon" } as const;

function noticeText(n: ScanNotice, now: number, blockedUntil: number | null): string {
  switch (n.kind) {
    case "recorded":
      return `Ο καφές καταχωρήθηκε${n.firstName ? ` για ${n.firstName}` : ""}: ${n.coffeeLabel}.`;
    case "not_found":
      return "Δεν βρέθηκε μέλος με αυτόν τον κωδικό. Ο πελάτης μπορεί να έχει ζητήσει νέο QR: ας ανοίξει ξανά την κάρτα του.";
    case "invalid_qr":
      return "Αυτός δεν είναι κωδικός QR του Club.";
    case "rate_limited": {
      const minutes = blockedUntil === null ? 5 : Math.max(1, Math.ceil((blockedUntil - now) / 60_000));
      return `Πολλές αποτυχημένες σαρώσεις από αυτό το tablet. Περιμένετε περίπου ${minutes} ${minutes === 1 ? "λεπτό" : "λεπτά"} και δοκιμάστε ξανά.`;
    }
    case "unavailable":
      return "Η υπηρεσία μελών δεν είναι διαθέσιμη αυτή τη στιγμή. Δεν δίνεται καφές μέχρι να επιβεβαιωθεί το μέλος.";
    case "offline":
      return "Δεν υπάρχει σύνδεση με τον διακομιστή. Ελέγξτε το δίκτυο και δοκιμάστε ξανά.";
    case "error":
      return "Μη αναμενόμενη απάντηση. Δοκιμάστε ξανά.";
  }
}

function useFocusHeading(key: string) {
  const ref = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    ref.current?.focus();
  }, [key]);
  return ref;
}

const BTN_PRIMARY = "min-h-12 rounded-xl border-none bg-bronze-dark px-6 py-3.5 text-sm font-semibold text-white disabled:opacity-50";
const BTN_SECONDARY = "min-h-12 rounded-xl border border-espresso/20 bg-surface px-5 py-3 text-sm font-semibold";

function Scan({ flow, state, camera }: { flow: CounterFlow; state: Extract<CounterState, { screen: "scan" }>; camera: CameraEnv }) {
  const now = () => flow.clock();
  const heading = useFocusHeading("scan");
  const [code, setCode] = useState("");
  const [cameraOn, setCameraOn] = useState(false);
  const [cameraError, setCameraError] = useState<CameraFailure | null>(null);
  const video = useRef<HTMLVideoElement>(null);
  const session = useRef<CameraSession | null>(null);
  const blocked = state.blockedUntil !== null && now() < state.blockedUntil;
  const canCamera = cameraSupported(camera);

  const stopCamera = () => {
    session.current?.stop();
    session.current = null;
    setCameraOn(false);
  };
  // The camera never outlives this screen (lock, logout, «Πίσω» or a member found all unmount it).
  useEffect(() => () => session.current?.stop(), []);

  const openCamera = async () => {
    if (!video.current || blocked) return;
    setCameraError(null);
    setCameraOn(true);
    try {
      session.current = await startQrCamera(
        video.current,
        (read) => {
          session.current = null;
          setCameraOn(false);
          void flow.submitScan(read);
        },
        camera,
      );
    } catch (failure) {
      setCameraOn(false);
      setCameraError(failure === "denied" ? "denied" : "unavailable");
    }
  };

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const value = code;
    setCode("");
    stopCamera();
    if (value.trim() !== "") void flow.submitScan(value);
  };

  const success = state.notice?.kind === "recorded";
  return (
    <main className="mx-auto max-w-[560px] px-5 pt-8 pb-12 md:px-8 md:pt-12" data-evc-counter="scan">
      <h1 ref={heading} tabIndex={-1} className="font-literata mb-1.5 text-[24px] font-semibold outline-none">
        Σάρωση QR μέλους
      </h1>
      <p className="mb-6 text-sm text-espresso/60">Ο πελάτης δείχνει τον κωδικό QR της κάρτας του από το κινητό του (ή στιγμιότυπο οθόνης).</p>

      <div role={success ? "status" : "alert"} aria-live={success ? "polite" : "assertive"} className="min-h-6">
        {state.notice && (
          <p className={`mb-5 rounded-xl px-4 py-3 text-[14px] ${success ? "bg-[#2f7d4f]/10 text-espresso" : "bg-maroon/10 text-maroon"}`}>
            {noticeText(state.notice, now(), state.blockedUntil)}
          </p>
        )}
      </div>

      {canCamera && (
        <section aria-label="Κάμερα" className="mb-6">
          <div className={`relative mx-auto aspect-square max-w-[320px] overflow-hidden rounded-2xl bg-espresso ${cameraOn ? "" : "hidden"}`}>
            <video ref={video} className="h-full w-full object-cover" aria-label="Εικόνα κάμερας για τον κωδικό QR" />
          </div>
          {cameraOn ? (
            <button type="button" onClick={stopCamera} className={`${BTN_SECONDARY} mt-3 w-full`}>
              Κλείσιμο κάμερας
            </button>
          ) : (
            <button type="button" onClick={() => void openCamera()} disabled={state.busy || blocked} className={`${BTN_PRIMARY} w-full`}>
              Άνοιγμα κάμερας
            </button>
          )}
          {cameraError && (
            <p role="alert" className="mt-2 text-[13px] text-maroon">
              {cameraError === "denied" ? "Δεν δόθηκε άδεια για την κάμερα. Επιτρέψτε την κάμερα στις ρυθμίσεις του browser ή χρησιμοποιήστε τον σαρωτή." : "Η κάμερα δεν είναι διαθέσιμη. Χρησιμοποιήστε τον σαρωτή."}
            </p>
          )}
        </section>
      )}

      <form onSubmit={submit} className="rounded-2xl border border-espresso/10 bg-surface p-5">
        <label htmlFor="evc-scan-code" className="mb-2 block text-[14px] font-semibold">
          Σαρωτής QR
        </label>
        <p id="evc-scan-help" className="mb-3 text-[12.5px] text-espresso/55">
          Με σαρωτή χειρός: πατήστε στο πεδίο και σαρώστε τον κωδικό.
        </p>
        <input
          id="evc-scan-code"
          aria-describedby="evc-scan-help"
          value={code}
          onChange={(e) => setCode(e.target.value)}
          autoComplete="off"
          autoCapitalize="off"
          spellCheck={false}
          maxLength={256}
          disabled={state.busy || blocked}
          className="min-h-12 w-full rounded-xl border border-espresso/20 bg-white px-4 text-[15px]"
        />
        <button type="submit" disabled={state.busy || blocked || code.trim() === ""} className={`${BTN_PRIMARY} mt-3 w-full`}>
          {state.busy ? "Αναζήτηση μέλους…" : "Αναζήτηση"}
        </button>
      </form>

      <button type="button" onClick={() => flow.home()} className={`${BTN_SECONDARY} mt-6 w-full`}>
        Πίσω στην αρχική
      </button>
    </main>
  );
}

function Member({ flow, state }: { flow: CounterFlow; state: Extract<CounterState, { screen: "member" }> }) {
  const heading = useFocusHeading(`member-${state.member.memberId}`);
  const name = state.member.firstName ?? "Μέλος του Club";
  const busy = state.redeem === "busy";
  const locked = state.redeem !== "idle";
  return (
    <main className="mx-auto max-w-[560px] px-5 pt-8 pb-12 md:px-8 md:pt-12" data-evc-counter="member" data-evc-member-status={state.status}>
      <h1 ref={heading} tabIndex={-1} className="font-literata mb-2 text-[28px] font-semibold outline-none">
        {name}
      </h1>
      <p className="mb-6 flex items-center gap-2 text-[15px]" data-evc-status-line="">
        <span className={`inline-block h-2.5 w-2.5 rounded-full ${STATUS_DOT[state.status]}`} aria-hidden="true" />
        {STATUS_TEXT[state.status]}
      </p>

      {state.message && (
        <p role="alert" className="mb-5 rounded-xl bg-maroon/10 px-4 py-3 text-[14px] text-maroon">
          {state.message}
        </p>
      )}

      {state.status === "active" && (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            void flow.redeem();
          }}
        >
          <fieldset disabled={locked} className="mb-5">
            <legend className="mb-3 text-[15px] font-semibold">Ποιος καφές;</legend>
            <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
              {COUNTER_COFFEES.map((c) => (
                <label key={c.code} className={`flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 text-[15px] ${state.coffee === c.code ? "border-bronze-dark bg-bronze/10 font-semibold" : "border-espresso/15 bg-surface"}`}>
                  <input type="radio" name="evc-coffee" value={c.code} checked={state.coffee === c.code} onChange={() => flow.chooseCoffee(c.code)} className="h-5 w-5 accent-[#8a5a2b]" />
                  {c.label}
                </label>
              ))}
            </div>
          </fieldset>
          <button type="submit" disabled={state.coffee === null || busy} aria-busy={busy} className={`${BTN_PRIMARY} w-full`}>
            {busy ? "Καταχώρηση…" : state.redeem === "retry" ? "Δοκιμή ξανά" : "Καταχώρηση καφέ"}
          </button>
        </form>
      )}

      <button type="button" onClick={() => flow.openScan()} disabled={busy} className={`${BTN_SECONDARY} mt-4 w-full`}>
        Επόμενος πελάτης
      </button>
    </main>
  );
}

export function CounterScreens({ flow, camera }: { flow: CounterFlow; camera: CameraEnv }) {
  const state = useSyncExternalStore(flow.subscribe, flow.getState);
  if (state.screen === "scan") return <Scan flow={flow} state={state} camera={camera} />;
  if (state.screen === "member") return <Member flow={flow} state={state} />;
  return null;
}
