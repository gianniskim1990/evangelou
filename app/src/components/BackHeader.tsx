import { useApp } from "../AppContext";
import type { Screen } from "../types";
import { ThemeToggle } from "./ThemeToggle";

const TITLES: Partial<Record<Screen, string>> = {
  checkout: "Ολοκλήρωση παραγγελίας",
  confirmation: "Επιβεβαίωση",
  status: "Κατάσταση παραγγελίας",
};

export function BackHeader() {
  const { screen, goHome, openCart } = useApp();

  const onBack = () => {
    if (screen === "checkout") {
      goHome();
      openCart();
    } else {
      goHome();
    }
  };

  return (
    <header className="sticky top-0 z-20 flex items-center gap-3 bg-surface px-4 py-3.5 shadow-[0_2px_12px_rgba(30,24,18,0.10)]">
      <button
        onClick={onBack}
        aria-label="Πίσω"
        className="flex h-8 w-8 items-center justify-center rounded-full border-none bg-cream text-espresso"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <path d="M15 5 8 12l7 7" />
        </svg>
      </button>
      <div className="font-literata flex-1 text-lg font-semibold">{TITLES[screen]}</div>
      <ThemeToggle />
    </header>
  );
}
