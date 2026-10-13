import { useState, type ReactNode } from "react";
import { coffeeName, greekDate, memberById, todaysRedemptions } from "./previewData";
import { cx, type StaffScreen } from "./screens";
import { Glyph, Logo, PreviewNote } from "./ds";

/*
 * UI-01.R1 signature screen, Staff counter (tablet-first). Visual only: the
 * REAL staff app (/club-admin/, WordPress) is separate, disabled and untouched.
 */

type Check = { time: string; memberId: string; ok: boolean; detail: string };

/** Today's counter log: the synthetic redemptions plus two refused checks. */
function todaysChecks(today: string): Check[] {
  const served = todaysRedemptions(today);
  const first = served[0];
  const checks: Check[] = served.map((r) => ({ time: r.time, memberId: r.memberId, ok: true, detail: coffeeName(r.coffee) }));
  checks.push({ time: "09:48", memberId: "m03", ok: false, detail: "Η συνδρομή έληξε" });
  if (first) checks.push({ time: "10:31", memberId: first.memberId, ok: false, detail: `Ο καφές δόθηκε ήδη στις ${first.time}` });
  return checks.sort((a, b) => b.time.localeCompare(a.time));
}

/** The counter's app chrome, shared by every staff screen. */
export function StaffFrame({ today, children }: { today: string; children: ReactNode }) {
  return (
    <div className="min-h-[calc(100svh-52px)] bg-[var(--milk)]">
      <header className="border-b border-[var(--rule)] bg-[var(--paper)]">
        <div className="mx-auto flex max-w-[1280px] items-center justify-between gap-4 px-5 py-3.5 md:px-8">
          <div className="flex items-center gap-4">
            <Logo className="h-7" />
            <span className="t-label hidden border-l border-[var(--rule-strong)] pl-4 text-[var(--ink)] sm:inline">Ταμείο 1</span>
          </div>
          <div className="t-label flex items-center gap-4 text-[var(--ink-2)]">
            <span className="hidden md:inline">{greekDate(today, true)}</span>
            <span className="rounded-full border border-[var(--rule-strong)] px-3 py-1.5 text-[var(--ink)]">Barista (δείγμα)</span>
          </div>
        </div>
      </header>
      {children}
    </div>
  );
}

export function StaffCounter({ today, go, openMember }: { today: string; go: (s: StaffScreen) => void; openMember: (id: string) => void }) {
  const [phone, setPhone] = useState("");
  const checks = todaysChecks(today);
  const last = checks[0];
  const lastMember = memberById(last.memberId);
  const served = checks.filter((c) => c.ok).length;

  return (
    <StaffFrame today={today}>

      <main className="mx-auto grid max-w-[1280px] grid-cols-[minmax(0,1fr)] gap-6 px-5 py-6 md:px-8 md:py-8 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)] lg:gap-8">
        <div className="flex flex-col gap-6">
          <button
            type="button"
            onClick={() => go("scan")}
            className="press on-dark group relative flex min-h-[340px] flex-col justify-between overflow-hidden rounded-[18px] bg-[var(--roast)] p-7 text-left text-[var(--milk)] md:min-h-[380px] md:p-9 lg:min-h-[480px]"
          >
            <span className="flex items-start justify-between gap-6">
              <span>
                <span className="t-headline block">Σάρωση κάρτας</span>
                <span className="t-body mt-2 block max-w-[30ch] text-[var(--milk)]/70">Ο πελάτης δείχνει το QR της κάρτας του στην κάμερα.</span>
              </span>
            </span>
            <span className="relative mx-auto my-8 block h-40 w-40 md:h-48 md:w-48" aria-hidden="true">
              {["top-0 left-0 border-t-2 border-l-2 rounded-tl-[10px]", "top-0 right-0 border-t-2 border-r-2 rounded-tr-[10px]", "bottom-0 left-0 border-b-2 border-l-2 rounded-bl-[10px]", "right-0 bottom-0 border-r-2 border-b-2 rounded-br-[10px]"].map((c) => (
                <span key={c} className={cx("absolute h-10 w-10 border-[var(--brass)]", c)} />
              ))}
              <span className="absolute inset-x-5 top-1/2 h-px bg-[var(--brass)]/60" />
            </span>
            <span className="flex items-center justify-end gap-4">
              <span className="inline-flex min-h-14 items-center rounded-full bg-[var(--brass)] px-7 text-[17px] font-semibold text-[var(--roast)] group-hover:bg-[#d6c291]">Άνοιγμα κάμερας</span>
            </span>
          </button>

          <form
            className="rounded-[18px] border border-[var(--rule-strong)] bg-[var(--paper)] p-5 md:p-6"
            onSubmit={(e) => {
              e.preventDefault();
              go("search");
            }}
          >
            <label htmlFor="evc-staff-phone" className="t-title block text-[var(--ink)]">Χωρίς κάρτα; Αναζήτηση με τηλέφωνο</label>
            <div className="mt-4 flex flex-col gap-3 sm:flex-row">
              <span className="flex min-h-14 min-w-0 flex-1 items-center gap-3 rounded-[12px] border border-[var(--rule-strong)] bg-[var(--milk)] px-4 focus-within:border-[var(--ink)]">
                <Glyph name="phone" className="flex-none text-[var(--ink-3)]" />
                <input
                  id="evc-staff-phone"
                  inputMode="numeric"
                  autoComplete="off"
                  value={phone}
                  onChange={(e) => setPhone(e.target.value.replace(/[^\d ]/g, "").slice(0, 12))}
                  placeholder="π.χ. 6912 345 678 ή 678"
                  className="t-num w-full min-w-0 bg-transparent text-[18px] text-[var(--ink)] outline-none placeholder:text-[15px] placeholder:text-[var(--ink-3)]"
                />
              </span>
              <button type="submit" className="press min-h-14 flex-none rounded-[12px] bg-[var(--roast)] px-6 text-[16px] font-semibold text-[var(--milk)] hover:bg-[var(--roast-3)]">
                Αναζήτηση
              </button>
            </div>
          </form>
        </div>

        <section aria-labelledby="evc-today-heading" className="flex flex-col">
          <h2 className="sr-only">Τελευταίος έλεγχος</h2>
          <button
            type="button"
            onClick={() => openMember(last.memberId)}
            className={cx("press flex items-center gap-5 rounded-[18px] p-5 text-left md:p-6", last.ok ? "bg-[var(--ok-tint)]" : "bg-[var(--stop-tint)]")}
          >
            <span className={cx("flex h-14 w-14 flex-none items-center justify-center rounded-full text-white", last.ok ? "bg-[var(--ok)]" : "bg-[var(--stop)]")}>
              <Glyph name={last.ok ? "check" : "stop"} size={28} />
            </span>
            <span className="min-w-0">
              <span className="t-small t-num block text-[var(--ink-2)]">Τελευταίος έλεγχος, {last.time}</span>
              <span className={cx("t-title block", last.ok ? "text-[var(--ok)]" : "text-[var(--stop)]")}>{last.ok ? "Δικαιούται καφέ" : "Όχι καφές σήμερα"}</span>
              <span className="t-body block text-[var(--ink)]">{lastMember.name}</span>
              <span className="t-small block text-[var(--ink-2)]">{last.detail}</span>
            </span>
          </button>

          <div className="mt-8 flex items-baseline justify-between gap-4 border-b border-[var(--rule-strong)] pb-3 pl-3">
            <h2 id="evc-today-heading" className="t-title text-[var(--ink)]">Σήμερα στο ταμείο</h2>
            <p className="t-small t-num text-[var(--ink-2)]">{served} καφέδες, {checks.length - served} αρνήσεις</p>
          </div>
          <ul className="m-0 list-none p-0">
            {checks.map((c) => {
              const m = memberById(c.memberId);
              return (
                <li key={`${c.time}-${c.memberId}`} className="border-b border-[var(--rule)]">
                  <button type="button" onClick={() => openMember(m.id)} className={cx("grid min-h-16 w-full grid-cols-[3.25rem_minmax(0,1fr)_auto] items-center gap-3 py-3 pr-1 pl-3 text-left hover:bg-[rgba(139,118,80,0.06)]", !c.ok && "bg-[var(--stop-tint)]/45 shadow-[inset_3px_0_0_var(--stop)]")}>
                    <span className="t-small t-num text-[var(--ink-3)]">{c.time}</span>
                    <span className="min-w-0">
                      <span className="t-body block truncate font-medium text-[var(--ink)]">{m.name}</span>
                      <span className="t-small block truncate text-[var(--ink-2)]">{c.detail}</span>
                    </span>
                    <span className={cx("t-label inline-flex items-center gap-1.5", c.ok ? "text-[var(--ok)]" : "text-[var(--stop)]")}>
                      <Glyph name={c.ok ? "check" : "stop"} size={18} />
                      {c.ok ? "Δόθηκε" : "Δεν δόθηκε"}
                    </span>
                  </button>
                </li>
              );
            })}
          </ul>
          <PreviewNote className="mt-6">Ένας καφές ανά μέλος ανά ημέρα, ώρα Ελλάδας. Στην προεπισκόπηση δεν καταγράφεται καμία εξαργύρωση.</PreviewNote>
        </section>
      </main>
    </StaffFrame>
  );
}
