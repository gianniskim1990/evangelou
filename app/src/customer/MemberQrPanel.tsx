import { useEffect, useMemo, useSyncExternalStore, type ReactNode } from "react";
import type { MemberQrFlow, MemberQrState } from "./memberQr";
import { encodeQr } from "./qrEncode";

/*
 * STAGE-05D: the member's real Club QR inside the approved pass (replaces the
 * sample code only when the server's QR route exists). Greek copy, polite
 * live region for status changes. The token is drawn, never printed as text.
 * Owner decision D21 (2026-11-19): the QR is always shown (or «Φόρτωση…»
 * while one is fetched); no «Νέο QR» button. Only the error states offer a
 * small «Δοκιμάστε ξανά» link (memberQr.ts retries by itself, within limits).
 * Owner decision D22 (2026-11-19): the code is the same on every device and
 * never changes when the card is opened. Round 7: when the server cannot be
 * reached the phone's own copy is shown as «unchecked», saying plainly that it
 * was not checked now and will be checked again (`online` re-checks at once).
 * STAGE-08 round 17 (owner-found bug, staging 2026-11-23): this panel is the
 * ONLY thing the live card ever puts in its QR place, from the very first
 * render: until the member's code is known it is a neutral place-holder
 * («Φόρτωση του κωδικού σας…», no pattern); without the QR route (`off`) it says
 * that the code is not available yet. The design preview's sample code is
 * not reachable from the live app at all (src/preview/sampleQr.tsx).
 */


/** STAGE-07: `label` / `kind` (optional) for the tablet poster; the member card's defaults are unchanged. */
export function QrSvg({ text, size = 196, label = "Ο κωδικός QR της κάρτας μέλους σας", kind = "member" }: { text: string; size?: number; label?: string; kind?: "member" | "join" }) {
  const matrix = useMemo(() => encodeQr(text), [text]);
  const n = matrix.length;
  const quiet = 4;
  const path = useMemo(() => {
    let d = "";
    for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) if (matrix[y][x]) d += `M${x + quiet} ${y + quiet}h1v1h-1z`;
    return d;
  }, [matrix, n]);
  return (
    <svg width={size} height={size} viewBox={`0 0 ${n + quiet * 2} ${n + quiet * 2}`} role="img" aria-label={label} shapeRendering="crispEdges" className="block" data-evc-qr={kind}>
      <rect width={n + quiet * 2} height={n + quiet * 2} fill="#ffffff" />
      <path d={path} fill="#000000" />
    </svg>
  );
}

function Message({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="mx-auto max-w-[30ch] py-6 text-center">
      <p className="t-title text-[var(--ink)]">{title}</p>
      {children && <div className="t-small mt-2 text-[var(--ink-2)]">{children}</div>}
    </div>
  );
}

/** The QR's place while the member's code is fetched: the size of the drawn code, words only, never a pattern. */
export function QrLoading() {
  return (
    <div className="inline-block rounded-[10px] bg-[var(--stone)] p-1" style={{ width: 204, height: 204 }} data-evc-qr-placeholder="loading">
      <div className="flex h-full items-center justify-center px-4 text-center">
        <p className="t-small text-[var(--ink-2)]">Φόρτωση του κωδικού σας…</p>
      </div>
    </div>
  );
}

/** The QR's place when this site has no member QR route yet (or no QR flow): words only, never a pattern. */
export function QrNotAvailable() {
  return (
    <div data-evc-qr-placeholder="not-available">
      <Message title="Ο κωδικός QR δεν είναι ακόμη διαθέσιμος">Θα εμφανιστεί σε αυτή την κάρτα μόλις ενεργοποιηθεί.</Message>
    </div>
  );
}

function body(state: MemberQrState, flow: MemberQrFlow) {
  switch (state.phase) {
    case "loading":
      return <QrLoading />;
    case "shown":
      return (
        <div className="text-center">
          <div className="inline-block rounded-[10px] bg-white p-1">
            <QrSvg text={state.token} size={196} />
          </div>
          <p className="t-small mt-3 text-[var(--ink-2)]">Ο κωδικός σας μένει ίδιος σε όλες τις συσκευές σας και δεν αλλάζει όταν ανοίγετε την κάρτα.</p>
          <p className="t-small mt-1 text-[var(--ink-2)]">Στιγμιότυπο οθόνης ισχύει κανονικά στο ταμείο, από όποια συσκευή κι αν το δείξετε.</p>
        </div>
      );
    case "unchecked":
      return (
        <div className="text-center">
          <div className="inline-block rounded-[10px] bg-white p-1">
            <QrSvg text={state.token} size={196} />
          </div>
          <p className="t-small mt-3 text-[var(--ink-2)]">Δεν μπορέσαμε να ελέγξουμε τον κωδικό αυτή τη στιγμή. Δείχνουμε αυτόν που είναι αποθηκευμένος σε αυτή τη συσκευή· θα τον ελέγξουμε ξανά μόλις γίνει δυνατό.</p>
        </div>
      );
    case "not_member":
      return <Message title="Η κάρτα σας δεν είναι ακόμη έτοιμη">Ο κωδικός QR εμφανίζεται μόλις επιβεβαιωθεί η πληρωμή της συνδρομής σας.</Message>;
    case "member_disabled":
      return <Message title="Η κάρτα σας δεν είναι ενεργή">Επικοινωνήστε με το κατάστημα.</Message>;
    case "signed_out":
      return <Message title="Η σύνδεσή σας έληξε">Συνδεθείτε ξανά για να δείτε τον κωδικό σας.</Message>;
    case "forbidden":
      return <Message title="Ο κωδικός δεν είναι διαθέσιμος">Με αυτόν τον λογαριασμό δεν εμφανίζεται κάρτα μέλους.</Message>;
    case "unavailable":
    case "error":
      return (
        <Message title={state.phase === "unavailable" ? "Ο κωδικός δεν είναι διαθέσιμος αυτή τη στιγμή" : "Δεν φορτώθηκε ο κωδικός σας"}>
          <p>Θα ξαναπροσπαθήσουμε αυτόματα σε λίγο.</p>
          <button type="button" onClick={() => flow.retry()} className="press t-small mt-2 min-h-11 rounded-full px-3 font-semibold text-[var(--ink)] underline underline-offset-4">
            Δοκιμάστε ξανά
          </button>
        </Message>
      );
    case "off":
      return <QrNotAvailable />;
  }
}

/** What the two fixed live regions announce (the visible text stays outside them). */
function announce(state: MemberQrState): { status: string; alert: string } {
  switch (state.phase) {
    case "loading":
      return { status: "Φόρτωση του κωδικού σας.", alert: "" };
    case "shown":
      return { status: "Ο κωδικός QR σας εμφανίζεται.", alert: "" };
    case "unchecked":
      return { status: "Εμφανίζεται ο αποθηκευμένος κωδικός QR σας, χωρίς έλεγχο αυτή τη στιγμή.", alert: "" };
    case "unavailable":
    case "error":
    case "signed_out":
      return { status: "", alert: "Ο κωδικός σας δεν φορτώθηκε." };
    default:
      return { status: "", alert: "" };
  }
}

export function MemberQrPanel({ flow }: { flow: MemberQrFlow }) {
  const state = useSyncExternalStore(flow.subscribe, flow.getState);
  // D21: a tab that becomes visible again (or comes back online, round 7) asks the server whether its code is
  // still the active one. The listeners go with the panel (CustomerApp also pauses the flow off the card).
  useEffect(() => {
    const doc = globalThis.document;
    if (!doc) return;
    const onVisible = () => {
      if (doc.visibilityState !== "hidden") flow.wake();
    };
    doc.addEventListener("visibilitychange", onVisible);
    globalThis.addEventListener?.("focus", onVisible);
    globalThis.addEventListener?.("online", onVisible);
    return () => {
      doc.removeEventListener("visibilitychange", onVisible);
      globalThis.removeEventListener?.("focus", onVisible);
      globalThis.removeEventListener?.("online", onVisible);
    };
  }, [flow]);
  const said = announce(state);
  return (
    <div data-evc-qr-state={state.phase} aria-busy={state.phase === "loading"}>
      {/* Two fixed live regions. */}
      <p role="status" aria-live="polite" className="sr-only">
        {said.status}
      </p>
      <p role="alert" aria-live="assertive" className="sr-only">
        {said.alert}
      </p>
      {body(state, flow)}
    </div>
  );
}
