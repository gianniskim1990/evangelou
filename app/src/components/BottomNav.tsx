import { useApp } from "../AppContext";
import type { Screen } from "../types";

function navColor(active: boolean) {
  return active ? "text-bronze-dark" : "text-espresso";
}

export function BottomNav() {
  const { screen, goHome, goCatalog, goConfigurator, openCart, cartCount } = useApp();
  const active: Screen = screen;

  return (
    <nav className="fixed bottom-0 left-1/2 z-20 flex w-full max-w-[480px] -translate-x-1/2 justify-around border-t border-espresso/12 bg-cream px-1 pt-2 pb-2.5">
      <button onClick={goHome} className={`flex cursor-pointer flex-col items-center gap-[3px] border-none bg-transparent ${navColor(active === "home")}`}>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <path d="M4 10.5 12 4l8 6.5" />
          <path d="M6 9.5V20h12V9.5" />
        </svg>
        <span className="text-[11px] font-semibold">Αρχική</span>
      </button>
      <button onClick={goCatalog} className={`flex cursor-pointer flex-col items-center gap-[3px] border-none bg-transparent ${navColor(active === "catalog")}`}>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
          <rect x="4" y="4" width="7" height="7" rx="1.5" />
          <rect x="13" y="4" width="7" height="7" rx="1.5" />
          <rect x="4" y="13" width="7" height="7" rx="1.5" />
          <rect x="13" y="13" width="7" height="7" rx="1.5" />
        </svg>
        <span className="text-[11px] font-semibold">Κατάλογος</span>
      </button>
      <button onClick={goConfigurator} className={`flex cursor-pointer flex-col items-center gap-[3px] border-none bg-transparent ${navColor(active === "configurator")}`}>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <path d="M4 11h16a8 8 0 0 1-16 0Z" />
          <path d="M12 4v4" />
          <circle cx="12" cy="3" r="1" />
        </svg>
        <span className="text-[11px] font-semibold">Δημιουργός</span>
      </button>
      <button onClick={openCart} className="relative flex cursor-pointer flex-col items-center gap-[3px] border-none bg-transparent text-espresso">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <path d="M6 8h12l-1 12H7L6 8Z" />
          <path d="M9 8a3 3 0 0 1 6 0" />
        </svg>
        <span className="text-[11px] font-semibold">Καλάθι</span>
        {cartCount > 0 && (
          <span className="absolute -top-[3px] right-2.5 flex h-[15px] min-w-[15px] items-center justify-center rounded-lg bg-bronze-dark text-[9.5px] font-bold text-white">
            {cartCount}
          </span>
        )}
      </button>
    </nav>
  );
}
