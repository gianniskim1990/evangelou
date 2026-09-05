import type { MenuOverrides } from "../types";

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

/** Saves a partial overrides delta (only the categories being changed). */
export async function saveOverrides(delta: Partial<MenuOverrides>): Promise<MenuOverrides> {
  const password = getStoredPassword();
  const res = await fetch("/api/overrides", {
    method: "POST",
    headers: { "Content-Type": "application/json", "x-admin-password": password ?? "" },
    body: JSON.stringify(delta),
  });
  if (res.status === 401) {
    clearStoredPassword();
    throw new AdminAuthError("Η σύνδεση έληξε, συνδέσου ξανά.");
  }
  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new Error(body.error ?? `Αποτυχία αποθήκευσης (${res.status})`);
  }
  const data = await res.json();
  return data.overrides as MenuOverrides;
}

export async function resetOverrides(): Promise<MenuOverrides> {
  const password = getStoredPassword();
  const res = await fetch("/api/overrides", {
    method: "POST",
    headers: { "Content-Type": "application/json", "x-admin-password": password ?? "" },
    body: JSON.stringify({ reset: true }),
  });
  if (res.status === 401) {
    clearStoredPassword();
    throw new AdminAuthError("Η σύνδεση έληξε, συνδέσου ξανά.");
  }
  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new Error(body.error ?? `Αποτυχία επαναφοράς (${res.status})`);
  }
  const data = await res.json();
  return data.overrides as MenuOverrides;
}
