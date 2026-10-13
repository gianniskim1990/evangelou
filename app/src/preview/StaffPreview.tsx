import { useState } from "react";
import { COFFEES, MEMBERS, addDays, coffeeName, greekDate, memberById, memberHistory, todaysRedemptions, type CoffeeCode, type PreviewMember } from "./previewData";
import { cx, type StaffScreen } from "./screens";
import { Avatar, Card, Eyebrow, GhostButton, Icon, PreviewOnly, PrimaryButton, ScreenTitle, StatusBadge } from "./ui";

/* UI-01 design preview — Staff Club counter flow (visual only). The REAL
 * staff app (/club-admin/, WordPress) is separate, disabled and untouched. */

type Eligibility = "eligible" | "redeemed_today" | "expired" | "pending";

function eligibility(m: PreviewMember): Eligibility {
  if (m.state === "expired") return "expired";
  if (m.state === "pending") return "pending";
  return m.redeemedToday ? "redeemed_today" : "eligible";
}

export function StaffPreview({ screen, go, today }: { screen: StaffScreen; go: (s: StaffScreen) => void; today: string }) {
  const [memberId, setMemberId] = useState("m01");
  const [coffee, setCoffee] = useState<CoffeeCode | null>(null);
  const member = memberById(memberId);
  const openMember = (id: string) => {
    setMemberId(id);
    setCoffee(null);
    go("member");
  };
  return (
    <div className="mx-auto w-full max-w-[1024px] md:my-6 md:overflow-hidden md:rounded-[28px] md:border md:border-espresso/15 md:shadow-[0_24px_60px_-30px_rgba(30,24,18,0.45)]">
      <header className="flex items-center justify-between gap-3 border-b border-espresso/12 bg-surface px-5 py-3.5 md:px-7">
        <div className="flex items-center gap-3">
          <img src="/logo-evaggelou-color.png" alt="" className="h-7 object-contain" />
          <span className="font-literata text-[15px] font-semibold">Club <span className="font-normal text-espresso/50">· ταμείο</span></span>
        </div>
        <div className="flex items-center gap-3 text-[12.5px] text-espresso/55">
          <span className="hidden sm:inline">{greekDate(today, true)}</span>
          <span className="rounded-lg border border-espresso/15 px-2.5 py-1.5 font-semibold text-espresso/70">Ταμείο (δείγμα)</span>
        </div>
      </header>
      <div className="min-h-[640px] bg-cream px-5 py-6 md:px-8 md:py-8">
        {screen === "dashboard" && <Dashboard go={go} today={today} openMember={openMember} />}
        {screen === "scan" && <Scan openMember={openMember} />}
        {screen === "search" && <Search openMember={openMember} />}
        {screen === "member" && <MemberSummary member={member} today={today} go={go} openMember={openMember} />}
        {screen === "coffee" && <CoffeeSelect member={member} coffee={coffee} setCoffee={setCoffee} go={go} />}
        {screen === "confirm" && <Confirm member={member} coffee={coffee ?? member.favourite} go={go} />}
        {screen === "blocked" && <Blocked today={today} openMember={openMember} />}
        {screen === "history" && <MemberHistory member={member} today={today} />}
      </div>
    </div>
  );
}

function Dashboard({ go, today, openMember }: { go: (s: StaffScreen) => void; today: string; openMember: (id: string) => void }) {
  const served = todaysRedemptions(today);
  return (
    <div>
      <ScreenTitle eyebrow="Ταμείο" title="Καλή βάρδια" lead="Σαρώστε την κάρτα μέλους ή αναζητήστε με τηλέφωνο." />
      <div className="grid gap-4 md:grid-cols-2">
        <button type="button" onClick={() => go("scan")} className="flex items-center gap-4 rounded-2xl bg-espresso p-6 text-left text-cream">
          <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-bronze/45"><Icon name="qr" size={28} /></span>
          <span>
            <span className="font-literata block text-[21px] font-semibold">Σάρωση κάρτας</span>
            <span className="block text-[13.5px] text-cream/70">Κάμερα tablet</span>
          </span>
        </button>
        <button type="button" onClick={() => go("search")} className="flex items-center gap-4 rounded-2xl border border-espresso/15 bg-surface p-6 text-left">
          <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-[#efe6d2] text-bronze-dark"><Icon name="search" size={28} /></span>
          <span>
            <span className="font-literata block text-[21px] font-semibold">Αναζήτηση τηλεφώνου</span>
            <span className="block text-[13.5px] text-espresso/55">Όταν δεν υπάρχει κάρτα</span>
          </span>
        </button>
      </div>
      <div className="mt-6 grid gap-4 md:grid-cols-3">
        <Card><p className="text-[12px] text-espresso/55">Καφέδες Club σήμερα</p><p className="font-literata mt-1 text-[30px] font-semibold">{served.length}</p></Card>
        <Card><p className="text-[12px] text-espresso/55">Ενεργά μέλη</p><p className="font-literata mt-1 text-[30px] font-semibold">{MEMBERS.filter((m) => m.state === "active").length}</p></Card>
        <Card><p className="text-[12px] text-espresso/55">Τελευταία εξαργύρωση</p><p className="font-literata mt-1 text-[30px] font-semibold">{served[served.length - 1]?.time ?? "—"}</p></Card>
      </div>
      <Card className="mt-4 p-0">
        <p className="border-b border-espresso/8 px-5 py-3 text-[13px] font-semibold text-espresso/70">Σήμερα (δείγμα)</p>
        {served.map((r) => {
          const m = memberById(r.memberId);
          return (
            <button key={r.memberId} type="button" onClick={() => openMember(m.id)} className="flex w-full items-center gap-3 border-b border-espresso/8 px-5 py-3 text-left last:border-0">
              <Avatar initials={m.initials} size={36} />
              <span className="flex-1 text-[14px] font-medium">{m.name}</span>
              <span className="text-[13px] text-espresso/55">{coffeeName(r.coffee)} · {r.time}</span>
            </button>
          );
        })}
      </Card>
    </div>
  );
}

function Scan({ openMember }: { openMember: (id: string) => void }) {
  return (
    <div className="mx-auto max-w-[560px] text-center">
      <ScreenTitle eyebrow="Σάρωση" title="Στρέψτε την κάμερα στην κάρτα" />
      <div className="relative mx-auto aspect-[4/3] w-full overflow-hidden rounded-3xl bg-[#2a231b]">
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_40%,rgba(255,255,255,0.08),transparent_60%)]" />
        <div className="absolute top-1/2 left-1/2 h-[58%] w-[44%] -translate-x-1/2 -translate-y-1/2 rounded-2xl border-2 border-dashed border-[#d9c9a0]/80" />
        <div className="absolute inset-x-0 bottom-4 flex justify-center">
          <span className="flex items-center gap-2 rounded-full bg-black/45 px-3.5 py-1.5 text-[12.5px] text-cream/85"><Icon name="camera" size={15} /> Η κάμερα δεν ενεργοποιείται στην προεπισκόπηση</span>
        </div>
      </div>
      <div className="mt-5 flex flex-wrap justify-center gap-3">
        <PrimaryButton onClick={() => openMember("m01")}>Προσομοίωση σάρωσης</PrimaryButton>
        <GhostButton onClick={() => openMember("m03")}>Προσομοίωση: ληγμένη κάρτα</GhostButton>
      </div>
      <PreviewOnly>Καμία κάμερα, κανένας κωδικός και καμία σύνδεση με το σύστημα — μόνο δείγμα ροής.</PreviewOnly>
    </div>
  );
}

function Search({ openMember }: { openMember: (id: string) => void }) {
  const [q, setQ] = useState("69");
  const keys = ["1", "2", "3", "4", "5", "6", "7", "8", "9", "⌫", "0", "OK"];
  const press = (k: string) => setQ((v) => (k === "⌫" ? v.slice(0, -1) : k === "OK" ? v : (v + k).slice(0, 10)));
  return (
    <div className="grid gap-6 md:grid-cols-[minmax(0,360px)_1fr]">
      <div>
        <ScreenTitle eyebrow="Αναζήτηση" title="Κινητό τηλέφωνο" />
        <div className="rounded-2xl border border-espresso/15 bg-surface px-5 py-4 font-literata text-[28px] tracking-[0.12em] tabular-nums">{q || <span className="text-espresso/30">69…</span>}</div>
        <div className="mt-4 grid grid-cols-3 gap-2.5">
          {keys.map((k) => (
            <button key={k} type="button" onClick={() => press(k)} className={cx("rounded-xl py-4 text-[20px] font-semibold", k === "OK" ? "bg-bronze-dark text-white" : "border border-espresso/12 bg-surface")}>{k}</button>
          ))}
        </div>
      </div>
      <div>
        <Eyebrow>Αποτελέσματα (δείγμα)</Eyebrow>
        <Card className="p-0">
          {MEMBERS.slice(0, 5).map((m) => (
            <button key={m.id} type="button" onClick={() => openMember(m.id)} className="flex w-full items-center gap-3 border-b border-espresso/8 px-5 py-4 text-left last:border-0 hover:bg-cream">
              <Avatar initials={m.initials} />
              <span className="flex-1">
                <span className="block text-[15px] font-semibold">{m.name}</span>
                <span className="block text-[13px] text-espresso/50 tabular-nums">{m.phoneMasked}</span>
              </span>
              <StatusBadge state={m.state} />
            </button>
          ))}
        </Card>
        <PreviewOnly>Τα αποτελέσματα είναι σταθερά δείγματα· η αναζήτηση δεν συνδέεται με καμία βάση.</PreviewOnly>
      </div>
    </div>
  );
}

const ELIGIBILITY_COPY: Record<Eligibility, { title: string; body: string; tone: string; icon: "check" | "coffee" | "x" | "clock" }> = {
  eligible: { title: "Δικαιούται δωρεάν καφέ σήμερα", body: "Η συνδρομή είναι ενεργή και δεν έχει εξαργυρωθεί καφές σήμερα.", tone: "border-[#2f5a2a]/25 bg-[#e3eedf] text-[#2f5a2a]", icon: "check" },
  redeemed_today: { title: "Ο σημερινός καφές έχει ήδη δοθεί", body: "Ένας δωρεάν καφές ανά ημέρα. Νέο δικαίωμα από τα μεσάνυχτα (ώρα Ελλάδας).", tone: "border-[#7a5a14]/25 bg-[#f6ecd6] text-[#7a5a14]", icon: "coffee" },
  expired: { title: "Η συνδρομή έχει λήξει", body: "Δεν δικαιούται δωρεάν καφέ. Το μέλος μπορεί να ανανεώσει.", tone: "border-maroon/25 bg-[#f4e2e3] text-maroon", icon: "x" },
  pending: { title: "Πληρωμή σε αναμονή", body: "Η συνδρομή δεν έχει ενεργοποιηθεί ακόμη. Δεν δικαιούται δωρεάν καφέ.", tone: "border-[#7a5a14]/25 bg-[#f6ecd6] text-[#7a5a14]", icon: "clock" },
};

const SCENARIO_LABEL: Record<Eligibility, string> = {
  eligible: "δικαιούται",
  redeemed_today: "ήδη σήμερα",
  expired: "έληξε",
  pending: "αναμονή πληρωμής",
};

function EligibilityPanel({ m }: { m: PreviewMember }) {
  const e = ELIGIBILITY_COPY[eligibility(m)];
  return (
    <div className={cx("flex gap-3.5 rounded-2xl border p-5", e.tone)}>
      <Icon name={e.icon} size={26} className="mt-0.5 flex-none" />
      <div>
        <p className="font-literata text-[19px] font-semibold">{e.title}</p>
        <p className="mt-1 text-[14px] opacity-85">{e.body}</p>
      </div>
    </div>
  );
}

function MemberSummary({ member, today, go, openMember }: { member: PreviewMember; today: string; go: (s: StaffScreen) => void; openMember: (id: string) => void }) {
  const el = eligibility(member);
  return (
    <div>
      <div className="mb-5 flex flex-wrap gap-2" aria-label="Δείγματα μελών">
        {MEMBERS.slice(0, 4).map((m) => (
          <button key={m.id} type="button" onClick={() => openMember(m.id)} className={cx("rounded-full border px-3 py-1.5 text-[12.5px] font-semibold", m.id === member.id ? "border-bronze-dark bg-bronze-dark text-white" : "border-espresso/15 bg-surface text-espresso/70")}>
            Δείγμα: {SCENARIO_LABEL[eligibility(m)]}
          </button>
        ))}
      </div>
      <div className="grid gap-5 md:grid-cols-[1fr_1fr]">
        <Card className="p-6">
          <div className="flex items-center gap-4">
            <Avatar initials={member.initials} size={60} />
            <div>
              <p className="font-literata text-[22px] font-semibold">{member.name}</p>
              <p className="text-[13.5px] text-espresso/55 tabular-nums">{member.phoneMasked}</p>
            </div>
          </div>
          <dl className="mt-5 grid grid-cols-2 gap-y-3 text-[13.5px]">
            <dt className="text-espresso/55">Συνδρομή</dt><dd><StatusBadge state={member.state} /></dd>
            <dt className="text-espresso/55">Ισχύει έως</dt><dd className="font-medium">{member.state === "pending" ? "—" : greekDate(addDays(today, member.paidUntilOffsetDays))}</dd>
            <dt className="text-espresso/55">Αγαπημένος</dt><dd className="font-medium">{coffeeName(member.favourite)}</dd>
            <dt className="text-espresso/55">Μέλος από</dt><dd className="font-medium">{greekDate(member.memberSince)}</dd>
          </dl>
          <button type="button" onClick={() => go("history")} className="mt-5 flex items-center gap-1.5 text-[13.5px] font-semibold text-bronze-dark"><Icon name="history" size={16} /> Ιστορικό καφέδων</button>
        </Card>
        <div>
          <EligibilityPanel m={member} />
          {el === "eligible" ? (
            <PrimaryButton className="mt-4 w-full py-4 text-[17px]" onClick={() => go("coffee")}>Επιλογή καφέ <Icon name="arrow" size={17} /></PrimaryButton>
          ) : (
            <GhostButton className="mt-4 w-full" onClick={() => go("dashboard")}>Επιστροφή στο ταμείο</GhostButton>
          )}
        </div>
      </div>
    </div>
  );
}

function CoffeeSelect({ member, coffee, setCoffee, go }: { member: PreviewMember; coffee: CoffeeCode | null; setCoffee: (c: CoffeeCode) => void; go: (s: StaffScreen) => void }) {
  const chosen = coffee ?? member.favourite;
  return (
    <div>
      <ScreenTitle eyebrow={member.name} title="Ποιος καφές σερβίρεται;" lead="Ο αγαπημένος του μέλους είναι προεπιλεγμένος." />
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
        {COFFEES.map((c) => (
          <button key={c.code} type="button" onClick={() => setCoffee(c.code)} aria-pressed={chosen === c.code} className={cx("rounded-2xl border p-5 text-left transition", chosen === c.code ? "border-bronze-dark bg-espresso text-cream" : "border-espresso/12 bg-surface hover:border-espresso/30")}>
            <Icon name="coffee" size={24} className={chosen === c.code ? "text-[#d9c9a0]" : "text-bronze-dark"} />
            <span className="font-literata mt-3 block text-[18px] font-semibold">{c.label}</span>
            <span className={cx("block text-[12.5px]", chosen === c.code ? "text-cream/65" : "text-espresso/50")}>{c.note}{c.code === member.favourite ? " · αγαπημένος" : ""}</span>
          </button>
        ))}
      </div>
      <div className="mt-6 flex flex-wrap gap-3">
        <PrimaryButton className="min-w-[220px] py-4 text-[17px]" onClick={() => go("confirm")}>Συνέχεια</PrimaryButton>
        <GhostButton onClick={() => go("member")}>Πίσω</GhostButton>
      </div>
    </div>
  );
}

function Confirm({ member, coffee, go }: { member: PreviewMember; coffee: CoffeeCode; go: (s: StaffScreen) => void }) {
  const [done, setDone] = useState(false);
  if (done) {
    return (
      <div className="mx-auto max-w-[520px] text-center" data-evc-preview="not-recorded">
        <span className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[#e3eedf] text-[#2f5a2a]"><Icon name="check" size={30} /></span>
        <h1 className="font-literata mt-4 text-[26px] font-semibold">Ολοκληρώθηκε (προσομοίωση)</h1>
        <p className="mt-2 text-[15px] text-espresso/65">{coffeeName(coffee)} για {member.name.split(" ")[0]}.</p>
        <p className="mx-auto mt-4 max-w-[42ch] rounded-xl border-2 border-maroon/40 bg-[#f4e2e3] px-4 py-3 text-[13.5px] font-semibold text-maroon">ΔΕΙΓΜΑ — καμία εξαργύρωση δεν καταχωρήθηκε. Αυτή η οθόνη δείχνει μόνο πώς θα φαίνεται.</p>
        <PrimaryButton className="mt-6" onClick={() => go("dashboard")}>Επόμενος πελάτης</PrimaryButton>
      </div>
    );
  }
  return (
    <div className="mx-auto max-w-[560px]">
      <ScreenTitle eyebrow="Επιβεβαίωση" title="Επιβεβαιώστε τον δωρεάν καφέ" />
      <Card className="p-6">
        <div className="flex items-center gap-4">
          <Avatar initials={member.initials} size={52} />
          <div className="flex-1">
            <p className="text-[16px] font-semibold">{member.name}</p>
            <p className="text-[13px] text-espresso/55">1 δωρεάν καφές · σήμερα</p>
          </div>
        </div>
        <div className="mt-5 flex items-center justify-between rounded-xl bg-cream px-4 py-3.5">
          <span className="text-[14px] text-espresso/60">Καφές</span>
          <span className="font-literata text-[19px] font-semibold">{coffeeName(coffee)}</span>
        </div>
      </Card>
      <div className="mt-5 grid grid-cols-2 gap-3">
        <GhostButton onClick={() => go("coffee")}>Αλλαγή</GhostButton>
        <PrimaryButton onClick={() => setDone(true)}>Επιβεβαίωση</PrimaryButton>
      </div>
      <PreviewOnly>Η «Επιβεβαίωση» δείχνει μόνο την επόμενη οθόνη. Δεν καλείται κανένα API και δεν καταχωρείται εξαργύρωση.</PreviewOnly>
    </div>
  );
}

function Blocked({ today, openMember }: { today: string; openMember: (id: string) => void }) {
  const redeemed = MEMBERS.find((m) => m.state === "active" && m.redeemedToday)!;
  const expired = MEMBERS.find((m) => m.state === "expired")!;
  const served = todaysRedemptions(today).find((r) => r.memberId === redeemed.id);
  return (
    <div>
      <ScreenTitle eyebrow="Μη διαθέσιμος καφές" title="Όταν το μέλος δεν δικαιούται" />
      <div className="grid gap-5 md:grid-cols-2">
        <Card className="p-6">
          <div className="flex items-center gap-3"><Avatar initials={redeemed.initials} /><p className="text-[16px] font-semibold">{redeemed.name}</p></div>
          <div className="mt-4"><EligibilityPanel m={redeemed} /></div>
          <p className="mt-3 text-[13.5px] text-espresso/60">Σήμερα: {coffeeName(served?.coffee ?? redeemed.favourite)} στις {served?.time ?? "—"}.</p>
          <GhostButton className="mt-4 w-full" onClick={() => openMember(redeemed.id)}>Άνοιγμα μέλους</GhostButton>
        </Card>
        <Card className="p-6">
          <div className="flex items-center gap-3"><Avatar initials={expired.initials} /><p className="text-[16px] font-semibold">{expired.name}</p></div>
          <div className="mt-4"><EligibilityPanel m={expired} /></div>
          <p className="mt-3 text-[13.5px] text-espresso/60">Έληξε στις {greekDate(addDays(today, expired.paidUntilOffsetDays))}.</p>
          <GhostButton className="mt-4 w-full" onClick={() => openMember(expired.id)}>Άνοιγμα μέλους</GhostButton>
        </Card>
      </div>
    </div>
  );
}

function MemberHistory({ member, today }: { member: PreviewMember; today: string }) {
  const rows = memberHistory(member.id, today).slice(0, 14);
  return (
    <div>
      <ScreenTitle eyebrow={member.name} title="Ιστορικό καφέδων" />
      <Card className="p-0">
        <div className="grid grid-cols-[1.4fr_1fr_0.6fr] border-b border-espresso/10 px-5 py-3 text-[12px] font-semibold text-espresso/55">
          <span>Ημερομηνία</span><span>Καφές</span><span className="text-right">Ώρα</span>
        </div>
        {rows.length === 0 && <p className="px-5 py-6 text-[14px] text-espresso/55">Δεν υπάρχουν καφέδες για αυτό το μέλος.</p>}
        {rows.map((r) => (
          <div key={`${r.date}-${r.time}`} className="grid grid-cols-[1.4fr_1fr_0.6fr] border-b border-espresso/8 px-5 py-3 text-[14px] last:border-0">
            <span>{greekDate(r.date, true)}</span><span className="font-medium">{coffeeName(r.coffee)}</span><span className="text-right text-espresso/55 tabular-nums">{r.time}</span>
          </div>
        ))}
      </Card>
      <p className="mt-3 text-[12px] text-espresso/45">Δείγμα ιστορικού με φανταστικά δεδομένα.</p>
    </div>
  );
}
