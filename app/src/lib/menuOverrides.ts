import type { MenuOverrides, Product } from "../types";

export const EMPTY_OVERRIDES: MenuOverrides = { products: {}, categoryNames: {} };

/**
 * Merges admin-edited overrides on top of the seed data from data/menu.ts.
 * A category present in overrides.products replaces that category's whole
 * product list; categoryNames merge key-by-key. Used by both the customer
 * app (read-only) and the admin panel (which edits a working copy of the
 * merged result before saving it back as the new overrides).
 */
export function mergeMenu(
  baseProducts: Record<string, Product[]>,
  baseCategoryNames: Record<string, string>,
  overrides: MenuOverrides | null,
): { products: Record<string, Product[]>; categoryNames: Record<string, string> } {
  if (!overrides) return { products: baseProducts, categoryNames: baseCategoryNames };
  return {
    products: { ...baseProducts, ...overrides.products } as Record<string, Product[]>,
    categoryNames: { ...baseCategoryNames, ...overrides.categoryNames } as Record<string, string>,
  };
}
