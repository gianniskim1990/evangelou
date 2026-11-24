import { visibleGroups, type Catalogue, type CatalogueCoffee, type CatalogueGroup } from "../../club/preparationContract";

/*
 * Owner decision D24 (2026-11-20): the till's step-by-step coffee order (pure, no DOM).
 *
 * The SERVER's approved catalogue (GET /coffee-catalogue, EVC_Club_Prep_Catalogue_Authority) decides which
 * steps a coffee has: «ανάλογα με τον καφέ πήγαινε στο ανάλογο βήμα». The order holds the coffee, one option
 * per group, quick notes and the short staff note. Rules (the same as the server's, which stays authoritative):
 * - a group is a step only while it is visible (e.g. the sweetener only when there is sugar); choosing an
 *   option that hides a group drops that group's choice; nothing is ever chosen for the staff (no defaults);
 * - the REQUIRED visible groups are the steps, in catalogue order; the optional ones (ice, foam, caffeine),
 *   the quick notes and the note are on the review screen, so the usual order needs one tap per step;
 * - quick notes only if the coffee offers them, at most one per exclusive set (the newest wins), at most
 *   maxQuickNotes;
 * - the note: no `<` or `>`, at most NOTE_MAX characters (the server refuses longer, never cuts);
 * - a pre-fill («Το ίδιο με την τελευταία φορά») goes through the same rules, so an option the catalogue no
 *   longer offers is never carried over: staff choose it again. A pre-fill is a suggestion, never a
 *   confirmed order (D-USUAL): staff review and press «Καταχώρηση καφέ».
 */

export const NOTE_MAX = 80;

export interface PreparedOrder {
  coffee: string | null;
  /** group id -> option id */
  choices: Readonly<Record<string, string>>;
  quickNotes: readonly string[];
  /** As typed (sanitised); trimmed and collapsed only when sent. */
  note: string;
}

export const EMPTY_ORDER: PreparedOrder = Object.freeze({ coffee: null, choices: Object.freeze({}), quickNotes: Object.freeze([]), note: "" });

export interface OrderPrefill {
  catalogueVersion: number;
  coffeeCode: string;
  modifiers: readonly { group: string; option: string }[];
  quickNotes: readonly string[];
  note: string | null;
}

function coffeeOf(catalogue: Catalogue, order: PreparedOrder): CatalogueCoffee | null {
  return order.coffee === null ? null : (catalogue.coffees.get(order.coffee) ?? null);
}

function selections(order: PreparedOrder): Map<string, string> {
  return new Map(Object.entries(order.choices));
}

/** The visible groups of the order's coffee, in catalogue order. */
export function visibleOrderGroups(catalogue: Catalogue, order: PreparedOrder): CatalogueGroup[] {
  const coffee = coffeeOf(catalogue, order);
  if (!coffee) return [];
  const visible = visibleGroups(coffee, selections(order));
  return coffee.groups.filter((g) => visible.includes(g.id));
}

/** Required visible groups still without a choice (the steps left), in catalogue order. */
export function missingSteps(catalogue: Catalogue, order: PreparedOrder): CatalogueGroup[] {
  return visibleOrderGroups(catalogue, order).filter((g) => g.required && order.choices[g.id] === undefined);
}

export function isComplete(catalogue: Catalogue, order: PreparedOrder): boolean {
  return coffeeOf(catalogue, order) !== null && missingSteps(catalogue, order).length === 0;
}

/** Drops every choice / quick note the coffee does not offer (or that is hidden now), and caps the note. */
export function normalizeOrder(catalogue: Catalogue, order: PreparedOrder): PreparedOrder {
  const coffee = coffeeOf(catalogue, order);
  if (!coffee) return EMPTY_ORDER;
  const choices: Record<string, string> = {};
  for (const g of coffee.groups) {
    const v = order.choices[g.id];
    if (v !== undefined && g.options.some((o) => o.id === v)) choices[g.id] = v;
  }
  // A dropped choice can hide another group (a rule never chains: UI-03C), so settle twice.
  for (let i = 0; i < 2; i++) {
    const visible = visibleGroups(coffee, new Map(Object.entries(choices)));
    for (const id of Object.keys(choices)) if (!visible.includes(id)) delete choices[id];
  }
  return { coffee: coffee.code, choices, quickNotes: resolveQuickNotes(catalogue, coffee, order.quickNotes), note: catalogue.freeTextNote ? sanitizeNote(order.note) : "" };
}

/** Allowed for this coffee, newest wins inside an exclusive set, at most maxQuickNotes (oldest dropped). */
function resolveQuickNotes(catalogue: Catalogue, coffee: CatalogueCoffee, ids: readonly string[]): string[] {
  const allowed = ids.filter((id, i) => coffee.quickNotes.includes(id) && ids.indexOf(id) === i);
  const kept = allowed.filter((id, i) => {
    const set = catalogue.exclusive.get(id);
    return set === undefined || !allowed.slice(i + 1).some((later) => catalogue.exclusive.get(later) === set);
  });
  return kept.slice(Math.max(0, kept.length - catalogue.maxQuickNotes));
}

/**
 * Typing filter: no `<` / `>` (the server refuses HTML), no control characters, at most NOTE_MAX characters counted
 * as Unicode code points like the server (EVC_Prep_Note). Round 10 NIT-3: the field has no maxLength, which counts
 * UTF-16 units and would stop at 40 emoji; this cut is the only limit.
 */
export function sanitizeNote(raw: string): string {
  const cleaned = raw.replace(/[<>]/g, "").replace(/[\p{Cc}\p{Cf}]/gu, " ");
  return [...cleaned].slice(0, NOTE_MAX).join("");
}

/** Round 11 (C1): the Greek notice when an edit of the note is refused for its length. */
export const NOTE_TOO_LONG = `Η σημείωση χωράει έως ${NOTE_MAX} χαρακτήρες· η αλλαγή δεν έγινε.`;

/**
 * STAGE-08 round 11 (C1, round-10 review): an edit (typing, paste, the end of an IME composition) that would make
 * the note longer than NOTE_MAX code points is REFUSED as a whole: the previous note stays, nothing is cut off its
 * end. Otherwise the typing filter applies (no `<` / `>`, control and format characters become spaces).
 */
export function acceptNoteEdit(previous: string, next: string): { note: string; refused: boolean } {
  const cleaned = next.replace(/[<>]/g, "").replace(/[\p{Cc}\p{Cf}]/gu, " ");
  if ([...cleaned].length > NOTE_MAX) return { note: previous, refused: true };
  return { note: cleaned, refused: false };
}

/** What is sent: white space collapsed and trimmed (as EVC_Prep_Note does); empty = no note. */
export function noteToSend(note: string): string | null {
  const text = sanitizeNote(note).replace(/[\s\p{Z}]+/gu, " ").trim();
  return text === "" ? null : text;
}

export function selectCoffee(catalogue: Catalogue, order: PreparedOrder, code: string): PreparedOrder {
  if (!catalogue.coffees.has(code)) return order;
  // Another coffee keeps what still applies (e.g. the sugar), and drops the rest.
  return normalizeOrder(catalogue, { ...order, coffee: code });
}

export function choose(catalogue: Catalogue, order: PreparedOrder, group: string, option: string): PreparedOrder {
  const coffee = coffeeOf(catalogue, order);
  const g = coffee?.groups.find((x) => x.id === group);
  if (!coffee || !g || !g.options.some((o) => o.id === option) || !visibleOrderGroups(catalogue, order).some((x) => x.id === group)) return order;
  return normalizeOrder(catalogue, { ...order, choices: { ...order.choices, [group]: option } });
}

/** Clears an OPTIONAL group (e.g. «Χωρίς πάγο» again); a required one is changed, never cleared. */
export function clearOptional(catalogue: Catalogue, order: PreparedOrder, group: string): PreparedOrder {
  const g = coffeeOf(catalogue, order)?.groups.find((x) => x.id === group);
  if (!g || g.required || order.choices[group] === undefined) return order;
  const choices = { ...order.choices };
  delete choices[group];
  return normalizeOrder(catalogue, { ...order, choices });
}

export function toggleQuickNote(catalogue: Catalogue, order: PreparedOrder, id: string): PreparedOrder {
  const coffee = coffeeOf(catalogue, order);
  if (!coffee || !coffee.quickNotes.includes(id)) return order;
  const next = order.quickNotes.includes(id) ? order.quickNotes.filter((q) => q !== id) : [...order.quickNotes, id];
  return { ...order, quickNotes: resolveQuickNotes(catalogue, coffee, next) };
}

export function setNote(catalogue: Catalogue, order: PreparedOrder, note: string): PreparedOrder {
  if (!catalogue.freeTextNote) return order;
  // Round 11 (C1): too long = refused (the order keeps its note), never cut.
  return { ...order, note: acceptNoteEdit(order.note, note).note };
}

/** «Το ίδιο με την τελευταία φορά»: only what the current catalogue offers is taken over. */
export function fromPrefill(catalogue: Catalogue, prefill: OrderPrefill): PreparedOrder {
  if (!catalogue.coffees.has(prefill.coffeeCode)) return EMPTY_ORDER;
  const choices: Record<string, string> = {};
  for (const m of prefill.modifiers) choices[m.group] = m.option;
  return normalizeOrder(catalogue, { coffee: prefill.coffeeCode, choices, quickNotes: [...prefill.quickNotes], note: prefill.note ?? "" });
}

export interface PreparedBody {
  request_id: string;
  catalogue_version: number;
  coffee_code: string;
  preparation: { modifiers: { group: string; option: string }[]; quick_notes: string[]; note: string | null };
}

/** The request body for a COMPLETE order (null otherwise): exactly the four keys the server accepts. */
export function preparedBody(catalogue: Catalogue, order: PreparedOrder, requestId: string): PreparedBody | null {
  const normal = normalizeOrder(catalogue, order);
  if (!isComplete(catalogue, normal) || normal.coffee === null) return null;
  const coffee = catalogue.coffees.get(normal.coffee)!;
  return {
    request_id: requestId,
    catalogue_version: catalogue.version,
    coffee_code: normal.coffee,
    preparation: {
      modifiers: coffee.groups.filter((g) => normal.choices[g.id] !== undefined).map((g) => ({ group: g.id, option: normal.choices[g.id] })),
      quick_notes: [...normal.quickNotes],
      note: catalogue.freeTextNote ? noteToSend(normal.note) : null,
    },
  };
}

/** The identity of an order for the request id: the same order (and note) keeps the id, any change gets a new one. */
export function orderKey(catalogue: Catalogue, memberId: string, order: PreparedOrder): string {
  const body = preparedBody(catalogue, order, "-");
  return JSON.stringify([memberId, body?.catalogue_version ?? null, body?.coffee_code ?? null, body?.preparation ?? null]);
}

/** Review rows: [group label, option label] in step order. */
export function reviewRows(catalogue: Catalogue, order: PreparedOrder): [string, string][] {
  const coffee = coffeeOf(catalogue, order);
  if (!coffee) return [];
  const rows: [string, string][] = [["Καφές", coffee.label]];
  for (const g of visibleOrderGroups(catalogue, order)) {
    const v = order.choices[g.id];
    if (v !== undefined) rows.push([g.label, g.options.find((o) => o.id === v)?.label ?? v]);
  }
  return rows;
}

/** One line for the confirmation notice: «Freddo Espresso · Μεγάλο μέγεθος · Διπλός …». */
export function summaryLine(catalogue: Catalogue, order: PreparedOrder): string {
  const coffee = coffeeOf(catalogue, order);
  if (!coffee) return "";
  const parts = reviewRows(catalogue, order).slice(1).map(([, label]) => label);
  for (const id of order.quickNotes) parts.push(catalogue.quickNotes.get(id)?.label ?? id);
  return [coffee.label, ...parts].join(" · ");
}
