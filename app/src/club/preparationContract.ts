/**
 * UI-03C — coffee preparation contract (pure TypeScript; NOT wired anywhere).
 *
 * Client-side mirror of the server rules in
 * wordpress/evangelou-club/includes/class-evc-prep-{catalogue,contract}.php.
 * The SERVER is authoritative; this module lets the future staff app build
 * and pre-check a request with the same rules and the same canonical form.
 * Both implementations are tested against the shared fixtures in
 * wordpress/evangelou-club/tests/fixtures/preparation/.
 *
 * Owner decisions (2026-10-13):
 * - D-NOTES: structured options only. There is NO free-text note; an absent
 *   or null `note` is tolerated, anything else is `free_text_not_supported`.
 * - D-PRICING: undecided. `pricing_policy` must be "undecided"; no prices.
 * - D3: no approved menu. Only an illustrative fixture exists.
 *
 * Canonical ids: /^[a-z][a-z0-9_]{1,31}$/ (underscores, never hyphens).
 */

export const ID_PATTERN = /^[a-z][a-z0-9_]{1,31}$/;
export const PREP_VERSION = "evc-prep-v1";
export const FINGERPRINT_VERSION = "evc-redeem-v2";
export const MAX_MODIFIERS = 12;
export const MAX_QUICK_NOTES_IN_REQUEST = 8;

const MAX_VERSION = 2147483647;
const MAX_COFFEES = 64;
const MAX_GROUPS = 12;
const MAX_OPTIONS = 16;
const MAX_QUICK_NOTES_DEFINED = 16;
const MAX_SELECTABLE_QUICK_NOTES = 8;
const MAX_LABEL_CODE_POINTS = 64;
const FOAM_GROUP = "foam";

export type Temperature = "hot" | "cold";
export type AppliesTo = "any" | "hot" | "cold" | "foam";
export type VisibilityRule = { group: string; mode: "in" | "not_in"; options: string[] };
export type CatalogueGroup = { id: string; label: string; required: boolean; options: { id: string; label: string }[]; visibleWhen: VisibilityRule | null };
export type CatalogueCoffee = { code: string; label: string; temperature: Temperature; groups: CatalogueGroup[]; quickNotes: string[] };
export type Catalogue = {
  version: number;
  status: "illustrative" | "approved";
  maxQuickNotes: number;
  quickNotes: Map<string, { label: string; appliesTo: AppliesTo }>;
  exclusive: Map<string, number>;
  coffees: Map<string, CatalogueCoffee>;
};

export type RejectionKind = "invalid_request" | "invalid_preparation" | "invalid_coffee";
export type Rejection = { ok: false; kind: RejectionKind; reason: string | null };
export type ParsedPreparation = { modifiers: [string, string][]; quickNotes: string[] };
export type NormalizedPreparation = {
  catalogueVersion: number;
  coffeeCode: string;
  coffeeLabel: string;
  modifiers: { group: string; option: string; groupLabel: string; label: string }[];
  quickNotes: { id: string; label: string }[];
  canonical: string;
};

export class CatalogueError extends Error {
  readonly reason: string;
  constructor(reason: string) {
    super(`Invalid preparation catalogue: ${reason}`);
    this.reason = reason;
  }
}

// ---- strict JSON helpers -------------------------------------------------------------

export function isId(v: unknown): v is string {
  return typeof v === "string" && ID_PATTERN.test(v);
}

export function isLabel(v: unknown): v is string {
  if (typeof v !== "string" || v === "" || /\p{Cc}/u.test(v) || v.trim() !== v) return false;
  return [...v].length <= MAX_LABEL_CODE_POINTS;
}

function isList(v: unknown): v is unknown[] {
  return Array.isArray(v);
}

function isObjectWith(v: unknown, required: string[], optional: string[] = []): v is Record<string, unknown> {
  if (typeof v !== "object" || v === null || Array.isArray(v)) return false;
  const keys = Object.keys(v);
  return required.every((k) => keys.includes(k)) && keys.every((k) => required.includes(k) || optional.includes(k));
}

function isInt(v: unknown, min: number, max: number): v is number {
  return typeof v === "number" && Number.isInteger(v) && v >= min && v <= max;
}

// ---- catalogue -------------------------------------------------------------------------------

export function parseCatalogue(d: unknown): Catalogue {
  const fail = (reason: string): never => {
    throw new CatalogueError(reason);
  };
  if (!isObjectWith(d, ["schema", "catalogue_version", "status", "pricing_policy", "free_text_note", "quick_notes", "exclusive_quick_notes", "max_quick_notes", "coffees"])) fail("shape");
  const c = d as Record<string, unknown>;
  if (c.schema !== "evc-prep-catalogue-v1") fail("schema");
  if (!isInt(c.catalogue_version, 1, MAX_VERSION)) fail("catalogue_version");
  if (c.status !== "illustrative" && c.status !== "approved") fail("status");
  if (c.pricing_policy !== "undecided") fail("pricing_policy");
  if (c.free_text_note !== false) fail("free_text_note");
  if (!isInt(c.max_quick_notes, 1, MAX_SELECTABLE_QUICK_NOTES)) fail("max_quick_notes");

  const quickNotes = new Map<string, { label: string; appliesTo: AppliesTo }>();
  if (!isList(c.quick_notes) || c.quick_notes.length > MAX_QUICK_NOTES_DEFINED) fail("quick_notes");
  for (const q of c.quick_notes as unknown[]) {
    if (!isObjectWith(q, ["id", "label", "applies_to"]) || !isId(q.id) || quickNotes.has(q.id) || !isLabel(q.label) || !["any", "hot", "cold", "foam"].includes(q.applies_to as string)) fail("quick_note");
    const n = q as { id: string; label: string; applies_to: AppliesTo };
    quickNotes.set(n.id, { label: n.label, appliesTo: n.applies_to });
  }

  const exclusive = new Map<string, number>();
  if (!isList(c.exclusive_quick_notes)) fail("exclusive_quick_notes");
  (c.exclusive_quick_notes as unknown[]).forEach((set, index) => {
    if (!isList(set) || set.length < 2) fail("exclusive_set");
    for (const id of set as unknown[]) {
      if (typeof id !== "string" || !quickNotes.has(id) || exclusive.has(id)) fail("exclusive_set");
      exclusive.set(id as string, index);
    }
  });

  if (!isList(c.coffees) || c.coffees.length < 1 || c.coffees.length > MAX_COFFEES) fail("coffees");
  const coffees = new Map<string, CatalogueCoffee>();
  for (const raw of c.coffees as unknown[]) {
    if (!isObjectWith(raw, ["code", "label", "temperature", "groups", "quick_notes"]) || !isId(raw.code) || coffees.has(raw.code) || !isLabel(raw.label) || (raw.temperature !== "hot" && raw.temperature !== "cold") || !isList(raw.groups) || raw.groups.length > MAX_GROUPS || !isList(raw.quick_notes)) fail("coffee");
    const cr = raw as { code: string; label: string; temperature: Temperature; groups: unknown[]; quick_notes: unknown[] };
    const groups: CatalogueGroup[] = [];
    const rawRules = new Map<string, unknown>();
    for (const g of cr.groups) {
      if (!isObjectWith(g, ["id", "label", "required", "options"], ["visible_when"]) || !isId(g.id) || groups.some((x) => x.id === g.id) || !isLabel(g.label) || typeof g.required !== "boolean" || !isList(g.options) || g.options.length < 1 || g.options.length > MAX_OPTIONS) fail("group");
      const gr = g as { id: string; label: string; required: boolean; options: unknown[]; visible_when?: unknown };
      const options: { id: string; label: string }[] = [];
      for (const o of gr.options) {
        if (!isObjectWith(o, ["id", "label"]) || !isId(o.id) || options.some((x) => x.id === o.id) || !isLabel(o.label)) fail("option");
        options.push({ id: (o as { id: string }).id, label: (o as { label: string }).label });
      }
      if ("visible_when" in gr) rawRules.set(gr.id, gr.visible_when);
      groups.push({ id: gr.id, label: gr.label, required: gr.required, options, visibleWhen: null });
    }
    for (const g of groups) {
      if (!rawRules.has(g.id)) continue;
      const v = rawRules.get(g.id);
      const mode = isObjectWith(v, ["group", "in"]) ? "in" : isObjectWith(v, ["group", "not_in"]) ? "not_in" : null;
      const rule = v as Record<string, unknown>;
      const target = mode ? groups.find((x) => x.id === rule.group) : undefined;
      if (!mode || typeof rule.group !== "string" || rule.group === g.id || !target || rawRules.has(target.id) || !isList(rule[mode]) || (rule[mode] as unknown[]).length < 1) fail("visible_when");
      const opts: string[] = [];
      for (const oid of rule[mode!] as unknown[]) {
        if (typeof oid !== "string" || !target!.options.some((o) => o.id === oid) || opts.includes(oid)) fail("visible_when");
        opts.push(oid as string);
      }
      g.visibleWhen = { group: target!.id, mode: mode!, options: opts };
    }
    const notes: string[] = [];
    for (const id of cr.quick_notes) {
      if (typeof id !== "string" || !quickNotes.has(id) || notes.includes(id)) fail("coffee_quick_note");
      const applies = quickNotes.get(id as string)!.appliesTo;
      const ok = applies === "any" || (applies === "hot" && cr.temperature === "hot") || (applies === "cold" && cr.temperature === "cold") || (applies === "foam" && groups.some((x) => x.id === FOAM_GROUP));
      if (!ok) fail("quick_note_not_applicable_to_coffee");
      notes.push(id as string);
    }
    coffees.set(cr.code, { code: cr.code, label: cr.label, temperature: cr.temperature, groups, quickNotes: notes });
  }
  return { version: c.catalogue_version as number, status: c.status as "illustrative" | "approved", maxQuickNotes: c.max_quick_notes as number, quickNotes, exclusive, coffees };
}

// ---- request preparation: shape + canonical form (no catalogue) ---------------------------------

const reject = (kind: RejectionKind, reason: string | null): Rejection => ({ ok: false, kind, reason });
const byteOrder = (a: string, b: string) => (a < b ? -1 : a > b ? 1 : 0);

export function parsePreparation(preparation: unknown): { ok: true; value: ParsedPreparation } | Rejection {
  if (!isObjectWith(preparation, ["modifiers", "quick_notes"], ["note"])) return reject("invalid_request", "preparation_shape");
  if ("note" in preparation && preparation.note !== null) return reject("invalid_preparation", "free_text_not_supported");
  const mods = preparation.modifiers;
  const notes = preparation.quick_notes;
  if (!isList(mods) || mods.length > MAX_MODIFIERS || !isList(notes) || notes.length > MAX_QUICK_NOTES_IN_REQUEST) return reject("invalid_request", "preparation_shape");
  const modifiers = new Map<string, string>();
  for (const m of mods) {
    if (!isObjectWith(m, ["group", "option"]) || !isId(m.group) || !isId(m.option)) return reject("invalid_request", "modifier_shape");
    if (modifiers.has(m.group)) return reject("invalid_preparation", "duplicate_group");
    modifiers.set(m.group, m.option);
  }
  const quick: string[] = [];
  for (const id of notes) {
    if (!isId(id)) return reject("invalid_request", "quick_note_shape");
    if (quick.includes(id)) return reject("invalid_preparation", "duplicate_quick_note");
    quick.push(id);
  }
  return { ok: true, value: { modifiers: [...modifiers.entries()].sort((a, b) => byteOrder(a[0], b[0])), quickNotes: quick.sort(byteOrder) } };
}

export function canonicalPreparation(catalogueVersion: number, coffeeCode: string, parsed: ParsedPreparation): string {
  const lines = [PREP_VERSION, `catalogue_version=${catalogueVersion}`, `coffee=${coffeeCode}`];
  for (const [group, option] of parsed.modifiers) lines.push(`modifier=${group}:${option}`);
  for (const id of parsed.quickNotes) lines.push(`quick_note=${id}`);
  return lines.join("\n");
}

/** The exact string the server hashes for fingerprint v2 (hash it with SHA-256 to compare). */
export function fingerprintV2Material(memberPublicId: string, benefitType: string, staffWpUserId: number, prepHash: string): string {
  return [FINGERPRINT_VERSION, memberPublicId, benefitType, String(staffWpUserId), prepHash].join("\n");
}

// ---- semantic validation against a catalogue -------------------------------------------------

export function visibleGroups(coffee: CatalogueCoffee, selections: ReadonlyMap<string, string>): string[] {
  return coffee.groups
    .filter((g) => {
      const rule = g.visibleWhen;
      if (!rule) return true;
      const chosen = selections.get(rule.group);
      if (chosen === undefined) return false;
      const hit = rule.options.includes(chosen);
      return rule.mode === "in" ? hit : !hit;
    })
    .map((g) => g.id);
}

export function validatePreparation(catalogue: Catalogue, coffeeCode: string, parsed: ParsedPreparation): { ok: true; value: NormalizedPreparation } | Rejection {
  const coffee = catalogue.coffees.get(coffeeCode);
  if (!coffee) return reject("invalid_coffee", null);
  const groups = new Map(coffee.groups.map((g) => [g.id, g] as const));
  for (const [group, option] of parsed.modifiers) {
    const g = groups.get(group);
    if (!g) return reject("invalid_preparation", "unknown_group");
    if (!g.options.some((o) => o.id === option)) return reject("invalid_preparation", "unknown_option");
  }
  const selections = new Map(parsed.modifiers);
  const visible = visibleGroups(coffee, selections);
  for (const [group] of parsed.modifiers) if (!visible.includes(group)) return reject("invalid_preparation", "hidden_group_present");
  for (const id of visible) if (groups.get(id)!.required && !selections.has(id)) return reject("invalid_preparation", "required_missing");
  if (parsed.quickNotes.length > catalogue.maxQuickNotes) return reject("invalid_preparation", "too_many_quick_notes");
  const sets = new Set<number>();
  for (const id of parsed.quickNotes) {
    if (!catalogue.quickNotes.has(id)) return reject("invalid_preparation", "unknown_quick_note");
    if (!coffee.quickNotes.includes(id)) return reject("invalid_preparation", "quick_note_not_applicable");
    const set = catalogue.exclusive.get(id);
    if (set !== undefined) {
      if (sets.has(set)) return reject("invalid_preparation", "exclusive_conflict");
      sets.add(set);
    }
  }
  return {
    ok: true,
    value: {
      catalogueVersion: catalogue.version,
      coffeeCode,
      coffeeLabel: coffee.label,
      modifiers: parsed.modifiers.map(([group, option]) => {
        const g = groups.get(group)!;
        return { group, option, groupLabel: g.label, label: g.options.find((o) => o.id === option)!.label };
      }),
      quickNotes: parsed.quickNotes.map((id) => ({ id, label: catalogue.quickNotes.get(id)!.label })),
      canonical: canonicalPreparation(catalogue.version, coffeeCode, parsed),
    },
  };
}
