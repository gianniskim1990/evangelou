import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from "react";
import type { StoredOrder } from "../types";
import { AdminAuthError, fetchAdminOrders } from "./adminApi";
import { playOrderChime } from "../lib/orderSound";

const SOUND_PREF_KEY = "evaggelou-admin-order-sound";
const POLL_MS = 12000;

interface AdminOrderAlertsValue {
  orders: StoredOrder[];
  loading: boolean;
  error: string;
  soundEnabled: boolean;
  toggleSound: () => void;
  testChime: () => void;
  refetch: () => void;
}

const Ctx = createContext<AdminOrderAlertsValue | null>(null);

function loadSoundPref(): boolean {
  try {
    return localStorage.getItem(SOUND_PREF_KEY) !== "off";
  } catch {
    return true;
  }
}

export function AdminOrderAlertsProvider({ children, onAuthExpired }: { children: ReactNode; onAuthExpired: () => void }) {
  const [orders, setOrders] = useState<StoredOrder[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [soundEnabled, setSoundEnabled] = useState(loadSoundPref);
  const seenNewIds = useRef<Set<string> | null>(null);
  const soundEnabledRef = useRef(soundEnabled);
  useEffect(() => {
    soundEnabledRef.current = soundEnabled;
  }, [soundEnabled]);

  const poll = useCallback(async () => {
    try {
      const fetched = await fetchAdminOrders();
      const currentNewIds = new Set(fetched.filter((o) => o.status === "new").map((o) => o.orderNumber));
      if (seenNewIds.current === null) {
        // First load — just record what's already there, don't alarm for it.
        seenNewIds.current = currentNewIds;
      } else {
        const hasFreshOrder = [...currentNewIds].some((id) => !seenNewIds.current!.has(id));
        if (hasFreshOrder && soundEnabledRef.current) playOrderChime();
        seenNewIds.current = currentNewIds;
      }
      setOrders(fetched);
      setError("");
    } catch (err) {
      if (err instanceof AdminAuthError) {
        onAuthExpired();
        return;
      }
      setError(err instanceof Error ? err.message : "Αποτυχία φόρτωσης παραγγελιών.");
    } finally {
      setLoading(false);
    }
  }, [onAuthExpired]);

  useEffect(() => {
    poll();
    const id = window.setInterval(poll, POLL_MS);
    return () => window.clearInterval(id);
  }, [poll]);

  const toggleSound = useCallback(() => {
    setSoundEnabled((prev) => {
      const next = !prev;
      try {
        localStorage.setItem(SOUND_PREF_KEY, next ? "on" : "off");
      } catch {
        // ignore
      }
      return next;
    });
  }, []);

  const testChime = useCallback(() => playOrderChime(), []);

  return (
    <Ctx.Provider value={{ orders, loading, error, soundEnabled, toggleSound, testChime, refetch: poll }}>
      {children}
    </Ctx.Provider>
  );
}

export function useAdminOrderAlerts(): AdminOrderAlertsValue {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error("useAdminOrderAlerts must be used within AdminOrderAlertsProvider");
  return ctx;
}
