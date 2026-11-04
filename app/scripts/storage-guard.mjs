// STAGE-05D round 2 — owner decision 2026-11-04 «Ναι, να τον θυμάται»: the member's phone remembers the
// Club QR. Browser storage is allowed in EXACTLY ONE file of the customer app, src/customer/qrStore.ts,
// and there only localStorage. Every other module of the customer bundle's real import graph (app
// sources AND node_modules) may not name any browser store. The packager runs this on every package
// (scripts/package-club-plugin.mjs) and tests/club/storageGuard.test.ts runs the fixtures.
//
// Checked on a NORMALISED source text (round 3, review of 77a0e35): comments removed; \uXXXX, \u{…}
// and \xXX escapes decoded; adjacent string literals joined ("local" + "Storage"); template
// placeholders of a literal folded (`${"local"}Storage`); constant bracket access turned into a member
// access (document["cookie"] -> document.cookie). Then an identifier, string or member naming a store
// is a problem; `.cookie`, `caches` and `navigator.storage` count wherever they appear.
// Not covered statically (documented in docs/member-qr.md): aliasing through a variable
// (const n = navigator; n.storage) and names built from run-time values. The packager's checks on the
// BUILT, minified customer bundle are the second net: the minifier folds escapes, concatenations and
// constant brackets, the bundle must contain `localStorage` exactly as often as qrStore.ts produces it,
// and every other store name is banned there.

export const STORAGE_ALLOWED_FILE = "src/customer/qrStore.ts";
export const STORAGE_ALLOWED_APIS = ["localStorage"];
export const STORAGE_APIS = ["localStorage", "sessionStorage", "indexedDB", "IDBFactory", "IDBDatabase", "openDatabase", "cookieStore", "CacheStorage", "caches", ".cookie", "navigator.storage", "document.cookie"];
/**
 * Generic names that dependencies legitimately use for other things (React's development build has a
 * local `caches`): checked in the app's own sources only. For node_modules the built-bundle bans of the
 * packager (on the minified production output) cover them.
 */
export const APP_ONLY_APIS = ["caches", ".cookie"];

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

/** The normalised text the rules run on (see the header). */
export function normalizeSource(src) {
  let code = stripComments(src);
  code = code
    .replace(/\\u\{([0-9a-fA-F]{1,6})\}/g, (_, h) => String.fromCodePoint(parseInt(h, 16)))
    .replace(/\\u([0-9a-fA-F]{4})/g, (_, h) => String.fromCharCode(parseInt(h, 16)))
    .replace(/\\x([0-9a-fA-F]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16)));
  const lit = /(["'`])([^"'`\\\n]*)\1\s*\+\s*(["'`])([^"'`\\\n]*)\3/;
  for (let guard = 0; lit.test(code) && guard < 10000; guard++) code = code.replace(lit, (_, _q, a, _r, b) => `"${a}${b}"`);
  code = code.replace(/\$\{\s*(["'])([^"'\\\n]*)\1\s*\}/g, (_, _q, a) => a);
  code = code.replace(/\[\s*(["'`])([A-Za-z_$][\w$]*)\1\s*\]/g, (_, _q, name) => `.${name}`);
  return code.replace(/\s*\.\s*/g, ".");
}

/** Problems for one module of the customer graph: `path` relative to app/ (node_modules paths as given). */
export function storageProblems(path, src) {
  const code = normalizeSource(src);
  const problems = [];
  const dependency = path.includes("node_modules/");
  for (const api of STORAGE_APIS) {
    if (dependency && APP_ONLY_APIS.includes(api)) continue;
    const before = api.startsWith(".") ? "" : "(^|[^A-Za-z0-9_$])";
    if (!new RegExp(`${before}${escape(api)}(?![A-Za-z0-9_$])`).test(code)) continue;
    const allowed = path === STORAGE_ALLOWED_FILE && STORAGE_ALLOWED_APIS.includes(api);
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
  expect("a cookie-like word is fine", "src/customer/x.ts", "const cookieConsent = false; const cachesize = 1;", false);
  return bad;
}
