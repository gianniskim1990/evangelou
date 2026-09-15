import type { ConfiguratorState } from "../types";
import { BUN_POS, TOP_POS, visualScaleFor } from "./previewAssets";
import {
  chocolateVisual,
  stateImageFor,
  toppingVisual,
  useAssetAvailability,
  usePhotoPreviewState,
  useToppingTransitions,
  type TrackedTopping,
} from "./previewHelpers";

/** A tasteful, deterministic wobble so falling pieces don't all land dead-straight — no Math.random(), so it never jitters between renders. */
function fallRotationFor(index: number): number {
  return ((index % 5) - 2) * 7;
}

/**
 * The profiterole preview: a photorealistic full-frame state photo when
 * one exists and loads for the current chocolate selection, otherwise the
 * procedural CSS/SVG illustration for that same selection. This is a
 * single always-mounted component (not a dispatcher swapping between two
 * child components) specifically so usePhotoPreviewState's "keep showing
 * the last good photo while the next one probes" logic survives across
 * every selection change — swapping mounts on every mode flip would reset
 * that state and reintroduce a flash of procedural on every switch. With
 * zero real assets in the repo (today), this always renders the
 * procedural illustration, identical to before photo mode existed.
 */
export function ProfiterolePreview({
  cfg,
  variant = "compact",
}: {
  cfg: ConfiguratorState;
  variant?: "compact" | "hero";
}) {
  const targetSrc = stateImageFor(cfg.choc);
  const photo = usePhotoPreviewState(targetSrc);
  const toppings = useToppingTransitions(cfg.toppings);
  const isHero = variant === "hero";
  const isPhoto = photo.mode === "photo";

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
        <div
          className={`relative ${isPhoto ? "overflow-hidden rounded-[18px]" : ""}`}
          style={{ width: 250, height: 210, transform: isHero ? "scale(1.3)" : undefined }}
        >
          {isPhoto ? (
            <PhotoLayers current={photo.current!} previous={photo.previous} />
          ) : (
            <ProceduralLayers cfg={cfg} />
          )}

          <ToppingLayer toppings={toppings} />
        </div>
      </div>
    </div>
  );
}

/** The current full-frame state photo, with the previous one kept mounted underneath during a crossfade (see usePhotoPreviewState). */
function PhotoLayers({ current, previous }: { current: string; previous: string | null }) {
  return (
    <>
      {previous && (
        <img src={previous} alt="" draggable={false} className="pointer-events-none absolute inset-0 h-full w-full object-cover" />
      )}
      <img
        key={current}
        src={current}
        alt="Προφιτερόλ"
        draggable={false}
        className="animate-state-crossfade-in pointer-events-none absolute inset-0 h-full w-full object-cover"
      />
    </>
  );
}

/** The original CSS/SVG illustration — gradients, clipped color caps, small shapes. Used whenever the current selection has no photo state (or it's confirmed missing). */
function ProceduralLayers({ cfg }: { cfg: ConfiguratorState }) {
  const scale = visualScaleFor(cfg.size);
  const choc = chocolateVisual(cfg.choc);

  return (
    <>
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
        <svg key={cfg.choc} width="250" height="210" viewBox="0 0 250 210" className="pointer-events-none absolute top-0 left-0">
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
    </>
  );
}

/** Toppings layer, shared by both photo and procedural modes: a piece with a loadable sprite renders as a cropped sprite frame (animated in, then left resting), everything else renders as the original colored dot/crumb/drizzle-squiggle. Toppings don't care whether the layer beneath them is a photo or the procedural illustration. */
function ToppingLayer({ toppings }: { toppings: TrackedTopping[] }) {
  return (
    <>
      {toppings.map((t, i) => (
        <ToppingPiece key={t.id} id={t.id} index={i} exiting={t.exiting} />
      ))}
    </>
  );
}

function ToppingPiece({ id, index, exiting }: { id: string; index: number; exiting: boolean }) {
  const visual = toppingVisual(id);
  const spriteReady = useAssetAvailability(visual.sprite?.image);
  const pos = TOP_POS[index % TOP_POS.length];
  const motionClass = exiting ? "animate-topping-exit" : "animate-topping-fall";
  const rotStyle = { "--fall-rot": `${fallRotationFor(index)}deg` } as React.CSSProperties;

  if (visual.sprite && spriteReady) {
    const frames = visual.sprite.frames;
    const frameIndex = index % frames;
    return (
      <div
        className={`pointer-events-none absolute ${motionClass}`}
        style={{
          left: pos.x - 7,
          top: pos.y - 7,
          width: 22,
          height: 22,
          backgroundImage: `url(${visual.sprite.image})`,
          backgroundSize: `${frames * 100}% 100%`,
          backgroundPosition: `${(frameIndex / Math.max(frames - 1, 1)) * 100}% 0%`,
          backgroundRepeat: "no-repeat",
          ...rotStyle,
        }}
      />
    );
  }

  if (visual.archetype === "drizzle") {
    return (
      <svg
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
}
