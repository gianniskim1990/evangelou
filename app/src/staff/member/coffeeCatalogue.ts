/*
 * STAGE-05D: the coffees the counter can record with a free coffee, EXACTLY
 * the server's approved list EVC_Approved_Coffee_Catalogue_Source
 * (wordpress/evangelou-club/includes/staff/class-evc-redemption-catalogue-source.php,
 * VERSION evc-coffee-v1-2026-11-03, owner decision D3 2026-11-03: "any coffee",
 * with the catch-all «Άλλος καφές»). The coffee never affects eligibility;
 * the server stays authoritative (an unknown code is refused, invalid_coffee).
 * tests/staff/memberCounter.test.ts reads the PHP file and requires the same
 * codes, labels and order, so the two cannot drift apart silently.
 */

export const COFFEE_CATALOGUE_VERSION = "evc-coffee-v1-2026-11-03";

export interface CounterCoffee {
  code: string;
  label: string;
}

export const COUNTER_COFFEES: readonly CounterCoffee[] = Object.freeze([
  { code: "espresso", label: "Espresso" },
  { code: "freddo_espresso", label: "Freddo Espresso" },
  { code: "cappuccino", label: "Cappuccino" },
  { code: "freddo_cappuccino", label: "Freddo Cappuccino" },
  { code: "greek_coffee", label: "Ελληνικός" },
  { code: "filter_coffee", label: "Φίλτρου" },
  { code: "other_coffee", label: "Άλλος καφές" },
]);

export function isCounterCoffee(code: unknown): code is string {
  return typeof code === "string" && COUNTER_COFFEES.some((c) => c.code === code);
}
