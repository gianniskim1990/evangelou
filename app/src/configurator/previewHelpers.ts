import { useEffect, useRef, useState } from "react";
import {
  CHOCOLATE_VISUALS,
  DEFAULT_TOPPING_VISUAL,
  NO_CHOCOLATE_VISUAL,
  TOPPING_VISUALS,
  type ChocolateVisual,
  type ToppingVisual,
} from "./previewAssets";

export function chocolateVisual(chocId: string | null): ChocolateVisual {
  if (!chocId) return NO_CHOCOLATE_VISUAL;
  return CHOCOLATE_VISUALS[chocId] ?? NO_CHOCOLATE_VISUAL;
}

export function toppingVisual(toppingId: string): ToppingVisual {
  return TOPPING_VISUALS[toppingId] ?? DEFAULT_TOPPING_VISUAL;
}

export interface TrackedTopping {
  id: string;
  exiting: boolean;
}

const EXIT_DURATION_MS = 200;

/**
 * Keeps a topping on screen slightly longer than `cfg.toppings` itself so
 * removing it can play a brief exit animation instead of vanishing
 * instantly. The enter side needs no extra state: a newly selected id is a
 * brand-new React key, so its CSS enter-animation plays automatically on
 * mount — this hook only has to manage the removal side, and cancels a
 * pending removal if the same topping is toggled back on before its exit
 * animation finishes (rapid on/off/on), so it never gets removed a beat
 * after popping back in.
 */
export function useToppingTransitions(currentIds: string[]): TrackedTopping[] {
  const [tracked, setTracked] = useState<TrackedTopping[]>(() => currentIds.map((id) => ({ id, exiting: false })));
  const timers = useRef<Map<string, number>>(new Map());

  useEffect(() => {
    setTracked((prev) => {
      const currentSet = new Set(currentIds);
      const prevIds = new Set(prev.map((t) => t.id));

      const next: TrackedTopping[] = prev.map((t) => {
        const stillSelected = currentSet.has(t.id);
        if (stillSelected && t.exiting) {
          const pending = timers.current.get(t.id);
          if (pending) {
            window.clearTimeout(pending);
            timers.current.delete(t.id);
          }
        }
        return stillSelected ? { ...t, exiting: false } : { ...t, exiting: true };
      });

      for (const id of currentIds) {
        if (!prevIds.has(id)) next.push({ id, exiting: false });
      }
      return next;
    });
  }, [currentIds]);

  useEffect(() => {
    for (const t of tracked) {
      if (t.exiting && !timers.current.has(t.id)) {
        const handle = window.setTimeout(() => {
          setTracked((prev) => prev.filter((x) => x.id !== t.id));
          timers.current.delete(t.id);
        }, EXIT_DURATION_MS);
        timers.current.set(t.id, handle);
      }
    }
  }, [tracked]);

  useEffect(() => {
    const timersMap = timers.current;
    return () => {
      timersMap.forEach((handle) => window.clearTimeout(handle));
      timersMap.clear();
    };
  }, []);

  return tracked;
}
