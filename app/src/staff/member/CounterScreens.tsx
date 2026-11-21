import { useEffect, useRef, useState, useSyncExternalStore, type FormEvent } from "react";
import type { Catalogue } from "../../club/preparationContract";
import { COUNTER_COFFEES } from "./coffeeCatalogue";
import { NOTE_MAX, NOTE_TOO_LONG, acceptNoteEdit, visibleOrderGroups } from "./preparedOrder";
import { countdown, type CounterFlow, type CounterState, type ScanNotice } from "./counterFlow";
import { cameraSupported, startQrCamera, type CameraEnv, type CameraFailure } from "./qrCamera";

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

/** The notice and whether it is good news (status region) or a problem (alert region). */
function noticeText(n: ScanNotice, now: number, blockedUntil: number | null): { text: string; good: boolean } {
  switch (n.kind) {
    case "recorded":
      return { text: `Ο καφές καταχωρήθηκε: ${n.coffeeLabel}${n.firstName ? ` · ${n.firstName}` : ""}`, good: true };
    case "not_found":
      return { text: "Δεν βρέθηκε μέλος με αυτόν τον κωδικό. Ο κωδικός του πελάτη μπορεί να έχει ανανεωθεί: ζητήστε του να ανοίξει ξανά την κάρτα του.", good: false };
    case "invalid_qr":
      return { text: "Αυτός δεν είναι κωδικός QR του Club. Σαρώστε ξανά τον κωδικό της κάρτας μέλους.", good: false };
    case "rate_limited":
      return blockedUntil !== null && now < blockedUntil
        ? { text: `Πολλές αποτυχημένες σαρώσεις από αυτό το tablet. Δοκιμάστε ξανά σε ${countdown(blockedUntil, now)}.`, good: false }
        : { text: "Μπορείτε να σαρώσετε ξανά.", good: true };
    case "unavailable":
      return { text: "Η υπηρεσία μελών δεν είναι διαθέσιμη αυτή τη στιγμή. Δεν δίνεται καφές μέχρι να επιβεβαιωθεί το μέλος.", good: false };
    case "offline":
      return { text: "Δεν υπάρχει σύνδεση με τον διακομιστή. Ελέγξτε το δίκτυο και δοκιμάστε ξανά.", good: false };
    case "error":
      return { text: "Μη αναμενόμενη απάντηση. Δοκιμάστε ξανά.", good: false };
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
  const now = flow.clock();
  const canCamera = cameraSupported(camera);
  const input = useRef<HTMLInputElement>(null);
  /** «Πληκτρολόγηση κωδικού»: only then the on-screen keyboard (inputMode text) for typing / pasting. */
  const [keyboard, setKeyboard] = useState(false);
  const [code, setCode] = useState("");
  const [cameraOn, setCameraOn] = useState(false);
  const [cameraError, setCameraError] = useState<CameraFailure | null>(null);
  const [, redraw] = useState(0);
  const video = useRef<HTMLVideoElement>(null);
  /** The ONE running (or starting) camera: aborting it stops the stream, even one still being granted. */
  const camRun = useRef<AbortController | null>(null);
  const blocked = state.blockedUntil !== null && now < state.blockedUntil;

  const stopCamera = () => {
    camRun.current?.abort();
    camRun.current = null;
    setCameraOn(false);
  };
  // The camera never outlives this screen (lock, logout, «Πίσω» or a member found all unmount it).
  useEffect(() => () => camRun.current?.abort(), []);

  /*
   * Round 3 (review MINOR 4): the scanner field has focus WHENEVER the camera is not running (on arrival,
   * after a lookup, after a wait, after «Κλείσιμο κάμερας»), so a USB / Bluetooth hand scanner works
   * without a tap, even on a tablet that also has a camera. To keep the on-screen keyboard from covering
   * the screen, the field is inputMode "none" (a hardware scanner or keyboard still types into it);
   * «Πληκτρολόγηση κωδικού» switches to the normal keyboard. The field's label tells a screen reader
   * where it is («Σαρωτής QR»).
   */
  // Round 4 (review MINOR 2): never steal focus. Refocus ONLY when focus is nowhere (body), inside the scan
  // form itself (the field or its «Αναζήτηση» button, so the next hand-scanner read lands in the field), or
  // on an element that just left the page (e.g. the camera's «Κλείσιμο» button). A staff member who moved
  // to «Πίσω στην αρχική» or «Πληκτρολόγηση» elsewhere keeps the focus there when a wait ends.
  useEffect(() => {
    if (state.busy || blocked || cameraOn) return;
    const field = input.current;
    const active = field?.ownerDocument.activeElement ?? null;
    const free = active === null || active === field?.ownerDocument.body || !active.isConnected || (field?.form?.contains(active) ?? false);
    if (field && free) field.focus({ preventScroll: true });
  }, [state.busy, blocked, cameraOn]);
  // D9 wait: redraw every second so the countdown moves and the scan re-opens when Retry-After has passed.
  useEffect(() => {
    if (!blocked) return;
    const id = flow.timers.setInterval(() => redraw((n) => n + 1), 1000);
    return () => flow.timers.clearInterval(id);
  }, [blocked, flow]);

  const openCamera = async () => {
    if (!video.current || blocked || camRun.current) return;
    const run = new AbortController();
    camRun.current = run;
    setCameraError(null);
    setCameraOn(true);
    try {
      await startQrCamera(
        video.current,
        (read) => {
          if (camRun.current === run) camRun.current = null;
          setCameraOn(false);
          void flow.submitScan(read);
        },
        camera,
        run.signal,
      );
    } catch (failure) {
      if (camRun.current === run) camRun.current = null;
      // Closed, locked or left first: whatever the camera answers now is not shown.
      if (failure === "aborted" || run.signal.aborted) return;
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

  const notice = state.notice ? noticeText(state.notice, now, state.blockedUntil) : null;
  return (
    <main className="mx-auto max-w-[560px] px-5 pt-8 pb-12 md:px-8 md:pt-12" data-evc-counter="scan">
      <h1 id="evc-scan-title" className="font-literata mb-1.5 text-[24px] font-semibold">
        Σάρωση QR μέλους
      </h1>
      <p className="mb-6 text-sm text-espresso/60">Ο πελάτης δείχνει τον κωδικό QR της κάρτας του από το κινητό του (ή στιγμιότυπο οθόνης).</p>

      {/* Two fixed live regions: good news is announced politely, problems at once. */}
      <div role="status" aria-live="polite" className="min-h-0">
        {notice?.good && <p className="mb-5 rounded-xl bg-[#2f7d4f]/10 px-4 py-3 text-[14px] text-espresso">{notice.text}</p>}
      </div>
      <div role="alert" aria-live="assertive" className="min-h-0">
        {notice && !notice.good && <p className="mb-5 rounded-xl bg-maroon/10 px-4 py-3 text-[14px] text-maroon">{notice.text}</p>}
        {cameraError && (
          <p className="mb-5 rounded-xl bg-maroon/10 px-4 py-3 text-[14px] text-maroon">
            {cameraError === "denied"
              ? "Δεν δόθηκε άδεια για την κάμερα. Επιτρέψτε την κάμερα στις ρυθμίσεις του προγράμματος περιήγησης ή χρησιμοποιήστε τον σαρωτή."
              : "Η κάμερα δεν είναι διαθέσιμη. Χρησιμοποιήστε τον σαρωτή."}
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
        </section>
      )}

      <form onSubmit={submit} className="rounded-2xl border border-espresso/10 bg-surface p-5">
        <label htmlFor="evc-scan-code" className="mb-2 block text-[14px] font-semibold">
          Σαρωτής QR
        </label>
        <p id="evc-scan-help" className="mb-3 text-[12.5px] text-espresso/55">
          Με σαρωτή χειρός: σαρώστε τον κωδικό (το πεδίο είναι ήδη έτοιμο).
        </p>
        <input
          ref={input}
          id="evc-scan-code"
          aria-describedby="evc-scan-title evc-scan-help"
          onPointerDown={(e) => {
            // A finger or stylus on the field means "I want to type": switch to the on-screen keyboard.
            if (e.pointerType !== "mouse") setKeyboard(true);
          }}
          value={code}
          onChange={(e) => setCode(e.target.value)}
          autoComplete="off"
          autoCorrect="off"
          autoCapitalize="off"
          spellCheck={false}
          inputMode={keyboard ? "text" : "none"}
          enterKeyHint="search"
          maxLength={256}
          disabled={state.busy || blocked}
          className="min-h-12 w-full rounded-xl border border-espresso/20 bg-white px-4 text-[15px]"
        />
        {!keyboard && (
          <button
            type="button"
            onClick={() => {
              setKeyboard(true);
              input.current?.focus();
            }}
            disabled={state.busy || blocked}
            className={`${BTN_SECONDARY} mt-3 w-full`}
          >
            Πληκτρολόγηση κωδικού
          </button>
        )}
        <button type="submit" disabled={state.busy || blocked || code.trim() === ""} className={`${BTN_PRIMARY} mt-3 w-full`}>
          {state.busy ? "Αναζήτηση μέλους…" : blocked && state.blockedUntil !== null ? `Αναμονή ${countdown(state.blockedUntil, now)}` : "Αναζήτηση"}
        </button>
      </form>

      <button type="button" onClick={() => flow.home()} className={`${BTN_SECONDARY} mt-6 w-full`}>
        Πίσω στην αρχική
      </button>
    </main>
  );
}

/** «20/11» in Athens terms (the server sends the Athens business day). */
function shortDate(day: string): string {
  return `${Number(day.slice(8, 10))}/${Number(day.slice(5, 7))}`;
}

const BTN_CHOICE = "min-h-14 rounded-xl border px-4 py-3 text-left text-[16px] font-semibold";
const CHOICE_ON = "border-bronze-dark bg-bronze/10";
const CHOICE_OFF = "border-espresso/15 bg-surface";
const CHIP = "min-h-12 rounded-full border px-4 py-2 text-[14px] font-semibold";

/** D24: the member's own last coffees, with «Το ίδιο με την τελευταία φορά» on the newest. */
function RecentCoffees({ flow, state }: { flow: CounterFlow; state: Extract<CounterState, { screen: "member" }> }) {
  const recent = state.recent;
  if (recent === null || recent.length === 0) return null;
  const last = recent[0];
  const canRepeat = state.status === "active" && state.redeem === "idle" && last.prefill !== null;
  return (
    <section aria-labelledby="evc-recent-title" className="mb-6 rounded-2xl border border-espresso/10 bg-surface p-4" data-evc-recent="">
      <h2 id="evc-recent-title" className="mb-2 text-[15px] font-semibold">
        Τελευταίοι καφέδες
      </h2>
      <ol className="m-0 list-none p-0">
        {recent.map((r, i) => (
          <li key={`${r.businessDate}-${i}`} className="border-b border-espresso/10 py-2 last:border-b-0">
            <p className="text-[15px]">
              <span className="mr-2 text-espresso/60">{shortDate(r.businessDate)}</span>
              <span className="font-semibold">{r.coffeeLabel}</span>
              {r.details.length > 0 && <span className="text-espresso/75"> · {r.details.join(" · ")}</span>}
            </p>
            {r.note !== null && <p className="text-[13.5px] text-espresso/70">Σημείωση: {r.note}</p>}
          </li>
        ))}
      </ol>
      {canRepeat && (
        <>
          <button type="button" onClick={() => flow.sameAsLast()} className={`${BTN_PRIMARY} mt-3 min-h-14 w-full text-[16px]`} data-evc-action="same-as-last">
            Το ίδιο με την τελευταία φορά
          </button>
          {last.prefillPartial && <p className="mt-2 text-[13px] text-espresso/65">Κάποια επιλογή εκείνου του καφέ δεν υπάρχει πια: θα τη διαλέξετε ξανά.</p>}
        </>
      )}
    </section>
  );
}

/** D24: the step-by-step order (coffee → each required step → review with extras and the note). */
function PreparedOrderPanel({ flow, state, catalogue }: { flow: CounterFlow; state: Extract<CounterState, { screen: "member" }>; catalogue: Catalogue }) {
  const stage = flow.stage();
  const heading = useFocusHeading(`${state.member.memberId}-${stage.kind}-${stage.kind === "step" ? stage.group : ""}`);
  const busy = state.redeem === "busy";
  const locked = state.redeem !== "idle";
  const coffee = state.order.coffee === null ? null : (catalogue.coffees.get(state.order.coffee) ?? null);

  if (stage.kind === "coffee" || coffee === null) {
    return (
      <section aria-labelledby="evc-step-title" data-evc-stage="coffee">
        <h2 ref={heading} id="evc-step-title" tabIndex={-1} className="mb-3 text-[17px] font-semibold outline-none">
          Ποιος καφές;
        </h2>
        <div className="grid grid-cols-2 gap-2.5">
          {[...catalogue.coffees.values()].map((c) => (
            <button key={c.code} type="button" disabled={locked} onClick={() => flow.chooseCoffee(c.code)} className={`${BTN_CHOICE} ${CHOICE_OFF}`} data-evc-coffee={c.code}>
              {c.label}
            </button>
          ))}
        </div>
      </section>
    );
  }

  if (stage.kind === "step") {
    const group = coffee.groups.find((g) => g.id === stage.group)!;
    const chosen = state.order.choices[group.id];
    return (
      <section aria-labelledby="evc-step-title" data-evc-stage="step" data-evc-group={group.id}>
        <p className="mb-1 text-[14px] text-espresso/65">{coffee.label}</p>
        <h2 ref={heading} id="evc-step-title" tabIndex={-1} className="mb-3 text-[20px] font-semibold outline-none">
          {group.label}
        </h2>
        <div className="grid grid-cols-2 gap-2.5">
          {group.options.map((o) => (
            <button key={o.id} type="button" disabled={locked} aria-pressed={chosen === o.id} onClick={() => flow.choose(group.id, o.id)} className={`${BTN_CHOICE} ${chosen === o.id ? CHOICE_ON : CHOICE_OFF}`} data-evc-option={o.id}>
              {o.label}
            </button>
          ))}
        </div>
        <button type="button" disabled={locked} onClick={() => flow.back()} className={`${BTN_SECONDARY} mt-4 w-full`}>
          Πίσω
        </button>
      </section>
    );
  }

  // Review: what will be recorded, the optional extras, the quick notes and the note; then confirm.
  const visible = visibleOrderGroups(catalogue, state.order);
  const optional = visible.filter((g) => !g.required);
  const notes = coffee.quickNotes;
  return (
    <section aria-labelledby="evc-step-title" data-evc-stage="review">
      <h2 ref={heading} id="evc-step-title" tabIndex={-1} className="mb-3 text-[20px] font-semibold outline-none">
        Έλεγχος παραγγελίας
      </h2>
      <dl className="m-0 mb-4 rounded-2xl border border-espresso/10 bg-surface px-4">
        {[{ id: null as string | null, label: "Καφές", value: coffee.label }, ...visible.filter((g) => g.required && state.order.choices[g.id] !== undefined).map((g) => ({ id: g.id as string | null, label: g.label, value: g.options.find((o) => o.id === state.order.choices[g.id])?.label ?? "" }))].map((row) => (
          <div key={row.id ?? "coffee"} className="flex min-h-12 items-center justify-between gap-3 border-b border-espresso/10 py-2 last:border-b-0" data-evc-review-row={row.id ?? "coffee"}>
            <dt className="text-[14px] text-espresso/65">{row.label}</dt>
            <dd className="m-0 flex items-center gap-3 text-[15px] font-semibold">
              {row.value}
              {!locked && (
                <button type="button" onClick={() => (row.id === null ? flow.startOver() : flow.edit(row.id))} className="min-h-11 rounded-lg px-2 text-[13.5px] font-semibold text-bronze-dark underline" aria-label={`Αλλαγή: ${row.label}`}>
                  Αλλαγή
                </button>
              )}
            </dd>
          </div>
        ))}
      </dl>
      {optional.map((g) => (
        <fieldset key={g.id} disabled={locked} className="mb-4">
          <legend className="mb-2 text-[14px] font-semibold">{g.label}</legend>
          <div className="flex flex-wrap gap-2">
            {g.options.map((o) => {
              const on = state.order.choices[g.id] === o.id;
              return (
                <button key={o.id} type="button" aria-pressed={on} onClick={() => (on ? flow.clearOptional(g.id) : flow.choose(g.id, o.id))} className={`${CHIP} ${on ? CHOICE_ON : CHOICE_OFF}`} data-evc-extra={`${g.id}:${o.id}`}>
                  {o.label}
                </button>
              );
            })}
          </div>
        </fieldset>
      ))}
      {notes.length > 0 && (
        <fieldset disabled={locked} className="mb-4">
          <legend className="mb-2 text-[14px] font-semibold">Γρήγορες σημειώσεις</legend>
          <div className="flex flex-wrap gap-2">
            {notes.map((id) => {
              const on = state.order.quickNotes.includes(id);
              return (
                <button key={id} type="button" aria-pressed={on} onClick={() => flow.toggleQuickNote(id)} className={`${CHIP} ${on ? CHOICE_ON : CHOICE_OFF}`} data-evc-quick-note={id}>
                  {catalogue.quickNotes.get(id)?.label ?? id}
                </button>
              );
            })}
          </div>
        </fieldset>
      )}
      {catalogue.freeTextNote && <NoteField note={state.order.note} locked={locked} onNote={(n) => flow.setNote(n)} />}
      <button type="button" onClick={() => void flow.redeem()} disabled={busy} aria-busy={busy} className={`${BTN_PRIMARY} min-h-14 w-full text-[16px]`} data-evc-action="record">
        {busy ? "Καταχώρηση…" : state.redeem === "retry" ? "Δοκιμή ξανά" : "Καταχώρηση καφέ"}
      </button>
    </section>
  );
}

function Member({ flow, state }: { flow: CounterFlow; state: Extract<CounterState, { screen: "member" }> }) {
  const heading = useFocusHeading(`member-${state.member.memberId}`);
  const name = state.member.firstName ?? "Μέλος του Club";
  const busy = state.redeem === "busy";
  const locked = state.redeem !== "idle";
  const catalogue = flow.getCatalogue();
  return (
    <main className="mx-auto max-w-[560px] px-5 pt-8 pb-12 md:px-8 md:pt-12" data-evc-counter="member" data-evc-member-status={state.status}>
      <h1 ref={heading} tabIndex={-1} className="font-literata mb-2 text-[28px] font-semibold outline-none">
        {name}
      </h1>
      <p className="mb-6 flex items-center gap-2 text-[15px]" data-evc-status-line="">
        <span className={`inline-block h-2.5 w-2.5 rounded-full ${STATUS_DOT[state.status]}`} aria-hidden="true" />
        {STATUS_TEXT[state.status]}
      </p>

      <div role="alert" aria-live="assertive" className="min-h-0">
        {state.message && <p className="mb-5 rounded-xl bg-maroon/10 px-4 py-3 text-[14px] text-maroon">{state.message}</p>}
      </div>

      <RecentCoffees flow={flow} state={state} />

      {state.status === "active" && state.prepared && catalogue !== null && <PreparedOrderPanel flow={flow} state={state} catalogue={catalogue} />}

      {state.status === "active" && !state.prepared && (
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

/**
 * STAGE-08 round 11 (C1): the free-text staff note. An edit that would pass NOTE_MAX code points (typing or a
 * paste) is refused as a whole, the previous note stays and a short notice says why. While an IME composition is
 * running (compositionstart … compositionend: Greek accents on some keyboards, Chinese / Japanese input) the field
 * shows what is being composed and nothing is rewritten; the rule applies once, at compositionend.
 */
function NoteField({ note, locked, onNote }: { note: string; locked: boolean; onNote: (note: string) => void }) {
  const [composing, setComposing] = useState<string | null>(null);
  const [tooLong, setTooLong] = useState(false);
  const commit = (next: string) => {
    const r = acceptNoteEdit(note, next);
    setTooLong(r.refused);
    if (!r.refused) onNote(r.note);
  };
  return (
    <div className="mb-5">
      <label htmlFor="evc-order-note" className="mb-2 block text-[14px] font-semibold">
        Σημείωση (προαιρετικά)
      </label>
      <input
        id="evc-order-note"
        value={composing ?? note}
        onCompositionStart={(e) => setComposing(e.currentTarget.value)}
        onCompositionEnd={(e) => {
          setComposing(null);
          commit(e.currentTarget.value);
        }}
        onChange={(e) => {
          if (composing !== null || (e.nativeEvent as InputEvent).isComposing === true) {
            setComposing(e.target.value);
            return;
          }
          commit(e.target.value);
        }}
        disabled={locked}
        autoComplete="off"
        aria-describedby="evc-order-note-help"
        aria-invalid={tooLong || undefined}
        className="min-h-12 w-full rounded-xl border border-espresso/20 bg-white px-4 text-[15px]"
      />
      <p id="evc-order-note-help" className="mt-1 text-[12.5px] text-espresso/55">
        Μόνο για την προετοιμασία, για το προσωπικό· δεν τη βλέπει ο πελάτης. {[...note].length}/{NOTE_MAX}
      </p>
      {tooLong && (
        <p role="alert" className="mt-1 text-[13px] font-semibold text-maroon" data-evc-note-notice="too-long">
          {NOTE_TOO_LONG}
        </p>
      )}
    </div>
  );
}
