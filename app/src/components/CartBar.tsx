import { useApp } from "../AppContext";
import { fmt } from "../lib/format";

export function CartBar() {
  const { openCart, cartCount, cartTotal } = useApp();

  return (
    <button
      onClick={openCart}
      className="fixed bottom-16 left-1/2 z-25 flex w-full max-w-[448px] -translate-x-1/2 cursor-pointer items-center justify-between rounded-2xl border-none bg-espresso px-4.5 py-3.5 text-cream shadow-[0_6px_20px_rgba(30,24,18,0.25)]"
    >
      <span className="text-[13.5px] font-semibold">
        {cartCount} προϊόντα · {fmt(cartTotal)}
      </span>
      <span className="text-[13.5px] font-bold underline">Δες το καλάθι</span>
    </button>
  );
}
