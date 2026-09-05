import type { VercelRequest, VercelResponse } from "@vercel/node";
import { isAdminRequest } from "../_lib/auth.js";
import { STORAGE_UNCONFIGURED_MESSAGE, getRedis } from "../_lib/redis.js";

const orderKey = (id: string) => `evaggelou:order:${id}`;
const VALID_STATUSES = ["new", "in_progress", "completed", "cancelled"];

export default async function handler(req: VercelRequest, res: VercelResponse) {
  const id = typeof req.query.id === "string" ? req.query.id : Array.isArray(req.query.id) ? req.query.id[0] : "";
  if (!id) {
    res.status(400).json({ error: "Λείπει ο αριθμός παραγγελίας." });
    return;
  }

  const redis = getRedis();
  if (!redis) {
    res.status(503).json({ error: STORAGE_UNCONFIGURED_MESSAGE });
    return;
  }

  if (req.method === "GET") {
    // Public — the customer polls this to see live order status.
    const order = await redis.get<Record<string, unknown>>(orderKey(id));
    if (!order) {
      res.status(404).json({ error: "Δεν βρέθηκε παραγγελία." });
      return;
    }
    res.status(200).json({ order });
    return;
  }

  if (req.method === "PATCH") {
    // Admin-only — change order status.
    if (!isAdminRequest(req)) {
      res.status(401).json({ error: "Μη εξουσιοδοτημένο." });
      return;
    }
    const status = req.body?.status;
    if (typeof status !== "string" || !VALID_STATUSES.includes(status)) {
      res.status(400).json({ error: "Μη έγκυρη κατάσταση." });
      return;
    }
    const existing = await redis.get<Record<string, unknown>>(orderKey(id));
    if (!existing) {
      res.status(404).json({ error: "Δεν βρέθηκε παραγγελία." });
      return;
    }
    const updated = { ...existing, status };
    await redis.set(orderKey(id), updated);
    res.status(200).json({ order: updated });
    return;
  }

  res.status(405).json({ error: "Method not allowed" });
}
