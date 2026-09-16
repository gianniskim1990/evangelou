/**
 * Visual asset mapping for the profiterole preview — the single place
 * that says "what does this option look like," so ProfiterolePreview.tsx
 * stays pure layout/rendering and never hardcodes a color or position
 * inline. Swapping these for final art assets later means editing this
 * file only — no component changes.
 *
 * Two independent visual strategies live side by side here:
 *
 * 1. Procedural (colors, positions, tiny SVG shapes) — always available,
 *    no asset required. This is the permanent fallback.
 * 2. Full-frame "dessert state" photos (STATE_IMAGES) — a complete photo
 *    of the finished dessert for one exact chocolate selection (bowl,
 *    buns, sauce, lighting all baked into a single opaque image), plus
 *    transparent topping sprite sheets (ToppingVisual.sprite) for
 *    toppings animated on top of whichever state is showing. Neither is a
 *    transparent layer stacked onto a shared base — each state image is
 *    self-contained.
 *
 * The renderer (ProfiterolePreview.tsx) uses a state image only if one is
 * defined AND loads for the current selection; otherwise that selection
 * falls back to the procedural illustration. See
 * app/public/configurator/README.md for the exact asset spec.
 */

/** Bun cluster layout, in a fixed 250×210 design space that ProfiterolePreview scales as a whole. */
export const BUN_POS = [
  { x: 90, y: 20, size: 40 },
  { x: 40, y: 50, size: 44 },
  { x: 140, y: 50, size: 44 },
  { x: 65, y: 85, size: 42 },
  { x: 115, y: 85, size: 42 },
];

/** Placement slots for topping pieces over the procedural illustration — cycled with modulo when more toppings are selected than slots. Calibrated against BUN_POS above; do not reuse for photo mode (see PHOTO_TOP_POS), the real photo's bun cluster sits in different coordinates. */
export const TOP_POS = [
  { x: 30, y: 15 },
  { x: 175, y: 20 },
  { x: 55, y: 35 },
  { x: 150, y: 35 },
  { x: 100, y: 5 },
  { x: 20, y: 70 },
  { x: 185, y: 65 },
  { x: 90, y: 100 },
  { x: 130, y: 105 },
  { x: 60, y: 105 },
];

/**
 * Placement slots for topping pieces over a photo state image, in the same
 * 250×210 design space. Measured directly against the real state photos
 * (states/base.webp, milk.webp — the bun cluster sits in the same spot on
 * both): each point sits on the top-facing surface of one visible bun,
 * inside the bowl, clear of the rim, the background napkin/prop, and the
 * counter. TOP_POS (above) is calibrated for the procedural illustration's
 * own bun layout and lands on the napkin/background when reused here.
 */
export const PHOTO_TOP_POS = [
  { x: 120, y: 35 },
  { x: 65, y: 88 },
  { x: 178, y: 80 },
  { x: 125, y: 102 },
  { x: 78, y: 130 },
  { x: 150, y: 132 },
  { x: 195, y: 115 },
  { x: 100, y: 50 },
];

export function visualScaleFor(sizeId: string | null): number {
  if (sizeId === "solo") return 0.85;
  if (sizeId === "duo") return 1;
  if (sizeId === "family") return 1.15;
  return 0.85;
}

/**
 * State-image canvas — same 25:21 aspect ratio as the procedural design
 * space (250×210) above, scaled 4× for resolution. Every full-frame state
 * photo must be authored to exactly this canvas (same crop, camera angle,
 * bowl position, background) so switching between states never shifts or
 * resizes the product in frame — that's what makes the crossfade between
 * them read as "the same bowl" rather than a scene cut. Full spec in
 * app/public/configurator/README.md.
 */
export const STATE_CANVAS_WIDTH = 1000;
export const STATE_CANVAS_HEIGHT = 840;

/**
 * Full-frame photorealistic "dessert state" images. Each entry is a
 * complete photo of the finished dessert for that exact selection — NOT a
 * transparent sauce layer meant to stack on a base photo. Keyed by
 * `chocolates[].id` from data/menu.ts, plus a special `"base"` entry used
 * when no chocolate has been chosen yet. Any chocolate id without an entry
 * here has no photo state yet; the preview falls back to the procedural
 * illustration for that specific selection only (see stateImageFor() /
 * useAssetAvailability() in previewHelpers.ts) — picking an
 * unphotographed chocolate doesn't break photo mode for the others.
 */
export const STATE_IMAGES: Record<string, string> = {
  base: "/configurator/states/base.webp",
  milk: "/configurator/states/milk.webp",
  strawberry: "/configurator/states/strawberry.webp",
  classic: "/configurator/states/classic.webp",
  white: "/configurator/states/white.webp",
  dark: "/configurator/states/dark.webp",
  gianduia: "/configurator/states/gianduia.webp",
  bueno: "/configurator/states/bueno.webp",
};
/**
 * Full-frame photorealistic state-image families by selected profiterole base.
 * Every family uses the same state keys as STATE_IMAGES:
 * base, milk, classic, white, dark, gianduia, strawberry, bueno.
 *
 * `classic` deliberately reuses the original STATE_IMAGES so the existing
 * verified visual set remains untouched.
 */
export const BASE_STATE_IMAGES: Record<string, Record<string, string>> = {
  classic: STATE_IMAGES,
  icecream: {
    base: "/configurator/bases/icecream/base.webp",
    milk: "/configurator/bases/icecream/milk.webp",
    classic: "/configurator/bases/icecream/classic.webp",
    white: "/configurator/bases/icecream/white.webp",
    dark: "/configurator/bases/icecream/dark.webp",
    gianduia: "/configurator/bases/icecream/gianduia.webp",
    strawberry: "/configurator/bases/icecream/strawberry.webp",
    bueno: "/configurator/bases/icecream/bueno.webp",
  },
  dubai: {
    base: "/configurator/bases/dubai/base.webp",
    milk: "/configurator/bases/dubai/milk.webp",
    classic: "/configurator/bases/dubai/classic.webp",
    white: "/configurator/bases/dubai/white.webp",
    dark: "/configurator/bases/dubai/dark.webp",
    gianduia: "/configurator/bases/dubai/gianduia.webp",
    strawberry: "/configurator/bases/dubai/strawberry.webp",
    bueno: "/configurator/bases/dubai/bueno.webp",
  },
};

/** What a chocolate coating looks like procedurally — a dip-cap color on each bun, a lighter gloss highlight, and a drizzle-line color. Always available; used whenever this chocolate has no entry in STATE_IMAGES (or its photo fails to load). */
export interface ChocolateVisual {
  coat: string;
  coatHighlight: string;
  drizzle: string;
}

/** Keyed by data/menu.ts's `chocolates[].id`. */
export const CHOCOLATE_VISUALS: Record<string, ChocolateVisual> = {
  milk: { coat: "#7C4A26", coatHighlight: "#A5713E", drizzle: "#5C371D" },
  classic: { coat: "#6B3D1F", coatHighlight: "#8F5A32", drizzle: "#4A2C16" },
  white: { coat: "#F2E3C6", coatHighlight: "#FFF8EA", drizzle: "#D8BD8F" },
  dark: { coat: "#3A2313", coatHighlight: "#5C3A20", drizzle: "#241509" },
  gianduia: { coat: "#8A5A32", coatHighlight: "#B27E4C", drizzle: "#623C1F" },
  strawberry: { coat: "#E6A0AE", coatHighlight: "#F6CDD6", drizzle: "#C96F82" },
  bueno: { coat: "#8F6136", coatHighlight: "#C0925C", drizzle: "#6B4423" },
};

export const NO_CHOCOLATE_VISUAL: ChocolateVisual = { coat: "transparent", coatHighlight: "transparent", drizzle: "transparent" };

export type ToppingArchetype = "piece" | "crumb" | "drizzle";

/**
 * A transparent sprite sheet: `frames` equal-width tiles arranged in a
 * single horizontal strip, sampled via CSS background-position so each
 * animated piece can show a slightly different tile instead of an
 * identical stamp repeated. `frames` must match the real asset's actual
 * tile count — see app/public/configurator/README.md.
 */
export interface ToppingSprite {
  image: string;
  frames: number;
}

export interface ToppingVisual {
  archetype: ToppingArchetype;
  color: string;
  /** px — only meaningful for "piece"/"crumb". */
  size?: number;
  /** If present and loadable, pieces of this topping render as sprite crops instead of a flat color dot — used for both the falling-in animation and the pieces left resting on the dessert afterward. */
  sprite?: ToppingSprite;
}

/** Keyed by data/menu.ts's toppingGroups[].items[].id. Grouped by archetype below to make the intent obvious; falls back to a generic bronze "piece" for any future topping added without a visual yet (see toppingVisual() in previewHelpers.ts). */
export const TOPPING_VISUALS: Record<string, ToppingVisual> = {
  // Ξηροί καρποί — small falling pieces
  walnut: { archetype: "piece", color: "#8B6F47", size: 9, sprite: { image: "/configurator/toppings/walnut-sprites.webp", frames: 4 } },
  hazelnut: { archetype: "piece", color: "#A47B4E", size: 8, sprite: { image: "/configurator/toppings/hazelnut-sprites.webp", frames: 4 } },
  almond: { archetype: "piece", color: "#C9A876", size: 8, sprite: { image: "/configurator/toppings/almond-sprites.webp", frames: 4 } },

  // Μπισκότα — crumble pieces (slightly angular, see ProfiterolePreview's crumb rendering)
  oreo: { archetype: "crumb", color: "#2B2420", size: 7, sprite: { image: "/configurator/toppings/oreo-sprites.webp", frames: 4 } },
  digestive: { archetype: "crumb", color: "#B08A5C", size: 7, sprite: { image: "/configurator/toppings/digestive-sprites.webp", frames: 4 } },
  lotusb: { archetype: "crumb", color: "#C08A4E", size: 7, sprite: { image: "/configurator/toppings/lotusb-sprites.webp", frames: 4 } },

  // Φρούτα — soft drop-in pieces
  strawberry: { archetype: "piece", color: "#D6495E", size: 9, sprite: { image: "/configurator/toppings/strawberry-sprites.webp", frames: 4 } },
  banana: { archetype: "piece", color: "#E8D48A", size: 9, sprite: { image: "/configurator/toppings/banana-sprites.webp", frames: 4 } },
  cherry: { archetype: "piece", color: "#A0233A", size: 8, sprite: { image: "/configurator/toppings/cherry-sprites.webp", frames: 4 } },

  // Σιρόπια — quick drizzle, rendered as a small squiggle instead of a dot
  chocsyrup: { archetype: "drizzle", color: "#3A2313", sprite: { image: "/configurator/toppings/chocsyrup-sprites.webp", frames: 4 } },
  caramel: { archetype: "drizzle", color: "#C17817", sprite: { image: "/configurator/toppings/caramel-sprites.webp", frames: 4 } },
  strawsyrup: { archetype: "drizzle", color: "#D6495E", sprite: { image: "/configurator/toppings/strawsyrup-sprites.webp", frames: 4 } },

  // Καραμέλες — kept to muted, brand-adjacent jewel tones rather than
  // literal bright candy colors, to stay premium rather than cartoonish.
  mms: { archetype: "piece", color: "#8A6A45", size: 7, sprite: { image: "/configurator/toppings/mms-sprites.webp", frames: 4 } },
  marshmallow: { archetype: "piece", color: "#FBF3E7", size: 8, sprite: { image: "/configurator/toppings/marshmallow-sprites.webp", frames: 4 } },
  gummy: { archetype: "piece", color: "#7A2E3B", size: 7, sprite: { image: "/configurator/toppings/gummy-sprites.webp", frames: 4 } },
};

export const DEFAULT_TOPPING_VISUAL: ToppingVisual = { archetype: "piece", color: "#86764F", size: 8 };
