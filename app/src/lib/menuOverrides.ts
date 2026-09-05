import type { CatalogGroup, MenuOverrides, Product } from "../types";

export const EMPTY_OVERRIDES: MenuOverrides = {
  products: {},
  categoryNames: {},
  newGroups: [],
  newCategories: [],
  productImages: {},
  categoryImages: {},
};

export interface MergedMenu {
  products: Record<string, Product[]>;
  categoryNames: Record<string, string>;
  groups: CatalogGroup[];
  productImages: Record<string, string>;
  categoryImages: Record<string, string>;
}

/**
 * Merges admin-edited overrides on top of the seed data from data/menu.ts:
 * - a category present in overrides.products replaces that category's whole
 *   product list; categoryNames merge key-by-key
 * - newGroups are appended as extra sticky-nav sections; newCategories are
 *   appended into their group's category list (creating the group first if
 *   it's one of newGroups) and get an empty product list unless overridden
 * - productImages/categoryImages pass through untouched (just id -> version)
 *
 * Used by both the customer app (read-only) and the admin panel (which
 * edits a working copy of the merged result before saving deltas back).
 */
export function mergeMenu(
  baseProducts: Record<string, Product[]>,
  baseCategoryNames: Record<string, string>,
  baseGroups: CatalogGroup[],
  overrides: MenuOverrides | null,
): MergedMenu {
  if (!overrides) {
    return { products: baseProducts, categoryNames: baseCategoryNames, groups: baseGroups, productImages: {}, categoryImages: {} };
  }

  const categoryNames: Record<string, string> = { ...baseCategoryNames, ...overrides.categoryNames } as Record<string, string>;
  for (const c of overrides.newCategories) categoryNames[c.id] = c.name;

  const groups: CatalogGroup[] = baseGroups.map((g) => ({ ...g, categories: [...g.categories] }));
  for (const g of overrides.newGroups) {
    if (!groups.some((existing) => existing.id === g.id)) groups.push({ id: g.id, name: g.name, categories: [] });
  }
  for (const c of overrides.newCategories) {
    const group = groups.find((g) => g.id === c.groupId);
    if (group && !group.categories.includes(c.id)) group.categories.push(c.id);
  }

  const products: Record<string, Product[]> = { ...baseProducts, ...overrides.products } as Record<string, Product[]>;
  for (const c of overrides.newCategories) {
    if (!products[c.id]) products[c.id] = [];
  }

  return {
    products,
    categoryNames,
    groups,
    productImages: { ...overrides.productImages } as Record<string, string>,
    categoryImages: { ...overrides.categoryImages } as Record<string, string>,
  };
}
