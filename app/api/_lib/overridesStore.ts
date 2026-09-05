import type { Redis } from "@upstash/redis";

// Single JSON document holding every admin-edited delta on top of the seed
// data in src/data/menu.ts. Shared by api/overrides.ts (reads/writes most
// of it) and api/images/* (which only touch productImages/categoryImages
// after a successful upload).
export const OVERRIDES_KEY = "evaggelou:menu-overrides";

export interface StoredProduct {
  name: string;
  price: number;
  perKilo?: boolean;
  diabetic?: boolean;
}

export interface StoredNewCategory {
  id: string;
  name: string;
  groupId: string;
}

export interface StoredNewGroup {
  id: string;
  name: string;
}

export interface StoredOverrides {
  products: Record<string, StoredProduct[]>;
  categoryNames: Record<string, string>;
  newGroups: StoredNewGroup[];
  newCategories: StoredNewCategory[];
  productImages: Record<string, string>;
  categoryImages: Record<string, string>;
  deletedGroups: string[];
  deletedCategories: string[];
}

export const EMPTY_OVERRIDES: StoredOverrides = {
  products: {},
  categoryNames: {},
  newGroups: [],
  newCategories: [],
  productImages: {},
  categoryImages: {},
  deletedGroups: [],
  deletedCategories: [],
};

export async function getOverrides(redis: Redis): Promise<StoredOverrides> {
  const data = await redis.get<Partial<StoredOverrides>>(OVERRIDES_KEY);
  if (!data) return EMPTY_OVERRIDES;
  return { ...EMPTY_OVERRIDES, ...data };
}

function upsertById<T extends { id: string }>(existing: T[], incoming: T[]): T[] {
  const map = new Map(existing.map((x) => [x.id, x]));
  for (const item of incoming) map.set(item.id, item);
  return [...map.values()];
}

function unionDistinct(existing: string[], incoming: string[]): string[] {
  return [...new Set([...existing, ...incoming])];
}

export interface OverridesPatch {
  products?: Record<string, StoredProduct[]>;
  categoryNames?: Record<string, string>;
  newGroups?: StoredNewGroup[];
  newCategories?: StoredNewCategory[];
  productImages?: Record<string, string>;
  categoryImages?: Record<string, string>;
  deletedGroups?: string[];
  deletedCategories?: string[];
}

/** Merges a partial delta into the stored overrides document (object fields
 * merge key-by-key, id-keyed arrays upsert by id, deletion lists union) and
 * persists the result. */
export async function patchOverrides(redis: Redis, patch: OverridesPatch): Promise<StoredOverrides> {
  const existing = await getOverrides(redis);
  const merged: StoredOverrides = {
    products: { ...existing.products, ...patch.products },
    categoryNames: { ...existing.categoryNames, ...patch.categoryNames },
    newGroups: upsertById(existing.newGroups, patch.newGroups ?? []),
    newCategories: upsertById(existing.newCategories, patch.newCategories ?? []),
    productImages: { ...existing.productImages, ...patch.productImages },
    categoryImages: { ...existing.categoryImages, ...patch.categoryImages },
    deletedGroups: unionDistinct(existing.deletedGroups, patch.deletedGroups ?? []),
    deletedCategories: unionDistinct(existing.deletedCategories, patch.deletedCategories ?? []),
  };
  await redis.set(OVERRIDES_KEY, merged);
  return merged;
}
