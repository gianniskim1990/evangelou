import { useMemo, useState } from "react";
import { CLUB_RULES, COFFEES, MEMBERS, addDays, coffeeName, greekDate, memberById, memberHistory, redemptionHistory, shortGreekDate, todaysRedemptions, type PreviewMember, type PreviewMembershipState, type PreviewRedemption } from "./previewData";
import { cx, type ManagementScreen } from "./screens";
import { HourChart, ManagementFrame, ManagementOverview, MembersDirectory, Metric, SectionHead } from "./ManagementSignature";
import { DefRows, Glyph, PreviewNote, StateMark } from "./ds";
import { addCalendarMonths, daysWord, fmt } from "./format";
import { GROUPS, historyPreparation, preparationLine, type CoffeeOrder, type GroupId } from "./coffeeConfig";

/*
 * UI-02 design preview, Club management (read-only, synthetic). This is NOT
 * the ordering AdminApp and has no create/edit/activate/cancel/payment action.
 */

export function ManagementPreview({ screen, go, today }: { screen: ManagementScreen; go: (s: ManagementScreen) => void; today: string }) {
  const [memberId, setMemberId] = useState("m01");
  const openMember = (id: string) => {
    setMemberId(id);
    go("member");
  };
  return (
    <ManagementFrame screen={screen} go={go}>
      {screen === "overview" && <ManagementOverview today={today} go={go} openMember={openMember} />}
      {screen === "members" && <MembersDirectory today={today} openMember={openMember} />}
      {screen === "member" && <MemberDetails member={memberById(memberId)} today={today} go={go} />}
      {screen === "statuses" && <Statuses today={today} openMember={openMember} />}
      {screen === "activity" && <Activity today={today} openMember={openMember} />}
      {screen === "preferences" && <Preferences today={today} />}
      {screen === "analytics" && <Analytics today={today} />}
      {screen === "settings" && <Settings />}
    </ManagementFrame>
  );
}

function PageHead({ title, lede }: { title: string; lede?: string }) {
  return (
    <header className="max-w-[64ch]">
      <h1 className="t-headline text-[var(--roast)]">{title}</h1>
      {lede && <p className="t-lede mt-3 text-[var(--ink-2)]">{lede}</p>}
    </header>
  );
}

const STATE_BAR: Record<PreviewMembershipState, string> = { active: "bg-[var(--ok)]", pending: "bg-[var(--wait)]", expired: "bg-[var(--stop)]" };

// ---- Member detail ------------------------------------------------------------------------

function MembershipTimeline({ m, today }: { m: PreviewMember; today: string }) {
  if (m.state === "pending") {
    return <p className="t-body border-y border-[var(--rule)] py-6 text-[var(--ink-2)]">Η περίοδος ξεκινά με την επιβεβαίωση της πληρωμής και διαρκεί έναν ημερολογιακό μήνα.</p>;
  }
  const end = addDays(today, m.paidUntilOffsetDays);
  const start = addCalendarMonths(end, -1);
  const span = (Date.parse(end) - Date.parse(start)) / 86_400_000;
  const elapsed = Math.min(span, Math.max(0, (Date.parse(today) - Date.parse(start)) / 86_400_000));
  const pct = (elapsed / span) * 100;
  const active = m.state === "active";
  return (
    <figure className="m-0 border-y border-[var(--rule)] py-6">
      <div className="t-small mb-3 flex justify-between gap-4 text-[var(--ink-2)]">
        <span>Τρέχουσα περίοδος, ένας ημερολογιακός μήνας</span>
        <span className={cx("t-num font-semibold", active ? (m.paidUntilOffsetDays <= 3 ? "text-[var(--wait)]" : "text-[var(--ink)]") : "text-[var(--stop)]")}>
          {active ? `Λήγει ${daysWord(m.paidUntilOffsetDays)}` : `Έληξε πριν από ${-m.paidUntilOffsetDays} ημέρες`}
        </span>
      </div>
      <div className="relative h-3 rounded-full bg-[var(--rule)]" role="img" aria-label={`Περίοδος από ${greekDate(start)} έως ${greekDate(end)}`}>
        <span className={cx("absolute inset-y-0 left-0 rounded-full", active ? "bg-[var(--bronze)]" : "bg-[var(--ink-3)]")} style={{ width: `${pct}%` }} />
        {active && <span className="absolute top-1/2 h-6 w-[3px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[var(--roast)]" style={{ left: `${pct}%` }} aria-hidden="true" />}
      </div>
      <figcaption className="t-small t-num mt-3 flex justify-between gap-4 text-[var(--ink-2)]">
        <span>{greekDate(start)}</span>
        {active && <span className="text-[var(--ink)]">Σήμερα</span>}
        <span>{greekDate(end)}</span>
      </figcaption>
    </figure>
  );
}

function MemberDetails({ member: m, today, go }: { member: PreviewMember; today: string; go: (s: ManagementScreen) => void }) {
  const hist = memberHistory(m.id, today);
  const days = Array.from({ length: 21 }, (_, i) => addDays(today, i - 20));
  const taken = new Map(hist.map((r) => [r.date, r] as const));
  const served = todaysRedemptions(today).find((r) => r.memberId === m.id);
  if (served) taken.set(today, served);
  const prefs = COFFEES.map((c) => ({ label: c.label, value: hist.filter((r) => r.coffee === c.code).length })).filter((d) => d.value > 0).sort((a, b) => b.value - a.value);
  return (
    <div>
      <button type="button" onClick={() => go("members")} className="press t-label -ml-2 flex min-h-10 items-center gap-1 rounded-full pr-3 pl-1 text-[var(--ink-2)] hover:text-[var(--ink)]">
        <Glyph name="back" size={18} />
        Όλα τα μέλη
      </button>
      <header className="mt-4 flex flex-wrap items-end justify-between gap-x-10 gap-y-3">
        <div>
          <h1 className="t-headline text-[var(--roast)]">{m.name}</h1>
          <p className="t-body mt-2 text-[var(--ink-2)]">
            <StateMark state={m.state} className="mr-4 text-[var(--ink)]" />
            Μέλος από τις {greekDate(m.memberSince)}
          </p>
        </div>
      </header>

      <div className="mt-8">
        <MembershipTimeline m={m} today={today} />
      </div>

      <div className="mt-10 grid grid-cols-[minmax(0,1fr)] gap-x-14 gap-y-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <section>
          <SectionHead id="evc-m-details" title="Στοιχεία" />
          <DefRows
            rows={[
              ["Τηλέφωνο", <span key="p" className="t-num">{m.phoneMasked}</span>],
              ["E-mail", m.email],
              ["Ανανέωση", `Χειροκίνητη, ${CLUB_RULES.monthlyPriceEur} € για έναν ημερολογιακό μήνα`],
              ["Newsletter", m.newsletter ? "Ναι, ξεχωριστή συναίνεση" : "Όχι"],
              ["Συνήθως πίνει", coffeeName(m.favourite)],
            ]}
          />
        </section>
        <section>
          <SectionHead id="evc-m-days" title="Τελευταίες 3 εβδομάδες" note={`${taken.size} καφέδες`} />
          <ol className="m-0 grid max-w-[22rem] list-none grid-cols-7 gap-1.5 p-0" aria-label="Ημέρες με καφέ Club">
            {days.map((d) => {
              const r = taken.get(d);
              return (
                <li key={d} title={`${greekDate(d)}${r ? `, ${coffeeName(r.coffee)} ${r.time}` : ", χωρίς καφέ"}`} className={cx("t-small t-num flex aspect-square items-center justify-center rounded-[6px]", r ? "bg-[var(--roast)] text-[var(--milk)]" : "border border-[var(--rule)] text-[var(--ink-3)]", d === today && "ring-2 ring-[var(--bronze)] ring-offset-1 ring-offset-[var(--milk)]")}>
                  {Number(d.slice(8))}
                  <span className="sr-only">{r ? ` ${coffeeName(r.coffee)}` : " χωρίς καφέ"}</span>
                </li>
              );
            })}
          </ol>
          {prefs.length > 0 && (
            <ul className="m-0 mt-6 list-none p-0">
              {prefs.map((p) => (
                <li key={p.label} className="grid grid-cols-[9rem_minmax(0,1fr)_2rem] items-center gap-4 border-b border-[var(--rule)] py-2.5">
                  <span className="t-small truncate text-[var(--ink)]">{p.label}</span>
                  <span className="h-2 rounded-r-[4px] bg-[var(--bronze)]" style={{ width: `${(p.value / prefs[0].value) * 100}%` }} aria-hidden="true" />
                  <span className="t-small t-num text-right text-[var(--ink-2)]">{p.value}</span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>

      <section className="mt-12">
        <SectionHead id="evc-m-recent" title="Πρόσφατοι καφέδες" />
        <table className="ledger w-full border-collapse">
          <thead>
            <tr>
              <th>Ημερομηνία</th>
              <th>Καφές</th>
              <th className="text-right!">Ώρα</th>
            </tr>
          </thead>
          <tbody>
            {[...(served ? [served] : []), ...hist].slice(0, 8).map((r) => (
              <tr key={r.date}>
                <td className="t-body pr-4 text-[var(--ink)]">{r.date === today ? "Σήμερα" : greekDate(r.date, true)}</td>
                <td className="pr-4">
                  <span className="t-body block text-[var(--ink)]">{coffeeName(r.coffee)}</span>
                  <span className="t-small block text-[var(--ink-2)]">{preparationLine(historyPreparation(m, r.coffee, r.date))}</span>
                </td>
                <td className="t-small t-num text-right text-[var(--ink-2)]">{r.time}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {hist.length === 0 && !served && <p className="t-body border-b border-[var(--rule)] py-8 text-[var(--ink-2)]">Το μέλος δεν έχει πάρει ακόμη καφέ Club.</p>}
      </section>
      <PreviewNote className="mt-10">Δεν υπάρχουν ενέργειες ενεργοποίησης, ανανέωσης, ακύρωσης ή πληρωμής: οι δυνατότητες διαχείρισης και τα δικαιώματα θα οριστικοποιηθούν ξεχωριστά.</PreviewNote>
    </div>
  );
}

// ---- Statuses ----------------------------------------------------------------------------------

function Statuses({ today, openMember }: { today: string; openMember: (id: string) => void }) {
  const groups: { state: PreviewMembershipState; title: string; empty: string }[] = [
    { state: "active", title: "Ενεργές", empty: "Καμία ενεργή συνδρομή." },
    { state: "pending", title: "Αναμονή πληρωμής", empty: "Καμία πληρωμή σε αναμονή." },
    { state: "expired", title: "Έληξαν", empty: "Καμία ληγμένη συνδρομή." },
  ];
  const counts = groups.map((g) => MEMBERS.filter((m) => m.state === g.state).length);
  const soon = MEMBERS.filter((m) => m.state === "active" && m.paidUntilOffsetDays <= 7).length;
  return (
    <div>
      <PageHead title="Συνδρομές" lede={`${counts[0]} ενεργές, ${counts[1]} σε αναμονή πληρωμής και ${counts[2]} ληγμένες. ${soon} ενεργές λήγουν μέσα στην εβδομάδα.`} />

      <figure className="m-0 mt-10">
        <div className="flex h-4 gap-[2px] overflow-hidden rounded-[4px]" role="img" aria-label={groups.map((g, i) => `${g.title}: ${counts[i]}`).join(", ")}>
          {groups.map((g, i) => (
            <span key={g.state} className={STATE_BAR[g.state]} style={{ width: `${(counts[i] / MEMBERS.length) * 100}%` }} />
          ))}
        </div>
        <figcaption className="t-small mt-3 flex flex-wrap gap-x-8 gap-y-2 text-[var(--ink)]">
          {groups.map((g, i) => (
            <span key={g.state} className="inline-flex items-center gap-2">
              <StateMark state={g.state} />
              <span className="t-num text-[var(--ink-2)]">{counts[i]}</span>
            </span>
          ))}
        </figcaption>
      </figure>

      <div className="mt-12 grid grid-cols-[minmax(0,1fr)] gap-x-12 gap-y-10 lg:grid-cols-3">
        {groups.map((g) => {
          const ms = MEMBERS.filter((m) => m.state === g.state).sort((a, b) => (g.state === "expired" ? b.paidUntilOffsetDays - a.paidUntilOffsetDays : a.paidUntilOffsetDays - b.paidUntilOffsetDays));
          return (
            <section key={g.state} aria-labelledby={`evc-st-${g.state}`}>
              <SectionHead id={`evc-st-${g.state}`} title={g.title} note={String(ms.length)} />
              <ul className="m-0 list-none p-0">
                {ms.map((m) => {
                  const warn = g.state === "active" && m.paidUntilOffsetDays <= 3;
                  return (
                    <li key={m.id} className="border-b border-[var(--rule)]">
                      <button type="button" onClick={() => openMember(m.id)} className="grid min-h-14 w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-4 py-2 text-left hover:bg-[rgba(139,118,80,0.06)]">
                        <span className="t-body truncate text-[var(--ink)]">{m.name}</span>
                        <span className={cx("t-small t-num text-right", warn ? "font-semibold text-[var(--wait)]" : "text-[var(--ink-2)]")}>
                          {g.state === "pending" ? `εγγραφή ${shortGreekDate(m.memberSince)}` : g.state === "expired" ? `έληξε ${shortGreekDate(addDays(today, m.paidUntilOffsetDays))}` : daysWord(m.paidUntilOffsetDays)}
                        </span>
                      </button>
                    </li>
                  );
                })}
              </ul>
              {ms.length === 0 && <p className="t-body py-6 text-[var(--ink-2)]">{g.empty}</p>}
            </section>
          );
        })}
      </div>
      <PreviewNote className="mt-12">Μόνο προβολή: η ανανέωση γίνεται χειροκίνητα από το μέλος και καμία συνδρομή δεν αλλάζει από εδώ.</PreviewNote>
    </div>
  );
}

// ---- Activity -----------------------------------------------------------------------------------

type Range = 1 | 7 | 14;

function dayLabel(date: string, today: string): string {
  if (date === today) return "Σήμερα";
  if (date === addDays(today, -1)) return "Χθες";
  return greekDate(date, true);
}

function Activity({ today, openMember }: { today: string; openMember: (id: string) => void }) {
  const [range, setRange] = useState<Range>(7);
  const groups = useMemo(() => {
    const all: PreviewRedemption[] = [...todaysRedemptions(today), ...redemptionHistory(today, 13)];
    const out: { date: string; rows: PreviewRedemption[] }[] = [];
    for (let i = 0; i < range; i++) {
      const date = addDays(today, -i);
      out.push({ date, rows: all.filter((r) => r.date === date).sort((a, b) => b.time.localeCompare(a.time)) });
    }
    return out;
  }, [today, range]);
  const total = groups.reduce((s, g) => s + g.rows.length, 0);
  return (
    <div>
      <div className="flex flex-wrap items-end justify-between gap-x-10 gap-y-5">
        <PageHead title="Δραστηριότητα" lede={`${total} καφέδες Club ${range === 1 ? "σήμερα" : `τις τελευταίες ${range} ημέρες`}.`} />
        <div role="group" aria-label="Χρονικό διάστημα" className="flex gap-1">
          {([1, 7, 14] as Range[]).map((r) => (
            <button key={r} type="button" aria-pressed={range === r} onClick={() => setRange(r)} className={cx("press t-label min-h-10 rounded-full px-4", range === r ? "bg-[var(--roast)] text-[var(--milk)]" : "text-[var(--ink-2)] hover:bg-[rgba(139,118,80,0.1)]")}>
              {r === 1 ? "Σήμερα" : `${r} ημέρες`}
            </button>
          ))}
        </div>
      </div>
      <div className="mt-10 max-w-[920px]">
        {groups.map((g) => (
          <section key={g.date} className="mb-8">
            <h2 className="flex items-baseline justify-between border-b border-[var(--rule-strong)] pb-2.5">
              <span className="t-title text-[var(--ink)]">{dayLabel(g.date, today)}</span>
              <span className="t-small t-num text-[var(--ink-2)]">{g.rows.length} καφέδες</span>
            </h2>
            <ul className="m-0 list-none p-0">
              {g.rows.map((r) => (
                <li key={`${r.date}-${r.memberId}`} className="border-b border-[var(--rule)]">
                  <button type="button" onClick={() => openMember(r.memberId)} className="grid min-h-12 w-full grid-cols-[3.5rem_minmax(0,1fr)] items-center gap-x-4 gap-y-0.5 py-2.5 text-left hover:bg-[rgba(139,118,80,0.06)] sm:grid-cols-[4rem_minmax(0,0.8fr)_minmax(0,1.2fr)]">
                    <span className="t-small t-num text-[var(--ink-3)]">{r.time}</span>
                    <span className="t-body truncate text-[var(--ink)]">{memberById(r.memberId).name}</span>
                    <span className="col-start-2 min-w-0 sm:col-start-auto">
                      <span className="t-small block truncate text-[var(--ink)]">{coffeeName(r.coffee)}</span>
                      <span className="t-small block truncate text-[var(--ink-3)]">{preparationLine(historyPreparation(memberById(r.memberId), r.coffee, r.date))}</span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
            {g.rows.length === 0 && <p className="t-small py-4 text-[var(--ink-3)]">Κανένας καφές Club αυτή την ημέρα.</p>}
          </section>
        ))}
      </div>
      <PreviewNote className="mt-4">Δείγμα δραστηριότητας: καμία εξαργύρωση δεν είναι πραγματική.</PreviewNote>
    </div>
  );
}

// ---- Preferences ----------------------------------------------------------------------------

function Preferences({ today }: { today: string }) {
  const { hist, preps } = useMemo(() => {
    const h = redemptionHistory(today, 30);
    return { hist: h, preps: h.map((r) => historyPreparation(memberById(r.memberId), r.coffee, r.date)) };
  }, [today]);
  const served = COFFEES.map((c) => ({ code: c.code, label: c.label, note: c.note, value: hist.filter((r) => r.coffee === c.code).length })).sort((a, b) => b.value - a.value);
  const members = MEMBERS.filter((m) => m.state !== "pending");
  const favourites = COFFEES.map((c) => ({ label: c.label, value: members.filter((m) => m.favourite === c.code).length })).sort((a, b) => b.value - a.value);
  const total = hist.length;
  const top = served[0];
  return (
    <div>
      <PageHead title="Προτιμήσεις καφέ" lede={`Ο ${top.label} είναι ο πιο συχνός καφές Club, με ${Math.round((top.value / Math.max(1, total)) * 100)}% των καφέδων των τελευταίων 30 ημερών. Ο κατάλογος είναι ενδεικτικός και δεν έχει εγκριθεί ακόμη.`} />
      <div className="mt-12 grid grid-cols-[minmax(0,1fr)] gap-x-16 gap-y-12 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
        <section aria-labelledby="evc-pref-served">
          <SectionHead id="evc-pref-served" title="Καφέδες που σερβιρίστηκαν" note={`${total} τις τελευταίες 30 ημέρες`} />
          <ol className="m-0 list-none p-0">
            {served.map((p) => (
              <li key={p.code} className="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)_5.5rem] items-center gap-4 border-b border-[var(--rule)] py-4 sm:grid-cols-[12rem_minmax(0,1fr)_6rem]">
                <span className="min-w-0">
                  <span className="t-body block truncate text-[var(--ink)]">{p.label}</span>
                  <span className="t-small block text-[var(--ink-3)]">{p.note}</span>
                </span>
                <span className="h-3 rounded-r-[4px] bg-[var(--bronze)]" style={{ width: `${Math.max(2, (p.value / top.value) * 100)}%` }} aria-hidden="true" />
                <span className="t-num text-right">
                  <span className="t-body text-[var(--ink)]">{p.value}</span>
                  <span className="t-small ml-2 text-[var(--ink-3)]">{Math.round((p.value / Math.max(1, total)) * 100)}%</span>
                </span>
              </li>
            ))}
          </ol>
        </section>
        <section aria-labelledby="evc-pref-fav">
          <SectionHead id="evc-pref-fav" title="Συνήθης επιλογή των μελών" note={`${members.length} μέλη`} />
          <p className="t-small mb-3 text-[var(--ink-2)]">Ο καφές που παίρνει συνήθως κάθε μέλος. Το ταμείο τον προτείνει πρώτο.</p>
          <ol className="m-0 list-none p-0">
            {favourites.map((f) => (
              <li key={f.label} className="grid grid-cols-[minmax(0,1fr)_auto] gap-4 border-b border-[var(--rule)] py-3">
                <span className="t-body text-[var(--ink)]">{f.label}</span>
                <span className="t-body t-num text-[var(--ink-2)]">{f.value}</span>
              </li>
            ))}
          </ol>
        </section>
      </div>
      <section aria-labelledby="evc-pref-prep" className="mt-14">
        <SectionHead id="evc-pref-prep" title="Πώς τον πίνουν" note="από την παρασκευή κάθε καφέ, 30 ημέρες" />
        <div className="grid grid-cols-[minmax(0,1fr)] gap-x-12 gap-y-8 md:grid-cols-3">
          {(["sweetness", "sweetener", "milk"] as GroupId[]).map((g) => (
            <PrepDistribution key={g} group={g} preps={preps} />
          ))}
        </div>
      </section>
      <PreviewNote className="mt-12">Φανταστικά δεδομένα. Ο τελικός κατάλογος καφέδων Club και οι επιλογές παρασκευής αναμένουν έγκριση από τον ιδιοκτήτη.</PreviewNote>
    </div>
  );
}

/** Share of each option among the coffees where the group applied (single series, bronze). */
function PrepDistribution({ group, preps }: { group: GroupId; preps: CoffeeOrder[] }) {
  const chosen = preps.map((p) => p.choices[group]).filter((v): v is string => v !== undefined);
  const rows = GROUPS[group].options.map((o) => ({ label: o.label, value: chosen.filter((v) => v === o.id).length })).sort((a, b) => b.value - a.value);
  const max = Math.max(1, ...rows.map((r) => r.value));
  return (
    <div>
      <h3 className="t-label flex items-baseline justify-between text-[var(--ink)]">
        {GROUPS[group].label}
        <span className="t-small t-num font-normal text-[var(--ink-3)]">{chosen.length} καφέδες</span>
      </h3>
      <ol className="m-0 mt-2 list-none p-0">
        {rows.map((r) => (
          <li key={r.label} className="grid grid-cols-[8rem_minmax(0,1fr)_2.75rem] items-center gap-3 border-b border-[var(--rule)] py-2.5">
            <span className="t-small truncate text-[var(--ink)]">{r.label}</span>
            <span className="h-2 rounded-r-[4px] bg-[var(--bronze)]" style={{ width: `${Math.max(2, (r.value / max) * 100)}%` }} aria-hidden="true" />
            <span className="t-small t-num text-right text-[var(--ink-2)]">{Math.round((r.value / Math.max(1, chosen.length)) * 100)}%</span>
          </li>
        ))}
      </ol>
    </div>
  );
}

// ---- Analytics ----------------------------------------------------------------------------------

function ColumnChart({ data, label, height = 200, highlight }: { data: { label: string; value: number }[]; label: string; height?: number; highlight?: number }) {
  const [hover, setHover] = useState<number | null>(null);
  const top = Math.max(4, Math.ceil(Math.max(...data.map((d) => d.value)) / 4) * 4);
  const ticks = [0, top / 2, top];
  return (
    <figure className="m-0">
      <div className="grid grid-cols-[2rem_minmax(0,1fr)] gap-x-2">
        <div className="relative" style={{ height }} aria-hidden="true">
          {ticks.map((t) => (
            <span key={t} className="t-num absolute right-0 translate-y-1/2 text-[11px] text-[var(--ink-3)]" style={{ bottom: `${(t / top) * 100}%` }}>{t}</span>
          ))}
        </div>
        <div className="relative" style={{ height }} role="img" aria-label={label}>
          {ticks.map((t) => (
            <span key={t} className={cx("absolute inset-x-0 border-t", t === 0 ? "border-[var(--ink-3)]" : "border-[var(--rule)]")} style={{ bottom: `${(t / top) * 100}%` }} aria-hidden="true" />
          ))}
          <div className="absolute inset-0 flex items-end gap-[2px]">
            {data.map((d, i) => (
              <button key={d.label} type="button" aria-label={`${d.label}: ${d.value}`} onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)} onFocus={() => setHover(i)} onBlur={() => setHover(null)} className="flex h-full min-w-0 flex-1 items-end justify-center">
                <span className={cx("block w-full max-w-[40px] rounded-t-[4px] transition-colors duration-150", i === highlight ? "bg-[var(--roast)]" : hover === null || hover === i ? "bg-[var(--bronze)]" : "bg-[var(--bronze)]/35")} style={{ height: `${Math.max(1, (d.value / top) * 100)}%` }} />
              </button>
            ))}
          </div>
          {hover !== null && (
            <div className="pointer-events-none absolute top-0 z-10 -translate-x-1/2 -translate-y-[calc(100%+6px)] rounded-[6px] bg-[var(--roast)] px-2.5 py-1.5 text-[12px] whitespace-nowrap text-[var(--milk)]" style={{ left: `${((hover + 0.5) / data.length) * 100}%` }}>
              {data[hover].label}: <strong className="t-num">{data[hover].value}</strong>
            </div>
          )}
        </div>
        <span />
        <div className="t-small mt-2 flex text-[var(--ink-3)]" aria-hidden="true">
          {data.map((d) => (
            <span key={d.label} className="min-w-0 flex-1 truncate text-center">{d.label}</span>
          ))}
        </div>
      </div>
      <div className="sr-only">
        <table>
        <caption>{label}</caption>
        <tbody>
          {data.map((d) => (
            <tr key={d.label}><th scope="row">{d.label}</th><td>{d.value}</td></tr>
          ))}
        </tbody>
        </table>
      </div>
    </figure>
  );
}

function Analytics({ today }: { today: string }) {
  const hist = useMemo(() => redemptionHistory(today, 28), [today]);
  const weekdays = ["Δευ", "Τρι", "Τετ", "Πεμ", "Παρ", "Σαβ", "Κυρ"];
  const byWeekday = weekdays.map((label, i) => ({ label, value: hist.filter((r) => (new Date(`${r.date}T12:00:00Z`).getUTCDay() + 6) % 7 === i).length }));
  const busiest = byWeekday.reduce((a, b, i) => (b.value > byWeekday[a].value ? i : a), 0);
  const hours = Array.from({ length: 11 }, (_, i) => 7 + i).map((h) => ({ hour: h, value: hist.filter((r) => Number(r.time.slice(0, 2)) === h).length }));
  const active = MEMBERS.filter((m) => m.state === "active");
  const eligibleDays = Array.from({ length: 28 }, (_, i) => addDays(today, -(i + 1))).reduce((s, date, i) => s + MEMBERS.filter((m) => m.state !== "pending" && date >= m.memberSince && (m.state === "active" || i + 1 >= -m.paidUntilOffsetDays)).length, 0);
  const buckets = [
    { label: "0", test: (n: number) => n === 0 },
    { label: "1–3", test: (n: number) => n >= 1 && n <= 3 },
    { label: "4–6", test: (n: number) => n >= 4 && n <= 6 },
    { label: "7–9", test: (n: number) => n >= 7 && n <= 9 },
    { label: "10–12", test: (n: number) => n >= 10 && n <= 12 },
    { label: "13+", test: (n: number) => n >= 13 },
  ].map((b) => ({ label: b.label, value: MEMBERS.filter((m) => m.state !== "pending" && b.test(m.visitsThisMonth)).length }));
  const newsletter = MEMBERS.filter((m) => m.newsletter).length;
  return (
    <div>
      <PageHead title="Στατιστικά" lede={`Οι τελευταίες 4 εβδομάδες. Πιο πολυσύχναστη ημέρα για τα μέλη: ${["Δευτέρα", "Τρίτη", "Τετάρτη", "Πέμπτη", "Παρασκευή", "Σάββατο", "Κυριακή"][busiest]}.`} />
      <dl className="mt-10 grid grid-cols-2 border-y border-[var(--rule-strong)] lg:grid-cols-4">
        <Metric label="Καφέδες την ημέρα" value={fmt(hist.length / 28, 1)} note="μέσος όρος 4 εβδομάδων" />
        <Metric label="Χρήση δικαιώματος" value={`${Math.round((hist.length / Math.max(1, eligibleDays)) * 100)}%`} note="των ημερών με ενεργή συνδρομή" />
        <Metric label="Συνδρομές μήνα" value={`${fmt(active.length * CLUB_RULES.monthlyPriceEur)} €`} note={`εκτίμηση: ${active.length} ενεργές × ${CLUB_RULES.monthlyPriceEur} €`} />
        <Metric label="Newsletter" value={`${Math.round((newsletter / MEMBERS.length) * 100)}%`} note={`${newsletter} από ${MEMBERS.length}, ξεχωριστή συναίνεση`} />
      </dl>
      <div className="mt-12 grid grid-cols-[minmax(0,1fr)] gap-x-14 gap-y-12 lg:grid-cols-2">
        <section aria-labelledby="evc-an-week">
          <SectionHead id="evc-an-week" title="Ανά ημέρα της εβδομάδας" note="καφέδες, 4 εβδομάδες" />
          <ColumnChart data={byWeekday} label="Καφέδες Club ανά ημέρα της εβδομάδας, τελευταίες 4 εβδομάδες" highlight={busiest} />
        </section>
        <section aria-labelledby="evc-an-hour">
          <SectionHead id="evc-an-hour" title="Ανά ώρα" note="ώρα Ελλάδας" />
          <HourChart data={hours} />
        </section>
        <section aria-labelledby="evc-an-dist" className="lg:col-span-2">
          <SectionHead id="evc-an-dist" title="Πόσο συχνά έρχονται τα μέλη" note="μέλη ανά αριθμό καφέδων αυτόν τον μήνα" />
          <div className="grid grid-cols-[minmax(0,1fr)] gap-x-14 gap-y-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
            <ColumnChart data={buckets} label="Αριθμός μελών ανά πλήθος καφέδων αυτόν τον μήνα" height={170} />
            <p className="t-body self-center text-[var(--ink-2)]">Κάθε στήλη μετρά μέλη με ενεργή ή ληγμένη συνδρομή. Τα μέλη χωρίς καφέ αυτόν τον μήνα είναι καλοί υποψήφιοι για μια υπενθύμιση, όταν το newsletter το επιτρέπει.</p>
          </div>
        </section>
      </div>
      <PreviewNote className="mt-12">Όλοι οι αριθμοί είναι φανταστικοί. Η εκτίμηση συνδρομών δεν είναι λογιστικό στοιχείο.</PreviewNote>
    </div>
  );
}

// ---- Settings ----------------------------------------------------------------------------------

function Settings() {
  const sections: { id: string; title: string; rows: [string, string][] }[] = [
    {
      id: "evc-set-membership",
      title: "Συνδρομή",
      rows: [
        ["Τιμή", `${CLUB_RULES.monthlyPriceEur} € για έναν ημερολογιακό μήνα`],
        ["Διάρκεια", "Ένας ημερολογιακός μήνας από την επιβεβαίωση της πληρωμής, με λήξη ώρα Ελλάδας"],
        ["Τέλος μήνα", "Αν η ημέρα δεν υπάρχει, λήγει την τελευταία ημέρα του μήνα (π.χ. 31/1 έως 28/2)"],
      ],
    },
    {
      id: "evc-set-coffee",
      title: "Δωρεάν καφές",
      rows: [
        ["Όριο", `${CLUB_RULES.coffeesPerAthensDay} καφές ανά ημερολογιακή ημέρα (Europe/Athens)`],
        ["Μεταφορά", "Ο καφές μιας ημέρας δεν μεταφέρεται στην επόμενη"],
        ["Κατάλογος", `Ενδεικτικός, αναμένει έγκριση: ${COFFEES.map((c) => c.label).join(", ")}`],
      ],
    },
    {
      id: "evc-set-renewal",
      title: "Ανανέωση",
      rows: [
        ["Τρόπος", "Χειροκίνητη στους πρώτους μήνες, χωρίς αυτόματη χρέωση"],
        ["Πρόωρη ανανέωση", "Συνεχίζει από τη λήξη της τρέχουσας συνδρομής"],
        ["Καθυστερημένη ανανέωση", "Ξεκινά από την επιβεβαίωση της πληρωμής"],
      ],
    },
    {
      id: "evc-set-counter",
      title: "Ταμείο",
      rows: [
        ["Λογαριασμός", "Ένας κοινός, περιορισμένος λογαριασμός προσωπικού"],
        ["Αναγνώριση μέλους", "Σάρωση κάρτας ή αναζήτηση με κινητό"],
      ],
    },
  ];
  const status: [string, string][] = [
    ["Εφαρμογή ταμείου", "Εκτός λειτουργίας"],
    ["Εξαργυρώσεις", "Μη διαθέσιμες"],
    ["Πληρωμές", "Δεν έχουν συνδεθεί"],
  ];
  const jump = (id: string) => document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });
  return (
    <div>
      <PageHead title="Ρυθμίσεις Club" lede="Μόνο προβολή. Οι κανόνες έχουν εγκριθεί από τον ιδιοκτήτη και αλλάζουν μόνο με νέα έγκριση." />
      <div className="mt-10 grid grid-cols-[minmax(0,1fr)] gap-x-16 gap-y-8 lg:grid-cols-[13rem_minmax(0,1fr)]">
        <nav aria-label="Ενότητες ρυθμίσεων" className="lg:sticky lg:top-24 lg:self-start">
          <ul className="m-0 flex list-none gap-1 overflow-x-auto p-0 [scrollbar-width:none] lg:flex-col">
            {[...sections.map((s) => [s.id, s.title]), ["evc-set-status", "Κατάσταση συστήματος"]].map(([id, title]) => (
              <li key={id} className="flex-none">
                <button type="button" onClick={() => jump(id)} className="t-label min-h-10 rounded-full px-3 text-left whitespace-nowrap text-[var(--ink-2)] hover:bg-[rgba(139,118,80,0.1)] hover:text-[var(--ink)] lg:w-full lg:rounded-[8px]">{title}</button>
              </li>
            ))}
          </ul>
        </nav>
        <div className="max-w-[820px]">
          {sections.map((s) => (
            <section key={s.id} id={s.id} aria-labelledby={`${s.id}-h`} className="mb-12 scroll-mt-28">
              <SectionHead id={`${s.id}-h`} title={s.title} note="μόνο προβολή" />
              <DefRows rows={s.rows} />
            </section>
          ))}
          <section id="evc-set-status" aria-labelledby="evc-set-status-h" className="scroll-mt-28">
            <SectionHead id="evc-set-status-h" title="Κατάσταση συστήματος" />
            <ul className="m-0 list-none p-0">
              {status.map(([k, v]) => (
                <li key={k} className="grid gap-x-6 gap-y-1 border-b border-[var(--rule)] py-3.5 sm:grid-cols-[11rem_minmax(0,1fr)] sm:items-baseline">
                  <span className="t-small text-[var(--ink-2)]">{k}</span>
                  <span className="t-body inline-flex items-center gap-2 text-[var(--ink)]">
                    <span className="h-2 w-2 flex-none rounded-full bg-[var(--stop)]" aria-hidden="true" />
                    {v}
                  </span>
                </li>
              ))}
            </ul>
          </section>
          <PreviewNote className="mt-10">Καμία ρύθμιση δεν μπορεί να αλλάξει από εδώ.</PreviewNote>
        </div>
      </div>
    </div>
  );
}
