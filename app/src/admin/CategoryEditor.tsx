import { useState } from "react";
import type { Product } from "../types";
import { AdminAuthError, saveOverrides } from "./adminApi";

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
  const [message, setMessage] = useState<{ kind: "ok" | "error"; text: string } | null>(null);
  const [savedSnapshot, setSavedSnapshot] = useState({ name: initialName, items: initialProducts });

  const dirty = name !== savedSnapshot.name || JSON.stringify(items) !== JSON.stringify(savedSnapshot.items);
  const allValid = items.every(isRowValid);

  const updateItem = (idx: number, patch: Partial<Product>) => {
    setItems((list) => list.map((it, i) => (i === idx ? { ...it, ...patch } : it)));
  };
  const removeItem = (idx: number) => setItems((list) => list.filter((_, i) => i !== idx));
  const addItem = () => setItems((list) => [...list, { name: "", price: 0 }]);

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
      if (err instanceof AdminAuthError) {
        onAuthExpired();
        return;
      }
      setMessage({ kind: "error", text: err instanceof Error ? err.message : "Αποτυχία αποθήκευσης." });
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="mb-2 overflow-hidden rounded-xl border border-espresso/10 bg-white">
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
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            className="mb-4 w-full rounded-lg border border-espresso/20 px-3 py-2 text-sm"
          />

          <div className="flex flex-col gap-2">
            {items.map((it, idx) => (
              <div key={idx} className="flex items-center gap-2">
                <input
                  value={it.name}
                  onChange={(e) => updateItem(idx, { name: e.target.value })}
                  placeholder="Όνομα προϊόντος"
                  className="flex-1 rounded-lg border border-espresso/20 px-3 py-2 text-sm"
                  style={{ borderColor: it.name.trim() ? undefined : "#7A2E3B" }}
                />
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  value={it.price}
                  onChange={(e) => updateItem(idx, { price: parseFloat(e.target.value) })}
                  className="w-24 rounded-lg border border-espresso/20 px-3 py-2 text-sm"
                  style={{ borderColor: Number.isFinite(it.price) && it.price >= 0 ? undefined : "#7A2E3B" }}
                />
                <label className="flex items-center gap-1.5 text-xs whitespace-nowrap text-espresso/70">
                  <input type="checkbox" checked={!!it.perKilo} onChange={(e) => updateItem(idx, { perKilo: e.target.checked })} />
                  /κιλό
                </label>
                <label className="flex items-center gap-1.5 text-xs whitespace-nowrap text-espresso/70">
                  <input type="checkbox" checked={!!it.diabetic} onChange={(e) => updateItem(idx, { diabetic: e.target.checked })} />
                  χωρίς ζάχαρη
                </label>
                <button onClick={() => removeItem(idx)} aria-label="Διαγραφή" className="px-2 text-espresso/50 hover:text-maroon">
                  ✕
                </button>
              </div>
            ))}
          </div>

          <button onClick={addItem} className="mt-3 text-sm font-semibold text-bronze-dark underline">
            + Νέο προϊόν
          </button>

          <div className="mt-4 flex items-center gap-3">
            <button
              onClick={save}
              disabled={!dirty || !allValid || saving}
              className="rounded-lg border-none bg-bronze-dark px-4 py-2 text-sm font-semibold text-white disabled:opacity-40"
            >
              {saving ? "Αποθήκευση…" : "Αποθήκευση"}
            </button>
            {!allValid && <span className="text-xs text-maroon">Συμπλήρωσε όνομα και έγκυρη τιμή σε κάθε γραμμή.</span>}
            {message && (
              <span className="text-xs" style={{ color: message.kind === "ok" ? "#86764F" : "#7A2E3B" }}>
                {message.text}
              </span>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
