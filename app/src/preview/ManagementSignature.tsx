import { useMemo, useState, type ReactNode } from "react";
import { CLUB_RULES, COFFEES, MEMBERS, addDays, coffeeName, greekDate, redemptionHistory, shortGreekDate, todaysRedemptions, type PreviewMember, type PreviewMembershipState } from "./previewData";
import { cx, type ManagementScreen } from "./screens";
import { Glyph, Logo, PreviewNote, StateMark } from "./ds";
import { daysWord, fmt } from "./format";

/*
 * UI-01.R1 signature screens, Club management: overview and members.
 * Read-only and synthetic; NOT the ordering AdminApp, no create/edit/
 * activate/cancel/payment action exists here.
 */

const NAV: { id: ManagementScreen; label: string }[] = [
  { id: "overview", label: "Επισκόπηση" },
  { id: "members", label: "Μέλη" },
  { id: "statuses", label: "Συνδρομές" },
  { id: "activity", label: "Δραστηριότητα" },
  { id: "preferences", label: "Προτιμήσεις" },
  { id: "analytics", label: "Στατιστικά" },
  { id: "settings", label: "Ρυθμίσεις" },
];

export function ManagementFrame({ screen, go, children }: { screen: ManagementScreen; go: (s: ManagementScreen) => void; children: ReactNode }) {
  // A member's page lives under "Μέλη".
  const section = screen === "member" ? "members" : screen;
  return (
    <div className="min-h-[calc(100svh-52px)] bg-[var(--milk)]">
      <header className="border-b border-[var(--rule)] bg-[var(--paper)]">
        <div className="mx-auto flex max-w-[1280px] flex-wrap items-center gap-x-10 gap-y-2 px-5 pt-4 md:px-10">
          <div className="flex items-center gap-3 pb-3">
            <Logo className="h-7" />
            <span className="t-label border-l border-[var(--rule-strong)] pl-3 text-[var(--ink)]">Club</span>
          </div>
          <nav aria-label="Διαχείριση Club" className="-mb-px flex max-w-full gap-6 overflow-x-auto [scrollbar-width:none]">
            {NAV.map((n) => (
              <button
                key={n.id}
                type="button"
                onClick={() => go(n.id)}
                aria-current={section === n.id ? "page" : undefined}
                className={cx("t-label min-h-11 border-b-2 pt-2 pb-3.5 whitespace-nowrap", section === n.id ? "border-[var(--roast)] text-[var(--ink)]" : "border-transparent text-[var(--ink-3)] hover:text-[var(--ink)]")}
              >
                {n.label}
              </button>
            ))}
          </nav>
          <span className="t-small ml-auto hidden pb-3 text-[var(--ink-3)] lg:inline">Διαχειριστής (δείγμα)</span>
        </div>
      </header>
      <main className="mx-auto max-w-[1280px] px-5 py-8 md:px-10 md:py-12">{children}</main>
    </div>
  );
}

// ---- Overview ---------------------------------------------------------------------

export function ManagementOverview({ today, go, openMember }: { today: string; go: (s: ManagementScreen) => void; openMember: (id: string) => void }) {
  const data = useMemo(() => {
    const history = redemptionHistory(today, 30);
    const daily = Array.from({ length: 30 }, (_, i) => {
      const date = addDays(today, -(30 - i));
      return { date, value: history.filter((r) => r.date === date).length };
    });
    const active = MEMBERS.filter((m) => m.state === "active");
    // Member-days with a valid subscription in the window (joined, not yet expired).
    const eligibleDays = daily.reduce((sum, { date }, i) => sum + MEMBERS.filter((m) => m.state !== "pending" && date >= m.memberSince && (m.state === "active" || 30 - i >= -m.paidUntilOffsetDays)).length, 0);
    const prefs = COFFEES.map((c) => ({ code: c.code, label: c.label, value: history.filter((r) => r.coffee === c.code).length })).sort((a, b) => b.value - a.value);
    const hours = Array.from({ length: 11 }, (_, i) => 7 + i).map((h) => ({ hour: h, value: history.filter((r) => Number(r.time.slice(0, 2)) === h).length }));
    const expiring = active.filter((m) => m.paidUntilOffsetDays <= 7).sort((a, b) => a.paidUntilOffsetDays - b.paidUntilOffsetDays);
    return { history, daily, active, eligibleDays, prefs, hours, expiring };
  }, [today]);

  const servedToday = todaysRedemptions(today).length;
  const avg = data.history.length / 30;
  const usage = Math.round((data.history.length / Math.max(1, data.eligibleDays)) * 100);
  const pending = MEMBERS.filter((m) => m.state === "pending").length;
  const expired = MEMBERS.filter((m) => m.state === "expired").length;
  const month = new Date(`${today}T12:00:00Z`).toLocaleDateString("el-GR", { timeZone: "UTC", month: "long", year: "numeric" });
  const thisWeek = data.expiring.filter((m) => m.paidUntilOffsetDays <= 7).length;

  return (
    <div>
      <header className="max-w-[60ch]">
        <p className="t-label text-[var(--ink-3)]">{greekDate(today, true)}</p>
        <h1 className="t-headline mt-2 text-[var(--roast)] first-letter:uppercase">{month}</h1>
        <p className="t-lede mt-3 text-[var(--ink-2)]">
          {data.active.length} ενεργά μέλη έχουν πάρει {servedToday} καφέδες σήμερα. {thisWeek} συνδρομές λήγουν μέσα στην εβδομάδα και {pending} περιμένουν επιβεβαίωση πληρωμής.
        </p>
      </header>

      <dl className="mt-10 grid grid-cols-2 border-y border-[var(--rule-strong)] lg:grid-cols-4">
        <Metric label="Ενεργά μέλη" value={fmt(data.active.length)} note={`${pending} σε αναμονή, ${expired} έληξαν`} />
        <Metric label="Καφέδες σήμερα" value={fmt(servedToday)} note={`από ${data.active.length} δυνατούς`} />
        <Metric label="Καφέδες, 30 ημέρες" value={fmt(data.history.length)} note={`${fmt(avg, 1)} την ημέρα κατά μέσο όρο`} />
        <Metric label="Χρήση δικαιώματος" value={`${usage}%`} note="των ημερών με ενεργή συνδρομή" />
      </dl>

      <div className="mt-12 grid grid-cols-[minmax(0,1fr)] gap-x-14 gap-y-12 lg:grid-cols-[minmax(0,1.75fr)_minmax(0,1fr)]">
        <section aria-labelledby="evc-daily">
          <SectionHead id="evc-daily" title="Καφέδες Club ανά ημέρα" note="Τελευταίες 30 ημέρες" />
          <DailyChart data={data.daily} avg={avg} />
        </section>

        <section aria-labelledby="evc-expiring">
          <SectionHead id="evc-expiring" title="Λήγουν σύντομα" note="Χειροκίνητη ανανέωση" action={<button type="button" onClick={() => go("members")} className="t-label -my-3 py-3 text-[var(--bronze-deep)] underline-offset-4 hover:underline">Όλα τα μέλη</button>} />
          <ul className="m-0 list-none p-0">
            {data.expiring.map((m) => (
              <li key={m.id} className="border-b border-[var(--rule)]">
                <button type="button" onClick={() => openMember(m.id)} className="grid w-full grid-cols-[minmax(0,1fr)_auto] items-baseline gap-4 py-3.5 text-left hover:bg-[rgba(139,118,80,0.06)]">
                  <span className="t-body truncate font-medium text-[var(--ink)]">{m.name}</span>
                  <span className={cx("t-small t-num", m.paidUntilOffsetDays <= 3 ? "font-semibold text-[var(--wait)]" : "text-[var(--ink-2)]")}>{daysWord(m.paidUntilOffsetDays)}</span>
                </button>
              </li>
            ))}
          </ul>
        </section>

        <section aria-labelledby="evc-prefs">
          <SectionHead id="evc-prefs" title="Τι πίνουν τα μέλη" note="30 ημέρες" />
          <ol className="m-0 list-none p-0">
            {data.prefs.map((p) => {
              const share = p.value / Math.max(1, data.history.length);
              return (
                <li key={p.code} className="grid grid-cols-[9.5rem_minmax(0,1fr)_3rem] items-center gap-4 border-b border-[var(--rule)] py-3 sm:grid-cols-[11rem_minmax(0,1fr)_3.5rem]">
                  <span className="t-body truncate text-[var(--ink)]">{p.label}</span>
                  <span className="h-2 rounded-r-[4px] bg-[var(--bronze)]" style={{ width: `${Math.max(2, (p.value / data.prefs[0].value) * 100)}%` }} aria-hidden="true" />
                  <span className="t-small t-num text-right text-[var(--ink-2)]">{Math.round(share * 100)}%</span>
                </li>
              );
            })}
          </ol>
        </section>

        <section aria-labelledby="evc-hours">
          <SectionHead id="evc-hours" title="Ώρες αιχμής" note="Εξαργυρώσεις ανά ώρα" />
          <HourChart data={data.hours} />
        </section>
      </div>

      <PreviewNote className="mt-14">Όλοι οι αριθμοί είναι φανταστικοί και υπάρχουν μόνο για τον σχεδιασμό. Η τιμή συνδρομής είναι {CLUB_RULES.monthlyPriceEur} € και κάθε μέλος δικαιούται {CLUB_RULES.coffeesPerAthensDay} καφέ την ημέρα.</PreviewNote>
    </div>
  );
}

export function Metric({ label, value, note }: { label: string; value: string; note: string }) {
  return (
    <div className="border-[var(--rule)] px-0 py-6 odd:pr-5 even:border-l even:pl-5 lg:border-l lg:px-6 lg:first:border-l-0 lg:first:pl-0">
      <dt className="t-small text-[var(--ink-2)]">{label}</dt>
      <dd className="m-0">
        <span className="t-figure mt-2 block text-[var(--roast)]">{value}</span>
        <span className="t-small mt-2 block text-[var(--ink-3)]">{note}</span>
      </dd>
    </div>
  );
}

export function SectionHead({ id, title, note, action }: { id: string; title: string; note?: string; action?: ReactNode }) {
  return (
    <div className="mb-4 flex items-baseline justify-between gap-4 border-b border-[var(--rule-strong)] pb-3">
      <h2 id={id} className="t-title text-[var(--ink)]">
        {title}
        {note && <span className="t-small block font-normal text-[var(--ink-3)] sm:ml-3 sm:inline">{note}</span>}
      </h2>
      {action}
    </div>
  );
}

function DailyChart({ data, avg }: { data: { date: string; value: number }[]; avg: number }) {
  const [hover, setHover] = useState<number | null>(null);
  const top = Math.max(4, Math.ceil(Math.max(...data.map((d) => d.value)) / 4) * 4);
  const ticks = [0, top / 2, top];
  const label = "Καφέδες Club ανά ημέρα, τελευταίες 30 ημέρες";
  return (
    <figure className="m-0">
      <div className="grid grid-cols-[1.75rem_minmax(0,1fr)_3.5rem] gap-x-2">
        <div className="relative h-[240px]" aria-hidden="true">
          {ticks.map((t) => (
            <span key={t} className="t-num absolute right-0 translate-y-1/2 text-[11px] text-[var(--ink-3)]" style={{ bottom: `${(t / top) * 100}%` }}>{t}</span>
          ))}
        </div>
        <div className="relative h-[240px]" role="img" aria-label={label}>
          {ticks.map((t) => (
            <span key={t} className={cx("absolute inset-x-0 border-t", t === 0 ? "border-[var(--ink-3)]" : "border-[var(--rule)]")} style={{ bottom: `${(t / top) * 100}%` }} aria-hidden="true" />
          ))}
          <div className="absolute inset-0 flex items-end gap-[2px]">
            {data.map((d, i) => (
              <button
                key={d.date}
                type="button"
                aria-label={`${shortGreekDate(d.date)}: ${d.value} καφέδες`}
                onMouseEnter={() => setHover(i)}
                onMouseLeave={() => setHover(null)}
                onFocus={() => setHover(i)}
                onBlur={() => setHover(null)}
                className="flex h-full min-w-0 flex-1 items-end justify-center"
              >
                <span
                  className={cx("block w-full max-w-[18px] rounded-t-[3px] transition-colors duration-150", hover === null || hover === i ? "bg-[var(--bronze)]" : "bg-[var(--bronze)]/35")}
                  style={{ height: `${Math.max(1, (d.value / top) * 100)}%` }}
                />
              </button>
            ))}
          </div>
          <span className="pointer-events-none absolute -right-2 left-0 border-t border-dashed border-[var(--roast)]" style={{ bottom: `${(avg / top) * 100}%` }} aria-hidden="true" />
          {hover !== null && (
            <div className="pointer-events-none absolute top-0 z-10 -translate-x-1/2 -translate-y-[calc(100%+6px)] rounded-[6px] bg-[var(--roast)] px-2.5 py-1.5 text-[12px] whitespace-nowrap text-[var(--milk)]" style={{ left: `${((hover + 0.5) / data.length) * 100}%` }}>
              {greekDate(data[hover].date)}: <strong className="t-num">{data[hover].value}</strong>
            </div>
          )}
        </div>
        <div className="relative h-[240px]" aria-hidden="true">
          <span className="t-small t-num absolute left-1 translate-y-1/2 leading-tight text-[var(--roast)]" style={{ bottom: `${(avg / top) * 100}%` }}>μ.ό.<br />{fmt(avg, 1)}</span>
        </div>
        <span />
        <div className="t-small t-num relative mt-2 h-5 text-[var(--ink-3)]" aria-hidden="true">
          {data.map((d, i) =>
            i % 7 === 2 || i === data.length - 1 ? (
              <span key={d.date} className="absolute -translate-x-1/2 whitespace-nowrap" style={{ left: `${((i + 0.5) / data.length) * 100}%` }}>{shortGreekDate(d.date)}</span>
            ) : null,
          )}
        </div>
        <span />
      </div>
      <div className="sr-only">
        <table>
        <caption>{label}</caption>
        <tbody>
          {data.map((d) => (
            <tr key={d.date}><th scope="row">{greekDate(d.date)}</th><td>{d.value}</td></tr>
          ))}
        </tbody>
        </table>
      </div>
    </figure>
  );
}

export function HourChart({ data }: { data: { hour: number; value: number }[] }) {
  const max = Math.max(1, ...data.map((d) => d.value));
  const peak = data.reduce((a, b) => (b.value > a.value ? b : a));
  return (
    <figure className="m-0">
      <div className="flex h-[132px] items-end gap-[2px] border-b border-[var(--ink-3)]" role="img" aria-label={`Εξαργυρώσεις ανά ώρα· αιχμή ${peak.hour}:00 με ${peak.value}`}>
        {data.map((d) => (
          <span key={d.hour} className="flex h-full min-w-0 flex-1 items-end justify-center" title={`${d.hour}:00, ${d.value}`}>
            <span className={cx("block w-full max-w-[26px] rounded-t-[3px]", d.hour === peak.hour ? "bg-[var(--roast)]" : "bg-[var(--bronze)]/55")} style={{ height: `${Math.max(1, (d.value / max) * 100)}%` }} />
          </span>
        ))}
      </div>
      <div className="t-small t-num mt-2 flex text-[var(--ink-3)]" aria-hidden="true">
        {data.map((d) => (
          <span key={d.hour} className="flex-1 text-center">{d.hour % 2 === 1 ? d.hour : ""}</span>
        ))}
      </div>
      <figcaption className="t-small mt-3 text-[var(--ink-2)]">Η πιο πολυσύχναστη ώρα είναι {peak.hour}:00 με {peak.hour + 1}:00.</figcaption>
    </figure>
  );
}

// ---- Members ----------------------------------------------------------------------

type Filter = PreviewMembershipState | "all";
type Sort = "expiry" | "name" | "visits";

const FILTERS: { id: Filter; label: string }[] = [
  { id: "all", label: "Όλα" },
  { id: "active", label: "Ενεργά" },
  { id: "pending", label: "Αναμονή" },
  { id: "expired", label: "Έληξαν" },
];

function untilCell(m: PreviewMember, today: string): { date: string; note: string; warn: boolean } {
  if (m.state === "pending") return { date: "—", note: "δεν έχει ενεργοποιηθεί", warn: false };
  const date = shortGreekDate(addDays(today, m.paidUntilOffsetDays));
  if (m.state === "expired") return { date, note: `πριν από ${-m.paidUntilOffsetDays} ημέρες`, warn: false };
  return { date, note: daysWord(m.paidUntilOffsetDays), warn: m.paidUntilOffsetDays <= 3 };
}

export function MembersDirectory({ today, openMember }: { today: string; openMember: (id: string) => void }) {
  const [q, setQ] = useState("");
  const [filter, setFilter] = useState<Filter>("all");
  const [sort, setSort] = useState<Sort>("expiry");
  const counts = Object.fromEntries(FILTERS.map((f) => [f.id, f.id === "all" ? MEMBERS.length : MEMBERS.filter((m) => m.state === f.id).length])) as Record<Filter, number>;
  const needle = q.trim().toLocaleLowerCase("el");
  const rank: Record<PreviewMembershipState, number> = { active: 0, pending: 1, expired: 2 };
  const rows = MEMBERS.filter((m) => (filter === "all" || m.state === filter) && (!needle || m.name.toLocaleLowerCase("el").includes(needle) || m.phoneMasked.replace(/\D/g, "").endsWith(needle.replace(/\D/g, "") || "x"))).sort((a, b) =>
    sort === "name" ? a.name.localeCompare(b.name, "el") : sort === "visits" ? b.visitsThisMonth - a.visitsThisMonth : rank[a.state] - rank[b.state] || a.paidUntilOffsetDays - b.paidUntilOffsetDays,
  );
  const maxVisits = Math.max(...MEMBERS.map((m) => m.visitsThisMonth));

  return (
    <div>
      <header className="flex flex-wrap items-end justify-between gap-x-10 gap-y-3">
        <div>
          <h1 className="t-headline text-[var(--roast)]">Μέλη</h1>
          <p className="t-body mt-2 text-[var(--ink-2)]">{counts.all} εγγεγραμμένα, {counts.active} με ενεργή συνδρομή.</p>
        </div>
      </header>

      <div className="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4 border-b border-[var(--rule-strong)] pb-5">
        <label className="flex min-h-11 w-full items-center gap-3 rounded-full border border-[var(--rule-strong)] bg-[var(--paper)] px-4 focus-within:border-[var(--ink)] sm:w-[320px]">
          <Glyph name="search" size={18} className="flex-none text-[var(--ink-3)]" />
          <span className="sr-only">Αναζήτηση μέλους</span>
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Όνομα ή τηλέφωνο" className="t-body w-full min-w-0 bg-transparent text-[var(--ink)] outline-none placeholder:text-[var(--ink-3)]" />
        </label>
        <div role="group" aria-label="Φίλτρο κατάστασης" className="-mx-1 flex max-w-full gap-1 overflow-x-auto px-1 [scrollbar-width:none]">
          {FILTERS.map((f) => (
            <button
              key={f.id}
              type="button"
              aria-pressed={filter === f.id}
              onClick={() => setFilter(f.id)}
              className={cx("press t-label min-h-10 rounded-full px-4 whitespace-nowrap", filter === f.id ? "bg-[var(--roast)] text-[var(--milk)]" : "text-[var(--ink-2)] hover:bg-[rgba(139,118,80,0.1)]")}
            >
              {f.label} <span className={cx("t-num ml-1", filter === f.id ? "text-[var(--milk)]/60" : "text-[var(--ink-3)]")}>{counts[f.id]}</span>
            </button>
          ))}
        </div>
        <label className="t-small ml-auto flex items-center gap-2 text-[var(--ink-2)]">
          Ταξινόμηση
          <select value={sort} onChange={(e) => setSort(e.target.value as Sort)} className="t-label min-h-10 rounded-full border border-[var(--rule-strong)] bg-[var(--paper)] px-3 text-[var(--ink)]">
            <option value="expiry">Λήξη συνδρομής</option>
            <option value="name">Όνομα</option>
            <option value="visits">Καφέδες μήνα</option>
          </select>
        </label>
      </div>

      {/* Tablet and desktop: the table. */}
      <table className="ledger mt-2 hidden w-full border-collapse md:table">
        <thead>
          <tr>
            <th className="pt-4">Μέλος</th>
            <th className="pt-4">Τηλέφωνο</th>
            <th className="pt-4">Κατάσταση</th>
            <th className="pt-4">Ισχύει έως</th>
            <th className="hidden pt-4 lg:table-cell">Συνήθως πίνει</th>
            <th className="pt-4 text-right!">Καφέδες μήνα</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((m) => {
            const u = untilCell(m, today);
            return (
              <tr key={m.id} onClick={() => openMember(m.id)} className="cursor-pointer">
                <td className="pr-4">
                  <button type="button" onClick={(e) => { e.stopPropagation(); openMember(m.id); }} className="t-body text-left font-medium text-[var(--ink)] hover:underline">{m.name}</button>
                  <span className="t-small block text-[var(--ink-3)]">{m.email}</span>
                </td>
                <td className="t-small t-num pr-4 text-[var(--ink-2)]">{m.phoneMasked}</td>
                <td className="t-small pr-4 text-[var(--ink)]"><StateMark state={m.state} /></td>
                <td className="pr-4">
                  <span className="t-small t-num block text-[var(--ink)]">{u.date}</span>
                  <span className={cx("t-small block", u.warn ? "font-semibold text-[var(--wait)]" : "text-[var(--ink-3)]")}>{u.note}</span>
                </td>
                <td className="t-small hidden pr-4 text-[var(--ink-2)] lg:table-cell">{coffeeName(m.favourite)}</td>
                <td className="text-right">
                  <span className="inline-flex items-center gap-3">
                    <span className="hidden h-1.5 w-16 bg-[var(--rule)] lg:block" aria-hidden="true">
                      <span className="block h-full bg-[var(--bronze)]" style={{ width: `${(m.visitsThisMonth / maxVisits) * 100}%` }} />
                    </span>
                    <span className="t-body t-num w-6 text-right text-[var(--ink)]">{m.visitsThisMonth}</span>
                  </span>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>

      {/* Phone: one compact row per member. */}
      <ul className="m-0 list-none p-0 md:hidden">
        {rows.map((m) => {
          const u = untilCell(m, today);
          return (
            <li key={m.id} className="border-b border-[var(--rule)]">
              <button type="button" onClick={() => openMember(m.id)} className="grid w-full grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-1 py-4 text-left">
                <span className="t-body truncate font-medium text-[var(--ink)]">{m.name}</span>
                <span className="t-small t-num self-center text-right text-[var(--ink-2)]">{m.visitsThisMonth} καφέδες</span>
                <span className="t-small text-[var(--ink-2)]"><StateMark state={m.state} /></span>
                <span className={cx("t-small text-right", u.warn ? "font-semibold text-[var(--wait)]" : "text-[var(--ink-3)]")}>{m.state === "active" ? u.note : u.date}</span>
              </button>
            </li>
          );
        })}
      </ul>

      {rows.length === 0 && (
        <div className="border-b border-[var(--rule)] py-12 text-center">
          <p className="t-title text-[var(--ink)]">Κανένα μέλος δεν ταιριάζει</p>
          <p className="t-small mt-2 text-[var(--ink-2)]">Δοκιμάστε άλλο όνομα ή τα 2 τελευταία ψηφία του τηλεφώνου.</p>
          <button type="button" onClick={() => { setQ(""); setFilter("all"); }} className="t-label mt-1 py-3 text-[var(--bronze-deep)] underline underline-offset-4">Καθαρισμός φίλτρων</button>
        </div>
      )}

      <PreviewNote className="mt-8">Μόνο προβολή: δημιουργία, επεξεργασία, ενεργοποίηση ή ακύρωση μελών δεν υπάρχει στην προεπισκόπηση. Τα τηλέφωνα εμφανίζονται πάντα μασκαρισμένα.</PreviewNote>
    </div>
  );
}
