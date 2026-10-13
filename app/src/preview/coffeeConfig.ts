/**
 * UI-02.R1 design preview: staff coffee configuration model (pure, typed,
 * preview-only). Describes which preparation choices a coffee type supports,
 * keeps an order consistent when the coffee changes, and defines the
 * DESIGN-ONLY shape of a future redemption snapshot.
 *
 * Everything here is illustrative: the eligible coffee catalogue, modifier
 * availability and any extra costs are NOT owner-approved. Nothing is sent,
 * stored or redeemed.
 */
import { coffeeName, type CoffeeCode, type PreviewMember } from "./previewData";

// ---- Catalogue of preparation choices ----------------------------------------------------

/** Single-choice groups, in the order staff see them. */
export type GroupId = "shots" | "sweetness" | "sweetener" | "milk" | "ice" | "foam";
/** Independent on/off extras. */
export type ExtraId = "decaf";

export type ChoiceOption = { id: string; label: string; summary: string };
export type ChoiceGroup = { id: GroupId; label: string; required: boolean; options: ChoiceOption[] };

export const GROUPS: Record<GroupId, ChoiceGroup> = {
  shots: {
    id: "shots",
    label: "Δόση",
    required: true,
    options: [
      { id: "single", label: "Μονός", summary: "μονός" },
      { id: "double", label: "Διπλός", summary: "διπλός" },
    ],
  },
  sweetness: {
    id: "sweetness",
    label: "Ζάχαρη",
    required: true,
    options: [
      { id: "none", label: "Σκέτος", summary: "σκέτος" },
      { id: "little", label: "Ολίγη", summary: "με ολίγη" },
      { id: "medium", label: "Μέτριος", summary: "μέτριος" },
      { id: "sweet", label: "Γλυκός", summary: "γλυκός" },
      { id: "very_sweet", label: "Πολύ γλυκός", summary: "πολύ γλυκός" },
    ],
  },
  sweetener: {
    id: "sweetener",
    label: "Γλυκαντικό",
    required: true,
    options: [
      { id: "white", label: "Λευκή ζάχαρη", summary: "λευκή ζάχαρη" },
      { id: "brown", label: "Καστανή ζάχαρη", summary: "καστανή ζάχαρη" },
      { id: "stevia", label: "Στέβια", summary: "στέβια" },
    ],
  },
  milk: {
    id: "milk",
    label: "Γάλα",
    required: true,
    options: [
      { id: "none", label: "Χωρίς γάλα", summary: "χωρίς γάλα" },
      { id: "regular", label: "Κανονικό", summary: "κανονικό γάλα" },
      { id: "lactose_free", label: "Χωρίς λακτόζη", summary: "γάλα χωρίς λακτόζη" },
      { id: "plant", label: "Φυτικό", summary: "φυτικό γάλα" },
    ],
  },
  ice: {
    id: "ice",
    label: "Πάγος",
    required: false,
    options: [
      { id: "little", label: "Λίγος πάγος", summary: "λίγος πάγος" },
      { id: "extra", label: "Πολύς πάγος", summary: "πολύς πάγος" },
    ],
  },
  foam: {
    id: "foam",
    label: "Αφρόγαλα",
    required: false,
    options: [
      { id: "little", label: "Λίγος αφρός", summary: "λίγος αφρός" },
      { id: "extra", label: "Έξτρα αφρός", summary: "έξτρα αφρός" },
    ],
  },
};

export const EXTRAS: Record<ExtraId, { id: ExtraId; label: string; summary: string }> = {
  decaf: { id: "decaf", label: "Χωρίς καφεΐνη", summary: "χωρίς καφεΐνη" },
};

/** What each (illustrative) coffee supports: groups with their allowed options, plus extras. */
type Profile = { groups: Partial<Record<GroupId, string[]>>; extras: ExtraId[] };

const SWEET = ["none", "little", "medium", "sweet", "very_sweet"];
const SWEETENERS = ["white", "brown", "stevia"];
const SHOTS = ["single", "double"];

export const PROFILES: Record<CoffeeCode, Profile> = {
  espresso: { groups: { shots: SHOTS, sweetness: SWEET, sweetener: SWEETENERS }, extras: ["decaf"] },
  "freddo-espresso": { groups: { shots: SHOTS, sweetness: SWEET, sweetener: SWEETENERS, milk: ["none", "regular", "lactose_free", "plant"], ice: ["little", "extra"] }, extras: ["decaf"] },
  cappuccino: { groups: { shots: SHOTS, sweetness: SWEET, sweetener: SWEETENERS, milk: ["regular", "lactose_free", "plant"], foam: ["little", "extra"] }, extras: ["decaf"] },
  "freddo-cappuccino": { groups: { shots: SHOTS, sweetness: SWEET, sweetener: SWEETENERS, milk: ["regular", "lactose_free", "plant"], ice: ["little", "extra"], foam: ["little", "extra"] }, extras: ["decaf"] },
  "greek-coffee": { groups: { shots: SHOTS, sweetness: SWEET, sweetener: SWEETENERS }, extras: [] },
  filter: { groups: { sweetness: SWEET, sweetener: SWEETENERS, milk: ["none", "regular", "lactose_free", "plant"] }, extras: ["decaf"] },
};

/** Shown on the Greek coffee: "Δόση" reads as the cup size there. */
export function groupLabel(coffee: CoffeeCode | null, group: GroupId): string {
  if (group === "shots" && coffee === "greek-coffee") return "Μέγεθος";
  return GROUPS[group].label;
}

// ---- Order ---------------------------------------------------------------------------------

export type CoffeeOrder = {
  coffee: CoffeeCode | null;
  choices: Partial<Record<GroupId, string>>;
  extras: ExtraId[];
  note: string;
};

export const EMPTY_ORDER: CoffeeOrder = { coffee: null, choices: {}, extras: [], note: "" };

export const NOTE_MAX = 80;
export const NOTE_SUGGESTIONS = ["λίγο πάγο", "έξτρα αφρό", "πολύ ζεστός", "σε ποτήρι για έξω"];

const ORDER: GroupId[] = ["shots", "sweetness", "sweetener", "milk", "ice", "foam"];

/** Groups that apply right now. The sweetener only matters once some sweetness is chosen. */
export function visibleGroups(order: CoffeeOrder): GroupId[] {
  if (!order.coffee) return [];
  const profile = PROFILES[order.coffee];
  return ORDER.filter((g) => profile.groups[g] && (g !== "sweetener" || (order.choices.sweetness !== undefined && order.choices.sweetness !== "none")));
}

export function optionsFor(coffee: CoffeeCode, group: GroupId): ChoiceOption[] {
  const allowed = PROFILES[coffee].groups[group] ?? [];
  return GROUPS[group].options.filter((o) => allowed.includes(o.id));
}

export function extrasFor(coffee: CoffeeCode | null): ExtraId[] {
  return coffee ? PROFILES[coffee].extras : [];
}

/** Drop every choice or extra the current coffee (or sweetness) no longer supports. */
function normalize(order: CoffeeOrder): CoffeeOrder {
  if (!order.coffee) return { ...order, choices: {}, extras: [] };
  const visible = visibleGroups(order);
  const coffee = order.coffee;
  const choices: Partial<Record<GroupId, string>> = {};
  for (const g of visible) {
    const v = order.choices[g];
    if (v !== undefined && optionsFor(coffee, g).some((o) => o.id === v)) choices[g] = v;
  }
  // Removing an unsupported sweetness can hide the sweetener: settle once more.
  const settled = { ...order, choices };
  const again = visibleGroups(settled);
  for (const g of Object.keys(choices) as GroupId[]) if (!again.includes(g)) delete choices[g];
  return { ...settled, choices, extras: order.extras.filter((e) => PROFILES[coffee].extras.includes(e)) };
}

export function selectCoffee(order: CoffeeOrder, coffee: CoffeeCode): CoffeeOrder {
  return normalize({ ...order, coffee });
}

/** Set (or clear with null) one choice; options the coffee does not support are ignored. */
export function setChoice(order: CoffeeOrder, group: GroupId, value: string | null): CoffeeOrder {
  if (!order.coffee) return order;
  if (value === null) return normalize({ ...order, choices: { ...order.choices, [group]: undefined } });
  const supported = (PROFILES[order.coffee].groups[group] ?? []).includes(value);
  const reachable = group !== "sweetener" || visibleGroups(order).includes("sweetener");
  if (!supported || !reachable) return order;
  return normalize({ ...order, choices: { ...order.choices, [group]: value } });
}

export function toggleExtra(order: CoffeeOrder, extra: ExtraId): CoffeeOrder {
  if (!extrasFor(order.coffee).includes(extra)) return order;
  const extras = order.extras.includes(extra) ? order.extras.filter((e) => e !== extra) : [...order.extras, extra];
  return { ...order, extras };
}

/** Single line, collapsed whitespace, at most NOTE_MAX characters. */
export function cleanNote(raw: string): string {
  return raw.replace(/\s+/g, " ").replace(/^\s+/, "").slice(0, NOTE_MAX);
}

export function setNote(order: CoffeeOrder, raw: string): CoffeeOrder {
  return { ...order, note: cleanNote(raw) };
}

/** The note is for preparation only: refuse anything that looks like a phone, card number or e-mail. */
export function noteProblem(note: string): string | null {
  const digits = note.replace(/[\s.\-/]/g, "").match(/\d{6,}/);
  if (digits || note.includes("@")) return "Οι οδηγίες είναι μόνο για την παρασκευή. Μην γράφετε τηλέφωνα, e-mail ή στοιχεία πληρωμής.";
  return null;
}

/** Labels of the required choices still missing (coffee first). */
export function missing(order: CoffeeOrder): string[] {
  if (!order.coffee) return ["Καφές"];
  return visibleGroups(order)
    .filter((g) => GROUPS[g].required && order.choices[g] === undefined)
    .map((g) => groupLabel(order.coffee, g));
}

export function isComplete(order: CoffeeOrder): boolean {
  return missing(order).length === 0 && noteProblem(order.note) === null;
}

function optionLabel(group: GroupId, id: string, field: "label" | "summary"): string {
  return GROUPS[group].options.find((o) => o.id === id)?.[field] ?? id;
}

/** Everything chosen, as ruled summary rows (only what was actually selected). */
export function preparationRows(order: CoffeeOrder): [string, string][] {
  if (!order.coffee) return [];
  const rows: [string, string][] = [["Καφές", coffeeName(order.coffee)]];
  for (const g of visibleGroups(order)) {
    const v = order.choices[g];
    if (v !== undefined) rows.push([groupLabel(order.coffee, g), optionLabel(g, v, "label")]);
  }
  if (order.extras.length) rows.push(["Επιπλέον", order.extras.map((e) => EXTRAS[e].label).join(", ")]);
  if (order.note.trim()) rows.push(["Οδηγίες", order.note.trim()]);
  return rows;
}

/** One line for lists: "διπλός, μέτριος, καστανή ζάχαρη, χωρίς γάλα" (coffee name excluded). */
export function preparationLine(order: CoffeeOrder): string {
  if (!order.coffee) return "";
  const parts: string[] = [];
  for (const g of visibleGroups(order)) {
    const v = order.choices[g];
    if (v !== undefined) parts.push(optionLabel(g, v, "summary"));
  }
  for (const e of order.extras) parts.push(EXTRAS[e].summary);
  if (order.note.trim()) parts.push(`«${order.note.trim()}»`);
  return parts.join(", ");
}

// ---- Synthetic preferences and history (preview data) ---------------------------------------

const memberNo = (id: string) => Number(id.replace(/\D/g, "")) || 0;

function build(coffee: CoffeeCode, picks: Partial<Record<GroupId, string>>, extras: ExtraId[] = [], note = ""): CoffeeOrder {
  let o = selectCoffee(EMPTY_ORDER, coffee);
  for (const g of ORDER) if (picks[g] !== undefined) o = setChoice(o, g, picks[g]!);
  for (const e of extras) o = toggleExtra(o, e);
  return setNote(o, note);
}

/** A member's usual preparation (synthetic). It only ever prefills today's order. */
export function usualOrder(member: PreviewMember): CoffeeOrder {
  const n = memberNo(member.id);
  const sweetness = SWEET[(n * 3) % SWEET.length];
  return build(member.favourite, {
    shots: n % 3 === 0 ? "single" : "double",
    sweetness,
    sweetener: SWEETENERS[n % SWEETENERS.length],
    milk: optionsFor(member.favourite, "milk")[n % 2]?.id,
    ice: n % 4 === 0 ? "little" : undefined,
    foam: n % 5 === 0 ? "extra" : undefined,
  }, n % 6 === 0 ? ["decaf"] : []);
}

/** The exact preparation recorded in a synthetic history entry (deterministic, varies from the usual). */
export function historyPreparation(member: PreviewMember, coffee: CoffeeCode, date: string): CoffeeOrder {
  const day = Number(date.slice(8));
  if (coffee === member.favourite) {
    const usual = usualOrder(member);
    if (day % 5 === 0 && visibleGroups(usual).includes("ice")) return setChoice(usual, "ice", "little");
    // Notes that fit the drink: cold coffees (with ice options) are never "πολύ ζεστός".
    if (day % 7 === 0) return setNote(usual, visibleGroups(usual).includes("ice") ? "σε ποτήρι για έξω" : "πολύ ζεστός");
    return usual;
  }
  const n = memberNo(member.id) + day;
  return build(coffee, {
    shots: n % 2 === 0 ? "double" : "single",
    sweetness: SWEET[n % SWEET.length],
    sweetener: SWEETENERS[(n + 1) % SWEETENERS.length],
    milk: optionsFor(coffee, "milk")[n % 3]?.id,
  });
}

// ---- Staff order state (reducer) ------------------------------------------------------------

export type OrderState = { memberId: string; order: CoffeeOrder };

export type OrderAction =
  | { type: "open-member"; memberId: string }
  | { type: "coffee"; coffee: CoffeeCode }
  | { type: "choice"; group: GroupId; value: string | null }
  | { type: "extra"; extra: ExtraId }
  | { type: "note"; note: string }
  | { type: "prefill"; order: CoffeeOrder }
  | { type: "reset" };

/**
 * One order per member visit. Opening a DIFFERENT member clears the order;
 * moving between the coffee and confirmation screens never touches it.
 */
export function orderReducer(state: OrderState, action: OrderAction): OrderState {
  switch (action.type) {
    case "open-member":
      return action.memberId === state.memberId ? state : { memberId: action.memberId, order: EMPTY_ORDER };
    case "coffee":
      return { ...state, order: selectCoffee(state.order, action.coffee) };
    case "choice":
      return { ...state, order: setChoice(state.order, action.group, action.value) };
    case "extra":
      return { ...state, order: toggleExtra(state.order, action.extra) };
    case "note":
      return { ...state, order: setNote(state.order, action.note) };
    case "prefill":
      // A copy: today's order may change without touching the member's usual preference.
      return action.order.coffee ? { ...state, order: normalize({ ...action.order, choices: { ...action.order.choices }, extras: [...action.order.extras] }) } : state;
    case "reset":
      return { ...state, order: EMPTY_ORDER };
  }
}

// ---- Future redemption snapshot (DESIGN ONLY, not implemented) -------------------------------

/**
 * DESIGN-ONLY data contract for the eventual redemption record. Nothing in the
 * preview creates, sends or stores it; no SQL, REST, WordPress or redemption
 * engine change is implied.
 *
 * - ONE record per redemption. The entitlement key stays (memberId,
 *   athensDate): one free coffee per member per Europe/Athens calendar day,
 *   whatever the preparation. Modifiers never create extra records.
 * - The drink is a SNAPSHOT of that transaction (codes plus the labels shown
 *   at the counter and the catalogue version), so later catalogue edits do not
 *   rewrite history and the member's usual preference is a separate thing.
 * - The note is optional, short (≤ 80), preparation-only free text.
 * - Final catalogue, modifier availability and any surcharge need separate
 *   owner approval.
 */
export type RedemptionPreparationSnapshot = {
  redemptionId: string;
  memberId: string;
  /** Athens business date YYYY-MM-DD (the entitlement key with memberId). */
  athensDate: string;
  /** Athens local time HH:MM of the redemption. */
  athensTime: string;
  catalogueVersion: string;
  coffee: { code: CoffeeCode; label: string };
  modifiers: { group: GroupId | ExtraId; option: string; label: string }[];
  note: string | null;
};

/** Entitlement key: preparation is deliberately NOT part of it. */
export function entitlementKey(s: Pick<RedemptionPreparationSnapshot, "memberId" | "athensDate">): string {
  return `${s.memberId}|${s.athensDate}`;
}

/** Builds the snapshot a confirmation WOULD carry; refuses incomplete orders. Preview: display only. */
export function toSnapshot(order: CoffeeOrder, meta: { redemptionId: string; memberId: string; athensDate: string; athensTime: string }): RedemptionPreparationSnapshot {
  if (!order.coffee || !isComplete(order)) throw new Error("incomplete coffee order");
  const modifiers: RedemptionPreparationSnapshot["modifiers"] = [];
  for (const g of visibleGroups(order)) {
    const v = order.choices[g];
    if (v !== undefined) modifiers.push({ group: g, option: v, label: optionLabel(g, v, "label") });
  }
  for (const e of order.extras) modifiers.push({ group: e, option: "yes", label: EXTRAS[e].label });
  return { ...meta, catalogueVersion: "preview-illustrative", coffee: { code: order.coffee, label: coffeeName(order.coffee) }, modifiers, note: order.note.trim() || null };
}
