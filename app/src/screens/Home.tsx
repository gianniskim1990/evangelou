import { useApp } from "../AppContext";
import { useMenu } from "../MenuContext";
import { useSettings } from "../SettingsContext";
import { StockImage } from "../components/StockImage";
import { offers, popular } from "../data/menu";
import { formatHoursSummary } from "../lib/hours";
import { productImagePath } from "../lib/images";
import { fmt } from "../lib/format";

export function Home() {
  const { goConfigurator, goCatalog, addToCart } = useApp();
  const { settings } = useSettings();
  const { productImages } = useMenu();

  return (
    <main>
      <section className="relative rounded-br-[32px] bg-espresso px-5 pt-9 pb-10">
        <div className="mb-2.5 text-[13px] font-semibold tracking-[0.02em] text-bronze">Ο δημιουργός προφιτερόλ</div>
        <h1 className="mb-3.5 max-w-[260px] font-literata text-[30px] leading-[1.15] font-medium text-cream">
          Φτιάξε το
          <br />
          <span className="font-bold">προφιτερόλ σου.</span>
        </h1>
        <div className="mb-6.5 max-w-[250px] text-[14.5px] text-cream/70">
          Μία από τις 7 σοκολάτες. Η βάση σου. Όσα υλικά θέλεις.
        </div>
        <button
          onClick={goConfigurator}
          className="rounded-sm border-none bg-cream px-6 py-3.5 text-[14.5px] font-bold text-espresso"
        >
          Φτιάξε το προφιτερόλ σου
        </button>
        <div className="absolute right-7 bottom-8 h-11.5 w-11.5 rounded-full border-[1.5px] border-bronze opacity-60" />
      </section>

      <section className="pt-7.5 pb-1">
        <div className="flex items-baseline justify-between px-4 pb-4">
          <h2 className="m-0 font-literata text-xl font-medium italic">Προσφορές</h2>
        </div>
        <div className="flex gap-5 overflow-x-auto px-4 pb-1.5">
          {offers.map((o) => (
            <div key={o.name} className="flex-none w-[168px] border-t-2 border-maroon pt-3">
              <StockImage src={productImagePath(o.name, productImages)} alt={o.name} className="mb-3 h-28 w-full" />
              <span className="mb-2.5 block text-[11.5px] font-semibold text-maroon underline">Προσφορά</span>
              <div className="mb-3 min-h-9.5 text-[14.5px] leading-[1.3] font-semibold">{o.name}</div>
              <div className="mb-3 flex items-baseline gap-1.5">
                <span className="font-literata text-[17px] font-semibold">
                  {fmt(o.price)}
                  {o.perKilo ? "/κιλό" : ""}
                </span>
                <span className="text-xs text-espresso/45 line-through">{fmt(o.was)}</span>
              </div>
              <button
                onClick={() => addToCart(o.name, o.price)}
                className="flex h-8 w-8 items-center justify-center border-[1.5px] border-espresso bg-transparent text-espresso"
              >
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                  <path d="M12 5v14M5 12h14" />
                </svg>
              </button>
            </div>
          ))}
        </div>
      </section>

      <section className="mt-3.5 bg-bronze/10 px-4 pt-7.5 pb-8.5">
        <h2 className="m-0 mb-4 font-literata text-xl font-medium italic">Δημοφιλέστερα</h2>
        <div className="grid grid-cols-2">
          {popular.map((p) => (
            <div key={p.name} className="border-t border-espresso/18 py-3 pr-3">
              <StockImage src={productImagePath(p.name, productImages)} alt={p.name} className="mb-2.5 h-20 w-full" />
              <div className="mb-2.5 min-h-8.5 text-[13.5px] leading-[1.3] font-semibold">{p.name}</div>
              <div className="flex items-center justify-between">
                <span className="font-literata text-[15px] font-semibold">{fmt(p.price)}</span>
                <button
                  onClick={() => addToCart(p.name, p.price)}
                  className="flex h-6.5 w-6.5 items-center justify-center border-[1.5px] border-espresso bg-transparent text-espresso"
                >
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round">
                    <path d="M12 5v14M5 12h14" />
                  </svg>
                </button>
              </div>
            </div>
          ))}
        </div>
      </section>

      <button
        onClick={goCatalog}
        className="flex w-full items-center justify-between border-none bg-bronze-dark px-4 py-5 text-[15px] font-bold text-white"
      >
        <span>Δες όλο τον κατάλογο</span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M9 5l7 7-7 7" />
        </svg>
      </button>

      <footer className="border-t-[3px] border-bronze bg-espresso px-5 pt-7.5 pb-8.5 text-cream">
        <img
          src="/logo-evaggelou-color.png"
          alt={settings.name}
          className="mb-4.5 h-7 object-contain opacity-90 brightness-0 invert"
        />
        <div className="text-[13.5px] leading-[1.8] opacity-85">
          <div>{settings.address}</div>
          <div>
            <a href={`tel:${settings.phoneHref}`} className="text-cream">
              {settings.phone}
            </a>
          </div>
          <div>{settings.instagram}</div>
          <div>Ωράριο: {formatHoursSummary(settings.hours)}</div>
        </div>
        <div className="mt-3 text-[11.5px] opacity-50">
          Φωτογραφίες από{" "}
          <a href="https://www.pexels.com" target="_blank" rel="noreferrer" className="text-cream underline">
            Pexels
          </a>
        </div>
        <a href="/club" className="mt-4 block text-[11.5px] text-cream opacity-40">
          Είσοδος προσωπικού
        </a>
      </footer>
    </main>
  );
}
