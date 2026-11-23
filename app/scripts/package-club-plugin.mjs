// Builds the REAL staff app and assembles an installable Evangelou Club
// plugin directory at app/dist-plugin/evangelou-club (no deployment).
//
//   1. vite build --config vite.staff.config.ts  (hashed assets + manifest)
//      vite build --config vite.customer.config.ts (STAGE-01 customer app)
//   2. copy ONLY runtime plugin files (allowlist): no tests, vendor,
//      composer files, PHPUnit config, fixtures or mock adapters
//   3. copy assets + manifest into staff-app/ and customer-app/ (the PHP shells read them)
//   4. verify: every manifest file exists, no source maps, no demo/mock
//      data or ordering-app code in the bundle, no test-only PHP classes
//
// Usage: node scripts/package-club-plugin.mjs [--skip-build]
import { execSync } from "node:child_process";
import { cpSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from "node:fs";
import { dirname, join, relative, sep } from "node:path";
import { fileURLToPath } from "node:url";
import { ADMIN_TOOLS_RULES, FLUENTCRM_RULES, MEMBER_SYNC_RULES, MIGRATIONS_RULES, ONBOARDING_RULES, PREPARED_RULES, PUSH_RULES, PWA_RULES, QR_RULES, REDEMPTION_RULES, adminToolsProblemsFor, fluentcrmProblemsFor, onboardingProblemsFor, memberSyncProblemsFor, preparedProblemsFor, problemsFor, pushProblemsFor, pwaProblemsFor, qrProblemsFor, redemptionProblemsFor, selfTest, workerProblems } from "./php-guards.mjs";
import { storageProblems, storageSelfTest } from "./storage-guard.mjs";
import { sampleBundleMarkers, sampleBundleProblems, sampleGraphProblems, sampleGuardSelfTest } from "./sample-guard.mjs";

const appDir = join(dirname(fileURLToPath(import.meta.url)), "..");
const pluginSrc = join(appDir, "..", "wordpress", "evangelou-club");
const distStaff = join(appDir, "dist-staff");
const distCustomer = join(appDir, "dist-customer");
const out = join(appDir, "dist-plugin", "evangelou-club");

const RUNTIME_ALLOWLIST = ["evangelou-club.php", "README.md", "includes", "database"];
const FORBIDDEN_BUNDLE_STRINGS = [
  "Μαρία Παπαδοπούλου", "Γιώργος Νικολάου", "DEMO-QR", "6900000001", "evaggelou-club-redemptions",
  "mockClubService", "createMockClubService", "TEST Espresso", "x-admin-password", "/api/orders", "/api/overrides",
  // UI-01 design preview must never reach the real staff bundle.
  "evc-club-design-preview", "ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ", "ΠΡΟΕΠΙΣΚΟΠΗΣΗ ΣΧΕΔΙΑΣΜΟΥ", "example.invalid",
  // STAGE-08 round 11 (D26): the member app's home-screen pieces never reach the staff app.
  "serviceWorker", "beforeinstallprompt", "webmanifest", "Εγκατάσταση στο κινητό",
  // Round 12 (D26 part 2): nor its notifications.
  "requestPermission", "pushManager", "Θέλω ειδοποιήσεις", "/me/push",
];
// STAGE-01 customer app: never the synthetic roster, the local journey simulation, the staff app or a mock service.
// STAGE-08 round 17 (owner-found bug): never the design preview's sample QR either (scripts/sample-guard.mjs: its
// stamp, pattern, labels and the whole roster are refused below, and so are the preview-only modules in the graph).
const FORBIDDEN_CUSTOMER_BUNDLE_STRINGS = [
  "example.invalid", "Αλεξάνδρα Κωνσταντίνου", "evc-club-design-preview", "customer-journey-local", "evc-cj", "SCENARIO_START",
  "mockClubService", "createMockClubService", "DEMO-QR", "evc-staff-config", "/club-admin", "x-admin-password", "/api/orders", "/api/overrides",
  // STAGE-05D round 2: localStorage is allowed in ONE source file (src/customer/qrStore.ts, the member's
  // own QR; scripts/storage-guard.mjs checks the real import graph below); every other store stays banned.
  // Round 3: checked on the MINIFIED output, where the minifier has already folded escapes, "a" + "b" and
  // x["y"] into plain names (it does NOT fold ["a", "b"].join("") or a destructuring: see the checks below
  // and the source guard). Round 4: "cookie" is banned outright (the bundle has none; a destructuring
  // such as let{cookie:e}=document keeps the word).
  "sessionStorage", "indexedDB", "document.cookie", ".cookie", "cookie", "caches", "navigator.storage", "cookieStore", "openDatabase", "CacheStorage",
];
// Rounds 3 + 4 (reviews of 77a0e35 and 5d8e1c6): the built customer JS must contain `localStorage` and,
// separately, `Storage` EXACTLY this often: the one access in src/customer/qrStore.ts. Any other use in
// a form the minifier resolves (an escape, a concatenation, a bracket, a second copy) changes a count; a
// name split into an array and joined ([..., "Storage"].join("")) changes the `Storage` count. Changing
// qrStore's access count means changing this number in the same reviewed change. Not covered here: a
// name split so that no piece is "Storage"; the source guard folds literal joins, and review is the last net.
const EXPECTED_CUSTOMER_LOCAL_STORAGE = 1;
/** Destructuring a store out of navigator in the minified output: let{storage:e}=navigator. */
const NAVIGATOR_DESTRUCTURING = /\{[^{}]*\bstorage\b[^{}]*\}\s*=\s*navigator\b/;
const FORBIDDEN_PHP_STRINGS = ["class EVC_Mock_Membership_Adapter", "class EVC_Test_", "class EVC_Faulty_Pdo", "class EVC_Tripwire", "class EVC_Fake_Pmpro_Reader", "class EVC_Pmpro_Fixture", "class EVC_In_Memory_Provenance_Store", "class EVC_Provenance_Fact_Builder", "class EVC_Provenance_Test_Fixture", "trait EVC_Provenance_Store_Contract", "new EVC_Bacs_Payment_Confirmation", "new EVC_Bacs_Payment_Finalizer", "new EVC_Bacs_Payment_Issuer", "class EVC_Hooked_Provenance_Store", "class EVC_Bacs_Intent_Fixture", "class EVC_Read_Only_Provenance_Store_Spy", "class EVC_Fake_Payment_Source_Verifier", "class EVC_Payment_Identity_Fixture", "implements EVC_Payment_Source_Verifier", "new EVC_Payment_Evidence_Gate", "new EVC_Prepared_Redeem_Precheck", "implements EVC_Prep_Request_Lookup", "class EVC_In_Memory_Prepared_Redemption_Store", "class EVC_Fake_Prep_Catalogue_Authority", "class EVC_Fake_Club_Staff_Authorizer", "new EVC_Handoff_Service", "new EVC_Fulfillment_Read_Model", "implements EVC_Redemption_Fulfillment_Store", "class EVC_Fake_Redemption_History", "class EVC_Fake_Member_Context_Verifier", "new EVC_Usual_Coffee_Service", "implements EVC_Redemption_History_Source", "new EVC_Signed_Member_Context_Verifier", "class EVC_Fake_Qr_Token_Store", "class EVC_Fake_Member_Phone_Directory", "class EVC_Fake_Lookup_Rate_Limiter", "class EVC_Fake_Member_Identity_Store", "class EVC_Fake_Wp_Account_Directory", "class EVC_Fake_Member_Status_Policy", "class EVC_Fake_Qr_Delivery_Policy", "new EVC_Member_Account_Service", "new EVC_Member_Qr_Coordinator", "implements EVC_Member_Status_Policy", "implements EVC_Qr_Delivery_Policy", "class EVC_Contract_Vector_Builder", "EVC_Test_Redemption_Backend", "EVC_Test_Coffee_Catalogue", "fixtures/coffee-catalog.php", "new EVC_Rest_V2_Domain_Response", "register_rest_route(EVC_Rest_V2_Routes", "'EVC_Rest_V2_Security'", "EVC_Rest_V2_Security::filter_post_dispatch", "EVC_Test_V2_Harness", "EVC_Staff_Role_Upgrade_Planner::", "new EVC_Staff_Role_Upgrade_Approval", "EVC_Staff_Role_Versions::VERSION_OPTION"];
const SILENCE = "<?php\n// Silence is golden.\n";

function fail(message) {
  console.error(`PACKAGE FAILED: ${message}`);
  process.exit(1);
}

function walk(dir) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    return statSync(path).isDirectory() ? walk(path) : [path];
  });
}

if (!process.argv.includes("--skip-build")) {
  const { build } = await import("vite");
  await build({ configFile: join(appDir, "vite.staff.config.ts"), logLevel: "warn" });
  await build({ configFile: join(appDir, "vite.customer.config.ts"), logLevel: "warn" });
}
const manifestPath = join(distStaff, ".vite", "manifest.json");
if (!existsSync(manifestPath)) fail("staff manifest missing; build the staff app first");
const customerManifestPath = join(distCustomer, ".vite", "manifest.json");
if (!existsSync(customerManifestPath)) fail("customer manifest missing; build the customer app first");

rmSync(join(appDir, "dist-plugin"), { recursive: true, force: true });
mkdirSync(out, { recursive: true });
for (const entry of RUNTIME_ALLOWLIST) {
  cpSync(join(pluginSrc, entry), join(out, entry), { recursive: true });
}
mkdirSync(join(out, "staff-app", "assets"), { recursive: true });
cpSync(join(distStaff, "assets"), join(out, "staff-app", "assets"), { recursive: true });
cpSync(manifestPath, join(out, "staff-app", "manifest.json"));
writeFileSync(join(out, "staff-app", "index.php"), SILENCE);
writeFileSync(join(out, "staff-app", "assets", "index.php"), SILENCE);
mkdirSync(join(out, "customer-app", "assets"), { recursive: true });
cpSync(join(distCustomer, "assets"), join(out, "customer-app", "assets"), { recursive: true });
cpSync(customerManifestPath, join(out, "customer-app", "manifest.json"));
writeFileSync(join(out, "customer-app", "index.php"), SILENCE);
writeFileSync(join(out, "customer-app", "assets", "index.php"), SILENCE);

// STAGE-08 round 11 (owner decision D26 part 1): the member app's service worker and home-screen icons in
// customer-app/pwa/. The worker is one classic script built from src/customer/pwa/sw.ts (not minified, so it can be
// read and checked below); EVC_Customer_Pwa serves it at /club-member/sw.js with its configuration in front. The
// icons are the committed PNG files made by scripts/generate-pwa-icons.mjs from the bundled logo.
const PWA_ICONS = { "icon-192.png": 192, "icon-512.png": 512, "icon-maskable-512.png": 512, "apple-touch-icon.png": 180 };
{
  mkdirSync(join(out, "customer-app", "pwa"), { recursive: true });
  const { build: esbuildWorker } = await import("esbuild");
  await esbuildWorker({
    entryPoints: [join(appDir, "src", "customer", "pwa", "sw.ts")],
    absWorkingDir: appDir,
    bundle: true,
    format: "iife",
    platform: "browser",
    target: "es2020",
    minify: false,
    legalComments: "none",
    outfile: join(out, "customer-app", "pwa", "sw.js"),
    logLevel: "warning",
  });
  for (const icon of Object.keys(PWA_ICONS)) cpSync(join(appDir, "src", "customer", "pwa", "icons", icon), join(out, "customer-app", "pwa", icon));
  writeFileSync(join(out, "customer-app", "pwa", "index.php"), SILENCE);
}

let commit = "unknown";
try {
  commit = execSync("git rev-parse HEAD", { cwd: appDir }).toString().trim();
} catch {
  // not a git checkout
}
writeFileSync(join(out, "staff-app", "build-info.json"), JSON.stringify({ source_commit: commit }, null, 2) + "\n");
writeFileSync(join(out, "customer-app", "build-info.json"), JSON.stringify({ source_commit: commit }, null, 2) + "\n");

// ---- verification ---------------------------------------------------------
const manifest = JSON.parse(readFileSync(join(out, "staff-app", "manifest.json"), "utf8"));
const entry = manifest["staff.html"];
if (!entry || !entry.isEntry || !entry.file) fail("manifest has no staff.html entry");
for (const chunk of Object.values(manifest)) {
  for (const file of [chunk.file, ...(chunk.css ?? []), ...(chunk.assets ?? [])]) {
    if (file && !existsSync(join(out, "staff-app", file))) fail(`manifest references missing file ${file}`);
  }
}
const customerManifest = JSON.parse(readFileSync(join(out, "customer-app", "manifest.json"), "utf8"));
const customerEntry = customerManifest["customer.html"];
if (!customerEntry || !customerEntry.isEntry || !customerEntry.file) fail("customer manifest has no customer.html entry");
for (const chunk of Object.values(customerManifest)) {
  for (const file of [chunk.file, ...(chunk.css ?? []), ...(chunk.assets ?? [])]) {
    if (file && !existsSync(join(out, "customer-app", file))) fail(`customer manifest references missing file ${file}`);
  }
}

const files = walk(out).map((f) => relative(out, f).split(sep).join("/"));
for (const f of files) {
  if (/(^|\/)(tests|vendor|node_modules)\//.test(f) || /(^|\/)(composer\.(json|lock)|phpunit[^/]*|\.gitignore)$/.test(f)) {
    fail(`dev-only file shipped: ${f}`);
  }
  if (f.endsWith(".map")) fail(`source map shipped: ${f}`);
}
// Round 11: the worker has its own rules (it is not part of the app bundle and may use the cache).
const WORKER_FILE = "customer-app/pwa/sw.js";
{
  if (!files.includes(WORKER_FILE)) fail("the member app's service worker is missing from the package");
  const problems = workerProblems(readFileSync(join(out, WORKER_FILE), "utf8"));
  if (problems.length > 0) fail(problems.join("; "));
  for (const [icon, size] of Object.entries(PWA_ICONS)) {
    const png = readFileSync(join(out, "customer-app", "pwa", icon));
    const isPng = png.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]));
    if (!isPng || png.readUInt32BE(16) !== size || png.readUInt32BE(20) !== size) fail(`home-screen icon ${icon} is not a ${size} x ${size} PNG`);
  }
}
{
  const customerJs = files.filter((x) => x.startsWith("customer-app/") && x.endsWith(".js") && x !== WORKER_FILE);
  const texts = customerJs.map((f) => readFileSync(join(out, f), "utf8"));
  for (const word of ["localStorage", "Storage"]) {
    const count = texts.reduce((n, t) => n + t.split(word).length - 1, 0);
    if (count !== EXPECTED_CUSTOMER_LOCAL_STORAGE) fail(`the built customer JS contains "${word}" ${count} time(s); expected exactly ${EXPECTED_CUSTOMER_LOCAL_STORAGE} (src/customer/qrStore.ts only)`);
  }
  if (texts.some((t) => NAVIGATOR_DESTRUCTURING.test(t))) fail("the built customer JS destructures storage out of navigator");
}
const SAMPLE_MARKERS = sampleBundleMarkers(appDir);
{
  const sampleSelf = sampleGuardSelfTest(appDir);
  if (sampleSelf.length > 0) fail(`sample guard self-test failed: ${sampleSelf.join("; ")}`);
}
for (const f of files.filter((x) => /\.(js|css)$/.test(x) && x !== WORKER_FILE)) {
  const text = readFileSync(join(out, f), "utf8");
  const customer = f.startsWith("customer-app/");
  if (!customer && !f.startsWith("staff-app/")) fail(`unexpected bundle file ${f}`);
  for (const needle of customer ? FORBIDDEN_CUSTOMER_BUNDLE_STRINGS : FORBIDDEN_BUNDLE_STRINGS) {
    if (text.includes(needle)) fail(`forbidden content "${needle}" in ${f}`);
  }
  // Round 17: the member app never contains the design preview's sample QR, sample card texts or roster.
  if (customer) {
    const problems = sampleBundleProblems(f, text, SAMPLE_MARKERS);
    if (problems.length > 0) fail(problems.join("; "));
  }
}
// STAGE-03D: the guarded classes may be constructed ONLY by the WooCommerce-completion bootstrap, inside
// wire(), reached only through build() <- boot() after the real gate statement
// `if (!EVC_Wc_Completion_Feature::enabled()) { return; }` (token-based checks in php-guards.mjs: comments,
// strings, `new \EVC_X`, spacing and case variants are all handled; selfTest() runs negative fixtures on every
// package). Everywhere else they stay forbidden (as before STAGE-03D); EVC_Bacs_Payment_Issuer / _Finalizer /
// _Confirmation stay forbidden everywhere.
const WC_COMPLETION_BOOTSTRAP = "includes/customer/class-evc-wc-completion-plugin.php";
// STAGE-05B: EVC_Club_Member_Sync (Club member rows after a recorded confirmation) joins the guarded classes.
const GUARDED_CLASSES = ["EVC_Wc_Bacs_Intent_Store", "EVC_Bacs_Recovery_Inspector", "EVC_Bacs_Issuance_Guard", "EVC_Wc_Completion_Confirmer", "EVC_Wc_Completion_Recovery", "EVC_Wc_Completion_Markers", "EVC_Club_Member_Sync"];
const FORBIDDEN_CLASSES = ["EVC_Bacs_Payment_Confirmation", "EVC_Bacs_Payment_Finalizer", "EVC_Bacs_Payment_Issuer"];
// STAGE-05D round 2: browser storage in the customer bundle only in src/customer/qrStore.ts (localStorage).
{
  const storageSelf = storageSelfTest();
  if (storageSelf.length > 0) fail(`storage guard self-test failed: ${storageSelf.join("; ")}`);
  const { build: esbuildBuild } = await import("esbuild");
  const graph = await esbuildBuild({
    entryPoints: [join(appDir, "src", "customer", "main.tsx")],
    absWorkingDir: appDir,
    bundle: true,
    write: false,
    metafile: true,
    outdir: join(appDir, "dist-plugin", ".storage-graph"),
    format: "esm",
    platform: "browser",
    jsx: "automatic",
    loader: { ".png": "file", ".css": "empty", ".webp": "file", ".svg": "file", ".jpg": "file" },
    logLevel: "silent",
  });
  const inputs = Object.keys(graph.metafile.inputs).filter((p) => /\.(m?[jt]sx?|cjs)$/.test(p));
  if (!inputs.includes("src/customer/qrStore.ts")) fail("storage guard: the customer graph does not contain src/customer/qrStore.ts (sanity)");
  // Round 17: the member app's graph never reaches the preview-only sample modules.
  const sampleGraph = sampleGraphProblems(inputs.map((p) => p.split(sep).join("/")));
  if (sampleGraph.length > 0) fail(sampleGraph.join("; "));
  for (const input of inputs) {
    const problems = storageProblems(input.split(sep).join("/"), readFileSync(join(appDir, input), "utf8"));
    if (problems.length > 0) fail(problems.join("; "));
  }
}
const guardSelfTest = selfTest();
if (guardSelfTest.length > 0) fail(`PHP construction guard self-test failed: ${guardSelfTest.join("; ")}`);
// Only these may take the member lock: the (never constructed) internal finalizer, which the issuer uses through
// with_member_lock(), and the STAGE-03D confirmer (constructed only by the bootstrap above).
const MEMBER_LOCK_FILES = ["includes/customer/class-evc-bacs-payment-finalizer.php", "includes/customer/class-evc-wc-completion-confirmer.php"];
// The only shipped files that may name a guarded class or EVC_Wc_Completion_Plugin at all (identifier or string).
const GUARDED_NAME_FILES = [
  "evangelou-club.php",
  WC_COMPLETION_BOOTSTRAP,
  "includes/customer/class-evc-wc-completion-confirmer.php",
  "includes/customer/class-evc-wc-completion-recovery.php",
  "includes/customer/class-evc-wc-completion-markers.php",
  "includes/customer/class-evc-wc-bacs-intent-store.php",
  "includes/customer/class-evc-bacs-recovery-inspector.php",
  "includes/customer/class-evc-bacs-issuance-guard.php",
  "includes/customer/class-evc-bacs-payment-issuer.php",
  "includes/customer/class-evc-bacs-payment-finalizer.php",
  // STAGE-05B: the member-sync file itself, and the (never constructed) manager confirmation that accepts it.
  "includes/customer/class-evc-club-member-sync.php",
  "includes/customer/class-evc-bacs-payment-confirmation.php",
];
// Files that define a guarded class (or the bootstrap): no `new self|static|parent` inside them.
const GUARDED_CLASS_FILES = [
  WC_COMPLETION_BOOTSTRAP,
  "includes/customer/class-evc-wc-completion-confirmer.php",
  "includes/customer/class-evc-wc-completion-recovery.php",
  "includes/customer/class-evc-wc-completion-markers.php",
  "includes/customer/class-evc-wc-bacs-intent-store.php",
  "includes/customer/class-evc-bacs-recovery-inspector.php",
  "includes/customer/class-evc-bacs-issuance-guard.php",
  "includes/customer/class-evc-club-member-sync.php",
];
let sawBootstrap = false;
let sawMigrationsBootstrap = false;
const sawRedemption = new Set();
const sawMemberSync = new Set();
const sawQr = new Set();
const sawFluentcrm = new Set();
const sawOnboarding = new Set();
const sawAdminTools = new Set();
const sawPrepared = new Set();
let sawPwa = false;
const sawPush = new Set();
for (const f of files.filter((x) => x.endsWith(".php"))) {
  const text = readFileSync(join(out, f), "utf8");
  const path = f.replace(/\\/g, "/");
  for (const needle of FORBIDDEN_PHP_STRINGS) {
    if (text.includes(needle)) fail(`test-only PHP "${needle}" in ${f}`);
  }
  if (path === WC_COMPLETION_BOOTSTRAP) sawBootstrap = true;
  for (const problem of problemsFor(path, text, { bootstrap: WC_COMPLETION_BOOTSTRAP, guarded: GUARDED_CLASSES, forbidden: FORBIDDEN_CLASSES, allowlist: GUARDED_NAME_FILES, guardedFiles: GUARDED_CLASS_FILES, memberLockFiles: MEMBER_LOCK_FILES })) {
    fail(problem);
  }
  // STAGE-04A: the Club-DB migrations page / command / EVC_Migrator only behind EVC_CLUB_MIGRATIONS_ENABLED.
  if (path === MIGRATIONS_RULES.bootstrap) sawMigrationsBootstrap = true;
  for (const problem of problemsFor(path, text, MIGRATIONS_RULES)) {
    fail(problem);
  }
  // STAGE-05A: the real staff redemption backend only behind EVC_CLUB_REDEMPTION_SOURCE, in register_routes().
  if ([REDEMPTION_RULES.plugin, REDEMPTION_RULES.backendFile, REDEMPTION_RULES.catalogueFile, REDEMPTION_RULES.featureFile].includes(path)) sawRedemption.add(path);
  for (const problem of redemptionProblemsFor(path, text)) {
    fail(problem);
  }
  // STAGE-05B: EVC_Member_Sync_Service and the SQL identity store only in EVC_Club_Member_Sync::service().
  if ([MEMBER_SYNC_RULES.syncFile, MEMBER_SYNC_RULES.storeFile, MEMBER_SYNC_RULES.coreFile].includes(path)) sawMemberSync.add(path);
  for (const problem of memberSyncProblemsFor(path, text)) {
    fail(problem);
  }
  // STAGE-06: FluentCRM only through the gateway, built only behind EVC_CLUB_FLUENTCRM_SYNC_ENABLED.
  if ([FLUENTCRM_RULES.bootstrap, FLUENTCRM_RULES.gateway].includes(path)) sawFluentcrm.add(path);
  for (const problem of problemsFor(path, text, FLUENTCRM_RULES).concat(fluentcrmProblemsFor(path, text))) {
    fail(problem);
  }
  // STAGE-07: the onboarding pieces only behind EVC_CLUB_ONBOARDING_ENABLED; users created / signed in only there.
  if (ONBOARDING_RULES.guardedFiles.includes(path)) sawOnboarding.add(path);
  for (const problem of problemsFor(path, text, ONBOARDING_RULES).concat(onboardingProblemsFor(path, text))) {
    fail(problem);
  }
  // STAGE-08: the Club admin pages only behind EVC_CLUB_ADMIN_TOOLS_ENABLED; readiness never writes; no password leaks.
  if (ADMIN_TOOLS_RULES.guardedFiles.includes(path)) sawAdminTools.add(path);
  for (const problem of problemsFor(path, text, ADMIN_TOOLS_RULES).concat(adminToolsProblemsFor(path, text))) {
    fail(problem);
  }
  // Round 9 (D24): the prepared-redemption pieces only at their one construction site, behind the redemption opt-in.
  if (Object.values(PREPARED_RULES).includes(path)) sawPrepared.add(path);
  for (const problem of preparedProblemsFor(path, text)) {
    fail(problem);
  }
  // Round 11 (D26): the manifest, the iOS tags and the worker only in EVC_Customer_Pwa; never Service-Worker-Allowed.
  if (path === PWA_RULES.pwaFile) sawPwa = true;
  for (const problem of pwaProblemsFor(path, text)) {
    fail(problem);
  }
  // Round 12 (D26 part 2): the push pieces only behind EVC_CLUB_PUSH_ENABLED; HTTP only in the sender; no secret in logs.
  if (PUSH_RULES.guardedFiles.includes(path) || PUSH_RULES.pureFiles.includes(path)) sawPush.add(path);
  for (const problem of problemsFor(path, text, PUSH_RULES).concat(pushProblemsFor(path, text))) {
    fail(problem);
  }
  // STAGE-05C: the QR pieces only at their one construction site, behind the customer / staff opt-ins.
  if (Object.values(QR_RULES).includes(path)) sawQr.add(path);
  for (const problem of qrProblemsFor(path, text)) {
    fail(problem);
  }
}
if (sawQr.size !== Object.values(QR_RULES).length) fail("a STAGE-05C QR file is missing from the package");
if (!sawPwa) fail("the round-11 home-screen app file (EVC_Customer_Pwa) is missing from the package");
if (sawPush.size !== PUSH_RULES.guardedFiles.length + PUSH_RULES.pureFiles.length) fail("a round-12 push file is missing from the package");
if (sawPrepared.size !== new Set(Object.values(PREPARED_RULES)).size) fail("a round-9 prepared-redemption file is missing from the package");
if (sawFluentcrm.size !== 2) fail("a STAGE-06 FluentCRM file (plugin or gateway) is missing from the package");
if (sawOnboarding.size !== ONBOARDING_RULES.guardedFiles.length) fail("a STAGE-07 onboarding file is missing from the package");
if (sawAdminTools.size !== ADMIN_TOOLS_RULES.guardedFiles.length) fail("a STAGE-08 admin-tools file is missing from the package");
if (!sawBootstrap) fail("the STAGE-03D bootstrap file is missing from the package");
if (!sawMigrationsBootstrap) fail("the STAGE-04A migrations bootstrap file is missing from the package");
if (sawMemberSync.size !== 3) fail("a STAGE-05B member-sync file (sync, SQL store or core) is missing from the package");
if (sawRedemption.size !== 4) fail("a STAGE-05A redemption file (staff plugin, backend, catalogue source or feature) is missing from the package");

console.log(
  `[evidence] club-plugin-package: files=${files.length} entry=${entry.file} css=${(entry.css ?? []).join(",")} customer=${customerEntry.file} commit=${commit.slice(0, 7)} path=${relative(appDir, out)}`,
);
