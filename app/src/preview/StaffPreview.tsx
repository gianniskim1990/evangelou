import { useState, type ReactNode } from "react";
import { COFFEES, MEMBERS, addDays, coffeeName, greekDate, memberById, memberHistory, todaysRedemptions, type CoffeeCode, type PreviewMember } from "./previewData";
import { cx, type StaffScreen } from "./screens";
import { StaffCounter, StaffFrame } from "./StaffSignature";
import { Btn, DefRows, Glyph, PreviewNote, StateMark } from "./ds";

/*
 * UI-02 design preview, Staff counter flow (tablet-first, visual only). The
 * REAL staff app (/club-admin/, WordPress) is separate, disabled and untouched.
 * The camera is a placeholder and confirmation never records anything.
 */

type Eligibility = "eligible" | "redeemed_today" | "expired" | "pending";

function eligibility(m: PreviewMember): Eligibility {
  if (m.state === "expired") return "expired";
  if (m.state === "pending") return "pending";
  return m.redeemedToday ? "redeemed_today" : "eligible";
}

type Tone = "ok" | "stop" | "wait";

const VERDICT: Record<Eligibility, { tone: Tone; title: string; body: string; short: string }> = {
  eligible: { tone: "ok", title: "Δικαιούται καφέ", body: "Η συνδρομή είναι ενεργή και δεν έχει δοθεί καφές σήμερα.", short: "Δικαιούται" },
  redeemed_today: { tone: "stop", title: "Όχι καφές σήμερα", body: "Ο καφές της ημέρας έχει ήδη δοθεί. Νέο δικαίωμα από τα μεσάνυχτα, ώρα Ελλάδας.", short: "Ήδη σήμερα" },
  expired: { tone: "stop", title: "Η συνδρομή έληξε", body: "Δεν δικαιούται δωρεάν καφέ. Το μέλος μπορεί να ανανεώσει από τον λογαριασμό του.", short: "Έληξε" },
  pending: { tone: "wait", title: "Πληρωμή σε αναμονή", body: "Η συνδρομή δεν έχει ενεργοποιηθεί ακόμη. Δεν δικαιούται δωρεάν καφέ.", short: "Αναμονή" },
};

const TONE_BG: Record<Tone, string> = { ok: "bg-[var(--ok-tint)]", stop: "bg-[var(--stop-tint)]", wait: "bg-[var(--wait-tint)]" };
const TONE_FG: Record<Tone, string> = { ok: "text-[var(--ok)]", stop: "text-[var(--stop)]", wait: "text-[var(--wait)]" };
const TONE_SOLID: Record<Tone, string> = { ok: "bg-[var(--ok)]", stop: "bg-[var(--stop)]", wait: "bg-[var(--wait)]" };

export function StaffPreview({ screen, go, today }: { screen: StaffScreen; go: (s: StaffScreen) => void; today: string }) {
  const [memberId, setMemberId] = useState("m01");
  const [coffee, setCoffee] = useState<CoffeeCode | null>(null);
  const member = memberById(memberId);
  const openMember = (id: string) => {
    setMemberId(id);
    setCoffee(null);
    go("member");
  };
  if (screen === "dashboard") return <StaffCounter go={go} today={today} openMember={openMember} />;
  return (
    <StaffFrame today={today}>
      {screen === "scan" && <Scan go={go} openMember={openMember} />}
      {screen === "search" && <Search go={go} openMember={openMember} />}
      {screen === "member" && <MemberCheck member={member} today={today} go={go} openMember={openMember} />}
      {screen === "coffee" && <CoffeeSelect member={member} coffee={coffee} setCoffee={setCoffee} go={go} />}
      {screen === "confirm" && <Confirm member={member} coffee={coffee ?? member.favourite} today={today} go={go} />}
      {screen === "blocked" && <Blocked today={today} go={go} openMember={openMember} />}
      {screen === "history" && <MemberHistory member={member} today={today} go={go} />}
    </StaffFrame>
  );
}

// ---- Shared pieces -------------------------------------------------------------------------

function FlowBar({ back, onBack, title }: { back: string; onBack: () => void; title: string }) {
  return (
    <div className="border-b border-[var(--rule)]">
      <div className="mx-auto flex max-w-[1280px] items-center gap-4 px-3 py-2 md:px-6">
        <button type="button" onClick={onBack} className="press t-label flex min-h-12 items-center gap-1 rounded-full pr-4 pl-2 text-[var(--ink)] hover:bg-[rgba(139,118,80,0.1)]">
          <Glyph name="back" size={22} />
          {back}
        </button>
        <span className="t-small truncate text-[var(--ink-2)]">{title}</span>
      </div>
    </div>
  );
}

function Page({ children, className }: { children: ReactNode; className?: string }) {
  return <main className={cx("mx-auto max-w-[1280px] px-5 py-6 md:px-8 md:py-8", className)}>{children}</main>;
}

function Verdict({ e, detail, large = false }: { e: Eligibility; detail?: string; large?: boolean }) {
  const v = VERDICT[e];
  return (
    <div role="status" className={cx("flex items-center gap-5 rounded-[18px]", TONE_BG[v.tone], large ? "p-6 md:p-8" : "p-5")}>
      <span className={cx("flex flex-none items-center justify-center rounded-full text-white", TONE_SOLID[v.tone], large ? "h-16 w-16 md:h-20 md:w-20" : "h-12 w-12")}>
        <Glyph name={v.tone === "ok" ? "check" : v.tone === "wait" ? "clock" : "stop"} size={large ? 34 : 24} />
      </span>
      <span className="min-w-0">
        <span className={cx("block", large ? "t-headline" : "t-title", TONE_FG[v.tone])}>{v.title}</span>
        <span className="t-body mt-1 block text-[var(--ink)]">{detail ?? v.body}</span>
      </span>
    </div>
  );
}

function Signal({ e }: { e: Eligibility }) {
  const v = VERDICT[e];
  return (
    <span className={cx("t-label inline-flex items-center gap-1.5 whitespace-nowrap", TONE_FG[v.tone])}>
      <Glyph name={v.tone === "ok" ? "check" : v.tone === "wait" ? "clock" : "stop"} size={18} />
      {v.short}
    </span>
  );
}

function servedToday(m: PreviewMember, today: string) {
  return todaysRedemptions(today).find((r) => r.memberId === m.id);
}

// ---- Screens --------------------------------------------------------------------------------

function Scan({ go, openMember }: { go: (s: StaffScreen) => void; openMember: (id: string) => void }) {
  return (
    <>
      <FlowBar back="Ταμείο" onBack={() => go("dashboard")} title="Σάρωση κάρτας μέλους" />
      <Page className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)] lg:gap-10">
        <div className="on-dark relative flex min-h-[380px] flex-col items-center justify-center overflow-hidden rounded-[18px] bg-[var(--roast)] p-6 text-[var(--milk)] md:min-h-[480px] lg:min-h-[560px]">
          <span className="relative block h-52 w-52 md:h-64 md:w-64" aria-hidden="true">
            {["top-0 left-0 border-t-2 border-l-2 rounded-tl-[12px]", "top-0 right-0 border-t-2 border-r-2 rounded-tr-[12px]", "bottom-0 left-0 border-b-2 border-l-2 rounded-bl-[12px]", "right-0 bottom-0 border-r-2 border-b-2 rounded-br-[12px]"].map((c) => (
              <span key={c} className={cx("absolute h-12 w-12 border-[var(--brass)]", c)} />
            ))}
            <span className="absolute inset-x-6 top-1/2 h-px bg-[var(--brass)]/60" />
          </span>
          <p className="t-title mt-8 text-center">Κρατήστε το QR μέσα στο πλαίσιο</p>
          <p className="t-small mt-2 text-center text-[var(--milk)]/60">Η κάμερα δεν ενεργοποιείται στην προεπισκόπηση.</p>
        </div>
        <section>
          <h1 className="t-headline text-[var(--ink)]">Προσομοίωση σάρωσης</h1>
          <p className="t-body mt-2 max-w-[40ch] text-[var(--ink-2)]">Διαλέξτε ποια κάρτα «σαρώνεται» για να δείτε το επόμενο βήμα.</p>
          <div className="mt-6 grid gap-3">
            {[
              { id: "m01", label: "Ενεργή κάρτα", e: "eligible" as const },
              { id: "m02", label: "Καφές ήδη σήμερα", e: "redeemed_today" as const },
              { id: "m03", label: "Ληγμένη κάρτα", e: "expired" as const },
            ].map((s) => (
              <button key={s.id} type="button" onClick={() => openMember(s.id)} className="press grid min-h-16 grid-cols-[minmax(0,1fr)_auto] items-center gap-4 rounded-[14px] border border-[var(--rule-strong)] bg-[var(--paper)] px-5 text-left hover:border-[var(--ink)]">
                <span className="t-title text-[var(--ink)]">{s.label}</span>
                <Signal e={s.e} />
              </button>
            ))}
          </div>
          <div className="mt-8 border-t border-[var(--rule-strong)] pt-6">
            <p className="t-body text-[var(--ink)]">Ο πελάτης δεν έχει την κάρτα;</p>
            <Btn tone="line" onClick={() => go("search")} className="mt-3 min-h-14 px-7">Αναζήτηση με τηλέφωνο</Btn>
          </div>
          <PreviewNote className="mt-8">Καμία κάμερα, κανένας κωδικός και καμία σύνδεση με το σύστημα: μόνο δείγμα ροής.</PreviewNote>
        </section>
      </Page>
    </>
  );
}

function Search({ go, openMember }: { go: (s: StaffScreen) => void; openMember: (id: string) => void }) {
  const [q, setQ] = useState("69");
  const press = (k: string) => setQ((v) => (k === "del" ? v.slice(0, -1) : k === "clear" ? "" : (v + k).slice(0, 10)));
  const digits = q.replace(/\D/g, "");
  // Phones are masked to the last two digits, so the sample matches on those.
  const rows = digits.length >= 4 ? MEMBERS.filter((m) => m.phoneMasked.replace(/\D/g, "").slice(-2) === digits.slice(-2)) : MEMBERS.slice(0, 6);
  const pretty = digits.replace(/^(\d{0,4})(\d{0,3})(\d{0,3}).*$/, (_, a, b, c) => [a, b, c].filter(Boolean).join(" "));
  const keys = ["1", "2", "3", "4", "5", "6", "7", "8", "9", "clear", "0", "del"];
  return (
    <>
      <FlowBar back="Ταμείο" onBack={() => go("dashboard")} title="Αναζήτηση με κινητό τηλέφωνο" />
      <Page className="grid grid-cols-[minmax(0,1fr)] gap-8 md:grid-cols-[minmax(0,340px)_minmax(0,1fr)] lg:grid-cols-[minmax(0,400px)_minmax(0,1fr)] lg:gap-12">
        <section>
          <p className="t-label text-[var(--ink-2)]" id="evc-phone-label">Κινητό τηλέφωνο</p>
          <output aria-labelledby="evc-phone-label" className="t-num mt-2 flex min-h-18 items-center rounded-[14px] border border-[var(--rule-strong)] bg-[var(--paper)] px-5 text-[30px] font-medium tracking-[0.04em] text-[var(--ink)]">
            {pretty || <span className="text-[20px] text-[var(--ink-3)]">69…</span>}
          </output>
          <div className="mt-4 grid grid-cols-3 gap-2.5">
            {keys.map((k) => (
              <button
                key={k}
                type="button"
                onClick={() => press(k)}
                aria-label={k === "del" ? "Διαγραφή ψηφίου" : k === "clear" ? "Καθαρισμός" : undefined}
                className={cx("press t-num min-h-16 rounded-[12px] text-[22px] font-medium", k === "del" || k === "clear" ? "text-[16px] text-[var(--ink-2)] hover:bg-[rgba(139,118,80,0.1)]" : "border border-[var(--rule-strong)] bg-[var(--paper)] text-[var(--ink)] hover:border-[var(--ink)]")}
              >
                {k === "del" ? "⌫" : k === "clear" ? "Καθαρ." : k}
              </button>
            ))}
          </div>
        </section>
        <section>
          <div className="flex items-baseline justify-between gap-4 border-b border-[var(--rule-strong)] pb-3">
            <h1 className="t-title text-[var(--ink)]">Αποτελέσματα</h1>
            <span className="t-small t-num whitespace-nowrap text-[var(--ink-2)]">{rows.length} μέλη</span>
          </div>
          {digits.length < 4 && <p className="t-small mt-3 text-[var(--ink-2)]">Πληκτρολογήστε το κινητό. Από 4 ψηφία και μετά τα αποτελέσματα φιλτράρονται.</p>}
          <ul className="m-0 list-none p-0">
            {rows.map((m) => (
              <li key={m.id} className="border-b border-[var(--rule)]">
                <button type="button" onClick={() => openMember(m.id)} className="grid min-h-18 w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-4 px-1 py-3 text-left hover:bg-[rgba(139,118,80,0.06)]">
                  <span className="min-w-0">
                    <span className="block truncate text-[18px] leading-snug font-semibold text-[var(--ink)]">{m.name}</span>
                    <span className="t-small t-num block text-[var(--ink-2)]">{m.phoneMasked}</span>
                  </span>
                  <Signal e={eligibility(m)} />
                </button>
              </li>
            ))}
          </ul>
          {rows.length === 0 && (
            <div className="py-10">
              <p className="t-title text-[var(--ink)]">Κανένα μέλος με αυτό το τηλέφωνο</p>
              <p className="t-body mt-2 text-[var(--ink-2)]">Ελέγξτε τα ψηφία ή ζητήστε από τον πελάτη να ανοίξει την κάρτα του.</p>
            </div>
          )}
          <PreviewNote className="mt-6">Τα αποτελέσματα είναι δείγματα· τα τηλέφωνα εμφανίζονται μασκαρισμένα και η αναζήτηση δεν συνδέεται με καμία βάση.</PreviewNote>
        </section>
      </Page>
    </>
  );
}

function MemberCheck({ member, today, go, openMember }: { member: PreviewMember; today: string; go: (s: StaffScreen) => void; openMember: (id: string) => void }) {
  const e = eligibility(member);
  const served = servedToday(member, today);
  const samples: { id: string; e: Eligibility }[] = [
    { id: "m01", e: "eligible" },
    { id: "m02", e: "redeemed_today" },
    { id: "m03", e: "expired" },
    { id: "m04", e: "pending" },
  ];
  const detail = e === "redeemed_today" && served ? `Δόθηκε ${coffeeName(served.coffee)} στις ${served.time}. Νέο δικαίωμα από τα μεσάνυχτα, ώρα Ελλάδας.` : undefined;
  return (
    <>
      <FlowBar back="Ταμείο" onBack={() => go("dashboard")} title="Έλεγχος δικαιώματος" />
      <Page>
        <div role="group" aria-label="Δείγμα μέλους" className="mb-6 flex flex-wrap items-center gap-2">
          <span className="t-small mr-1 text-[var(--ink-3)]">Δείγμα μέλους</span>
          {samples.map((s) => (
            <button key={s.id} type="button" aria-pressed={member.id === s.id} onClick={() => openMember(s.id)} className={cx("press t-label min-h-10 rounded-full px-4", member.id === s.id ? "bg-[var(--roast)] text-[var(--milk)]" : "text-[var(--ink-2)] hover:bg-[rgba(139,118,80,0.1)]")}>
              {VERDICT[s.e].short}
            </button>
          ))}
        </div>
        <Verdict e={e} detail={detail} large />
        <div className="mt-8 grid grid-cols-[minmax(0,1fr)] gap-x-12 gap-y-8 md:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
          <section>
            <h1 className="t-headline text-[var(--ink)]">{member.name}</h1>
            <p className="t-body t-num mt-1 text-[var(--ink-2)]">{member.phoneMasked}</p>
            <DefRows
              className="mt-5"
              rows={[
                ["Συνδρομή", <StateMark key="s" state={member.state} />],
                ["Ισχύει έως", member.state === "pending" ? "Δεν έχει ενεργοποιηθεί" : greekDate(addDays(today, member.paidUntilOffsetDays))],
                ["Συνήθως πίνει", coffeeName(member.favourite)],
                ["Μέλος από", greekDate(member.memberSince)],
              ]}
            />
          </section>
          <section className="flex flex-col gap-3 md:pt-2">
            {e === "eligible" ? (
              <button type="button" onClick={() => go("coffee")} className="press min-h-18 rounded-[14px] bg-[var(--roast)] px-6 text-[19px] font-semibold text-[var(--milk)] hover:bg-[var(--roast-3)]">Επιλογή καφέ</button>
            ) : (
              <button type="button" onClick={() => go("dashboard")} className="press min-h-18 rounded-[14px] bg-[var(--roast)] px-6 text-[19px] font-semibold text-[var(--milk)] hover:bg-[var(--roast-3)]">Επόμενος πελάτης</button>
            )}
            <Btn tone="line" onClick={() => go("history")} className="min-h-14">Ιστορικό καφέδων</Btn>
          </section>
        </div>
      </Page>
    </>
  );
}

function CoffeeSelect({ member, coffee, setCoffee, go }: { member: PreviewMember; coffee: CoffeeCode | null; setCoffee: (c: CoffeeCode) => void; go: (s: StaffScreen) => void }) {
  const chosen = coffee ?? member.favourite;
  return (
    <>
      <FlowBar back="Μέλος" onBack={() => go("member")} title={member.name} />
      <Page className="pb-32">
        <h1 className="t-headline text-[var(--ink)]">Ποιος καφές σερβίρεται;</h1>
        <p className="t-body mt-2 text-[var(--ink-2)]">Ο καφές που πίνει συνήθως το μέλος είναι ήδη επιλεγμένος. Ενδεικτικός κατάλογος, δεν έχει εγκριθεί ακόμη.</p>
        <div role="radiogroup" aria-label="Καφές" className="mt-6 grid grid-cols-2 gap-3 md:grid-cols-3">
          {COFFEES.map((c) => {
            const on = chosen === c.code;
            return (
              <button key={c.code} type="button" role="radio" aria-checked={on} onClick={() => setCoffee(c.code)} className={cx("press flex min-h-28 flex-col justify-between rounded-[14px] border p-4 text-left md:p-5", on ? "on-dark border-[var(--roast)] bg-[var(--roast)] text-[var(--milk)]" : "border-[var(--rule-strong)] bg-[var(--paper)] text-[var(--ink)] hover:border-[var(--ink)]")}>
                <span className="flex items-start justify-between gap-2">
                  <span className="t-title">{c.label}</span>
                  {on && <Glyph name="check" size={22} className="flex-none text-[var(--brass)]" />}
                </span>
                <span className={cx("t-small", on ? "text-[var(--milk)]/65" : "text-[var(--ink-2)]")}>
                  {c.note}
                  {c.code === member.favourite ? ", συνήθως αυτόν" : ""}
                </span>
              </button>
            );
          })}
        </div>
      </Page>
      <div className="sticky bottom-0 z-10 border-t border-[var(--rule)] bg-[var(--paper)]">
        <div className="mx-auto flex max-w-[1280px] items-center justify-between gap-4 px-5 py-3 md:px-8">
          <p className="t-body min-w-0 truncate text-[var(--ink)]">
            <span className="text-[var(--ink-2)]">Επιλογή: </span>
            <strong className="font-semibold">{coffeeName(chosen)}</strong>
          </p>
          <button type="button" onClick={() => go("confirm")} className="press min-h-14 flex-none rounded-[12px] bg-[var(--roast)] px-8 text-[17px] font-semibold text-[var(--milk)] hover:bg-[var(--roast-3)]">Συνέχεια</button>
        </div>
      </div>
    </>
  );
}

function Confirm({ member, coffee, today, go }: { member: PreviewMember; coffee: CoffeeCode; today: string; go: (s: StaffScreen) => void }) {
  const [done, setDone] = useState(false);
  if (done) {
    return (
      <Page className="max-w-[640px] py-12 text-center md:py-16">
        <div data-evc-preview="not-recorded">
          <span className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-[var(--ok)] text-white">
            <Glyph name="check" size={38} />
          </span>
          <h1 className="t-headline mt-6 text-[var(--ink)]">Ο καφές δόθηκε (προσομοίωση)</h1>
          <p className="t-lede mt-2 text-[var(--ink-2)]">
            {coffeeName(coffee)} για {member.name.split(" ")[0]}.
          </p>
          <p role="note" className="t-body mx-auto mt-6 max-w-[44ch] rounded-[12px] border-2 border-[var(--stop)] bg-[var(--stop-tint)] px-5 py-4 font-semibold text-[var(--stop)]">
            ΔΕΙΓΜΑ: καμία εξαργύρωση δεν καταχωρήθηκε. Αυτή η οθόνη δείχνει μόνο πώς θα φαίνεται.
          </p>
          <button type="button" onClick={() => go("dashboard")} className="press mt-8 min-h-16 rounded-[14px] bg-[var(--roast)] px-10 text-[18px] font-semibold text-[var(--milk)] hover:bg-[var(--roast-3)]">Επόμενος πελάτης</button>
        </div>
      </Page>
    );
  }
  return (
    <>
      <FlowBar back="Καφές" onBack={() => go("coffee")} title="Επιβεβαίωση δωρεάν καφέ" />
      <Page className="max-w-[720px]">
        <h1 className="t-headline text-[var(--ink)]">Επιβεβαιώστε τον καφέ</h1>
        <div className="mt-6 rounded-[18px] border border-[var(--rule-strong)] bg-[var(--paper)] px-6 py-2">
          <DefRows
            rows={[
              ["Μέλος", member.name],
              ["Καφές", <span key="c" className="t-title">{coffeeName(coffee)}</span>],
              ["Ημέρα", `${greekDate(today, true)}, ο 1 καφές της ημέρας`],
            ]}
          />
        </div>
        <div className="mt-6 grid grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)] gap-3">
          <button type="button" onClick={() => go("coffee")} className="press min-h-16 rounded-[14px] border border-[var(--rule-strong)] text-[17px] font-semibold text-[var(--ink)] hover:border-[var(--ink)]">Αλλαγή</button>
          <button type="button" onClick={() => setDone(true)} className="press min-h-16 rounded-[14px] bg-[var(--roast)] text-[18px] font-semibold text-[var(--milk)] hover:bg-[var(--roast-3)]">Επιβεβαίωση</button>
        </div>
        <PreviewNote className="mt-6">Η «Επιβεβαίωση» δείχνει μόνο την επόμενη οθόνη. Δεν καλείται κανένα API και δεν καταχωρείται εξαργύρωση.</PreviewNote>
      </Page>
    </>
  );
}

function Blocked({ today, go, openMember }: { today: string; go: (s: StaffScreen) => void; openMember: (id: string) => void }) {
  const redeemed = MEMBERS.find((m) => m.state === "active" && m.redeemedToday)!;
  const expired = MEMBERS.find((m) => m.state === "expired")!;
  const pending = MEMBERS.find((m) => m.state === "pending")!;
  const served = servedToday(redeemed, today);
  const cases: { m: PreviewMember; e: Eligibility; detail: string }[] = [
    { m: redeemed, e: "redeemed_today", detail: `Σήμερα: ${coffeeName(served?.coffee ?? redeemed.favourite)} στις ${served?.time ?? "—"}. Νέο δικαίωμα από τα μεσάνυχτα.` },
    { m: expired, e: "expired", detail: `Έληξε στις ${greekDate(addDays(today, expired.paidUntilOffsetDays))}. Ανανέωση από τον λογαριασμό του μέλους.` },
    { m: pending, e: "pending", detail: "Η πληρωμή δεν έχει επιβεβαιωθεί. Ο καφές ξεκινά μόλις ενεργοποιηθεί η συνδρομή." },
  ];
  return (
    <>
      <FlowBar back="Ταμείο" onBack={() => go("dashboard")} title="Όταν δεν δίνεται καφές" />
      <Page>
        <h1 className="t-headline text-[var(--ink)]">Όταν δεν δίνεται καφές</h1>
        <p className="t-body mt-2 max-w-[60ch] text-[var(--ink-2)]">Οι τρεις λόγοι άρνησης, όπως εμφανίζονται στο ταμείο. Το χρώμα δεν είναι ποτέ η μόνη ένδειξη: κάθε περίπτωση έχει εικονίδιο και κείμενο.</p>
        <div className="mt-8 grid grid-cols-[minmax(0,1fr)] gap-x-6 lg:grid-cols-3">
          {cases.map((c) => (
            <section key={c.e} className="row-span-2 mb-6 grid grid-rows-subgrid lg:mb-0">
              <Verdict e={c.e} detail={c.detail} />
              <div className="flex items-center justify-between gap-4 border-b border-[var(--rule)] py-4">
                <span className="min-w-0">
                  <span className="t-title block truncate text-[var(--ink)]">{c.m.name}</span>
                  <span className="t-small t-num block text-[var(--ink-2)]">{c.m.phoneMasked}</span>
                </span>
                <Btn tone="line" onClick={() => openMember(c.m.id)} className="min-h-12 flex-none px-5">Άνοιγμα</Btn>
              </div>
            </section>
          ))}
        </div>
      </Page>
    </>
  );
}

function MemberHistory({ member, today, go }: { member: PreviewMember; today: string; go: (s: StaffScreen) => void }) {
  const rows = memberHistory(member.id, today);
  const todayRow = servedToday(member, today);
  return (
    <>
      <FlowBar back="Μέλος" onBack={() => go("member")} title="Ιστορικό καφέδων" />
      <Page className="max-w-[880px]">
        <div className="flex flex-wrap items-end justify-between gap-x-8 gap-y-3">
          <div>
            <h1 className="t-headline text-[var(--ink)]">{member.name}</h1>
            <p className="t-body mt-1 text-[var(--ink-2)]">
              <StateMark state={member.state} /> <span className="t-num ml-3">{rows.length} καφέδες τις τελευταίες 3 εβδομάδες</span>
            </p>
          </div>
        </div>
        {todayRow && <p className="t-body mt-6 rounded-[12px] bg-[var(--stop-tint)] px-4 py-3 text-[var(--stop)]">Σήμερα: {coffeeName(todayRow.coffee)} στις {todayRow.time}. Ο καφές της ημέρας έχει δοθεί.</p>}
        <table className="ledger mt-6 w-full border-collapse">
          <thead>
            <tr>
              <th>Ημερομηνία</th>
              <th>Καφές</th>
              <th className="text-right!">Ώρα</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.date}>
                <td className="t-body pr-4 text-[var(--ink)]">{greekDate(r.date, true)}</td>
                <td className="t-body pr-4 font-medium text-[var(--ink)]">{coffeeName(r.coffee)}</td>
                <td className="t-small t-num text-right text-[var(--ink-2)]">{r.time}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {rows.length === 0 && (
          <div className="border-b border-[var(--rule)] py-10">
            <p className="t-title text-[var(--ink)]">Δεν υπάρχουν καφέδες</p>
            <p className="t-body mt-2 text-[var(--ink-2)]">Το μέλος δεν έχει πάρει ακόμη καφέ Club.</p>
          </div>
        )}
        <PreviewNote className="mt-6">Δείγμα ιστορικού με φανταστικά δεδομένα.</PreviewNote>
      </Page>
    </>
  );
}
