import { Redis } from "@upstash/redis";

// Vercel's Storage tab (Upstash for Redis, or the legacy "Vercel KV"
// naming) injects one of these env var pairs once the integration is
// connected — we accept either name.
export function getRedis(): Redis | null {
  const url = process.env.KV_REST_API_URL || process.env.UPSTASH_REDIS_REST_URL;
  const token = process.env.KV_REST_API_TOKEN || process.env.UPSTASH_REDIS_REST_TOKEN;
  if (!url || !token) return null;
  return new Redis({ url, token });
}

export const STORAGE_UNCONFIGURED_MESSAGE =
  "Το menu storage δεν έχει ρυθμιστεί ακόμα — πρόσθεσε ένα Upstash Redis (ή Vercel KV) integration από το Storage tab του project.";
