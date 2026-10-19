import type { ReactNode, Ref } from "react";
import type { CustomerMembershipState } from "./customerView";
import { cx, sampleQrModules } from "./screens";

/*
 * Design preview: primitives of the approved "contemporary coffee club"
 * system (scoped by .evc-ds in preview.css), shared by all 26 preview
 * screens. Presentational only, no data access.
 */

export function Logo({ className }: { className?: string }) {
  return <img src="/logo-evaggelou-color.png" alt="Ευαγγέλου" className={cx("object-contain", className)} />;
}

/**
 * The brand motif: nested parallel strokes taken from the wing in the
 * Evangelou mark. Drawn as hairlines, never filled.
 */
export function WingLines({ className, lines = 9, gap = 7 }: { className?: string; lines?: number; gap?: number }) {
  const w = 60 + lines * gap;
  return (
    <svg viewBox={`0 0 ${w} 120`} className={className} fill="none" stroke="currentColor" strokeWidth="1" aria-hidden="true" preserveAspectRatio="xMaxYMid meet">
      {Array.from({ length: lines }, (_, i) => {
        const x = 8 + i * gap;
        return <polyline key={i} points={`${x + 52},${4 + i * 1.5} ${x},${60} ${x + 52},${116 - i * 1.5}`} vectorEffect="non-scaling-stroke" />;
      })}
    </svg>
  );
}

type BtnTone = "roast" | "line" | "brass" | "ghost-dark";

const BTN: Record<BtnTone, string> = {
  roast: "bg-[var(--roast)] text-[var(--milk)] hover:bg-[var(--roast-3)]",
  line: "border border-[var(--rule-strong)] text-[var(--ink)] hover:border-[var(--ink)]",
  brass: "bg-[var(--brass)] text-[var(--roast)] hover:bg-[#d6c291]",
  "ghost-dark": "border border-[var(--brass-soft)] text-[var(--milk)] hover:border-[var(--brass)]",
};

export function Btn({ children, tone = "roast", onClick, className, disabled, title, type = "button" }: { children: ReactNode; tone?: BtnTone; onClick?: () => void; className?: string; disabled?: boolean; title?: string; type?: "button" | "submit" }) {
  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      title={title}
      className={cx("press inline-flex min-h-12 items-center justify-center gap-2 rounded-full px-6 text-[15px] font-semibold disabled:cursor-not-allowed disabled:opacity-45", BTN[tone], className)}
    >
      {children}
    </button>
  );
}

// "cancelled" is used only by the CUSTOMER-01 customer journey preview.
const STATE_TEXT: Record<CustomerMembershipState, string> = { active: "Ενεργή", pending: "Αναμονή πληρωμής", expired: "Έληξε", cancelled: "Ακυρώθηκε" };
const STATE_DOT: Record<CustomerMembershipState, string> = { active: "bg-[var(--ok)]", pending: "bg-[var(--wait)]", expired: "bg-[var(--stop)]", cancelled: "bg-[var(--stop)]" };

/** Status as a dot plus words (never colour alone). */
export function StateMark({ state, className }: { state: CustomerMembershipState; className?: string }) {
  return (
    <span className={cx("inline-flex items-center gap-2 whitespace-nowrap", className)}>
      <span className={cx("h-2 w-2 flex-none rounded-full", STATE_DOT[state])} aria-hidden="true" />
      {STATE_TEXT[state]}
    </span>
  );
}

/** Quiet inline reminder that the screen is a non-functional preview. */
export function PreviewNote({ children, dark = false, className }: { children: ReactNode; dark?: boolean; className?: string }) {
  return (
    <p className={cx("t-small flex max-w-[60ch] items-start gap-2", dark ? "text-[var(--milk)]/60" : "text-[var(--ink-3)]", className)}>
      <span className={cx("mt-[7px] h-[5px] w-[5px] flex-none rotate-45", dark ? "bg-[var(--brass)]" : "bg-[var(--bronze)]")} aria-hidden="true" />
      <span>{children}</span>
    </p>
  );
}

/**
 * A QR-LOOKING pattern for the membership pass. NOT an encoded QR code
 * (seeded random modules), so it cannot be scanned or redeemed, and it is
 * permanently stamped "ΔΕΙΓΜΑ / ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ".
 */
export function PassQr({ seed = 11, size = 196, muted = false }: { seed?: number; size?: number; muted?: boolean }) {
  const n = 25;
  const finder = (x: number, y: number) => (
    <g key={`f${x}-${y}`}>
      <rect x={x} y={y} width="7" height="7" rx="1.4" />
      <rect x={x + 1} y={y + 1} width="5" height="5" rx="0.9" fill="#fffdf9" />
      <rect x={x + 2} y={y + 2} width="3" height="3" rx="0.6" />
    </g>
  );
  return (
    <div className="relative inline-block" data-evc-preview="sample-qr">
      <svg width={size} height={size} viewBox={`-1 -1 ${n + 2} ${n + 2}`} role="img" aria-label="Δείγμα κωδικού QR, μη εξαργυρώσιμο" className={cx("block", muted && "opacity-30")}>
        <g fill="#2a211b">
          {sampleQrModules(seed, n).map(([r, c]) => (
            <rect key={`${r}-${c}`} x={c + 0.06} y={r + 0.06} width="0.88" height="0.88" rx="0.22" />
          ))}
          {finder(0, 0)}
          {finder(n - 7, 0)}
          {finder(0, n - 7)}
        </g>
      </svg>
      <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
        <span className="-rotate-[8deg] border-[1.5px] border-[var(--stop)] bg-[#fffdf9] px-2.5 py-1 text-center text-[11px] leading-[1.25] font-bold tracking-[0.06em] text-[var(--stop)]">
          ΔΕΙΓΜΑ
          <br />
          ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ
        </span>
      </div>
    </div>
  );
}

/** Small stroke icons used by the signature screens (24px grid). */
export function Glyph({ name, size = 20, className }: { name: "scan" | "phone" | "check" | "stop" | "back" | "search" | "clock"; size?: number; className?: string }) {
  const d: Record<typeof name, ReactNode> = {
    scan: (<><path d="M4 8V5.5A1.5 1.5 0 0 1 5.5 4H8M16 4h2.5A1.5 1.5 0 0 1 20 5.5V8M20 16v2.5a1.5 1.5 0 0 1-1.5 1.5H16M8 20H5.5A1.5 1.5 0 0 1 4 18.5V16" /><path d="M4 12h16" /></>),
    phone: (<><rect x="7" y="3" width="10" height="18" rx="2.2" /><path d="M11 17.5h2" /></>),
    check: <path d="M5 12.5l4.5 4.5L19 7.5" />,
    stop: (<><circle cx="12" cy="12" r="8.5" /><path d="M6 18 18 6" /></>),
    back: <path d="M14.5 5.5 8 12l6.5 6.5" />,
    search: (<><circle cx="11" cy="11" r="6.5" /><path d="m20 20-4-4" /></>),
    clock: (<><circle cx="12" cy="12" r="8.5" /><path d="M12 7.5V12l3 2" /></>),
  };
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true">
      {d[name]}
    </svg>
  );
}

type EditableField = {
  onChange: (value: string) => void;
  id: string;
  name: string;
  error?: string;
  required?: boolean;
  autoComplete?: string;
  inputMode?: "text" | "tel" | "email";
};

/**
 * Read-only sample text field (the preview never submits or stores anything).
 * CUSTOMER-01: with `edit` (customer journey preview only) it becomes a real,
 * controlled input with an accessible error; without it the approved markup is unchanged.
 */
export function Field({ label, value, type = "text", hint, edit, inputRef }: { label: string; value: string; type?: string; hint?: string; edit?: EditableField; inputRef?: Ref<HTMLInputElement> }) {
  if (edit) {
    const hintId = `${edit.id}-hint`;
    const errorId = `${edit.id}-error`;
    const describedBy = [edit.error ? errorId : null, hint ? hintId : null].filter(Boolean).join(" ") || undefined;
    return (
      <div>
        <label htmlFor={edit.id} className="t-label block text-[var(--ink)]">
          {label} <span className="t-small font-normal text-[var(--ink-3)]">{edit.required ? "απαιτείται" : "προαιρετικό"}</span>
        </label>
        <input
          ref={inputRef}
          id={edit.id}
          name={edit.name}
          type={type}
          value={value}
          onChange={(e) => edit.onChange(e.target.value)}
          required={edit.required}
          aria-required={edit.required || undefined}
          aria-invalid={edit.error ? true : undefined}
          aria-describedby={describedBy}
          autoComplete={edit.autoComplete}
          inputMode={edit.inputMode}
          className={cx("t-body mt-2 min-h-13 w-full rounded-[12px] border bg-[var(--paper)] px-4 text-[var(--ink)] outline-none focus:border-[var(--ink)]", edit.error ? "border-[var(--stop)]" : "border-[var(--rule-strong)]")}
        />
        {edit.error && (
          <span id={errorId} className="t-small mt-1.5 block font-semibold text-[var(--stop)]">
            {edit.error}
          </span>
        )}
        {hint && <span id={hintId} className="t-small mt-1.5 block text-[var(--ink-3)]">{hint}</span>}
      </div>
    );
  }
  return (
    <label className="block">
      <span className="t-label block text-[var(--ink)]">{label}</span>
      <input type={type} defaultValue={value} readOnly className="t-body mt-2 min-h-13 w-full rounded-[12px] border border-[var(--rule-strong)] bg-[var(--paper)] px-4 text-[var(--ink)] outline-none focus:border-[var(--ink)]" />
      {hint && <span className="t-small mt-1.5 block text-[var(--ink-3)]">{hint}</span>}
    </label>
  );
}

/** A real sequence (onboarding steps), so numbering carries meaning here. */
export function Steps({ steps, active }: { steps: string[]; active: number }) {
  return (
    <ol className="m-0 grid list-none gap-3 p-0" style={{ gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))` }} aria-label="Βήματα εγγραφής">
      {steps.map((s, i) => (
        <li key={s} aria-current={i === active ? "step" : undefined} className={cx("border-t-2 pt-2.5", i <= active ? "border-[var(--roast)]" : "border-[var(--rule-strong)]")}>
          <span className={cx("t-small t-num block", i === active ? "font-semibold text-[var(--ink)]" : "text-[var(--ink-3)]")}>
            {i + 1}. {s}
          </span>
        </li>
      ))}
    </ol>
  );
}

/** A consent choice as a ruled row with a large checkbox; each consent is independent. */
export function ConsentRow({ checked, onChange, title, body, required }: { checked: boolean; onChange: (v: boolean) => void; title: string; body: string; required?: boolean }) {
  return (
    <label className="grid cursor-pointer grid-cols-[1.75rem_minmax(0,1fr)] gap-4 border-b border-[var(--rule-strong)] py-5">
      <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="mt-0.5 h-6 w-6 accent-[#1e1713]" />
      <span>
        <span className="t-title block text-[var(--ink)]">
          {title} <span className="t-small font-normal text-[var(--ink-3)]">{required ? "απαιτείται" : "προαιρετικό"}</span>
        </span>
        <span className="t-body mt-1.5 block max-w-[52ch] text-[var(--ink-2)]">{body}</span>
      </span>
    </label>
  );
}

/** Two-column definition rows used by profile, member and settings screens. */
export function DefRows({ rows, dark = false, className }: { rows: [string, ReactNode][]; dark?: boolean; className?: string }) {
  return (
    <dl className={cx("m-0", className)}>
      {rows.map(([k, v]) => (
        <div key={k} className={cx("grid gap-x-6 gap-y-1 border-b py-3.5 sm:grid-cols-[11rem_minmax(0,1fr)] sm:items-baseline", dark ? "border-[var(--brass-soft)]" : "border-[var(--rule)]")}>
          <dt className={cx("t-small", dark ? "text-[var(--milk)]/60" : "text-[var(--ink-2)]")}>{k}</dt>
          <dd className={cx("t-body m-0", dark ? "text-[var(--milk)]" : "text-[var(--ink)]")}>{v}</dd>
        </div>
      ))}
    </dl>
  );
}
