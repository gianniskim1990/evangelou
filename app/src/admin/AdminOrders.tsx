import { useMemo, useState } from "react";
import type { OrderStatus, StoredOrder } from "../types";
import { fmt } from "../lib/format";
import { bases, chocolates, sizes, toppingGroups } from "../data/menu";
import { FULFILLMENT_LABELS_EL, ORDER_STATUS_LABELS_EL, PAYMENT_LABELS_EL, formatAthensDateTime } from "../lib/orderConstants";
import { AdminAuthError, updateOrderStatus } from "./adminApi";
import { useAdminOrderAlerts } from "./AdminOrderAlertsProvider";

const adminToppingNameById = new Map(
  toppingGroups.flatMap((group) => group.items).map((item) => [item.id, item.name]),
);

const TABS: { key: OrderStatus; label: string }[] = [
  { key: "new", label: "Νέες" },
  { key: "in_progress", label: "Σε εξέλιξη" },
  { key: "completed", label: "Ολοκληρωμένες" },
  { key: "cancelled", label: "Ακυρωμένες" },
];

const NEXT_STATUS: Partial<Record<OrderStatus, OrderStatus>> = {
  new: "in_progress",
  in_progress: "completed",
};

function AdminOrderItemDetails({ item }: { item: StoredOrder["items"][number] }) {
  const custom = item.customConfig;

  if (!custom) {
    return (
      <div className="flex justify-between gap-3 py-1.5">
        <span>
          {item.name}
          {item.meta ? ` (${item.meta})` : ""} × {item.qty}
        </span>
        <span className="flex-none font-semibold">{fmt(item.qty * item.unitPrice)}</span>
      </div>
    );
  }

  const size = sizes.find((entry) => entry.id === custom.size);
  const chocolate = chocolates.find((entry) => entry.id === custom.choc);
  const base = bases.find((entry) => entry.id === custom.base);
  const toppings = custom.toppings.map((id) => adminToppingNameById.get(id) ?? id);

  return (
    <div className="border-b border-cream py-2.5 last:border-b-0">
      <div className="flex justify-between gap-3">
        <span className="font-semibold">Το προφιτερόλ σου × {item.qty}</span>
        <span className="flex-none font-semibold">{fmt(item.qty * item.unitPrice)}</span>
      </div>
      <div className="mt-1 grid gap-0.5 text-xs text-espresso/65 sm:grid-cols-2">
        <div><span className="font-semibold text-espresso/80">Μέγεθος:</span> {size?.name ?? custom.size}</div>
        <div><span className="font-semibold text-espresso/80">Σοκολάτα:</span> {chocolate?.name ?? custom.choc}</div>
        <div><span className="font-semibold text-espresso/80">Βάση:</span> {base?.name ?? custom.base}</div>
        <div className="sm:col-span-2"><span className="font-semibold text-espresso/80">Υλικά:</span> {toppings.length ? toppings.join(", ") : "Χωρίς toppings"}</div>
      </div>
    </div>
  );
}

function matchesSearch(order: StoredOrder, query: string): boolean {
  const q = query.trim().toLowerCase();
  if (!q) return true;
  return (
    order.orderNumber.toLowerCase().includes(q) ||
    order.customer.name.toLowerCase().includes(q) ||
    order.customer.phone.toLowerCase().includes(q)
  );
}

export function AdminOrders({ onAuthExpired }: { onAuthExpired: () => void }) {
  const { orders, loading, error, refetch } = useAdminOrderAlerts();
  const [tab, setTab] = useState<OrderStatus>("new");
  const [search, setSearch] = useState("");
  const [expanded, setExpanded] = useState<string | null>(null);
  const [updating, setUpdating] = useState<string | null>(null);

  const visible = useMemo(
    () =>
      orders
        .filter((o) => o.status === tab)
        .filter((o) => matchesSearch(o, search))
        .sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime()),
    [orders, tab, search],
  );

  const counts = useMemo(() => {
    const c: Record<OrderStatus, number> = { new: 0, in_progress: 0, completed: 0, cancelled: 0 };
    for (const o of orders) c[o.status]++;
    return c;
  }, [orders]);

  const changeStatus = async (orderNumber: string, status: OrderStatus) => {
    setUpdating(orderNumber);
    try {
      await updateOrderStatus(orderNumber, status);
      refetch();
    } catch (err) {
      if (err instanceof AdminAuthError) {
        onAuthExpired();
        return;
      }
      alert(err instanceof Error ? err.message : "Αποτυχία ενημέρωσης.");
    } finally {
      setUpdating(null);
    }
  };

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center gap-2">
        {TABS.map((t) => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            className="rounded-full border px-4 py-2 text-sm font-semibold"
            style={{
              borderColor: tab === t.key ? "var(--color-bronze-dark)" : "color-mix(in srgb, var(--color-espresso) 15%, transparent)",
              background: tab === t.key ? "var(--color-bronze-dark)" : "var(--color-surface)",
              color: tab === t.key ? "#FFFFFF" : "var(--color-espresso)",
            }}
          >
            {t.label} ({counts[t.key]})
          </button>
        ))}
        <button onClick={refetch} className="ml-auto rounded-lg border border-espresso/20 px-3 py-2 text-sm">
          ↻ Ανανέωση
        </button>
      </div>

      <input
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        placeholder="Αναζήτηση με αριθμό, όνομα ή τηλέφωνο…"
        className="mb-4 w-full rounded-xl border border-espresso/20 px-3.5 py-2.5 text-sm"
      />

      {error && <div className="mb-4 rounded-xl bg-maroon/10 px-4 py-3 text-sm text-maroon">{error}</div>}
      {loading && orders.length === 0 && <div className="text-sm text-espresso/60">Φόρτωση…</div>}
      {!loading && visible.length === 0 && <div className="text-sm text-espresso/60">Καμία παραγγελία εδώ.</div>}

      <div className="flex flex-col gap-2.5">
        {visible.map((order) => {
          const isOpen = expanded === order.orderNumber;
          const next = NEXT_STATUS[order.status];
          return (
            <div key={order.orderNumber} className="overflow-hidden rounded-xl border border-espresso/10 bg-surface">
              <button
                onClick={() => setExpanded(isOpen ? null : order.orderNumber)}
                className="flex w-full items-center justify-between px-4 py-3 text-left"
              >
                <div>
                  <div className="text-sm font-semibold">
                    {order.orderNumber} · {order.customer.name}
                  </div>
                  <div className="text-xs text-espresso/60">
                    {formatAthensDateTime(order.createdAt)} · {FULFILLMENT_LABELS_EL[order.fulfillment]} ·{" "}
                    {PAYMENT_LABELS_EL[order.payment]}
                  </div>
                </div>
                <div className="flex items-center gap-3">
                  <span className="font-literata text-sm font-semibold">{fmt(order.total)}</span>
                  <span className="text-espresso/50">{isOpen ? "▲" : "▼"}</span>
                </div>
              </button>

              {isOpen && (
                <div className="border-t border-espresso/10 p-4 text-sm">
                  <div className="mb-3">
                    <div>
                      Τηλέφωνο: <a href={`tel:${order.customer.phone}`}>{order.customer.phone}</a>
                    </div>
                    {order.fulfillment === "delivery" && order.address && (
                      <div>
                        Διεύθυνση: {order.address.street}
                        {order.address.floor ? `, όροφος ${order.address.floor}` : ""}
                        {order.address.bell ? `, κουδούνι ${order.address.bell}` : ""}
                        {order.address.notes ? ` — ${order.address.notes}` : ""}
                      </div>
                    )}
                    {order.fulfillment === "pickup" && <div>Ώρα παραλαβής: {order.pickupTime || "—"}</div>}
                    {order.hasCake && (
                      <div>
                        Τούρτα: {order.cakeDateTime ? new Date(order.cakeDateTime).toLocaleString("el-GR") : "—"}
                        {order.cakeMessage ? ` · μήνυμα: «${order.cakeMessage}»` : ""} · κεράκια: {order.candles}
                      </div>
                    )}
                  </div>

                  <div className="mb-3 border-t border-cream pt-3">
                    {order.items.map((it) => (
                      <AdminOrderItemDetails key={it.id} item={it} />
                    ))}
                    <div className="mt-1 flex justify-between border-t border-cream pt-2 font-bold">
                      <span>Σύνολο</span>
                      <span>{fmt(order.total)}</span>
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    <span className="text-xs text-espresso/60">Κατάσταση: {ORDER_STATUS_LABELS_EL[order.status]}</span>
                    {next && (
                      <button
                        onClick={() => changeStatus(order.orderNumber, next)}
                        disabled={updating === order.orderNumber}
                        className="rounded-lg border-none bg-bronze-dark px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
                      >
                        Μετάβαση σε: {ORDER_STATUS_LABELS_EL[next]}
                      </button>
                    )}
                    {order.status !== "cancelled" && order.status !== "completed" && (
                      <button
                        onClick={() => changeStatus(order.orderNumber, "cancelled")}
                        disabled={updating === order.orderNumber}
                        className="rounded-lg border border-maroon px-3 py-1.5 text-xs font-semibold text-maroon disabled:opacity-50"
                      >
                        Ακύρωση
                      </button>
                    )}
                  </div>
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}
