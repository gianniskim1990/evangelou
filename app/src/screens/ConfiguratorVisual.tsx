import { BUN_POS, CHOC_TONE, TOP_POS, visualScaleFor } from "./configuratorVisual";
import type { ConfiguratorState } from "../types";

export function ConfiguratorVisual({ cfg }: { cfg: ConfiguratorState }) {
  const chocOpacity = cfg.choc ? CHOC_TONE[cfg.choc] : 0;
  const scale = visualScaleFor(cfg.size);

  return (
    <div className="relative mx-auto mb-6.5 h-[210px] w-[250px]">
      <div
        className="absolute bottom-1.5 left-1.5 h-6.5 w-[240px] rounded-full"
        style={{ background: "radial-gradient(ellipse at center, rgba(30,24,18,0.16), transparent 75%)" }}
      />
      <div className="absolute bottom-3.5 left-3.5 h-20 w-[222px] rounded-b-[110px] border-[1.5px] border-t-0 border-espresso bg-white opacity-55" />

      {BUN_POS.map((b, i) => (
        <div
          key={i}
          className="absolute rounded-full border-[1.5px] border-bronze transition-transform duration-400"
          style={{
            left: b.x,
            top: b.y,
            width: b.size,
            height: b.size,
            background: "radial-gradient(circle at 32% 30%, #FFFFFF, #FAF8F3 45%, #86764F 100%)",
            transform: `scale(${scale})`,
          }}
        />
      ))}

      <svg
        width="250"
        height="210"
        viewBox="0 0 250 210"
        className="pointer-events-none absolute top-0 left-0 transition-opacity duration-500"
        style={{ opacity: chocOpacity }}
      >
        <path d="M20 50 Q 60 30, 100 50 T 180 50 T 230 55" stroke="#1E1812" strokeWidth="4" fill="none" strokeLinecap="round" />
        <path d="M35 85 Q 75 65, 115 85 T 195 85" stroke="#1E1812" strokeWidth="4" fill="none" strokeLinecap="round" />
        <path d="M55 120 Q 95 100, 135 120 T 190 122" stroke="#1E1812" strokeWidth="4" fill="none" strokeLinecap="round" />
      </svg>

      {cfg.toppings.map((_, i) => {
        const pos = TOP_POS[i % TOP_POS.length];
        return (
          <div
            key={i}
            className="animate-pop-in absolute h-2.5 w-2.5 rounded-full bg-espresso opacity-85"
            style={{ left: pos.x, top: pos.y }}
          />
        );
      })}
    </div>
  );
}
