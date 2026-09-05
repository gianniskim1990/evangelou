import type { MenuOverrides, OrderStatus, StoreSettings, StoredOrder } from "../types";

const SESSION_KEY = "evaggelou-admin-password";

export function getStoredPassword(): string | null {
  try {
    return sessionStorage.getItem(SESSION_KEY);
  } catch {
    return null;
  }
}

export function storePassword(password: string): void {
  try {
    sessionStorage.setItem(SESSION_KEY, password);
  } catch {
    // sessionStorage unavailable (private mode etc.) — login will just be
    // asked for again on next save, which is a fine fallback.
  }
}

export function clearStoredPassword(): void {
  try {
    sessionStorage.removeItem(SESSION_KEY);
  } catch {
    // ignore
  }
}

export class AdminAuthError extends Error {}

async function adminFetch<T>(url: string, init?: RequestInit): Promise<T> {
  const password = getStoredPassword();
  const res = await fetch(url, {
    ...init,
    headers: { "Content-Type": "application/json", "x-admin-password": password ?? "", ...init?.headers },
  });
  if (res.status === 401) {
    clearStoredPassword();
    throw new AdminAuthError("Η σύνδεση έληξε, συνδέσου ξανά.");
  }
  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new Error(body.error ?? `Αίτημα απέτυχε (${res.status})`);
  }
  return res.json();
}

/** Saves a partial overrides delta (only the categories being changed). */
export async function saveOverrides(delta: Partial<MenuOverrides>): Promise<MenuOverrides> {
  const data = await adminFetch<{ overrides: MenuOverrides }>("/api/overrides", {
    method: "POST",
    body: JSON.stringify(delta),
  });
  return data.overrides;
}

export async function resetOverrides(): Promise<MenuOverrides> {
  const data = await adminFetch<{ overrides: MenuOverrides }>("/api/overrides", {
    method: "POST",
    body: JSON.stringify({ reset: true }),
  });
  return data.overrides;
}

export async function fetchAdminOrders(): Promise<StoredOrder[]> {
  const data = await adminFetch<{ orders: StoredOrder[] }>("/api/orders");
  return data.orders;
}

export async function updateOrderStatus(orderNumber: string, status: OrderStatus): Promise<StoredOrder> {
  const data = await adminFetch<{ order: StoredOrder }>(`/api/orders/${orderNumber}`, {
    method: "PATCH",
    body: JSON.stringify({ status }),
  });
  return data.order;
}

export async function saveSettings(settings: StoreSettings): Promise<StoreSettings> {
  const data = await adminFetch<{ settings: StoreSettings }>("/api/settings", {
    method: "POST",
    body: JSON.stringify(settings),
  });
  return data.settings;
}

export interface AdminAnalytics {
  days: number;
  totalOrders: number;
  totalValue: number;
  completedCount: number;
  byStatus: Record<OrderStatus, number>;
  topProducts: { name: string; qty: number }[];
}

export async function fetchAnalytics(days: number): Promise<AdminAnalytics> {
  return adminFetch<AdminAnalytics>(`/api/analytics?days=${days}`);
}

export async function uploadProductImage(slug: string, dataUrl: string): Promise<string> {
  const data = await adminFetch<{ updatedAt: string }>(`/api/images/product/${slug}`, {
    method: "POST",
    body: JSON.stringify({ dataUrl }),
  });
  return data.updatedAt;
}

export async function uploadCategoryImage(id: string, dataUrl: string): Promise<string> {
  const data = await adminFetch<{ updatedAt: string }>(`/api/images/category/${id}`, {
    method: "POST",
    body: JSON.stringify({ dataUrl }),
  });
  return data.updatedAt;
}
