import { useState } from "react";
import type { Product } from "../types";
import { slugify } from "../lib/slug";
import { AdminAuthError, saveOverrides, uploadCategoryImage, uploadProductImage } from "./adminApi";
import { ImageUploadButton } from "./ImageUploadButton";

interface Props {
  catId: string;
  initialName: string;
  initialProducts: Product[];
  onSaved: () => void;
  onAuthExpired: () => void;
}

function isRowValid(p: Product): boolean {
  return p.name.trim().length > 0 && Number.isFinite(p.price) && p.price >= 0;
}

export function CategoryEditor({ catId, initialName, initialProducts, onSaved, onAuthExpired }: Props) {
  const [open, setOpen] = useState(false);
  const [name, setName] = useState(initialName);
  const [items, setItems] = useState<Product[]>(initialProducts);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [message, setMessage] = useState<{ kind: "ok" | "error"; text: string } | null>(null);
  const [savedSnapshot, setSavedSnapshot] = useState({ name: initialName, items: initialProducts });

  const dirty = name !== savedSnapshot.name || JSON.stringify(items) !== JSON.stringify(savedSnapshot.items);
  const allValid = items.every(isRowValid);

  const updateItem = (idx: number, patch: Partial<Product>) => {
    setItems((list) => list.map((it, i) => (i === idx ? { ...it, ...patch } : it)));
  };
  const removeItem = (idx: number) => setItems((list) => list.filter((_, i) => i !== idx));
  const addItem = () => setItems((list) => [...list, { name: "", price: 0 }]);

  const handleAuthError = (err: unknown): boolean => {
    if (err instanceof AdminAuthError) {
      onAuthExpired();
      return true;
    }
    return false;
  };

  const save = async () => {
    if (!allValid) return;
    setSaving(true);
    setMessage(null);
    try {
      const finalName = name.trim() || initialName;
      await saveOverrides({ products: { [catId]: items }, categoryNames: { [catId]: finalName } });
      setSavedSnapshot({ name: finalName, items });
      setMessage({ kind: "ok", text: "Αποθηκεύτηκε." });
      onSaved();
    } catch (err) {
      if (handleAuthError(err)) return;
      setMessage({ kind: "error", text: err instanceof Error ? err.message : "Αποτυχία αποθήκευσης." });
    } finally {
      setSaving(false);
    }
  };

  const uploadCategoryPhoto = async (dataUrl: string) => {
    try {
      await uploadCategoryImage(catId, dataUrl);
      onSaved();
    } catch (err) {
      if (handleAuthError(err)) return;
      throw err;
    }
  };

  const uploadProductPhoto = async (productName: string, dataUrl: string) => {
    try {
      await uploadProductImage(slugify(productName), dataUrl);
      onSaved();
    } catch (err) {
      if (handleAuthError(err)) return;
      throw err;
    }
  };

  const deleteCategory = async () => {
    if (!confirm(`Να διαγραφεί η κατηγορία "${name}" μαζί με τα ${items.length} προϊόντα της; Αυτό δεν αναιρείται (εκτός από "Επαναφορά όλων").`)) return;
    setDeleting(true);
    setMessage(null);
    try {
      await saveOverrides({ deletedCategories: [catId] });
      onSaved();
    } catch (err) {
      if (handleAuthError(err)) return;
      setMessage({ kind: "error", text: err instanceof Error ? err.message : "Αποτυχία διαγραφής." });
      setDeleting(false);
    }
  };

  return (
    <div className="mb-2 overflow-hidden rounded-xl border border-espresso/10 bg-surface">
      <button
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between px-4 py-3 text-left"
      >
        <span className="text-sm font-semibold">
          {name} <span className="font-normal text-espresso/45">· {items.length} προϊόντα</span>
        </span>
        <span className="text-espresso/50">{open ? "▲" : "▼"}</span>
      </button>

      {open && (
        <div className="border-t border-espresso/10 p-4">
          <label className="mb-1 block text-xs font-semibold text-espresso/60">Όνομα κατηγορίας</label>
          <div className="mb-4 flex flex-wrap items-center gap-2.5">
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="flex-1 rounded-lg border border-espresso/20 px-3 py-2 text-sm"
            />
            <ImageUploadButton label="Εικόνα κατηγορίας" onUpload={uploadCategoryPhoto} />
            <button
              onClick={deleteCategory}
              disabled={deleting}
              className="rounded-lg border border-maroon px-2.5 py-1.5 text-xs font-semibold text-maroon disabled:opacity-40"
            >
              {deleting ? "Διαγραφή…" : "Διαγραφή κατηγορίας"}
            </button>
          </div>

          <div className="flex flex-col gap-2">
            {items.map((it, idx) => (
              <div key={idx} className="flex flex-wrap items-center gap-2">
                <input
                  value={it.name}
                  onChange={(e) => updateItem(idx, { name: e.target.value })}
                  placeholder="Όνομα προϊόντος"
                  className="flex-1 rounded-lg border border-espresso/20 px-3 py-2 text-sm"
                  style={{ borderColor: it.name.trim() ? undefined : "var(--color-maroon)" }}
                />
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  value={it.price}
                  onChange={(e) => updateItem(idx, { price: parseFloat(e.target.value) })}
                  className="w-24 rounded-lg border border-espresso/20 px-3 py-2 text-sm"
                  style={{ borderColor: Number.isFinite(it.price) && it.price >= 0 ? undefined : "var(--color-maroon)" }}
                />
                <label className="flex items-center gap-1.5 text-xs whitespace-nowrap text-espresso/70">
                  <input type="checkbox" checked={!!it.perKilo} onChange={(e) => updateItem(idx, { perKilo: e.target.checked })} />
                  /κιλό
                </label>
                <label className="flex items-center gap-1.5 text-xs whitespace-nowrap text-espresso/70">
                  <input type="checkbox" checked={!!it.diabetic} onChange={(e) => updateItem(idx, { diabetic: e.target.checked })} />
                  χωρίς ζάχαρη
                </label>
                <ImageUploadButton
                  label="Εικόνα"
                  disabledReason={it.name.trim() ? undefined : "Συμπλήρωσε πρώτα το όνομα"}
                  onUpload={(dataUrl) => uploadProductPhoto(it.name, dataUrl)}
                />
                <button onClick={() => removeItem(idx)} aria-label="Διαγραφή" className="px-2 text-espresso/50 hover:text-maroon">
                  ✕
                </button>
              </div>
            ))}
          </div>

          <button onClick={addItem} className="mt-3 text-sm font-semibold text-bronze-dark underline">
            + Νέο προϊόν
          </button>
          <p className="mt-1 text-xs text-espresso/50">
            Η εικόνα συνδέεται με το όνομα του προϊόντος — γράψε πρώτα το όνομα, μετά πάτα "Εικόνα" (δεν χρειάζεται
            να έχεις ήδη πατήσει Αποθήκευση). Αν αλλάξεις το όνομα αργότερα, ανέβασε ξανά την εικόνα.
          </p>

          <div className="mt-4 flex flex-wrap items-center gap-3">
            <button
              onClick={save}
              disabled={!dirty || !allValid || saving}
              className="rounded-lg border-none bg-bronze-dark px-4 py-2 text-sm font-semibold text-white disabled:opacity-40"
            >
              {saving ? "Αποθήκευση…" : "Αποθήκευση"}
            </button>
            {!allValid && <span className="text-xs text-maroon">Συμπλήρωσε όνομα και έγκυρη τιμή σε κάθε γραμμή.</span>}
            {message && (
              <span className="text-xs" style={{ color: message.kind === "ok" ? "var(--color-bronze)" : "var(--color-maroon)" }}>
                {message.text}
              </span>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
