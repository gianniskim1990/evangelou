// Token-based guards over the PHP that ships in the Club plugin (no regular expressions on raw text:
// comments and strings never count, `new \EVC_X`, `new   EVC_X`, `NEW EVC_X` and a `new` split over
// lines are all seen). Used by package-club-plugin.mjs, which also runs selfTest() on every package so
// a broken guard fails the build instead of passing silently.
//
// STAGE-03D rule set (the WooCommerce-completion path exists only behind EVC_CLUB_WC_COMPLETION_ENABLED):
//  1. Guarded classes may be constructed ONLY inside EVC_Wc_Completion_Plugin::wire().
//  2. wire() may be called only from build(); build() only from boot(), and only after boot()'s first
//     statement, which must be the real gate `if (!EVC_Wc_Completion_Feature::enabled()) { return; }`.
//  3. No other shipped file may call EVC_Wc_Completion_Plugin::build()/wire().
//  4. (review L-A) The guarded class names and EVC_Wc_Completion_Plugin may appear (as identifiers or inside
//     strings) only in an allowlist of files; class_alias / Reflection* / newInstance* / unserialize, dynamic
//     `new $x` / `new (...)`, `$x::` calls, and `new self|static|parent` inside a guarded class are forbidden;
//     boot() / build() / wire() are defined exactly once in the bootstrap; EVC_Member_Lock::acquire( only in the
//     allowed files; the TEST-ONLY construction constant is never define()d.
// Not covered statically (documented): a class name assembled from string pieces at run time. The guarded
// constructors refuse to run while the feature is off (EVC_Wc_Completion_Feature::assert_constructible).

export function tokenize(src) {
  const out = [];
  const n = src.length;
  let i = 0;
  let php = false;
  const push = (t, v) => out.push({ t, v });
  while (i < n) {
    if (!php) {
      const open = src.indexOf("<?php", i);
      const openShort = src.indexOf("<?=", i);
      const next = [open, openShort].filter((x) => x >= 0).sort((a, b) => a - b)[0];
      if (next === undefined) break;
      i = next + (src.startsWith("<?php", next) ? 5 : 3);
      php = true;
      continue;
    }
    const c = src[i];
    if (c === " " || c === "\t" || c === "\r" || c === "\n") { i++; continue; }
    if (c === "?" && src[i + 1] === ">") { php = false; i += 2; push("op", ";"); continue; }
    if (c === "#" && src[i + 1] !== "[") { while (i < n && src[i] !== "\n" && !(src[i] === "?" && src[i + 1] === ">")) i++; continue; }
    if (c === "/" && src[i + 1] === "/") { while (i < n && src[i] !== "\n" && !(src[i] === "?" && src[i + 1] === ">")) i++; continue; }
    if (c === "/" && src[i + 1] === "*") { const e = src.indexOf("*/", i + 2); i = e < 0 ? n : e + 2; continue; }
    if (c === "'" || c === '"') {
      let j = i + 1;
      while (j < n && src[j] !== c) { if (src[j] === "\\") j++; j++; }
      push("str", src.slice(i + 1, j)); i = j + 1; continue;
    }
    if (c === "<" && src.startsWith("<<<", i)) {
      const m = /^<<<[ \t]*(['"]?)([A-Za-z_][A-Za-z0-9_]*)\1\r?\n/.exec(src.slice(i, i + 200));
      if (m) {
        const re = new RegExp("\\n[ \\t]*" + m[2] + "\\b");
        const rest = src.slice(i + m[0].length - 1);
        const hit = re.exec(rest);
        push("str", hit ? rest.slice(0, hit.index) : rest);
        i = hit ? i + m[0].length - 1 + hit.index + hit[0].length : n;
        continue;
      }
    }
    if (c === "$" && /[A-Za-z_]/.test(src[i + 1] ?? "")) { let j = i + 1; while (j < n && /[A-Za-z0-9_]/.test(src[j])) j++; push("var", src.slice(i, j)); i = j; continue; }
    if (/[A-Za-z_\\\x80-￿]/.test(c)) { let j = i; while (j < n && /[A-Za-z0-9_\\\x80-￿]/.test(src[j])) j++; push("id", src.slice(i, j)); i = j; continue; }
    if (/[0-9]/.test(c)) { let j = i; while (j < n && /[0-9A-Za-z_.]/.test(src[j])) j++; push("num", src.slice(i, j)); i = j; continue; }
    if (c === ":" && src[i + 1] === ":") { push("op", "::"); i += 2; continue; }
    if (c === "-" && src[i + 1] === ">") { push("op", "->"); i += 2; continue; }
    if (c === "?" && src[i + 1] === "-" && src[i + 2] === ">") { push("op", "->"); i += 3; continue; }
    push("op", c); i++;
  }
  return out;
}

const bare = (name) => name.replace(/^\\+/, "");
const same = (a, b) => bare(a).toLowerCase() === bare(b).toLowerCase();

/** [{name, at}] for every `new <Name>` (names resolved without a leading backslash). */
export function constructions(tokens) {
  const found = [];
  tokens.forEach((tk, k) => {
    if (tk.t === "id" && tk.v.toLowerCase() === "new" && tokens[k + 1]?.t === "id") found.push({ name: bare(tokens[k + 1].v), at: k });
  });
  return found;
}

/** {name -> {start, end}} token ranges of every function / method body (brace matched). */
export function functionBodies(tokens) {
  const bodies = {};
  Object.defineProperty(bodies, "__count", { value: {}, enumerable: false });
  tokens.forEach((tk, k) => {
    if (tk.t !== "id" || tk.v.toLowerCase() !== "function" || tokens[k + 1]?.t !== "id") return;
    const name = tokens[k + 1].v;
    let j = k + 2;
    if (tokens[j]?.v !== "(") return;
    let depth = 0;
    for (; j < tokens.length; j++) {
      if (tokens[j].v === "(") depth++;
      else if (tokens[j].v === ")" && --depth === 0) break;
    }
    while (j < tokens.length && tokens[j].v !== "{" && tokens[j].v !== ";") j++;
    if (tokens[j]?.v !== "{") return;
    const start = j;
    depth = 0;
    for (; j < tokens.length; j++) {
      if (tokens[j].v === "{") depth++;
      else if (tokens[j].v === "}" && --depth === 0) break;
    }
    bodies.__count[name.toLowerCase()] = (bodies.__count[name.toLowerCase()] ?? 0) + 1;
    bodies[name] = { start, end: j };
  });
  return bodies;
}

const gateFor = (featureClass) => ["{", "if", "(", "!", featureClass, "::", "enabled", "(", ")", ")", "{", "return", ";", "}"];

/** True when boot()'s body STARTS with the real gate statement of $featureClass. */
export function bootIsGated(tokens, bodies, featureClass = "EVC_Wc_Completion_Feature") {
  const b = bodies.boot;
  if (!b) return false;
  return gateFor(featureClass).every((v, i) => {
    const tk = tokens[b.start + i];
    return tk && (tk.t === "str" ? false : same(tk.v, v) || tk.v === v);
  });
}

/** Static or self calls to Class::name( : [{cls, name, at}] */
export function staticCalls(tokens, names) {
  const calls = [];
  tokens.forEach((tk, k) => {
    if (tk.t === "id" && tokens[k + 1]?.v === "::" && tokens[k + 2]?.t === "id" && names.includes(tokens[k + 2].v.toLowerCase()) && tokens[k + 3]?.v === "(") {
      calls.push({ cls: bare(tk.v), name: tokens[k + 2].v.toLowerCase(), at: k });
    }
  });
  return calls;
}

const inside = (bodies, fn, at) => bodies[fn] && at > bodies[fn].start && at < bodies[fn].end;

/**
 * Problems for ONE shipped PHP file. $path is relative to the plugin ("includes/...").
 * $guarded: classes only the bootstrap may construct. $forbidden: classes nobody may construct.
 */
const DANGEROUS = ["class_alias", "reflectionclass", "reflectionobject", "reflectionmethod", "newinstance", "newinstanceargs", "newinstancewithoutconstructor", "unserialize"];
const TEST_CONSTANT = "EVC_TEST_WC_COMPLETION_CONSTRUCTION";

export function problemsFor(path, src, { bootstrap, guarded, forbidden, allowlist = null, guardedFiles = [], memberLockFiles = null, pluginClass = "EVC_Wc_Completion_Plugin", featureClass = "EVC_Wc_Completion_Feature", forbiddenDefines = [TEST_CONSTANT] }) {
  const tokens = tokenize(src);
  const bodies = functionBodies(tokens);
  const problems = [];
  const restricted = [...guarded, pluginClass].map((n) => n.toLowerCase());
  const allowed = allowlist === null || allowlist.includes(path);
  tokens.forEach((tk, k) => {
    if (tk.t === "id") {
      const v = bare(tk.v).toLowerCase();
      if (!allowed && restricted.some((r) => v === r || v.endsWith("\\" + r))) problems.push(`${path}: mentions ${bare(tk.v)} (not in the allowlist)`);
      if (DANGEROUS.includes(v)) problems.push(`${path}: uses ${tk.v}`);
      if (v === "new") {
        const nx = tokens[k + 1];
        if (!nx || nx.t === "var" || nx.v === "(" || nx.t === "str") problems.push(`${path}: dynamic construction (new ${nx ? nx.v : ""})`);
        else if (guardedFiles.includes(path) && nx.t === "id" && ["self", "static", "parent", "class"].includes(nx.v.toLowerCase())) problems.push(`${path}: new ${nx.v} inside a guarded class`);
      }
      if (v === "define" && tokens[k + 1]?.v === "(" && tokens[k + 2]?.t === "str" && forbiddenDefines.includes(tokens[k + 2].v.replace(/^\\+/, ""))) problems.push(`${path}: defines ${tokens[k + 2].v} (never in a shipped file)`);
    }
    if (tk.t === "str") {
      const v = tk.v.toLowerCase();
      if (!allowed && restricted.some((r) => v.includes(r))) problems.push(`${path}: a string names a guarded class (not in the allowlist)`);
      if (memberLockFiles !== null && !memberLockFiles.includes(path) && v.includes("evc_member_lock")) problems.push(`${path}: a string names EVC_Member_Lock`);
    }
    if (tk.t === "var" && tokens[k + 1]?.v === "::") problems.push(`${path}: dynamic static call ${tk.v}::`);
    // STAGE-04A review: string callables reaching build()/wire() (array('X_Plugin', 'build'), 'X_Plugin::wire'),
    // even inside allowlisted files; only boot()/deactivate() may be named that way (the plugin entrypoint).
    if (tk.t === "str") {
      const sv = tk.v.replace(/^\\+/, "").toLowerCase();
      const plugin = pluginClass.toLowerCase();
      if (sv === plugin && tokens[k + 1]?.v === "," && tokens[k + 2]?.t === "str" && ["build", "wire"].includes(tokens[k + 2].v.toLowerCase())) problems.push(`${path}: string callable ${pluginClass}::${tokens[k + 2].v}`);
      if (sv.replace(/\s+/g, "") === plugin + "::build" || sv.replace(/\s+/g, "") === plugin + "::wire") problems.push(`${path}: string callable ${tk.v}`);
    }
  });
  if (memberLockFiles !== null && !memberLockFiles.includes(path)) {
    for (const call of staticCalls(tokens, ["acquire"])) {
      if (call.cls.toLowerCase() === "evc_member_lock") problems.push(`${path}: EVC_Member_Lock::acquire() outside the allowed files`);
    }
  }
  if (path === bootstrap) {
    for (const fn of ["boot", "build", "wire"]) {
      if ((bodies.__count[fn] ?? 0) !== 1) problems.push(`${path}: ${fn}() must be defined exactly once`);
    }
  }
  for (const c of constructions(tokens)) {
    if (forbidden.some((f) => same(f, c.name))) problems.push(`${path}: forbidden construction of ${c.name}`);
    if (guarded.some((g) => same(g, c.name)) && !(path === bootstrap && inside(bodies, "wire", c.at))) {
      problems.push(`${path}: ${c.name} constructed outside ${bootstrap}::wire()`);
    }
  }
  for (const call of staticCalls(tokens, ["build", "wire"])) {
    if (call.cls.toLowerCase() === pluginClass.toLowerCase() || (path === bootstrap && ["self", "static"].includes(call.cls.toLowerCase()))) {
      if (path !== bootstrap) { problems.push(`${path}: calls ${call.cls}::${call.name}() outside the bootstrap`); continue; }
      const okIn = call.name === "wire" ? "build" : "boot";
      if (!inside(bodies, okIn, call.at)) problems.push(`${path}: ${call.cls}::${call.name}() outside ${okIn}()`);
      if (call.name === "build" && bodies.boot && inside(bodies, "boot", call.at) && !bootIsGated(tokens, bodies, featureClass)) problems.push(`${path}: boot() is not gated by the Feature::enabled() statement`);
    }
  }
  if (path === bootstrap && !bootIsGated(tokens, bodies, featureClass)) problems.push(`${path}: boot() does not start with if (!${featureClass}::enabled()) { return; }`);
  return problems;
}

// ---- STAGE-04A rule set: the Club-DB migrations page / command exist only behind EVC_CLUB_MIGRATIONS_ENABLED ----
// Same rules as above with its own bootstrap and gate: the service, admin page, CLI command AND EVC_Migrator
// may be constructed only inside EVC_Club_Migrations_Plugin::wire() (reached only via build() <- boot() after
// `if (!EVC_Club_Migrations_Feature::enabled()) { return; }`); their names appear only in the allowlisted
// files; no shipped file may define EVC_CLUB_MIGRATIONS_ENABLED itself.
const MIGRATIONS_BOOTSTRAP = "includes/admin/class-evc-club-migrations-plugin.php";
export const MIGRATIONS_RULES = Object.freeze({
  bootstrap: MIGRATIONS_BOOTSTRAP,
  pluginClass: "EVC_Club_Migrations_Plugin",
  featureClass: "EVC_Club_Migrations_Feature",
  guarded: ["EVC_Club_Migrations_Service", "EVC_Club_Migrations_Admin_Page", "EVC_Club_Migrations_Cli", "EVC_Migrator"],
  forbidden: [],
  allowlist: [
    "evangelou-club.php",
    MIGRATIONS_BOOTSTRAP,
    "includes/admin/class-evc-club-migrations-service.php",
    "includes/admin/class-evc-club-migrations-admin-page.php",
    "includes/admin/class-evc-club-migrations-cli.php",
    "includes/class-evc-migrator.php",
  ],
  guardedFiles: [
    MIGRATIONS_BOOTSTRAP,
    "includes/admin/class-evc-club-migrations-service.php",
    "includes/admin/class-evc-club-migrations-admin-page.php",
    "includes/admin/class-evc-club-migrations-cli.php",
    "includes/class-evc-migrator.php",
  ],
  memberLockFiles: null,
  forbiddenDefines: ["EVC_CLUB_MIGRATIONS_ENABLED"],
});
const GOOD_MIGRATIONS = `<?php
final class EVC_Club_Migrations_Plugin {
    public static function boot(): void {
        if (!EVC_Club_Migrations_Feature::enabled()) {
            return;
        }
        $p = self::build();
    }
    public static function build() { return self::wire(); }
    private static function wire() {
        $s = new EVC_Club_Migrations_Service(function ($db) { return new EVC_Migrator($db, 'd'); });
        return array(new EVC_Club_Migrations_Admin_Page($s), new EVC_Club_Migrations_Cli($s));
    }
}`;

function migrationsSelfTest() {
  const bad = [];
  const expect = (label, path, src, want) => {
    const got = problemsFor(path, src, MIGRATIONS_RULES).length > 0;
    if (got !== want) bad.push(`migrations ${label}: expected ${want ? "a problem" : "no problem"}`);
  };
  const boot = MIGRATIONS_BOOTSTRAP;
  const allowed = "includes/admin/class-evc-club-migrations-service.php";
  expect("good bootstrap", boot, GOOD_MIGRATIONS, false);
  expect("service constructed elsewhere", allowed, "<?php\n$s = new EVC_Club_Migrations_Service(1);", true);
  expect("EVC_Migrator constructed in an allowlisted file", allowed, "<?php\n$m = new \\EVC_Migrator($db, $dir);", true);
  expect("EVC_Migrator constructed in another file", "includes/x.php", "<?php\n$m = new EVC_Migrator($db, $dir);", true);
  expect("migrator named outside the allowlist", "includes/x.php", "<?php\nfunction f(EVC_Migrator $m) { return $m; }", true);
  expect("migrator static call outside the allowlist", "includes/x.php", "<?php\n$x = EVC_Migrator::split_statements($s);", true);
  expect("admin page constructed outside wire()", boot, GOOD_MIGRATIONS.replace("public static function build() {", "public static function build() { new EVC_Club_Migrations_Admin_Page(1);"), true);
  expect("gate of another feature", boot, GOOD_MIGRATIONS.replace("EVC_Club_Migrations_Feature::enabled", "EVC_Wc_Completion_Feature::enabled"), true);
  expect("gate missing", boot, GOOD_MIGRATIONS.replace("if (!EVC_Club_Migrations_Feature::enabled()) {\n            return;\n        }", ""), true);
  expect("gate negation dropped", boot, GOOD_MIGRATIONS.replace("if (!EVC_Club_Migrations_Feature", "if (EVC_Club_Migrations_Feature"), true);
  expect("build() from another file", "includes/x.php", "<?php\nEVC_Club_Migrations_Plugin::build();", true);
  expect("plugin named in a string elsewhere", "includes/x.php", "<?php\nadd_action('init', array('EVC_Club_Migrations_Plugin', 'build'));", true);
  expect("defines the opt-in constant", "includes/x.php", "<?php\ndefine('EVC_CLUB_MIGRATIONS_ENABLED', true);", true);
  expect("defines the opt-in constant in the bootstrap", boot, GOOD_MIGRATIONS.replace("final class", "define('EVC_CLUB_MIGRATIONS_ENABLED', true);\nfinal class"), true);
  expect("new self inside the migrator", "includes/class-evc-migrator.php", "<?php\nfinal class EVC_Migrator { public static function make() { return new self(); } }", true);
  expect("file paths with hyphens are not names", "includes/admin/bootstrap.php", "<?php\nrequire_once $d . 'class-evc-club-migrations-service.php';", false);
  expect("entrypoint may hook boot()", "evangelou-club.php", "<?php\nadd_action('plugins_loaded', array('EVC_Club_Migrations_Plugin', 'boot'));", false);
  expect("array callable to build() in an allowlisted file", "evangelou-club.php", "<?php\nadd_action('init', array('EVC_Club_Migrations_Plugin', 'build'));", true);
  expect("array callable to wire() in an allowlisted file", allowed, "<?php\ncall_user_func(array('\\\\EVC_Club_Migrations_Plugin', 'WIRE'));", true);
  expect("string callable to build() in an allowlisted file", "evangelou-club.php", "<?php\nadd_action('init', 'EVC_Club_Migrations_Plugin::build');", true);
  return bad;
}

// ---- STAGE-05A rule set: the staff redemption backend on real memberships (EVC_CLUB_REDEMPTION_SOURCE) ----------
// 1. EVC_Club_Redemption_Backend is constructed exactly once, in EVC_Staff_Plugin::register_routes(), whose body starts
//    with the staff gate `if (!EVC_Staff_Feature::enabled()) { return; }`; the construction is the true branch of
//    `EVC_Club_Redemption_Feature::enabled() ? new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source())`
//    (no test seam arguments) and register_routes() still constructs EVC_Unavailable_Redemption_Backend (the default).
// 2. Its name appears only in the staff plugin and its own file; EVC_Approved_Coffee_Catalogue_Source only in the staff
//    plugin and the catalogue file. EVC_Coffee_Catalog is constructed only in the catalogue file, inside catalogue().
// 3. Only the catalogue file implements EVC_Redemption_Catalogue_Source and only the two backend files implement
//    EVC_Redemption_Backend (no test catalogue, fake or extra backend can ship).
// 4. EVC_Club_Redemption_Feature::is_enabled_value() is exactly `return $value === self::VALUE;` with
//    `const VALUE = 'club_db_v1';`; no shipped file defines EVC_CLUB_REDEMPTION_SOURCE or EVC_CLUB_PAYMENT_PROVENANCE.
// Every earlier rule set (STAGE-03D, STAGE-04A, and the packager's v2 / QR / lookup / member-sync string bans) is
// unchanged and still applies to the same files.
const STAFF_PLUGIN = "includes/staff/class-evc-staff-plugin.php";
export const REDEMPTION_RULES = Object.freeze({
  plugin: STAFF_PLUGIN,
  backendFile: "includes/staff/class-evc-club-redemption-backend.php",
  defaultBackendFile: "includes/staff/class-evc-redemption-backend.php",
  catalogueFile: "includes/staff/class-evc-redemption-catalogue-source.php",
  featureFile: "includes/staff/class-evc-club-redemption-feature.php",
});
const STAFF_GATE = ["{", "if", "(", "!", "EVC_Staff_Feature", "::", "enabled", "(", ")", ")", "{", "return", ";", "}"];
const BACKEND_PREFIX = ["EVC_Club_Redemption_Feature", "::", "enabled", "(", ")", "?"];
const BACKEND_ARGS = ["(", "new", "EVC_Approved_Coffee_Catalogue_Source", "(", ")", ")"];
// STAGE-05A review: the WHOLE statement, so the condition cannot be widened (`true || ...`, `!...`, `false && ...`,
// parentheses) and the result cannot be replaced afterwards. It must stand alone (preceded by `;`, `{` or `}`).
const BACKEND_STATEMENT = ["$backend", "=", ...BACKEND_PREFIX, "new", "EVC_Club_Redemption_Backend", ...BACKEND_ARGS, ":", "new", "EVC_Unavailable_Redemption_Backend", "(", ")", ";"];
const BACKEND_USE = ["(", "new", "EVC_Rest_Redeem_Controller", "(", "$backend", ")", ")", "->", "register_routes", "(", ")", ";"];
const matchAt = (tokens, at, seq) => seq.every((v, i) => { const tk = tokens[at + i]; return tk && tk.t !== "str" && same(tk.v, v); });

export function redemptionProblemsFor(path, src, rules = REDEMPTION_RULES) {
  const tokens = tokenize(src);
  const bodies = functionBodies(tokens);
  const problems = [];
  const names = [
    ["evc_club_redemption_backend", [rules.plugin, rules.backendFile]],
    ["evc_approved_coffee_catalogue_source", [rules.plugin, rules.catalogueFile]],
  ];
  tokens.forEach((tk, k) => {
    if (tk.t === "id" || tk.t === "str") {
      const v = bare(tk.v).toLowerCase();
      for (const [name, files] of names) {
        const hit = tk.t === "id" ? v === name || v.endsWith("\\" + name) : v.includes(name);
        if (hit && !files.includes(path)) problems.push(`${path}: names ${name} (redemption allowlist)`);
      }
    }
    if (tk.t === "id" && tk.v.toLowerCase() === "define" && tokens[k + 1]?.v === "(" && tokens[k + 2]?.t === "str" && ["EVC_CLUB_REDEMPTION_SOURCE", "EVC_CLUB_PAYMENT_PROVENANCE"].includes(tokens[k + 2].v.replace(/^\\+/, ""))) {
      problems.push(`${path}: defines ${tokens[k + 2].v} (never in a shipped file)`);
    }
    if (tk.t === "id" && tk.v.toLowerCase() === "implements") {
      for (let j = k + 1; j < tokens.length && tokens[j].v !== "{"; j++) {
        if (tokens[j].t !== "id") continue;
        if (same(tokens[j].v, "EVC_Redemption_Catalogue_Source") && path !== rules.catalogueFile) problems.push(`${path}: implements EVC_Redemption_Catalogue_Source`);
        if (same(tokens[j].v, "EVC_Redemption_Backend") && ![rules.backendFile, rules.defaultBackendFile].includes(path)) problems.push(`${path}: implements EVC_Redemption_Backend`);
      }
    }
  });
  const built = constructions(tokens);
  let backends = 0;
  for (const c of built) {
    if (same(c.name, "EVC_Coffee_Catalog") && !(path === rules.catalogueFile && inside(bodies, "catalogue", c.at))) problems.push(`${path}: EVC_Coffee_Catalog constructed outside the approved catalogue source`);
    if (same(c.name, "EVC_Club_Redemption_Backend")) {
      backends++;
      const start = c.at - 2 - BACKEND_PREFIX.length;
      const ok = path === rules.plugin && inside(bodies, "register_routes", c.at) && start > 0 && [";", "{", "}"].includes(tokens[start - 1]?.v) && matchAt(tokens, start, BACKEND_STATEMENT);
      if (!ok) problems.push(`${path}: EVC_Club_Redemption_Backend constructed outside \`EVC_Club_Redemption_Feature::enabled() ? new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source())\` in register_routes()`);
    }
    if (same(c.name, "EVC_Approved_Coffee_Catalogue_Source")) {
      const ok = path === rules.plugin && matchAt(tokens, c.at - 3, ["new", "EVC_Club_Redemption_Backend", "("]);
      if (!ok) problems.push(`${path}: EVC_Approved_Coffee_Catalogue_Source constructed outside the backend construction`);
    }
  }
  if (backends > 1) problems.push(`${path}: EVC_Club_Redemption_Backend constructed more than once`);
  if (path === rules.plugin) {
    if ((bodies.__count.register_routes ?? 0) !== 1) problems.push(`${path}: register_routes() must be defined exactly once`);
    const b = bodies.register_routes;
    if (!b || !matchAt(tokens, b.start, STAFF_GATE)) problems.push(`${path}: register_routes() does not start with if (!EVC_Staff_Feature::enabled()) { return; }`);
    if (!built.some((c) => same(c.name, "EVC_Unavailable_Redemption_Backend") && inside(bodies, "register_routes", c.at))) problems.push(`${path}: register_routes() lost the default EVC_Unavailable_Redemption_Backend`);
    if (backends !== 1) problems.push(`${path}: register_routes() must construct EVC_Club_Redemption_Backend exactly once (behind the opt-in)`);
    if (b) {
      let assignments = 0;
      let uses = 0;
      for (let k = b.start; k < b.end; k++) {
        if (tokens[k].t === "var" && tokens[k].v === "$backend" && tokens[k + 1]?.v === "=" && tokens[k + 2]?.v !== "=") assignments++;
        if (tokens[k].t === "var" && tokens[k].v === "$backend") {
          // compound assignments: .= ??= |= <<= **= ...
          let j = k + 1;
          while (tokens[j] && tokens[j].t === "op" && ["+", "-", "*", "/", ".", "?", "|", "&", "^", "%", "<", ">"].includes(tokens[j].v)) j++;
          const run = tokens.slice(k + 1, j).map((t) => t.v).join("");
          if (j > k + 1 && run !== "<" && run !== ">" && tokens[j]?.v === "=" && tokens[j + 1]?.v !== "=") assignments++;
        }
        if (matchAt(tokens, k, BACKEND_USE)) uses++;
      }
      if (assignments !== 1) problems.push(`${path}: register_routes() must assign $backend exactly once`);
      if (uses !== 1) problems.push(`${path}: register_routes() must pass $backend to exactly one EVC_Rest_Redeem_Controller`);
    }
  }
  if (path === rules.featureFile) {
    const b = bodies.is_enabled_value;
    if (!b || !matchAt(tokens, b.start, ["{", "return", "$value", "=", "=", "=", "self", "::", "VALUE", ";", "}"])) problems.push(`${path}: is_enabled_value() must be exactly return $value === self::VALUE;`);
    const at = tokens.findIndex((tk, k) => tk.t === "id" && tk.v.toLowerCase() === "const" && tokens[k + 1]?.v === "VALUE");
    if (at < 0 || tokens[at + 2]?.v !== "=" || tokens[at + 3]?.t !== "str" || tokens[at + 3].v !== "club_db_v1") problems.push(`${path}: const VALUE must be 'club_db_v1'`);
  }
  return problems;
}

const GOOD_STAFF_PLUGIN = `<?php
final class EVC_Staff_Plugin {
    public static function register_routes(): void {
        if (!EVC_Staff_Feature::enabled()) {
            return;
        }
        $backend = EVC_Club_Redemption_Feature::enabled() ? new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source()) : new EVC_Unavailable_Redemption_Backend();
        (new EVC_Rest_Redeem_Controller($backend))->register_routes();
    }
}`;
const GOOD_CATALOGUE = `<?php
interface EVC_Redemption_Catalogue_Source { public function catalogue(): ?EVC_Coffee_Catalog; }
final class EVC_Approved_Coffee_Catalogue_Source implements EVC_Redemption_Catalogue_Source {
    const ITEMS = array('espresso' => 'Espresso');
    public function catalogue(): ?EVC_Coffee_Catalog { return new EVC_Coffee_Catalog(self::ITEMS); }
}`;
const GOOD_FEATURE = `<?php
final class EVC_Club_Redemption_Feature {
    const CONSTANT = 'EVC_CLUB_REDEMPTION_SOURCE';
    const VALUE = 'club_db_v1';
    public static function is_enabled_value($value): bool {
        return $value === self::VALUE;
    }
}`;

function redemptionSelfTest() {
  const bad = [];
  const R = REDEMPTION_RULES;
  const expect = (label, path, src, want) => {
    const got = redemptionProblemsFor(path, src).length > 0;
    if (got !== want) bad.push(`redemption ${label}: expected ${want ? "a problem" : "no problem"}`);
  };
  expect("good staff plugin", R.plugin, GOOD_STAFF_PLUGIN, false);
  expect("good catalogue source", R.catalogueFile, GOOD_CATALOGUE, false);
  expect("good feature", R.featureFile, GOOD_FEATURE, false);
  expect("backend file may name itself", R.backendFile, "<?php\nfinal class EVC_Club_Redemption_Backend implements EVC_Redemption_Backend { public static function constants() {} }", false);
  expect("staff gate missing", R.plugin, GOOD_STAFF_PLUGIN.replace("if (!EVC_Staff_Feature::enabled()) {\n            return;\n        }", ""), true);
  expect("staff gate negation dropped", R.plugin, GOOD_STAFF_PLUGIN.replace("if (!EVC_Staff_Feature", "if (EVC_Staff_Feature"), true);
  expect("opt-in not checked", R.plugin, GOOD_STAFF_PLUGIN.replace("EVC_Club_Redemption_Feature::enabled() ? ", "true ? "), true);
  for (const [label, cond] of [
    ["true ||", "true || EVC_Club_Redemption_Feature::enabled()"],
    ["negated", "!EVC_Club_Redemption_Feature::enabled()"],
    ["false &&", "false && EVC_Club_Redemption_Feature::enabled()"],
    ["|| true", "EVC_Club_Redemption_Feature::enabled() || true"],
    ["parenthesised", "(EVC_Club_Redemption_Feature::enabled())"],
    ["parenthesised || true", "(EVC_Club_Redemption_Feature::enabled() || true)"],
    ["double negation", "!!EVC_Club_Redemption_Feature::enabled()"],
    ["compared", "EVC_Club_Redemption_Feature::enabled() == false"],
    ["or keyword", "1 or EVC_Club_Redemption_Feature::enabled()"],
  ]) {
    expect(`condition widened: ${label}`, R.plugin, GOOD_STAFF_PLUGIN.replace("EVC_Club_Redemption_Feature::enabled() ?", cond + " ?"), true);
  }
  expect("result replaced afterwards", R.plugin, GOOD_STAFF_PLUGIN.replace("(new EVC_Rest_Redeem_Controller($backend))", "$backend = new EVC_Unavailable_Redemption_Backend();\n        (new EVC_Rest_Redeem_Controller($backend))"), true);
  expect("result compound-assigned afterwards", R.plugin, GOOD_STAFF_PLUGIN.replace("(new EVC_Rest_Redeem_Controller($backend))", "$backend ??= null;\n        (new EVC_Rest_Redeem_Controller($backend))"), true);
  expect("another variable used", R.plugin, GOOD_STAFF_PLUGIN.replace("(new EVC_Rest_Redeem_Controller($backend))", "(new EVC_Rest_Redeem_Controller($other))"), true);
  expect("branches swapped", R.plugin, GOOD_STAFF_PLUGIN.replace("? new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source()) : new EVC_Unavailable_Redemption_Backend()", "? new EVC_Unavailable_Redemption_Backend() : new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source())"), true);
  expect("statement nested in an expression", R.plugin, GOOD_STAFF_PLUGIN.replace("$backend = EVC_Club_Redemption_Feature", "$x = 1 + $backend = EVC_Club_Redemption_Feature"), true);
  expect("opt-in of another feature", R.plugin, GOOD_STAFF_PLUGIN.replace("EVC_Club_Redemption_Feature::enabled()", "EVC_Club_Migrations_Feature::enabled()"), true);
  expect("default backend dropped", R.plugin, GOOD_STAFF_PLUGIN.replace(" : new EVC_Unavailable_Redemption_Backend()", " : null"), true);
  expect("test seam passed in shipped code", R.plugin, GOOD_STAFF_PLUGIN.replace("new EVC_Approved_Coffee_Catalogue_Source())", "new EVC_Approved_Coffee_Catalogue_Source(), $env)"), true);
  expect("another catalogue passed", R.plugin, GOOD_STAFF_PLUGIN.replace("new EVC_Approved_Coffee_Catalogue_Source()", "new EVC_Other_Catalogue()"), true);
  expect("backend constructed in boot()", R.plugin, GOOD_STAFF_PLUGIN.replace("final class EVC_Staff_Plugin {", "final class EVC_Staff_Plugin {\n    public static function boot() { $b = EVC_Club_Redemption_Feature::enabled() ? new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source()) : null; }"), true);
  expect("backend constructed twice", R.plugin, GOOD_STAFF_PLUGIN.replace("(new EVC_Rest_Redeem_Controller($backend))", "$x = EVC_Club_Redemption_Feature::enabled() ? new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source()) : 1;\n        (new EVC_Rest_Redeem_Controller($backend))"), true);
  expect("backend constructed elsewhere", "includes/x.php", "<?php\n$b = EVC_Club_Redemption_Feature::enabled() ? new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source()) : null;", true);
  expect("backend named in a string elsewhere", "includes/x.php", "<?php\n$c = 'EVC_Club_Redemption_Backend';", true);
  expect("backend with a leading backslash elsewhere", "includes/x.php", "<?php\n$b = new \\EVC_Club_Redemption_Backend($c);", true);
  expect("catalogue source constructed alone", R.plugin, GOOD_STAFF_PLUGIN.replace("(new EVC_Rest_Redeem_Controller($backend))", "$c = new EVC_Approved_Coffee_Catalogue_Source();\n        (new EVC_Rest_Redeem_Controller($backend))"), true);
  expect("coffee list built elsewhere", "includes/x.php", "<?php\n$c = new EVC_Coffee_Catalog(array('espresso' => 'E'));", true);
  expect("coffee list built outside catalogue()", R.catalogueFile, GOOD_CATALOGUE.replace("const ITEMS", "public static function other() { return new EVC_Coffee_Catalog(array()); }\n    const ITEMS"), true);
  expect("test catalogue implementation", "includes/x.php", "<?php\nfinal class EVC_Test_Coffee_Catalogue implements EVC_Redemption_Catalogue_Source { }", true);
  expect("catalogue implementation listed second", "includes/x.php", "<?php\nfinal class X implements Countable, \\EVC_Redemption_Catalogue_Source { }", true);
  expect("extra backend implementation", "includes/x.php", "<?php\nfinal class X implements EVC_Redemption_Backend { }", true);
  expect("unavailable backend file may implement it", R.defaultBackendFile, "<?php\nfinal class EVC_Unavailable_Redemption_Backend implements EVC_Redemption_Backend { }", false);
  expect("defines the opt-in constant", "includes/x.php", "<?php\ndefine('EVC_CLUB_REDEMPTION_SOURCE', 'club_db_v1');", true);
  expect("defines the provenance constant", R.plugin, GOOD_STAFF_PLUGIN.replace("final class", "define('EVC_CLUB_PAYMENT_PROVENANCE', 'club_db_v1');\nfinal class"), true);
  expect("parser not exact", R.featureFile, GOOD_FEATURE.replace("return $value === self::VALUE;", "return strtolower(trim($value)) === self::VALUE;"), true);
  expect("parser loose", R.featureFile, GOOD_FEATURE.replace("===", "=="), true);
  expect("value changed", R.featureFile, GOOD_FEATURE.replace("const VALUE = 'club_db_v1';", "const VALUE = 'CLUB_DB_V1';"), true);
  expect("comment mention is fine", "includes/x.php", "<?php\n// new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source())\n$x = 1;", false);
  return bad;
}

// ---- STAGE-05B rule set: Club member rows after a recorded payment confirmation ---------------------------------
// Lifts the earlier blanket bans on `new EVC_Member_Sync_Service`, `implements EVC_Member_Identity_Store` and
// `implements EVC_Wp_Account_Directory` for ONE approved site only:
// 1. EVC_Member_Sync_Service, EVC_Sql_Member_Identity_Store and EVC_Wp_Club_Account_Directory are constructed only in
//    the member-sync file, inside EVC_Club_Member_Sync::service() (defined exactly once), each exactly once.
// 2. Only the store file implements EVC_Member_Identity_Store and only the member-sync file implements
//    EVC_Wp_Account_Directory; no shipped interface extends either.
// 3. Their names appear only in the allowlisted files (identifiers and strings).
// EVC_Club_Member_Sync itself is a STAGE-03D guarded class (constructed only in EVC_Wc_Completion_Plugin::wire(),
// behind EVC_CLUB_WC_COMPLETION_ENABLED). The account-status service, the QR coordinator and the status / QR-delivery
// policy implementations stay banned (packager string bans), as do the QR / lookup / v2 constructions.
export const MEMBER_SYNC_RULES = Object.freeze({
  syncFile: "includes/customer/class-evc-club-member-sync.php",
  storeFile: "includes/class-evc-sql-member-identity-store.php",
  coreFile: "includes/class-evc-member-sync.php",
});

export function memberSyncProblemsFor(path, src, rules = MEMBER_SYNC_RULES) {
  const tokens = tokenize(src);
  const bodies = functionBodies(tokens);
  const problems = [];
  const names = [
    ["evc_member_sync_service", [rules.coreFile, rules.syncFile]],
    ["evc_sql_member_identity_store", [rules.storeFile, rules.syncFile]],
    ["evc_wp_club_account_directory", [rules.syncFile]],
  ];
  tokens.forEach((tk, k) => {
    if (tk.t === "id" || tk.t === "str") {
      const v = bare(tk.v).toLowerCase();
      for (const [name, files] of names) {
        const hit = tk.t === "id" ? v === name || v.endsWith("\\" + name) : v.includes(name);
        if (hit && !files.includes(path)) problems.push(`${path}: names ${name} (member-sync allowlist)`);
      }
    }
    if (tk.t === "id" && ["implements", "extends"].includes(tk.v.toLowerCase())) {
      for (let j = k + 1; j < tokens.length && tokens[j].v !== "{"; j++) {
        if (tokens[j].t !== "id") continue;
        const ext = tk.v.toLowerCase() === "extends";
        if (same(tokens[j].v, "EVC_Member_Identity_Store") && (ext || path !== rules.storeFile)) problems.push(`${path}: ${tk.v} EVC_Member_Identity_Store`);
        if (same(tokens[j].v, "EVC_Wp_Account_Directory") && (ext || path !== rules.syncFile)) problems.push(`${path}: ${tk.v} EVC_Wp_Account_Directory`);
      }
    }
  });
  const counts = {};
  for (const c of constructions(tokens)) {
    for (const cls of ["EVC_Member_Sync_Service", "EVC_Sql_Member_Identity_Store", "EVC_Wp_Club_Account_Directory"]) {
      if (!same(c.name, cls)) continue;
      counts[cls] = (counts[cls] ?? 0) + 1;
      if (!(path === rules.syncFile && inside(bodies, "service", c.at))) problems.push(`${path}: ${cls} constructed outside EVC_Club_Member_Sync::service()`);
    }
  }
  for (const [cls, n] of Object.entries(counts)) {
    if (n > 1) problems.push(`${path}: ${cls} constructed more than once`);
  }
  if (path === rules.syncFile && (bodies.__count.service ?? 0) !== 1) problems.push(`${path}: service() must be defined exactly once`);
  return problems;
}

const GOOD_MEMBER_SYNC = `<?php
final class EVC_Wp_Club_Account_Directory implements EVC_Wp_Account_Directory { public function account_kind(int $u): string { return self::MISSING; } }
final class EVC_Club_Member_Sync implements EVC_Clock_Source {
    private function service(EVC_Club_Db $db): EVC_Member_Sync_Service {
        return new EVC_Member_Sync_Service(new EVC_Sql_Member_Identity_Store($db), new EVC_Wp_Club_Account_Directory(), $a, $this, null, function (array $d) { return 1; });
    }
}`;

function memberSyncSelfTest() {
  const bad = [];
  const M = MEMBER_SYNC_RULES;
  const expect = (label, path, src, want) => {
    const got = memberSyncProblemsFor(path, src).length > 0;
    if (got !== want) bad.push(`member-sync ${label}: expected ${want ? "a problem" : "no problem"}`);
  };
  expect("good member-sync file", M.syncFile, GOOD_MEMBER_SYNC, false);
  expect("store file implements the store", M.storeFile, "<?php\nfinal class EVC_Sql_Member_Identity_Store implements EVC_Member_Identity_Store { }", false);
  expect("core file defines the service", M.coreFile, "<?php\nfinal class EVC_Member_Sync_Service { }\ninterface EVC_Member_Identity_Store { }", false);
  expect("service constructed elsewhere", "includes/x.php", "<?php\n$s = new EVC_Member_Sync_Service($a, $b, $c, $d);", true);
  expect("service constructed with a leading backslash", "includes/x.php", "<?php\n$s = new \\EVC_Member_Sync_Service($a, $b, $c, $d);", true);
  expect("service constructed in the core file", M.coreFile, "<?php\nfunction f() { return new EVC_Member_Sync_Service($a, $b, $c, $d); }", true);
  expect("service constructed outside service()", M.syncFile, GOOD_MEMBER_SYNC.replace("    private function service", "    public function other() { return new EVC_Member_Sync_Service($a, $b, $c, $d); }\n    private function service"), true);
  expect("service constructed twice", M.syncFile, GOOD_MEMBER_SYNC.replace("        return new EVC_Member_Sync_Service(", "        $x = new EVC_Member_Sync_Service($a, $b, $c, $d);\n        return new EVC_Member_Sync_Service("), true);
  expect("service() defined twice", M.syncFile, GOOD_MEMBER_SYNC.replace("final class EVC_Club_Member_Sync", "final class Y { private function service() { return 1; } }\nfinal class EVC_Club_Member_Sync"), true);
  expect("store constructed elsewhere", "includes/x.php", "<?php\n$s = new EVC_Sql_Member_Identity_Store($db);", true);
  expect("store constructed in its own file", M.storeFile, "<?php\nfinal class EVC_Sql_Member_Identity_Store implements EVC_Member_Identity_Store { public static function make($db) { return new EVC_Sql_Member_Identity_Store($db); } }", true);
  expect("directory constructed outside service()", M.syncFile, GOOD_MEMBER_SYNC.replace("    private function service", "    public function d() { return new EVC_Wp_Club_Account_Directory(); }\n    private function service"), true);
  expect("store named elsewhere", "includes/x.php", "<?php\nfunction f(EVC_Sql_Member_Identity_Store $s) { return $s; }", true);
  expect("store named in a string elsewhere", "includes/x.php", "<?php\n$c = 'EVC_Sql_Member_Identity_Store';", true);
  expect("service named in a string elsewhere", "includes/x.php", "<?php\n$c = 'EVC_Member_Sync_Service';", true);
  expect("another store implementation", "includes/x.php", "<?php\nfinal class X implements EVC_Member_Identity_Store { }", true);
  expect("store implementation listed second", "includes/x.php", "<?php\nfinal class X implements Countable, \\EVC_Member_Identity_Store { }", true);
  expect("store interface extended", "includes/x.php", "<?php\ninterface X extends EVC_Member_Identity_Store { }", true);
  expect("another account directory", "includes/x.php", "<?php\nfinal class X implements EVC_Wp_Account_Directory { }", true);
  expect("account directory in the store file", M.storeFile, "<?php\nfinal class X implements EVC_Wp_Account_Directory { }", true);
  expect("store implemented in the member-sync file", M.syncFile, GOOD_MEMBER_SYNC + "\nfinal class Z implements EVC_Member_Identity_Store { }", true);
  expect("comment mention is fine", "includes/x.php", "<?php\n// new EVC_Member_Sync_Service(new EVC_Sql_Member_Identity_Store($db))\n$x = 1;", false);
  return bad;
}

// ---- Round 9 rule set: prepared redemption (owner decision D24) ---------------------------------------------------
// Lifts the packager's blanket string bans on `new EVC_Prepared_Redemption_Service`, `implements EVC_Prep_Redemption_Store`
// and `implements EVC_Prep_Catalogue_Authority` for ONE approved site each (as STAGE-05B / 05C did for their pieces):
// 1. CONSTRUCTION: EVC_Prepared_Redemption_Service, EVC_Sql_Prep_Redemption_Store and EVC_Club_Prep_Catalogue_Authority
//    are constructed only in the backend file, inside EVC_Club_Prepared_Redemption_Backend::build() (defined exactly
//    once), each exactly once. The backend and the controller are constructed only in EVC_Staff_Plugin::register_routes()
//    (whose staff gate the redemption rules already require) as the exact standalone statement
//    `if (EVC_Club_Redemption_Feature::enabled()) { (new EVC_Rest_Prepared_Redeem_Controller(new EVC_Club_Prepared_Redemption_Backend()))->register_routes(); }`
//    (no test-seam arguments), exactly once.
// 2. IMPLEMENTS: EVC_Prep_Redemption_Store only by `class EVC_Sql_Prep_Redemption_Store` in the store file;
//    EVC_Prep_Catalogue_Authority only by `class EVC_Club_Prep_Catalogue_Authority` in the catalogue file; neither is
//    extended. EVC_Prep_Request_Lookup / the precheck stay banned (packager string bans), as do the test fakes.
// 3. NAMES: the SQL store, the backend and the controller are named (identifier or string) only in their allowlisted
//    files (the service's name stays free: the handoff contract reads its REQUEST_ID_PATTERN constant).
// 4. NO DYNAMIC CONSTRUCTION (`new $x`, `new (…)`, `new self|static|parent|class`, class_alias, Reflection*,
//    newInstance*, unserialize) in the store, catalogue, backend and controller files.
// Not covered statically (documented): a class name assembled at run time from pieces in a file outside those four.
export const PREPARED_RULES = Object.freeze({
  plugin: STAFF_PLUGIN,
  coreFile: "includes/class-evc-prepared-redemption.php",
  storeFile: "includes/class-evc-sql-prep-redemption-store.php",
  catalogueFile: "includes/class-evc-club-prep-catalogue.php",
  historyFile: "includes/class-evc-coffee-history.php",
  backendFile: "includes/staff/class-evc-club-prepared-redemption-backend.php",
  controllerFile: "includes/staff/class-evc-rest-prepared-redeem-controller.php",
});
const PR = PREPARED_RULES;
const PREPARED_SITES = [
  ["EVC_Prepared_Redemption_Service", PR.backendFile, "build"],
  ["EVC_Sql_Prep_Redemption_Store", PR.backendFile, "build"],
  ["EVC_Club_Prep_Catalogue_Authority", PR.backendFile, "build"],
  ["EVC_Club_Prepared_Redemption_Backend", PR.plugin, "register_routes"],
  ["EVC_Rest_Prepared_Redeem_Controller", PR.plugin, "register_routes"],
];
const PREPARED_NAMES = [
  ["evc_sql_prep_redemption_store", [PR.storeFile, PR.backendFile, PR.historyFile]],
  ["evc_club_prepared_redemption_backend", [PR.backendFile, PR.controllerFile, PR.plugin]],
  ["evc_rest_prepared_redeem_controller", [PR.controllerFile, PR.plugin]],
];
const PREPARED_IMPLEMENTS = [
  ["EVC_Prep_Redemption_Store", "EVC_Sql_Prep_Redemption_Store", PR.storeFile],
  ["EVC_Prep_Catalogue_Authority", "EVC_Club_Prep_Catalogue_Authority", PR.catalogueFile],
];
const PREPARED_FILES = [PR.storeFile, PR.catalogueFile, PR.backendFile, PR.controllerFile];
const PREPARED_STATEMENT = ["if", "(", "EVC_Club_Redemption_Feature", "::", "enabled", "(", ")", ")", "{", "(", "new", "EVC_Rest_Prepared_Redeem_Controller", "(", "new", "EVC_Club_Prepared_Redemption_Backend", "(", ")", ")", ")", "->", "register_routes", "(", ")", ";", "}"];

export function preparedProblemsFor(path, src) {
  const tokens = tokenize(src);
  const bodies = functionBodies(tokens);
  const problems = [];
  const guardedFile = PREPARED_FILES.includes(path);
  tokens.forEach((tk, k) => {
    if (tk.t === "id" || tk.t === "str") {
      const v = bare(tk.v).toLowerCase();
      for (const [name, files] of PREPARED_NAMES) {
        const hit = tk.t === "id" ? v === name || v.endsWith("\\" + name) : v.includes(name);
        if (hit && !files.includes(path)) problems.push(`${path}: names ${name} (prepared-redemption allowlist)`);
      }
    }
    if (tk.t === "id" && ["implements", "extends"].includes(tk.v.toLowerCase())) {
      for (let j = k + 1; j < tokens.length && tokens[j].v !== "{"; j++) {
        if (tokens[j].t !== "id") continue;
        for (const [iface, cls, file] of PREPARED_IMPLEMENTS) {
          if (!same(tokens[j].v, iface)) continue;
          if (tk.v.toLowerCase() === "extends") { problems.push(`${path}: extends ${iface}`); continue; }
          const d = declarationBefore(tokens, k);
          if (!(d.kind === "class" && !d.anonymous && path === file && same(d.name, cls))) problems.push(`${path}: ${d.anonymous ? "anonymous class" : d.kind + " " + d.name} implements ${iface}`);
        }
      }
    }
    if (guardedFile && tk.t === "id" && DANGEROUS.includes(tk.v.toLowerCase())) problems.push(`${path}: ${tk.v} in a prepared-redemption file`);
    if (guardedFile && tk.t === "id" && tk.v.toLowerCase() === "new") {
      const next = tokens[k + 1];
      if (!next || next.t !== "id" || ["self", "static", "parent", "class"].includes(next.v.toLowerCase())) problems.push(`${path}: dynamic or self construction (new ${next ? next.v : ""}) in a prepared-redemption file`);
    }
  });
  const counts = {};
  for (const c of constructions(tokens)) {
    for (const [cls, file, fn] of PREPARED_SITES) {
      if (!same(c.name, cls)) continue;
      counts[cls] = (counts[cls] ?? 0) + 1;
      if (!(path === file && inside(bodies, fn, c.at))) problems.push(`${path}: ${cls} constructed outside ${file} ${fn}()`);
    }
  }
  for (const [cls, n] of Object.entries(counts)) if (n > 1) problems.push(`${path}: ${cls} constructed more than once`);
  if (path === PR.backendFile && (bodies.__count.build ?? 0) !== 1) problems.push(`${path}: build() must be defined exactly once`);
  if (path === PR.plugin) {
    const b = bodies.register_routes;
    let found = 0;
    if (b) {
      for (let k = b.start; k < b.end; k++) if (matchAt(tokens, k, PREPARED_STATEMENT) && [";", "{", "}"].includes(tokens[k - 1]?.v)) found++;
    }
    if (found !== 1) problems.push(`${path}: register_routes() must hold exactly one \`if (EVC_Club_Redemption_Feature::enabled()) { (new EVC_Rest_Prepared_Redeem_Controller(new EVC_Club_Prepared_Redemption_Backend()))->register_routes(); }\``);
  }
  return problems;
}

const GOOD_PREPARED_PLUGIN = GOOD_STAFF_PLUGIN.replace("(new EVC_Rest_Redeem_Controller($backend))->register_routes();", "(new EVC_Rest_Redeem_Controller($backend))->register_routes();\n        if (EVC_Club_Redemption_Feature::enabled()) {\n            (new EVC_Rest_Prepared_Redeem_Controller(new EVC_Club_Prepared_Redemption_Backend()))->register_routes();\n        }");
const GOOD_PREPARED_BACKEND = `<?php
final class EVC_Club_Prepared_Redemption_Backend {
    private function build(): ?EVC_Prepared_Redemption_Service {
        return new EVC_Prepared_Redemption_Service(new EVC_Sql_Prep_Redemption_Store($db), new EVC_Club_Prep_Catalogue_Authority(), $m, $this->clock, null, function (array $d) { return 1; });
    }
}`;

function preparedSelfTest() {
  const bad = [];
  const expect = (label, path, src, want) => {
    const got = preparedProblemsFor(path, src).length > 0;
    if (got !== want) bad.push(`prepared ${label}: expected ${want ? "a problem" : "no problem"}`);
  };
  expect("good staff plugin", PR.plugin, GOOD_PREPARED_PLUGIN, false);
  expect("good backend", PR.backendFile, GOOD_PREPARED_BACKEND, false);
  expect("good store", PR.storeFile, "<?php\nfinal class EVC_Sql_Prep_Redemption_Store implements EVC_Prep_Redemption_Store { }", false);
  expect("good catalogue", PR.catalogueFile, "<?php\nfinal class EVC_Club_Prep_Catalogue_Authority implements EVC_Prep_Catalogue_Authority { }", false);
  expect("good controller", PR.controllerFile, "<?php\nfinal class EVC_Rest_Prepared_Redeem_Controller { public function __construct(EVC_Club_Prepared_Redemption_Backend $b) { } }", false);
  expect("history may read the store's static helper", PR.historyFile, "<?php\n$s = EVC_Sql_Prep_Redemption_Store::snapshot($row);", false);
  expect("registration missing", PR.plugin, GOOD_STAFF_PLUGIN, true);
  expect("registration without the opt-in", PR.plugin, GOOD_PREPARED_PLUGIN.replace("if (EVC_Club_Redemption_Feature::enabled()) {\n            (new EVC_Rest_Prepared", "if (true) {\n            (new EVC_Rest_Prepared"), true);
  expect("opt-in widened", PR.plugin, GOOD_PREPARED_PLUGIN.replace("if (EVC_Club_Redemption_Feature::enabled()) {\n            (new EVC_Rest_Prepared", "if (EVC_Club_Redemption_Feature::enabled() || true) {\n            (new EVC_Rest_Prepared"), true);
  expect("opt-in negated", PR.plugin, GOOD_PREPARED_PLUGIN.replace("if (EVC_Club_Redemption_Feature::enabled()) {\n            (new EVC_Rest_Prepared", "if (!EVC_Club_Redemption_Feature::enabled()) {\n            (new EVC_Rest_Prepared"), true);
  expect("test seam passed", PR.plugin, GOOD_PREPARED_PLUGIN.replace("new EVC_Club_Prepared_Redemption_Backend()", "new EVC_Club_Prepared_Redemption_Backend($env)"), true);
  expect("registered twice", PR.plugin, GOOD_PREPARED_PLUGIN.replace("    }\n}", "        if (EVC_Club_Redemption_Feature::enabled()) {\n            (new EVC_Rest_Prepared_Redeem_Controller(new EVC_Club_Prepared_Redemption_Backend()))->register_routes();\n        }\n    }\n}"), true);
  expect("backend constructed in boot()", PR.plugin, GOOD_PREPARED_PLUGIN.replace("final class EVC_Staff_Plugin {", "final class EVC_Staff_Plugin {\n    public static function boot() { $b = new EVC_Club_Prepared_Redemption_Backend(); }"), true);
  expect("backend constructed elsewhere", "includes/x.php", "<?php\n$b = new EVC_Club_Prepared_Redemption_Backend();", true);
  expect("backend named in a string elsewhere", "includes/x.php", "<?php\n$c = 'EVC_Club_Prepared_Redemption_Backend';", true);
  expect("service constructed elsewhere", "includes/x.php", "<?php\n$s = new \\EVC_Prepared_Redemption_Service($a, $b, $c, $d);", true);
  expect("service constructed outside build()", PR.backendFile, GOOD_PREPARED_BACKEND.replace("    private function build()", "    public function other() { return new EVC_Prepared_Redemption_Service($a, $b, $c, $d); }\n    private function build()"), true);
  expect("service constructed twice", PR.backendFile, GOOD_PREPARED_BACKEND.replace("        return new EVC_Prepared_Redemption_Service(", "        $x = new EVC_Prepared_Redemption_Service($a, $b, $c, $d);\n        return new EVC_Prepared_Redemption_Service("), true);
  expect("build() defined twice", PR.backendFile, GOOD_PREPARED_BACKEND.replace("final class EVC_Club_Prepared_Redemption_Backend {", "final class Y { private function build() { return 1; } }\nfinal class EVC_Club_Prepared_Redemption_Backend {"), true);
  expect("store constructed elsewhere", "includes/x.php", "<?php\n$s = new EVC_Sql_Prep_Redemption_Store($db);", true);
  expect("store constructed in its own file", PR.storeFile, "<?php\nfinal class EVC_Sql_Prep_Redemption_Store implements EVC_Prep_Redemption_Store { public static function make($db) { return new EVC_Sql_Prep_Redemption_Store($db); } }", true);
  expect("store named elsewhere", "includes/x.php", "<?php\nfunction f(EVC_Sql_Prep_Redemption_Store $s) { return $s; }", true);
  expect("catalogue authority constructed elsewhere", "includes/x.php", "<?php\n$a = new EVC_Club_Prep_Catalogue_Authority();", true);
  expect("another store implementation", "includes/x.php", "<?php\nfinal class X implements EVC_Prep_Redemption_Store { }", true);
  expect("store implementation listed second", "includes/x.php", "<?php\nfinal class X implements Countable, \\EVC_Prep_Redemption_Store { }", true);
  expect("anonymous store implementation", PR.storeFile, "<?php\n$x = new class() implements EVC_Prep_Redemption_Store { };", true);
  expect("store interface extended", "includes/x.php", "<?php\ninterface X extends EVC_Prep_Redemption_Store { }", true);
  expect("another catalogue authority", "includes/x.php", "<?php\nfinal class X implements EVC_Prep_Catalogue_Authority { }", true);
  expect("catalogue authority in the store file", PR.storeFile, "<?php\nfinal class EVC_Sql_Prep_Redemption_Store implements EVC_Prep_Catalogue_Authority { }", true);
  expect("new $x in the backend", PR.backendFile, GOOD_PREPARED_BACKEND + "\n$o = new $cls();", true);
  expect("new self in the store", PR.storeFile, "<?php\nfinal class EVC_Sql_Prep_Redemption_Store implements EVC_Prep_Redemption_Store { public static function make() { return new self(); } }", true);
  expect("reflection in the controller", PR.controllerFile, "<?php\n$r = new ReflectionClass($c);", true);
  expect("comment mention is fine", "includes/x.php", "<?php\n// new EVC_Prepared_Redemption_Service(new EVC_Sql_Prep_Redemption_Store($db))\n$x = 1;", false);
  return bad;
}

// ---- STAGE-05C rule set: Club member QR (token store, customer QR, staff lookup) -------------------------------
// Lifts the packager's blanket string bans on `new EVC_Qr_Token_Service`, `new EVC_Member_Lookup_Service`,
// `new EVC_Lookup_Key_Ring`, `implements EVC_Qr_Token_Store`, `implements EVC_Member_Phone_Directory`,
// `implements EVC_Lookup_Rate_Limiter` and `implements EVC_Club_Staff_Authorizer` at ONE site each:
// 1. CONSTRUCTION: every QR piece is constructed only in includes/class-evc-club-qr-services.php, inside its
//    named method (store / authorizer / token_service / lookup_service), at most once per method; the two
//    backends only in their plugin's register_routes(), as the exact standalone statement
//    `if (<gate>) { (new <Controller>(new <Backend>()))->register_routes(); }` (no test seam arguments), after
//    the staff gate for the staff plugin. The gates are EVC_Club_Redemption_Feature::enabled() (staff) and
//    EVC_Customer_Qr_Backend::provenance_recorded() (customer; its body must be exactly the provenance check).
// 2. IMPLEMENTS: each interface is implemented only by its one class in its one file; the implementing
//    declaration must be `class <ThatClass>` (not an enum / trait / interface / anonymous class); none of the
//    interfaces is extended.
// 3. NAMES: each piece's name (identifier or inside a string) appears only in its allowlisted files, and no
//    shipped string outside them contains a piece of a QR class name (e.g. 'Qr_Token_Store'), so
//    `new $cls` built from such a string is caught.
// 4. NO DYNAMIC CONSTRUCTION in the QR files: `new $x`, `new (...)`, `new self|static|parent`, class_alias,
//    Reflection*, newInstance*, unserialize are refused there; `new $x` / `new (...)` / `new self|static|parent`
//    also in the staff and customer plugin files (review: `EVC_Customer_Qr_Backend::class` + `new $c`).
//    EVC_Qr_Token_Service / EVC_Member_Lookup_Service / EVC_Lookup_Key_Ring are named only in the UI-04C file and
//    the services file.
// Not covered statically (documented): a class name assembled at run time from pieces that are not QR name
// fragments, in a file outside the QR files. The bans on `new EVC_Signed_Member_Context_Verifier` and every
// other v2 construction are unchanged.
export const QR_RULES = Object.freeze({
  storeFile: "includes/class-evc-sql-qr-token-store.php",
  limiterFile: "includes/class-evc-sql-lookup-rate-limiter.php",
  servicesFile: "includes/class-evc-club-qr-services.php",
  lookupInterfaceFile: "includes/class-evc-member-lookup.php",
  staffPlugin: "includes/staff/class-evc-staff-plugin.php",
  lookupBackend: "includes/staff/class-evc-club-lookup-backend.php",
  lookupController: "includes/staff/class-evc-rest-lookup-controller.php",
  customerPlugin: "includes/customer/class-evc-customer-plugin.php",
  customerBackend: "includes/customer/class-evc-customer-qr-backend.php",
  customerController: "includes/customer/class-evc-rest-me-qr-controller.php",
  // STAGE-05D: GET /session asks EVC_Rest_Lookup_Controller::lookup_ready() for features.qr_lookup.
  sessionController: "includes/staff/class-evc-rest-session-controller.php",
  // STAGE-08 round 7, owner decision D22: the stable member token is derived here (EVC_Qr_Token::derive).
  tokenFile: "includes/class-evc-qr-token.php",
});

const R5C = QR_RULES;
// class => [file, method] of its ONE construction site
const QR_SITES = [
  ["EVC_Sql_Qr_Token_Store", R5C.servicesFile, "store"],
  ["EVC_Wp_Club_Staff_Authorizer", R5C.servicesFile, "authorizer"],
  ["EVC_Qr_Token_Service", R5C.servicesFile, "token_service"],
  ["EVC_First_Issue_Only_Qr_Store", R5C.servicesFile, "token_service"],
  ["EVC_Member_Lookup_Service", R5C.servicesFile, "lookup_service"],
  ["EVC_Sql_Lookup_Rate_Limiter", R5C.servicesFile, "lookup_service"],
  ["EVC_Lookup_Key_Ring", R5C.servicesFile, "lookup_service"],
  ["EVC_Lookup_Context_Policy", R5C.servicesFile, "lookup_service"],
  ["EVC_No_Member_Phone_Directory", R5C.servicesFile, "lookup_service"],
  ["EVC_Club_Lookup_Backend", R5C.staffPlugin, "register_routes"],
  ["EVC_Customer_Qr_Backend", R5C.customerPlugin, "register_routes"],
];
// lower-case name => files allowed to name it
const QR_NAMES = [
  ["evc_sql_qr_token_store", [R5C.storeFile, R5C.servicesFile]],
  ["evc_sql_lookup_rate_limiter", [R5C.limiterFile, R5C.servicesFile]],
  // STAGE-08 round 2: the read-only readiness checks reuse EVC_Club_Qr_Services::is_lookup_secret() (the lookup
  // backend's own >= 32-byte rule); adminToolsProblemsFor() allows ONLY that static call there.
  ["evc_club_qr_services", [R5C.servicesFile, R5C.lookupBackend, R5C.customerBackend, "includes/admin/class-evc-readiness.php"]],
  ["evc_wp_club_staff_authorizer", [R5C.servicesFile]],
  ["evc_no_member_phone_directory", [R5C.servicesFile]],
  ["evc_first_issue_only_qr_store", [R5C.servicesFile]],
  ["evc_club_lookup_backend", [R5C.lookupBackend, R5C.lookupController, R5C.staffPlugin]],
  ["evc_rest_lookup_controller", [R5C.lookupController, R5C.staffPlugin, R5C.sessionController]],
  ["evc_customer_qr_backend", [R5C.customerBackend, R5C.customerController, R5C.customerPlugin]],
  ["evc_rest_me_qr_controller", [R5C.customerController, R5C.customerPlugin]],
  // STAGE-05C review (MINOR 3): the reviewed UI-04C service / key classes too, so `X::class` + `new $c` cannot
  // reach them from any other shipped file.
  ["evc_qr_token_service", [R5C.lookupInterfaceFile, R5C.servicesFile]],
  ["evc_member_lookup_service", [R5C.lookupInterfaceFile, R5C.servicesFile]],
  ["evc_lookup_key_ring", [R5C.lookupInterfaceFile, R5C.servicesFile]],
];
// interface => [class, file]
const QR_IMPLEMENTS = [
  ["EVC_Qr_Token_Store", [["EVC_Sql_Qr_Token_Store", R5C.storeFile], ["EVC_First_Issue_Only_Qr_Store", R5C.servicesFile]]],
  ["EVC_Lookup_Rate_Limiter", [["EVC_Sql_Lookup_Rate_Limiter", R5C.limiterFile]]],
  ["EVC_Member_Phone_Directory", [["EVC_No_Member_Phone_Directory", R5C.servicesFile]]],
  ["EVC_Club_Staff_Authorizer", [["EVC_Wp_Club_Staff_Authorizer", R5C.servicesFile]]],
];
// Fragments of QR class names that no shipped STRING may contain outside the QR files.
const QR_FRAGMENTS = ["qr_token_store", "qr_token_service", "member_lookup_service", "lookup_rate_limiter", "lookup_key_ring", "club_qr_services", "club_lookup_backend", "customer_qr_backend", "staff_authorizer", "phone_directory"];
const QR_FILES = [R5C.storeFile, R5C.limiterFile, R5C.servicesFile, R5C.lookupBackend, R5C.lookupController, R5C.customerBackend, R5C.customerController];
// STAGE-05C review (MINOR 3): the two wiring plugins may name their backend, so they may not construct dynamically either.
const QR_DYNAMIC_NEW_FILES = [...QR_FILES, R5C.staffPlugin, R5C.customerPlugin];
const QR_GATES = {
  [R5C.staffPlugin]: { gate: ["EVC_Club_Redemption_Feature", "::", "enabled", "(", ")"], controller: "EVC_Rest_Lookup_Controller", backend: "EVC_Club_Lookup_Backend" },
  [R5C.customerPlugin]: { gate: ["EVC_Customer_Qr_Backend", "::", "provenance_recorded", "(", ")"], controller: "EVC_Rest_Me_Qr_Controller", backend: "EVC_Customer_Qr_Backend" },
};
const PROVENANCE_GATE_BODY = ["{", "return", "defined", "(", "'EVC_CLUB_PAYMENT_PROVENANCE'", ")", "&", "&", "constant", "(", "'EVC_CLUB_PAYMENT_PROVENANCE'", ")", "=", "=", "=", "self", "::", "PROVENANCE", ";", "}"];

/** Nearest declaration keyword before token k: {kind, name, anonymous}. */
function declarationBefore(tokens, k) {
  for (let c = k - 1; c >= 0; c--) {
    const tk = tokens[c];
    if (tk.t !== "id") continue;
    const v = tk.v.toLowerCase();
    if (["class", "enum", "interface", "trait"].includes(v)) {
      const prev = tokens[c - 1];
      const anonymous = v === "class" && prev?.t === "id" && prev.v.toLowerCase() === "new";
      const next = tokens[c + 1];
      return { kind: v, name: next?.t === "id" ? next.v : "", anonymous };
    }
  }
  return { kind: "", name: "", anonymous: false };
}

export function qrProblemsFor(path, src) {
  const tokens = tokenize(src);
  const bodies = functionBodies(tokens);
  const problems = [];
  const qrFile = QR_FILES.includes(path);
  tokens.forEach((tk, k) => {
    if (tk.t === "id" || tk.t === "str") {
      const v = bare(tk.v).toLowerCase();
      for (const [name, files] of QR_NAMES) {
        const hit = tk.t === "id" ? v === name || v.endsWith("\\" + name) : v.includes(name);
        if (hit && !files.includes(path)) problems.push(`${path}: names ${name} (QR allowlist)`);
      }
      if (tk.t === "str" && !qrFile && path !== R5C.lookupInterfaceFile) {
        for (const f of QR_FRAGMENTS) if (v.includes(f)) problems.push(`${path}: a string contains the QR name fragment '${f}'`);
      }
    }
    if (tk.t === "id" && ["implements", "extends"].includes(tk.v.toLowerCase())) {
      for (let j = k + 1; j < tokens.length && tokens[j].v !== "{"; j++) {
        if (tokens[j].t !== "id") continue;
        for (const [iface, owners] of QR_IMPLEMENTS) {
          if (!same(tokens[j].v, iface)) continue;
          if (tk.v.toLowerCase() === "extends") { problems.push(`${path}: extends ${iface}`); continue; }
          const d = declarationBefore(tokens, k);
          const ok = d.kind === "class" && !d.anonymous && owners.some(([cls, file]) => file === path && same(d.name, cls));
          if (!ok) problems.push(`${path}: ${d.anonymous ? "anonymous class" : d.kind + " " + d.name} implements ${iface}`);
        }
      }
    }
    if (qrFile && tk.t === "id" && DANGEROUS.includes(tk.v.toLowerCase())) problems.push(`${path}: ${tk.v} in a QR file`);
  });
  const counts = {};
  tokens.forEach((tk, k) => {
    if (!(tk.t === "id" && tk.v.toLowerCase() === "new")) return;
    const next = tokens[k + 1];
    if (QR_DYNAMIC_NEW_FILES.includes(path) && (!next || next.t !== "id" || ["self", "static", "parent", "class"].includes(next.v.toLowerCase()))) {
      problems.push(`${path}: dynamic or self construction (new ${next ? next.v : ""}) in a QR file`);
    }
  });
  for (const c of constructions(tokens)) {
    for (const [cls, file, fn] of QR_SITES) {
      if (!same(c.name, cls)) continue;
      const key = `${cls}@${fn}`;
      counts[key] = (counts[key] ?? 0) + 1;
      if (!(path === file && inside(bodies, fn, c.at))) problems.push(`${path}: ${cls} constructed outside ${file} ${fn}()`);
    }
  }
  for (const [key, n] of Object.entries(counts)) if (n > 1) problems.push(`${path}: ${key} constructed more than once`);
  if (path === R5C.servicesFile) {
    for (const fn of ["store", "authorizer", "token_service", "lookup_service"]) {
      if ((bodies.__count[fn] ?? 0) !== 1) problems.push(`${path}: ${fn}() must be defined exactly once`);
    }
  }
  const g = QR_GATES[path];
  if (g) {
    const b = bodies.register_routes;
    if ((bodies.__count.register_routes ?? 0) !== 1 || !b) problems.push(`${path}: register_routes() must be defined exactly once`);
    else {
      const stmt = ["if", "(", ...g.gate, ")", "{", "(", "new", g.controller, "(", "new", g.backend, "(", ")", ")", ")", "->", "register_routes", "(", ")", ";", "}"];
      let found = 0;
      for (let k = b.start; k < b.end; k++) {
        if (matchAt(tokens, k, stmt) && [";", "{", "}"].includes(tokens[k - 1]?.v)) found++;
      }
      if (found !== 1) problems.push(`${path}: register_routes() must hold exactly one \`if (${g.gate.join("")}) { (new ${g.controller}(new ${g.backend}()))->register_routes(); }\``);
      if (path === R5C.staffPlugin && !matchAt(tokens, b.start, STAFF_GATE)) problems.push(`${path}: register_routes() does not start with the staff gate`);
    }
  }
  if (path === R5C.customerBackend) {
    const b = bodies.provenance_recorded;
    if ((bodies.__count.provenance_recorded ?? 0) !== 1 || !b || !PROVENANCE_GATE_BODY.every((v, i) => {
      const tk = tokens[b.start + i];
      return tk && (tk.t === "str" ? `'${tk.v}'` === v : same(tk.v, v) || tk.v === v);
    })) problems.push(`${path}: provenance_recorded() must be exactly the EVC_CLUB_PAYMENT_PROVENANCE === self::PROVENANCE check`);
    const at = tokens.findIndex((tk, k) => tk.t === "id" && tk.v.toLowerCase() === "const" && tokens[k + 1]?.v === "PROVENANCE");
    if (at < 0 || tokens[at + 3]?.t !== "str" || tokens[at + 3].v !== "club_db_v1") problems.push(`${path}: const PROVENANCE must be 'club_db_v1'`);
  }
  return problems.concat(qrSecretProblemsFor(path, tokens));
}

// STAGE-08 round 7, owner decision D22: the member-QR derivation secret and the derived token.
//  1. No shipped file defines a Club secret (define('EVC_CLUB_QR_SECRET', ...) or a class constant of that name):
//     the secrets live only in each site's wp-config.php.
//  2. The token file may not call any output / logging / storage function at all.
//  3. The customer backend may not pass the secret or a token to one: no logging-type call, and no dynamic call
//     (the injected `($this->log)(...)` / `$log(...)` callables), whose arguments name a secret or a token.
const CLUB_SECRET_CONSTANTS = ["evc_club_qr_secret", "evc_club_lookup_key", "evc_club_evidence_key"];
const SECRET_SINKS = ["error_log", "print_r", "var_export", "var_dump", "debug_zval_dump", "printf", "vprintf", "trigger_error", "user_error", "syslog",
  "file_put_contents", "fwrite", "fputs", "wp_mail", "header", "setcookie", "set_transient", "set_site_transient", "update_option", "add_option",
  "update_site_option", "update_user_meta", "add_user_meta", "wp_cache_set", "wp_cache_add", "do_action", "apply_filters", "call_user_func",
  "call_user_func_array", "json_encode", "wp_json_encode", "serialize", "echo", "print"];
function carriesSecret(args) {
  return args.some((a, i) => {
    if (a.t === "var" && /secret|token/i.test(a.v)) return true;
    if (a.t === "id" && /secret|token/i.test(a.v) && args[i - 1]?.v === "->") return true;
    return a.t === "str" && /qr_secret|evc_club_qr_secret/i.test(a.v);
  });
}
export function qrSecretProblemsFor(path, tokens) {
  const problems = [];
  tokens.forEach((tk, k) => {
    if (tk.t === "id" && tk.v.toLowerCase() === "define" && tokens[k + 1]?.v === "(" && tokens[k + 2]?.t === "str" && CLUB_SECRET_CONSTANTS.includes(tokens[k + 2].v.toLowerCase())) {
      problems.push(`${path}: defines the Club secret ${tokens[k + 2].v} (secrets belong in wp-config.php only)`);
    }
    if (tk.t === "id" && tk.v.toLowerCase() === "const" && tokens[k + 1]?.t === "id" && CLUB_SECRET_CONSTANTS.includes(tokens[k + 1].v.toLowerCase())) {
      problems.push(`${path}: declares a constant named like the Club secret ${tokens[k + 1].v}`);
    }
  });
  if (path !== R5C.tokenFile && path !== R5C.customerBackend) return problems;
  tokens.forEach((tk, k) => {
    const member = ["->", "::"].includes(tokens[k - 1]?.v);
    if (tk.t === "id" && !member && SECRET_SINKS.includes(bare(tk.v).toLowerCase())) {
      const args = tokens[k + 1]?.v === "(" ? argumentTokens(tokens, k + 1) : tokens.slice(k + 1, tokens.findIndex((x, j) => j > k && x.v === ";"));
      if (path === R5C.tokenFile) problems.push(`${path}: ${tk.v} in the QR token file (D22: the token and the secret are never output)`);
      else if (carriesSecret(args)) problems.push(`${path}: ${tk.v}() with the QR secret or a token`);
    }
    if (tk.v === "(" && tk.t === "op" && (tokens[k - 1]?.v === ")" || tokens[k - 1]?.t === "var") && carriesSecret(argumentTokens(tokens, k))) {
      problems.push(`${path}: a dynamic call with the QR secret or a token (logging callables never receive them)`);
    }
  });
  return problems;
}

const GOOD_QR_STORE = "<?php\nfinal class EVC_Sql_Qr_Token_Store implements EVC_Qr_Token_Store { public function __construct(EVC_Club_Db $db) { $this->db = $db; } }";
const GOOD_QR_SERVICES = `<?php
final class EVC_Club_Qr_Services {
    public static function store($db) { return new EVC_Sql_Qr_Token_Store($db); }
    public static function authorizer() { return new EVC_Wp_Club_Staff_Authorizer(); }
    public static function token_service($db, $c, $r, $f) { $s = self::store($db); return new EVC_Qr_Token_Service($f ? new EVC_First_Issue_Only_Qr_Store($s) : $s, self::authorizer(), $c, null, $r); }
    public static function lookup_service($db, $k, $c, $r) { return new EVC_Member_Lookup_Service(self::store($db), new EVC_No_Member_Phone_Directory(), self::authorizer(), new EVC_Sql_Lookup_Rate_Limiter($db, $c), new EVC_Lookup_Key_Ring(array('k1' => $k), 'k1'), new EVC_Lookup_Context_Policy(300), $p, $c, 'member_lookup', null, $r); }
}
final class EVC_Wp_Club_Staff_Authorizer implements EVC_Club_Staff_Authorizer { }
final class EVC_No_Member_Phone_Directory implements EVC_Member_Phone_Directory { }
final class EVC_First_Issue_Only_Qr_Store implements EVC_Qr_Token_Store { }`;
const GOOD_QR_STAFF_PLUGIN = GOOD_STAFF_PLUGIN.replace("(new EVC_Rest_Redeem_Controller($backend))->register_routes();", "(new EVC_Rest_Redeem_Controller($backend))->register_routes();\n        if (EVC_Club_Redemption_Feature::enabled()) {\n            (new EVC_Rest_Lookup_Controller(new EVC_Club_Lookup_Backend()))->register_routes();\n        }");
const GOOD_QR_CUSTOMER_PLUGIN = `<?php
final class EVC_Customer_Plugin {
    public static function register_routes(): void {
        if (!EVC_Customer_Feature::enabled()) {
            return;
        }
        (new EVC_Rest_Me_Controller(self::membership_adapter(), new EVC_System_Clock()))->register_routes();
        if (EVC_Customer_Qr_Backend::provenance_recorded()) {
            (new EVC_Rest_Me_Qr_Controller(new EVC_Customer_Qr_Backend()))->register_routes();
        }
    }
}`;
const GOOD_QR_CUSTOMER_BACKEND = `<?php
final class EVC_Customer_Qr_Backend {
    const PROVENANCE = 'club_db_v1';
    public static function provenance_recorded(): bool {
        return defined('EVC_CLUB_PAYMENT_PROVENANCE') && constant('EVC_CLUB_PAYMENT_PROVENANCE') === self::PROVENANCE;
    }
    public function issue($u) { return EVC_Club_Qr_Services::token_service($db, $c, $r, true); }
}`;

function qrSelfTest() {
  const bad = [];
  const expect = (label, path, src, want) => {
    const got = qrProblemsFor(path, src).length > 0;
    if (got !== want) bad.push(`qr ${label}: expected ${want ? "a problem" : "no problem"}`);
  };
  const Q = QR_RULES;
  expect("good store file", Q.storeFile, GOOD_QR_STORE, false);
  expect("good services file", Q.servicesFile, GOOD_QR_SERVICES, false);
  expect("good staff plugin", Q.staffPlugin, GOOD_QR_STAFF_PLUGIN, false);
  expect("good customer plugin", Q.customerPlugin, GOOD_QR_CUSTOMER_PLUGIN, false);
  expect("good customer backend", Q.customerBackend, GOOD_QR_CUSTOMER_BACKEND, false);
  expect("interface definitions are fine", Q.lookupInterfaceFile, "<?php\ninterface EVC_Qr_Token_Store { }\nfinal class S { public function __construct(EVC_Qr_Token_Store $t) { } }\n$m = 'EVC_Qr_Token_Store';", false);
  expect("comment mention is fine", "includes/x.php", "<?php\n// new EVC_Sql_Qr_Token_Store($db)\n$x = 1;", false);
  // construction outside the one site
  expect("store constructed elsewhere", "includes/x.php", "<?php\n$s = new EVC_Sql_Qr_Token_Store($db);", true);
  expect("store constructed with a leading backslash", "includes/x.php", "<?php\n$s = new \\EVC_Sql_Qr_Token_Store($db);", true);
  expect("store constructed upper-case over two lines", "includes/x.php", "<?php\n$s = NEW\n  EVC_SQL_QR_TOKEN_STORE($db);", true);
  expect("store constructed in its own file", Q.storeFile, GOOD_QR_STORE.replace("{ public function", "{ public static function make($db) { return new EVC_Sql_Qr_Token_Store($db); } public function"), true);
  expect("store constructed outside store()", Q.servicesFile, GOOD_QR_SERVICES.replace("public static function authorizer() {", "public static function authorizer() { $x = new EVC_Sql_Qr_Token_Store($db);"), true);
  expect("store constructed twice in store()", Q.servicesFile, GOOD_QR_SERVICES.replace("{ return new EVC_Sql_Qr_Token_Store($db); }", "{ $a = new EVC_Sql_Qr_Token_Store($db); return new EVC_Sql_Qr_Token_Store($db); }"), true);
  expect("token service constructed in a backend", Q.customerBackend, GOOD_QR_CUSTOMER_BACKEND.replace("return EVC_Club_Qr_Services::token_service($db, $c, $r, true);", "return new EVC_Qr_Token_Service($s, $a, $c);"), true);
  expect("lookup service constructed elsewhere", "includes/x.php", "<?php\n$s = new EVC_Member_Lookup_Service($a, $b, $c, $d, $e, $f, $g, $h, 'x');", true);
  expect("lookup keys constructed elsewhere", "includes/x.php", "<?php\n$k = new EVC_Lookup_Key_Ring(array('k1' => $s), 'k1');", true);
  expect("store() defined twice", Q.servicesFile, GOOD_QR_SERVICES.replace("final class EVC_Club_Qr_Services {", "final class Y { public static function store() { return 1; } }\nfinal class EVC_Club_Qr_Services {"), true);
  // dynamic construction in QR files
  expect("new self in the store file", Q.storeFile, GOOD_QR_STORE.replace("{ public function", "{ public static function make($db) { return new self($db); } public function"), true);
  expect("new static in the store file", Q.storeFile, GOOD_QR_STORE.replace("{ public function", "{ public static function make($db) { return new static($db); } public function"), true);
  expect("new parent in the store file", Q.storeFile, GOOD_QR_STORE.replace("{ public function", "{ public static function make($db) { return new parent($db); } public function"), true);
  expect("new $c from self::class in the store file", Q.storeFile, GOOD_QR_STORE.replace("{ public function", "{ public static function make($db) { $c = self::class; return new $c($db); } public function"), true);
  expect("new (expr) in the services file", Q.servicesFile, GOOD_QR_SERVICES.replace("public static function authorizer() {", "public static function authorizer() { $x = new ('A' . 'B')();"), true);
  expect("ReflectionClass in the store file", Q.storeFile, GOOD_QR_STORE.replace("{ public function", "{ public static function make($db) { return (new ReflectionClass(self::class))->newInstance($db); } public function"), true);
  expect("class_alias in a backend", Q.lookupBackend, "<?php\nclass_alias('EVC_Club_Lookup_Backend', 'B');", true);
  // names and fragments elsewhere
  expect("store named elsewhere (type hint)", "includes/x.php", "<?php\nfunction f(EVC_Sql_Qr_Token_Store $s) { return $s; }", true);
  expect("store named in a string elsewhere", "includes/x.php", "<?php\n$c = 'EVC_Sql_Qr_Token_Store'; $s = new $c($db);", true);
  expect("name fragment in a string elsewhere", "includes/x.php", "<?php\n$c = 'EVC_Sql_' . 'Qr_Token_Store'; $s = new $c($db);", true);
  expect("service fragment in a string elsewhere", "includes/x.php", "<?php\n$c = 'EVC_' . 'Member_Lookup_Service';", true);
  expect("class_alias elsewhere", "includes/x.php", "<?php\nclass_alias('EVC_Sql_Qr_Token_Store', 'S');", true);
  expect("services named outside the backends", "includes/x.php", "<?php\n$s = EVC_Club_Qr_Services::store($db);", true);
  // implements
  expect("another store implementation", "includes/x.php", "<?php\nfinal class X implements EVC_Qr_Token_Store { }", true);
  expect("store implementation listed second", "includes/x.php", "<?php\nfinal class X implements Countable, \\EVC_Qr_Token_Store { }", true);
  expect("store interface extended", "includes/x.php", "<?php\ninterface X extends EVC_Qr_Token_Store { }", true);
  expect("second implementation in the store file", Q.storeFile, GOOD_QR_STORE + "\nfinal class Z implements EVC_Qr_Token_Store { }", true);
  expect("anonymous implementation in the store file", Q.storeFile, GOOD_QR_STORE + "\n$x = new class implements EVC_Qr_Token_Store { };", true);
  expect("enum implementation in the store file", Q.storeFile, GOOD_QR_STORE + "\nenum E implements EVC_Qr_Token_Store { case A; }", true);
  expect("enum named like the store", Q.storeFile, "<?php\nenum EVC_Sql_Qr_Token_Store implements EVC_Qr_Token_Store { case A; }", true);
  expect("interface extending the store interface in the store file", Q.storeFile, GOOD_QR_STORE + "\ninterface I extends EVC_Qr_Token_Store { }", true);
  expect("trait in the store file", Q.storeFile, GOOD_QR_STORE + "\ntrait T { }\nfinal class W implements EVC_Qr_Token_Store { use T; }", true);
  expect("another rate limiter", "includes/x.php", "<?php\nfinal class X implements EVC_Lookup_Rate_Limiter { }", true);
  expect("another phone directory", Q.servicesFile, GOOD_QR_SERVICES + "\nfinal class P implements EVC_Member_Phone_Directory { }", true);
  expect("another staff authorizer", "includes/x.php", "<?php\nfinal class X implements EVC_Club_Staff_Authorizer { }", true);
  // wiring
  const SP = Q.staffPlugin;
  expect("staff lookup gate dropped", SP, GOOD_QR_STAFF_PLUGIN.replace("if (EVC_Club_Redemption_Feature::enabled()) {\n            (new EVC_Rest_Lookup_Controller(new EVC_Club_Lookup_Backend()))->register_routes();\n        }", "(new EVC_Rest_Lookup_Controller(new EVC_Club_Lookup_Backend()))->register_routes();"), true);
  expect("staff lookup gate widened", SP, GOOD_QR_STAFF_PLUGIN.replace("if (EVC_Club_Redemption_Feature::enabled()) {\n            (new EVC_Rest_Lookup", "if (EVC_Club_Redemption_Feature::enabled() || true) {\n            (new EVC_Rest_Lookup"), true);
  expect("staff lookup gate negated", SP, GOOD_QR_STAFF_PLUGIN.replace("if (EVC_Club_Redemption_Feature::enabled()) {\n            (new EVC_Rest_Lookup", "if (!EVC_Club_Redemption_Feature::enabled()) {\n            (new EVC_Rest_Lookup"), true);
  expect("staff lookup backend with a test seam", SP, GOOD_QR_STAFF_PLUGIN.replace("new EVC_Club_Lookup_Backend()", "new EVC_Club_Lookup_Backend($env)"), true);
  expect("staff lookup backend constructed in boot()", SP, GOOD_QR_STAFF_PLUGIN.replace("final class EVC_Staff_Plugin {", "final class EVC_Staff_Plugin {\n    public static function boot() { $b = new EVC_Club_Lookup_Backend(); }"), true);
  expect("customer QR gate dropped", Q.customerPlugin, GOOD_QR_CUSTOMER_PLUGIN.replace("if (EVC_Customer_Qr_Backend::provenance_recorded()) {\n            (new EVC_Rest_Me_Qr_Controller(new EVC_Customer_Qr_Backend()))->register_routes();\n        }", "(new EVC_Rest_Me_Qr_Controller(new EVC_Customer_Qr_Backend()))->register_routes();"), true);
  expect("customer QR gate of another check", Q.customerPlugin, GOOD_QR_CUSTOMER_PLUGIN.replace("EVC_Customer_Qr_Backend::provenance_recorded()", "EVC_Customer_Feature::enabled()"), true);
  expect("customer provenance gate loosened", Q.customerBackend, GOOD_QR_CUSTOMER_BACKEND.replace("=== self::PROVENANCE", "== self::PROVENANCE"), true);
  expect("customer provenance gate always true", Q.customerBackend, GOOD_QR_CUSTOMER_BACKEND.replace("return defined('EVC_CLUB_PAYMENT_PROVENANCE') &&", "return true ||"), true);
  // review MINOR 3: ::class + new $c
  expect("customer backend via ::class and new $c in the customer plugin", Q.customerPlugin, GOOD_QR_CUSTOMER_PLUGIN.replace("        (new EVC_Rest_Me_Controller(", "        $c = EVC_Customer_Qr_Backend::class;\n        $b = new $c();\n        (new EVC_Rest_Me_Controller("), true);
  expect("lookup backend via ::class and new $c in the staff plugin", Q.staffPlugin, GOOD_QR_STAFF_PLUGIN.replace("        (new EVC_Rest_Redeem_Controller($backend))->register_routes();", "        $c = EVC_Club_Lookup_Backend::class;\n        $b = new $c();\n        (new EVC_Rest_Redeem_Controller($backend))->register_routes();"), true);
  expect("new (expr) in the staff plugin", Q.staffPlugin, GOOD_QR_STAFF_PLUGIN.replace("        (new EVC_Rest_Redeem_Controller($backend))->register_routes();", "        $b = new ('EVC_' . $x)();\n        (new EVC_Rest_Redeem_Controller($backend))->register_routes();"), true);
  expect("token service via ::class elsewhere", "includes/x.php", "<?php\n$c = EVC_Qr_Token_Service::class;\n$s = new $c($a, $b, $d);", true);
  expect("lookup service via ::class elsewhere", "includes/x.php", "<?php\n$c = EVC_Member_Lookup_Service::class;\n$s = new $c($a);", true);
  expect("lookup keys via ::class elsewhere", "includes/x.php", "<?php\n$c = EVC_Lookup_Key_Ring::class;\n$k = new $c(array(), 'k1');", true);
  expect("lookup key constant read in a backend", Q.lookupBackend, "<?php\n$n = EVC_Lookup_Key_Ring::MIN_SECRET_BYTES;", true);
  // STAGE-05D: the session controller may ask the lookup controller whether the QR lookup is ready, nothing else may.
  expect("session controller asks lookup_ready()", Q.sessionController, "<?php\nfinal class EVC_Rest_Session_Controller { public function status() { $qr = EVC_Rest_Lookup_Controller::lookup_ready(); } }", false);
  expect("another file asks lookup_ready()", "includes/staff/class-evc-staff-shell.php", "<?php\n$qr = EVC_Rest_Lookup_Controller::lookup_ready();", true);
  expect("session controller builds the lookup backend", Q.sessionController, "<?php\n$b = new EVC_Club_Lookup_Backend();", true);
  expect("customer provenance value changed", Q.customerBackend, GOOD_QR_CUSTOMER_BACKEND.replace("const PROVENANCE = 'club_db_v1';", "const PROVENANCE = 'CLUB_DB_V1';"), true);
  // STAGE-08 round 7 (D22): no secret in a shipped file; the token / secret never reach a log or output.
  expect("a secret defined in a shipped file", "includes/x.php", "<?php\ndefine('EVC_CLUB_QR_SECRET', 'abcdefabcdefabcdefabcdefabcdefabcdef');", true);
  expect("a secret defined upper / lower case", "includes/x.php", "<?php\nDEFINE(\"evc_club_lookup_key\", 'x');", true);
  expect("a class constant named like the secret", "includes/x.php", "<?php\nclass X { const EVC_CLUB_QR_SECRET = 'x'; }", true);
  expect("reading the secret constant is fine", "includes/x.php", "<?php\n$s = defined('EVC_CLUB_QR_SECRET') ? constant('EVC_CLUB_QR_SECRET') : null;", false);
  expect("logging in the token file", Q.tokenFile, "<?php\nerror_log('x');", true);
  expect("printing in the token file", Q.tokenFile, "<?php\nprint_r($a);", true);
  expect("a pure token file", Q.tokenFile, "<?php\nfinal class EVC_Qr_Token { public static function derive($s, $m, $g) { return 'evc_' . substr(hash_hmac('sha256', $m, $s), 0, 48); } }", false);
  expect("the backend logs the token", Q.customerBackend, GOOD_QR_CUSTOMER_BACKEND.replace("    public function issue", "    public function f($token) { error_log('t=' . $token); }\n    public function issue"), true);
  expect("the backend logs the secret by property", Q.customerBackend, GOOD_QR_CUSTOMER_BACKEND.replace("    public function issue", "    public function f() { error_log($this->secret); }\n    public function issue"), true);
  expect("the backend passes the token to its log callable", Q.customerBackend, GOOD_QR_CUSTOMER_BACKEND.replace("    public function issue", "    public function f($token) { ($this->log)('t=' . $token); }\n    public function issue"), true);
  expect("the backend passes the secret to a variable callable", Q.customerBackend, GOOD_QR_CUSTOMER_BACKEND.replace("    public function issue", "    public function f($log, $secret) { $log($secret); }\n    public function issue"), true);
  expect("the backend logs a fixed line", Q.customerBackend, GOOD_QR_CUSTOMER_BACKEND.replace("    public function issue", "    public function f() { ($this->log)('Evangelou Club: customer QR unavailable (x).'); }\n    public function issue"), false);
  return bad;
}

// ---- self test: negative fixtures, run on every package --------------------------------------------
const OPTS = {
  bootstrap: "includes/customer/class-evc-wc-completion-plugin.php",
  guarded: ["EVC_Wc_Completion_Confirmer", "EVC_Bacs_Issuance_Guard"],
  forbidden: ["EVC_Bacs_Payment_Issuer"],
  allowlist: ["includes/customer/class-evc-wc-completion-plugin.php", "includes/customer/class-evc-wc-completion-confirmer.php", "includes/allowed.php"],
  guardedFiles: ["includes/customer/class-evc-wc-completion-confirmer.php"],
  memberLockFiles: ["includes/customer/class-evc-wc-completion-confirmer.php"],
};
const GOOD = `<?php
final class EVC_Wc_Completion_Plugin {
    public static function boot(): void {
        if (!EVC_Wc_Completion_Feature::enabled()) {
            return;
        }
        $p = self::build(1);
    }
    public static function build($x) { return self::wire($x); }
    private static function wire($x) { return new EVC_Wc_Completion_Confirmer(); }
}`;

// ---- STAGE-06 rule set: FluentCRM membership tags, only behind EVC_CLUB_FLUENTCRM_SYNC_ENABLED ------------------
// A. The STAGE-03D construction rules with their own bootstrap and gate: EVC_Fluentcrm_Api_Gateway and
//    EVC_Fluentcrm_Tag_Sync are constructed only inside EVC_Fluentcrm_Plugin::wire() (reached only via build() <- boot()
//    after `if (!EVC_Fluentcrm_Feature::enabled()) { return; }`); their names appear only in the allowlisted files; no
//    shipped file defines the opt-in constant or any of the six tag constants (no enabling defaults).
// B. fluentcrmProblemsFor(): nothing but the gateway file reaches FluentCRM at all (FluentCrmApi, the FluentCrm\
//    namespace, FLUENTCRM* constants, fluentcrm_* / fluent_crm/* hooks, as identifiers or inside strings); the
//    gateway calls only the documented read / tag methods (getContact, getContactByUserId, find, hasAnyTagId,
//    attachTags, detachTags) and fires or hooks nothing; and NO shipped file names a never-touch method (contact
//    creation, the user-link writer, lists, status / double opt-in, custom fields, bulk tag import) or defines the
//    FluentCRM event switch.
// Round 2 additions: the FluentCRM table names (fc_subscriber*, fc_tag*, fc_list*) inside any string of any shipped
// file (no raw SQL on FluentCRM); in the gateway, static calls only to its own helpers and the feature guard
// (Class::name( checked against an allowlist), FluentCrmApi( only with the literal 'contacts' or 'tags', no
// call_user_func* / forward_static_call*, and no property write on any variable other than $this (so a contact's
// status, name or e-mail can never be assigned), including compound and dynamic ({...}) property writes.
// Round 3: in the gateway also no variable member (->$x), no call of an expression result (]( or )(, casts
// excepted), no variable function call ($f(), `new` only of its own exceptions, and no write chained off
// anything but $this's own property ($this->x(...)->p = / $this->a->b =); <=, >=, <=> are not writes.
// STAGE-07 nits: in the gateway no "{$" / "${" inside a string and none of the built-ins that call a callable
// (array_map, usort, preg_replace_callback, ...). A string callable handed to some other built-in remains a limit.
// LIMITATION (documented): a token guard cannot follow names assembled at run time from string pieces
// (e.g. 'fc_' . 'tags', 'create' . 'OrUpdate'); the gateway's small, reviewed surface and the test-only fake
// FluentCRM (which fails on any undocumented call) cover that gap, not this guard.
const FLUENTCRM_BOOTSTRAP = "includes/fluentcrm/class-evc-fluentcrm-plugin.php";
const FLUENTCRM_GATEWAY = "includes/fluentcrm/class-evc-fluentcrm-api-gateway.php";
export const FLUENTCRM_RULES = Object.freeze({
  bootstrap: FLUENTCRM_BOOTSTRAP,
  pluginClass: "EVC_Fluentcrm_Plugin",
  featureClass: "EVC_Fluentcrm_Feature",
  guarded: ["EVC_Fluentcrm_Api_Gateway", "EVC_Fluentcrm_Tag_Sync"],
  forbidden: [],
  // STAGE-08: the read-only readiness checks may NAME the plugin (EVC_Fluentcrm_Plugin::problem(), read-only), never build it.
  allowlist: ["evangelou-club.php", FLUENTCRM_BOOTSTRAP, FLUENTCRM_GATEWAY, "includes/class-evc-fluentcrm-tags.php", "includes/admin/class-evc-readiness.php"],
  guardedFiles: [FLUENTCRM_BOOTSTRAP, FLUENTCRM_GATEWAY],
  memberLockFiles: null,
  forbiddenDefines: [
    "EVC_CLUB_FLUENTCRM_SYNC_ENABLED",
    "EVC_CLUB_FLUENTCRM_TAG_ACTIVE_ID", "EVC_CLUB_FLUENTCRM_TAG_ACTIVE_NAME",
    "EVC_CLUB_FLUENTCRM_TAG_INACTIVE_ID", "EVC_CLUB_FLUENTCRM_TAG_INACTIVE_NAME",
    "EVC_CLUB_FLUENTCRM_TAG_PENDING_ID", "EVC_CLUB_FLUENTCRM_TAG_PENDING_NAME",
  ],
  gateway: FLUENTCRM_GATEWAY,
});
const FLUENTCRM_GATEWAY_METHODS = ["getcontact", "getcontactbyuserid", "find", "hasanytagid", "attachtags", "detachtags"];
const FLUENTCRM_NEVER = ["createorupdate", "getcontactbyuserref", "attachlists", "detachlists", "updatestatus", "senddoubleoptinemail", "synccustomfieldvalues", "importbulk", "fluentcrm_disable_tag_list_events"];
const FLUENTCRM_GATEWAY_FORBIDDEN_CALLS = ["do_action", "apply_filters", "add_action", "add_filter", "do_action_ref_array", "apply_filters_ref_array", "wp_mail"];
const FLUENTCRM_TABLES = ["fc_subscriber", "fc_tag", "fc_list"];
const FLUENTCRM_GATEWAY_STATIC = { self: ["int", "row", "tag_id", "version_supported"], static: ["int", "row", "tag_id", "version_supported"], evc_fluentcrm_feature: ["assert_constructible"] };
const FLUENTCRM_DYNAMIC_CALLS = ["call_user_func", "call_user_func_array", "forward_static_call", "forward_static_call_array",
  // STAGE-07 nit: built-ins that call a callable (a string callable passed to them is a hidden call).
  "array_map", "array_filter", "array_walk", "array_walk_recursive", "array_reduce", "usort", "uasort", "uksort", "iterator_apply",
  "register_shutdown_function", "register_tick_function", "set_error_handler", "set_exception_handler", "spl_autoload_register",
  "preg_replace_callback", "preg_replace_callback_array", "ob_start", "header_register_callback"];
/** The gateway may construct only its own exceptions. */
const FLUENTCRM_GATEWAY_NEW = ["runtimeexception", "invalidargumentexception"];
const reachesFluentcrm = (v) => v.startsWith("fluentcrm") || v.startsWith("fluent_crm") || v.includes("fluentcrmapi") || v.includes("\\fluentcrm\\") || v.startsWith("fluentcampaign");

export function fluentcrmProblemsFor(path, src, rules = FLUENTCRM_RULES) {
  const tokens = tokenize(src);
  const problems = [];
  const gateway = path === rules.gateway;
  tokens.forEach((tk, k) => {
    if (tk.t !== "id" && tk.t !== "str") return;
    const v = bare(tk.v).toLowerCase();
    if (!gateway && reachesFluentcrm(v)) problems.push(`${path}: reaches FluentCRM (${tk.t === "str" ? "string" : tk.v}) outside the gateway`);
    if (FLUENTCRM_NEVER.some((n) => (tk.t === "id" ? v === n || v.endsWith("\\" + n) : v.includes(n)))) problems.push(`${path}: names a never-touch FluentCRM method (${tk.v})`);
    if (tk.t === "str" && FLUENTCRM_TABLES.some((t) => v.includes(t))) problems.push(`${path}: names a FluentCRM table in a string`);
    // STAGE-07 nit: "{$x->m()}" / "${x}" inside a string runs code; none in the gateway.
    if (gateway && tk.t === "str" && (tk.v.includes("{$") || tk.v.includes("${"))) problems.push(`${path}: the gateway interpolates code in a string`);
    if (gateway && tk.t === "id") {
      if (tokens[k - 1]?.v === "->" && tokens[k + 1]?.v === "(" && !FLUENTCRM_GATEWAY_METHODS.includes(v) && !["contacts", "tags", "subscriber"].includes(v)) problems.push(`${path}: the gateway calls ->${tk.v}()`);
      if (FLUENTCRM_GATEWAY_FORBIDDEN_CALLS.includes(v) && tokens[k + 1]?.v === "(") problems.push(`${path}: the gateway calls ${tk.v}()`);
      if (FLUENTCRM_DYNAMIC_CALLS.includes(v)) problems.push(`${path}: the gateway uses ${tk.v}`);
      if (tokens[k + 1]?.v === "::" && tokens[k + 2]?.t === "id" && tokens[k + 3]?.v === "(") {
        const allowed = FLUENTCRM_GATEWAY_STATIC[v] ?? [];
        if (!allowed.includes(tokens[k + 2].v.toLowerCase())) problems.push(`${path}: the gateway calls ${tk.v}::${tokens[k + 2].v}()`);
      }
      if (v === "fluentcrmapi" && tokens[k + 1]?.v === "(" && !(tokens[k + 2]?.t === "str" && ["contacts", "tags"].includes(tokens[k + 2].v) && tokens[k + 3]?.v === ")")) problems.push(`${path}: FluentCrmApi() with anything but 'contacts' or 'tags'`);
    }
  });
  if (gateway) {
    tokens.forEach((tk, k) => {
      const nx = tokens[k + 1];
      if (tk.t === "op" && tk.v === "->") {
        // Round 3: variable / dynamic members, and writes on anything but $this's own properties (so also
        // `$this->x(...)->prop =` and `$this->a->b =`).
        if (nx?.t === "var") problems.push(`${path}: the gateway uses a variable member ->${nx.v}`);
        if (nx?.v === "{") problems.push(`${path}: the gateway uses a dynamic member ->{...}`);
        if (nx?.t === "id" && !(tokens[k - 1]?.t === "var" && tokens[k - 1].v === "$this") && writesAt(tokens, k + 2)) problems.push(`${path}: the gateway writes ->${nx.v}`);
      }
      const isCast = tk.v === ")" && tokens[k - 2]?.v === "(" && ["string", "int", "integer", "bool", "boolean", "float", "double", "array"].includes(String(tokens[k - 1]?.v).toLowerCase());
      if (tk.t === "op" && (tk.v === "]" || tk.v === ")") && nx?.v === "(" && !isCast) problems.push(`${path}: the gateway calls the result of an expression (${tk.v}()`);
      if (tk.t === "var" && nx?.v === "(") problems.push(`${path}: the gateway calls a variable function ${tk.v}()`);
      if (tk.t === "id" && tk.v.toLowerCase() === "new" && !(nx?.t === "id" && FLUENTCRM_GATEWAY_NEW.includes(bare(nx.v).toLowerCase()))) problems.push(`${path}: the gateway constructs ${nx ? nx.v : "?"}`);
    });
  }
  return problems;
}

/**
 * Is the token at $j the start of a write to the member just before it? Assignments (=, compound ops,
 * ??=, <<=, >>=, **=), ++ / -- and array writes count; comparisons (==, ===, <=, >=, <=>, !=) and reads
 * (?? without =) do not.
 */
function writesAt(tokens, j) {
  const a = tokens[j]?.v;
  const b = tokens[j + 1]?.v;
  if (tokens[j]?.t !== "op") return false;
  if (a === "=") return b !== "=" && b !== ">";
  if ([".", "+", "-", "*", "/", "%", "|", "&", "^"].includes(a) && b === "=") return true;
  if ((a === "+" || a === "-") && b === a) return true;
  if (["?", "<", ">", "*"].includes(a) && b === a && tokens[j + 2]?.v === "=") return true;
  if (a === "[") return isAssignAfterBracket(tokens, j);
  return false;
}

/** `$x->p[...] = …` : after the matching ], an assignment follows. */
function isAssignAfterBracket(tokens, at) {
  let depth = 0;
  for (let j = at; j < tokens.length; j++) {
    if (tokens[j].v === "[") depth++;
    else if (tokens[j].v === "]" && --depth === 0) return tokens[j + 1]?.v === "=" && tokens[j + 2]?.v !== "=" && tokens[j + 2]?.v !== ">";
  }
  return false;
}

const GOOD_FLUENTCRM_GATEWAY = `<?php
final class EVC_Fluentcrm_Api_Gateway implements EVC_Fluentcrm_Gateway {
    const API_FUNCTION = 'FluentCrmApi';
    public function __construct() { EVC_Fluentcrm_Feature::assert_constructible(__CLASS__); }
    public function available(): bool { return function_exists(self::API_FUNCTION) && self::version_supported(defined('FLUENTCRM_PLUGIN_VERSION') ? '1' : null); }
    public function tag(int $id): ?array { $t = $this->tags()->find($id); return array('id' => self::int($t->id)); }
    public function attach_tag(int $c, int $t): void { $s = $this->subscriber($c); $s->attachTags(array(self::tag_id($t))); $ok = $s->id === $c; $this->last = $s; }
    private function subscriber(int $c) { return $this->contacts()->getContact($c); }
    private function contacts() { return FluentCrmApi('contacts'); }
    private function tags() { return FluentCrmApi('tags'); }
}`;
const GOOD_FLUENTCRM_PLUGIN = `<?php
final class EVC_Fluentcrm_Plugin {
    public static function boot($seams = null): void {
        if (!EVC_Fluentcrm_Feature::enabled()) {
            return;
        }
        $parts = self::build();
    }
    public static function build() { return self::wire(); }
    private static function wire() { return new EVC_Fluentcrm_Tag_Sync($c, new EVC_Fluentcrm_Api_Gateway(), $m, $e, $k); }
}`;

function fluentcrmSelfTest() {
  const bad = [];
  const F = FLUENTCRM_RULES;
  const expect = (label, path, src, want) => {
    const got = problemsFor(path, src, F).length + fluentcrmProblemsFor(path, src).length > 0;
    if (got !== want) bad.push(`fluentcrm ${label}: expected ${want ? "a problem" : "no problem"}`);
  };
  expect("good plugin", F.bootstrap, GOOD_FLUENTCRM_PLUGIN, false);
  expect("good gateway", F.gateway, GOOD_FLUENTCRM_GATEWAY, false);
  expect("gate missing", F.bootstrap, GOOD_FLUENTCRM_PLUGIN.replace("if (!EVC_Fluentcrm_Feature::enabled()) {\n            return;\n        }", ""), true);
  expect("gate of another feature", F.bootstrap, GOOD_FLUENTCRM_PLUGIN.replace("EVC_Fluentcrm_Feature::enabled", "EVC_Customer_Feature::enabled"), true);
  expect("gateway constructed elsewhere", "includes/x.php", "<?php\n$g = new EVC_Fluentcrm_Api_Gateway();", true);
  expect("sync constructed outside wire()", F.bootstrap, GOOD_FLUENTCRM_PLUGIN.replace("public static function build() {", "public static function build() { new EVC_Fluentcrm_Tag_Sync(1, 2, 3, 4, 5);"), true);
  expect("FluentCrmApi called elsewhere", "includes/x.php", "<?php\n$c = FluentCrmApi('contacts');", true);
  expect("FluentCrmApi with a leading backslash", "includes/x.php", "<?php\n$c = \\FluentCrmApi('contacts');", true);
  expect("FluentCrmApi through a string", "includes/x.php", "<?php\n$c = call_user_func('fluentcrmapi', 'contacts');", true);
  expect("FluentCrm namespace elsewhere", "includes/x.php", "<?php\n$s = \\FluentCrm\\App\\Models\\Subscriber::where('email', $e)->first();", true);
  expect("FluentCrm namespace in a string", "includes/x.php", "<?php\n$c = 'FluentCrm\\\\App\\\\Models\\\\Tag';", true);
  expect("a FluentCRM hook elsewhere", "includes/x.php", "<?php\nadd_action('fluent_crm/contact_added_to_tags', 'f');", true);
  expect("a legacy FluentCRM hook elsewhere", "includes/x.php", "<?php\ndo_action('fluentcrm_contact_added_to_tags', $a, $b);", true);
  expect("a FluentCRM constant elsewhere", "includes/x.php", "<?php\n$v = FLUENTCRM_PLUGIN_VERSION;", true);
  expect("contact creation", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("->find($id)", "->createOrUpdate(array())"), true);
  expect("contact creation named elsewhere", "includes/x.php", "<?php\n$m = 'createOrUpdate';", true);
  expect("the user-link writer", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("->getContact($c)", "->getContactByUserRef($c)"), true);
  expect("lists", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("->attachTags(", "->attachLists("), true);
  expect("status write", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("->attachTags(array(self::tag_id($t)))", "->updateStatus('subscribed')"), true);
  expect("double opt-in", "includes/x.php", "<?php\n$s->sendDoubleOptinEmail();", true);
  expect("an undocumented gateway call", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("->attachTags(array(self::tag_id($t)))", "->save()"), true);
  expect("the gateway fires a hook", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("$t = $this->tags()", "do_action('x'); $t = $this->tags()"), true);
  expect("the event switch", "includes/x.php", "<?php\ndefine('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);", true);
  expect("the event switch in the gateway", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("final class", "define('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);\nfinal class"), true);
  expect("defines the opt-in constant", "includes/x.php", "<?php\ndefine('EVC_CLUB_FLUENTCRM_SYNC_ENABLED', true);", true);
  expect("defines a tag ID default", "includes/x.php", "<?php\ndefine('EVC_CLUB_FLUENTCRM_TAG_ACTIVE_ID', 2);", true);
  expect("our own option names are fine", "includes/x.php", "<?php\nupdate_option('evc_club_fluentcrm_status', array());\n$c = 'EVC_CLUB_FLUENTCRM_SYNC_ENABLED';", false);
  expect("a comment mention is fine", "includes/x.php", "<?php\n// FluentCrmApi('contacts')->createOrUpdate()\n$x = 1;", false);
  // Round 2.
  expect("raw SQL on the subscribers table", F.bootstrap, GOOD_FLUENTCRM_PLUGIN.replace("$parts = self::build();", "$wpdb->query(\"UPDATE {$wpdb->prefix}fc_subscribers SET status = 'x'\"); $parts = self::build();"), true);
  expect("raw SQL on the tag pivot in the gateway", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("return array('id'", "$q = 'DELETE FROM wp_fc_subscriber_pivot'; return array('id'"), true);
  expect("the lists table elsewhere", "includes/x.php", "<?php\n$t = $wpdb->prefix . 'fc_lists';", true);
  expect("the tags table elsewhere", "includes/x.php", "<?php\n$t = 'FC_TAGS';", true);
  expect("a foreign static call in the gateway", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("$t = $this->tags()->find($id);", "$t = Subscriber::where('id', $id);"), true);
  expect("an unknown helper on self in the gateway", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("self::int($t->id)", "self::write($t->id)"), true);
  expect("the feature guard is fine, but not another feature method", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("EVC_Fluentcrm_Feature::assert_constructible(__CLASS__)", "EVC_Fluentcrm_Feature::tag_config()"), true);
  expect("call_user_func in the gateway", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("return FluentCrmApi('contacts');", "return call_user_func('FluentCrmApi', 'contacts');"), true);
  expect("call_user_func_array in the gateway", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("$ok = $s->id === $c;", "call_user_func_array(array($s, 'save'), array());"), true);
  expect("forward_static_call in the gateway", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("$ok = $s->id === $c;", "forward_static_call(array('X', 'y'));"), true);
  expect("FluentCrmApi('lists') in the gateway", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("FluentCrmApi('tags')", "FluentCrmApi('lists')"), true);
  expect("FluentCrmApi with a variable in the gateway", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("FluentCrmApi('tags')", "FluentCrmApi($k)"), true);
  expect("a contact's status assigned", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("$ok = $s->id === $c;", "$s->status = 'subscribed';"), true);
  expect("a contact's name appended", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("$ok = $s->id === $c;", "$s->first_name .= 'x';"), true);
  expect("a contact's field array written", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("$ok = $s->id === $c;", "$s->custom_fields['a'] = 1;"), true);
  expect("a dynamic property write", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("$ok = $s->id === $c;", "$s->{$p} = 1;"), true);
  expect("a null-coalescing property write", F.gateway, GOOD_FLUENTCRM_GATEWAY.replace("$ok = $s->id === $c;", "$s->email ??= 'x';"), true);
  // Round 3: bypasses ...
  const g = (code) => GOOD_FLUENTCRM_GATEWAY.replace("$ok = $s->id === $c;", code);
  expect("a variable method call", F.gateway, g("$s->$m();"), true);
  expect("a variable property read", F.gateway, g("$x = $s->$p;"), true);
  expect("a call through an array element", F.gateway, g("$fns[0]($s);"), true);
  expect("a call through a parenthesised expression", F.gateway, g("($this->fn)($s);"), true);
  expect("a variable function call", F.gateway, g("$f($s);"), true);
  expect("constructing another class", F.gateway, g("$q = new Foo();"), true);
  expect("constructing a namespaced class", F.gateway, g("$q = new \\FluentCrm\\App\\Models\\Tag();"), true);
  expect("constructing a class through a variable", F.gateway, g("$q = new $cls();"), true);
  expect("a write chained off a method call", F.gateway, g("$this->subscriber($c)->status = 'x';"), true);
  expect("a write through a property of $this", F.gateway, g("$this->last->status = 'x';"), true);
  expect("an increment", F.gateway, g("$s->n++;"), true);
  expect("a power-assign", F.gateway, g("$s->n **= 2;"), true);
  expect("a shift-assign", F.gateway, g("$s->n <<= 2;"), true);
  // ... and no false positives.
  expect("<= is a comparison", F.gateway, g("$ok = $s->id <= $c;"), false);
  expect(">= is a comparison", F.gateway, g("$ok = $s->id >= $c;"), false);
  expect("<=> is a comparison", F.gateway, g("$ok = $s->id <=> $c;"), false);
  expect("!= is a comparison", F.gateway, g("$ok = $s->id != $c;"), false);
  expect("?? is a read", F.gateway, g("$ok = $s->user_id ?? null;"), false);
  expect("the gateway's own exceptions", F.gateway, g("if (!$s) { throw new RuntimeException('x'); } $e = new \\InvalidArgumentException('y');"), false);
  expect("its own property", F.gateway, g("$this->cache = $s;"), false);
  expect("a cast before a parenthesis", F.gateway, g("$x = (string) ($s->email ?? '');"), false);
  // STAGE-07 nits.
  expect("a method call interpolated in a string", F.gateway, g('$x = "{$s->save()}";'), true);
  expect("a variable interpolated in a string", F.gateway, g('$x = "${s}";'), true);
  expect("a string callable to array_map", F.gateway, g("$x = array_map('FluentCrmApi', array('lists'));"), true);
  expect("a string callable to usort", F.gateway, g("usort($a, 'evil');"), true);
  expect("preg_replace_callback", F.gateway, g("$x = preg_replace_callback('/a/', 'f', $y);"), true);
  expect("a plain single-quoted string with braces is fine", F.gateway, g("$x = '{not code}';"), false);
  return bad;
}

// ---- STAGE-07 rule set: in-store QR onboarding, only behind EVC_CLUB_ONBOARDING_ENABLED -------------------------
// A. The STAGE-03D construction rules with their own bootstrap and gate: the registration, the hand-off, the join
//    page shell and the join REST controller are constructed only inside EVC_Onboarding_Plugin::wire() (reached only
//    via build() <- boot() after `if (!EVC_Onboarding_Feature::enabled()) { return; }`); their names appear only in
//    the allowlisted files; no shipped file defines EVC_CLUB_ONBOARDING_ENABLED.
// B. onboardingProblemsFor(): creating a user or signing one in (wp_insert_user / wp_create_user / wp_update_user /
//    register_new_user / wp_set_auth_cookie / wp_signon / wp_set_current_user) only in the registration file; an
//    add-to-cart URL only in the hand-off file (identifiers and strings, any case).
const ONBOARDING_BOOTSTRAP = "includes/onboarding/class-evc-onboarding-plugin.php";
export const ONBOARDING_RULES = Object.freeze({
  bootstrap: ONBOARDING_BOOTSTRAP,
  pluginClass: "EVC_Onboarding_Plugin",
  featureClass: "EVC_Onboarding_Feature",
  guarded: ["EVC_Onboarding_Registration", "EVC_Onboarding_Handoff", "EVC_Onboarding_Shell", "EVC_Rest_Join_Controller", "EVC_Onboarding_Checkout_Guard"],
  forbidden: [],
  allowlist: [
    "evangelou-club.php",
    ONBOARDING_BOOTSTRAP,
    "includes/onboarding/class-evc-onboarding-registration.php",
    "includes/onboarding/class-evc-onboarding-handoff.php",
    "includes/onboarding/class-evc-onboarding-shell.php",
    "includes/onboarding/class-evc-rest-join-controller.php",
    "includes/onboarding/class-evc-onboarding-checkout-guard.php",
    // STAGE-08: the readiness checks reuse EVC_Onboarding_Shell::same_site_url() (D13 terms URL); they construct nothing.
    "includes/admin/class-evc-readiness.php",
    // STAGE-08 round 10: the member page sends EVC_Onboarding_Shell::join_url() (static, behind
    // EVC_Onboarding_Feature::enabled()) so a customer without a membership can join; it constructs nothing.
    "includes/customer/class-evc-customer-shell.php",
  ],
  guardedFiles: [
    ONBOARDING_BOOTSTRAP,
    "includes/onboarding/class-evc-onboarding-registration.php",
    "includes/onboarding/class-evc-onboarding-handoff.php",
    "includes/onboarding/class-evc-onboarding-shell.php",
    "includes/onboarding/class-evc-rest-join-controller.php",
    "includes/onboarding/class-evc-onboarding-checkout-guard.php",
  ],
  memberLockFiles: null,
  forbiddenDefines: ["EVC_CLUB_ONBOARDING_ENABLED"],
  // STAGE-08 round 11 (C4 of the round-10 review): an allowlisted file outside the onboarding path may name the
  // onboarding classes ONLY through these exact static calls (class::method followed by "("), nothing else: no other
  // method (accept_terms, poster_url, …), no ::class, no string, no type hint.
  pinnedCalls: {
    "includes/customer/class-evc-customer-shell.php": ["evc_onboarding_shell::join_url"],
  },
  registrationFile: "includes/onboarding/class-evc-onboarding-registration.php",
  handoffFile: "includes/onboarding/class-evc-onboarding-handoff.php",
  checkoutGuardFile: "includes/onboarding/class-evc-onboarding-checkout-guard.php",
});
// Round 2 (D15): only the checkout guard may change which gateways WooCommerce offers or veto the cart / checkout.
// Round 3 (R1): the Block Checkout / Store API hooks too.
const CHECKOUT_HOOKS = [
  "woocommerce_available_payment_gateways",
  "woocommerce_add_to_cart_validation",
  "woocommerce_after_checkout_validation",
  "woocommerce_no_available_payment_methods_message",
  "woocommerce_store_api_cart_errors",
  "woocommerce_store_api_checkout_update_order_from_request",
  "woocommerce_store_api_validate_add_to_cart",
];
const USER_WRITERS = ["wp_insert_user", "wp_create_user", "wp_update_user", "register_new_user", "wp_set_auth_cookie", "wp_signon", "wp_set_current_user"];
// STAGE-08: the shared till account service may CREATE that one account (wp_insert_user) and nothing else of the list.
const STAFF_ACCOUNT_SERVICE = "includes/admin/class-evc-staff-account-service.php";

export function onboardingProblemsFor(path, src, rules = ONBOARDING_RULES) {
  const problems = [];
  const pinned = rules.pinnedCalls?.[path];
  if (pinned) {
    const names = [...rules.guarded, rules.pluginClass].map((n) => n.toLowerCase());
    const tokens = tokenize(src);
    tokens.forEach((tk, k) => {
      if (tk.t === "str" && names.some((n) => tk.v.toLowerCase().includes(n))) problems.push(`${path}: a string names an onboarding class (only ${pinned.join(", ")}( is allowed here)`);
      if (tk.t !== "id" || !names.includes(bare(tk.v).toLowerCase())) return;
      const method = tokens[k + 1]?.v === "::" && tokens[k + 2]?.t === "id" ? `${bare(tk.v).toLowerCase()}::${tokens[k + 2].v.toLowerCase()}` : null;
      if (method === null || !pinned.includes(method) || tokens[k + 3]?.v !== "(") problems.push(`${path}: names ${bare(tk.v)} other than through ${pinned.join(", ")}(`);
    });
    // Round 12 (N2 of the round-11 review): a class name assembled at run time cannot reach the onboarding classes from
    // the pinned file: no `$x::`, no `new $x`, no call of a variable or an expression, no call_user_func* /
    // forward_static_call* / constant() / class_exists() / is_callable() / method_exists() / get_class_methods().
    const DYNAMIC = ["call_user_func", "call_user_func_array", "forward_static_call", "forward_static_call_array", "constant", "class_exists", "is_callable", "method_exists", "get_class_methods", "is_subclass_of", "is_a"];
    tokens.forEach((tk, k) => {
      if (tk.t === "var" && tokens[k + 1]?.v === "::") problems.push(`${path}: dynamic static call ${tk.v}:: in a pinned file`);
      if (tk.t === "id" && tk.v.toLowerCase() === "new" && (tokens[k + 1]?.t === "var" || tokens[k + 1]?.v === "(")) problems.push(`${path}: dynamic construction in a pinned file`);
      if (tk.t === "var" && tokens[k + 1]?.v === "(" && tokens[k - 1]?.v !== "->" && tokens[k - 1]?.v !== "::") problems.push(`${path}: call of a variable ${tk.v}( in a pinned file`);
      if (tk.t === "id" && DYNAMIC.includes(bare(tk.v).toLowerCase()) && tokens[k + 1]?.v === "(" && tokens[k - 1]?.v !== "->" && tokens[k - 1]?.v !== "::") problems.push(`${path}: ${tk.v}() in a pinned file`);
    });
  }
  for (const tk of tokenize(src)) {
    if (tk.t !== "id" && tk.t !== "str") continue;
    const v = bare(tk.v).toLowerCase();
    if (path !== rules.registrationFile && USER_WRITERS.some((w) => (tk.t === "id" ? v === w : v.includes(w)) && !(path === STAFF_ACCOUNT_SERVICE && w === "wp_insert_user"))) problems.push(`${path}: creates or signs in a user (${tk.v}) outside the onboarding registration`);
    if (path !== rules.handoffFile && v.includes("add-to-cart")) problems.push(`${path}: builds an add-to-cart URL outside the onboarding hand-off`);
    if (path !== rules.checkoutGuardFile && CHECKOUT_HOOKS.some((h) => v.includes(h))) problems.push(`${path}: hooks the WooCommerce cart / checkout / gateways (${tk.v}) outside the onboarding checkout guard`);
  }
  return problems;
}

const GOOD_ONBOARDING = `<?php
final class EVC_Onboarding_Plugin {
    public static function boot($seams = null): void {
        if (!EVC_Onboarding_Feature::enabled()) {
            return;
        }
        $p = self::build();
    }
    public static function build() { return self::wire(); }
    private static function wire() {
        $h = new EVC_Onboarding_Handoff(null, $m, $c);
        $r = new EVC_Onboarding_Registration();
        return array(new EVC_Onboarding_Shell($h, $a), new EVC_Rest_Join_Controller($r, $h), new EVC_Onboarding_Checkout_Guard($h, $cart));
    }
}`;

function onboardingSelfTest() {
  const bad = [];
  const O = ONBOARDING_RULES;
  const expect = (label, path, src, want) => {
    const got = problemsFor(path, src, O).length + onboardingProblemsFor(path, src).length > 0;
    if (got !== want) bad.push(`onboarding ${label}: expected ${want ? "a problem" : "no problem"}`);
  };
  expect("good bootstrap", O.bootstrap, GOOD_ONBOARDING, false);
  expect("gate missing", O.bootstrap, GOOD_ONBOARDING.replace("if (!EVC_Onboarding_Feature::enabled()) {\n            return;\n        }", ""), true);
  expect("gate of another feature", O.bootstrap, GOOD_ONBOARDING.replace("EVC_Onboarding_Feature::enabled", "EVC_Customer_Feature::enabled"), true);
  expect("registration constructed elsewhere", "includes/x.php", "<?php\n$r = new EVC_Onboarding_Registration();", true);
  expect("controller constructed outside wire()", O.bootstrap, GOOD_ONBOARDING.replace("public static function build() {", "public static function build() { new EVC_Rest_Join_Controller(1, 2);"), true);
  expect("hand-off named outside the allowlist", "includes/x.php", "<?php\nfunction f(EVC_Onboarding_Handoff $h) { return $h; }", true);
  expect("round 10: the member page reads the static join URL", "includes/customer/class-evc-customer-shell.php", "<?php\nif (EVC_Onboarding_Feature::enabled()) { $c['joinUrl'] = EVC_Onboarding_Shell::join_url(); }", false);
  expect("round 10: the member page never constructs the join shell", "includes/customer/class-evc-customer-shell.php", "<?php\n$s = new EVC_Onboarding_Shell($h, $a);", true);
  // Round 11 (C4): the member page is pinned to the one static call.
  const SHELL = "includes/customer/class-evc-customer-shell.php";
  expect("round 11: the member page may not accept the terms", SHELL, "<?php\nEVC_Onboarding_Handoff::accept_terms($id);", true);
  expect("round 11: the member page may not send the poster URL", SHELL, "<?php\n$c['joinUrl'] = EVC_Onboarding_Shell::poster_url();", true);
  expect("round 11: nor read the terms", SHELL, "<?php\n$t = EVC_Onboarding_Handoff::terms_accepted($id);", true);
  expect("round 11: nor name the class", SHELL, "<?php\n$c = EVC_Onboarding_Shell::class;", true);
  expect("round 11: nor take the method as a callable", SHELL, "<?php\n$f = array('EVC_Onboarding_Shell', 'join_url');", true);
  expect("round 11: nor a type hint", SHELL, "<?php\nfunction f(EVC_Onboarding_Shell $s) { return 1; }", true);
  expect("round 11: nor the plugin's parts", SHELL, "<?php\n$p = EVC_Onboarding_Plugin::parts();", true);
  expect("round 11: the pinned call with a leading backslash and spaces", SHELL, "<?php\n$u = \\EVC_Onboarding_Shell :: join_url ();", false);
  // Round 12 (N2): a class name assembled at run time.
  expect("round 12: a variable static call", SHELL, "<?php\n$c = 'EVC_Onboarding_' . 'Shell';\n$u = $c::poster_url();", true);
  expect("round 12: call_user_func with an assembled name", SHELL, "<?php\n$u = call_user_func(array('EVC_Onboarding_' . 'Shell', 'poster_url'));", true);
  expect("round 12: forward_static_call", SHELL, "<?php\n$u = forward_static_call(array($c, 'poster_url'));", true);
  expect("round 12: a variable callable", SHELL, "<?php\n$f = 'x';\n$u = $f();", true);
  expect("round 12: new of an assembled name", SHELL, "<?php\n$o = new $c();", true);
  expect("round 12: constant() of an assembled name", SHELL, "<?php\n$v = constant($c . '::SLUG');", true);
  expect("round 12: a method call on an object is fine", SHELL, "<?php\n$u = $this->assets->resolve();", false);
  expect("build() from another file", "includes/x.php", "<?php\nEVC_Onboarding_Plugin::build();", true);
  expect("defines the opt-in constant", "includes/x.php", "<?php\ndefine('EVC_CLUB_ONBOARDING_ENABLED', true);", true);
  expect("a user created elsewhere", "includes/customer/x.php", "<?php\n$id = wp_insert_user(array());", true);
  expect("a user created through a string", "includes/x.php", "<?php\ncall_user_func('wp_create_user', 'a', 'b');", true);
  expect("a sign-in elsewhere", "includes/x.php", "<?php\nwp_set_auth_cookie($id);", true);
  expect("a user switched elsewhere", O.handoffFile, "<?php\nwp_set_current_user(1);", true);
  expect("checkout guard constructed elsewhere", "includes/x.php", "<?php\n$g = new EVC_Onboarding_Checkout_Guard($h);", true);
  expect("gateways filtered elsewhere", "includes/customer/x.php", "<?php\nadd_filter('woocommerce_available_payment_gateways', 'f');", true);
  expect("checkout vetoed elsewhere", "includes/x.php", "<?php\nadd_action('WooCommerce_After_Checkout_Validation', 'f', 20, 2);", true);
  expect("cart vetoed elsewhere", "includes/x.php", "<?php\nadd_filter('woocommerce_add_to_cart_validation', 'f');", true);
  expect("Store API cart errors elsewhere", "includes/x.php", "<?php\nadd_action('woocommerce_store_api_cart_errors', 'f', 10, 2);", true);
  expect("Store API order hook elsewhere", "includes/x.php", "<?php\nadd_action('woocommerce_store_api_checkout_update_order_from_request', 'f', 10, 2);", true);
  expect("the guard may hook the Store API", O.checkoutGuardFile, "<?php\nadd_action('woocommerce_store_api_cart_errors', array($this, 'store_api_cart_errors'), 20, 2);", false);
  expect("the guard may filter gateways", O.checkoutGuardFile, "<?php\nadd_filter('woocommerce_available_payment_gateways', array($this, 'gateways'), 100, 1);", false);
  expect("the registration may create users", O.registrationFile, "<?php\n$id = wp_insert_user($a); wp_set_auth_cookie($id);", false);
  expect("an add-to-cart URL elsewhere", "includes/x.php", "<?php\n$u = add_query_arg('add-to-cart', 5, $c);", true);
  expect("an add-to-cart URL in the registration", O.registrationFile, "<?php\n$u = 'ADD-TO-CART';", true);
  expect("the hand-off builds it", O.handoffFile, "<?php\n$u = add_query_arg('add-to-cart', 5, $c);", false);
  expect("a comment mention is fine", "includes/x.php", "<?php\n// wp_insert_user() add-to-cart\n$x = 1;", false);
  return bad;
}

// ---- STAGE-08 round 11 (owner decision D26 part 1): the member app's home-screen files ---------------------------
// PHP: only EVC_Customer_Pwa (and the member page that calls it) may name it; the manifest link, the iOS tags and
// the worker exist in that one file (so the staff app, the join pages and every other page get none of them); no
// shipped file may send Service-Worker-Allowed (the worker's scope can never be wider than /club-member/).
export const PWA_RULES = Object.freeze({
  pwaFile: "includes/customer/class-evc-customer-pwa.php",
  allowlist: ["includes/customer/class-evc-customer-pwa.php", "includes/customer/class-evc-customer-shell.php"],
  pwaStrings: ['rel="manifest"', "manifest+json", "apple-touch-icon", "apple-mobile-web-app", "serviceworker", "sw.js", "__evc_sw__", "webmanifest"],
});

export function pwaProblemsFor(path, src, rules = PWA_RULES) {
  const problems = [];
  for (const tk of tokenize(src)) {
    if (tk.t === "id" && bare(tk.v).toLowerCase() === "evc_customer_pwa" && !rules.allowlist.includes(path)) problems.push(`${path}: names EVC_Customer_Pwa (only the member page may)`);
    if (tk.t !== "str") continue;
    const v = tk.v.toLowerCase();
    if (v.includes("service-worker-allowed")) problems.push(`${path}: sends Service-Worker-Allowed (the worker's scope stays /club-member/)`);
    if (v.includes("evc_customer_pwa") && !rules.allowlist.includes(path)) problems.push(`${path}: a string names EVC_Customer_Pwa`);
    if (path !== rules.pwaFile) {
      for (const needle of rules.pwaStrings) if (v.includes(needle)) problems.push(`${path}: home-screen app text "${needle}" outside ${rules.pwaFile}`);
    }
  }
  return problems;
}

// The BUILT worker (customer-app/pwa/sw.js, esbuild, not minified). It may store nothing but its versioned cache,
// may never load other code, and keeps the REST network-only rule and exactly its two cache writes / answers.
// Round 12 (D26 part 2): the worker now shows push notifications and opens the member page on a tap; it still never
// subscribes (pushManager is the page's), never syncs, never messages, and never reads data from a notification (so a
// tap can only ever open the registration's own scope).
export const WORKER_FORBIDDEN = ["localStorage", "sessionStorage", "indexedDB", "document.cookie", "cookieStore", "importScripts", "eval(", "new Function", "XMLHttpRequest", "club-admin", "pushManager", '"sync"', "BroadcastChannel", "postMessage", "notification.data", ".data.url", "periodicsync", "pushsubscriptionchange"];
export function workerProblems(text) {
  const problems = [];
  for (const word of WORKER_FORBIDDEN) if (text.includes(word)) problems.push(`service worker: uses ${word}`);
  for (const word of ["rest_route", "evc-club-member-", "__EVC_SW__", "no-store", '"push"', '"notificationclick"', "registration.scope"]) if (!text.includes(word)) problems.push(`service worker: ${word} missing`);
  const count = (needle) => text.split(needle).length - 1;
  if (count("cache.put(") !== 2) problems.push(`service worker: ${count("cache.put(")} cache writes (expected 2: install and the asset rule)`);
  if (count(".respondWith(") !== 2) problems.push(`service worker: ${count(".respondWith(")} answers (expected 2: the member page and the asset rule)`);
  if (count(".showNotification(") !== 1) problems.push(`service worker: ${count(".showNotification(")} notifications (expected 1: the push handler)`);
  if (count(".openWindow(") !== 1) problems.push(`service worker: ${count(".openWindow(")} window openings (expected 1: the notification tap)`);
  return problems;
}

function pwaSelfTest() {
  const bad = [];
  const expect = (label, path, src, want) => {
    if ((pwaProblemsFor(path, src).length > 0) !== want) bad.push(`pwa ${label}: expected ${want ? "a problem" : "no problem"}`);
  };
  const P = PWA_RULES.pwaFile;
  const SHELL = "includes/customer/class-evc-customer-shell.php";
  expect("the PWA file writes the manifest link", P, "<?php\n$h = '<link rel=\"manifest\" href=\"' . $u . '\">';", false);
  expect("the member page calls the PWA class", SHELL, "<?php\n$c = EVC_Customer_Pwa::config();", false);
  expect("the staff page names the PWA class", "includes/staff/class-evc-staff-shell.php", "<?php\n$c = EVC_Customer_Pwa::config();", true);
  expect("the join page writes a manifest link", "includes/onboarding/class-evc-onboarding-shell.php", "<?php\n$h = '<link rel=\"manifest\" href=\"x\">';", true);
  expect("the staff page names a worker", "includes/staff/class-evc-staff-shell.php", "<?php\n$c['serviceWorkerUrl'] = home_url('/club-admin/sw.js');", true);
  expect("an apple tag elsewhere", "includes/x.php", "<?php\necho '<link rel=\"apple-touch-icon\" href=\"x\">';", true);
  expect("Service-Worker-Allowed even in the PWA file", P, "<?php\n$h['Service-Worker-Allowed'] = '/';", true);
  expect("a string naming the class elsewhere", "includes/x.php", "<?php\ncall_user_func(array('EVC_Customer_Pwa', 'config'));", true);
  const W = "self.__EVC_SW__; const p = 'evc-club-member-'; u.searchParams.has('rest_route'); h = 'no-store'; await cache.put(a, b); await cache.put(c, d); e.respondWith(x); e.respondWith(y); sw.addEventListener(\"push\", f); sw.registration.showNotification(t, o); sw.addEventListener(\"notificationclick\", g); clickTarget(w, sw.registration.scope); sw.clients.openWindow(t.open);";
  const wexpect = (label, text, want) => {
    if ((workerProblems(text).length > 0) !== want) bad.push(`worker ${label}: expected ${want ? "a problem" : "no problem"}`);
  };
  wexpect("a good worker", W, false);
  wexpect("REST rule removed", W.replace("rest_route", "rest"), true);
  wexpect("a third cache write", W + " await cache.put(e, f);", true);
  wexpect("a third answer", W + " e.respondWith(z);", true);
  wexpect("only one answer", W.replace(" e.respondWith(y);", ""), true);
  wexpect("localStorage", W + " localStorage.x = 1;", true);
  wexpect("importScripts", W + " importScripts('/x.js');", true);
  wexpect("round 12: the worker subscribes itself", W + " self.registration.pushManager.subscribe(o);", true);
  wexpect("round 12: no push handler", W.replace('\"push\"', '\"pull\"'), true);
  wexpect("round 12: no click handler", W.replace('\"notificationclick\"', '\"x\"'), true);
  wexpect("round 12: a second notification", W + " sw.registration.showNotification(a, b);", true);
  wexpect("round 12: a second window opening", W + " sw.clients.openWindow('/');", true);
  wexpect("round 12: a tap opens an address from the message", W + " u = event.notification.data.url;", true);
  wexpect("round 12: the scope is not the registration's", W.replace("sw.registration.scope", "cfg.scope"), true);
  wexpect("round 12: background sync", W + ' self.addEventListener("sync", f);', true);
  wexpect("the staff app", W + " fetch('/club-admin/');", true);
  return bad;
}

// ---- STAGE-08 round 12 (owner decision D26 part 2): member web push notifications ---------------------------------
// A. problemsFor(PUSH_RULES): the push pieces (backend, sender, campaign, admin page, automatic notifications, the /me/push
//    controller) are constructed ONLY in EVC_Push_Plugin::wire(), reached only through build() <- boot() after
//    `if (!EVC_Push_Feature::enabled()) { return; }`; their names appear only in the push files; no shipped file
//    defines a push constant.
// B. pushProblemsFor():
//    - outgoing HTTP (wp_remote_*, wp_safe_remote_*, curl_*, fsockopen, stream_socket_client, the Requests / WP_Http
//      classes) only in the sender (the one place that re-checks the endpoint allowlist before calling);
//    - EVC_Sql_Push_Subscription_Store constructed only in the backend and named only by the store, the backend and the
//      controller (its result constants);
//    - the private key's constant name only in EVC_Push_Vapid (and the read-only readiness page that checks it);
//    - in every push file: no log / output call (error_log, print_r, var_export, var_dump, trigger_error, syslog,
//      file_put_contents, …) whose arguments carry an endpoint, a key, a subscription row, the payload or the keys;
//    - the campaign, the automatic notifications and the admin page never name 'endpoint', 'p256dh' or 'auth' in a
//      string (their audit entries and pages hold counts only).
//    Round 13 (review N-d / N-e), cheap widenings:
//    - PHP's URL-capable file and stream functions: in a push file (the sender included: it uses wp_remote_post() only)
//      ANY call of file_get_contents / fopen / file / readfile / copy / get_headers / stream_context_create /
//      stream_socket_client / fsockopen / socket_connect / … is refused; in any other shipped file the always-network
//      ones (get_headers, get_meta_tags, stream_context_create, socket_connect) are refused, and the file ones when an
//      argument is a URL string (scheme://, except php://) or a variable named like a URL / endpoint / host;
//    - the Requests library under its namespace (WpOrg\Requests\…), static calls and callable strings
//      ('Requests::post', 'WP_Http::…') outside the sender;
//    - in a push file: variable functions ($f(…)), call_user_func[_array] / forward_static_call[_array], eval, any
//      Reflection* class and Closure::fromCallable;
//    - the sender's real request (EVC_Push_Sender::post) called or named as a callable (static call, 'EVC_Push_Sender::post',
//      array('EVC_Push_Sender', 'post'), array(EVC_Push_Sender::class, 'post')) outside the sender.
//    Round 14 (review f): a variable "looks like a URL" only by a whole word of its name (url, uri, endpoint, host,
//    hostname, href, … as a snake_case / camelCase part; $ghost or $security do not), and property arguments count:
//    file_get_contents($this->url) elsewhere, error_log($this->endpoint) in a push file.
//    LIMITS — these guards are ACCIDENT PREVENTION, NOT AN ANTI-EVASION BOUNDARY. They read tokens of each shipped file
//    on its own; they do not follow data. Code written to evade them (names built by string concatenation outside the
//    push files, variable variables, include of generated files, callbacks handed to WordPress or to a third-party
//    library, a WordPress filter that rewrites a request, …) is not detected. Review stays the boundary; the guards stop
//    the honest mistake (a second HTTP path, a key in a log line) from shipping unnoticed. Also in docs/member-push.md.
const PUSH_BOOTSTRAP = "includes/push/class-evc-push-plugin.php";
const PUSH_FILES = [
  PUSH_BOOTSTRAP,
  "includes/push/class-evc-push-backend.php",
  "includes/push/class-evc-push-sender.php",
  "includes/push/class-evc-push-campaign.php",
  "includes/push/class-evc-push-admin-page.php",
  "includes/push/class-evc-push-auto.php",
  "includes/push/class-evc-rest-me-push-controller.php",
];
export const PUSH_RULES = Object.freeze({
  bootstrap: PUSH_BOOTSTRAP,
  pluginClass: "EVC_Push_Plugin",
  featureClass: "EVC_Push_Feature",
  guarded: ["EVC_Push_Backend", "EVC_Push_Sender", "EVC_Push_Campaign", "EVC_Push_Admin_Page", "EVC_Push_Auto", "EVC_Rest_Me_Push_Controller"],
  forbidden: [],
  allowlist: ["evangelou-club.php", ...PUSH_FILES],
  guardedFiles: PUSH_FILES,
  memberLockFiles: null,
  forbiddenDefines: ["EVC_CLUB_PUSH_ENABLED", "EVC_CLUB_PUSH_AUTO_ACTIVATED", "EVC_CLUB_PUSH_AUTO_EXPIRY", "EVC_CLUB_PUSH_VAPID_PUBLIC", "EVC_CLUB_PUSH_VAPID_PRIVATE", "EVC_CLUB_PUSH_SUBJECT"],
  senderFile: "includes/push/class-evc-push-sender.php",
  backendFile: "includes/push/class-evc-push-backend.php",
  controllerFile: "includes/push/class-evc-rest-me-push-controller.php",
  storeFile: "includes/class-evc-sql-push-subscription-store.php",
  vapidFile: "includes/class-evc-push-vapid.php",
  readinessFile: "includes/admin/class-evc-readiness.php",
  pureFiles: ["includes/class-evc-push-crypto.php", "includes/class-evc-push-vapid.php", "includes/class-evc-push-endpoint.php", "includes/class-evc-push-message.php", "includes/class-evc-sql-push-subscription-store.php"],
  countsOnlyFiles: ["includes/push/class-evc-push-campaign.php", "includes/push/class-evc-push-auto.php", "includes/push/class-evc-push-admin-page.php"],
});
// Round 13: URL-capable file / stream / socket functions (see the header above).
const PUSH_URL_IO = ["file_get_contents", "fopen", "file", "readfile", "copy", "get_headers", "get_meta_tags", "stream_context_create", "stream_socket_client", "fsockopen", "pfsockopen", "socket_create", "socket_connect", "simplexml_load_file", "parse_ini_file", "getimagesize", "exif_read_data", "md5_file", "sha1_file", "hash_file"];
const PUSH_ALWAYS_NETWORK = ["get_headers", "get_meta_tags", "stream_context_create", "socket_connect"];
const PUSH_URL_FILE = ["file_get_contents", "fopen", "file", "readfile", "copy", "simplexml_load_file", "getimagesize", "md5_file", "sha1_file", "hash_file"];
const PUSH_DYNAMIC_CALLS = ["call_user_func", "call_user_func_array", "forward_static_call", "forward_static_call_array", "eval"];
// Round 14 (review f): a name looks like a URL only by a whole word of it (snake_case / camelCase parts), so $ghost,
// $security or $posthost_count no longer match; and a property argument ($this->url, $o?->endpoint) is read as well.
// Round 15 (nit): a method call ($this->url(), $o?->endpoint()) is not a property, exactly as in carriesPushSecret.
const URL_WORDS = new Set(["url", "urls", "uri", "uris", "endpoint", "endpoints", "host", "hosts", "hostname", "href"]);
const nameWords = (name) => name.replace(/^\$/, "").replace(/([a-z0-9])([A-Z])/g, "$1_$2").toLowerCase().split(/[^a-z0-9]+/).filter(Boolean);
const urlName = (name) => nameWords(name).some((w) => URL_WORDS.has(w));
const looksLikeUrl = (t, i, args) => (t.t === "str" && /^[a-z][a-z0-9+.-]*:\/\//i.test(t.v) && !/^php:\/\//i.test(t.v))
  || (t.t === "var" && urlName(t.v))
  || (t.t === "id" && (args?.[i - 1]?.v === "->" || args?.[i - 1]?.v === "?->") && args?.[i + 1]?.v !== "(" && urlName(t.v));
const PUSH_HTTP = ["wp_remote_post", "wp_remote_get", "wp_remote_request", "wp_remote_head", "wp_safe_remote_post", "wp_safe_remote_get", "wp_safe_remote_request", "wp_safe_remote_head", "curl_init", "curl_exec", "curl_multi_exec", "fsockopen", "pfsockopen", "stream_socket_client", "wp_http", "requests", "_wp_http_get_object", "download_url"];
const PUSH_LOG = ["error_log", "print_r", "var_export", "var_dump", "trigger_error", "user_error", "syslog", "file_put_contents", "fwrite", "fputs", "printf", "vprintf", "wp_die", "wp_mail", "set_transient", "update_option", "add_option", "update_user_meta", "do_action", "apply_filters"];
const PUSH_SECRET_VAR = /endpoint|p256dh|auth|private|secret|subscription|^\$row$|payload|^\$keys?$|sending|^\$body$|^\$json$/i;
function carriesPushSecret(args) {
  // Round 14 (review f): a property ($this->endpoint, $s?->keys) counts like the variable of that name.
  return args.some((t, i) => (t.t === "var" && PUSH_SECRET_VAR.test(t.v))
    || (t.t === "id" && (args[i - 1]?.v === "->" || args[i - 1]?.v === "?->") && args[i + 1]?.v !== "(" && PUSH_SECRET_VAR.test("$" + t.v))
    || (t.t === "str" && ["endpoint", "p256dh", "auth", "private", "keys"].includes(t.v.toLowerCase()) && args[i - 1]?.v === "["));
}

export function pushProblemsFor(path, src, rules = PUSH_RULES) {
  const problems = [];
  const tokens = tokenize(src);
  const pushFile = rules.guardedFiles.includes(path) || rules.pureFiles.includes(path);
  tokens.forEach((tk, k) => {
    const v = tk.t === "id" || tk.t === "str" ? bare(tk.v).toLowerCase() : "";
    const call = tokens[k + 1]?.v === "(";
    const member = tokens[k - 1]?.v === "->" || tokens[k - 1]?.v === "::" || tokens[k - 1]?.v === "?->";
    if (tk.t === "id" && path !== rules.senderFile && (PUSH_HTTP.includes(v) || v.startsWith("curl_")) && !member && (call || v === "wp_http" || v === "requests" || tokens[k - 1]?.v.toLowerCase() === "new" || tokens[k + 1]?.v === "::")) {
      problems.push(`${path}: outgoing HTTP (${tk.v}) outside the push sender`);
    }
    if (tk.t === "str" && path !== rules.senderFile && PUSH_HTTP.some((h) => v === h || v.startsWith(h + "("))) problems.push(`${path}: a string names the HTTP function ${tk.v} outside the push sender`);
    if ((tk.t === "id" || tk.t === "str") && v.includes("evc_sql_push_subscription_store") && ![rules.storeFile, rules.backendFile, rules.controllerFile].includes(path)) problems.push(`${path}: names EVC_Sql_Push_Subscription_Store (only the store, the backend and the controller may)`);
    if (tk.t === "id" && v === "new" && bare(tokens[k + 1]?.v ?? "").toLowerCase() === "evc_sql_push_subscription_store" && path !== rules.backendFile) problems.push(`${path}: constructs EVC_Sql_Push_Subscription_Store outside the push backend`);
    if (tk.t === "str" && v.includes("evc_club_push_vapid_private") && path !== rules.vapidFile && path !== rules.readinessFile) problems.push(`${path}: names the VAPID private key constant (only EVC_Push_Vapid may read it)`);
    if (pushFile && tk.t === "id" && call && !member && PUSH_LOG.includes(v) && carriesPushSecret(argumentTokens(tokens, k + 1))) problems.push(`${path}: ${tk.v}() with an endpoint, a key or a payload`);
    if (pushFile && (tk.v === "echo" || tk.v === "print") && carriesPushSecret(tokens.slice(k + 1, tokens.findIndex((t, j) => j > k && t.v === ";")))) problems.push(`${path}: prints an endpoint, a key or a payload`);
    if (rules.countsOnlyFiles.includes(path) && tk.t === "str" && ["endpoint", "p256dh", "auth", "endpoint_hash"].includes(tk.v.toLowerCase())) problems.push(`${path}: names '${tk.v}' (audit entries and pages hold counts only)`);
    // ---- round 13 (N-d / N-e) ----
    const callId = tk.t === "id" && call && !member && tokens[k - 1]?.v.toLowerCase() !== "function";
    if (callId && pushFile && PUSH_URL_IO.includes(v)) problems.push(`${path}: ${tk.v}() in a push file (it can open a URL; push HTTP goes through the sender's wp_remote_post only)`);
    else if (callId && path !== rules.senderFile && PUSH_ALWAYS_NETWORK.includes(v)) problems.push(`${path}: ${tk.v}() makes a network request outside the push sender`);
    else if (callId && path !== rules.senderFile && PUSH_URL_FILE.includes(v) && argumentTokens(tokens, k + 1).some(looksLikeUrl)) problems.push(`${path}: ${tk.v}() with a URL outside the push sender`);
    if (tk.t === "id" && path !== rules.senderFile && (/^wporg\\requests(\\|$)/.test(v) || v.endsWith("\\wp_http") || v.endsWith("\\requests"))) problems.push(`${path}: names the Requests HTTP library (${tk.v}) outside the push sender`);
    if (tk.t === "str" && path !== rules.senderFile && (/^(requests|wp_http)::/.test(v) || v.startsWith("wporg\\requests"))) problems.push(`${path}: a callable string names an HTTP class (${tk.v}) outside the push sender`);
    if (pushFile && tk.t === "var" && call && !member) problems.push(`${path}: variable function ${tk.v}() in a push file`);
    if (pushFile && callId && PUSH_DYNAMIC_CALLS.includes(v)) problems.push(`${path}: ${tk.v}() in a push file`);
    if (pushFile && tk.t === "id" && v.startsWith("reflection")) problems.push(`${path}: ${tk.v} in a push file`);
    if (pushFile && tk.t === "id" && v === "closure" && tokens[k + 1]?.v === "::" && (tokens[k + 2]?.v ?? "").toLowerCase() === "fromcallable") problems.push(`${path}: Closure::fromCallable in a push file`);
    if (path !== rules.senderFile) {
      const nameIsSender = (t) => t && (t.t === "id" || t.t === "str") && bare(t.v).toLowerCase() === "evc_push_sender";
      const isPost = (t) => t && (t.t === "id" || t.t === "str") && t.v.toLowerCase() === "post";
      if (nameIsSender(tk) && tk.t === "id" && tokens[k + 1]?.v === "::" && isPost(tokens[k + 2])) problems.push(`${path}: EVC_Push_Sender::post outside the sender`);
      if (nameIsSender(tk) && tk.t === "id" && tokens[k + 1]?.v === "::" && (tokens[k + 2]?.v ?? "").toLowerCase() === "class" && tokens[k + 3]?.v === "," && isPost(tokens[k + 4])) problems.push(`${path}: callable array (EVC_Push_Sender::class, 'post') outside the sender`);
      if (nameIsSender(tk) && tk.t === "str" && tokens[k + 1]?.v === "," && isPost(tokens[k + 2])) problems.push(`${path}: callable array ('EVC_Push_Sender', 'post') outside the sender`);
      if (tk.t === "str" && v.replace(/\s+/g, "") === "evc_push_sender::post") problems.push(`${path}: callable string 'EVC_Push_Sender::post' outside the sender`);
    }
  });
  return problems;
}

const GOOD_PUSH = `<?php
final class EVC_Push_Plugin {
    public static function boot($seams = null): void {
        if (!EVC_Push_Feature::enabled()) {
            return;
        }
        self::$parts = self::build(array());
    }
    public static function build(array $seams = array()): array { return self::wire($seams); }
    private static function wire(array $seams): array {
        $backend = new EVC_Push_Backend();
        $sender = new EVC_Push_Sender();
        $campaign = new EVC_Push_Campaign($backend, $sender);
        return array(new EVC_Push_Admin_Page($campaign), new EVC_Push_Auto($backend, $sender), new EVC_Rest_Me_Push_Controller($backend));
    }
}`;

function pushSelfTest() {
  const bad = [];
  const R = PUSH_RULES;
  const expect = (label, path, src, want) => {
    const got = problemsFor(path, src, R).length + pushProblemsFor(path, src).length > 0;
    if (got !== want) bad.push(`push ${label}: expected ${want ? "a problem" : "no problem"}`);
  };
  expect("good bootstrap", R.bootstrap, GOOD_PUSH, false);
  expect("gate missing", R.bootstrap, GOOD_PUSH.replace("if (!EVC_Push_Feature::enabled()) {\n            return;\n        }", ""), true);
  expect("gate of another feature", R.bootstrap, GOOD_PUSH.replace("EVC_Push_Feature::enabled", "EVC_Customer_Feature::enabled"), true);
  expect("sender constructed elsewhere", "includes/customer/x.php", "<?php\n$s = new EVC_Push_Sender();", true);
  expect("campaign constructed outside wire()", R.bootstrap, GOOD_PUSH.replace("public static function build(array $seams = array()): array {", "public static function build(array $seams = array()): array { new EVC_Push_Campaign(1, 2);"), true);
  expect("the backend named outside the push files", "includes/staff/x.php", "<?php\nfunction f(EVC_Push_Backend $b) { return $b; }", true);
  expect("build() from another file", "includes/x.php", "<?php\nEVC_Push_Plugin::build();", true);
  expect("defines the flag", "includes/x.php", "<?php\ndefine('EVC_CLUB_PUSH_ENABLED', true);", true);
  expect("defines the private key", "includes/x.php", "<?php\ndefine('EVC_CLUB_PUSH_VAPID_PRIVATE', 'x');", true);
  expect("the sender may post", R.senderFile, "<?php\n$r = wp_remote_post($url, $args);", false);
  expect("HTTP from the campaign", "includes/push/class-evc-push-campaign.php", "<?php\n$r = wp_remote_post($url, $args);", true);
  expect("HTTP from anywhere else", "includes/customer/x.php", "<?php\n$r = wp_safe_remote_get($u);", true);
  expect("curl elsewhere", "includes/x.php", "<?php\n$c = curl_init($u);", true);
  expect("WP_Http elsewhere", "includes/x.php", "<?php\n$h = new WP_Http();", true);
  expect("Requests elsewhere", "includes/x.php", "<?php\n$r = Requests::post($u);", true);
  expect("HTTP through a string callable", "includes/x.php", "<?php\ncall_user_func('wp_remote_post', $u);", true);
  expect("the store built by the backend", R.backendFile, "<?php\n$s = new EVC_Sql_Push_Subscription_Store($db);", false);
  expect("the store built by the campaign", "includes/push/class-evc-push-campaign.php", "<?php\n$s = new EVC_Sql_Push_Subscription_Store($db);", true);
  expect("the controller reads the store's constants", R.controllerFile, "<?php\n$x = EVC_Sql_Push_Subscription_Store::CREATED;", false);
  expect("the store named elsewhere", "includes/admin/x.php", "<?php\n$x = EVC_Sql_Push_Subscription_Store::CREATED;", true);
  expect("the private key read elsewhere", "includes/push/class-evc-push-backend.php", "<?php\n$k = constant('EVC_CLUB_PUSH_VAPID_PRIVATE');", true);
  expect("the VAPID class reads it", R.vapidFile, "<?php\nconst PRIVATE_CONSTANT = 'EVC_CLUB_PUSH_VAPID_PRIVATE';", false);
  expect("an endpoint logged", "includes/push/class-evc-push-sender.php", "<?php\nerror_log('push ' . $endpoint);", true);
  expect("a row logged", "includes/push/class-evc-push-campaign.php", "<?php\nerror_log(print_r($row, true));", true);
  expect("a key in a log array", "includes/push/class-evc-push-auto.php", "<?php\nerror_log('x' . $sub['auth']);", true);
  expect("the payload printed", "includes/push/class-evc-push-admin-page.php", "<?php\necho $payload;", true);
  expect("a fixed reason logged", "includes/push/class-evc-push-backend.php", "<?php\nerror_log('Evangelou Club: push unavailable (' . $reason . ').');", false);
  expect("an endpoint in the audit", "includes/push/class-evc-push-campaign.php", "<?php\n$entry = array('endpoint' => $x);", true);
  expect("auth in the admin page", "includes/push/class-evc-push-admin-page.php", "<?php\n$x = $r['auth'];", true);
  // round 13 (N-d / N-e)
  const C = "includes/push/class-evc-push-campaign.php";
  expect("r13 file_get_contents in a push file", C, "<?php\n$x = file_get_contents($p);", true);
  expect("r13 fopen in the sender", R.senderFile, "<?php\n$h = fopen($u, 'r');", true);
  expect("r13 get_headers in a pure push file", "includes/class-evc-push-endpoint.php", "<?php\n$h = get_headers('https://x');", true);
  expect("r13 get_headers anywhere", "includes/customer/x.php", "<?php\n$h = get_headers($a);", true);
  expect("r13 stream_context_create anywhere", "includes/x.php", "<?php\n$c = stream_context_create(array());", true);
  expect("r13 fsockopen anywhere", "includes/x.php", "<?php\n$s = fsockopen($h, 443);", true);
  expect("r13 stream_socket_client anywhere", "includes/x.php", "<?php\n$s = stream_socket_client('tls://a:443');", true);
  expect("r13 file_get_contents of a URL string", "includes/x.php", "<?php\n$x = file_get_contents('https://fcm.googleapis.com/x');", true);
  expect("r13 file_get_contents of a URL variable", "includes/x.php", "<?php\n$x = file_get_contents($endpoint);", true);
  expect("r13 fopen of an http string", "includes/x.php", "<?php\n$h = fopen('http://a/' . $p, 'r');", true);
  expect("r13 file_get_contents of a local file elsewhere", "includes/customer/x.php", "<?php\n$x = file_get_contents($script_file);", false);
  expect("r13 php://input elsewhere", "includes/x.php", "<?php\n$x = file_get_contents('php://input');", false);
  expect("r13 namespaced Requests static call", "includes/x.php", "<?php\n$r = \\WpOrg\\Requests\\Requests::request($u);", true);
  expect("r13 namespaced Requests use", "includes/x.php", "<?php\nuse WpOrg\\Requests\\Requests;", true);
  expect("r13 Requests callable string", "includes/x.php", "<?php\nadd_action('x', 'Requests::post');", true);
  expect("r13 WP_Http callable string", "includes/x.php", "<?php\n$f = 'WP_Http::request';", true);
  expect("r13 variable function in a push file", C, "<?php\n$f = 'x';\n$f($u);", true);
  expect("r13 seam call through a property is fine", C, "<?php\n$t = ($this->timer)();", false);
  expect("r13 call_user_func in a push file", "includes/push/class-evc-push-auto.php", "<?php\ncall_user_func($cb, 1);", true);
  expect("r13 call_user_func_array in a push file", C, "<?php\ncall_user_func_array(array($o, 'm'), $a);", true);
  expect("r13 eval in a push file", C, "<?php\neval($code);", true);
  expect("r13 ReflectionFunction in a push file", C, "<?php\n$r = new ReflectionFunction('f');", true);
  expect("r13 ReflectionProperty in a push file", "includes/push/class-evc-push-admin-page.php", "<?php\n$r = new ReflectionProperty($o, 'campaign');", true);
  expect("r13 Closure::fromCallable in a push file", C, "<?php\n$f = Closure::fromCallable('x');", true);
  expect("r13 the sender's post called from the campaign", C, "<?php\n$s = EVC_Push_Sender::post($u, $h, $b);", true);
  expect("r13 the sender's post as a callable array", "includes/push/class-evc-push-plugin.php", "<?php\n$h = array('EVC_Push_Sender', 'post');", true);
  expect("r13 the sender's post as ::class callable", C, "<?php\n$h = array(EVC_Push_Sender::class, 'post');", true);
  expect("r13 the sender's post as a string", "includes/push/class-evc-push-auto.php", "<?php\n$h = 'EVC_Push_Sender::post';", true);
  // round 14 (review f)
  expect("r14 a ghost is not a host", "includes/customer/x.php", "<?php\n$x = file_get_contents($ghost);", false);
  expect("r14 security is not a uri", "includes/customer/x.php", "<?php\n$x = file_get_contents($security_file);", false);
  expect("r14 a word inside a word is not a host", "includes/customer/x.php", "<?php\n$x = file($posthost_count);", false);
  expect("r14 a host variable is", "includes/customer/x.php", "<?php\n$x = file_get_contents($push_host);", true);
  expect("r14 a camelCase URL variable is", "includes/customer/x.php", "<?php\n$x = file_get_contents($remoteUrl);", true);
  expect("r14 file($this->url) elsewhere", "includes/customer/x.php", "<?php\n$x = file($this->url);", true);
  expect("r14 fopen($o?->endpoint) elsewhere", "includes/x.php", "<?php\n$h = fopen($o?->endpoint, 'r');", true);
  expect("r14 file($this->script_file) elsewhere is fine", "includes/customer/x.php", "<?php\n$x = file($this->script_file);", false);
  expect("r14 file($this->url) in a push file", "includes/push/class-evc-push-backend.php", "<?php\n$x = file($this->url);", true);
  expect("r14 a property endpoint logged in a push file", "includes/push/class-evc-push-backend.php", "<?php\nerror_log('x ' . $this->endpoint);", true);
  expect("r14 a property key logged in a push file", R.senderFile, "<?php\nerror_log(print_r($this->keys, true));", true);
  expect("r14 a fixed reason through a property call is fine", "includes/push/class-evc-push-backend.php", "<?php\nerror_log('Evangelou Club: push unavailable (' . $this->reason() . ').');", false);
  // round 15 (nit): a method call is not a property argument (as in carriesPushSecret)
  expect("r15 file($this->url()) elsewhere is a method call", "includes/customer/x.php", "<?php\n$x = file($this->url());", false);
  expect("r15 fopen($o?->endpoint()) elsewhere is a method call", "includes/x.php", "<?php\n$h = fopen($o?->endpoint(), 'r');", false);
  expect("r15 file($this->url) next to a method call still is", "includes/customer/x.php", "<?php\n$x = file($this->url, $this->flags());", true);
  expect("r13 the sender names its own post", R.senderFile, "<?php\n$this->http = $http ?? array(__CLASS__, 'post');", false);
  expect("r13 the sender's constants elsewhere", C, "<?php\nif ($o === EVC_Push_Sender::SENT) { }", false);
  return bad;
}

export function selfTest() {
  const bad = [];
  const expect = (label, path, src, want) => {
    const got = problemsFor(path, src, OPTS).length > 0;
    if (got !== want) bad.push(`${label}: expected ${want ? "a problem" : "no problem"}`);
  };
  const boot = OPTS.bootstrap;
  expect("good bootstrap", boot, GOOD, false);
  expect("construction elsewhere", "includes/x.php", "<?php\n$a = new EVC_Wc_Completion_Confirmer();", true);
  expect("leading backslash", "includes/x.php", "<?php\n$a = new \\EVC_Wc_Completion_Confirmer();", true);
  expect("newline between new and class", "includes/x.php", "<?php\n$a = new\n   EVC_Bacs_Issuance_Guard ();", true);
  expect("upper-case NEW", "includes/x.php", "<?php\n$a = NEW EVC_Bacs_Issuance_Guard();", true);
  expect("forbidden everywhere", boot, GOOD.replace("new EVC_Wc_Completion_Confirmer()", "new EVC_Bacs_Payment_Issuer()"), true);
  expect("mention in a comment only", "includes/x.php", "<?php\n// new EVC_Wc_Completion_Confirmer()\n$s = 'x'; /* new EVC_Bacs_Issuance_Guard */", false);
  expect("construction text inside a string in an allowed file is not a construction", "includes/allowed.php", "<?php\n$s = 'new EVC_Wc_Completion_Confirmer()';", false);
  expect("construction outside wire() in the bootstrap", boot, GOOD.replace("public static function build($x) { return self::wire($x); }", "public static function build($x) { new EVC_Wc_Completion_Confirmer(); return self::wire($x); }"), true);
  expect("gate only in a comment", boot, GOOD.replace("if (!EVC_Wc_Completion_Feature::enabled()) {\n            return;\n        }", "// if (!EVC_Wc_Completion_Feature::enabled()) { return; }"), true);
  expect("gate not first", boot, GOOD.replace("public static function boot(): void {", "public static function boot(): void {\n        $p = self::build(1);"), true);
  expect("gate negation dropped", boot, GOOD.replace("if (!EVC_Wc_Completion_Feature", "if (EVC_Wc_Completion_Feature"), true);
  expect("gate without return", boot, GOOD.replace("return;\n        }\n        $p", "$q = 1;\n        }\n        $p"), true);
  expect("build() called from another file", "includes/x.php", "<?php\n$p = EVC_Wc_Completion_Plugin::build(1);", true);
  expect("build() called from the bootstrap outside boot()", boot, GOOD.replace("private static function wire", "public static function other() { return self::build(2); }\n    private static function wire"), true);
  expect("wire() called outside build()", boot, GOOD.replace("public static function build($x) { return self::wire($x); }", "public static function build($x) { return 1; } public static function other() { return self::wire(1); }"), true);
  const other = "includes/x.php";
  expect("allowed file may name a guarded class", "includes/allowed.php", "<?php\nfunction f(EVC_Wc_Completion_Confirmer $c) { return $c; }", false);
  expect("type hint outside the allowlist", other, "<?php\nfunction f(EVC_Wc_Completion_Confirmer $c) { return $c; }", true);
  expect("new $cls with the name in a string", other, "<?php\n$cls = 'EVC_Wc_Completion_Confirmer';\n$a = new $cls();", true);
  expect("new $cls with the name elsewhere", "includes/allowed.php", "<?php\n$a = new $cls();", true);
  expect("use ... as alias", other, "<?php\nuse EVC_Wc_Completion_Confirmer as G;\n$a = new G();", true);
  expect("class_alias", "includes/allowed.php", "<?php\nclass_alias('EVC_Wc_Completion_Confirmer', 'G');", true);
  expect("ReflectionClass::newInstance", "includes/allowed.php", "<?php\n$r = new ReflectionClass($n); $r->newInstance();", true);
  expect("unserialize", "includes/allowed.php", "<?php\n$o = unserialize($s);", true);
  expect("call_user_func with the plugin name", other, "<?php\ncall_user_func(array('EVC_Wc_Completion_Plugin', 'build'), 1);", true);
  expect("dynamic static call", "includes/allowed.php", "<?php\n$k::build(1);", true);
  expect("name inside an interpolated string", other, '<?php\n$a = "x {$y} EVC_Bacs_Issuance_Guard";', true);
  expect("new (expression)", "includes/allowed.php", "<?php\n$a = new ($x . 'Y')();", true);
  expect("new self inside a guarded class", "includes/customer/class-evc-wc-completion-confirmer.php", "<?php\nfinal class EVC_Wc_Completion_Confirmer { public static function make() { return new self(); } }", true);
  expect("new self elsewhere is fine", "includes/allowed.php", "<?php\nfinal class R { public static function make() { return new self(); } }", false);
  expect("duplicate wire()", boot, GOOD.replace("private static function wire($x)", "private static function wire2($x) { return 1; }\n    private static function wire($x)").replace("wire2", "wire"), true);
  expect("member lock elsewhere", other, "<?php\n$l = EVC_Member_Lock::acquire($db, 1, 0);", true);
  expect("member lock with a leading backslash", other, "<?php\n$l = \\EVC_Member_Lock :: acquire($db, 1, 0);", true);
  expect("member lock where allowed", "includes/customer/class-evc-wc-completion-confirmer.php", "<?php\n$l = EVC_Member_Lock::acquire($db, 1, 0);", false);
  expect("member lock through a string", other, "<?php\ncall_user_func(array('EVC_Member_Lock', 'acquire'), $db, 1, 0);", true);
  expect("defines the test constant", other, "<?php\ndefine('EVC_TEST_WC_COMPLETION_CONSTRUCTION', true);", true);
  expect("array callable to build() in an allowlisted file", "includes/allowed.php", "<?php\nadd_action('init', array('EVC_Wc_Completion_Plugin', 'build'));", true);
  expect("string callable to wire() in an allowlisted file", "includes/allowed.php", "<?php\nadd_action('init', 'EVC_Wc_Completion_Plugin::wire');", true);
  expect("entrypoint may hook boot() and deactivate()", "includes/allowed.php", "<?php\nadd_action('plugins_loaded', array('EVC_Wc_Completion_Plugin', 'boot'));\nregister_deactivation_hook(__FILE__, array('EVC_Wc_Completion_Plugin', 'deactivate'));", false);
  return bad.concat(migrationsSelfTest(), redemptionSelfTest(), memberSyncSelfTest(), preparedSelfTest(), qrSelfTest(), fluentcrmSelfTest(), onboardingSelfTest(), adminToolsSelfTest(), pwaSelfTest(), pushSelfTest());
}

// ---- STAGE-08 rule set: the Club admin pages, only behind EVC_CLUB_ADMIN_TOOLS_ENABLED --------------------------------
// A. The STAGE-03D construction rules with their own bootstrap and gate: the till-account service and page and the
//    readiness checks and page are constructed only inside EVC_Admin_Tools_Plugin::wire() (reached only via build() <-
//    boot() after `if (!EVC_Admin_Tools_Feature::enabled()) { return; }`); their names appear only in the allowlisted
//    files; no shipped file defines EVC_CLUB_ADMIN_TOOLS_ENABLED.
// B. adminToolsProblemsFor(), the READ-ONLY files (the readiness checks and page, and the till-account reader):
//    - no call of a WordPress writer or hook (READ_ONLY_FORBIDDEN: options, user options, meta, transients, cache,
//      users, posts (also wp_trash_post), sign-out (wp_logout, wp_clear_auth_cookie, wp_destroy_*_sessions), cron,
//      hooks, rewrite rules, error_log) and no string naming one (so no callable string reaches it through
//      call_user_func / a variable function);
//    - no call_user_func*, forward_static_call*, no `$var::` (also refused in every file by problemsFor()), and no
//      variable method name ($o->$m(), $o->{$m}(), $c::$m());
//    - no call of a WRITER as a method or static method, whatever the receiver (READ_ONLY_METHODS: ->query / insert /
//      update / delete / replace / execute / exec_raw / transactional / save / migrate / apply, and create,
//      set_password, set_disabled, revoke_all, revoke_current, record_login, process, handle_load, prune_activity,
//      on_logout, handle_admin_revoke, delete_all_activity, revoke_all_staff_sessions, destroy, destroy_all,
//      destroy_others, destroy_all_for_all_users, register, unregister, the role/capability writers), nor a string
//      naming one;
//    - ALLOWLIST of EVC_* static calls: EVC_X::m(…), the string callable 'EVC_X::m' and the pair array('EVC_X', 'm')
//      only for READ_ONLY_STATIC_ALLOW (the read-only helpers the files actually use); a pair whose method is not a
//      string literal, EVC_X::class and `new EVC_X` are refused; so are static::m() / parent::m(). EVC_X::CONSTANT and
//      self::m() stay allowed (self:: is the file's own class, itself under these rules);
//    - no string carrying an SQL write (INSERT / REPLACE in any form, UPDATE … SET (also multi-table and
//      {$wpdb->options}), DELETE … FROM, ALTER / DROP / CREATE / TRUNCATE / RENAME TABLE …, GRANT, REVOKE,
//      INTO OUTFILE / DUMPFILE, LOAD DATA, LOCK TABLES, SET GLOBAL / PASSWORD, CALL), whatever method receives it
//      (e.g. $wpdb->get_var("DELETE …")).
//    Not covered statically (documented): a writer name, a class or method name or an SQL statement assembled at run
//    time from pieces; a callable handed in from outside the file (the constructor seams).
// C. The till-account files (service and page) never let a generated password out: no error_log / print_r /
//    var_export / var_dump / trigger_error / syslog / file_put_contents / fwrite / fputs / wp_mail / set_transient /
//    set_site_transient / setcookie / wp_cache_set / add_query_arg / wp_redirect / wp_safe_redirect at all, and no
//    update_option / add_option / update_user_meta / add_user_meta / update_user_option / wp_cache_add /
//    wp_cache_replace / header / wp_die / do_action / apply_filters, and no call of a variable or expression
//    ($f(…), ($this->header)(…)), whose arguments carry the password: a variable whose name contains "pass", a
//    'password' string such as $result['password'], or the result array itself ($result / $this->result, unless
//    indexed by a string key other than 'password', e.g. $result['code']).
// D. Only the service file writes a password (wp_set_password).
const ADMIN_TOOLS_BOOTSTRAP = "includes/admin/class-evc-admin-tools-plugin.php";
const STAFF_ACCOUNT_READER = "includes/admin/class-evc-staff-account-reader.php";
export const ADMIN_TOOLS_RULES = Object.freeze({
  bootstrap: ADMIN_TOOLS_BOOTSTRAP,
  pluginClass: "EVC_Admin_Tools_Plugin",
  featureClass: "EVC_Admin_Tools_Feature",
  guarded: ["EVC_Staff_Account_Service", "EVC_Staff_Account_Page", "EVC_Readiness", "EVC_Readiness_Page"],
  forbidden: [],
  allowlist: [
    "evangelou-club.php",
    ADMIN_TOOLS_BOOTSTRAP,
    "includes/admin/class-evc-staff-account-service.php",
    "includes/admin/class-evc-staff-account-page.php",
    "includes/admin/class-evc-readiness.php",
    "includes/admin/class-evc-readiness-page.php",
  ],
  guardedFiles: [
    ADMIN_TOOLS_BOOTSTRAP,
    STAFF_ACCOUNT_READER,
    "includes/admin/class-evc-staff-account-service.php",
    "includes/admin/class-evc-staff-account-page.php",
    "includes/admin/class-evc-readiness.php",
    "includes/admin/class-evc-readiness-page.php",
  ],
  memberLockFiles: null,
  forbiddenDefines: ["EVC_CLUB_ADMIN_TOOLS_ENABLED"],
  readOnlyFiles: ["includes/admin/class-evc-readiness.php", "includes/admin/class-evc-readiness-page.php", STAFF_ACCOUNT_READER],
  secretFiles: ["includes/admin/class-evc-staff-account-service.php", "includes/admin/class-evc-staff-account-page.php"],
  passwordFile: "includes/admin/class-evc-staff-account-service.php",
});
const READ_ONLY_FORBIDDEN = ["update_option", "add_option", "delete_option", "update_site_option", "add_site_option", "delete_site_option",
  "update_user_option", "delete_user_option", "update_user_meta", "add_user_meta", "delete_user_meta", "update_post_meta", "add_post_meta",
  "delete_post_meta", "update_term_meta", "add_term_meta", "delete_term_meta", "update_metadata", "add_metadata", "delete_metadata",
  "set_transient", "delete_transient", "set_site_transient", "delete_site_transient", "wp_cache_set", "wp_cache_add", "wp_cache_replace",
  "wp_cache_delete", "wp_cache_incr", "wp_cache_decr", "wp_cache_flush", "wp_insert_user", "wp_update_user", "wp_delete_user", "wp_create_user",
  "wp_set_password", "wp_set_current_user", "wp_set_auth_cookie", "wp_clear_auth_cookie", "wp_logout", "wp_destroy_current_session",
  "wp_destroy_other_sessions", "wp_destroy_all_sessions", "wp_insert_post", "wp_update_post", "wp_delete_post", "wp_trash_post",
  "wp_untrash_post", "add_action", "add_filter", "remove_action", "remove_filter", "do_action", "do_action_ref_array", "wp_schedule_event",
  "wp_schedule_single_event", "wp_clear_scheduled_hook", "wp_unschedule_event", "flush_rewrite_rules", "error_log", "call_user_func",
  "call_user_func_array", "forward_static_call", "forward_static_call_array"];
// Writers of a database handle (also EVC_Migrator->migrate / a migration's apply), of the till account
// (EVC_Staff_Account_Service, EVC_Staff_Account_Page, EVC_Staff_Session::delete_all_activity / revoke_all_staff_sessions /
// revoke_current / prune_activity / on_logout / handle_admin_revoke, EVC_Staff_Auth::set_disabled, EVC_Staff_Role
// register / unregister) and of WordPress session tokens: refused as ->method() and ::method() in the read-only files,
// whatever the receiver.
const READ_ONLY_METHODS = ["query", "insert", "update", "delete", "replace", "execute", "exec_raw", "transactional", "save", "migrate", "apply",
  "create", "set_password", "set_disabled", "revoke_all", "revoke_current", "record_login", "process", "handle_load", "prune_activity",
  "on_logout", "handle_admin_revoke", "delete_all_activity", "revoke_all_staff_sessions", "destroy", "destroy_all", "destroy_others",
  "destroy_all_for_all_users", "register", "unregister", "add_cap", "remove_cap", "set_role", "add_role", "remove_role"];
// The ONLY EVC_* static methods the read-only files may call or name as a callable (lower case "class::method"):
// read-only helpers (feature flags, configuration readers, environment probes, the lookup-key check, the till-account
// reader and access check) and the Club DB connection the readiness checks use for SELECTs.
export const READ_ONLY_STATIC_ALLOW = Object.freeze([
  "evc_admin_tools_feature::assert_constructible", "evc_admin_tools_feature::is_enabled_value",
  "evc_customer_feature::is_enabled_value", "evc_staff_feature::is_enabled_value", "evc_club_redemption_feature::is_enabled_value",
  "evc_onboarding_feature::is_enabled_value", "evc_onboarding_feature::enabled", "evc_fluentcrm_feature::is_enabled_value",
  "evc_fluentcrm_feature::enabled", "evc_fluentcrm_feature::tag_config", "evc_wc_completion_feature::is_enabled_value",
  "evc_club_migrations_feature::is_enabled_value", "evc_club_migrations_feature::target_version",
  "evc_db_config::from_constants", "evc_club_db::connect", "evc_commerce_source_config::from_constants",
  "evc_wp_commerce_pmpro_reader::commerce_plugins_loaded", "evc_wp_commerce_pmpro_reader::environment_conflicts",
  "evc_bacs_payment_issuer::site_problem", "evc_onboarding_shell::same_site_url", "evc_fluentcrm_plugin::problem",
  "evc_club_qr_services::is_lookup_secret", "evc_qr_token::is_derivation_secret", "evc_staff_account_reader::accounts", "evc_staff_account_service::may_manage",
  "evc_staff_auth::is_disabled", "evc_staff_role::is_restricted_staff_account",
  // Round 9 (D24): the readiness row «Καταγραφή προετοιμασίας καφέ» (pure catalogue check, reads nothing).
  "evc_club_prep_catalogue_authority::is_authorised",
  // Round 10 (LOW-1): the pure reader of the SHOW GRANTS lines the readiness row read (runs nothing itself).
  "evc_db_grants::table_privileges",
  // Round 12 (D26 part 2): the push rows (the VAPID key check reads and signs nothing; the flag parser).
  "evc_push_vapid::keys_problem", "evc_push_feature::is_enabled_value",
]);
const SQL_WRITE = new RegExp([
  String.raw`\binsert\b`, // INSERT INTO, INSERT t VALUES, INSERT LOW_PRIORITY / DELAYED / IGNORE …
  String.raw`\breplace\s+(low_priority\s+|delayed\s+)*(into\s+)?[\w\`{$]`, // REPLACE INTO t, REPLACE t …
  String.raw`\bupdate\b[^;]*?\bset\b`, // UPDATE t SET, UPDATE {$wpdb->options} SET, UPDATE t1, t2 SET, UPDATE LOW_PRIORITY …
  String.raw`\bdelete\b[^;]*?\bfrom\b`,
  String.raw`\b(alter|drop|create|truncate|rename)\s+(temporary\s+|definer\s*=\s*\S+\s+)*(table|tables|database|schema|index|unique|fulltext|view|trigger|procedure|function|event|user|role)\b`,
  String.raw`\bgrant\s`, String.raw`\brevoke\s+\w`, String.raw`\binto\s+(outfile|dumpfile)\b`, String.raw`\bload\s+(data|xml)\b`,
  String.raw`\block\s+tables?\b`, String.raw`\bset\s+(global|persist|persist_only|password)\b`, String.raw`\bcall\s+[\w\`]+\s*\(`,
].join("|"), "i");
const SECRET_FORBIDDEN = ["error_log", "print_r", "var_export", "var_dump", "trigger_error", "user_error", "syslog", "file_put_contents", "fwrite",
  "fputs", "wp_mail", "set_transient", "set_site_transient", "setcookie", "wp_cache_set", "add_query_arg", "wp_redirect", "wp_safe_redirect"];
const SECRET_WITH_PASSWORD = ["update_option", "add_option", "update_site_option", "update_user_meta", "add_user_meta", "update_user_option",
  "wp_cache_add", "wp_cache_replace", "header", "wp_die", "do_action", "do_action_ref_array", "apply_filters", "apply_filters_ref_array"];
/** The tokens of the argument list that starts at tokens[open] === "(" (to its matching ")"). */
function argumentTokens(tokens, open) {
  const out = [];
  let depth = 0;
  for (let j = open; j < tokens.length; j++) {
    if (tokens[j].v === "(") depth++;
    else if (tokens[j].v === ")") { depth--; if (depth === 0) break; }
    if (j > open) out.push(tokens[j]);
  }
  return out;
}
/**
 * Whether an argument list carries the generated password: a variable whose name contains "pass", a 'password'
 * string (e.g. $result['password']), or the result array that holds it ($result / $this->result / $x->result) unless
 * it is indexed by a string key other than 'password' (e.g. $result['code']).
 */
function carriesPassword(args) {
  return args.some((a, i) => {
    if (a.t === "var" && a.v.toLowerCase().includes("pass")) return true;
    if (a.t === "str" && a.v.toLowerCase().includes("password")) return true;
    const isResult = (a.t === "var" && a.v.toLowerCase() === "$result") || (a.t === "id" && a.v.toLowerCase() === "result" && args[i - 1]?.v === "->");
    if (!isResult) return false;
    const indexedSafely = args[i + 1]?.v === "[" && args[i + 2]?.t === "str" && args[i + 3]?.v === "]" && !args[i + 2].v.toLowerCase().includes("password");
    return !indexedSafely;
  });
}

export function adminToolsProblemsFor(path, src, rules = ADMIN_TOOLS_RULES) {
  const problems = [];
  const tokens = tokenize(src);
  const readOnly = rules.readOnlyFiles.includes(path);
  const allowed = (cls, m) => READ_ONLY_STATIC_ALLOW.includes(`${cls}::${m}`.toLowerCase());
  tokens.forEach((tk, k) => {
    if (readOnly && tk.t === "str") {
      const sv = bare(tk.v).toLowerCase();
      if (READ_ONLY_FORBIDDEN.includes(sv) || READ_ONLY_METHODS.includes(sv)) problems.push(`${path}: a string names the writer '${tk.v}' in a read-only file`);
      if (SQL_WRITE.test(tk.v)) problems.push(`${path}: an SQL write in a string in a read-only file`);
      // String callables of an EVC_* class: 'EVC_X::m' and array('EVC_X', 'm') only for READ_ONLY_STATIC_ALLOW.
      const dc = /^(evc_\w+)\s*::\s*(\w+)$/i.exec(bare(tk.v));
      if (dc && !allowed(dc[1], dc[2])) problems.push(`${path}: the string callable '${tk.v}' is not an allowed read-only helper`);
      if (/^evc_\w+$/i.test(bare(tk.v)) && /[a-z]/.test(bare(tk.v)) && tokens[k + 1]?.v === ",") {
        const m = tokens[k + 2];
        if (m?.t !== "str" || !allowed(bare(tk.v), m.v)) problems.push(`${path}: the callable array('${tk.v}', ${m?.t === "str" ? `'${m.v}'` : "…"}) is not an allowed read-only helper`);
      }
    }
    if (readOnly && tk.t === "var" && tokens[k + 1]?.v === "::") problems.push(`${path}: dynamic static call ${tk.v}:: in a read-only file`);
    if (readOnly && (tk.v === "->" || tk.v === "::" || tk.v === "?->") && (tokens[k + 1]?.t === "var" || tokens[k + 1]?.v === "{")) {
      problems.push(`${path}: variable method name ${tk.v}${tokens[k + 1].v} in a read-only file`);
    }
    // Password leaks through a call of a variable or an expression: $f($password), ($this->header)($result['password']).
    if (rules.secretFiles.includes(path) && (tk.t === "var" || tk.v === ")") && tokens[k + 1]?.v === "(" && tokens[k - 1]?.v !== "->" && tokens[k - 1]?.v !== "::") {
      if (tk.t === "var" ? true : !["if", "elseif", "while", "for", "foreach", "switch", "match", "catch", "array", "isset", "empty", "list", "function", "fn", "use"].includes((tokens[openOf(tokens, k) - 1]?.v ?? "").toLowerCase())) {
        if (carriesPassword(argumentTokens(tokens, k + 1))) problems.push(`${path}: a callable is called with the password`);
      }
    }
    if (tk.t !== "id") return;
    const v = bare(tk.v).toLowerCase();
    const call = tokens[k + 1]?.v === "(";
    const member = tokens[k - 1]?.v === "->" || tokens[k - 1]?.v === "::" || tokens[k - 1]?.v === "?->";
    if (readOnly) {
      if (call && !member && READ_ONLY_FORBIDDEN.includes(v)) problems.push(`${path}: writes or hooks (${tk.v}) in a read-only file`);
      if (call && member && READ_ONLY_METHODS.includes(v)) problems.push(`${path}: calls ${tokens[k - 1].v}${tk.v}() in a read-only file`);
      const staticCall = (name) => tokens[k + 1]?.v === "::" && tokens[k + 2]?.t === "id" && tokens[k + 2].v.toLowerCase() === name && tokens[k + 3]?.v === "(";
      if (v === "evc_staff_account_service" && !staticCall("may_manage")) problems.push(`${path}: names EVC_Staff_Account_Service other than ::may_manage() in a read-only file`);
      if (v === "evc_admin_tools_plugin") problems.push(`${path}: names EVC_Admin_Tools_Plugin (its parts hold the service) in a read-only file`);
      if (v === "evc_club_qr_services" && !staticCall("is_lookup_secret")) problems.push(`${path}: names EVC_Club_Qr_Services other than ::is_lookup_secret() in a read-only file`);
      // The allowlist of EVC_* static calls (class names have a lower-case letter; EVC_CLUB_* constants do not).
      if (/^evc_/.test(v) && /[a-z]/.test(bare(tk.v)) && tokens[k + 1]?.v === "::" && !member) {
        const m = tokens[k + 2];
        if (m?.t === "id" && m.v.toLowerCase() === "class") problems.push(`${path}: ${tk.v}::class in a read-only file`);
        else if (m?.t === "id" && tokens[k + 3]?.v === "(" && !allowed(v, m.v)) problems.push(`${path}: ${tk.v}::${m.v}() is not an allowed read-only helper`);
      }
      if (/^evc_/.test(v) && /[a-z]/.test(bare(tk.v)) && tokens[k - 1]?.v.toLowerCase() === "new") problems.push(`${path}: new ${tk.v} in a read-only file`);
      if ((v === "static" || v === "parent") && tokens[k + 1]?.v === "::" && tokens[k + 3]?.v === "(") problems.push(`${path}: ${tk.v}::${tokens[k + 2]?.v}() in a read-only file (only self:: is allowed)`);
    }
    if (rules.secretFiles.includes(path) && call && !member) {
      if (SECRET_FORBIDDEN.includes(v)) problems.push(`${path}: ${tk.v}() could leak a generated password`);
      if (SECRET_WITH_PASSWORD.includes(v) && carriesPassword(argumentTokens(tokens, k + 1))) problems.push(`${path}: ${tk.v}() with the password`);
    }
    if (path !== rules.passwordFile && call && !member && v === "wp_set_password") problems.push(`${path}: sets a password outside the till-account service`);
  });
  return problems;
}
/** The index of the "(" matching tokens[close] === ")". */
function openOf(tokens, close) {
  let depth = 0;
  for (let j = close; j >= 0; j--) {
    if (tokens[j].v === ")") depth++;
    else if (tokens[j].v === "(") { depth--; if (depth === 0) return j; }
  }
  return -1;
}

const GOOD_ADMIN_TOOLS = `<?php
final class EVC_Admin_Tools_Plugin {
    public static function boot($seams = null): void {
        if (!EVC_Admin_Tools_Feature::enabled()) {
            return;
        }
        $p = self::build();
    }
    public static function build() { return self::wire(); }
    private static function wire() {
        $s = new EVC_Staff_Account_Service();
        $r = new EVC_Readiness();
        return array(new EVC_Staff_Account_Page($s), new EVC_Readiness_Page($r));
    }
}`;

function adminToolsSelfTest() {
  const bad = [];
  const A = ADMIN_TOOLS_RULES;
  const expect = (label, path, src, want) => {
    const got = problemsFor(path, src, A).length + adminToolsProblemsFor(path, src).length + onboardingProblemsFor(path, src).length + qrProblemsFor(path, src).length > 0;
    if (got !== want) bad.push(`admin tools ${label}: expected ${want ? "a problem" : "no problem"}`);
  };
  // Only adminToolsProblemsFor(): a rule of the read-only / password lists, so deleting it fails its self-test even
  // when a general rule of problemsFor() would also catch the fixture.
  const expectOwn = (label, path, src, want) => {
    const got = adminToolsProblemsFor(path, src).length > 0;
    if (got !== want) bad.push(`admin tools (own rule) ${label}: expected ${want ? "a problem" : "no problem"}`);
  };
  // The fixtures name the files by path (not by their index in the rule lists) and check that the lists hold them.
  const R = "includes/admin/class-evc-readiness.php";
  const RP = "includes/admin/class-evc-readiness-page.php";
  const READER = STAFF_ACCOUNT_READER;
  const PAGE = "includes/admin/class-evc-staff-account-page.php";
  for (const f of [R, RP, READER]) if (!A.readOnlyFiles.includes(f)) bad.push(`admin tools: ${f} is not a read-only file`);
  for (const f of [A.passwordFile, PAGE]) if (!A.secretFiles.includes(f)) bad.push(`admin tools: ${f} is not a password file`);
  expect("good bootstrap", A.bootstrap, GOOD_ADMIN_TOOLS, false);
  expect("gate missing", A.bootstrap, GOOD_ADMIN_TOOLS.replace("if (!EVC_Admin_Tools_Feature::enabled()) {\n            return;\n        }", ""), true);
  expect("service built elsewhere", "includes/x.php", "<?php\n$s = new EVC_Staff_Account_Service();", true);
  expect("readiness named outside the allowlist", "includes/x.php", "<?php\nfunction f(EVC_Readiness $r) { return $r; }", true);
  expect("defines the opt-in constant", "includes/x.php", "<?php\ndefine('EVC_CLUB_ADMIN_TOOLS_ENABLED', true);", true);
  expect("readiness writes an option", R, "<?php\nupdate_option('x', 1);", true);
  expect("readiness writes the Club DB", R, "<?php\n$db->execute('DELETE FROM t');", true);
  expect("readiness hooks something", RP, "<?php\nadd_action('init', 'f');", true);
  expect("readiness reads", R, "<?php\n$v = get_option('x'); $r = $db->fetch_one('SELECT 1');", false);
  expect("password logged", A.passwordFile, "<?php\nerror_log($password);", true);
  expect("password cached", PAGE, "<?php\nset_transient('p', $password, 60);", true);
  expect("password set elsewhere", "includes/x.php", "<?php\nwp_set_password('x', 1);", true);
  expect("the service may set a password", A.passwordFile, "<?php\nwp_set_password($p, $id);", false);
  expect("the service may create the account", A.passwordFile, "<?php\n$id = wp_insert_user(array());", false);
  expect("the service may not sign anyone in", A.passwordFile, "<?php\nwp_set_auth_cookie($id);", true);
  expect("a user created elsewhere still refused", "includes/admin/class-evc-readiness-page.php", "<?php\n$id = wp_insert_user(array());", true);
  // Round 2: the read-only files cannot reach a till-account writer, an SQL write or a callable writer.
  for (const m of ["create", "set_password", "set_disabled", "revoke_all", "record_login", "process", "handle_load", "destroy_all"]) {
    expect(`readiness calls ->${m}()`, R, `<?php\n$this->staff->${m}(1);`, true);
  }
  expect("readiness calls EVC_Staff_Session::delete_all_activity()", R, "<?php\nEVC_Staff_Session::delete_all_activity(1);", true);
  expect("readiness calls EVC_Staff_Auth::set_disabled()", R, "<?php\nEVC_Staff_Auth::set_disabled(1, true);", true);
  expect("readiness calls EVC_Staff_Session::revoke_all_staff_sessions()", R, "<?php\nEVC_Staff_Session::revoke_all_staff_sessions();", true);
  expect("the reader destroys sessions", READER, "<?php\nWP_Session_Tokens::get_instance(1)->destroy_all();", true);
  expect("the reader may read sessions", READER, "<?php\n$s = WP_Session_Tokens::get_instance(1)->get_all(); $m = get_user_meta(1, 'k', true);", false);
  expect("readiness takes the service", R, "<?php\nfunction f(EVC_Staff_Account_Service $s) { return $s; }", true);
  expect("readiness reaches the service through the plugin", R, "<?php\n$s = EVC_Admin_Tools_Plugin::parts()['service'];", true);
  expect("readiness page may check access", RP, "<?php\nif (!EVC_Staff_Account_Service::may_manage(wp_get_current_user())) { return; }", false);
  expect("readiness page names the service other than ::may_manage()", RP, "<?php\nEVC_Staff_Account_Service::audit_entries(1);", true);
  expectOwn("readiness page calls a static writer (EVC_Staff_Session::revoke_current)", RP, "<?php\nEVC_Staff_Session::revoke_current();", true);
  for (const sql of ["UPDATE wp_options SET option_value = 1", "delete from wp_users where ID = 1", "INSERT INTO evc_x VALUES (1)", "REPLACE INTO t VALUES (1)", "ALTER TABLE t ADD c INT", "DROP TABLE evc_x", "CREATE TABLE t (id INT)", "TRUNCATE TABLE t", "SELECT 1 INTO OUTFILE '/tmp/x'", "GRANT ALL ON *.* TO x"]) {
    expect(`readiness SQL write via get_var: ${sql}`, R, `<?php\n$v = $wpdb->get_var("${sql}");`, true);
  }
  expect("readiness SQL write via a Club DB read", R, "<?php\n$db->fetch_one('DELETE FROM evc_members');", true);
  expect("readiness SELECT stays allowed", R, "<?php\n$db->fetch_one('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_NAME = ?', array('t')); $u = 'create new FluentCRM contacts on WP user registration';", false);
  expect("readiness call_user_func", R, "<?php\ncall_user_func('get_option', 'x');", true);
  expect("readiness call_user_func_array", R, "<?php\ncall_user_func_array(array($s, 'accounts'), array());", true);
  expect("readiness callable string of a writer", R, "<?php\n$f = 'update_option'; $f('x', 1);", true);
  expect("readiness callable array of a till writer", R, "<?php\n$f = array($s, 'set_password'); $f(1);", true);
  expect("readiness update_user_option", R, "<?php\nupdate_user_option(1, 'x', 1);", true);
  expect("readiness dynamic static call", R, "<?php\n$c::accounts();", true);
  expectOwn("readiness dynamic static call (read-only rule)", R, "<?php\n$n = $c::LIMIT;", true);
  expect("readiness names the QR services other than is_lookup_secret", R, "<?php\n$s = EVC_Club_Qr_Services::store($db);", true);
  expect("readiness checks the lookup key", R, "<?php\n$ok = EVC_Club_Qr_Services::is_lookup_secret($v);", false);
  expect("the QR services named in the readiness page", RP, "<?php\n$ok = EVC_Club_Qr_Services::is_lookup_secret($v);", true);
  // Round 2: the password-leak list.
  for (const [label, path, src] of [
    ["update_option", A.passwordFile, "<?php\nupdate_option('x', $password);"],
    ["add_option", A.passwordFile, "<?php\nadd_option('x', array('p' => $password));"],
    ["update_user_meta", A.passwordFile, "<?php\nupdate_user_meta($id, 'x', $password);"],
    ["update_user_option", A.passwordFile, "<?php\nupdate_user_option($id, 'x', $password);"],
    ["wp_cache_add", PAGE, "<?php\nwp_cache_add('p', $result['password']);"],
    ["wp_cache_replace", PAGE, "<?php\nwp_cache_replace('p', $result['password']);"],
    ["header", PAGE, "<?php\nheader('X-P: ' . $result['password']);"],
    ["do_action", A.passwordFile, "<?php\ndo_action('evc_password', $password);"],
    ["apply_filters", A.passwordFile, "<?php\n$x = apply_filters('evc_x', $password);"],
    ["add_query_arg", PAGE, "<?php\n$u = add_query_arg('p', 'x', $url);"],
    ["wp_redirect", PAGE, "<?php\nwp_redirect($url);"],
    ["wp_safe_redirect", PAGE, "<?php\nwp_safe_redirect($url);"],
  ]) {
    expect(`password leak via ${label}`, path, src, true);
  }
  // Round 3: the widened SQL-write patterns.
  for (const sql of ["UPDATE {$wpdb->options} SET option_value = 1", "INSERT evc_x VALUES (1)", "INSERT LOW_PRIORITY INTO t VALUES (1)", "insert ignore t set a = 1",
    "REPLACE evc_x VALUES (1)", "REPLACE LOW_PRIORITY INTO t VALUES (1)", "UPDATE t1, t2 SET t1.a = t2.a", "UPDATE LOW_PRIORITY t SET a = 1",
    "DELETE t1 FROM t1 JOIN t2", "DELETE LOW_PRIORITY QUICK FROM t", "LOAD DATA INFILE '/tmp/x' INTO TABLE t", "LOCK TABLES t WRITE",
    "CREATE OR REPLACE VIEW v AS SELECT 1", "CREATE DEFINER=root@localhost PROCEDURE p() SELECT 1", "SET GLOBAL general_log = 1", "CALL evc_proc(1)", "REVOKE ALL ON *.* FROM x", "SELECT 1 INTO DUMPFILE '/tmp/x'"]) {
    expectOwn(`readiness SQL write in a string: ${sql}`, R, `<?php\n$v = $wpdb->get_var("${sql}");`, true);
  }
  expectOwn("readiness SQL reads stay allowed", R, "<?php\n$a = $db->fetch_one('SELECT COALESCE(MAX(version), 0) AS v FROM evc_schema_migrations'); $b = $db->fetch_one('SELECT VERSION() AS v'); $c = 'Ορίστηκε, αλλά όχι με την ακριβή τιμή';", false);
  // Round 3: the allowlist of EVC_* static calls in the read-only files.
  for (const [label, src] of [
    ["EVC_Staff_Session::prune_activity()", "<?php\nEVC_Staff_Session::prune_activity(1, 2);"],
    ["EVC_Staff_Session::on_logout()", "<?php\nEVC_Staff_Session::on_logout(1);"],
    ["EVC_Staff_Session::handle_admin_revoke()", "<?php\nEVC_Staff_Session::handle_admin_revoke();"],
    ["EVC_Staff_Session::verify_current() (it touches the activity)", "<?php\n$s = EVC_Staff_Session::verify_current(1);"],
    ["EVC_Staff_Role::register()", "<?php\nEVC_Staff_Role::register();"],
    ["EVC_Staff_Role::unregister()", "<?php\nEVC_Staff_Role::unregister();"],
    ["an unknown EVC_* static", "<?php\n$x = EVC_Club_Members::anything(1);"],
    ["a string callable 'EVC_X::m'", "<?php\n$f = 'EVC_Staff_Role::register'; $f();"],
    ["a callable pair of a writer", "<?php\n$f = array('EVC_Staff_Session', 'revoke_current'); $f();"],
    ["a callable pair of a helper outside the allowlist", "<?php\n$f = array('EVC_Staff_Session', 'verify_current'); $f(1);"],
    ["a callable pair with a variable method", "<?php\n$f = array('EVC_Staff_Auth', $m); $f(1);"],
    ["EVC_X::class", "<?php\n$f = array(EVC_Staff_Session::class, 'evaluate');"],
    ["new EVC_X", "<?php\n$m = new EVC_Migrator($db, $dir);"],
    ["static::m()", "<?php\nstatic::reset();"],
    ["parent::m()", "<?php\nparent::reset();"],
  ]) {
    expectOwn(`readiness ${label}`, R, src, true);
  }
  expectOwn("readiness allowed helpers, constants and self::", R, "<?php\n$a = EVC_Fluentcrm_Plugin::problem(); $b = EVC_Customer_Feature::CONSTANT; $c = self::row('k', 'l', self::OK); $d = array('EVC_Club_Db', 'connect'); $e = EVC_Staff_Auth::is_disabled(1);", false);
  expectOwn("the reader may use the role check", READER, "<?php\n$r = EVC_Staff_Role::is_restricted_staff_account($u); $x = EVC_Staff_Role::ROLE;", false);
  // Round 3: variable method names and more WordPress writers.
  expectOwn("readiness variable method ->$m()", R, "<?php\n$this->staff->$m(1);", true);
  expectOwn("readiness variable method ->{$m}()", R, "<?php\n$this->staff->{$m}(1);", true);
  expectOwn("readiness variable static method $c::$m()", R, "<?php\nEVC_Staff_Auth::$m(1);", true);
  for (const fn of ["wp_logout", "wp_clear_auth_cookie", "wp_trash_post", "wp_untrash_post", "wp_destroy_current_session", "wp_destroy_other_sessions", "wp_destroy_all_sessions", "forward_static_call"]) {
    expectOwn(`readiness calls ${fn}()`, R, `<?php\n${fn}(1);`, true);
  }
  for (const m of ["migrate", "apply", "destroy_all_for_all_users", "revoke_current", "prune_activity", "on_logout", "handle_admin_revoke", "register", "unregister"]) {
    expectOwn(`the reader calls ->${m}()`, READER, `<?php\n$o->${m}(1);`, true);
  }
  expectOwn("the reader calls WP_Session_Tokens::destroy_all_for_all_users()", READER, "<?php\nWP_Session_Tokens::destroy_all_for_all_users();", true);
  // Round 3: the password-leak rules.
  for (const [label, path, src] of [
    ["trigger_error", A.passwordFile, "<?php\ntrigger_error('x');"],
    ["syslog", PAGE, "<?php\nsyslog(LOG_INFO, 'x');"],
    ["file_put_contents", PAGE, "<?php\nfile_put_contents('/tmp/x', 'y');"],
    ["fwrite", PAGE, "<?php\nfwrite($fh, 'y');"],
    ["fputs", PAGE, "<?php\nfputs($fh, 'y');"],
    ["user_error", A.passwordFile, "<?php\nuser_error('x');"],
    ["wp_mail", A.passwordFile, "<?php\nwp_mail('a@b.c', 's', 'm');"],
    ["wp_die with the result", PAGE, "<?php\nwp_die(print_r_safe($result));"],
    ["wp_die with the password", PAGE, "<?php\nwp_die(esc_html($result['password']));"],
    ["do_action with the result", A.passwordFile, "<?php\ndo_action('evc_done', $result);"],
    ["apply_filters with $this->result", PAGE, "<?php\n$x = apply_filters('evc_x', $this->result);"],
    ["the injected header callable with the password", PAGE, "<?php\n($this->header)('X-P: ' . $result['password']);"],
    ["a variable callable with the password", PAGE, "<?php\n$f($password);"],
  ]) {
    expectOwn(`password leak via ${label}`, path, src, true);
  }
  expectOwn("wp_die with a result code only", PAGE, "<?php\nwp_die(esc_html(self::MESSAGES[$result['code']]), '', array('response' => 403));", false);
  expectOwn("the injected header callable without the password", PAGE, "<?php\n($this->header)(self::NO_STORE_HEADER);", false);
  expect("the audit option without the password", A.passwordFile, "<?php\nupdate_option(self::AUDIT_OPTION, array_slice($all, -100), false);", false);
  expect("the no-store header without the password", PAGE, "<?php\nheader($line, true);", false);
  return bad;
}
