import { useApp } from "../AppContext";
import { fmt } from "../lib/format";

export function CartDrawer() {
  const { cartOpen, closeCart, cart, cartTotal, incCartItem, decCartItem, goCatalog, goConfigurator, goCheckout } = useApp();

  if (!cartOpen) return null;

  return (
    <>
      <div onClick={closeCart} className="fixed inset-0 z-29 bg-espresso/45" />
      <div className="fixed bottom-0 left-1/2 z-30 flex max-h-[78vh] w-full max-w-[480px] -translate-x-1/2 flex-col rounded-t-[20px] bg-surface shadow-[0_-8px_30px_rgba(30,24,18,0.25)]">
        <div className="flex items-center justify-between border-b border-cream px-4.5 pt-4 pb-2.5">
          <h2 className="m-0 font-literata text-lg font-semibold">Το καλάθι σου</h2>
          <button
            onClick={closeCart}
            aria-label="Κλείσιμο"
            className="flex h-[30px] w-[30px] items-center justify-center rounded-full border-none bg-cream text-espresso"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
              <path d="M6 6l12 12M18 6 6 18" />
            </svg>
          </button>
        </div>

        <div className="flex-1 overflow-y-auto px-4.5 py-2">
          {cart.length === 0 && (
            <div className="px-2.5 py-7.5 text-center">
              <div className="mb-4.5 text-sm opacity-70">
                Το καλάθι σου είναι άδειο. Πρόσθεσε κάτι από τον κατάλογο ή φτιάξε το δικό σου προφιτερόλ.
              </div>
              <div className="flex gap-2.5">
                <button
                  onClick={() => {
                    closeCart();
                    goCatalog();
                  }}
                  className="flex-1 rounded-[10px] border border-bronze bg-surface py-2.5 text-[13.5px] font-semibold"
                >
                  Δες τον κατάλογο
                </button>
                <button
                  onClick={() => {
                    closeCart();
                    goConfigurator();
                  }}
                  className="flex-1 rounded-[10px] border-none bg-bronze-dark py-2.5 text-[13.5px] font-semibold text-white"
                >
                  Φτιάξε προφιτερόλ
                </button>
              </div>
            </div>
          )}

          {cart.map((it) => (
            <div key={it.id} className="flex items-center justify-between border-b border-cream py-3">
              <div className="flex-1 pr-2.5">
                <div className="text-sm font-semibold">{it.name}</div>
                {it.meta && <div className="mt-0.5 text-xs opacity-60">{it.meta}</div>}
                <div className="mt-2 flex items-center gap-2.5">
                  <button
                    onClick={() => decCartItem(it.id)}
                    className="flex h-6 w-6 items-center justify-center rounded-full border border-bronze bg-surface text-[13px] leading-none"
                  >
                    −
                  </button>
                  <span className="min-w-3.5 text-center text-[13px] font-semibold">{it.qty}</span>
                  <button
                    onClick={() => incCartItem(it.id)}
                    className="flex h-6 w-6 items-center justify-center rounded-full border border-bronze bg-surface text-[13px] leading-none"
                  >
                    +
                  </button>
                </div>
              </div>
              <span className="text-sm font-semibold">{fmt(it.qty * it.unitPrice)}</span>
            </div>
          ))}
        </div>

        {cart.length > 0 && (
          <div className="border-t border-cream px-4.5 pt-3.5 pb-5.5">
            <div className="mb-3.5 flex justify-between text-[15px] font-bold">
              <span>Σύνολο</span>
              <span className="font-literata">{fmt(cartTotal)}</span>
            </div>
            <button
              onClick={goCheckout}
              className="w-full rounded-xl border-none bg-bronze-dark py-3.5 text-[15px] font-semibold text-white"
            >
              Ολοκλήρωση παραγγελίας
            </button>
          </div>
        )}
      </div>
    </>
  );
}
