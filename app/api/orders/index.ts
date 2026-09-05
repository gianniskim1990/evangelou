import type { VercelRequest, VercelResponse } from "@vercel/node";
import { isAdminRequest } from "../_lib/auth.js";
import { STORAGE_UNCONFIGURED_MESSAGE, getRedis } from "../_lib/redis.js";

const ORDERS_INDEX_KEY = "evaggelou:orders:index";
const orderKey = (id: string) => `evaggelou:order:${id}`;
// Bound how many recent orders we ever pull back in one request — this is a
// small-business demo, not built for high order volume.
const MAX_ORDERS_RETURNED = 500;

interface CartItem {
  id: string;
  name: string;
  unitPrice: number;
  qty: number;
  meta: string;
  isCake: boolean;
}

function isValidCartItem(it: unknown): it is CartItem {
  if (!it || typeof it !== "object") return false;
  const rec = it as Record<string, unknown>;
  return (
    typeof rec.id === "string" &&
    typeof rec.name === "string" &&
    typeof rec.unitPrice === "number" &&
    typeof rec.qty === "number" &&
    rec.qty > 0 &&
    typeof rec.meta === "string" &&
    typeof rec.isCake === "boolean"
  );
}

function isValidNewOrder(body: unknown): body is Record<string, unknown> {
  if (!body || typeof body !== "object") return false;
  const rec = body as Record<string, unknown>;
  if (!Array.isArray(rec.items) || rec.items.length === 0 || !rec.items.every(isValidCartItem)) return false;
  if (typeof rec.subtotal !== "number" || typeof rec.total !== "number" || typeof rec.deliveryFee !== "number") return false;
  if (rec.fulfillment !== "pickup" && rec.fulfillment !== "delivery") return false;
  if (rec.payment !== "cash" && rec.payment !== "card") return false;
  const customer = rec.customer as Record<string, unknown> | undefined;
  if (!customer || typeof customer.name !== "string" || customer.name.trim().length === 0) return false;
  if (typeof customer.phone !== "string" || customer.phone.trim().length === 0) return false;
  return true;
}

export default async function handler(req: VercelRequest, res: VercelResponse) {
  const redis = getRedis();
  if (!redis) {
    res.status(503).json({ error: STORAGE_UNCONFIGURED_MESSAGE });
    return;
  }

  if (req.method === "POST") {
    // Public — this is the customer's checkout submitting a real order.
    if (!isValidNewOrder(req.body)) {
      res.status(400).json({ error: "Μη έγκυρα δεδομένα παραγγελίας." });
      return;
    }
    const orderNumber = "EV-" + Math.floor(100000 + Math.random() * 900000);
    const now = Date.now();
    const order = {
      ...req.body,
      orderNumber,
      status: "new",
      createdAt: new Date(now).toISOString(),
    };
    await redis.set(orderKey(orderNumber), order);
    await redis.zadd(ORDERS_INDEX_KEY, { score: now, member: orderNumber });
    res.status(200).json({ order });
    return;
  }

  if (req.method === "GET") {
    // Admin-only — the orders board.
    if (!isAdminRequest(req)) {
      res.status(401).json({ error: "Μη εξουσιοδοτημένο." });
      return;
    }
    const ids = await redis.zrange<string[]>(ORDERS_INDEX_KEY, 0, MAX_ORDERS_RETURNED - 1, { rev: true });
    if (ids.length === 0) {
      res.status(200).json({ orders: [] });
      return;
    }
    const orders = await redis.mget<Record<string, unknown>[]>(...ids.map(orderKey));
    res.status(200).json({ orders: orders.filter((o: Record<string, unknown> | null): o is Record<string, unknown> => o != null) });
    return;
  }

  res.status(405).json({ error: "Method not allowed" });
}
