import { useApp } from "../AppContext";
import { useSettings } from "../SettingsContext";
import { fmt } from "../lib/format";

export function Confirmation() {
  const { order, goStatus } = useApp();
  const { settings } = useSettings();

  if (!order) return null;

  const isDelivery = order.fulfillment === "delivery";
  let etaLabel = "Εκτιμώμενη ώρα";
  let etaText = isDelivery
    ? `${settings.deliveryEtaMinMinutes}–${settings.deliveryEtaMaxMinutes} λεπτά`
    : order.pickupTime || "—";
  if (order.hasCake && order.cakeDateTime) {
    etaLabel = "Παραλαβή τούρτας";
    etaText = new Date(order.cakeDateTime).toLocaleString("el-GR", {
      day: "2-digit",
      month: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
    });
  }

  return (
    <main className="px-5 pt-10 pb-10 text-center">
      <div className="mx-auto mb-4.5 flex h-14 w-14 items-center justify-center rounded-full bg-bronze">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M4 12l5 5L20 6" />
        </svg>
      </div>
      <h2 className="font-literata mb-1.5 text-[21px] font-semibold">Η παραγγελία σου καταχωρήθηκε</h2>
      <div className="mb-5.5 text-sm opacity-70">Αριθμός παραγγελίας {order.orderNumber}</div>

      <div className="mb-4.5 rounded-[14px] bg-white p-4 text-left">
        {order.items.map((it) => (
          <div key={it.id} className="flex justify-between py-1.5 text-sm">
            <span>
              {it.name}
              {it.qty > 1 ? ` ×${it.qty}` : ""}
            </span>
            <span className="font-semibold">{fmt(it.qty * it.unitPrice)}</span>
          </div>
        ))}
        <div className="mt-1.5 flex justify-between border-t border-cream pt-2.5 font-bold">
          <span>Σύνολο</span>
          <span>{fmt(order.total)}</span>
        </div>
      </div>

      <div className="mb-6.5 text-sm opacity-75">
        {etaLabel}: <strong className="text-espresso opacity-100">{etaText}</strong>
        <br />
        Για οποιαδήποτε ερώτηση:{" "}
        <a href={`tel:${settings.phoneHref}`}>{settings.phone}</a>
      </div>

      <button
        onClick={goStatus}
        className="w-full rounded-xl border-none bg-bronze-dark py-3.5 text-[15px] font-semibold text-white"
      >
        Δες την κατάσταση παραγγελίας
      </button>
    </main>
  );
}
