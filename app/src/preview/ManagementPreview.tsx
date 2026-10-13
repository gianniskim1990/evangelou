import { useMemo, useState } from "react";
import { CLUB_RULES, COFFEES, MEMBERS, addDays, coffeeName, greekDate, memberById, memberHistory, redemptionHistory, shortGreekDate, todaysRedemptions, type PreviewMembershipState } from "./previewData";
import type { ManagementScreen } from "./screens";
import { ManagementFrame, ManagementOverview, MembersDirectory } from "./ManagementSignature";
import { Avatar, BarChart, Card, Eyebrow, PreviewOnly, ScreenTitle, Stat, StatusBadge } from "./ui";

/* UI-01 design preview — Club management (read-only, synthetic). This is NOT
 * the ordering AdminApp and has no create/edit/activate/cancel/payment action. */

export function ManagementPreview({ screen, go, today }: { screen: ManagementScreen; go: (s: ManagementScreen) => void; today: string }) {
  const [memberId, setMemberId] = useState("m01");
  const openMember = (id: string) => {
    setMemberId(id);
    go("member");
  };
  // UI-01.R1 signature screens (new design system, full-bleed).
  if (screen === "overview" || screen === "members") {
    return (
      <ManagementFrame screen={screen} go={go}>
        {screen === "overview" ? <ManagementOverview today={today} go={go} openMember={openMember} /> : <MembersDirectory today={today} openMember={openMember} />}
      </ManagementFrame>
    );
  }
  return (
    <div className="mx-auto w-full max-w-[1180px] px-4 py-6 md:px-8 md:py-8">
      {screen === "member" && <MemberDetails id={memberId} today={today} />}
      {screen === "statuses" && <Statuses today={today} openMember={openMember} />}
      {screen === "activity" && <Activity today={today} />}
      {screen === "preferences" && <Preferences today={today} />}
      {screen === "analytics" && <Analytics today={today} />}
      {screen === "settings" && <Settings />}
    </div>
  );
}

function MemberDetails({ id, today }: { id: string; today: string }) {
  const m = memberById(id);
  const hist = memberHistory(m.id, today);
  const byCoffee = COFFEES.map((c) => ({ label: c.label, value: hist.filter((r) => r.coffee === c.code).length })).filter((d) => d.value > 0);
  return (
    <div>
      <ScreenTitle eyebrow="Καρτέλα μέλους" title={m.name} />
      <div className="grid gap-4 lg:grid-cols-[1fr_1.3fr]">
        <Card className="p-6">
          <div className="flex items-center gap-4"><Avatar initials={m.initials} size={56} /><StatusBadge state={m.state} /></div>
          <dl className="mt-5 grid grid-cols-[auto_1fr] gap-x-6 gap-y-3 text-[14px]">
            <dt className="text-espresso/55">Τηλέφωνο</dt><dd className="tabular-nums">{m.phoneMasked}</dd>
            <dt className="text-espresso/55">E-mail</dt><dd>{m.email}</dd>
            <dt className="text-espresso/55">Μέλος από</dt><dd>{greekDate(m.memberSince)}</dd>
            <dt className="text-espresso/55">Ισχύει έως</dt><dd>{m.state === "pending" ? "Αναμονή πληρωμής" : greekDate(addDays(today, m.paidUntilOffsetDays))}</dd>
            <dt className="text-espresso/55">Ανανέωση</dt><dd>Χειροκίνητη</dd>
            <dt className="text-espresso/55">Newsletter</dt><dd>{m.newsletter ? "Ναι (ξεχωριστή συναίνεση)" : "Όχι"}</dd>
          </dl>
        </Card>
        <Card>
          <p className="mb-4 text-[15px] font-semibold">Προτιμήσεις καφέ (21 ημέρες)</p>
          {byCoffee.length ? <BarChart data={byCoffee} height={140} label={`Προτιμήσεις καφέ του μέλους ${m.name}`} /> : <p className="text-[14px] text-espresso/55">Χωρίς καφέδες.</p>}
        </Card>
      </div>
      <Card className="mt-4 p-0">
        <p className="border-b border-espresso/8 px-5 py-3.5 text-[15px] font-semibold">Πρόσφατοι καφέδες</p>
        {hist.slice(0, 8).map((r) => (
          <div key={`${r.date}-${r.time}`} className="flex justify-between border-b border-espresso/8 px-5 py-3 text-[14px] last:border-0">
            <span>{greekDate(r.date, true)}</span><span className="font-medium">{coffeeName(r.coffee)}</span><span className="text-espresso/55 tabular-nums">{r.time}</span>
          </div>
        ))}
        {hist.length === 0 && <p className="px-5 py-5 text-[14px] text-espresso/55">Δεν υπάρχουν καφέδες.</p>}
      </Card>
      <PreviewOnly>Δεν υπάρχουν κουμπιά ενεργοποίησης, ανανέωσης, ακύρωσης ή πληρωμής: οι δυνατότητες διαχείρισης και τα δικαιώματα θα οριστικοποιηθούν ξεχωριστά.</PreviewOnly>
    </div>
  );
}

function Statuses({ today, openMember }: { today: string; openMember: (id: string) => void }) {
  const groups: { state: PreviewMembershipState; title: string }[] = [
    { state: "active", title: "Ενεργές" },
    { state: "pending", title: "Σε αναμονή πληρωμής" },
    { state: "expired", title: "Έληξαν" },
  ];
  return (
    <div>
      <ScreenTitle eyebrow="Συνδρομές" title="Καταστάσεις συνδρομών" />
      <div className="grid gap-4 lg:grid-cols-3">
        {groups.map((g) => {
          const ms = MEMBERS.filter((m) => m.state === g.state);
          return (
            <Card key={g.state} className="p-0">
              <div className="flex items-center justify-between border-b border-espresso/8 px-5 py-3.5">
                <StatusBadge state={g.state} />
                <span className="font-literata text-[22px] font-semibold">{ms.length}</span>
              </div>
              {ms.map((m) => (
                <button key={m.id} type="button" onClick={() => openMember(m.id)} className="flex w-full items-center justify-between gap-3 border-b border-espresso/8 px-5 py-3 text-left last:border-0 hover:bg-cream">
                  <span className="text-[14px] font-medium">{m.name}</span>
                  <span className="text-[12.5px] text-espresso/55">{g.state === "pending" ? "—" : shortGreekDate(addDays(today, m.paidUntilOffsetDays))}</span>
                </button>
              ))}
            </Card>
          );
        })}
      </div>
    </div>
  );
}

function Activity({ today }: { today: string }) {
  const rows = useMemo(() => [...todaysRedemptions(today), ...redemptionHistory(today, 7)], [today]);
  return (
    <div>
      <ScreenTitle eyebrow="Δραστηριότητα" title="Εξαργυρώσεις (7 ημέρες)" />
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[560px] text-left text-[14px]">
          <thead className="border-b border-espresso/10 text-[12px] text-espresso/55">
            <tr><th className="px-5 py-3 font-semibold">Ημερομηνία</th><th className="px-3 py-3 font-semibold">Ώρα</th><th className="px-3 py-3 font-semibold">Μέλος</th><th className="px-5 py-3 font-semibold">Καφές</th></tr>
          </thead>
          <tbody>
            {rows.slice(0, 30).map((r) => (
              <tr key={`${r.date}-${r.memberId}`} className="border-b border-espresso/8 last:border-0">
                <td className="px-5 py-3">{r.date === today ? "Σήμερα" : shortGreekDate(r.date)}</td>
                <td className="px-3 py-3 text-espresso/60 tabular-nums">{r.time}</td>
                <td className="px-3 py-3 font-medium">{memberById(r.memberId).name}</td>
                <td className="px-5 py-3">{coffeeName(r.coffee)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
      <p className="mt-3 text-[12px] text-espresso/45">Δείγμα δραστηριότητας — καμία εξαργύρωση δεν είναι πραγματική.</p>
    </div>
  );
}

function Preferences({ today }: { today: string }) {
  const hist = redemptionHistory(today, 30);
  const data = COFFEES.map((c) => ({ label: c.label, value: hist.filter((r) => r.coffee === c.code).length })).sort((a, b) => b.value - a.value);
  const total = data.reduce((s, d) => s + d.value, 0);
  return (
    <div>
      <ScreenTitle eyebrow="Προτιμήσεις" title="Ιστορικό προτιμήσεων καφέ" lead="Ποιοι καφέδες επιλέγονται με τον δωρεάν καφέ (30 ημέρες)." />
      <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
        <Card><BarChart data={data} height={190} label="Καφέδες ανά είδος, 30 ημέρες" /></Card>
        <Card className="p-0">
          {data.map((d) => (
            <div key={d.label} className="flex items-center justify-between border-b border-espresso/8 px-5 py-3 text-[14px] last:border-0">
              <span className="font-medium">{d.label}</span>
              <span className="text-espresso/60 tabular-nums">{d.value} · {total ? Math.round((d.value / total) * 100) : 0}%</span>
            </div>
          ))}
        </Card>
      </div>
    </div>
  );
}

function Analytics({ today }: { today: string }) {
  const hist = redemptionHistory(today, 28);
  const weekdays = ["Κυρ", "Δευ", "Τρι", "Τετ", "Πεμ", "Παρ", "Σαβ"];
  const byWeekday = weekdays.map((label, wd) => ({ label, value: hist.filter((r) => new Date(`${r.date}T12:00:00Z`).getUTCDay() === wd).length }));
  const hours = ["08", "09", "10", "11", "12", "13", "16", "17"];
  const byHour = hours.map((h) => ({ label: `${h}:00`, value: hist.filter((r) => r.time.startsWith(h)).length }));
  const active = MEMBERS.filter((m) => m.state === "active").length;
  return (
    <div>
      <ScreenTitle eyebrow="Στατιστικά" title="Βασικά στατιστικά" lead="Συνθετικά δεδομένα 28 ημερών." />
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Μέσος όρος / ημέρα" value={(hist.length / 28).toFixed(1)} />
        <Stat label="Χρήση από ενεργά μέλη" value={`${Math.round((hist.length / (active * 28)) * 100)}%`} hint="καφέδες / δυνατές ημέρες" />
        <Stat label="Μηνιαίο έσοδο (εκτίμηση)" value={`${active * CLUB_RULES.monthlyPriceEur} €`} hint={`${active} × ${CLUB_RULES.monthlyPriceEur} €`} />
        <Stat label="Newsletter" value={String(MEMBERS.filter((m) => m.newsletter).length)} hint="ξεχωριστή συναίνεση" />
      </div>
      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <Card><p className="mb-4 text-[15px] font-semibold">Ανά ημέρα εβδομάδας</p><BarChart data={byWeekday} label="Καφέδες ανά ημέρα εβδομάδας" /></Card>
        <Card><p className="mb-4 text-[15px] font-semibold">Ανά ώρα (ώρα Ελλάδας)</p><BarChart data={byHour} label="Καφέδες ανά ώρα" /></Card>
      </div>
    </div>
  );
}

function Settings() {
  const rows: [string, string][] = [
    ["Μηνιαία συνδρομή", `${CLUB_RULES.monthlyPriceEur} €`],
    ["Δωρεάν καφές", `${CLUB_RULES.coffeesPerAthensDay} ανά ημερολογιακή ημέρα (Europe/Athens)`],
    ["Διάρκεια", "Ένας ημερολογιακός μήνας από την επιβεβαίωση πληρωμής"],
    ["Πρόωρη ανανέωση", "Συνεχίζει από τη λήξη της τρέχουσας συνδρομής"],
    ["Καθυστερημένη ανανέωση", "Ξεκινά από την επιβεβαίωση της πληρωμής"],
    ["Τέλος μήνα", "Τελευταία ημέρα του μήνα (π.χ. 31/1 → 28/2)"],
    ["Ανανέωση", "Χειροκίνητη (αρχικοί μήνες)"],
    ["Λογαριασμός ταμείου", "Ένας κοινός, περιορισμένος λογαριασμός προσωπικού"],
  ];
  return (
    <div>
      <ScreenTitle eyebrow="Ρυθμίσεις" title="Ρυθμίσεις Club" lead="Μόνο για προβολή — οι κανόνες έχουν εγκριθεί από τον ιδιοκτήτη." />
      <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
        <Card className="p-0">
          {rows.map(([k, v]) => (
            <div key={k} className="grid gap-1 border-b border-espresso/8 px-5 py-3.5 last:border-0 md:grid-cols-[220px_1fr]">
              <span className="text-[13.5px] text-espresso/55">{k}</span><span className="text-[14px] font-medium">{v}</span>
            </div>
          ))}
        </Card>
        <div className="space-y-4">
          <Card>
            <Eyebrow>Κατάλογος καφέδων</Eyebrow>
            <div className="mt-2 flex flex-wrap gap-2">{COFFEES.map((c) => <span key={c.code} className="rounded-full border border-espresso/15 px-3 py-1 text-[13px]">{c.label}</span>)}</div>
          </Card>
          <Card>
            <Eyebrow>Κατάσταση συστήματος</Eyebrow>
            <ul className="mt-2 space-y-2 text-[13.5px]">
              {["Εφαρμογή ταμείου: εκτός λειτουργίας", "Εξαργυρώσεις: μη διαθέσιμες", "Πληρωμές: δεν έχουν συνδεθεί"].map((t) => (
                <li key={t} className="flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-maroon/70" aria-hidden="true" />{t}</li>
              ))}
            </ul>
          </Card>
        </div>
      </div>
      <PreviewOnly>Καμία ρύθμιση δεν μπορεί να αλλάξει από εδώ.</PreviewOnly>
    </div>
  );
}
