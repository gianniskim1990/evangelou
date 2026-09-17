import { useApp } from "../AppContext";
import { useSettings } from "../SettingsContext";
import { ProfiterolePreview } from "../configurator/ProfiterolePreview";
import { bases, chocolates, sizes, toppingGroups } from "../data/menu";
import { fmt } from "../lib/format";
import type { CartItem, ConfiguratorState } from "../types";

const confirmationToppingNameById = new Map(
  toppingGroups.flatMap((group) => group.items).map((item) => [item.id, item.name]),
);

function ConfirmationOrderItem({ item }: { item: CartItem }) {
  const custom = item.customConfig;

  if (!custom) {
    return (
      <div className="flex items-start justify-between gap-3 border-b border-cream py-2.5 last:border-b-0">
        <div className="min-w-0 flex-1">
          <div className="text-sm font-semibold">
            {item.name}{item.qty > 1 ? ` ×${item.qty}` : ""}
          </div>
          {item.meta && <div className="mt-0.5 text-[11.5px] text-espresso/55">{item.meta}</div>}
        </div>
        <span className="flex-none text-sm font-semibold">{fmt(item.qty * item.unitPrice)}</span>
      </div>
    );
  }

  const size = sizes.find((entry) => entry.id === custom.size);
  const chocolate = chocolates.find((entry) => entry.id === custom.choc);
  const base = bases.find((entry) => entry.id === custom.base);
  const toppings = custom.toppings.map((id) => confirmationToppingNameById.get(id) ?? id);

  const previewCfg: ConfiguratorState = {
    step: 5,
    size: custom.size,
    choc: custom.choc,
    base: custom.base,
    toppings: [...custom.toppings],
  };

  return (
    <div className="border-b border-cream py-4 last:border-b-0">
      <div className="mb-7 text-center">
        <div className="font-literata text-[16px] font-semibold">Η δημιουργία σου</div>
        {item.qty > 1 && <div className="mt-0.5 text-xs text-espresso/55">Ποσότητα: {item.qty}</div>}
      </div>

      <ProfiterolePreview cfg={previewCfg} variant="compact" />

      <div className="rounded-xl bg-cream/55 px-3.5 py-3 text-left">
        <div className="mb-2 flex items-start justify-between gap-3">
          <span className="text-[13px] font-semibold">Το προφιτερόλ σου</span>
          <span className="flex-none text-[13px] font-semibold">{fmt(item.qty * item.unitPrice)}</span>
        </div>
        <div className="space-y-0.5 text-[11.5px] leading-[1.45] text-espresso/65">
          <div><span className="font-semibold text-espresso/85">Μέγεθος:</span> {size?.name ?? custom.size}</div>
          <div><span className="font-semibold text-espresso/85">Σοκολάτα:</span> {chocolate?.name ?? custom.choc}</div>
          <div><span className="font-semibold text-espresso/85">Βάση:</span> {base?.name ?? custom.base}</div>
          <div><span className="font-semibold text-espresso/85">Υλικά:</span> {toppings.length ? toppings.join(", ") : "Χωρίς toppings"}</div>
        </div>
      </div>
    </div>
  );
}

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

      <div className="mb-4.5 rounded-[14px] bg-surface p-4 text-left">
        {order.items.map((it) => (
          <ConfirmationOrderItem key={it.id} item={it} />
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
