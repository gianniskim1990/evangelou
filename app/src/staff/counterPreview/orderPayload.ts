import { isComplete, visibleGroups, type CoffeeOrder } from "../../preview/coffeeConfig";
import type { CoffeeCode } from "../../preview/previewData";
import type { PreparedOrderPayload } from "../redemptionRecovery/machine";

/**
 * UI-04O: the approved UI-02 configurator keeps its illustrative hyphenated
 * coffee codes; the redemption contract (UI-03C) uses canonical underscore
 * codes. This is the same mapping the UI-03C parity test pins
 * (tests/club/preparationContract.test.ts), applied to one order:
 *  - coffee codes: freddo-espresso -> freddo_espresso, greek-coffee ->
 *    greek_coffee, filter -> filter_coffee, the rest unchanged;
 *  - every visible single-choice group keeps its id and option id;
 *  - the "decaf" extra becomes the optional `caffeine` group (option `decaf`);
 *  - quick notes keep their typed ids. There is no free text of any kind.
 *
 * An incomplete order yields null: nothing is ever filled in automatically.
 */

/** Version of the illustrative catalogue fixture (status "illustrative", NOT an approved menu). */
export const PREVIEW_CATALOGUE_VERSION = 1;

export const CANONICAL_COFFEE: Readonly<Record<CoffeeCode, string>> = {
  espresso: "espresso",
  "freddo-espresso": "freddo_espresso",
  cappuccino: "cappuccino",
  "freddo-cappuccino": "freddo_cappuccino",
  "greek-coffee": "greek_coffee",
  filter: "filter_coffee",
};

export function toPreparedPayload(order: CoffeeOrder): PreparedOrderPayload | null {
  if (!order.coffee || !isComplete(order)) return null;
  const modifiers: { group: string; option: string }[] = [];
  for (const group of visibleGroups(order)) {
    const option = order.choices[group];
    if (option !== undefined) modifiers.push({ group, option });
  }
  if (order.extras.includes("decaf")) modifiers.push({ group: "caffeine", option: "decaf" });
  return {
    catalogueVersion: PREVIEW_CATALOGUE_VERSION,
    coffeeCode: CANONICAL_COFFEE[order.coffee],
    preparation: { modifiers, quick_notes: [...order.quickNotes] },
  };
}
