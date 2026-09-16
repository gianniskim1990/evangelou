import { useEffect, useRef, useState } from "react";
import {
  BASE_STATE_IMAGES,
  CHOCOLATE_VISUALS,
  DEFAULT_TOPPING_VISUAL,
  NO_CHOCOLATE_VISUAL,
  STATE_IMAGES,
  TOPPING_VISUALS,
  type ChocolateVisual,
  type ToppingVisual,
} from "./previewAssets";

/**
 * Module-level cache of image-URL → loaded-successfully, shared by every
 * useAssetAvailability() caller. A src is probed at most once for the
 * whole session (not once per component instance), so mounting/unmounting
 * the preview while switching configurator steps never re-triggers a
 * network request for an asset already known to exist (or not).
 */
const assetAvailabilityCache = new Map<string, boolean>();
const assetAvailabilityListeners = new Map<string, Set<() => void>>();

function probeAsset(src: string): void {
  if (assetAvailabilityCache.has(src)) return;
  const img = new Image();
  const settle = (ok: boolean) => {
    assetAvailabilityCache.set(src, ok);
    assetAvailabilityListeners.get(src)?.forEach((notify) => notify());
    assetAvailabilityListeners.delete(src);
  };
  img.onload = () => settle(true);
  img.onerror = () => settle(false);
  img.src = src;
}

export type AssetProbeStatus = "unknown" | "loaded" | "failed";

/**
 * Tri-state probe result for `src`: "unknown" while pending (or if `src`
 * is undefined), "loaded"/"failed" once resolved. Used wherever telling
 * "still loading" apart from "confirmed missing" matters (see
 * usePhotoPreviewState below); useAssetAvailability is a thin boolean
 * wrapper for callers that only care about the loaded case.
 */
export function useAssetProbeStatus(src: string | undefined): AssetProbeStatus {
  const [, bump] = useState(0);

  useEffect(() => {
    if (!src || assetAvailabilityCache.has(src)) return;
    probeAsset(src);
    let listeners = assetAvailabilityListeners.get(src);
    if (!listeners) {
      listeners = new Set();
      assetAvailabilityListeners.set(src, listeners);
    }
    const notify = () => bump((n) => n + 1);
    listeners.add(notify);
    return () => {
      listeners!.delete(notify);
    };
  }, [src]);

  if (!src || !assetAvailabilityCache.has(src)) return "unknown";
  return assetAvailabilityCache.get(src) ? "loaded" : "failed";
}

/**
 * True once `src` is confirmed to load; false while unknown/pending and if
 * it fails to load (or if `src` is undefined). Used independently per
 * topping to decide whether a sprite is available.
 */
export function useAssetAvailability(src: string | undefined): boolean {
  return useAssetProbeStatus(src) === "loaded";
}

export function chocolateVisual(chocId: string | null): ChocolateVisual {
  if (!chocId) return NO_CHOCOLATE_VISUAL;
  return CHOCOLATE_VISUALS[chocId] ?? NO_CHOCOLATE_VISUAL;
}

export function toppingVisual(toppingId: string): ToppingVisual {
  return TOPPING_VISUALS[toppingId] ?? DEFAULT_TOPPING_VISUAL;
}

/** The full-frame state image URL for a chocolate selection (or the "base" state when none is chosen yet) — undefined if that selection has no photo state defined at all. Gate with useAssetAvailability() before rendering it. */
export function stateImageFor(chocId: string | null, baseId: string | null = null): string | undefined {
  const stateKey = chocId ?? "base";

  if (baseId) {
    const familySrc = BASE_STATE_IMAGES[baseId]?.[stateKey];
    if (familySrc) return familySrc;
  }

  return STATE_IMAGES[stateKey];
}

const STATE_CROSSFADE_MS = 550;

interface ShownState {
  current: string;
  previous: string | null;
}

export interface PhotoPreviewState {
  mode: "photo" | "procedural";
  current: string | null;
  previous: string | null;
}

/**
 * The single source of truth for "should the preview show a photo right
 * now, and which one" for a given target state-image URL (from
 * stateImageFor()). This intentionally does NOT flip to procedural the
 * moment a newly-selected target is still probing — probing a real,
 * existing file normally resolves within a frame or two, and flipping to
 * procedural in that gap would flash the illustration on every single
 * sauce switch. Instead it keeps showing the last confirmed-good photo
 * (sticky) until the new target resolves — either becoming the new
 * current photo (crossfade) once loaded, or, if genuinely absent
 * (`status === "failed"`), dropping to procedural immediately so a
 * stale photo of a *different* chocolate is never shown for a selection
 * that has none. Only returns "procedural" before anything has ever
 * loaded (first paint) or once a target is confirmed missing.
 */
export function usePhotoPreviewState(targetSrc: string | undefined): PhotoPreviewState {
  const status = useAssetProbeStatus(targetSrc);
  const [shown, setShown] = useState<ShownState | null>(() =>
    targetSrc && assetAvailabilityCache.get(targetSrc) === true ? { current: targetSrc, previous: null } : null,
  );

  useEffect(() => {
    if (!targetSrc || status !== "loaded") return;
    setShown((prev) => {
      if (prev && prev.current === targetSrc) return prev;
      return { current: targetSrc, previous: prev ? prev.current : null };
    });
  }, [targetSrc, status]);

  useEffect(() => {
    if (!shown?.previous) return;
    const settled = shown;
    const handle = window.setTimeout(() => {
      setShown((s) => (s === settled ? { current: s.current, previous: null } : s));
    }, STATE_CROSSFADE_MS);
    return () => window.clearTimeout(handle);
  }, [shown]);

  if (status === "failed") return { mode: "procedural", current: null, previous: null };
  if (shown) return { mode: "photo", current: shown.current, previous: shown.previous };
  return { mode: "procedural", current: null, previous: null };
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
