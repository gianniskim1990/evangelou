/**
 * Visual asset mapping for the profiterole preview — the single place
 * that says "what does this option look like," so ProfiterolePreview.tsx
 * stays pure layout/rendering and never hardcodes a color or position
 * inline. Swapping these for final art assets later (real photos, sprite
 * sheets, whatever) means editing this file only — no component changes.
 *
 * Everything here is procedural (colors, positions, tiny SVG shapes) since
 * the repo has no photographic assets suited to compositing — see the
 * project README for how that trade-off was made.
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

/** What a chocolate coating looks like — a dip-cap color on each bun, a lighter gloss highlight, and a drizzle-line color. */
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

export interface ToppingVisual {
  archetype: ToppingArchetype;
  color: string;
  /** px — only meaningful for "piece"/"crumb". */
  size?: number;
}

/** Keyed by data/menu.ts's toppingGroups[].items[].id. Grouped by archetype below to make the intent obvious; falls back to a generic bronze "piece" for any future topping added without a visual yet (see toppingVisual() in previewHelpers.ts). */
export const TOPPING_VISUALS: Record<string, ToppingVisual> = {
  // Ξηροί καρποί — small falling pieces
  walnut: { archetype: "piece", color: "#8B6F47", size: 9 },
  hazelnut: { archetype: "piece", color: "#A47B4E", size: 8 },
  almond: { archetype: "piece", color: "#C9A876", size: 8 },

  // Μπισκότα — crumble pieces (slightly angular, see ProfiterolePreview's crumb rendering)
  oreo: { archetype: "crumb", color: "#2B2420", size: 7 },
  digestive: { archetype: "crumb", color: "#B08A5C", size: 7 },
  lotusb: { archetype: "crumb", color: "#C08A4E", size: 7 },

  // Φρούτα — soft drop-in pieces
  strawberry: { archetype: "piece", color: "#D6495E", size: 9 },
  banana: { archetype: "piece", color: "#E8D48A", size: 9 },
  cherry: { archetype: "piece", color: "#A0233A", size: 8 },

  // Σιρόπια — quick drizzle, rendered as a small squiggle instead of a dot
  chocsyrup: { archetype: "drizzle", color: "#3A2313" },
  caramel: { archetype: "drizzle", color: "#C17817" },
  strawsyrup: { archetype: "drizzle", color: "#D6495E" },

  // Καραμέλες — kept to muted, brand-adjacent jewel tones rather than
  // literal bright candy colors, to stay premium rather than cartoonish.
  mms: { archetype: "piece", color: "#8A6A45", size: 7 },
  marshmallow: { archetype: "piece", color: "#FBF3E7", size: 8 },
  gummy: { archetype: "piece", color: "#7A2E3B", size: 7 },
};

export const DEFAULT_TOPPING_VISUAL: ToppingVisual = { archetype: "piece", color: "#86764F", size: 8 };
