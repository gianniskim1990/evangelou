import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
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
};

interface SettingsContextValue {
  settings: StoreSettings;
  loading: boolean;
  settingsUnavailable: boolean;
  refetch: () => void;
}

const SettingsContext = createContext<SettingsContextValue | null>(null);

export function SettingsProvider({ children }: { children: ReactNode }) {
  const [settings, setSettings] = useState<StoreSettings>(DEFAULT_SETTINGS);
  const [loading, setLoading] = useState(true);
  const [settingsUnavailable, setSettingsUnavailable] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    fetch("/api/settings")
      .then((res) => (res.ok ? res.json() : Promise.reject(new Error(`status ${res.status}`))))
      .then((data: StoreSettings) => {
        setSettings(data);
        setSettingsUnavailable(false);
      })
      .catch(() => {
        setSettings(DEFAULT_SETTINGS);
        setSettingsUnavailable(true);
      })
      .finally(() => setLoading(false));
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
