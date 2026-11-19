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

function body(state: MemberQrState, flow: MemberQrFlow) {
  switch (state.phase) {
    case "loading":
      return <Message title="Φόρτωση του κωδικού σας…" />;
    case "shown":
      return (
        <div className="text-center">
          <div className="inline-block rounded-[10px] bg-white p-1">
            <QrSvg text={state.token} size={196} />
          </div>
          <p className="t-small mt-3 text-[var(--ink-2)]">Ο κωδικός ανανεώνεται αυτόματα· μόλις ανανεωθεί, ο παλιός και κάθε στιγμιότυπο οθόνης του δεν ισχύουν πια στο ταμείο.</p>
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
      return null;
  }
}

/** What the two fixed live regions announce (the visible text stays outside them). */
function announce(state: MemberQrState): { status: string; alert: string } {
  switch (state.phase) {
    case "loading":
      return { status: "Φόρτωση του κωδικού σας.", alert: "" };
    case "shown":
      return { status: "Ο κωδικός QR σας εμφανίζεται.", alert: "" };
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
  // D21: a tab that becomes visible again asks the server whether its code is still the active one.
  useEffect(() => {
    const doc = globalThis.document;
    if (!doc) return;
    const onVisible = () => {
      if (doc.visibilityState !== "hidden") flow.wake();
    };
    doc.addEventListener("visibilitychange", onVisible);
    globalThis.addEventListener?.("focus", onVisible);
    return () => {
      doc.removeEventListener("visibilitychange", onVisible);
      globalThis.removeEventListener?.("focus", onVisible);
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
