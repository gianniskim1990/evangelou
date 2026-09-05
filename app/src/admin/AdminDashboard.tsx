import type { ReactNode } from "react";
import type { AdminSection } from "./AdminShell";

const CARDS: { key: AdminSection; title: string; subtitle: string; icon: ReactNode }[] = [
  {
    key: "menu",
    title: "Μενού",
    subtitle: "Κατηγορίες, προϊόντα",
    icon: (
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
        <path d="M6 3v7a2 2 0 0 0 4 0V3M6 3v18M10 3v6M17 3c-2 1-3 3-3 6s1 5 3 6v3" />
      </svg>
    ),
  },
  {
    key: "settings",
    title: "Ρυθμίσεις",
    subtitle: "Κατάστημα, delivery, ωράριο λειτουργίας",
    icon: (
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="12" cy="12" r="3" />
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" />
      </svg>
    ),
  },
  {
    key: "orders",
    title: "Παραγγελίες",
    subtitle: "Παραγγελίες καταστήματος",
    icon: (
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
        <path d="M6 8h12l-1 12H7L6 8Z" />
        <path d="M9 8a3 3 0 0 1 6 0" />
      </svg>
    ),
  },
  {
    key: "statistics",
    title: "Στατιστικά",
    subtitle: "Πωλήσεις, παραγγελίες, δημοφιλή προϊόντα",
    icon: (
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
        <path d="M4 20V10M12 20V4M20 20v-7" />
      </svg>
    ),
  },
];

export function AdminDashboard({ storeName, onSelect }: { storeName: string; onSelect: (s: AdminSection) => void }) {
  return (
    <div>
      <h1 className="font-literata mb-1 text-2xl font-bold uppercase">
        Διαχείριση {storeName}
      </h1>
      <p className="mb-8 text-sm text-espresso/60">Περιοχή διαχείρισης καταστήματος.</p>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {CARDS.map((c) => (
          <button
            key={c.key}
            onClick={() => onSelect(c.key)}
            className="flex items-center gap-4 rounded-2xl bg-bronze/10 p-5 text-left"
          >
            <span className="flex h-11 w-11 flex-none items-center justify-center rounded-xl bg-bronze/20 text-bronze-dark">
              {c.icon}
            </span>
            <span>
              <span className="block text-[15px] font-bold">{c.title}</span>
              <span className="block text-sm text-bronze-dark">{c.subtitle}</span>
            </span>
          </button>
        ))}
      </div>
    </div>
  );
}
