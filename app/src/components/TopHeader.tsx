import { useApp } from "../AppContext";
import { store } from "../data/menu";

export function TopHeader() {
  const { goHome, openCart, cartCount } = useApp();

  return (
    <header className="sticky top-0 z-20 flex items-center justify-between border-b border-espresso/12 bg-cream px-4 py-3.5">
      <img
        src="/logo-evaggelou-color.png"
        alt={store.name}
        className="h-9 cursor-pointer object-contain object-left"
        onClick={goHome}
      />
      <div className="flex items-center gap-4.5">
        <a
          href={`tel:${store.phoneHref}`}
          aria-label="Κλήση καταστήματος"
          className="flex items-center justify-center text-espresso no-underline"
        >
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
            <path d="M5 4h3l2 5-2 2a12 12 0 0 0 6 6l2-2 5 2v3a2 2 0 0 1-2 2C10 22 2 14 2 6a2 2 0 0 1 2-2Z" />
          </svg>
        </a>
        <button
          onClick={openCart}
          aria-label="Καλάθι"
          className="relative flex cursor-pointer items-center justify-center border-none bg-transparent p-0 text-espresso"
        >
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
            <path d="M6 8h12l-1 12H7L6 8Z" />
            <path d="M9 8a3 3 0 0 1 6 0" />
          </svg>
          {cartCount > 0 && (
            <span className="absolute -top-2 -right-2.5 flex h-[15px] min-w-[15px] items-center justify-center rounded-lg bg-bronze-dark px-[3px] text-[10px] font-bold text-white">
              {cartCount}
            </span>
          )}
        </button>
      </div>
    </header>
  );
}
