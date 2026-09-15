function Corner({ className }: { className: string }) {
  return <span className={`absolute h-7 w-7 border-bronze ${className}`} />;
}

export function ClubQrScanner({ onSimulateScan }: { onSimulateScan: () => void }) {
  return (
    <main className="mx-auto max-w-[520px] px-5 pt-8 pb-10">
      <h1 className="font-literata mb-1.5 text-[24px] font-semibold">Σάρωση QR</h1>
      <p className="mb-7 text-sm text-espresso/60">Τοποθετήστε το QR του μέλους μέσα στο πλαίσιο.</p>

      <div className="relative mx-auto mb-7 flex aspect-square max-w-[320px] items-center justify-center rounded-2xl bg-espresso">
        <Corner className="top-5 left-5 rounded-tl-lg border-t-[3px] border-l-[3px]" />
        <Corner className="top-5 right-5 rounded-tr-lg border-t-[3px] border-r-[3px]" />
        <Corner className="bottom-5 left-5 rounded-bl-lg border-b-[3px] border-l-[3px]" />
        <Corner className="right-5 bottom-5 rounded-br-lg border-r-[3px] border-b-[3px]" />

        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.3" strokeLinecap="round" strokeLinejoin="round" className="text-cream/35">
          <rect x="3.5" y="3.5" width="6.5" height="6.5" rx="1" />
          <rect x="14" y="3.5" width="6.5" height="6.5" rx="1" />
          <rect x="3.5" y="14" width="6.5" height="6.5" rx="1" />
          <path d="M14 14h2.8M14 17.5h6.5M20.5 14v3.5M14 20.5h6.5" />
        </svg>
      </div>

      <div className="mb-6 rounded-xl bg-bronze/10 px-4 py-3.5 text-center">
        <p className="text-[13px] text-espresso/70">
          Σε πραγματική λειτουργία εδώ θα ανοίγει η κάμερα. Στο demo, χρησιμοποίησε την προσομοίωση παρακάτω.
        </p>
      </div>

      <button
        onClick={onSimulateScan}
        className="flex w-full items-center justify-center gap-2.5 rounded-xl border-none bg-bronze-dark py-3.5 text-sm font-semibold text-white"
      >
        <span className="rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-bold tracking-wide uppercase">Demo</span>
        Προσομοίωση σάρωσης
      </button>
    </main>
  );
}
