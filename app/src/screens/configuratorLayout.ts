export const CHOC_TONE: Record<string, number> = {
  milk: 0.35,
  classic: 0.5,
  white: 0.15,
  dark: 0.7,
  gianduia: 0.55,
  strawberry: 0.3,
  bueno: 0.45,
};

export const BUN_POS = [
  { x: 90, y: 20, size: 40 },
  { x: 40, y: 50, size: 44 },
  { x: 140, y: 50, size: 44 },
  { x: 65, y: 85, size: 42 },
  { x: 115, y: 85, size: 42 },
];

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
