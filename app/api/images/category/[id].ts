import type { VercelRequest, VercelResponse } from "@vercel/node";
import { isAdminRequest } from "../../_lib/auth.js";
import { parseImageDataUrl, serveImage, storeImage } from "../../_lib/images.js";
import { patchOverrides } from "../../_lib/overridesStore.js";
import { STORAGE_UNCONFIGURED_MESSAGE, getRedis } from "../../_lib/redis.js";

const imageKey = (id: string) => `evaggelou:category-image:${id}`;

export default async function handler(req: VercelRequest, res: VercelResponse) {
  const id = typeof req.query.id === "string" ? req.query.id : Array.isArray(req.query.id) ? req.query.id[0] : "";
  if (!id) {
    res.status(400).json({ error: "Λείπει η κατηγορία." });
    return;
  }

  const redis = getRedis();
  if (!redis) {
    res.status(503).json({ error: STORAGE_UNCONFIGURED_MESSAGE });
    return;
  }

  if (req.method === "GET") {
    await serveImage(redis, imageKey(id), res);
    return;
  }

  if (req.method === "POST") {
    if (!isAdminRequest(req)) {
      res.status(401).json({ error: "Μη εξουσιοδοτημένο." });
      return;
    }
    const parsed = parseImageDataUrl(req.body?.dataUrl);
    if (!parsed) {
      res.status(400).json({ error: "Μη έγκυρη ή πολύ μεγάλη εικόνα." });
      return;
    }
    const updatedAt = await storeImage(redis, imageKey(id), parsed.contentType, parsed.base64);
    await patchOverrides(redis, { categoryImages: { [id]: updatedAt } });
    res.status(200).json({ ok: true, updatedAt });
    return;
  }

  res.status(405).json({ error: "Method not allowed" });
}
