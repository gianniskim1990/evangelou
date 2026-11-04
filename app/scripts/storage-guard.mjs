// STAGE-05D round 2 — owner decision 2026-11-04 «Ναι, να τον θυμάται»: the member's phone remembers the
// Club QR. Browser storage is allowed in EXACTLY ONE file of the customer app, src/customer/qrStore.ts,
// and there only localStorage. Every other module of the customer bundle's real import graph (app
// sources AND node_modules) may not name any browser store. The packager runs this on every package
// (scripts/package-club-plugin.mjs) and tests/club/storageGuard.test.ts runs the fixtures.
//
// Checked on the source text with comments removed: an identifier or a string naming a store is a
// problem. Not covered statically (documented): a name assembled at run time from harmless pieces
// (e.g. "local" + "Storage"); the built-bundle string checks of the packager stay as a second net.

export const STORAGE_ALLOWED_FILE = "src/customer/qrStore.ts";
export const STORAGE_ALLOWED_APIS = ["localStorage"];
export const STORAGE_APIS = ["localStorage", "sessionStorage", "indexedDB", "IDBFactory", "IDBDatabase", "openDatabase", "cookieStore", "document.cookie", "navigator.storage", "caches.open", "CacheStorage"];

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

/** Problems for one module of the customer graph: `path` relative to app/ (node_modules paths as given). */
export function storageProblems(path, src) {
  const code = stripComments(src);
  const problems = [];
  for (const api of STORAGE_APIS) {
    if (!new RegExp(`(^|[^A-Za-z0-9_$])${escape(api)}(?![A-Za-z0-9_$])`).test(code)) continue;
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
  return bad;
}
