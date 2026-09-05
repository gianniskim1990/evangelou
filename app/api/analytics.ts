import type { VercelRequest, VercelResponse } from "@vercel/node";
import { isAdminRequest } from "./_lib/auth.js";
import { STORAGE_UNCONFIGURED_MESSAGE, getRedis } from "./_lib/redis.js";

const ORDERS_INDEX_KEY = "evaggelou:orders:index";
const orderKey = (id: string) => `evaggelou:order:${id}`;
const ALLOWED_DAYS = [1, 7, 30, 90];
const MAX_ORDERS_SCANNED = 1000;

interface OrderRecord {
  status: string;
  total: number;
  createdAt: string;
  items: { name: string; qty: number }[];
}

export default async function handler(req: VercelRequest, res: VercelResponse) {
  if (!isAdminRequest(req)) {
    res.status(401).json({ error: "Μη εξουσιοδοτημένο." });
    return;
  }

  const redis = getRedis();
  if (!redis) {
    res.status(503).json({ error: STORAGE_UNCONFIGURED_MESSAGE });
    return;
  }

  const daysParam = Number(req.query.days);
  const days = ALLOWED_DAYS.includes(daysParam) ? daysParam : 30;
  const since = Date.now() - days * 24 * 60 * 60 * 1000;

  const ids = await redis.zrange<string[]>(ORDERS_INDEX_KEY, 0, MAX_ORDERS_SCANNED - 1, { rev: true });
  const orders: (OrderRecord | null)[] = ids.length ? await redis.mget<(OrderRecord | null)[]>(...ids.map(orderKey)) : [];
  const inRange = orders.filter((o: OrderRecord | null): o is OrderRecord => !!o && new Date(o.createdAt).getTime() >= since);

  const byStatus: Record<string, number> = { new: 0, in_progress: 0, completed: 0, cancelled: 0 };
  const productQty = new Map<string, number>();
  let totalValue = 0;
  let completedCount = 0;

  for (const order of inRange) {
    byStatus[order.status] = (byStatus[order.status] ?? 0) + 1;
    if (order.status !== "cancelled") {
      totalValue += order.total;
      if (order.status === "completed") completedCount++;
      for (const item of order.items ?? []) {
        productQty.set(item.name, (productQty.get(item.name) ?? 0) + item.qty);
      }
    }
  }

  const topProducts = [...productQty.entries()]
    .sort((a, b) => b[1] - a[1])
    .slice(0, 8)
    .map(([name, qty]) => ({ name, qty }));

  res.status(200).json({
    days,
    totalOrders: inRange.length,
    totalValue,
    completedCount,
    byStatus,
    topProducts,
  });
}
