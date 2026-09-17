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

  // In procedural mode, visualScaleFor() is already applied to each bun.
  // In photo mode, scale the entire finished composition instead so the
  // photo and every topping stay perfectly aligned as the portion changes.
  const photoPortionScale = isPhoto ? visualScaleFor(cfg.size) : 1;
  const heroScale = isHero ? 1.3 : 1;
  const compositionScale = heroScale * photoPortionScale;

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
          style={{
            width: 250,
            height: 210,
            transform: `scale(${compositionScale})`,
            transformOrigin: "center center",
            transition: "transform 420ms cubic-bezier(0.22, 1, 0.36, 1)",
          }}
        >
          {isPhoto ? (
            <PhotoLayers current={photo.current!} previous={photo.previous} baseId={cfg.base} />
          ) : (
            <ProceduralLayers cfg={cfg} />
          )}

          <ToppingLayer toppings={toppings} isPhoto={isPhoto} sizeId={cfg.size} />
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

/**
 * Chilly and Lotus source photos contain a soft background artifact near
 * the upper edge. Crop only those two families slightly tighter and shift
 * the source upward so that artifact stays outside the clipped preview.
 *
 * All other base families preserve the existing scale(1.03) treatment.
 */
function photoImageTransformForBase(baseId: string | null): string {
  if (baseId === "chilly" || baseId === "lotus") {
    return "translateY(-7px) scale(1.12)";
  }
  return PHOTO_EDGE_TRIM_SCALE;
}

/** The current full-frame state photo, with the previous one kept mounted underneath during a crossfade (see usePhotoPreviewState). */
function PhotoLayers({
  current,
  previous,
  baseId,
}: {
  current: string;
  previous: string | null;
  baseId: string | null;
}) {
  const imageTransform = photoImageTransformForBase(baseId);

  return (
    <>
      {previous && (
        <img
          src={previous}
          alt=""
          draggable={false}
          className="pointer-events-none absolute inset-0 h-full w-full object-cover"
          style={{ transform: imageTransform }}
        />
      )}
      <img
        key={current}
        src={current}
        alt="Προφιτερόλ"
        draggable={false}
        className="animate-state-crossfade-in pointer-events-none absolute inset-0 h-full w-full object-cover"
        style={{ transform: imageTransform }}
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

/** Toppings layer, shared by both photo and procedural modes.
 *
 * Portion-size density rules:
 * - Solo: one visual piece per selected topping. The whole composition is
 *   already scaled to 0.85, so the result naturally reads lighter.
 * - Duo: one visual piece per selected topping — this preserves the
 *   existing visual baseline exactly.
 * - Family: selected toppings gain extra visual pieces, but the renderer
 *   uses an adaptive budget so a user who selects many toppings does not
 *   turn the dessert into an unreadable pile. This affects presentation
 *   only; cart selections/pricing remain one topping per selected id.
 */
function ToppingLayer({
  toppings,
  isPhoto,
  sizeId,
}: {
  toppings: TrackedTopping[];
  isPhoto: boolean;
  sizeId: string | null;
}) {
  const activeCount = toppings.filter((t) => !t.exiting).length;

  // A running counter across every rendered piece (not just per-topping) —
  // see ToppingPiece's slotIndex below for why this needs to be globally
  // unique rather than derived from toppingIndex alone.
  let pieceSeed = 0;

  return (
    <>
      {toppings.flatMap((t, toppingIndex) => {
        const copies = toppingVisualCopies(sizeId, toppingIndex, activeCount);

        return Array.from({ length: copies }, (_, copyIndex) => (
          <ToppingPiece
            key={`${t.id}:${copyIndex}`}
            id={t.id}
            toppingIndex={toppingIndex}
            copyIndex={copyIndex}
            slotSeed={pieceSeed++}
            exiting={t.exiting}
            isPhoto={isPhoto}
          />
        ));
      })}
    </>
  );
}

/**
 * Family gets richer topping coverage without exploding when many toppings
 * are selected:
 *   1-2 selected => 3 visual pieces each
 *   3-5 selected => 2 visual pieces each
 *   6+ selected  => only enough duplicates to reach ~10 visible pieces
 *
 * Solo/Duo deliberately stay at one piece per selected topping.
 */
function toppingVisualCopies(sizeId: string | null, toppingIndex: number, selectedCount: number): number {
  if (sizeId !== "family") return 1;

  if (selectedCount <= 2) return 3;
  if (selectedCount <= 5) return 2;

  const extraBudget = Math.max(0, 10 - selectedCount);
  return toppingIndex < extraBudget ? 2 : 1;
}

function ToppingPiece({
  id,
  toppingIndex,
  copyIndex,
  slotSeed,
  exiting,
  isPhoto,
}: {
  id: string;
  toppingIndex: number;
  copyIndex: number;
  slotSeed: number;
  exiting: boolean;
  isPhoto: boolean;
}) {
  const visual = toppingVisual(id);
  const spriteReady = useAssetAvailability(visual.sprite?.image);
  const slots = isPhoto ? PHOTO_TOP_POS : TOP_POS;

  // Every rendered piece (across every topping, not just each topping's own
  // copies) gets a slot from a single shared running count. A per-topping
  // formula like `toppingIndex + copyIndex*stride` looks fine in isolation
  // but two *different* toppings can land on the exact same slot once
  // enough toppings are selected (e.g. topping 0's 2nd copy and topping 3's
  // 1st copy both resolving to slot 3) — invisible in the math, but two
  // different sprites rendering centered on the identical spot in the
  // photo. A shared counter guarantees every piece gets a distinct slot as
  // long as the total piece count fits the slot list (it does up to the
  // ~10-piece Family cap on the 8-slot photo map only in the sense that
  // slots start repeating gracefully past that, same as before).
  const slotIndex = slotSeed % slots.length;
  const pos = slots[slotIndex];
  const motionClass = exiting ? "animate-topping-exit" : "animate-topping-fall";
  const rotationIndex = toppingIndex + copyIndex * 2;
  const rotStyle = { "--fall-rot": `${fallRotationFor(rotationIndex)}deg` } as React.CSSProperties;

  // Extra Family pieces are subtly smaller so the result reads like a
  // natural scatter rather than cloned identical stickers.
  const copyScale = copyIndex === 0 ? 1 : copyIndex === 1 ? 0.88 : 0.76;

  if (visual.sprite && spriteReady) {
    const frames = visual.sprite.frames;
    const frameIndex = (toppingIndex + copyIndex) % frames;
    const isDrizzle = visual.archetype === "drizzle";
    const baseW = isDrizzle ? 27 : 22;
    const baseH = isDrizzle ? 10 : 22;
    const w = baseW * copyScale;
    const h = baseH * copyScale;

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
    const w = 20 * copyScale;
    const h = 10 * copyScale;

    return (
      <svg
        className={`pointer-events-none absolute ${motionClass}`}
        style={{ left: pos.x - w / 2, top: pos.y - h / 2, ...rotStyle }}
        width={w}
        height={h}
        viewBox="0 0 20 10"
      >
        <path d="M1 5 Q5 1 9 5 T18 5" stroke={visual.color} strokeWidth="2.4" fill="none" strokeLinecap="round" />
      </svg>
    );
  }

  const size = (visual.size ?? 8) * copyScale;

  return (
    <div
      className={`pointer-events-none absolute ${motionClass}`}
      style={{
        left: pos.x - size / 2,
        top: pos.y - size / 2,
        width: size,
        height: size,
        background: visual.color,
        borderRadius: visual.archetype === "crumb" ? "30%" : "50%",
        ...rotStyle,
      }}
    />
  );
}