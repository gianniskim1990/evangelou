/**
 * Visual asset mapping for the profiterole preview — the single place
 * that says "what does this option look like," so ProfiterolePreview.tsx
 * stays pure layout/rendering and never hardcodes a color or position
 * inline. Swapping these for final art assets later (real photos, sprite
 * sheets, whatever) means editing this file only — no component changes.
 *
 * Every visual below is defined procedurally (colors, positions, tiny SVG
 * shapes) AND, optionally, as a real photo asset path (`overlayImage`,
 * `pourEffectImage`, `image`). The renderer (ProfiterolePreview.tsx) picks
 * photo-mode only if the base photo exists (see BASE_IMAGE /
 * useAssetAvailability in previewHelpers.ts); each individual sauce/topping
 * photo independently no-ops if its own file hasn't been supplied yet. See
 * app/public/configurator/README.md for the exact asset spec — filenames,
 * dimensions, alignment — needed to fill these in with real photography.
 */

/** Bun cluster layout, in a fixed 250×210 design space that ProfiterolePreview scales as a whole. */
export const BUN_POS = [
  { x: 90, y: 20, size: 40 },
  { x: 40, y: 50, size: 44 },
  { x: 140, y: 50, size: 44 },
  { x: 65, y: 85, size: 42 },
  { x: 115, y: 85, size: 42 },
];

/** Placement slots for topping pieces — cycled with modulo when more toppings are selected than slots. */
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

export function visualScaleFor(sizeId: string | null): number {
  if (sizeId === "solo") return 0.85;
  if (sizeId === "duo") return 1;
  if (sizeId === "family") return 1.15;
  return 0.85;
}

/**
 * Photo-mode canvas — the same 25:21 aspect ratio as the procedural design
 * space (250×210) above, scaled 4× for resolution. Every real photo asset
 * (base, sauce overlays, topping overlays, pour effects) must be authored
 * to exactly this canvas: same crop, same camera angle, same bowl position,
 * so switching a selection never shifts the product in frame. Full spec in
 * app/public/configurator/README.md.
 */
export const PHOTO_CANVAS_WIDTH = 1000;
export const PHOTO_CANVAS_HEIGHT = 840;

/**
 * The photorealistic base plate: plain choux buns in the bowl, no
 * chocolate, no toppings. Whether this file loads is the single switch
 * between "photo mode" and the procedural fallback (see
 * useAssetAvailability in previewHelpers.ts) — so with zero real assets
 * present (today), the app renders exactly as it did before this file
 * existed. This path does not exist in the repo yet; see the README.
 */
export const BASE_IMAGE = "/configurator/base/profiterole-base.webp";

/**
 * What a chocolate coating looks like. `coat`/`coatHighlight`/`drizzle`
 * drive the procedural renderer (always available, no asset needed).
 * `overlayImage` is the final photo of the whole bowl coated in this
 * chocolate; `pourEffectImage` is an optional brief transient layer shown
 * while the coating "settles" before `overlayImage` remains on screen.
 * Both are independently optional — the photo renderer skips whichever
 * isn't supplied yet without falling back to procedural for just that
 * piece (the mode switch is base-image-only, see BASE_IMAGE above).
 */
export interface ChocolateVisual {
  coat: string;
  coatHighlight: string;
  drizzle: string;
  overlayImage?: string;
  pourEffectImage?: string;
}

/** Keyed by data/menu.ts's `chocolates[].id`. */
export const CHOCOLATE_VISUALS: Record<string, ChocolateVisual> = {
  milk: {
    coat: "#7C4A26",
    coatHighlight: "#A5713E",
    drizzle: "#5C371D",
    overlayImage: "/configurator/sauces/milk-coat.webp",
    pourEffectImage: "/configurator/effects/milk-pour.webp",
  },
  classic: {
    coat: "#6B3D1F",
    coatHighlight: "#8F5A32",
    drizzle: "#4A2C16",
    overlayImage: "/configurator/sauces/classic-coat.webp",
    pourEffectImage: "/configurator/effects/classic-pour.webp",
  },
  white: {
    coat: "#F2E3C6",
    coatHighlight: "#FFF8EA",
    drizzle: "#D8BD8F",
    overlayImage: "/configurator/sauces/white-coat.webp",
    pourEffectImage: "/configurator/effects/white-pour.webp",
  },
  dark: {
    coat: "#3A2313",
    coatHighlight: "#5C3A20",
    drizzle: "#241509",
    overlayImage: "/configurator/sauces/dark-coat.webp",
    pourEffectImage: "/configurator/effects/dark-pour.webp",
  },
  gianduia: {
    coat: "#8A5A32",
    coatHighlight: "#B27E4C",
    drizzle: "#623C1F",
    overlayImage: "/configurator/sauces/gianduia-coat.webp",
    pourEffectImage: "/configurator/effects/gianduia-pour.webp",
  },
  strawberry: {
    coat: "#E6A0AE",
    coatHighlight: "#F6CDD6",
    drizzle: "#C96F82",
    overlayImage: "/configurator/sauces/strawberry-coat.webp",
    pourEffectImage: "/configurator/effects/strawberry-pour.webp",
  },
  bueno: {
    coat: "#8F6136",
    coatHighlight: "#C0925C",
    drizzle: "#6B4423",
    overlayImage: "/configurator/sauces/bueno-coat.webp",
    pourEffectImage: "/configurator/effects/bueno-pour.webp",
  },
};

export const NO_CHOCOLATE_VISUAL: ChocolateVisual = { coat: "transparent", coatHighlight: "transparent", drizzle: "transparent" };

export type ToppingArchetype = "piece" | "crumb" | "drizzle";

export interface ToppingVisual {
  archetype: ToppingArchetype;
  color: string;
  /** px — only meaningful for "piece"/"crumb". */
  size?: number;
  /**
   * A full-canvas photo of the bowl with this topping sprinkled on —
   * rendered as the final static overlay once selected, and (briefly, on
   * entrance) cropped into a few small circular pieces to fake a "falling
   * in" moment before settling into the full overlay. Optional — the photo
   * renderer skips this topping's photo layer entirely if not supplied.
   */
  image?: string;
}

/** Keyed by data/menu.ts's toppingGroups[].items[].id. Grouped by archetype below to make the intent obvious; falls back to a generic bronze "piece" for any future topping added without a visual yet (see toppingVisual() in previewHelpers.ts). */
export const TOPPING_VISUALS: Record<string, ToppingVisual> = {
  // Ξηροί καρποί — small falling pieces
  walnut: { archetype: "piece", color: "#8B6F47", size: 9, image: "/configurator/toppings/walnut.webp" },
  hazelnut: { archetype: "piece", color: "#A47B4E", size: 8, image: "/configurator/toppings/hazelnut.webp" },
  almond: { archetype: "piece", color: "#C9A876", size: 8, image: "/configurator/toppings/almond.webp" },

  // Μπισκότα — crumble pieces (slightly angular, see ProfiterolePreview's crumb rendering)
  oreo: { archetype: "crumb", color: "#2B2420", size: 7, image: "/configurator/toppings/oreo.webp" },
  digestive: { archetype: "crumb", color: "#B08A5C", size: 7, image: "/configurator/toppings/digestive.webp" },
  lotusb: { archetype: "crumb", color: "#C08A4E", size: 7, image: "/configurator/toppings/lotusb.webp" },

  // Φρούτα — soft drop-in pieces
  strawberry: { archetype: "piece", color: "#D6495E", size: 9, image: "/configurator/toppings/strawberry.webp" },
  banana: { archetype: "piece", color: "#E8D48A", size: 9, image: "/configurator/toppings/banana.webp" },
  cherry: { archetype: "piece", color: "#A0233A", size: 8, image: "/configurator/toppings/cherry.webp" },

  // Σιρόπια — quick drizzle, rendered as a small squiggle instead of a dot
  chocsyrup: { archetype: "drizzle", color: "#3A2313", image: "/configurator/toppings/chocsyrup.webp" },
  caramel: { archetype: "drizzle", color: "#C17817", image: "/configurator/toppings/caramel.webp" },
  strawsyrup: { archetype: "drizzle", color: "#D6495E", image: "/configurator/toppings/strawsyrup.webp" },

  // Καραμέλες — kept to muted, brand-adjacent jewel tones rather than
  // literal bright candy colors, to stay premium rather than cartoonish.
  mms: { archetype: "piece", color: "#8A6A45", size: 7, image: "/configurator/toppings/mms.webp" },
  marshmallow: { archetype: "piece", color: "#FBF3E7", size: 8, image: "/configurator/toppings/marshmallow.webp" },
  gummy: { archetype: "piece", color: "#7A2E3B", size: 7, image: "/configurator/toppings/gummy.webp" },
};

export const DEFAULT_TOPPING_VISUAL: ToppingVisual = { archetype: "piece", color: "#86764F", size: 8 };
