// STAGE-05D round 2 — owner decision 2026-11-04 «Ναι, να τον θυμάται»: the member's phone remembers the
// Club QR. Browser storage is allowed in EXACTLY ONE file of the customer app, src/customer/qrStore.ts,
// and there only localStorage. Every other module of the customer bundle's real import graph (app
// sources AND node_modules) may not name any browser store. The packager runs this on every package
// (scripts/package-club-plugin.mjs) and tests/club/storageGuard.test.ts runs the fixtures.
//
// Checked on a NORMALISED source text (rounds 3 + 4, reviews of 77a0e35 and 5d8e1c6):
//   1. esbuild parses the module (a real parser: comments are removed correctly, also next to a regex
//      literal such as /a\/*/) and prints it with minifySyntax, which decodes identifier and string
//      escapes, folds "local" + "Storage" and `${"local"}Storage`, and turns x["cookie"] into x.cookie;
//   2. on that text, ["local", "Storage"].join("") is folded (esbuild leaves joins alone).
// Then an identifier, string or member naming a store is a problem. In the app's own sources the
// property NAMES `cookie` and `storage` are refused too, which covers destructuring
// (const { cookie } = document; const { storage } = navigator).
// A module esbuild cannot parse is a problem (fail closed).
// Not covered statically (documented in docs/member-qr.md): aliasing that is not a destructuring
// (const n = navigator; const k = "stor" + suffix; n[k]) and names built from run-time values. The
// packager's checks on the BUILT, minified customer bundle are the second net (see there).
import { createRequire } from "node:module";
import { join } from "node:path";

/** esbuild, resolved at call time: from this file, or from the app when bundled into a temp test dir. */
function transformSync(src, options) {
  const bases = [import.meta.url, process.env.EVC_APP_DIR ? join(process.env.EVC_APP_DIR, "package.json") : null];
  for (const base of bases) {
    if (!base) continue;
    try {
      return createRequire(base)("esbuild").transformSync(src, options);
    } catch (err) {
      if (err?.code !== "MODULE_NOT_FOUND") throw err;
    }
  }
  throw new Error("storage guard: esbuild not found");
}

export const STORAGE_ALLOWED_FILE = "src/customer/qrStore.ts";
export const STORAGE_ALLOWED_APIS = ["localStorage"];
export const STORAGE_APIS = ["localStorage", "sessionStorage", "indexedDB", "IDBFactory", "IDBDatabase", "openDatabase", "cookieStore", "CacheStorage", "caches", ".cookie", "navigator.storage", "document.cookie", "cookie", "storage"];
/**
 * Generic names that dependencies legitimately use for other things (React's development build has a
 * local `caches`): checked in the app's own sources only. For node_modules the built-bundle bans of the
 * packager (on the minified production output) cover them.
 */
export const APP_ONLY_APIS = ["caches", ".cookie", "cookie", "storage"];
/** In the allowed file itself: localStorage, plus the generic name "storage" (its own variables, the storage event). */
const ALLOWED_FILE_NAMES = ["localStorage", "storage"];

/** Removes // and /* *\/ comments (strings are kept: a string naming a store is a problem too). */
export function stripComments(src) {
  let out = "";
  let i = 0;
  let quote = null;
  while (i < src.length) {
    const c = src[i];
    const n = src[i + 1];
    if (quote) {
      out += c;
      if (c === "\\") {
        out += n ?? "";
        i += 2;
        continue;
      }
      if (c === quote) quote = null;
      i++;
      continue;
    }
    if (c === '"' || c === "'" || c === "`") {
      quote = c;
      out += c;
      i++;
      continue;
    }
    if (c === "/" && n === "/") {
      while (i < src.length && src[i] !== "\n") i++;
      continue;
    }
    if (c === "/" && n === "*") {
      i += 2;
      while (i < src.length && !(src[i] === "*" && src[i + 1] === "/")) i++;
      i += 2;
      continue;
    }
    out += c;
    i++;
  }
  return out;
}

const escape = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

const LOADERS = { ".ts": "ts", ".tsx": "tsx", ".js": "js", ".mjs": "js", ".cjs": "js", ".jsx": "jsx" };

/** The normalised text the rules run on (see the header); null when esbuild cannot parse the module. */
export function normalizeSource(src, path = "x.ts") {
  const ext = path.slice(path.lastIndexOf("."));
  let code;
  try {
    code = transformSync(src, { loader: LOADERS[ext] ?? "ts", minifySyntax: true, legalComments: "none", logLevel: "silent" }).code;
  } catch {
    return null;
  }
  // esbuild already folded escapes, "a" + "b", templates and constant brackets; it does NOT fold a join.
  code = code.replace(/\[((?:\s*(["'`])[^"'`\\\n]*\2\s*,?)+)\]\s*\.\s*join\(\s*(["'`])\3\s*\)/g, (_, items) => `"${[...items.matchAll(/(["'`])([^"'`\\\n]*)\1/g)].map((m) => m[2]).join("")}"`);
  return code.replace(/\s*\.\s*/g, ".");
}

/** Problems for one module of the customer graph: `path` relative to app/ (node_modules paths as given). */
export function storageProblems(path, src) {
  const code = normalizeSource(src, path);
  if (code === null) return [`${path}: cannot be parsed, so it cannot be checked for browser storage`];
  const problems = [];
  const dependency = path.includes("node_modules/");
  for (const api of STORAGE_APIS) {
    if (dependency && APP_ONLY_APIS.includes(api)) continue;
    const before = api.startsWith(".") ? "" : "(^|[^A-Za-z0-9_$])";
    if (!new RegExp(`${before}${escape(api)}(?![A-Za-z0-9_$])`).test(code)) continue;
    const allowed = path === STORAGE_ALLOWED_FILE && ALLOWED_FILE_NAMES.includes(api);
    if (!allowed) problems.push(`${path}: uses ${api} (browser storage is allowed only in ${STORAGE_ALLOWED_FILE}, and only ${STORAGE_ALLOWED_APIS.join(", ")})`);
  }
  return problems;
}

/** Negative fixtures, run on every package. Returns the list of failed expectations (empty = OK). */
export function storageSelfTest() {
  const bad = [];
  const expect = (label, path, src, want) => {
    if ((storageProblems(path, src).length > 0) !== want) bad.push(`storage ${label}: expected ${want ? "a problem" : "no problem"}`);
  };
  const A = STORAGE_ALLOWED_FILE;
  expect("localStorage in the allowed file", A, "const s = globalThis.localStorage;", false);
  expect("localStorage elsewhere", "src/customer/memberQr.ts", "const s = window.localStorage;", true);
  expect("localStorage named in a string elsewhere", "src/customer/memberQr.ts", "const s = (globalThis as any)['localStorage'];", true);
  expect("a comment mentioning localStorage", "src/customer/memberQr.ts", "// never in localStorage\n/* nor sessionStorage */\nconst x = 1;", false);
  expect("a URL with // inside a string is not a comment", "src/customer/x.ts", "const u = 'https://x/'; const s = localStorage;", true);
  expect("sessionStorage even in the allowed file", A, "const s = sessionStorage;", true);
  expect("indexedDB in the allowed file", A, "const db = indexedDB.open('x');", true);
  expect("indexedDB elsewhere", "src/customer/x.ts", "indexedDB.open('x');", true);
  expect("document.cookie", "src/customer/x.ts", "const c = document.cookie;", true);
  expect("a dependency using storage", "node_modules/some-lib/index.js", "module.exports = () => window.sessionStorage;", true);
  expect("a longer identifier is not the API", "src/customer/x.ts", "const localStorageFree = 1; const mySessionStorageNote = 2;", false);
  // Round 3: the bypasses found in the review of 77a0e35.
  expect("an identifier escape", "src/customer/x.ts", "const s = window.local\\u0053torage;", true);
  expect("a braced identifier escape", "src/customer/x.ts", "const s = window.local\\u{53}torage;", true);
  expect("a hex escape in a string", "src/customer/x.ts", "const s = window['local\\x53torage'];", true);
  expect("string concatenation", "src/customer/x.ts", 'const s = (globalThis as any)["local" + "Storage"];', true);
  expect("three-part concatenation", "src/customer/x.ts", "const s = globalThis['lo' + 'cal' + `Storage`];", true);
  expect("a template placeholder", "src/customer/x.ts", 'const s = globalThis[`${"local"}Storage`];', true);
  expect("document['cookie']", "src/customer/x.ts", "const c = document['cookie'];", true);
  expect("cookie through an alias", "src/customer/x.ts", "const d = document; const c = d.cookie;", true);
  expect("caches.match", "src/customer/x.ts", "void caches.match('/x');", true);
  expect("window.caches.keys", "src/customer/x.ts", "void window.caches.keys();", true);
  expect("navigator['storage']", "src/customer/x.ts", "void navigator['storage'].persist();", true);
  expect("navigator . storage with spaces", "src/customer/x.ts", "void navigator . storage.estimate();", true);
  expect("a dependency's own `caches` variable (React development build)", "node_modules/react-dom/cjs/x.js", "var caches = new Map();", false);
  expect("a dependency's document.cookie", "node_modules/x/index.js", "var c = document.cookie;", true);
  // Round 4 (review of 5d8e1c6).
  expect("destructuring cookie from document", "src/customer/x.ts", "const { cookie } = document;", true);
  expect("destructuring storage from navigator", "src/customer/x.ts", "const { storage } = navigator; void storage.persist();", true);
  expect("renamed destructuring", "src/customer/x.ts", "const { cookie: c } = document;", true);
  expect("an array joined into the name", "src/customer/x.ts", 'const W = window as any; const s = W[["local", "Storage"].join("")];', true);
  expect("a three-part join", "src/customer/x.ts", "const s = (globalThis as any)[['lo', 'cal', 'Storage'].join('')];", true);
  expect("a regex literal with /* does not hide code", "src/customer/x.ts", "const r = /a\\/*/; const s = localStorage; const z = 1; // */", true);
  expect("a module that cannot be parsed", "src/customer/x.ts", "const = ;", true);
  expect("the allowed file may name storage itself", A, "const storage = globalThis.localStorage; addEventListener('storage', () => {});", false);
  expect("a cookie-like word is fine", "src/customer/x.ts", "const cookieConsent = false; const cachesize = 1; const storageKey = 2;", false);
  return bad;
}
