import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
import { categoryNames as baseCategoryNames, products as baseProducts } from "./data/menu";
import { EMPTY_OVERRIDES, mergeMenu } from "./lib/menuOverrides";
import type { MenuOverrides, Product } from "./types";

interface MenuContextValue {
  products: Record<string, Product[]>;
  categoryNames: Record<string, string>;
  loading: boolean;
  /** True once we know the /api/overrides endpoint is unreachable (e.g. local `npm run dev`
   * without `vercel dev`, or the site deployed without the storage integration configured yet). */
  overridesUnavailable: boolean;
  refetch: () => void;
}

const MenuContext = createContext<MenuContextValue | null>(null);

export function MenuProvider({ children }: { children: ReactNode }) {
  const [overrides, setOverrides] = useState<MenuOverrides | null>(null);
  const [loading, setLoading] = useState(true);
  const [overridesUnavailable, setOverridesUnavailable] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    fetch("/api/overrides")
      .then((res) => (res.ok ? res.json() : Promise.reject(new Error(`status ${res.status}`))))
      .then((data: MenuOverrides) => {
        setOverrides(data);
        setOverridesUnavailable(false);
      })
      .catch(() => {
        // No overrides API reachable (local dev, or storage not set up yet) — just
        // use the seed data from data/menu.ts unmodified.
        setOverrides(EMPTY_OVERRIDES);
        setOverridesUnavailable(true);
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const merged = mergeMenu(baseProducts, baseCategoryNames, overrides);

  return (
    <MenuContext.Provider
      value={{ products: merged.products, categoryNames: merged.categoryNames, loading, overridesUnavailable, refetch: load }}
    >
      {children}
    </MenuContext.Provider>
  );
}

export function useMenu(): MenuContextValue {
  const ctx = useContext(MenuContext);
  if (!ctx) throw new Error("useMenu must be used within MenuProvider");
  return ctx;
}
