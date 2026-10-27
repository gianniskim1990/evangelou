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
  return bad.concat(migrationsSelfTest());
}
