import { useState } from "react";
import type { CatalogGroup } from "../types";
import { slugify } from "../lib/slug";
import { AdminAuthError, saveOverrides } from "./adminApi";

const NEW_GROUP_VALUE = "__new_group__";

function uniqueId(base: string, taken: Set<string>): string {
  if (!taken.has(base)) return base;
  let i = 2;
  while (taken.has(`${base}-${i}`)) i++;
  return `${base}-${i}`;
}

interface Props {
  groups: CatalogGroup[];
  existingCategoryIds: Set<string>;
  onCreated: () => void;
  onAuthExpired: () => void;
}

export function AddCategoryForm({ groups, existingCategoryIds, onCreated, onAuthExpired }: Props) {
  const [open, setOpen] = useState(false);
  const [name, setName] = useState("");
  const [groupChoice, setGroupChoice] = useState<string>(groups[0]?.id ?? NEW_GROUP_VALUE);
  const [newGroupName, setNewGroupName] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const creatingNewGroup = groupChoice === NEW_GROUP_VALUE;
  const canSave = name.trim().length > 0 && (!creatingNewGroup || newGroupName.trim().length > 0);

  const reset = () => {
    setName("");
    setNewGroupName("");
    setGroupChoice(groups[0]?.id ?? NEW_GROUP_VALUE);
    setOpen(false);
    setError("");
  };

  const save = async () => {
    if (!canSave) return;
    setSaving(true);
    setError("");
    try {
      const takenGroupIds = new Set(groups.map((g) => g.id));
      const categoryId = uniqueId(slugify(name), existingCategoryIds);
      const groupId = creatingNewGroup ? uniqueId(slugify(newGroupName), takenGroupIds) : groupChoice;

      const delta: Parameters<typeof saveOverrides>[0] = {
        newCategories: [{ id: categoryId, name: name.trim(), groupId }],
      };
      if (creatingNewGroup) {
        delta.newGroups = [{ id: groupId, name: newGroupName.trim() }];
      }

      await saveOverrides(delta);
      onCreated();
      reset();
    } catch (err) {
      if (err instanceof AdminAuthError) {
        onAuthExpired();
        return;
      }
      setError(err instanceof Error ? err.message : "Αποτυχία δημιουργίας κατηγορίας.");
    } finally {
      setSaving(false);
    }
  };

  if (!open) {
    return (
      <button
        onClick={() => setOpen(true)}
        className="mb-5 rounded-lg border border-bronze-dark px-4 py-2 text-sm font-semibold text-bronze-dark"
      >
        + Νέα κατηγορία
      </button>
    );
  }

  return (
    <div className="mb-6 rounded-xl border border-espresso/10 bg-white p-4">
      <h3 className="mb-3 text-sm font-semibold">Νέα κατηγορία</h3>
      <div className="flex flex-col gap-2.5 sm:flex-row sm:items-start">
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Όνομα κατηγορίας (π.χ. Προφιτερόλ Bueno)"
          className="flex-1 rounded-lg border border-espresso/20 px-3 py-2 text-sm"
        />
        <select
          value={groupChoice}
          onChange={(e) => setGroupChoice(e.target.value)}
          className="rounded-lg border border-espresso/20 px-3 py-2 text-sm"
        >
          {groups.map((g) => (
            <option key={g.id} value={g.id}>
              {g.name}
            </option>
          ))}
          <option value={NEW_GROUP_VALUE}>+ Νέα ενότητα</option>
        </select>
      </div>
      {creatingNewGroup && (
        <input
          value={newGroupName}
          onChange={(e) => setNewGroupName(e.target.value)}
          placeholder="Όνομα νέας ενότητας (π.χ. Εποχιακά προϊόντα)"
          className="mt-2.5 w-full rounded-lg border border-espresso/20 px-3 py-2 text-sm"
        />
      )}
      <div className="mt-3 flex items-center gap-3">
        <button
          onClick={save}
          disabled={!canSave || saving}
          className="rounded-lg border-none bg-bronze-dark px-4 py-2 text-sm font-semibold text-white disabled:opacity-40"
        >
          {saving ? "Δημιουργία…" : "Δημιουργία κατηγορίας"}
        </button>
        <button onClick={reset} className="text-sm text-espresso/60 underline">
          Άκυρο
        </button>
        {error && <span className="text-xs text-maroon">{error}</span>}
      </div>
    </div>
  );
}
