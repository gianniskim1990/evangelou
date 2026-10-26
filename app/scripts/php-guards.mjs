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
      i = j + 1; push("str", ""); continue;
    }
    if (c === "<" && src.startsWith("<<<", i)) {
      const m = /^<<<[ \t]*(['"]?)([A-Za-z_][A-Za-z0-9_]*)\1\r?\n/.exec(src.slice(i, i + 200));
      if (m) {
        const re = new RegExp("\\n[ \\t]*" + m[2] + "\\b");
        const rest = src.slice(i + m[0].length - 1);
        const hit = re.exec(rest);
        i = hit ? i + m[0].length - 1 + hit.index + hit[0].length : n;
        push("str", ""); continue;
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
    bodies[name] = { start, end: j };
  });
  return bodies;
}

const GATE = ["{", "if", "(", "!", "EVC_Wc_Completion_Feature", "::", "enabled", "(", ")", ")", "{", "return", ";", "}"];

/** True when boot()'s body STARTS with the real gate statement. */
export function bootIsGated(tokens, bodies) {
  const b = bodies.boot;
  if (!b) return false;
  return GATE.every((v, i) => {
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
export function problemsFor(path, src, { bootstrap, guarded, forbidden }) {
  const tokens = tokenize(src);
  const bodies = functionBodies(tokens);
  const problems = [];
  for (const c of constructions(tokens)) {
    if (forbidden.some((f) => same(f, c.name))) problems.push(`${path}: forbidden construction of ${c.name}`);
    if (guarded.some((g) => same(g, c.name)) && !(path === bootstrap && inside(bodies, "wire", c.at))) {
      problems.push(`${path}: ${c.name} constructed outside ${bootstrap}::wire()`);
    }
  }
  for (const call of staticCalls(tokens, ["build", "wire"])) {
    if (call.cls.toLowerCase() === "evc_wc_completion_plugin" || (path === bootstrap && ["self", "static"].includes(call.cls.toLowerCase()))) {
      if (path !== bootstrap) { problems.push(`${path}: calls ${call.cls}::${call.name}() outside the bootstrap`); continue; }
      const okIn = call.name === "wire" ? "build" : "boot";
      if (!inside(bodies, okIn, call.at)) problems.push(`${path}: ${call.cls}::${call.name}() outside ${okIn}()`);
      if (call.name === "build" && bodies.boot && inside(bodies, "boot", call.at) && !bootIsGated(tokens, bodies)) problems.push(`${path}: boot() is not gated by the Feature::enabled() statement`);
    }
  }
  if (path === bootstrap && !bootIsGated(tokens, bodies)) problems.push(`${path}: boot() does not start with if (!EVC_Wc_Completion_Feature::enabled()) { return; }`);
  return problems;
}

// ---- self test: negative fixtures, run on every package --------------------------------------------
const OPTS = { bootstrap: "includes/customer/class-evc-wc-completion-plugin.php", guarded: ["EVC_Wc_Completion_Confirmer", "EVC_Bacs_Issuance_Guard"], forbidden: ["EVC_Bacs_Payment_Issuer"] };
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
  expect("mention in a comment or string only", "includes/x.php", "<?php\n// new EVC_Wc_Completion_Confirmer()\n$s = 'new EVC_Wc_Completion_Confirmer()'; /* new EVC_Bacs_Issuance_Guard */", false);
  expect("construction outside wire() in the bootstrap", boot, GOOD.replace("public static function build($x) { return self::wire($x); }", "public static function build($x) { new EVC_Wc_Completion_Confirmer(); return self::wire($x); }"), true);
  expect("gate only in a comment", boot, GOOD.replace("if (!EVC_Wc_Completion_Feature::enabled()) {\n            return;\n        }", "// if (!EVC_Wc_Completion_Feature::enabled()) { return; }"), true);
  expect("gate not first", boot, GOOD.replace("public static function boot(): void {", "public static function boot(): void {\n        $p = self::build(1);"), true);
  expect("gate negation dropped", boot, GOOD.replace("if (!EVC_Wc_Completion_Feature", "if (EVC_Wc_Completion_Feature"), true);
  expect("gate without return", boot, GOOD.replace("return;\n        }\n        $p", "$q = 1;\n        }\n        $p"), true);
  expect("build() called from another file", "includes/x.php", "<?php\n$p = EVC_Wc_Completion_Plugin::build(1);", true);
  expect("build() called from the bootstrap outside boot()", boot, GOOD.replace("private static function wire", "public static function other() { return self::build(2); }\n    private static function wire"), true);
  expect("wire() called outside build()", boot, GOOD.replace("public static function build($x) { return self::wire($x); }", "public static function build($x) { return 1; } public static function other() { return self::wire(1); }"), true);
  return bad;
}
