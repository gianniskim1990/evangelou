import { useMemo, useState } from "react";
import { useApp } from "../AppContext";
import { StockImage } from "../components/StockImage";
import { categoryNames, groups, offers, popular, products, store } from "../data/menu";
import { productImagePath } from "../lib/images";
import { fmt, isStoreClosedNow } from "../lib/format";

const POPULAR_ID = "__popular__";
const OFFERS_ID = "__offers__";

interface Card {
  key: string;
  name: string;
  price: number;
  was?: number;
  perKilo?: boolean;
  diabetic?: boolean;
  badge: string;
  badgeTone: "bronze" | "maroon";
  onAdd: () => void;
}

export function DesktopHome() {
  const { addToCart, fulfillment, setFulfillment, goConfigurator, openCart, cartCount } = useApp();
  const [selected, setSelected] = useState<string>(POPULAR_ID);
  const [query, setQuery] = useState("");

  const closedNow = isStoreClosedNow(store.hours);

  const allProducts = useMemo(
    () =>
      Object.entries(products).flatMap(([catId, list]) =>
        list.map((p) => ({ ...p, catId, key: `${catId}:${p.name}` })),
      ),
    [],
  );

  const searching = query.trim().length > 0;

  const cards: Card[] = useMemo(() => {
    if (searching) {
      const q = query.trim().toLowerCase();
      return allProducts
        .filter((p) => p.name.toLowerCase().includes(q))
        .map((p) => ({
          key: p.key,
          name: p.name,
          price: p.price,
          perKilo: p.perKilo,
          diabetic: p.diabetic,
          badge: categoryNames[p.catId],
          badgeTone: "bronze",
          onAdd: () => addToCart(p.name, p.price),
        }));
    }
    if (selected === POPULAR_ID) {
      return popular.map((p) => ({
        key: p.name,
        name: p.name,
        price: p.price,
        badge: "Δημοφιλές",
        badgeTone: "bronze",
        onAdd: () => addToCart(p.name, p.price),
      }));
    }
    if (selected === OFFERS_ID) {
      return offers.map((o) => ({
        key: o.name,
        name: o.name,
        price: o.price,
        was: o.was,
        perKilo: o.perKilo,
        badge: "Προσφορά",
        badgeTone: "maroon",
        onAdd: () => addToCart(o.name, o.price),
      }));
    }
    const group = groups.find((g) => g.id === selected);
    if (!group) return [];
    return group.categories.flatMap((catId) =>
      products[catId].map((p) => ({
        key: `${catId}:${p.name}`,
        name: p.name,
        price: p.price,
        perKilo: p.perKilo,
        diabetic: p.diabetic,
        badge: categoryNames[catId],
        badgeTone: "bronze" as const,
        onAdd: () => addToCart(p.name, p.price, "", catId === "cakes" || catId === "icecream_cakes"),
      })),
    );
  }, [selected, searching, query, allProducts, addToCart]);

  const heading = searching
    ? `Αποτελέσματα για «${query.trim()}»`
    : selected === POPULAR_ID
      ? "Δημοφιλέστερα"
      : selected === OFFERS_ID
        ? "Προσφορές"
        : (groups.find((g) => g.id === selected)?.name ?? "");

  return (
    <div className="mx-auto flex min-h-screen max-w-[1280px] gap-8 px-8 py-8">
      <aside className="w-[280px] flex-none">
        <div className="mb-5 flex items-center justify-between">
          <img src="/logo-evaggelou-color.png" alt={store.name} className="h-10 object-contain object-left" />
          <button
            onClick={openCart}
            aria-label="Καλάθι"
            className="relative flex h-9 w-9 items-center justify-center rounded-full border border-espresso/15 bg-white"
          >
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
              <path d="M6 8h12l-1 12H7L6 8Z" />
              <path d="M9 8a3 3 0 0 1 6 0" />
            </svg>
            {cartCount > 0 && (
              <span className="absolute -top-1.5 -right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-bronze-dark px-1 text-[10px] font-bold text-white">
                {cartCount}
              </span>
            )}
          </button>
        </div>

        <div className="mb-5 text-[13px] font-semibold" style={{ color: closedNow ? "rgba(30,24,18,0.45)" : "#86764F" }}>
          {closedNow ? "Κλειστά τώρα" : `Ανοιχτά · ${store.hours}`}
        </div>

        <div className="mb-4 flex gap-2">
          <button
            onClick={() => setFulfillment("delivery")}
            className="flex-1 rounded-full border py-2 text-[13px] font-semibold"
            style={{
              borderColor: fulfillment === "delivery" ? "#5F5335" : "rgba(30,24,18,0.15)",
              background: fulfillment === "delivery" ? "#5F5335" : "#FFFFFF",
              color: fulfillment === "delivery" ? "#FFFFFF" : "#1E1812",
            }}
          >
            Delivery
          </button>
          <button
            onClick={() => setFulfillment("pickup")}
            className="flex-1 rounded-full border py-2 text-[13px] font-semibold"
            style={{
              borderColor: fulfillment === "pickup" ? "#5F5335" : "rgba(30,24,18,0.15)",
              background: fulfillment === "pickup" ? "#5F5335" : "#FFFFFF",
              color: fulfillment === "pickup" ? "#FFFFFF" : "#1E1812",
            }}
          >
            Παραλαβή
          </button>
        </div>

        <div className="mb-2 text-[13px] text-espresso/70">{store.address}</div>

        <input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Τι θα ήθελες να φας;"
          className="mb-5 w-full rounded-xl border border-espresso/15 bg-white px-3.5 py-2.5 text-sm"
        />

        <button
          onClick={goConfigurator}
          className="mb-6 w-full rounded-xl border-none bg-espresso px-4 py-3.5 text-left text-sm font-bold text-cream"
        >
          Φτιάξε το προφιτερόλ σου
        </button>

        <nav className="flex flex-col gap-1.5">
          <button
            onClick={() => {
              setSelected(POPULAR_ID);
              setQuery("");
            }}
            className="rounded-xl px-3.5 py-2.5 text-left text-sm font-semibold"
            style={{
              background: !searching && selected === POPULAR_ID ? "#1E1812" : "transparent",
              color: !searching && selected === POPULAR_ID ? "#FFFFFF" : "#1E1812",
            }}
          >
            Δημοφιλέστερα
          </button>
          <button
            onClick={() => {
              setSelected(OFFERS_ID);
              setQuery("");
            }}
            className="rounded-xl px-3.5 py-2.5 text-left text-sm font-semibold"
            style={{
              background: !searching && selected === OFFERS_ID ? "#1E1812" : "transparent",
              color: !searching && selected === OFFERS_ID ? "#FFFFFF" : "#1E1812",
            }}
          >
            Προσφορές
          </button>
          <div className="my-2 border-t border-espresso/10" />
          {groups.map((g) => (
            <button
              key={g.id}
              onClick={() => {
                setSelected(g.id);
                setQuery("");
              }}
              className="rounded-xl px-3.5 py-2.5 text-left text-sm font-semibold"
              style={{
                background: !searching && selected === g.id ? "#1E1812" : "transparent",
                color: !searching && selected === g.id ? "#FFFFFF" : "#1E1812",
              }}
            >
              {g.name}
            </button>
          ))}
        </nav>
      </aside>

      <main className="min-w-0 flex-1">
        <h1 className="font-literata mb-6 text-[13px] font-semibold tracking-[0.14em] text-bronze uppercase">
          {heading}
        </h1>

        {cards.length === 0 && (
          <div className="text-sm text-espresso/60">Δεν βρέθηκαν προϊόντα.</div>
        )}

        <div className="grid grid-cols-2 gap-5 xl:grid-cols-3">
          {cards.map((c) => (
            <div key={c.key} className="overflow-hidden rounded-2xl bg-white shadow-[0_4px_18px_rgba(30,24,18,0.08)]">
              <div className="relative">
                <StockImage src={productImagePath(c.name)} alt={c.name} className="h-40 w-full" />
                <span
                  className="absolute top-3 left-3 rounded-full px-2.5 py-1 text-[11px] font-semibold text-white"
                  style={{ background: c.badgeTone === "maroon" ? "#7A2E3B" : "#1E1812" }}
                >
                  {c.badge}
                </span>
              </div>
              <div className="p-4">
                <div className="mb-1 text-[14.5px] leading-[1.3] font-semibold">{c.name}</div>
                {c.diabetic && <span className="mb-1 block text-[11.5px] text-bronze-dark underline">Χωρίς ζάχαρη</span>}
                <div className="mt-2 flex items-center justify-between">
                  <div className="flex items-baseline gap-1.5">
                    <span className="font-literata text-base font-semibold">
                      {fmt(c.price)}
                      {c.perKilo ? "/κιλό" : ""}
                    </span>
                    {c.was !== undefined && (
                      <span className="text-xs text-espresso/45 line-through">{fmt(c.was)}</span>
                    )}
                  </div>
                  <button
                    onClick={c.onAdd}
                    className="flex h-8 w-8 items-center justify-center rounded-full bg-espresso text-white"
                    aria-label="Προσθήκη"
                  >
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round">
                      <path d="M12 5v14M5 12h14" />
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      </main>
    </div>
  );
}
