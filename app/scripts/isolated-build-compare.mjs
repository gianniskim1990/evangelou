// Pure helpers of scripts/check-isolated-build.mjs, kept in their own module so
// tests can import them (the check script itself runs a build on import).
import { lstatSync, rmSync, rmdirSync, unlinkSync } from "node:fs";

/**
 * Compares two build outputs, each a Map of file name -> content hash.
 * Files with the same name but a different hash count as CHANGED: Vite puts
 * the hash in most file names, but files without one (index.html, manifests)
 * would otherwise slip through.
 *
 * @param {Map<string, string>} a
 * @param {Map<string, string>} b
 * @returns {{ same: boolean, onlyA: string[], onlyB: string[], changed: string[] }}
 */
export function compare(a, b) {
  const onlyA = [...a.keys()].filter((k) => !b.has(k));
  const onlyB = [...b.keys()].filter((k) => !a.has(k));
  const changed = [...a.keys()].filter((k) => b.has(k) && a.get(k) !== b.get(k));
  return { same: onlyA.length === 0 && onlyB.length === 0 && changed.length === 0, onlyA, onlyB, changed };
}

/** Class-like selectors of a stylesheet, for readable failure reports. */
export function selectors(css) {
  return new Set(css.match(/\.[a-zA-Z][\w\\:\-[\]/.%]*(?=[{,:])/g) ?? []);
}

/**
 * Removes a symlink / junction WITHOUT touching its target. Returns true if
 * the link is gone afterwards. A path that is a real directory or file is
 * never deleted here (returns false).
 */
export function unlinkLinkOnly(path) {
  let stat;
  try {
    stat = lstatSync(path);
  } catch {
    return true; // already gone
  }
  if (!stat.isSymbolicLink()) return false;
  try {
    unlinkSync(path);
  } catch {
    rmdirSync(path); // Windows junction
  }
  try {
    lstatSync(path);
    return false;
  } catch {
    return true;
  }
}

/**
 * Removes a temp directory that contains `link` (a link to the REAL
 * node_modules). The link is removed first, on its own; if it cannot be
 * proven gone, the directory is left in place rather than risk recursing
 * into the real dependencies.
 *
 * @returns {boolean} true when the directory was removed
 */
export function removeTempTree(dir, link) {
  if (!unlinkLinkOnly(link)) return false;
  rmSync(dir, { recursive: true, force: true });
  return true;
}
