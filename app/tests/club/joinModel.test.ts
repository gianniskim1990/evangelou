import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { canonicalGreekMobile as previewMobile, MESSAGES, validateRegistration } from "../../src/customerPreview/registration";
import { createJoinApi, parseCheckout, parseRegister, parseTerms } from "../../src/customer/joinApi";
import { bootMode, parseJoinConfig, parsePosterConfig } from "../../src/customer/joinConfig";
import { JOIN_MESSAGES, canonicalGreekMobile, passwordProblem, SERVER_CODES, validateJoin } from "../../src/customer/joinRules";

/*
 * STAGE-07 — the in-store join flow's pure parts: the form rules (pinned to the approved CUSTOMER-01 rules), the
 * bootstrap configs (same site only: no open redirect) and the strict join-v1 client. Fake fetch only.
 */

const ORIGIN = "https://evangelouprofiterole.gr";
const BASE = `${ORIGIN}/prometheus`;
const join = (over: Record<string, unknown> = {}) =>
  JSON.stringify({
    mode: "join",
    restBase: `${BASE}/?rest_route=/evangelou-club-customer/v1`,
    nonce: "0123456789",
    step: "register",
    joinUrl: `${BASE}/?evc_club_join=1`,
    memberUrl: `${BASE}/?evc_club_member=1`,
    loginUrl: `${BASE}/wp-login.php?redirect_to=x`,
    logoutUrl: `${BASE}/wp-login.php?action=logout&_wpnonce=abc`,
    lostPasswordUrl: `${BASE}/wp-login.php?action=lostpassword`,
    privacyUrl: "",
    termsUrl: `${BASE}/oroi/`,
    appVersion: "0.1.0",
    ...over,
  });

describe("STAGE-07 join form rules", () => {
  it("are the approved CUSTOMER-01 rules with the e-mail required", () => {
    const cases = [
      { name: "", phone: "", email: "" },
      { name: "Ελένη", phone: "2510123456", email: "x@y" },
      { name: "Ελένη 7", phone: "+30 694 123 4567", email: "eleni@example.gr" },
      { name: "Ελένη Μαρκοπούλου", phone: "0030 694-123-4567", email: "" },
      { name: `Α ${"β".repeat(80)}`, phone: "69412345", email: `${"a".repeat(250)}@x.gr` },
    ];
    for (const c of cases) {
      const preview = validateRegistration(c, true);
      const live = validateJoin({ ...c, password: "kafes-sto-limani-7" });
      assert.deepEqual({ name: live.name, phone: live.phone, email: live.email }, { name: preview.name, phone: preview.phone, email: preview.email }, JSON.stringify(c));
    }
    for (const key of Object.keys(MESSAGES) as (keyof typeof MESSAGES)[]) assert.equal(JOIN_MESSAGES[key], MESSAGES[key], key);
    for (const raw of ["6941234567", "+30 694 123 4567", "0030 694-123-4567", "2510123456", "+44 7700 900123"]) assert.equal(canonicalGreekMobile(raw), previewMobile(raw), raw);
  });

  it("the password policy mirrors the server", () => {
    assert.equal(passwordProblem("short", "", ""), JOIN_MESSAGES.passwordShort);
    assert.equal(passwordProblem("ten chars!", "", ""), null);
    assert.equal(passwordProblem("ab".repeat(65), "", ""), JOIN_MESSAGES.passwordLong);
    assert.equal(passwordProblem("Password123", "", ""), JOIN_MESSAGES.passwordWeak);
    assert.equal(passwordProblem("aaaaaaaaaaaa", "", ""), JOIN_MESSAGES.passwordWeak);
    assert.equal(passwordProblem("eleni.m-2026", " Eleni.M@example.gr", ""), JOIN_MESSAGES.passwordWeak);
    assert.equal(passwordProblem("x-41234567-x", "", "694 123 4567"), JOIN_MESSAGES.passwordWeak);
    for (const code of ["name_missing", "name_one_word", "name_chars", "name_long", "phone_missing", "phone_invalid", "email_missing", "email_invalid", "password_short", "password_long", "password_weak"]) {
      assert.ok(SERVER_CODES[code], code);
    }
  });
});

describe("STAGE-07 bootstrap configs", () => {
  it("picks the app from the mode; the member area has none", () => {
    assert.equal(bootMode(join()), "join");
    assert.equal(bootMode(JSON.stringify({ mode: "poster", joinUrl: `${BASE}/`, appVersion: "" })), "poster");
    assert.equal(bootMode(JSON.stringify({ restBase: "x" })), "member");
    assert.equal(bootMode(JSON.stringify({ mode: "admin" })), null);
    assert.equal(bootMode("not json"), null);
    assert.equal(bootMode(null), null);
  });

  it("accepts the join config only when every URL is this site", () => {
    const ok = parseJoinConfig(join(), ORIGIN);
    assert.ok(ok.ok);
    assert.equal(ok.ok && ok.config.termsUrl, `${BASE}/oroi/`);
    for (const key of ["joinUrl", "memberUrl", "loginUrl", "logoutUrl", "lostPasswordUrl", "termsUrl", "privacyUrl", "restBase"]) {
      for (const bad of ["https://evil.example/x", "//evil.example/x", "javascript:alert(1)", "http://evangelouprofiterole.gr.evil.example/"]) {
        const r = parseJoinConfig(join({ [key]: bad }), ORIGIN);
        assert.ok(!r.ok, `${key} = ${bad}`);
      }
    }
    assert.ok(!parseJoinConfig(join({ step: "admin" }), ORIGIN).ok);
    assert.ok(!parseJoinConfig(join({ nonce: "x" }), ORIGIN).ok);
    assert.ok(!parseJoinConfig(join({ restBase: `${BASE}/?rest_route=/wp/v2` }), ORIGIN).ok);
    assert.ok(!parseJoinConfig(join({ mode: "poster" }), ORIGIN).ok);
  });

  it("the poster carries only the public join URL", () => {
    const good = (joinUrl: string) => JSON.stringify({ mode: "poster", joinUrl, appVersion: "1" });
    assert.ok(parsePosterConfig(good(`${BASE}/?evc_club_join=1&src=store`), ORIGIN).ok);
    assert.ok(parsePosterConfig(good(`${BASE}/club/join/?src=store`), ORIGIN).ok);
    for (const bad of [`${BASE}/?evc_club_join=1&src=store&email=a%40b.gr`, `${BASE}/?evc_club_join=1&token=abc`, `https://evil.example/club/join/`, `${BASE}/club/join/#x`, `https://u:p@evangelouprofiterole.gr/club/join/`]) {
      assert.ok(!parsePosterConfig(good(bad), ORIGIN).ok, bad);
    }
    assert.ok(!parsePosterConfig(JSON.stringify({ mode: "poster", joinUrl: `${BASE}/club/join/`, appVersion: "1", nonce: "0123456789" }), ORIGIN).ok, "no extra keys");
  });
});

describe("STAGE-07 join-v1 client", () => {
  it("parses every answer strictly", () => {
    assert.deepEqual(parseRegister(200, { result: "ok" }), { kind: "ok" });
    assert.deepEqual(parseRegister(200, { result: "check_account" }), { kind: "check_account" });
    assert.deepEqual(parseRegister(200, { result: "invalid", fields: { email: "email_invalid" } }), { kind: "invalid", fields: { email: "email_invalid" } });
    assert.deepEqual(parseRegister(200, { result: "invalid", fields: { role: "x" } }), { kind: "error" });
    assert.deepEqual(parseRegister(200, { result: "ok", user_id: 5 }), { kind: "error" });
    assert.deepEqual(parseRegister(429, { error: { code: "rate_limited" } }), { kind: "rate_limited" });
    assert.deepEqual(parseRegister(503, { result: "unavailable" }), { kind: "unavailable" });
    assert.deepEqual(parseRegister(401, { error: { code: "unauthorized" } }), { kind: "error" });
    assert.deepEqual(parseTerms(200, { result: "ok" }), { kind: "ok" });
    assert.deepEqual(parseTerms(401, null), { kind: "signed_out" });
    assert.deepEqual(parseTerms(200, { result: "ok", extra: 1 }), { kind: "error" });
    assert.deepEqual(parseCheckout(200, { result: "ok", next: "checkout", url: `${BASE}/checkout/?add-to-cart=24435` }, ORIGIN), { kind: "checkout", url: `${BASE}/checkout/?add-to-cart=24435` });
    assert.deepEqual(parseCheckout(200, { result: "ok", next: "member", url: `${BASE}/club-member/` }, ORIGIN), { kind: "member", url: `${BASE}/club-member/` });
    assert.deepEqual(parseCheckout(200, { result: "ok", next: "checkout", url: "https://evil.example/pay" }, ORIGIN), { kind: "error" }, "never another site");
    assert.deepEqual(parseCheckout(200, { result: "ok", next: "elsewhere", url: `${BASE}/` }, ORIGIN), { kind: "error" });
    assert.deepEqual(parseCheckout(200, { result: "terms_required" }, ORIGIN), { kind: "terms_required" });
  });

  it("posts same-origin JSON with the page nonce and the honeypot", async () => {
    const parsed = parseJoinConfig(join(), ORIGIN);
    assert.ok(parsed.ok);
    const calls: { url: string; init: RequestInit }[] = [];
    const fetchImpl = (async (url: string, init: RequestInit) => {
      calls.push({ url, init });
      return { status: 200, headers: new Headers({ "X-WP-Nonce": "abcdef0123" }), json: async () => ({ result: "ok" }) } as unknown as Response;
    }) as unknown as typeof fetch;
    const api = createJoinApi(parsed.config, fetchImpl);
    assert.deepEqual(await api.register({ name: "Ελένη Μαρκοπούλου", phone: "6941234567", email: "e@example.gr", password: "kafes-sto-limani-7" }, "filled-by-a-bot"), { kind: "ok" });
    assert.deepEqual(await api.acceptTerms(), { kind: "ok" });
    assert.equal(calls[0].url, `${BASE}/?rest_route=%2Fevangelou-club-customer%2Fv1%2Fjoin%2Faccount`);
    assert.equal(calls[0].init.method, "POST");
    assert.equal(calls[0].init.credentials, "same-origin");
    assert.equal(calls[0].init.redirect, "error");
    assert.equal((calls[0].init.headers as Record<string, string>)["X-WP-Nonce"], "0123456789");
    assert.deepEqual(JSON.parse(String(calls[0].init.body)), { name: "Ελένη Μαρκοπούλου", phone: "6941234567", email: "e@example.gr", password: "kafes-sto-limani-7", website: "filled-by-a-bot" }, "the honeypot goes to the server as typed");
    assert.equal((calls[1].init.headers as Record<string, string>)["X-WP-Nonce"], "abcdef0123", "the refreshed nonce");
    assert.deepEqual(JSON.parse(String(calls[1].init.body)), { accept: true });
  });

  it("a network failure or a slow server is an error, never a success", async () => {
    const parsed = parseJoinConfig(join(), ORIGIN);
    assert.ok(parsed.ok);
    const failing = (async () => {
      throw new Error("offline");
    }) as unknown as typeof fetch;
    assert.deepEqual(await createJoinApi(parsed.config, failing).register({ name: "", phone: "", email: "", password: "" }, ""), { kind: "error" });
    const never = (() => new Promise(() => {})) as unknown as typeof fetch;
    assert.deepEqual(await createJoinApi(parsed.config, never, 5).checkout(), { kind: "error" });
  });
});
