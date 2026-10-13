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

export type QuickNoteId = "takeaway" | "very_hot" | "lukewarm" | "ice_side" | "no_straw" | "cinnamon" | "cocoa";

/**
 * One-tap preparation notes. Each declares when it makes sense, derived from
 * the coffee's (illustrative) profile, so a cold drink is never offered
 * "πολύ ζεστός" and foam notes appear only where there is foam.
 */
/**
 * `exclusive`: notes in the same group contradict each other (a coffee cannot be
 * both "πολύ ζεστός" and "χλιαρός"); selecting one replaces the other.
 */
type QuickNote = { id: QuickNoteId; label: string; when: "any" | "hot" | "cold" | "foam"; exclusive?: "temperature" };

export const QUICK_NOTES: QuickNote[] = [
  { id: "takeaway", label: "σε ποτήρι για έξω", when: "any" },
  { id: "very_hot", label: "πολύ ζεστός", when: "hot", exclusive: "temperature" },
  { id: "lukewarm", label: "χλιαρός", when: "hot", exclusive: "temperature" },
  { id: "ice_side", label: "πάγος στο πλάι", when: "cold" },
  { id: "no_straw", label: "χωρίς καλαμάκι", when: "cold" },
  { id: "cinnamon", label: "κανέλα στον αφρό", when: "foam" },
  { id: "cocoa", label: "κακάο στον αφρό", when: "foam" },
];

/** A coffee is "cold" when its profile offers ice options; everything else is served hot. */
export function isColdCoffee(coffee: CoffeeCode): boolean {
  return PROFILES[coffee].groups.ice !== undefined;
}

export function quickNotesFor(coffee: CoffeeCode | null): QuickNote[] {
  if (!coffee) return [];
  const cold = isColdCoffee(coffee);
  const foam = PROFILES[coffee].groups.foam !== undefined;
  return QUICK_NOTES.filter((q) => q.when === "any" || (q.when === "cold" && cold) || (q.when === "hot" && !cold) || (q.when === "foam" && foam));
}

export type CoffeeOrder = {
  coffee: CoffeeCode | null;
  choices: Partial<Record<GroupId, string>>;
  extras: ExtraId[];
  /** Tapped quick notes: typed, pruned like any other option when the coffee changes. */
  quickNotes: QuickNoteId[];
  /** Optional free text. Kept on a coffee change, but then flagged for review. */
  note: string;
  /** True when the coffee changed after free text was written: staff must re-read it. */
  noteReview: boolean;
};

export const EMPTY_ORDER: CoffeeOrder = { coffee: null, choices: {}, extras: [], quickNotes: [], note: "", noteReview: false };

export const NOTE_MAX = 80;

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
  if (!order.coffee) return { ...order, choices: {}, extras: [], quickNotes: [] };
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
  const allowedNotes = quickNotesFor(coffee).map((q) => q.id);
  return { ...settled, choices, extras: order.extras.filter((e) => PROFILES[coffee].extras.includes(e)), quickNotes: resolveExclusive(order.quickNotes.filter((q) => allowedNotes.includes(q))) };
}

export function selectCoffee(order: CoffeeOrder, coffee: CoffeeCode): CoffeeOrder {
  // Free text cannot be checked against the new coffee, so it is kept but flagged for review.
  const changed = order.coffee !== null && order.coffee !== coffee;
  return normalize({ ...order, coffee, noteReview: order.noteReview || (changed && order.note.trim() !== "") });
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

/** Keep at most one note per exclusive group: the most recently selected one wins. */
function resolveExclusive(ids: QuickNoteId[]): QuickNoteId[] {
  const groupOf = (id: QuickNoteId) => QUICK_NOTES.find((q) => q.id === id)?.exclusive;
  return ids.filter((id, i) => {
    const g = groupOf(id);
    return g === undefined || !ids.slice(i + 1).some((later) => groupOf(later) === g);
  });
}

export function toggleQuickNote(order: CoffeeOrder, id: QuickNoteId): CoffeeOrder {
  if (!quickNotesFor(order.coffee).some((q) => q.id === id)) return order;
  const quickNotes = order.quickNotes.includes(id) ? order.quickNotes.filter((q) => q !== id) : resolveExclusive([...order.quickNotes, id]);
  return { ...order, quickNotes };
}

/** Single line, collapsed whitespace, at most NOTE_MAX characters. */
export function cleanNote(raw: string): string {
  return raw.replace(/\s+/g, " ").replace(/^\s+/, "").slice(0, NOTE_MAX);
}

/** Editing the free text counts as reviewing it. */
export function setNote(order: CoffeeOrder, raw: string): CoffeeOrder {
  const note = cleanNote(raw);
  return { ...order, note, noteReview: order.noteReview && note.trim() !== "" && note === order.note };
}

/** Staff confirm the free text still fits the current coffee. */
export function confirmNote(order: CoffeeOrder): CoffeeOrder {
  return { ...order, noteReview: false };
}

/**
 * The free text is for preparation only. This is a basic heuristic that refuses
 * obvious phone/card numbers and e-mail addresses; it does NOT validate the
 * text's meaning or catch every kind of personal data.
 */
export function noteProblem(note: string): string | null {
  const digits = note.replace(/[\s.\-/]/g, "").match(/\d{6,}/);
  if (digits || note.includes("@")) return "Οι οδηγίες είναι μόνο για την παρασκευή. Μην γράφετε τηλέφωνα, e-mail ή στοιχεία πληρωμής.";
  return null;
}

/** Labels of the required choices still missing (coffee first). */
export function missing(order: CoffeeOrder): string[] {
  if (!order.coffee) return ["Καφές"];
  const gaps = visibleGroups(order)
    .filter((g) => GROUPS[g].required && order.choices[g] === undefined)
    .map((g) => groupLabel(order.coffee, g));
  return order.noteReview ? [...gaps, "Έλεγχος οδηγιών"] : gaps;
}

export function isComplete(order: CoffeeOrder): boolean {
  return missing(order).length === 0 && noteProblem(order.note) === null;
}

/** Quick notes (catalogue labels) followed by the free text. */
export function noteParts(order: CoffeeOrder): string[] {
  const quick = QUICK_NOTES.filter((q) => order.quickNotes.includes(q.id)).map((q) => q.label);
  return order.note.trim() ? [...quick, order.note.trim()] : quick;
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
  if (noteParts(order).length) rows.push(["Οδηγίες", noteParts(order).join(", ")]);
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
  for (const n of noteParts(order)) parts.push(`«${n}»`);
  return parts.join(", ");
}

// ---- Synthetic preferences and history (preview data) ---------------------------------------

const memberNo = (id: string) => Number(id.replace(/\D/g, "")) || 0;

function build(coffee: CoffeeCode, picks: Partial<Record<GroupId, string>>, extras: ExtraId[] = []): CoffeeOrder {
  let o = selectCoffee(EMPTY_ORDER, coffee);
  for (const g of ORDER) if (picks[g] !== undefined) o = setChoice(o, g, picks[g]!);
  for (const e of extras) o = toggleExtra(o, e);
  return o;
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
    // Quick notes go through toggleQuickNote, so they always fit the drink.
    if (day % 7 === 0) return toggleQuickNote(usual, isColdCoffee(coffee) ? "takeaway" : "very_hot");
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
  | { type: "quick-note"; id: QuickNoteId }
  | { type: "note"; note: string }
  | { type: "note-ok" }
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
    case "quick-note":
      return { ...state, order: toggleQuickNote(state.order, action.id) };
    case "note":
      return { ...state, order: setNote(state.order, action.note) };
    case "note-ok":
      return { ...state, order: confirmNote(state.order) };
    case "prefill":
      // A copy: today's order may change without touching the member's usual preference.
      return action.order.coffee ? { ...state, order: normalize({ ...action.order, choices: { ...action.order.choices }, extras: [...action.order.extras], quickNotes: [...action.order.quickNotes], noteReview: false }) } : state;
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
 * - Quick notes are typed ids from the catalogue (only those valid for the
 *   coffee); the free-text note is optional, short (≤ 80) and preparation-only.
 *   Its personal-data check is a heuristic, not full validation.
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
  quickNotes: { id: QuickNoteId; label: string }[];
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
  return { ...meta, catalogueVersion: "preview-illustrative", coffee: { code: order.coffee, label: coffeeName(order.coffee) }, modifiers, quickNotes: QUICK_NOTES.filter((q) => order.quickNotes.includes(q.id)).map((q) => ({ id: q.id, label: q.label })), note: order.note.trim() || null };
}
