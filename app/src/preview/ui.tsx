import { useState, type ReactNode } from "react";
import type { PreviewMembershipState } from "./previewData";
import { cx, sampleQrModules } from "./screens";

/* UI-01 design preview: shared presentational pieces. No data access. */

type IconName =
  | "coffee" | "card" | "user" | "history" | "home" | "qr" | "search" | "check" | "x" | "clock"
  | "chart" | "users" | "settings" | "spark" | "mail" | "shield" | "calendar" | "arrow" | "list" | "camera" | "alert";

const PATHS: Record<IconName, ReactNode> = {
  coffee: (<><path d="M5 9h11v5.5A4.5 4.5 0 0 1 11.5 19H9.5A4.5 4.5 0 0 1 5 14.5V9Z" /><path d="M16 10.5h1.5a2.25 2.25 0 0 1 0 4.5H16" /><path d="M8 6c0-1 .8-1.3.8-2.2M11.5 6c0-1 .8-1.3.8-2.2" /></>),
  card: (<><rect x="3" y="5.5" width="18" height="13" rx="2.5" /><path d="M3 10h18M7 15h4" /></>),
  user: (<><circle cx="12" cy="8.5" r="3.5" /><path d="M5 19.5c1.2-3.3 3.8-5 7-5s5.8 1.7 7 5" /></>),
  history: (<><path d="M4 12a8 8 0 1 0 2.4-5.7" /><path d="M4 4.5v3.8h3.8M12 8v4.5l3 1.8" /></>),
  home: (<><path d="M4 10.5 12 4l8 6.5V19a1 1 0 0 1-1 1h-4.5v-5.5h-5V20H5a1 1 0 0 1-1-1v-8.5Z" /></>),
  qr: (<><rect x="4" y="4" width="6" height="6" rx="1" /><rect x="14" y="4" width="6" height="6" rx="1" /><rect x="4" y="14" width="6" height="6" rx="1" /><path d="M14 14h2v2h-2zM18 14h2M14 18h2M18 18h2v2" /></>),
  search: (<><circle cx="11" cy="11" r="6.5" /><path d="m20 20-4-4" /></>),
  check: (<path d="M4.5 12.5l5 5L19.5 7" />),
  x: (<path d="M6 6l12 12M18 6 6 18" />),
  clock: (<><circle cx="12" cy="12" r="8" /><path d="M12 7.5V12l3 2" /></>),
  chart: (<><path d="M4 20h16" /><path d="M7 16v-5M12 16V7M17 16v-8" /></>),
  users: (<><circle cx="9" cy="9" r="3.2" /><path d="M3.5 19c.9-2.9 3-4.5 5.5-4.5s4.6 1.6 5.5 4.5" /><path d="M15.5 6.2a3 3 0 0 1 0 5.6M17.5 14.8c1.5.6 2.6 2 3 4.2" /></>),
  settings: (<><circle cx="12" cy="12" r="3" /><path d="M12 3.5v2.2M12 18.3v2.2M20.5 12h-2.2M5.7 12H3.5M18 6l-1.6 1.6M7.6 16.4 6 18M18 18l-1.6-1.6M7.6 7.6 6 6" /></>),
  spark: (<path d="M12 3.5 13.8 10l6.7 2-6.7 2L12 20.5 10.2 14 3.5 12l6.7-2L12 3.5Z" />),
  mail: (<><rect x="3.5" y="5.5" width="17" height="13" rx="2" /><path d="m4.5 7 7.5 6 7.5-6" /></>),
  shield: (<path d="M12 3.5 19 6v5.5c0 4.3-2.9 7.6-7 9-4.1-1.4-7-4.7-7-9V6l7-2.5Z" />),
  calendar: (<><rect x="4" y="5.5" width="16" height="14" rx="2" /><path d="M4 9.5h16M8.5 3.5v4M15.5 3.5v4" /></>),
  arrow: (<path d="M9 5l7 7-7 7" />),
  list: (<path d="M8 7h12M8 12h12M8 17h12M4 7h.01M4 12h.01M4 17h.01" />),
  camera: (<><path d="M4 8.5h3l1.5-2h7L17 8.5h3V18H4V8.5Z" /><circle cx="12" cy="13" r="3.2" /></>),
  alert: (<><path d="M12 4 21 19.5H3L12 4Z" /><path d="M12 10v4M12 17h.01" /></>),
};

export function Icon({ name, size = 20, className }: { name: IconName; size?: number; className?: string }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true">
      {PATHS[name]}
    </svg>
  );
}

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cx("rounded-2xl border border-espresso/10 bg-surface p-5 shadow-[0_1px_0_rgba(30,24,18,0.04)]", className)}>{children}</div>;
}

export function Eyebrow({ children }: { children: ReactNode }) {
  return <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-[0.14em] text-bronze-dark">{children}</p>;
}

export function ScreenTitle({ eyebrow, title, lead }: { eyebrow?: string; title: string; lead?: string }) {
  return (
    <header className="mb-6">
      {eyebrow && <Eyebrow>{eyebrow}</Eyebrow>}
      <h1 className="font-literata text-[26px] leading-tight font-semibold md:text-[30px]">{title}</h1>
      {lead && <p className="mt-2 max-w-[60ch] text-[15px] leading-relaxed text-espresso/65">{lead}</p>}
    </header>
  );
}

const STATE_COPY: Record<PreviewMembershipState, { label: string; tone: string; icon: IconName }> = {
  active: { label: "Ενεργή", tone: "bg-[#e3eedf] text-[#2f5a2a] border-[#2f5a2a]/20", icon: "check" },
  expired: { label: "Έληξε", tone: "bg-[#f4e2e3] text-maroon border-maroon/25", icon: "x" },
  pending: { label: "Σε αναμονή πληρωμής", tone: "bg-[#f6ecd6] text-[#7a5a14] border-[#7a5a14]/25", icon: "clock" },
};

export function StatusBadge({ state, className }: { state: PreviewMembershipState; className?: string }) {
  const s = STATE_COPY[state];
  return (
    <span className={cx("inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[12px] font-semibold", s.tone, className)}>
      <Icon name={s.icon} size={13} />
      {s.label}
    </span>
  );
}

export function PrimaryButton({ children, onClick, disabled, className, title }: { children: ReactNode; onClick?: () => void; disabled?: boolean; className?: string; title?: string }) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      title={title}
      className={cx(
        "inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3.5 text-[15px] font-semibold transition",
        disabled ? "cursor-not-allowed bg-disabled text-white/90" : "bg-bronze-dark text-white hover:bg-espresso",
        className,
      )}
    >
      {children}
    </button>
  );
}

export function GhostButton({ children, onClick, className }: { children: ReactNode; onClick?: () => void; className?: string }) {
  return (
    <button type="button" onClick={onClick} className={cx("inline-flex items-center justify-center gap-2 rounded-xl border border-espresso/20 bg-surface px-5 py-3.5 text-[15px] font-semibold hover:border-espresso/40", className)}>
      {children}
    </button>
  );
}

/** Inline reminder that an action is intentionally disabled in the preview. */
export function PreviewOnly({ children }: { children: ReactNode }) {
  return (
    <p className="mt-3 flex items-start gap-2 rounded-xl border border-dashed border-bronze/50 bg-[#f6f0e2] px-3.5 py-2.5 text-[13px] leading-snug text-bronze-dark">
      <Icon name="shield" size={16} className="mt-px flex-none" />
      <span>{children}</span>
    </p>
  );
}

export function Avatar({ initials, size = 44 }: { initials: string; size?: number }) {
  return (
    <span
      className="inline-flex flex-none items-center justify-center rounded-full bg-[#efe6d2] font-literata font-semibold text-bronze-dark"
      style={{ width: size, height: size, fontSize: size * 0.36 }}
      aria-hidden="true"
    >
      {initials}
    </span>
  );
}

export function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <Card className="p-4">
      <p className="text-[12px] font-medium text-espresso/55">{label}</p>
      <p className="font-literata mt-1 text-[28px] leading-none font-semibold">{value}</p>
      {hint && <p className="mt-1.5 text-[12px] text-espresso/50">{hint}</p>}
    </Card>
  );
}

/**
 * A QR-LOOKING pattern for layout only. It is NOT an encoded QR code (random
 * modules from a fixed seed), so it cannot be scanned or redeemed, and it is
 * permanently overprinted "ΔΕΙΓΜΑ — ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ".
 */
export function SampleQr({ seed = 7, size = 200 }: { seed?: number; size?: number }) {
  const n = 25;
  const cells = sampleQrModules(seed, n).map(([r, c]) => <rect key={`${r}-${c}`} x={c} y={r} width="1" height="1" />);
  const finderMark = (x: number, y: number) => (
    <g key={`f${x}${y}`}>
      <rect x={x} y={y} width="7" height="7" />
      <rect x={x + 1} y={y + 1} width="5" height="5" fill="#fff" />
      <rect x={x + 2} y={y + 2} width="3" height="3" />
    </g>
  );
  return (
    <div className="relative inline-block" data-evc-preview="sample-qr">
      <svg width={size} height={size} viewBox={`-1 -1 ${n + 2} ${n + 2}`} className="rounded-lg bg-white" role="img" aria-label="Δείγμα κωδικού QR — μη εξαργυρώσιμο">
        <g fill="#1e1812" opacity="0.55">
          {cells}
          {finderMark(0, 0)}
          {finderMark(n - 7, 0)}
          {finderMark(0, n - 7)}
        </g>
      </svg>
      <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
        <span className="-rotate-12 rounded-md border-2 border-maroon bg-white/92 px-2.5 py-1 text-center text-[12px] leading-tight font-bold tracking-wide text-maroon">
          ΔΕΙΓΜΑ
          <br />
          ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ
        </span>
      </div>
    </div>
  );
}

/**
 * Single-series bar chart (one measure, brand bronze). Per-bar hover/focus
 * tooltip, rounded data ends, 2px gaps, recessive baseline, and a
 * screen-reader table carrying the same numbers.
 */
export function BarChart({ data, height = 160, unit = "", label }: { data: { label: string; value: number }[]; height?: number; unit?: string; label: string }) {
  const [hover, setHover] = useState<number | null>(null);
  const max = Math.max(1, ...data.map((d) => d.value));
  const w = 100 / data.length;
  return (
    <figure className="m-0">
      <div className="relative" style={{ height }} role="img" aria-label={label}>
        <div className="absolute inset-0 flex items-end gap-[2px] border-b border-espresso/15">
          {data.map((d, i) => (
            <button
              key={d.label}
              type="button"
              aria-label={`${d.label}: ${d.value}${unit}`}
              onMouseEnter={() => setHover(i)}
              onMouseLeave={() => setHover(null)}
              onFocus={() => setHover(i)}
              onBlur={() => setHover(null)}
              className="flex h-full min-w-0 flex-1 items-end justify-center bg-transparent"
            >
              <span
                className={cx("block w-[68%] max-w-[44px] rounded-t-[4px] transition-colors", hover === null || hover === i ? "bg-bronze" : "bg-bronze/40")}
                style={{ height: `${Math.max(2, (d.value / max) * 88)}%` }}
              />
            </button>
          ))}
        </div>
        {hover !== null && (
          <div
            className="pointer-events-none absolute -top-2 -translate-x-1/2 -translate-y-full rounded-lg bg-espresso px-2.5 py-1.5 text-[12px] font-medium whitespace-nowrap text-cream shadow"
            style={{ left: `${(hover + 0.5) * w}%` }}
          >
            {data[hover].label}: <strong>{data[hover].value}{unit}</strong>
          </div>
        )}
      </div>
      <div className="mt-2 flex text-[10.5px] text-espresso/50">
        {data.map((d, i) => (
          <span key={d.label} className="truncate text-center" style={{ width: `${w}%` }}>
            {data.length > 10 && i % 2 === 1 ? "" : d.label}
          </span>
        ))}
      </div>
      <table className="sr-only">
        <caption>{label}</caption>
        <tbody>
          {data.map((d) => (
            <tr key={d.label}>
              <th scope="row">{d.label}</th>
              <td>{d.value}{unit}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </figure>
  );
}
