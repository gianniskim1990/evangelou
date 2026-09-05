import type { VercelRequest, VercelResponse } from "@vercel/node";
import { isAdminRequest } from "./_lib/auth.js";
import { STORAGE_UNCONFIGURED_MESSAGE, getRedis } from "./_lib/redis.js";

const SETTINGS_KEY = "evaggelou:settings";

interface OpeningPeriod {
  weekday: number;
  opensAt: string;
  closesAt: string;
  isClosed: boolean;
}

interface StoreSettings {
  name: string;
  address: string;
  phone: string;
  phoneHref: string;
  instagram: string;
  deliveryMinOrder: number;
  deliveryFee: number;
  hours: OpeningPeriod[];
  deliveryEtaMinMinutes: number;
  deliveryEtaMaxMinutes: number;
  pickupPrepMinutes: number;
}

function isValidPeriod(p: unknown): p is OpeningPeriod {
  if (!p || typeof p !== "object") return false;
  const rec = p as Record<string, unknown>;
  return (
    typeof rec.weekday === "number" &&
    rec.weekday >= 0 &&
    rec.weekday <= 6 &&
    typeof rec.opensAt === "string" &&
    typeof rec.closesAt === "string" &&
    typeof rec.isClosed === "boolean"
  );
}

function isValidSettings(body: unknown): body is StoreSettings {
  if (!body || typeof body !== "object") return false;
  const rec = body as Record<string, unknown>;
  if (typeof rec.name !== "string" || rec.name.trim().length === 0) return false;
  if (typeof rec.address !== "string") return false;
  if (typeof rec.phone !== "string") return false;
  if (typeof rec.phoneHref !== "string") return false;
  if (typeof rec.instagram !== "string") return false;
  if (typeof rec.deliveryMinOrder !== "number" || !Number.isFinite(rec.deliveryMinOrder) || rec.deliveryMinOrder < 0) return false;
  if (typeof rec.deliveryFee !== "number" || !Number.isFinite(rec.deliveryFee) || rec.deliveryFee < 0) return false;
  if (!Array.isArray(rec.hours) || rec.hours.length !== 7 || !rec.hours.every(isValidPeriod)) return false;
  if (typeof rec.deliveryEtaMinMinutes !== "number" || !Number.isFinite(rec.deliveryEtaMinMinutes) || rec.deliveryEtaMinMinutes < 0) return false;
  if (typeof rec.deliveryEtaMaxMinutes !== "number" || !Number.isFinite(rec.deliveryEtaMaxMinutes) || rec.deliveryEtaMaxMinutes < rec.deliveryEtaMinMinutes) return false;
  if (typeof rec.pickupPrepMinutes !== "number" || !Number.isFinite(rec.pickupPrepMinutes) || rec.pickupPrepMinutes < 0) return false;
  return true;
}

export default async function handler(req: VercelRequest, res: VercelResponse) {
  const redis = getRedis();
  if (!redis) {
    res.status(503).json({ error: STORAGE_UNCONFIGURED_MESSAGE });
    return;
  }

  if (req.method === "GET") {
    const data = await redis.get<StoreSettings>(SETTINGS_KEY);
    if (!data) {
      res.status(404).json({ error: "Δεν έχουν οριστεί ρυθμίσεις ακόμα." });
      return;
    }
    res.status(200).json(data);
    return;
  }

  if (req.method === "POST") {
    if (!isAdminRequest(req)) {
      res.status(401).json({ error: "Μη εξουσιοδοτημένο." });
      return;
    }
    if (!isValidSettings(req.body)) {
      res.status(400).json({ error: "Μη έγκυρα δεδομένα." });
      return;
    }
    await redis.set(SETTINGS_KEY, req.body);
    res.status(200).json({ ok: true, settings: req.body });
    return;
  }

  res.status(405).json({ error: "Method not allowed" });
}
