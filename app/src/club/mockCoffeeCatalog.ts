import type { CoffeeOption } from "./types";

/**
 * DEMO DATA ONLY — not the shop's final menu. The production coffee list is
 * an open owner decision (D3) and will come from the backend allowlist.
 * Codes match the Task 1B PHP test fixture so mock and engine agree.
 */
export const DEMO_COFFEE_OPTIONS: readonly CoffeeOption[] = [
  { code: "espresso", label: "Espresso" },
  { code: "freddo_espresso", label: "Freddo espresso" },
  { code: "cappuccino", label: "Cappuccino" },
  { code: "freddo_cappuccino", label: "Freddo cappuccino" },
  { code: "greek_coffee", label: "Ελληνικός" },
  { code: "filter_coffee", label: "Φίλτρου" },
];

export function coffeeLabel(options: readonly CoffeeOption[], code: string | null | undefined): string | null {
  if (!code) return null;
  return options.find((o) => o.code === code)?.label ?? null;
}
