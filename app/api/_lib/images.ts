import type { Redis } from "@upstash/redis";
import type { VercelResponse } from "@vercel/node";

// Client-side compression (src/lib/imageUpload.ts) targets far less than
// this before it ever reaches us — this is just a hard backstop against
// an oversized Redis value, not the primary size control.
const MAX_DATA_URL_LENGTH = 900_000; // ~650KB decoded

export interface StoredImage {
  contentType: string;
  base64: string;
  updatedAt: string;
}

const DATA_URL_RE = /^data:(image\/(?:jpeg|png|webp));base64,(.+)$/;

export function parseImageDataUrl(dataUrl: unknown): { contentType: string; base64: string } | null {
  if (typeof dataUrl !== "string" || dataUrl.length === 0 || dataUrl.length > MAX_DATA_URL_LENGTH) return null;
  const match = DATA_URL_RE.exec(dataUrl);
  if (!match) return null;
  return { contentType: match[1], base64: match[2] };
}

export async function storeImage(redis: Redis, key: string, contentType: string, base64: string): Promise<string> {
  const updatedAt = new Date().toISOString();
  const record: StoredImage = { contentType, base64, updatedAt };
  await redis.set(key, record);
  return updatedAt;
}

export async function serveImage(redis: Redis, key: string, res: VercelResponse): Promise<void> {
  const record = await redis.get<StoredImage>(key);
  if (!record) {
    res.status(404).json({ error: "Δεν βρέθηκε εικόνα." });
    return;
  }
  const buffer = Buffer.from(record.base64, "base64");
  res.setHeader("Content-Type", record.contentType);
  res.setHeader("Cache-Control", "public, max-age=31536000, immutable");
  res.status(200).send(buffer);
}
