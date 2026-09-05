import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from "react";
import { store as defaultStore } from "./data/menu";
import { defaultHours } from "./lib/hours";
import type { StoreSettings } from "./types";

export const DEFAULT_SETTINGS: StoreSettings = {
  name: defaultStore.name,
  address: defaultStore.address,
  phone: defaultStore.phone,
  phoneHref: defaultStore.phoneHref,
  instagram: defaultStore.instagram,
  deliveryMinOrder: defaultStore.deliveryMinOrder,
  deliveryFee: defaultStore.deliveryFee,
  hours: defaultHours(),
  deliveryEtaMinMinutes: 35,
  deliveryEtaMaxMinutes: 45,
  pickupPrepMinutes: 20,
};

interface SettingsContextValue {
  settings: StoreSettings;
  /** Only true for the very first fetch — see the same note on MenuContext's
   * `loading`, which this mirrors for the same reason (don't unmount admin
   * forms on every post-save refetch). */
  loading: boolean;
  settingsUnavailable: boolean;
  refetch: () => void;
}

const SettingsContext = createContext<SettingsContextValue | null>(null);

export function SettingsProvider({ children }: { children: ReactNode }) {
  const [settings, setSettings] = useState<StoreSettings>(DEFAULT_SETTINGS);
  const [loading, setLoading] = useState(true);
  const [settingsUnavailable, setSettingsUnavailable] = useState(false);
  const hasLoadedOnce = useRef(false);

  const load = useCallback(() => {
    if (!hasLoadedOnce.current) setLoading(true);
    fetch("/api/settings")
      .then((res) => (res.ok ? res.json() : Promise.reject(new Error(`status ${res.status}`))))
      .then((data: Partial<StoreSettings>) => {
        // Merge over the defaults so a settings doc saved before a field
        // existed (e.g. the ETA/prep-time settings) doesn't end up missing it.
        setSettings({ ...DEFAULT_SETTINGS, ...data });
        setSettingsUnavailable(false);
      })
      .catch(() => {
        setSettings(DEFAULT_SETTINGS);
        setSettingsUnavailable(true);
      })
      .finally(() => {
        hasLoadedOnce.current = true;
        setLoading(false);
      });
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  return (
    <SettingsContext.Provider value={{ settings, loading, settingsUnavailable, refetch: load }}>
      {children}
    </SettingsContext.Provider>
  );
}

export function useSettings(): SettingsContextValue {
  const ctx = useContext(SettingsContext);
  if (!ctx) throw new Error("useSettings must be used within SettingsProvider");
  return ctx;
}
