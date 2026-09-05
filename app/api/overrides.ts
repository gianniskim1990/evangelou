import type { VercelRequest, VercelResponse } from "@vercel/node";
import { isAdminRequest } from "./_lib/auth.js";
import { STORAGE_UNCONFIGURED_MESSAGE, getRedis } from "./_lib/redis.js";

// Single JSON document holding every admin-edited delta on top of the seed
// data in src/data/menu.ts.
const OVERRIDES_KEY = "evaggelou:menu-overrides";

interface StoredProduct {
  name: string;
  price: number;
  perKilo?: boolean;
  diabetic?: boolean;
}

interface StoredOverrides {
  products: Record<string, StoredProduct[]>;
  categoryNames: Record<string, string>;
}

const EMPTY: StoredOverrides = { products: {}, categoryNames: {} };

function isValidProduct(p: unknown): p is StoredProduct {
  if (!p || typeof p !== "object") return false;
  const rec = p as Record<string, unknown>;
  if (typeof rec.name !== "string" || rec.name.trim().length === 0) return false;
  if (typeof rec.price !== "number" || !Number.isFinite(rec.price) || rec.price < 0) return false;
  if (rec.perKilo !== undefined && typeof rec.perKilo !== "boolean") return false;
  if (rec.diabetic !== undefined && typeof rec.diabetic !== "boolean") return false;
  return true;
}

function validateIncoming(body: unknown): StoredOverrides | null {
  if (!body || typeof body !== "object") return null;
  const rec = body as Record<string, unknown>;
  const products: Record<string, StoredProduct[]> = {};
  const categoryNames: Record<string, string> = {};

  if (rec.products !== undefined) {
    if (typeof rec.products !== "object" || rec.products === null) return null;
    for (const [catId, list] of Object.entries(rec.products as Record<string, unknown>)) {
      if (!Array.isArray(list) || !list.every(isValidProduct)) return null;
      products[catId] = list;
    }
  }
  if (rec.categoryNames !== undefined) {
    if (typeof rec.categoryNames !== "object" || rec.categoryNames === null) return null;
    for (const [catId, name] of Object.entries(rec.categoryNames as Record<string, unknown>)) {
      if (typeof name !== "string" || name.trim().length === 0) return null;
      categoryNames[catId] = name;
    }
  }
  return { products, categoryNames };
}

export default async function handler(req: VercelRequest, res: VercelResponse) {
  const redis = getRedis();
  if (!redis) {
    res.status(503).json({ error: STORAGE_UNCONFIGURED_MESSAGE });
    return;
  }

  if (req.method === "GET") {
    const data = (await redis.get<StoredOverrides>(OVERRIDES_KEY)) ?? EMPTY;
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
      res.status(200).json({ ok: true, overrides: EMPTY });
      return;
    }

    const incoming = validateIncoming(req.body);
    if (!incoming) {
      res.status(400).json({ error: "Μη έγκυρα δεδομένα." });
      return;
    }

    const existing = (await redis.get<StoredOverrides>(OVERRIDES_KEY)) ?? EMPTY;
    const merged: StoredOverrides = {
      products: { ...existing.products, ...incoming.products },
      categoryNames: { ...existing.categoryNames, ...incoming.categoryNames },
    };
    await redis.set(OVERRIDES_KEY, merged);
    res.status(200).json({ ok: true, overrides: merged });
    return;
  }

  res.status(405).json({ error: "Method not allowed" });
}
