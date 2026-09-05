import { useEffect, useState } from "react";
import { fmt } from "../lib/format";
import { AdminAuthError, fetchAnalytics, type AdminAnalytics } from "./adminApi";

const ALLOWED_DAYS = [1, 7, 30, 90] as const;
const RANGE_LABELS: Record<(typeof ALLOWED_DAYS)[number], string> = {
  1: "Σήμερα",
  7: "7 ημέρες",
  30: "30 ημέρες",
  90: "90 ημέρες",
};

const cardClass = "rounded-2xl border border-espresso/10 bg-surface p-5";

export function AdminStatistics({ onAuthExpired }: { onAuthExpired: () => void }) {
  const [days, setDays] = useState<(typeof ALLOWED_DAYS)[number]>(30);
  const [data, setData] = useState<AdminAnalytics | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError("");
    fetchAnalytics(days)
      .then((d) => {
        if (!cancelled) setData(d);
      })
      .catch((err) => {
        if (cancelled) return;
        if (err instanceof AdminAuthError) {
          onAuthExpired();
          return;
        }
        setError(err instanceof Error ? err.message : "Αποτυχία φόρτωσης στατιστικών.");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [days, onAuthExpired]);

  const maxQty = data?.topProducts.reduce((m, p) => Math.max(m, p.qty), 0) ?? 0;

  return (
    <div>
      <div className="mb-5 flex flex-wrap gap-2">
        {ALLOWED_DAYS.map((d) => (
          <button
            key={d}
            onClick={() => setDays(d)}
            className="rounded-full border px-4 py-2 text-sm font-semibold"
            style={{
              borderColor: days === d ? "var(--color-bronze-dark)" : "color-mix(in srgb, var(--color-espresso) 15%, transparent)",
              background: days === d ? "var(--color-bronze-dark)" : "var(--color-surface)",
              color: days === d ? "#FFFFFF" : "var(--color-espresso)",
            }}
          >
            {RANGE_LABELS[d]}
          </button>
        ))}
      </div>

      {error && <div className="mb-4 rounded-xl bg-maroon/10 px-4 py-3 text-sm text-maroon">{error}</div>}
      {loading && <div className="text-sm text-espresso/60">Φόρτωση…</div>}

      {data && (
        <>
          <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div className={cardClass}>
              <div className="text-xs text-espresso/60">Παραγγελίες</div>
              <div className="font-literata text-2xl font-semibold">{data.totalOrders}</div>
            </div>
            <div className={cardClass}>
              <div className="text-xs text-espresso/60">Αξία παραγγελιών</div>
              <div className="font-literata text-2xl font-semibold">{fmt(data.totalValue)}</div>
            </div>
            <div className={cardClass}>
              <div className="text-xs text-espresso/60">Ολοκληρωμένες</div>
              <div className="font-literata text-2xl font-semibold">{data.completedCount}</div>
            </div>
            <div className={cardClass}>
              <div className="text-xs text-espresso/60">Ακυρωμένες</div>
              <div className="font-literata text-2xl font-semibold">{data.byStatus.cancelled ?? 0}</div>
            </div>
          </div>

          <div className={cardClass}>
            <h3 className="mb-4 text-sm font-semibold">Δημοφιλέστερα προϊόντα</h3>
            {data.topProducts.length === 0 && <div className="text-sm text-espresso/60">Δεν υπάρχουν ακόμα δεδομένα.</div>}
            <div className="flex flex-col gap-2.5">
              {data.topProducts.map((p) => (
                <div key={p.name} className="flex items-center gap-3">
                  <span className="w-40 flex-none truncate text-sm">{p.name}</span>
                  <div className="h-4 flex-1 overflow-hidden rounded-full bg-cream">
                    <div
                      className="h-full rounded-full bg-bronze"
                      style={{ width: `${maxQty ? (p.qty / maxQty) * 100 : 0}%` }}
                    />
                  </div>
                  <span className="w-8 flex-none text-right text-sm font-semibold">{p.qty}</span>
                </div>
              ))}
            </div>
          </div>
          <p className="mt-4 text-xs text-espresso/50">
            Επιχειρησιακά στοιχεία παραγγελιών — δεν αποτελεί λογιστική/φορολογική αναφορά.
          </p>
        </>
      )}
    </div>
  );
}
