import { useState } from "react";
import { useSettings } from "../SettingsContext";
import { AdminDashboard } from "./AdminDashboard";
import { AdminOrders } from "./AdminOrders";
import { AdminOrderSoundControl } from "./AdminOrderSoundControl";
import { AdminPanel } from "./AdminPanel";
import { AdminSettings } from "./AdminSettings";
import { AdminStatistics } from "./AdminStatistics";
import { clearStoredPassword } from "./adminApi";

export type AdminSection = "dashboard" | "menu" | "settings" | "orders" | "statistics";

const SECTION_TITLES: Record<Exclude<AdminSection, "dashboard">, string> = {
  menu: "Μενού",
  settings: "Ρυθμίσεις",
  orders: "Παραγγελίες",
  statistics: "Στατιστικά",
};

export function AdminShell({ onLogout }: { onLogout: () => void }) {
  const { settings } = useSettings();
  const [section, setSection] = useState<AdminSection>("dashboard");

  const logout = () => {
    clearStoredPassword();
    onLogout();
  };

  return (
    <div className="min-h-screen bg-cream">
      <header className="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-espresso/12 bg-white px-6 py-4">
        <div className="flex items-center gap-3">
          {section !== "dashboard" && (
            <button
              onClick={() => setSection("dashboard")}
              aria-label="Πίσω στη διαχείριση"
              className="flex h-8 w-8 items-center justify-center rounded-full border border-espresso/15"
            >
              ←
            </button>
          )}
          <img src="/logo-evaggelou-color.png" alt="" className="h-8 object-contain" />
          <h1 className="font-literata text-lg font-semibold">
            {section === "dashboard" ? "Διαχείριση" : SECTION_TITLES[section]}
          </h1>
        </div>
        <div className="flex items-center gap-2">
          <a href="/" className="mr-2 text-sm text-bronze-dark underline">
            Δες το site
          </a>
          <button onClick={logout} className="rounded-lg border border-espresso/20 px-3 py-2 text-sm">
            Αποσύνδεση
          </button>
        </div>
      </header>

      <main className="mx-auto max-w-[960px] px-6 py-6">
        {section === "dashboard" && <AdminDashboard storeName={settings.name.toUpperCase()} onSelect={setSection} />}
        {section === "menu" && <AdminPanel onAuthExpired={logout} />}
        {section === "settings" && <AdminSettings onAuthExpired={logout} />}
        {section === "orders" && <AdminOrders onAuthExpired={logout} />}
        {section === "statistics" && <AdminStatistics onAuthExpired={logout} />}
      </main>

      <AdminOrderSoundControl />
    </div>
  );
}
