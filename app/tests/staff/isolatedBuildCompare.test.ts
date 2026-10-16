import assert from "node:assert/strict";
import { existsSync, lstatSync, mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, it } from "node:test";
import { compare, removeTempTree, unlinkLinkOnly } from "../../scripts/isolated-build-compare.mjs";

/*
 * Behaviour of the pure helpers behind scripts/check-isolated-build.mjs.
 * Deterministic: in-memory maps and throw-away temp directories only.
 */

const out = (entries: Record<string, string>) => new Map(Object.entries(entries));

describe("isolated-build comparator: file names AND content hashes", () => {
  it("identical outputs are the same", () => {
    const r = compare(out({ "dist/index.html": "h1", "dist-staff/assets/staff.js": "h2" }), out({ "dist-staff/assets/staff.js": "h2", "dist/index.html": "h1" }));
    assert.deepEqual(r, { same: true, onlyA: [], onlyB: [], changed: [] });
  });

  it("the SAME file name with a DIFFERENT hash is a change (the bug: it used to compare names only)", () => {
    const r = compare(out({ "staff.js": "hashA" }), out({ "staff.js": "hashB" }));
    assert.equal(r.same, false);
    assert.deepEqual(r.changed, ["staff.js"]);
    assert.deepEqual(r.onlyA, []);
    assert.deepEqual(r.onlyB, []);
  });

  it("reports changed-content files separately from added and removed files", () => {
    const a = out({ "same.js": "x", "changed.html": "old", "removed.css": "r" });
    const b = out({ "same.js": "x", "changed.html": "new", "added.js": "n" });
    const r = compare(a, b);
    assert.equal(r.same, false);
    assert.deepEqual(r.changed, ["changed.html"]);
    assert.deepEqual(r.onlyA, ["removed.css"]);
    assert.deepEqual(r.onlyB, ["added.js"]);
  });

  it("an unhashed file name (index.html, a manifest) changing content is caught", () => {
    assert.equal(compare(out({ "dist/index.html": "a" }), out({ "dist/index.html": "b" })).same, false);
    assert.equal(compare(out({ "dist-staff/.vite/manifest.json": "a" }), out({ "dist-staff/.vite/manifest.json": "b" })).same, false);
  });

  it("only added or only removed files are different too", () => {
    assert.equal(compare(out({ a: "1" }), out({ a: "1", b: "2" })).same, false);
    assert.equal(compare(out({ a: "1", b: "2" }), out({ a: "1" })).same, false);
    assert.equal(compare(out({}), out({})).same, true);
  });

  it("is symmetric: swapping the arguments swaps only-A/only-B and keeps the changed set", () => {
    const a = out({ c: "1", x: "1" });
    const b = out({ c: "2", y: "1" });
    const ab = compare(a, b);
    const ba = compare(b, a);
    assert.deepEqual(ab.onlyA, ba.onlyB);
    assert.deepEqual(ab.onlyB, ba.onlyA);
    assert.deepEqual(ab.changed, ba.changed);
    assert.equal(ab.same, ba.same);
  });
});

describe("isolated-build cleanup never touches the real dependencies", () => {
  function fixture() {
    const root = mkdtempSync(join(tmpdir(), "evc-cleanup-test-"));
    const realModules = join(root, "real-node_modules");
    mkdirSync(join(realModules, "pkg"), { recursive: true });
    writeFileSync(join(realModules, "pkg", "index.js"), "module.exports = 1;");
    const work = join(root, "work");
    mkdirSync(join(work, "tree"), { recursive: true });
    writeFileSync(join(work, "tree", "file.txt"), "x");
    const link = join(work, "node_modules");
    symlinkSync(realModules, link, "junction");
    return { root, realModules, work, link };
  }

  it("unlinkLinkOnly removes the link and leaves the target intact", () => {
    const f = fixture();
    try {
      assert.equal(lstatSync(f.link).isSymbolicLink(), true);
      assert.equal(unlinkLinkOnly(f.link), true);
      assert.equal(existsSync(f.link), false);
      assert.equal(readFileSync(join(f.realModules, "pkg", "index.js"), "utf8"), "module.exports = 1;");
    } finally {
      rmSync(f.root, { recursive: true, force: true });
    }
  });

  it("removeTempTree removes the temp directory and the real dependencies survive", () => {
    const f = fixture();
    try {
      assert.equal(removeTempTree(f.work, f.link), true);
      assert.equal(existsSync(f.work), false);
      assert.equal(readFileSync(join(f.realModules, "pkg", "index.js"), "utf8"), "module.exports = 1;");
    } finally {
      rmSync(f.root, { recursive: true, force: true });
    }
  });

  it("a path that is a REAL directory is never deleted by unlinkLinkOnly or removeTempTree", () => {
    const root = mkdtempSync(join(tmpdir(), "evc-cleanup-real-"));
    try {
      const work = join(root, "work");
      const realDir = join(work, "node_modules");
      mkdirSync(join(realDir, "pkg"), { recursive: true });
      writeFileSync(join(realDir, "pkg", "index.js"), "keep");
      assert.equal(unlinkLinkOnly(realDir), false);
      assert.equal(removeTempTree(work, realDir), false, "refuses when the link cannot be proven to be a link");
      assert.equal(readFileSync(join(realDir, "pkg", "index.js"), "utf8"), "keep");
    } finally {
      rmSync(root, { recursive: true, force: true });
    }
  });

  it("a missing link is fine and the temp directory is still removed", () => {
    const root = mkdtempSync(join(tmpdir(), "evc-cleanup-missing-"));
    try {
      const work = join(root, "work");
      mkdirSync(work);
      assert.equal(removeTempTree(work, join(work, "node_modules")), true);
      assert.equal(existsSync(work), false);
    } finally {
      rmSync(root, { recursive: true, force: true });
    }
  });
});
