import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from "react";
import { categoryNames as baseCategoryNames, groups as baseGroups, products as baseProducts } from "./data/menu";
import { EMPTY_OVERRIDES, mergeMenu } from "./lib/menuOverrides";
import type { CatalogGroup, MenuOverrides, Product } from "./types";

interface MenuContextValue {
  products: Record<string, Product[]>;
  categoryNames: Record<string, string>;
  groups: CatalogGroup[];
  productImages: Record<string, string>;
  categoryImages: Record<string, string>;
  /** Only true for the very first fetch — a later refetch() (e.g. after an
   * admin save) updates data in place without flipping this back on, so
   * consumers that gate rendering on `loading` don't get unmounted (and
   * lose in-progress local edits) every time something is saved. */
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
  const hasLoadedOnce = useRef(false);

  const load = useCallback(() => {
    if (!hasLoadedOnce.current) setLoading(true);
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
      .finally(() => {
        hasLoadedOnce.current = true;
        setLoading(false);
      });
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const merged = mergeMenu(baseProducts, baseCategoryNames, baseGroups, overrides);

  return (
    <MenuContext.Provider
      value={{
        products: merged.products,
        categoryNames: merged.categoryNames,
        groups: merged.groups,
        productImages: merged.productImages,
        categoryImages: merged.categoryImages,
        loading,
        overridesUnavailable,
        refetch: load,
      }}
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
