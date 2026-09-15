function Corner({ className }: { className: string }) {
  return <span className={`absolute h-7 w-7 border-bronze ${className}`} aria-hidden="true" />;
}

export function ClubQrScanner({
  onSimulateScan,
  scanning,
  error,
}: {
  onSimulateScan: () => void;
  scanning: boolean;
  error?: string;
}) {
  return (
    <main className="mx-auto max-w-[520px] px-5 pt-8 pb-10 md:max-w-[640px] md:px-8 md:pt-12">
      <h1 className="font-literata mb-1.5 text-[24px] font-semibold">Σάρωση QR</h1>
      <p className="mb-7 text-sm text-espresso/60">Τοποθετήστε το QR του μέλους μέσα στο πλαίσιο.</p>

      <div className="relative mx-auto mb-7 flex aspect-square max-w-[320px] items-center justify-center overflow-hidden rounded-2xl bg-espresso md:max-w-[360px]">
        <Corner className="top-5 left-5 rounded-tl-lg border-t-[3px] border-l-[3px]" />
        <Corner className="top-5 right-5 rounded-tr-lg border-t-[3px] border-r-[3px]" />
        <Corner className="bottom-5 left-5 rounded-bl-lg border-b-[3px] border-l-[3px]" />
        <Corner className="right-5 bottom-5 rounded-br-lg border-r-[3px] border-b-[3px]" />

        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.3" strokeLinecap="round" strokeLinejoin="round" className="text-cream/35" aria-hidden="true">
          <rect x="3.5" y="3.5" width="6.5" height="6.5" rx="1" />
          <rect x="14" y="3.5" width="6.5" height="6.5" rx="1" />
          <rect x="3.5" y="14" width="6.5" height="6.5" rx="1" />
          <path d="M14 14h2.8M14 17.5h6.5M20.5 14v3.5M14 20.5h6.5" />
        </svg>

        {!scanning && <span className="absolute inset-x-8 h-px animate-scan-line bg-bronze/70" aria-hidden="true" />}
      </div>

      <div className="mb-6 rounded-xl bg-bronze/10 px-4 py-3.5 text-center">
        <p className="text-[13px] text-espresso/70">
          Σε πραγματική λειτουργία εδώ θα ανοίγει η κάμερα. Στο demo, χρησιμοποίησε την προσομοίωση παρακάτω.
        </p>
      </div>

      {error && <p className="mb-4 text-center text-sm text-maroon">{error}</p>}

      <button
        onClick={onSimulateScan}
        disabled={scanning}
        className="flex w-full items-center justify-center gap-2.5 rounded-xl border-none bg-bronze-dark py-3.5 text-sm font-semibold text-white disabled:opacity-60"
      >
        {scanning ? (
          "Αναζήτηση μέλους…"
        ) : (
          <>
            <span className="rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-bold tracking-wide uppercase">Demo</span>
            Προσομοίωση σάρωσης
          </>
        )}
      </button>
    </main>
  );
}
