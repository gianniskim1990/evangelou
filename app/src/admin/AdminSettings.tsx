import { useEffect, useState } from "react";
import { useSettings } from "../SettingsContext";
import { WEEKDAY_DISPLAY_ORDER, WEEKDAY_LABELS_EL, isValidTime } from "../lib/hours";
import type { OpeningPeriod, StoreSettings } from "../types";
import { AdminAuthError, saveSettings } from "./adminApi";

const inputClass = "w-full rounded-lg border border-espresso/20 px-3 py-2 text-sm";

export function AdminSettings({ onAuthExpired }: { onAuthExpired: () => void }) {
  const { settings, loading, refetch } = useSettings();
  const [draft, setDraft] = useState<StoreSettings | null>(null);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ kind: "ok" | "error"; text: string } | null>(null);

  useEffect(() => {
    if (!loading && draft === null) setDraft(settings);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loading]);

  if (loading || !draft) {
    return <div className="text-sm text-espresso/60">Φόρτωση…</div>;
  }

  const updatePeriod = (weekday: number, patch: Partial<OpeningPeriod>) => {
    setDraft((d) =>
      d ? { ...d, hours: d.hours.map((h) => (h.weekday === weekday ? { ...h, ...patch } : h)) } : d,
    );
  };

  const hoursValid = draft.hours.every((h) => h.isClosed || (isValidTime(h.opensAt) && isValidTime(h.closesAt)));
  const etaValid =
    Number.isFinite(draft.deliveryEtaMinMinutes) &&
    Number.isFinite(draft.deliveryEtaMaxMinutes) &&
    draft.deliveryEtaMinMinutes >= 0 &&
    draft.deliveryEtaMaxMinutes >= draft.deliveryEtaMinMinutes &&
    Number.isFinite(draft.pickupPrepMinutes) &&
    draft.pickupPrepMinutes >= 0;
  const canSave =
    draft.name.trim().length > 0 &&
    Number.isFinite(draft.deliveryMinOrder) &&
    Number.isFinite(draft.deliveryFee) &&
    hoursValid &&
    etaValid;

  const save = async () => {
    if (!canSave) return;
    setSaving(true);
    setMessage(null);
    try {
      await saveSettings(draft);
      refetch();
      setMessage({ kind: "ok", text: "Αποθηκεύτηκε." });
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
    <div className="flex flex-col gap-6">
      <section className="rounded-2xl border border-espresso/10 bg-surface p-5">
        <h2 className="mb-4 text-sm font-semibold tracking-wide text-bronze uppercase">Κατάστημα</h2>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label className="text-xs font-semibold text-espresso/60">
            Όνομα
            <input value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} className={`${inputClass} mt-1`} />
          </label>
          <label className="text-xs font-semibold text-espresso/60">
            Instagram
            <input value={draft.instagram} onChange={(e) => setDraft({ ...draft, instagram: e.target.value })} className={`${inputClass} mt-1`} />
          </label>
          <label className="text-xs font-semibold text-espresso/60 sm:col-span-2">
            Διεύθυνση
            <input value={draft.address} onChange={(e) => setDraft({ ...draft, address: e.target.value })} className={`${inputClass} mt-1`} />
          </label>
          <label className="text-xs font-semibold text-espresso/60">
            Τηλέφωνο (εμφάνιση)
            <input value={draft.phone} onChange={(e) => setDraft({ ...draft, phone: e.target.value })} className={`${inputClass} mt-1`} />
          </label>
          <label className="text-xs font-semibold text-espresso/60">
            Τηλέφωνο (tel: link, μόνο αριθμοί)
            <input
              value={draft.phoneHref}
              onChange={(e) => setDraft({ ...draft, phoneHref: e.target.value.replace(/\D/g, "") })}
              className={`${inputClass} mt-1`}
            />
          </label>
        </div>
      </section>

      <section className="rounded-2xl border border-espresso/10 bg-surface p-5">
        <h2 className="mb-4 text-sm font-semibold tracking-wide text-bronze uppercase">Delivery</h2>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label className="text-xs font-semibold text-espresso/60">
            Ελάχιστη παραγγελία (€)
            <input
              type="number"
              step="0.5"
              min="0"
              value={draft.deliveryMinOrder}
              onChange={(e) => setDraft({ ...draft, deliveryMinOrder: parseFloat(e.target.value) })}
              className={`${inputClass} mt-1`}
            />
          </label>
          <label className="text-xs font-semibold text-espresso/60">
            Μεταφορικά (€)
            <input
              type="number"
              step="0.5"
              min="0"
              value={draft.deliveryFee}
              onChange={(e) => setDraft({ ...draft, deliveryFee: parseFloat(e.target.value) })}
              className={`${inputClass} mt-1`}
            />
          </label>
        </div>
      </section>

      <section className="rounded-2xl border border-espresso/10 bg-surface p-5">
        <h2 className="mb-4 text-sm font-semibold tracking-wide text-bronze uppercase">Χρόνοι παράδοσης</h2>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <label className="text-xs font-semibold text-espresso/60">
            Delivery — από (λεπτά)
            <input
              type="number"
              step="5"
              min="0"
              value={draft.deliveryEtaMinMinutes}
              onChange={(e) => setDraft({ ...draft, deliveryEtaMinMinutes: parseInt(e.target.value, 10) })}
              className={`${inputClass} mt-1`}
            />
          </label>
          <label className="text-xs font-semibold text-espresso/60">
            Delivery — έως (λεπτά)
            <input
              type="number"
              step="5"
              min="0"
              value={draft.deliveryEtaMaxMinutes}
              onChange={(e) => setDraft({ ...draft, deliveryEtaMaxMinutes: parseInt(e.target.value, 10) })}
              className={`${inputClass} mt-1`}
            />
          </label>
          <label className="text-xs font-semibold text-espresso/60">
            Χρόνος προετοιμασίας παραλαβής (λεπτά)
            <input
              type="number"
              step="5"
              min="0"
              value={draft.pickupPrepMinutes}
              onChange={(e) => setDraft({ ...draft, pickupPrepMinutes: parseInt(e.target.value, 10) })}
              className={`${inputClass} mt-1`}
            />
          </label>
        </div>
        <p className="mt-3 text-xs text-espresso/50">
          Το "Delivery" εμφανίζεται σαν εκτιμώμενη ώρα στην επιβεβαίωση παραγγελίας. Το "Χρόνος προετοιμασίας
          παραλαβής" καθορίζει την πιο κοντινή ώρα παραλαβής που μπορεί να διαλέξει ο πελάτης από τώρα.
        </p>
      </section>

      <section className="rounded-2xl border border-espresso/10 bg-surface p-5">
        <h2 className="mb-4 text-sm font-semibold tracking-wide text-bronze uppercase">Ωράριο λειτουργίας</h2>
        <div className="flex flex-col gap-2">
          {WEEKDAY_DISPLAY_ORDER.map((weekday) => {
            const period = draft.hours.find((h) => h.weekday === weekday);
            if (!period) return null;
            return (
              <div key={weekday} className="flex flex-wrap items-center gap-2.5">
                <span className="w-24 flex-none text-sm font-semibold">{WEEKDAY_LABELS_EL[weekday]}</span>
                <label className="flex items-center gap-1.5 text-xs text-espresso/70">
                  <input
                    type="checkbox"
                    checked={period.isClosed}
                    onChange={(e) => updatePeriod(weekday, { isClosed: e.target.checked })}
                  />
                  Κλειστά
                </label>
                {!period.isClosed && (
                  <>
                    <input
                      type="time"
                      value={period.opensAt}
                      onChange={(e) => updatePeriod(weekday, { opensAt: e.target.value })}
                      className="rounded-lg border border-espresso/20 px-2 py-1.5 text-sm"
                    />
                    <span className="text-espresso/50">–</span>
                    <input
                      type="time"
                      value={period.closesAt}
                      onChange={(e) => updatePeriod(weekday, { closesAt: e.target.value })}
                      className="rounded-lg border border-espresso/20 px-2 py-1.5 text-sm"
                    />
                  </>
                )}
              </div>
            );
          })}
        </div>
      </section>

      <div className="flex items-center gap-3">
        <button
          onClick={save}
          disabled={!canSave || saving}
          className="rounded-lg border-none bg-bronze-dark px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-40"
        >
          {saving ? "Αποθήκευση…" : "Αποθήκευση ρυθμίσεων"}
        </button>
        {!hoursValid && <span className="text-xs text-maroon">Έλεγξε τις ώρες (μορφή ΩΩ:ΛΛ).</span>}
        {message && (
          <span className="text-xs" style={{ color: message.kind === "ok" ? "var(--color-bronze)" : "var(--color-maroon)" }}>
            {message.text}
          </span>
        )}
      </div>
    </div>
  );
}
