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
import { problemsFor, selfTest } from "./php-guards.mjs";

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
];
// STAGE-01 customer app: the approved screens with the non-redeemable sample QR stamp are expected,
// but never the synthetic roster, the local journey simulation, the staff app or a mock service.
const FORBIDDEN_CUSTOMER_BUNDLE_STRINGS = [
  "example.invalid", "Αλεξάνδρα Κωνσταντίνου", "evc-club-design-preview", "customer-journey-local", "evc-cj", "SCENARIO_START",
  "mockClubService", "createMockClubService", "DEMO-QR", "evc-staff-config", "/club-admin", "x-admin-password", "/api/orders", "/api/overrides",
  "localStorage", "sessionStorage",
];
const FORBIDDEN_PHP_STRINGS = ["class EVC_Mock_Membership_Adapter", "class EVC_Test_", "class EVC_Faulty_Pdo", "class EVC_Tripwire", "class EVC_Fake_Pmpro_Reader", "class EVC_Pmpro_Fixture", "class EVC_In_Memory_Provenance_Store", "class EVC_Provenance_Fact_Builder", "class EVC_Provenance_Test_Fixture", "trait EVC_Provenance_Store_Contract", "new EVC_Bacs_Payment_Confirmation", "new EVC_Bacs_Payment_Finalizer", "new EVC_Bacs_Payment_Issuer", "class EVC_Hooked_Provenance_Store", "class EVC_Bacs_Intent_Fixture", "class EVC_Read_Only_Provenance_Store_Spy", "class EVC_Fake_Payment_Source_Verifier", "class EVC_Payment_Identity_Fixture", "implements EVC_Payment_Source_Verifier", "new EVC_Payment_Evidence_Gate", "new EVC_Prepared_Redeem_Precheck", "implements EVC_Prep_Request_Lookup", "class EVC_In_Memory_Prepared_Redemption_Store", "class EVC_Fake_Prep_Catalogue_Authority", "new EVC_Prepared_Redemption_Service", "implements EVC_Prep_Redemption_Store", "implements EVC_Prep_Catalogue_Authority", "class EVC_Fake_Club_Staff_Authorizer", "new EVC_Handoff_Service", "new EVC_Fulfillment_Read_Model", "implements EVC_Redemption_Fulfillment_Store", "implements EVC_Club_Staff_Authorizer", "class EVC_Fake_Redemption_History", "class EVC_Fake_Member_Context_Verifier", "new EVC_Usual_Coffee_Service", "implements EVC_Redemption_History_Source", "new EVC_Signed_Member_Context_Verifier", "new EVC_Member_Lookup_Service", "new EVC_Qr_Token_Service", "new EVC_Lookup_Key_Ring", "implements EVC_Qr_Token_Store", "implements EVC_Member_Phone_Directory", "implements EVC_Lookup_Rate_Limiter", "class EVC_Fake_Qr_Token_Store", "class EVC_Fake_Member_Phone_Directory", "class EVC_Fake_Lookup_Rate_Limiter", "class EVC_Fake_Member_Identity_Store", "class EVC_Fake_Wp_Account_Directory", "class EVC_Fake_Member_Status_Policy", "class EVC_Fake_Qr_Delivery_Policy", "new EVC_Member_Sync_Service", "new EVC_Member_Account_Service", "new EVC_Member_Qr_Coordinator", "implements EVC_Member_Identity_Store", "implements EVC_Wp_Account_Directory", "implements EVC_Member_Status_Policy", "implements EVC_Qr_Delivery_Policy", "class EVC_Contract_Vector_Builder", "new EVC_Rest_V2_Domain_Response", "register_rest_route(EVC_Rest_V2_Routes", "'EVC_Rest_V2_Security'", "EVC_Rest_V2_Security::filter_post_dispatch", "EVC_Test_V2_Harness", "EVC_Staff_Role_Upgrade_Planner::", "new EVC_Staff_Role_Upgrade_Approval", "EVC_Staff_Role_Versions::VERSION_OPTION"];
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
for (const f of files.filter((x) => /\.(js|css)$/.test(x))) {
  const text = readFileSync(join(out, f), "utf8");
  const customer = f.startsWith("customer-app/");
  if (!customer && !f.startsWith("staff-app/")) fail(`unexpected bundle file ${f}`);
  for (const needle of customer ? FORBIDDEN_CUSTOMER_BUNDLE_STRINGS : FORBIDDEN_BUNDLE_STRINGS) {
    if (text.includes(needle)) fail(`forbidden content "${needle}" in ${f}`);
  }
}
// STAGE-03D: the guarded classes may be constructed ONLY by the WooCommerce-completion bootstrap, inside
// wire(), reached only through build() <- boot() after the real gate statement
// `if (!EVC_Wc_Completion_Feature::enabled()) { return; }` (token-based checks in php-guards.mjs: comments,
// strings, `new \EVC_X`, spacing and case variants are all handled; selfTest() runs negative fixtures on every
// package). Everywhere else they stay forbidden (as before STAGE-03D); EVC_Bacs_Payment_Issuer / _Finalizer /
// _Confirmation stay forbidden everywhere.
const WC_COMPLETION_BOOTSTRAP = "includes/customer/class-evc-wc-completion-plugin.php";
const GUARDED_CLASSES = ["EVC_Wc_Bacs_Intent_Store", "EVC_Bacs_Recovery_Inspector", "EVC_Bacs_Issuance_Guard", "EVC_Wc_Completion_Confirmer", "EVC_Wc_Completion_Recovery", "EVC_Wc_Completion_Markers"];
const FORBIDDEN_CLASSES = ["EVC_Bacs_Payment_Confirmation", "EVC_Bacs_Payment_Finalizer", "EVC_Bacs_Payment_Issuer"];
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
];
let sawBootstrap = false;
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
}
if (!sawBootstrap) fail("the STAGE-03D bootstrap file is missing from the package");

console.log(
  `[evidence] club-plugin-package: files=${files.length} entry=${entry.file} css=${(entry.css ?? []).join(",")} customer=${customerEntry.file} commit=${commit.slice(0, 7)} path=${relative(appDir, out)}`,
);
