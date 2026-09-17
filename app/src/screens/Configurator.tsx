import { useApp } from "../AppContext";
import { ProfiterolePreview } from "../configurator/ProfiterolePreview";
import { FREE_TOPPINGS, TOPPING_EXTRA_PRICE, bases, chocolates, sizes, toppingGroups } from "../data/menu";
import { fmt } from "../lib/format";

const STEP_TITLES: Record<number, string> = {
  1: "Διάλεξε μέγεθος",
  2: "Διάλεξε σοκολάτα",
  3: "Διάλεξε βάση",
  4: "Υλικά & toppings",
  5: "Η σύνοψη σου",
};

export function Configurator() {
  const {
    cfg,
    cfgTotal,
    cfgCanProceed,
    selectSize,
    selectChoc,
    selectBase,
    toggleTopping,
    cfgNext,
    cfgPrev,
    addConfiguredToCart,
    isEditingConfiguredItem,
  } = useApp();

  const step = cfg.step;
  const sizeObj = sizes.find((s) => s.id === cfg.size) ?? null;
  const chocObj = chocolates.find((c) => c.id === cfg.choc) ?? null;
  const baseObj = bases.find((b) => b.id === cfg.base) ?? null;

  // Chilly still uses its clean family hero on the base-selection step.
  // Lotus now has a complete chocolate-specific image family, so it keeps
  // the customer's selected chocolate visible immediately.
  const previewCfg =
    step === 3 && cfg.base === "chilly"
      ? { ...cfg, choc: null }
      : cfg;

  const toppingNames = cfg.toppings.length
    ? cfg.toppings
        .map((tid) => toppingGroups.flatMap((g) => g.items).find((it) => it.id === tid)?.name)
        .join(", ")
    : "χωρίς υλικά";

  return (
    <main className="px-4 pt-4.5 pb-6">
      <div className="mb-5.5 flex items-center gap-5">
        <span className="font-literata flex-none text-[22px] font-bold">{step}/5</span>
        <div className="flex flex-1 gap-1.5">
          {[1, 2, 3, 4, 5].map((n) => (
            <div key={n} className="h-0.5 flex-1" style={{ background: n <= step ? "var(--color-bronze)" : "var(--color-hairline)" }} />
          ))}
        </div>
      </div>
      <h2 className="font-literata mb-4.5 text-[22px] font-semibold">{STEP_TITLES[step]}</h2>

      {isEditingConfiguredItem && (
        <div className="mx-auto mb-3 w-fit rounded-full bg-bronze/10 px-3 py-1 text-[12px] font-semibold text-bronze-dark">
          Επεξεργάζεσαι προϊόν από το καλάθι
        </div>
      )}

      <ProfiterolePreview cfg={previewCfg} variant={step === 5 ? "hero" : "compact"} />

      {step === 1 && (
        <div className="flex flex-col">
          {sizes.map((s) => {
            const sel = cfg.size === s.id;
            return (
              <button
                key={s.id}
                onClick={() => selectSize(s.id)}
                className="flex items-center justify-between border-none px-3.5 py-4 text-left"
                style={{
                  borderBottom: `1px solid ${sel ? "transparent" : "color-mix(in srgb, var(--color-espresso) 12%, transparent)"}`,
                  background: sel ? "var(--color-bronze-dark)" : "var(--color-surface)",
                  color: sel ? "#FFFFFF" : "var(--color-espresso)",
                }}
              >
                <span className="text-[15px] font-semibold">{s.name}</span>
                <span className="font-literata text-[15px] font-semibold" style={{ color: sel ? "#FFFFFF" : "var(--color-bronze-dark)" }}>
                  {fmt(s.price)}
                </span>
              </button>
            );
          })}
        </div>
      )}

      {step === 2 && (
        <div className="flex flex-col">
          {chocolates.map((c) => {
            const sel = cfg.choc === c.id;
            return (
              <button
                key={c.id}
                onClick={() => selectChoc(c.id)}
                className="border-none px-3.5 py-3.5 text-left"
                style={{
                  borderBottom: `1px solid ${sel ? "transparent" : "color-mix(in srgb, var(--color-espresso) 12%, transparent)"}`,
                  background: sel ? "var(--color-bronze-dark)" : "var(--color-surface)",
                }}
              >
                <div className="mb-0.5 text-[15px] font-semibold" style={{ color: sel ? "#FFFFFF" : "var(--color-espresso)" }}>
                  {c.name}
                </div>
                <div className="text-[12.5px]" style={{ color: sel ? "rgba(255,255,255,0.75)" : "color-mix(in srgb, var(--color-espresso) 60%, transparent)" }}>
                  {c.desc}
                </div>
              </button>
            );
          })}
        </div>
      )}

      {step === 3 && (
        <div className="flex flex-col">
          {bases.map((b) => {
            const sel = cfg.base === b.id;
            return (
              <button
                key={b.id}
                onClick={() => selectBase(b.id)}
                className="flex items-center justify-between border-none px-3.5 py-4 text-left"
                style={{
                  borderBottom: `1px solid ${sel ? "transparent" : "color-mix(in srgb, var(--color-espresso) 12%, transparent)"}`,
                  background: sel ? "var(--color-bronze-dark)" : "var(--color-surface)",
                  color: sel ? "#FFFFFF" : "var(--color-espresso)",
                }}
              >
                <span className="text-[15px] font-semibold">{b.name}</span>
                <span className="text-[13px] font-semibold" style={{ color: sel ? "#FFFFFF" : "var(--color-bronze-dark)" }}>
                  {b.extra > 0 ? "+" + fmt(b.extra) : "χωρίς χρέωση"}
                </span>
              </button>
            );
          })}
        </div>
      )}

      {step === 4 && (
        <>
          <div className="mb-3.5 text-[13px] text-espresso/70">
            Τα πρώτα {FREE_TOPPINGS} είναι δωρεάν, τα υπόλοιπα με {fmt(TOPPING_EXTRA_PRICE)} το καθένα. Έχεις επιλέξει{" "}
            {cfg.toppings.length}.
          </div>
          {toppingGroups.map((grp) => (
            <div key={grp.id} className="mb-4">
              <h4 className="m-0 mb-2 text-[13px] font-semibold text-bronze">{grp.name}</h4>
              <div className="flex flex-wrap gap-2">
                {grp.items.map((it) => {
                  const selected = cfg.toppings.includes(it.id);
                  return (
                    <button
                      key={it.id}
                      onClick={() => toggleTopping(it.id)}
                      className="rounded-full border-[1.5px] px-3.5 py-2 text-[13px] font-semibold"
                      style={{
                        borderColor: selected ? "var(--color-bronze-dark)" : "var(--color-hairline)",
                        background: selected ? "var(--color-bronze-dark)" : "var(--color-surface)",
                        color: selected ? "#FFFFFF" : "var(--color-espresso)",
                      }}
                    >
                      {it.name}
                    </button>
                  );
                })}
              </div>
            </div>
          ))}
        </>
      )}

      {step === 5 && (
        <>
          <div className="mb-4.5 rounded-[14px] bg-surface p-4">
            <div className="flex justify-between border-b border-cream py-1.5">
              <span className="opacity-65">Μέγεθος</span>
              <span className="font-semibold">{sizeObj?.name ?? "—"}</span>
            </div>
            <div className="flex justify-between border-b border-cream py-1.5">
              <span className="opacity-65">Σοκολάτα</span>
              <span className="font-semibold">{chocObj?.name ?? "—"}</span>
            </div>
            <div className="flex justify-between border-b border-cream py-1.5">
              <span className="opacity-65">Βάση</span>
              <span className="font-semibold">{baseObj?.name ?? "—"}</span>
            </div>
            <div className="flex justify-between py-1.5">
              <span className="opacity-65">Υλικά</span>
              <span className="max-w-[60%] text-right font-semibold">{toppingNames}</span>
            </div>
          </div>
          <div className="mb-4 flex items-baseline justify-between">
            <span className="text-[15px] font-semibold">Σύνολο</span>
            <span className="font-literata text-[22px] font-bold">{fmt(cfgTotal)}</span>
          </div>
        </>
      )}

      <div className="mt-5.5 flex gap-2.5">
        {step > 1 && (
          <button
            onClick={cfgPrev}
            className="flex-none w-[90px] rounded-xl border border-bronze bg-surface py-3.5 text-sm font-semibold text-espresso"
          >
            Πίσω
          </button>
        )}
        {step === 5 ? (
          <button
            onClick={addConfiguredToCart}
            className="flex-1 rounded-xl border-none bg-bronze-dark py-3.5 text-[15px] font-semibold text-white"
          >
            {isEditingConfiguredItem ? "Αποθήκευση αλλαγών" : "Προσθήκη στο καλάθι"}
          </button>
        ) : (
          <button
            onClick={cfgNext}
            disabled={!cfgCanProceed}
            className="flex-1 rounded-xl border-none py-3.5 text-[15px] font-semibold text-white"
            style={{ background: cfgCanProceed ? "var(--color-bronze-dark)" : "var(--color-disabled)" }}
          >
            Επόμενο
          </button>
        )}
      </div>
    </main>
  );
}
