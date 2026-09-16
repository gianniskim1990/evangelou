import type { ConfiguratorState } from "../types";
import { BUN_POS, PHOTO_TOP_POS, TOP_POS, visualScaleFor } from "./previewAssets";
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
  const targetSrc = stateImageFor(cfg.choc, cfg.base);
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

          <ToppingLayer toppings={toppings} isPhoto={isPhoto} />
        </div>
      </div>
    </div>
  );
}

/**
 * A hair over 100% scale on every state photo: the real assets have a
 * thin (~1-1.5% of width) encoding-seam artifact along their right edge
 * (visible on all three current state photos, same position on each), and
 * the parent box already clips overflow — a tiny uniform overscale pushes
 * that sliver off-frame on every edge without touching the source files
 * or disturbing the object-cover framing/alignment between states.
 */
const PHOTO_EDGE_TRIM_SCALE = "scale(1.03)";

/** The current full-frame state photo, with the previous one kept mounted underneath during a crossfade (see usePhotoPreviewState). */
function PhotoLayers({ current, previous }: { current: string; previous: string | null }) {
  return (
    <>
      {previous && (
        <img
          src={previous}
          alt=""
          draggable={false}
          className="pointer-events-none absolute inset-0 h-full w-full object-cover"
          style={{ transform: PHOTO_EDGE_TRIM_SCALE }}
        />
      )}
      <img
        key={current}
        src={current}
        alt="Προφιτερόλ"
        draggable={false}
        className="animate-state-crossfade-in pointer-events-none absolute inset-0 h-full w-full object-cover"
        style={{ transform: PHOTO_EDGE_TRIM_SCALE }}
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

/** Toppings layer, shared by both photo and procedural modes: a piece with a loadable sprite renders as a cropped sprite frame (animated in, then left resting), everything else renders as the original colored dot/crumb/drizzle-squiggle. The rendering itself doesn't care whether the layer beneath is a photo or the procedural illustration — but the bun cluster sits in different coordinates in each, so `isPhoto` picks the matching position set (PHOTO_TOP_POS vs. TOP_POS). */
function ToppingLayer({ toppings, isPhoto }: { toppings: TrackedTopping[]; isPhoto: boolean }) {
  return (
    <>
      {toppings.map((t, i) => (
        <ToppingPiece key={t.id} id={t.id} index={i} exiting={t.exiting} isPhoto={isPhoto} />
      ))}
    </>
  );
}

function ToppingPiece({ id, index, exiting, isPhoto }: { id: string; index: number; exiting: boolean; isPhoto: boolean }) {
  const visual = toppingVisual(id);
  const spriteReady = useAssetAvailability(visual.sprite?.image);
  const slots = isPhoto ? PHOTO_TOP_POS : TOP_POS;
  const pos = slots[index % slots.length];
  const motionClass = exiting ? "animate-topping-exit" : "animate-topping-fall";
  const rotStyle = { "--fall-rot": `${fallRotationFor(index)}deg` } as React.CSSProperties;

  if (visual.sprite && spriteReady) {
    const frames = visual.sprite.frames;
    const frameIndex = index % frames;
    const isDrizzle = visual.archetype === "drizzle";
    // Syrup sprite frames are a wide, short wavy squiggle (same shape as the
    // procedural drizzle SVG below), not a roundish piece — the square 22×22
    // box every other topping uses would squash it into an unrecognizable
    // dot. Use a wide/short box instead so it still reads as a drizzle.
    const w = isDrizzle ? 27 : 22;
    const h = isDrizzle ? 10 : 22;
    return (
      <div
        className={`pointer-events-none absolute ${motionClass}`}
        style={{
          left: pos.x - w / 2,
          top: pos.y - h / 2,
          width: w,
          height: h,
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
