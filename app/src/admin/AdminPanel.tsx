import { useState } from "react";
import { useMenu } from "../MenuContext";
import { groups } from "../data/menu";
import { AdminAuthError, clearStoredPassword, resetOverrides } from "./adminApi";
import { CategoryEditor } from "./CategoryEditor";

export function AdminPanel({ onLogout }: { onLogout: () => void }) {
  const { products, categoryNames, loading, overridesUnavailable, refetch } = useMenu();
  const [resetting, setResetting] = useState(false);
  const [resetMessage, setResetMessage] = useState("");

  const logout = () => {
    clearStoredPassword();
    onLogout();
  };

  const handleReset = async () => {
    if (!confirm("Να επαναφερθούν όλες οι κατηγορίες/προϊόντα στις προεπιλεγμένες τιμές; Αυτό δεν αναιρείται.")) return;
    setResetting(true);
    setResetMessage("");
    try {
      await resetOverrides();
      refetch();
      setResetMessage("Έγινε επαναφορά.");
    } catch (err) {
      if (err instanceof AdminAuthError) {
        onLogout();
        return;
      }
      setResetMessage(err instanceof Error ? err.message : "Αποτυχία επαναφοράς.");
    } finally {
      setResetting(false);
    }
  };

  if (loading) {
    return <div className="flex min-h-screen items-center justify-center text-espresso/60">Φόρτωση…</div>;
  }

  return (
    <div className="min-h-screen bg-cream">
      <header className="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-espresso/12 bg-white px-6 py-4">
        <div className="flex items-center gap-4">
          <img src="/logo-evaggelou-color.png" alt="" className="h-8 object-contain" />
          <h1 className="font-literata text-lg font-semibold">Διαχείριση καταλόγου</h1>
        </div>
        <div className="flex items-center gap-2">
          <a href="/" className="mr-2 text-sm text-bronze-dark underline">
            Δες το site
          </a>
          <button
            onClick={handleReset}
            disabled={resetting}
            className="rounded-lg border border-maroon px-3 py-2 text-sm text-maroon disabled:opacity-50"
          >
            Επαναφορά όλων
          </button>
          <button onClick={logout} className="rounded-lg border border-espresso/20 px-3 py-2 text-sm">
            Αποσύνδεση
          </button>
        </div>
      </header>

      {overridesUnavailable && (
        <div className="mx-6 mt-4 rounded-xl bg-maroon/10 px-4 py-3 text-sm text-maroon">
          Δεν βρέθηκε σύνδεση με το menu storage (Upstash/KV). Οι αλλαγές δεν θα αποθηκευτούν μέχρι να ρυθμιστεί το
          integration στο Vercel project — βλέπε README.
        </div>
      )}
      {resetMessage && <div className="mx-6 mt-4 text-sm text-espresso/70">{resetMessage}</div>}

      <main className="mx-auto max-w-[900px] px-6 py-6">
        {groups.map((g) => (
          <section key={g.id} className="mb-7">
            <h2 className="font-literata mb-2 text-[13px] font-semibold tracking-[0.14em] text-bronze uppercase">
              {g.name}
            </h2>
            {g.categories.map((catId) => (
              <CategoryEditor
                key={catId}
                catId={catId}
                initialName={categoryNames[catId]}
                initialProducts={products[catId] ?? []}
                onSaved={refetch}
                onAuthExpired={onLogout}
              />
            ))}
          </section>
        ))}
      </main>
    </div>
  );
}
