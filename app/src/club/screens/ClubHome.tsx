import { useState } from "react";
import { DEMO_PHONE_HINTS } from "../clubService";
import { normalizeGreekPhone } from "../format";

const inputClass =
  "w-full rounded-xl border border-espresso/20 bg-surface px-3.5 py-3.5 text-base font-[Commissioner,sans-serif] tracking-wide";

export function ClubHome({
  onScanQr,
  onSearch,
  searching,
  error,
}: {
  onScanQr: () => void;
  onSearch: (phone: string) => void;
  searching: boolean;
  error?: string;
}) {
  const [phone, setPhone] = useState("");
  const [showDemoInfo, setShowDemoInfo] = useState(false);

  const normalized = normalizeGreekPhone(phone);
  const canSearch = normalized.length === 10 && !searching;
  const showIncompleteHint = normalized.length > 0 && normalized.length < 10 && !searching;

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (canSearch) onSearch(phone);
  };

  return (
    <main className="mx-auto max-w-[520px] px-5 pt-8 pb-10 md:max-w-[860px] md:px-8 md:pt-12 md:pb-16">
      <h1 className="font-literata mb-1.5 text-[24px] font-semibold">Έλεγχος μέλους</h1>
      <p className="mb-8 text-sm text-espresso/60">Σάρωσε το QR του μέλους ή αναζήτησε με το τηλέφωνό του.</p>

      <div className="md:grid md:grid-cols-2 md:items-stretch md:gap-6">
        <button
          onClick={onScanQr}
          className="mb-6 flex w-full items-center gap-4 rounded-2xl border-[1.5px] border-bronze bg-surface px-5 py-5 text-left md:mb-0 md:px-7 md:py-8"
        >
          <span className="flex h-12 w-12 flex-none items-center justify-center rounded-xl bg-bronze/12 text-bronze-dark md:h-14 md:w-14">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <rect x="3.5" y="3.5" width="6.5" height="6.5" rx="1" />
              <rect x="14" y="3.5" width="6.5" height="6.5" rx="1" />
              <rect x="3.5" y="14" width="6.5" height="6.5" rx="1" />
              <path d="M14 14h2.8M14 17.5h6.5M20.5 14v3.5M14 20.5h6.5" />
            </svg>
          </span>
          <span className="flex-1">
            <span className="block text-[15px] font-semibold md:text-base">Σάρωση QR</span>
            <span className="block text-[13px] text-espresso/55">Κάρτα μέλους ή κινητό</span>
          </span>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="flex-none text-espresso/40" aria-hidden="true">
            <path d="M9 5l7 7-7 7" />
          </svg>
        </button>

        <div className="rounded-2xl border border-espresso/10 bg-surface px-5 py-5 md:flex md:flex-col md:justify-center md:px-7 md:py-8">
          <div className="mb-6 flex items-center gap-3 text-xs font-medium text-espresso/40 md:hidden">
            <span className="h-px flex-1 bg-espresso/12" />
            ή
            <span className="h-px flex-1 bg-espresso/12" />
          </div>

          <form onSubmit={submit}>
            <label htmlFor="club-phone" className="mb-2 block text-[13px] font-semibold text-espresso/70">
              Τηλέφωνο μέλους
            </label>
            <div className="flex gap-2.5">
              <input
                id="club-phone"
                value={phone}
                onChange={(e) => setPhone(e.target.value.replace(/[^\d\s+]/g, ""))}
                type="tel"
                inputMode="tel"
                placeholder="69XX XXX XXX"
                autoFocus
                className={inputClass}
              />
              <button
                type="submit"
                disabled={!canSearch}
                className="min-w-[128px] flex-none rounded-xl border-none bg-bronze-dark px-6 py-3.5 text-sm font-semibold text-white disabled:opacity-40"
              >
                {searching ? "Αναζήτηση…" : "Αναζήτηση"}
              </button>
            </div>
            {showIncompleteHint && (
              <p className="mt-2 text-xs text-espresso/45">Χρειάζεται ολόκληρος αριθμός κινητού (10 ψηφία).</p>
            )}
            {error && <p className="mt-2 text-xs text-maroon">{error}</p>}
          </form>
        </div>
      </div>

      <div className="mt-10 border-t border-espresso/10 pt-4">
        <button
          type="button"
          onClick={() => setShowDemoInfo((v) => !v)}
          className="text-xs font-medium text-espresso/40 underline decoration-dotted underline-offset-2"
        >
          Στοιχεία demo {showDemoInfo ? "▲" : "▼"}
        </button>
        {showDemoInfo && (
          <div className="mt-3 rounded-xl bg-hairline/50 p-3.5 md:max-w-[420px]">
            <p className="mb-2.5 text-[11px] text-espresso/50">Μόνο για την παρουσίαση — δεν εμφανίζεται στο πραγματικό προϊόν.</p>
            <div className="flex flex-col gap-1.5">
              {DEMO_PHONE_HINTS.map((hint) => (
                <button
                  key={hint.phone}
                  type="button"
                  onClick={() => setPhone(hint.phone)}
                  className="flex items-center justify-between rounded-lg bg-surface px-3 py-2.5 text-left text-[12.5px]"
                >
                  <span className="font-semibold text-espresso">{hint.phone}</span>
                  <span className="text-espresso/50">{hint.label}</span>
                </button>
              ))}
            </div>
          </div>
        )}
      </div>
    </main>
  );
}
