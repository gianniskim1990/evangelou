import type { VercelRequest, VercelResponse } from "@vercel/node";
import { isAdminRequest } from "./_lib/auth.js";
import { STORAGE_UNCONFIGURED_MESSAGE, getRedis } from "./_lib/redis.js";
import {
  EMPTY_OVERRIDES,
  OVERRIDES_KEY,
  getOverrides,
  patchOverrides,
  type OverridesPatch,
  type StoredNewCategory,
  type StoredNewGroup,
  type StoredProduct,
} from "./_lib/overridesStore.js";

function isValidProduct(p: unknown): p is StoredProduct {
  if (!p || typeof p !== "object") return false;
  const rec = p as Record<string, unknown>;
  if (typeof rec.name !== "string" || rec.name.trim().length === 0) return false;
  if (typeof rec.price !== "number" || !Number.isFinite(rec.price) || rec.price < 0) return false;
  if (rec.perKilo !== undefined && typeof rec.perKilo !== "boolean") return false;
  if (rec.diabetic !== undefined && typeof rec.diabetic !== "boolean") return false;
  return true;
}

function isNonEmptyString(v: unknown): v is string {
  return typeof v === "string" && v.trim().length > 0;
}

function isValidNewGroup(g: unknown): g is StoredNewGroup {
  if (!g || typeof g !== "object") return false;
  const rec = g as Record<string, unknown>;
  return isNonEmptyString(rec.id) && isNonEmptyString(rec.name);
}

function isValidNewCategory(c: unknown): c is StoredNewCategory {
  if (!c || typeof c !== "object") return false;
  const rec = c as Record<string, unknown>;
  return isNonEmptyString(rec.id) && isNonEmptyString(rec.name) && isNonEmptyString(rec.groupId);
}

function isStringArray(v: unknown): v is string[] {
  return Array.isArray(v) && v.every((x) => typeof x === "string" && x.length > 0);
}

function validateIncoming(body: unknown): OverridesPatch | null {
  if (!body || typeof body !== "object") return null;
  const rec = body as Record<string, unknown>;
  const patch: OverridesPatch = {};

  if (rec.products !== undefined) {
    if (typeof rec.products !== "object" || rec.products === null) return null;
    const products: Record<string, StoredProduct[]> = {};
    for (const [catId, list] of Object.entries(rec.products as Record<string, unknown>)) {
      if (!Array.isArray(list) || !list.every(isValidProduct)) return null;
      products[catId] = list;
    }
    patch.products = products;
  }

  if (rec.categoryNames !== undefined) {
    if (typeof rec.categoryNames !== "object" || rec.categoryNames === null) return null;
    const categoryNames: Record<string, string> = {};
    for (const [catId, name] of Object.entries(rec.categoryNames as Record<string, unknown>)) {
      if (typeof name !== "string" || name.trim().length === 0) return null;
      categoryNames[catId] = name;
    }
    patch.categoryNames = categoryNames;
  }

  if (rec.newGroups !== undefined) {
    if (!Array.isArray(rec.newGroups) || !rec.newGroups.every(isValidNewGroup)) return null;
    patch.newGroups = rec.newGroups;
  }

  if (rec.newCategories !== undefined) {
    if (!Array.isArray(rec.newCategories) || !rec.newCategories.every(isValidNewCategory)) return null;
    patch.newCategories = rec.newCategories;
  }

  if (rec.deletedGroups !== undefined) {
    if (!isStringArray(rec.deletedGroups)) return null;
    patch.deletedGroups = rec.deletedGroups;
  }

  if (rec.deletedCategories !== undefined) {
    if (!isStringArray(rec.deletedCategories)) return null;
    patch.deletedCategories = rec.deletedCategories;
  }

  return patch;
}

export default async function handler(req: VercelRequest, res: VercelResponse) {
  const redis = getRedis();
  if (!redis) {
    res.status(503).json({ error: STORAGE_UNCONFIGURED_MESSAGE });
    return;
  }

  if (req.method === "GET") {
    const data = await getOverrides(redis);
    res.status(200).json(data);
    return;
  }

  if (req.method === "POST") {
    if (!isAdminRequest(req)) {
      res.status(401).json({ error: "Μη εξουσιοδοτημένο." });
      return;
    }

    if (req.body?.reset === true) {
      await redis.del(OVERRIDES_KEY);
      res.status(200).json({ ok: true, overrides: EMPTY_OVERRIDES });
      return;
    }

    const patch = validateIncoming(req.body);
    if (!patch) {
      res.status(400).json({ error: "Μη έγκυρα δεδομένα." });
      return;
    }

    const merged = await patchOverrides(redis, patch);
    res.status(200).json({ ok: true, overrides: merged });
    return;
  }

  res.status(405).json({ error: "Method not allowed" });
}
