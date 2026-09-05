import { useMemo, useState } from "react";
import { useMenu } from "../MenuContext";
import { AddCategoryForm } from "./AddCategoryForm";
import { AdminAuthError, resetOverrides } from "./adminApi";
import { CategoryEditor } from "./CategoryEditor";

export function AdminPanel({ onAuthExpired }: { onAuthExpired: () => void }) {
  const { products, categoryNames, groups, loading, overridesUnavailable, refetch } = useMenu();
  const [resetting, setResetting] = useState(false);
  const [resetMessage, setResetMessage] = useState("");

  const existingCategoryIds = useMemo(() => new Set(Object.keys(categoryNames)), [categoryNames]);

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
        onAuthExpired();
        return;
      }
      setResetMessage(err instanceof Error ? err.message : "Αποτυχία επαναφοράς.");
    } finally {
      setResetting(false);
    }
  };

  if (loading) {
    return <div className="text-sm text-espresso/60">Φόρτωση…</div>;
  }

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <AddCategoryForm
          groups={groups}
          existingCategoryIds={existingCategoryIds}
          onCreated={refetch}
          onAuthExpired={onAuthExpired}
        />
        <button
          onClick={handleReset}
          disabled={resetting}
          className="rounded-lg border border-maroon px-3 py-2 text-sm text-maroon disabled:opacity-50"
        >
          Επαναφορά όλων
        </button>
      </div>

      {overridesUnavailable && (
        <div className="mb-4 rounded-xl bg-maroon/10 px-4 py-3 text-sm text-maroon">
          Δεν βρέθηκε σύνδεση με το menu storage (Upstash/KV). Οι αλλαγές δεν θα αποθηκευτούν μέχρι να ρυθμιστεί το
          integration στο Vercel project — βλέπε README.
        </div>
      )}
      {resetMessage && <div className="mb-4 text-sm text-espresso/70">{resetMessage}</div>}

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
              onAuthExpired={onAuthExpired}
            />
          ))}
        </section>
      ))}
    </div>
  );
}
