import { useState } from "react";
import type { ConfiguratorState } from "../types";
import { BASE_IMAGE, BUN_POS, TOP_POS, visualScaleFor, type ChocolateVisual, type ToppingVisual } from "./previewAssets";
import { chocolateVisual, toppingVisual, useAssetAvailability, useToppingTransitions, type TrackedTopping } from "./previewHelpers";

/** A tasteful, deterministic wobble so falling pieces don't all land dead-straight — no Math.random(), so it never jitters between renders. */
function fallRotationFor(index: number): number {
  return ((index % 5) - 2) * 7;
}

/**
 * Public entry point: picks photo mode if a real base photo has been
 * supplied (app/public/configurator/base/, see previewAssets.ts), else
 * falls back to the procedural illustration below. With zero real assets
 * in the repo (today), this always renders ProceduralProfiterolePreview —
 * identical behavior to before photo mode existed. Props are intentionally
 * unchanged so callers (Configurator.tsx) never need to know which mode
 * rendered.
 */
export function ProfiterolePreview({
  cfg,
  variant = "compact",
}: {
  cfg: ConfiguratorState;
  variant?: "compact" | "hero";
}) {
  const photoModeAvailable = useAssetAvailability(BASE_IMAGE);
  return photoModeAvailable ? (
    <PhotoProfiterolePreview cfg={cfg} variant={variant} />
  ) : (
    <ProceduralProfiterolePreview cfg={cfg} variant={variant} />
  );
}

/** The original CSS/SVG illustration — gradients, clipped color caps, small shapes. Untouched other than being extracted into its own component. */
function ProceduralProfiterolePreview({
  cfg,
  variant = "compact",
}: {
  cfg: ConfiguratorState;
  variant?: "compact" | "hero";
}) {
  const scale = visualScaleFor(cfg.size);
  const choc = chocolateVisual(cfg.choc);
  const toppings = useToppingTransitions(cfg.toppings);
  const isHero = variant === "hero";

  return (
    <div
      className={`relative mx-auto mb-6.5 ${isHero ? "animate-hero-in" : ""}`}
      style={{ width: isHero ? 300 : 250, height: isHero ? 300 : 210 }}
    >
      {isHero && (
        <div
          className="absolute inset-0 rounded-full"
          style={{
            background:
              "radial-gradient(circle at 50% 42%, color-mix(in srgb, var(--color-bronze) 14%, transparent), transparent 70%)",
          }}
        />
      )}

      {/* Design space is a fixed 250×210 box (same as the original illustration) —
          the hero variant just scales that whole box up, so both variants stay
          visually identical in proportion and the customer recognizes it as the
          same profiterole they were building. */}
      <div className="absolute inset-0 flex items-center justify-center">
        <div className="relative" style={{ width: 250, height: 210, transform: isHero ? "scale(1.3)" : undefined }}>
          <div
            className="absolute bottom-1.5 left-1.5 h-6.5 w-[240px] rounded-full"
            style={{ background: "radial-gradient(ellipse at center, rgba(30,24,18,0.16), transparent 75%)" }}
          />
          <div className="absolute bottom-3.5 left-3.5 h-20 w-[222px] rounded-b-[110px] border-[1.5px] border-t-0 border-ink bg-paper opacity-55" />

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
            >
              {cfg.choc && (
                <div
                  key={cfg.choc}
                  className="animate-pour-reveal absolute inset-0 overflow-hidden rounded-full"
                  style={{ clipPath: "inset(0 0 42% 0)" }}
                >
                  <div
                    className="absolute inset-0"
                    style={{ background: `radial-gradient(circle at 35% 25%, ${choc.coatHighlight}, ${choc.coat} 65%)` }}
                  />
                </div>
              )}
            </div>
          ))}

          {cfg.choc && (
            <svg
              key={cfg.choc}
              width="250"
              height="210"
              viewBox="0 0 250 210"
              className="pointer-events-none absolute top-0 left-0"
            >
              <path
                d="M20 50 Q 60 30, 100 50 T 180 50 T 230 55"
                stroke={choc.drizzle}
                strokeWidth="4"
                fill="none"
                strokeLinecap="round"
                strokeDasharray="260"
                strokeDashoffset="260"
                className="animate-drizzle-draw"
              />
              <path
                d="M35 85 Q 75 65, 115 85 T 195 85"
                stroke={choc.drizzle}
                strokeWidth="4"
                fill="none"
                strokeLinecap="round"
                strokeDasharray="230"
                strokeDashoffset="230"
                className="animate-drizzle-draw"
                style={{ animationDelay: "70ms" }}
              />
              <path
                d="M55 120 Q 95 100, 135 120 T 190 122"
                stroke={choc.drizzle}
                strokeWidth="4"
                fill="none"
                strokeLinecap="round"
                strokeDasharray="220"
                strokeDashoffset="220"
                className="animate-drizzle-draw"
                style={{ animationDelay: "140ms" }}
              />
            </svg>
          )}

          {toppings.map((t, i) => {
            const visual = toppingVisual(t.id);
            const pos = TOP_POS[i % TOP_POS.length];
            const motionClass = t.exiting ? "animate-topping-exit" : "animate-topping-fall";
            const rotStyle = { "--fall-rot": `${fallRotationFor(i)}deg` } as React.CSSProperties;

            if (visual.archetype === "drizzle") {
              return (
                <svg
                  key={t.id}
                  className={`pointer-events-none absolute ${motionClass}`}
                  style={{ left: pos.x - 9, top: pos.y - 4, ...rotStyle }}
                  width="20"
                  height="10"
                  viewBox="0 0 20 10"
                >
                  <path d="M1 5 Q5 1 9 5 T18 5" stroke={visual.color} strokeWidth="2.4" fill="none" strokeLinecap="round" />
                </svg>
              );
            }

            const size = visual.size ?? 8;
            return (
              <div
                key={t.id}
                className={`pointer-events-none absolute ${motionClass}`}
                style={{
                  left: pos.x,
                  top: pos.y,
                  width: size,
                  height: size,
                  background: visual.color,
                  borderRadius: visual.archetype === "crumb" ? "30%" : "50%",
                  ...rotStyle,
                }}
              />
            );
          })}
        </div>
      </div>
    </div>
  );
}

/**
 * Photo-mode sauce layer: the static coat overlay (once its file is
 * available) plus a brief transient pour-effect image on top of it, if
 * one is supplied. Neither requires the other — a chocolate can ship with
 * just the static overlay and no pour effect yet.
 */
function PhotoSauce({ choc, chocId }: { choc: ChocolateVisual; chocId: string | null }) {
  const overlayReady = useAssetAvailability(choc.overlayImage);
  const pourReady = useAssetAvailability(choc.pourEffectImage);
  // Remounted via `key={chocId}` in the parent whenever the selection
  // changes, so this always starts fresh at `true` — no effect needed to
  // "reset" it on selection change.
  const [showPour, setShowPour] = useState(true);

  if (!chocId || !overlayReady || !choc.overlayImage) return null;

  return (
    <>
      <img
        key={`overlay-${chocId}`}
        src={choc.overlayImage}
        alt=""
        draggable={false}
        className="animate-pour-reveal pointer-events-none absolute inset-0 h-full w-full object-cover"
      />
      {pourReady && showPour && choc.pourEffectImage && (
        <img
          key={`pour-${chocId}`}
          src={choc.pourEffectImage}
          alt=""
          draggable={false}
          className="animate-pour-effect-fade pointer-events-none absolute inset-0 h-full w-full object-cover"
          onAnimationEnd={() => setShowPour(false)}
        />
      )}
    </>
  );
}

/**
 * Photo-mode topping layer: a full-bowl photo of that topping sprinkled on
 * (the final, permanent look), preceded on entrance by a handful of small
 * circular crops of that same photo animated in with the existing
 * topping-fall motion, so the piece appears to "land" before the full
 * overlay settles. Reuses useToppingTransitions' enter/exit timing — see
 * its `exiting` flag. No-ops entirely if this topping has no image yet.
 */
function PhotoTopping({ index, visual, exiting }: { index: number; visual: ToppingVisual; exiting: boolean }) {
  const overlayReady = useAssetAvailability(visual.image);
  const [justLanded, setJustLanded] = useState(false);

  if (!overlayReady || !visual.image) return null;

  if (exiting) {
    return (
      <img
        src={visual.image}
        alt=""
        draggable={false}
        className="animate-topping-exit pointer-events-none absolute inset-0 h-full w-full object-cover"
      />
    );
  }

  return (
    <>
      {!justLanded &&
        [0, 1, 2].map((piece) => {
          const slot = TOP_POS[(index * 3 + piece) % TOP_POS.length];
          const cropX = (piece * 37 + index * 11) % 100;
          const cropY = (piece * 53 + index * 17) % 100;
          return (
            <div
              key={piece}
              className="animate-topping-fall pointer-events-none absolute overflow-hidden rounded-full"
              style={
                {
                  left: (slot.x / 250) * 100 + "%",
                  top: (slot.y / 210) * 100 + "%",
                  width: 22,
                  height: 22,
                  "--fall-rot": `${fallRotationFor(index * 3 + piece)}deg`,
                } as React.CSSProperties
              }
              onAnimationEnd={piece === 2 ? () => setJustLanded(true) : undefined}
            >
              <img
                src={visual.image}
                alt=""
                draggable={false}
                style={{
                  width: 110,
                  height: 110,
                  objectFit: "cover",
                  objectPosition: `${cropX}% ${cropY}%`,
                  transform: "translate(-40%, -40%)",
                }}
              />
            </div>
          );
        })}
      <img
        src={visual.image}
        alt=""
        draggable={false}
        className="animate-pop-in pointer-events-none absolute inset-0 h-full w-full object-cover"
      />
    </>
  );
}

/**
 * Photo-mode renderer: the real base photo plus optional sauce/topping
 * photo layers stacked on top, at the same fixed design-space box (and
 * hero-scale transform) as the procedural version above, so both modes
 * stay pixel-compatible with Configurator.tsx's layout.
 */
function PhotoProfiterolePreview({
  cfg,
  variant = "compact",
}: {
  cfg: ConfiguratorState;
  variant?: "compact" | "hero";
}) {
  const choc = chocolateVisual(cfg.choc);
  const toppings = useToppingTransitions(cfg.toppings);
  const isHero = variant === "hero";

  return (
    <div
      className={`relative mx-auto mb-6.5 ${isHero ? "animate-hero-in" : ""}`}
      style={{ width: isHero ? 300 : 250, height: isHero ? 300 : 210 }}
    >
      {isHero && (
        <div
          className="absolute inset-0 rounded-full"
          style={{
            background:
              "radial-gradient(circle at 50% 42%, color-mix(in srgb, var(--color-bronze) 14%, transparent), transparent 70%)",
          }}
        />
      )}

      <div className="absolute inset-0 flex items-center justify-center">
        <div
          className="relative overflow-hidden rounded-[18px]"
          style={{ width: 250, height: 210, transform: isHero ? "scale(1.3)" : undefined }}
        >
          <img src={BASE_IMAGE} alt="Προφιτερόλ" draggable={false} className="absolute inset-0 h-full w-full object-cover" />

          <PhotoSauce key={cfg.choc} choc={choc} chocId={cfg.choc} />

          {toppings.map((t: TrackedTopping, i: number) => (
            <PhotoTopping key={t.id} index={i} visual={toppingVisual(t.id)} exiting={t.exiting} />
          ))}
        </div>
      </div>
    </div>
  );
}
