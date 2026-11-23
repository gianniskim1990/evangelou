// STAGE-08 round 17 — owner-found bug on staging (2026-11-23, plugin 98e25e8): an ACTIVE member who opened the
// home-screen app saw the design preview's SAMPLE QR (a non-redeemable decoration stamped «ΔΕΙΓΜΑ») for about two
// seconds before the real code. The live card kept the sample until the first GET /me/qr answer.
//
// The fix is structural: the sample code, the sample member view and the preview-only customer screens live in
// three preview-only modules (SAMPLE_ONLY_MODULES) that the real member app must never import. This guard is run
//   1. by the packager (scripts/package-club-plugin.mjs) on every package: on the esbuild import graph of
//      src/customer/main.tsx (sampleGraphProblems) and on the BUILT customer JS/CSS (sampleBundleProblems);
//   2. by tests/club/customerSampleGuard.test.ts, which also runs sampleGuardSelfTest().
// A sample marker or a module of the list = the package fails (fail closed).
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

/** The modules that hold the sample QR, the sample member view, the preview-only customer screens and the roster. */
export const SAMPLE_ONLY_MODULES = ["src/preview/sampleQr.tsx", "src/preview/customerSample.ts", "src/preview/customerSampleScreens.tsx", "src/preview/previewRoster.ts"];

/**
 * What the sample QR is made of, as it survives minification: its stamp, its marker attribute, its accessible
 * names, the sample-card note and label, and the numbers of its pseudo-random pattern (screens.ts until round 17).
 */
export const SAMPLE_QR_MARKERS = [
  "ΔΕΙΓΜΑ",
  "ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ",
  "sample-qr",
  "Δείγμα κωδικού",
  "(δείγμα)",
  "Ο κωδικός είναι δείγμα",
  "233280",
  "49297",
  "evc-club-design-preview",
];

/** The fictional preview roster (names, e-mails, masked phones), read from src/preview/previewRoster.ts. */
export function rosterMarkers(previewDataSource) {
  const out = new Set();
  for (const re of [/\bname: "([^"]+)"/g, /\bemail: "([^"]+)"/g, /\bphoneMasked: "([^"]+)"/g]) {
    for (const m of previewDataSource.matchAll(re)) out.add(m[1]);
  }
  return [...out];
}

function appDirOf() {
  return process.env.EVC_APP_DIR ?? join(dirname(fileURLToPath(import.meta.url)), "..");
}

/** Every marker the built customer bundle may not contain. */
export function sampleBundleMarkers(appDir = appDirOf()) {
  const roster = rosterMarkers(readFileSync(join(appDir, "src", "preview", "previewRoster.ts"), "utf8"));
  if (roster.length < 18 * 2) throw new Error(`sample guard: only ${roster.length} roster markers read from previewRoster.ts (sanity)`);
  return [...SAMPLE_QR_MARKERS, ...roster];
}

/** Problems of one built customer file (JS or CSS). */
export function sampleBundleProblems(fileName, text, markers) {
  return markers.filter((m) => text.includes(m)).map((m) => `sample guard: the member app bundle ${fileName} contains the preview sample "${m}"`);
}

/** Problems of the member app's import graph (paths relative to the app directory, forward slashes). */
export function sampleGraphProblems(inputs) {
  const norm = inputs.map((p) => p.replace(/\\/g, "/"));
  const problems = [];
  for (const mod of SAMPLE_ONLY_MODULES) {
    if (norm.some((p) => p === mod || p.endsWith(`/${mod}`))) problems.push(`sample guard: the member app imports the preview-only module ${mod}`);
  }
  if (!norm.some((p) => p.endsWith("src/customer/MemberQrPanel.tsx"))) problems.push("sample guard: the member app graph does not contain src/customer/MemberQrPanel.tsx (sanity)");
  return problems;
}

/** Negative fixtures: each must be refused, the clean ones accepted. */
export function sampleGuardSelfTest(appDir = appDirOf()) {
  const failures = [];
  const markers = sampleBundleMarkers(appDir);
  const clean = 'const a="Φόρτωση του κωδικού σας…";const b="Κάρτα μέλους Evangelou Club";';
  if (sampleBundleProblems("x.js", clean, markers).length !== 0) failures.push("clean bundle refused");
  for (const bad of [
    'children:["ΔΕΙΓΜΑ",(0,r.jsx)("br",{}),"ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ"]',
    '"data-evc-preview":"sample-qr"',
    "s=(s*9301+49297)%233280",
    '"aria-label":"Κάρτα μέλους Evangelou Club (δείγμα)"',
    "name:`Αλεξάνδρα Κωνσταντίνου`",
    'email:"nikos.v@example.invalid"',
  ]) {
    if (sampleBundleProblems("x.js", bad, markers).length === 0) failures.push(`not refused: ${bad}`);
  }
  if (sampleGraphProblems(["src/customer/main.tsx", "src/customer/MemberQrPanel.tsx"]).length !== 0) failures.push("clean graph refused");
  for (const mod of SAMPLE_ONLY_MODULES) {
    if (sampleGraphProblems(["src/customer/MemberQrPanel.tsx", mod]).length === 0) failures.push(`graph with ${mod} not refused`);
  }
  if (sampleGraphProblems(["src/customer/main.tsx"]).length === 0) failures.push("graph without the QR panel not refused");
  return failures;
}
