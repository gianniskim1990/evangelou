export function ClubEntry({ onEnter }: { onEnter: () => void }) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-cream px-5">
      <div className="w-full max-w-[380px] rounded-2xl bg-surface p-8 text-center shadow-[0_4px_24px_rgba(30,24,18,0.1)]">
        <div className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full border-[1.5px] border-bronze">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" className="text-bronze-dark">
            <path d="M12 3 4 6.5V11c0 4.5 3.2 8.3 8 9.5 4.8-1.2 8-5 8-9.5V6.5L12 3Z" />
            <path d="m9 12 2 2 4-4" />
          </svg>
        </div>

        <div className="mb-1 text-[12px] font-semibold tracking-[0.16em] text-bronze uppercase">Ευαγγέλου</div>
        <h1 className="font-literata mb-2 text-[26px] font-semibold">Club</h1>
        <p className="mb-7 text-sm text-espresso/60">Πρόσβαση προσωπικού</p>

        <button
          onClick={onEnter}
          className="w-full rounded-xl border-none bg-bronze-dark py-3.5 text-sm font-semibold text-white"
        >
          Είσοδος στο demo
        </button>

        <p className="mt-5 text-xs text-espresso/45 italic">«Περιβάλλον προσωπικού καταστήματος»</p>
      </div>
    </div>
  );
}
