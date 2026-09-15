import type { ReactNode } from "react";

export function ClubShell({
  onBack,
  onExit,
  children,
}: {
  onBack?: () => void;
  onExit: () => void;
  children: ReactNode;
}) {
  return (
    <div className="min-h-screen bg-cream">
      <header className="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-espresso/12 bg-surface px-5 py-3.5 md:px-8">
        <div className="flex items-center gap-3">
          {onBack && (
            <button
              onClick={onBack}
              aria-label="Πίσω"
              className="flex h-10 w-10 flex-none items-center justify-center rounded-full border border-espresso/15"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="M15 5 8 12l7 7" />
              </svg>
            </button>
          )}
          <img src="/logo-evaggelou-color.png" alt="" className="h-7 object-contain" />
          <span className="font-literata text-[15px] font-semibold">
            Club <span className="font-normal text-espresso/50">· προσωπικό</span>
          </span>
        </div>
        <button onClick={onExit} className="flex-none rounded-lg border border-espresso/20 px-3 py-2.5 text-[13px] font-semibold">
          Αποχώρηση
        </button>
      </header>

      {children}
    </div>
  );
}
