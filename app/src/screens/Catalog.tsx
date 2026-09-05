import { useEffect } from "react";
import { useApp } from "../AppContext";
import { useMenu } from "../MenuContext";
import { StockImage } from "../components/StockImage";
import { CAKE_CATEGORIES } from "../data/menu";
import { categoryImagePath, productImagePath } from "../lib/images";
import { fmt } from "../lib/format";

export function Catalog() {
  const { activeGroup, setActiveGroup, addToCart } = useApp();
  const { products, categoryNames, groups, productImages, categoryImages } = useMenu();

  useEffect(() => {
    if (!activeGroup && groups.length > 0) setActiveGroup(groups[0].id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [groups]);

  useEffect(() => {
    const onScroll = () => {
      if (groups.length === 0) return;
      let current = groups[0].id;
      for (const g of groups) {
        const el = document.getElementById(`group-${g.id}`);
        if (el && el.getBoundingClientRect().top <= 130) current = g.id;
      }
      setActiveGroup(current);
    };
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [groups]);

  const selectGroup = (id: string) => {
    setActiveGroup(id);
    const el = document.getElementById(`group-${id}`);
    if (el) window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 100, behavior: "smooth" });
  };

  return (
    <main>
      <div className="sticky top-[65px] z-15 overflow-x-auto border-b border-espresso/12 bg-cream px-3 whitespace-nowrap">
        {groups.map((g) => {
          const active = activeGroup === g.id;
          return (
            <button
              key={g.id}
              onClick={() => selectGroup(g.id)}
              className="mr-5 inline-block border-none bg-transparent py-3 text-[13px]"
              style={{
                borderBottom: `2px solid ${active ? "var(--color-bronze)" : "transparent"}`,
                fontWeight: active ? 700 : 500,
                color: active ? "var(--color-espresso)" : "color-mix(in srgb, var(--color-espresso) 40%, transparent)",
              }}
            >
              {g.name}
            </button>
          );
        })}
      </div>

      {groups.map((g) => (
        <section key={g.id} id={`group-${g.id}`} className="px-4 pt-6.5 pb-1.5">
          <h2 className="font-literata m-0 mb-4 text-[15px] font-semibold tracking-[0.14em] text-bronze uppercase">
            {g.name}
          </h2>
          {g.categories.map((catId) => (
            <div key={catId} className="mb-5.5">
              <StockImage
                src={categoryImagePath(catId, categoryImages)}
                alt={categoryNames[catId]}
                className="mb-2.5 h-28 w-full rounded-lg"
              />
              <h3 className="m-0 mb-2.5 text-base font-semibold">{categoryNames[catId]}</h3>
              <div>
                {(products[catId] ?? []).map((p) => (
                  <div
                    key={p.name}
                    className="flex items-center justify-between gap-3 border-b border-espresso/10 py-3.5"
                  >
                    <StockImage
                      src={productImagePath(p.name, productImages)}
                      alt=""
                      className="h-11 w-11 flex-none rounded-md"
                    />
                    <div className="flex-1">
                      <div className="text-[14.5px] leading-[1.3] font-semibold">{p.name}</div>
                      {p.diabetic && <span className="text-[11.5px] text-bronze-dark underline">Χωρίς ζάχαρη</span>}
                    </div>
                    <span className="font-literata text-[14.5px] font-semibold whitespace-nowrap">
                      {fmt(p.price)}
                      {p.perKilo ? "/κιλό" : ""}
                    </span>
                    <button
                      onClick={() => addToCart(p.name, p.price, "", CAKE_CATEGORIES.has(catId))}
                      aria-label="Προσθήκη"
                      className="flex h-7 w-7 flex-none items-center justify-center border-[1.5px] border-espresso bg-transparent text-espresso"
                    >
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round">
                        <path d="M12 5v14M5 12h14" />
                      </svg>
                    </button>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </section>
      ))}
      <div className="h-6" />
    </main>
  );
}
