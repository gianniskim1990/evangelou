import { useEffect, useMemo, useRef, useSyncExternalStore, type ReactNode, type RefObject } from "react";
import { Btn } from "../preview/ds";
import type { MemberQrFlow, MemberQrState } from "./memberQr";
import { encodeQr } from "./qrEncode";

/*
 * STAGE-05D: the member's real Club QR inside the approved pass (replaces the
 * sample code only when the server's QR route exists). Greek copy, polite
 * live region for status changes, focus moves to the confirmation question.
 * The token is drawn, never printed as text.
 */

const ATHENS_DATE = new Intl.DateTimeFormat("el-GR", { timeZone: "Europe/Athens", day: "numeric", month: "long", year: "numeric" });

export function QrSvg({ text, size = 196 }: { text: string; size?: number }) {
  const matrix = useMemo(() => encodeQr(text), [text]);
  const n = matrix.length;
  const quiet = 4;
  const path = useMemo(() => {
    let d = "";
    for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) if (matrix[y][x]) d += `M${x + quiet} ${y + quiet}h1v1h-1z`;
    return d;
  }, [matrix, n]);
  return (
    <svg width={size} height={size} viewBox={`0 0 ${n + quiet * 2} ${n + quiet * 2}`} role="img" aria-label="Ο κωδικός QR της κάρτας μέλους σας" shapeRendering="crispEdges" className="block" data-evc-qr="member">
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

function body(state: MemberQrState, flow: MemberQrFlow, confirmRef: RefObject<HTMLHeadingElement | null>) {
  switch (state.phase) {
    case "loading":
    case "replacing":
      return <Message title={state.phase === "loading" ? "Φόρτωση του κωδικού σας…" : "Δημιουργία νέου κωδικού…"} />;
    case "shown":
      return (
        <div className="text-center">
          <div className="inline-block">
            <QrSvg text={state.token} size={196} />
          </div>
          <p className="t-small mt-3 text-[var(--ink-2)]">Ενεργός από τις {ATHENS_DATE.format(new Date(state.issuedAt))}. Μένει ίδιος μέχρι να ζητήσετε νέο. Μπορείτε να κρατήσετε στιγμιότυπο οθόνης.</p>
          <button type="button" onClick={() => flow.askReplace()} className="press t-small mt-2 min-h-11 rounded-full px-3 font-semibold text-[var(--ink)] underline underline-offset-4">
            Νέο QR
          </button>
        </div>
      );
    case "elsewhere":
      return (
        <Message title={state.reason === "created_elsewhere" ? "Ο κωδικός σας δημιουργήθηκε σε άλλη συσκευή" : "Ο κωδικός σας δεν φαίνεται σε αυτή τη συσκευή"}>
          <p>Για λόγους ασφαλείας ο κωδικός QR εμφανίζεται μόνο τη στιγμή που δημιουργείται. Αν έχετε στιγμιότυπο οθόνης, ισχύει κανονικά. Διαφορετικά, ζητήστε νέο κωδικό.</p>
          <Btn onClick={() => flow.askReplace()} className="mt-4">Νέο QR</Btn>
        </Message>
      );
    case "confirm_replace":
      return (
        <div role="alertdialog" aria-labelledby="evc-qr-confirm-title" aria-describedby="evc-qr-confirm-text" className="mx-auto max-w-[30ch] py-4 text-center">
          <h2 id="evc-qr-confirm-title" ref={confirmRef} tabIndex={-1} className="t-title text-[var(--ink)]">Θέλετε νέο κωδικό QR;</h2>
          <p id="evc-qr-confirm-text" className="t-small mt-2 text-[var(--ink-2)]">
            Ο τωρινός κωδικός, και κάθε στιγμιότυπο οθόνης του, θα σταματήσει να ισχύει αμέσως, σε όλες τις συσκευές.
          </p>
          <div className="mt-4 flex flex-wrap justify-center gap-3">
            <Btn onClick={() => void flow.confirmReplace()}>Ναι, νέο QR</Btn>
            <Btn tone="line" onClick={() => flow.cancelReplace()}>Άκυρο</Btn>
          </div>
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
          <p>Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.</p>
          <Btn onClick={() => void flow.open()} className="mt-4">Δοκιμάστε ξανά</Btn>
        </Message>
      );
    case "off":
      return null;
  }
}

export function MemberQrPanel({ flow }: { flow: MemberQrFlow }) {
  const state = useSyncExternalStore(flow.subscribe, flow.getState);
  const confirmRef = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    if (state.phase === "confirm_replace") confirmRef.current?.focus();
  }, [state.phase]);
  return (
    <div data-evc-qr-state={state.phase} aria-live="polite" aria-busy={state.phase === "loading" || state.phase === "replacing"}>
      {body(state, flow, confirmRef)}
    </div>
  );
}
