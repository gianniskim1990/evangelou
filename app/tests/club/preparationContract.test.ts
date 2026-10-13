import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { describe, it } from "node:test";
import {
  CatalogueError,
  canonicalPreparation,
  fingerprintV2Material,
  isId,
  isLabel,
  parseCatalogue,
  parsePreparation,
  validatePreparation,
  visibleGroups,
} from "../../src/club/preparationContract";
import { GROUPS, PROFILES, quickNotesFor, type GroupId } from "../../src/preview/coffeeConfig";
import catalogueJson from "../../../wordpress/evangelou-club/tests/fixtures/preparation/catalogue-illustrative.json";
import vectors from "../../../wordpress/evangelou-club/tests/fixtures/preparation/vectors.json";

/**
 * UI-03C — the TypeScript client mirror of the preparation contract must
 * reproduce every SHARED vector that the PHP server implementation is tested
 * against (wordpress/evangelou-club/tests/unit/PreparationContractTest.php).
 */

const sha = (s: string) => createHash("sha256").update(s, "utf8").digest("hex");
const catalogue = () => parseCatalogue(structuredClone(catalogueJson));

function evaluate(coffee: string, preparation: unknown): { kind: string; reason: string | null; canonical?: string } {
  const parsed = parsePreparation(preparation);
  if (!parsed.ok) return { kind: parsed.kind, reason: parsed.reason };
  const v = validatePreparation(catalogue(), coffee, parsed.value);
  if (!v.ok) return { kind: v.kind, reason: v.reason };
  return { kind: "ok", reason: null, canonical: v.value.canonical };
}

describe("UI-03C shared preparation vectors (TypeScript)", () => {
  for (const c of vectors.cases) {
    it(`${c.name}`, () => {
      const got = evaluate(c.coffee_code, c.preparation);
      const expect = c.expect as { kind: string; reason?: string | null; canonical?: string; prep_hash?: string };
      assert.equal(got.kind, expect.kind);
      if (expect.kind === "ok") {
        assert.equal(got.canonical, expect.canonical);
        assert.equal(sha(got.canonical!), expect.prep_hash);
      } else {
        assert.equal(got.reason, expect.reason ?? null);
      }
    });
  }

  it("equivalent requests share one canonical form", () => {
    for (const [a, b] of vectors.equivalent) {
      const ca = vectors.cases.find((c) => c.name === a)!;
      const cb = vectors.cases.find((c) => c.name === b)!;
      assert.equal(evaluate(ca.coffee_code, ca.preparation).canonical, evaluate(cb.coffee_code, cb.preparation).canonical);
    }
  });

  it("fingerprint v2 material hashes to the shared vectors and differs from v1", () => {
    for (const f of vectors.fingerprints) {
      assert.equal(sha(fingerprintV2Material(f.member_public_id, f.benefit_type, f.staff_wp_user_id, f.prep_hash)), f.fingerprint_v2);
      assert.notEqual(f.fingerprint_v2, vectors.fingerprint_v1_reference.fingerprint_v1);
    }
    const r = vectors.fingerprint_v1_reference;
    assert.equal(sha(["evc-redeem-v1", r.member_public_id, r.benefit_type, r.coffee_code, String(r.staff_wp_user_id)].join("\n")), r.fingerprint_v1);
  });

  for (const r of vectors.catalogue_rejections) {
    it(`catalogue rejected: ${r.name}`, () => {
      const data = structuredClone(catalogueJson) as unknown as Record<string | number, unknown>;
      let target: Record<string | number, unknown> = data;
      for (const key of r.path.slice(0, -1)) target = target[key] as Record<string | number, unknown>;
      target[r.path[r.path.length - 1]] = r.value;
      assert.throws(() => parseCatalogue(data), (e: unknown) => e instanceof CatalogueError && e.reason === r.reason);
    });
  }
});

describe("UI-03C catalogue and identifier rules", () => {
  it("the fixture is illustrative, unpriced and has no free text", () => {
    const c = catalogue();
    assert.equal(c.status, "illustrative");
    assert.equal(catalogueJson.pricing_policy, "undecided");
    assert.equal(catalogueJson.free_text_note, false);
    assert.ok(!JSON.stringify(catalogueJson).includes("price"));
  });

  it("canonical ids use underscores, never hyphens", () => {
    for (const ok of ["freddo_espresso", "lactose_free", "very_hot"]) assert.ok(isId(ok), ok);
    for (const bad of ["freddo-espresso", "Freddo_espresso", "f", "1abc", "espresso ", "a".repeat(33), 7, null]) assert.ok(!isId(bad), String(bad));
  });

  it("label length counts Unicode code points (same as PHP), not UTF-16 units", () => {
    assert.ok(isLabel("α".repeat(64)));
    assert.ok(!isLabel("α".repeat(65)));
    assert.ok(isLabel("😀".repeat(64)), "64 astral code points = 128 UTF-16 units");
    assert.ok(!isLabel("😀".repeat(65)));
    assert.ok(!isLabel("Δόση\u0007"));
  });

  it("the sweetener is visible only once a non-plain sweetness is chosen", () => {
    const espresso = catalogue().coffees.get("espresso")!;
    assert.ok(!visibleGroups(espresso, new Map()).includes("sweetener"));
    assert.ok(!visibleGroups(espresso, new Map([["sweetness", "none"]])).includes("sweetener"));
    assert.ok(visibleGroups(espresso, new Map([["sweetness", "medium"]])).includes("sweetener"));
  });

  it("canonical form is catalogue-independent and stable", () => {
    const parsed = parsePreparation({ modifiers: [{ group: "b_group", option: "x1" }, { group: "a_group", option: "y1" }], quick_notes: ["zz", "aa"] });
    assert.ok(parsed.ok);
    assert.equal(canonicalPreparation(3, "any_coffee", parsed.value), "evc-prep-v1\ncatalogue_version=3\ncoffee=any_coffee\nmodifier=a_group:y1\nmodifier=b_group:x1\nquick_note=aa\nquick_note=zz");
  });
});

describe("UI-03C catalogue fixture encodes the approved preview configurator rules", () => {
  // Preview (illustrative) codes -> canonical codes. The preview keeps its
  // hyphenated codes until a separate UI task; the real contract uses these.
  const CODE: Record<string, string> = { espresso: "espresso", "freddo-espresso": "freddo_espresso", cappuccino: "cappuccino", "freddo-cappuccino": "freddo_cappuccino", "greek-coffee": "greek_coffee", filter: "filter_coffee" };
  const NOTE: Record<string, string> = { takeaway: "takeaway", very_hot: "very_hot", lukewarm: "lukewarm", ice_side: "ice_side", no_straw: "no_straw", cinnamon: "cinnamon", cocoa: "cocoa" };

  for (const [previewCode, profile] of Object.entries(PROFILES)) {
    it(`${previewCode} -> ${CODE[previewCode]}`, () => {
      const coffee = catalogue().coffees.get(CODE[previewCode])!;
      assert.ok(coffee, "coffee present");
      const fixtureGroups = new Map(coffee.groups.map((g) => [g.id, g] as const));
      for (const [gid, allowed] of Object.entries(profile.groups) as [GroupId, string[]][]) {
        const g = fixtureGroups.get(gid);
        assert.ok(g, `group ${gid}`);
        assert.deepEqual(g.options.map((o) => o.id), allowed, `options of ${gid}`);
        assert.equal(g.required, GROUPS[gid].required, `required ${gid}`);
        assert.equal(g.visibleWhen !== null, gid === "sweetener", `visibility rule ${gid}`);
      }
      // Preview "extras" (decaf) become the optional `caffeine` group.
      assert.equal(fixtureGroups.has("caffeine"), profile.extras.includes("decaf"));
      assert.equal(coffee.groups.length, Object.keys(profile.groups).length + (profile.extras.length ? 1 : 0));
      assert.deepEqual([...coffee.quickNotes].sort(), quickNotesFor(previewCode as keyof typeof PROFILES).map((q) => NOTE[q.id]).sort());
    });
  }

  it("the temperature notes stay mutually exclusive", () => {
    assert.deepEqual(catalogueJson.exclusive_quick_notes, [["very_hot", "lukewarm"]]);
  });
});
