import { useTheme, type ThemePreference } from "../ThemeContext";

const NEXT: Record<ThemePreference, ThemePreference> = {
  light: "dark",
  dark: "system",
  system: "light",
};

const LABELS: Record<ThemePreference, string> = {
  light: "Φωτεινό θέμα — πάτα για σκοτεινό",
  dark: "Σκοτεινό θέμα — πάτα για αυτόματο (σύστημα)",
  system: "Αυτόματο θέμα (σύστημα) — πάτα για φωτεινό",
};

export function ThemeToggle({ className = "" }: { className?: string }) {
  const { preference, setPreference } = useTheme();

  return (
    <button
      type="button"
      onClick={() => setPreference(NEXT[preference])}
      aria-label={LABELS[preference]}
      title={LABELS[preference]}
      className={`flex h-9 w-9 flex-none items-center justify-center rounded-full border border-espresso/15 bg-surface text-espresso ${className}`}
    >
      {preference === "light" && (
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <circle cx="12" cy="12" r="4.5" />
          <path d="M12 2.5v2.2M12 19.3v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6" />
        </svg>
      )}
      {preference === "dark" && (
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <path d="M20 14.2A8.3 8.3 0 0 1 9.8 4a8.3 8.3 0 1 0 10.2 10.2Z" />
        </svg>
      )}
      {preference === "system" && (
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <rect x="3" y="4.5" width="18" height="12" rx="2" />
          <path d="M8 20h8M12 16.5V20" />
        </svg>
      )}
    </button>
  );
}
