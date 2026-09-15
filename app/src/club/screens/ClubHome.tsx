import { useState } from "react";

const inputClass =
  "w-full rounded-xl border border-espresso/20 bg-surface px-3.5 py-3.5 text-base font-[Commissioner,sans-serif] tracking-wide";

export function ClubHome({
  onScanQr,
  onSearch,
  searching,
}: {
  onScanQr: () => void;
  onSearch: (phone: string) => void;
  searching: boolean;
}) {
  const [phone, setPhone] = useState("");

  const canSearch = phone.replace(/\D/g, "").length >= 10 && !searching;

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (canSearch) onSearch(phone);
  };

  return (
    <main className="mx-auto max-w-[520px] px-5 pt-8 pb-10">
      <h1 className="font-literata mb-1.5 text-[24px] font-semibold">Έλεγχος μέλους</h1>
      <p className="mb-8 text-sm text-espresso/60">Σάρωσε το QR του μέλους ή αναζήτησε με το τηλέφωνό του.</p>

      <button
        onClick={onScanQr}
        className="mb-6 flex w-full items-center gap-4 rounded-2xl border-[1.5px] border-bronze bg-surface px-5 py-5 text-left"
      >
        <span className="flex h-12 w-12 flex-none items-center justify-center rounded-xl bg-bronze/12 text-bronze-dark">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3.5" y="3.5" width="6.5" height="6.5" rx="1" />
            <rect x="14" y="3.5" width="6.5" height="6.5" rx="1" />
            <rect x="3.5" y="14" width="6.5" height="6.5" rx="1" />
            <path d="M14 14h2.8M14 17.5h6.5M20.5 14v3.5M14 20.5h6.5" />
          </svg>
        </span>
        <span className="flex-1">
          <span className="block text-[15px] font-semibold">Σάρωση QR</span>
          <span className="block text-[13px] text-espresso/55">Κάρτα μέλους ή κινητό</span>
        </span>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="flex-none text-espresso/40">
          <path d="M9 5l7 7-7 7" />
        </svg>
      </button>

      <div className="mb-6 flex items-center gap-3 text-xs font-semibold tracking-wide text-espresso/40 uppercase">
        <span className="h-px flex-1 bg-espresso/12" />
        ή
        <span className="h-px flex-1 bg-espresso/12" />
      </div>

      <form onSubmit={submit}>
        <label className="mb-2 block text-[13px] font-semibold text-espresso/70">Τηλέφωνο μέλους</label>
        <div className="flex gap-2.5">
          <input
            value={phone}
            onChange={(e) => setPhone(e.target.value.replace(/[^\d\s]/g, ""))}
            type="tel"
            inputMode="numeric"
            placeholder="69XX XXX XXX"
            autoFocus
            className={inputClass}
          />
          <button
            type="submit"
            disabled={!canSearch}
            className="flex-none rounded-xl border-none bg-bronze-dark px-6 py-3.5 text-sm font-semibold text-white disabled:opacity-40"
          >
            {searching ? "…" : "Αναζήτηση"}
          </button>
        </div>
      </form>
    </main>
  );
}
