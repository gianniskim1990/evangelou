import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { endpointUrl, parseCustomerConfig } from "../../src/customer/config";
import { createCustomerApi, parseMe } from "../../src/customer/customerApi";
import { toLiveView } from "../../src/customer/liveView";

/*
 * STAGE-01 — the REAL customer app's bootstrap config, /me client and view
 * mapping. Fake fetch only: nothing touches the network.
 */

const ORIGIN = "https://evangelouprofiterole.gr";
const PRETTY = `${ORIGIN}/prometheus/wp-json/evangelou-club-customer/v1`;
const PLAIN = `${ORIGIN}/prometheus/?rest_route=/evangelou-club-customer/v1`;
const cfg = (over: Record<string, unknown> = {}) =>
  JSON.stringify({ restBase: PRETTY, nonce: "0123456789", loginUrl: `${ORIGIN}/prometheus/wp-login.php?redirect_to=x`, logoutUrl: `${ORIGIN}/prometheus/wp-login.php?action=logout&_wpnonce=abc`, appVersion: "0.1.0-dev", ...over });

const okBody = (membership: Record<string, unknown>, extra: Record<string, unknown> = {}) => ({
  result: "ok",
  contract: "customer-me-v1",
  account: { display_name: "Ελένη Μαρκοπούλου", email: "eleni@example.org" },
  membership,
  club: { coffee_today: "not_connected", card: "sample_only" },
  ...extra,
});

function fakeFetch(status: number, body: unknown, headers: Record<string, string> = {}) {
  const calls: { url: string; init: RequestInit }[] = [];
  const impl = (async (url: string, init: RequestInit) => {
    calls.push({ url, init });
    return { status, headers: new Headers(headers), json: async () => body } as unknown as Response;
  }) as unknown as typeof fetch;
  return { impl, calls };
}

describe("STAGE-01 customer bootstrap config", () => {
  it("accepts pretty and plain permalinks, same origin only", () => {
    for (const base of [PRETTY, PLAIN]) {
      const r = parseCustomerConfig(cfg({ restBase: base }), ORIGIN);
      assert.ok(r.ok, base);
    }
    assert.equal(endpointUrl(PRETTY, "/me"), `${PRETTY}/me`);
    assert.equal(new URL(endpointUrl(PLAIN, "/me")).searchParams.get("rest_route"), "/evangelou-club-customer/v1/me");
  });

  it("rejects anything else (fail closed)", () => {
    const bad: [string, Record<string, unknown>][] = [
      ["other origin", { restBase: `https://evil.example/wp-json/evangelou-club-customer/v1` }],
      ["staff namespace", { restBase: `${ORIGIN}/wp-json/evangelou-club/v1` }],
      ["plain other route", { restBase: `${ORIGIN}/?rest_route=/wp/v2` }],
      ["nonce", { nonce: "<script>" }],
      ["login elsewhere", { loginUrl: "https://evil.example/login" }],
      ["logout missing", { logoutUrl: "" }],
    ];
    for (const [label, over] of bad) assert.equal(parseCustomerConfig(cfg(over), ORIGIN).ok, false, label);
    assert.equal(parseCustomerConfig(null, ORIGIN).ok, false);
    assert.equal(parseCustomerConfig("{", ORIGIN).ok, false);
  });
});

describe("STAGE-01 /me client", () => {
  const config = (parseCustomerConfig(cfg(), ORIGIN) as { ok: true; config: Parameters<typeof createCustomerApi>[0] }).config;

  it("sends a same-origin, no-store GET with the in-memory nonce and adopts the refreshed one", async () => {
    const f = fakeFetch(200, okBody({ state: "active", paid_until_utc: "2026-11-15T10:00:00+00:00" }), { "X-WP-Nonce": "abcdef0123" });
    const api = createCustomerApi(config, f.impl);
    const r = await api.me();
    assert.equal(r.kind, "ok");
    assert.equal(f.calls[0].url, `${PRETTY}/me`);
    assert.equal(f.calls[0].init.method, "GET");
    assert.equal(f.calls[0].init.credentials, "same-origin");
    assert.equal(f.calls[0].init.cache, "no-store");
    assert.equal(f.calls[0].init.redirect, "error");
    assert.equal((f.calls[0].init.headers as Record<string, string>)["X-WP-Nonce"], "0123456789");
    assert.equal(f.calls[0].init.body, undefined, "never sends a user id or anything else");
    await api.me();
    assert.equal((f.calls[1].init.headers as Record<string, string>)["X-WP-Nonce"], "abcdef0123");
  });

  it("maps the contract statuses", async () => {
    assert.equal((await createCustomerApi(config, fakeFetch(503, { result: "unavailable" }).impl).me()).kind, "unavailable");
    assert.equal((await createCustomerApi(config, fakeFetch(503, { error: { code: "server_error" } }).impl).me()).kind, "error", "only the exact domain body means unavailable");
    assert.equal((await createCustomerApi(config, fakeFetch(401, { error: { code: "unauthorized" } }).impl).me()).kind, "signed_out");
    assert.equal((await createCustomerApi(config, fakeFetch(403, { error: { code: "forbidden" } }).impl).me()).kind, "forbidden");
    assert.equal((await createCustomerApi(config, fakeFetch(500, null).impl).me()).kind, "error");
    const throwing = (async () => {
      throw new TypeError("network");
    }) as unknown as typeof fetch;
    assert.equal((await createCustomerApi(config, throwing).me()).kind, "error");
  });

  it("times out instead of waiting forever", async () => {
    const never = (() => new Promise(() => {})) as unknown as typeof fetch;
    assert.equal((await createCustomerApi(config, never, 20).me()).kind, "error");
  });

  it("parses strictly: an unverifiable answer is never a membership", () => {
    assert.ok(parseMe(okBody({ state: "active", paid_until_utc: "2026-11-15T10:00:00+00:00" })));
    for (const state of ["pending_payment", "cancelled", "none", "unverified"]) assert.ok(parseMe(okBody({ state, paid_until_utc: null })), state);
    const rejected: [string, unknown][] = [
      ["active without an end", okBody({ state: "active", paid_until_utc: null })],
      ["pending with an end", okBody({ state: "pending_payment", paid_until_utc: "2026-11-15T10:00:00+00:00" })],
      ["unknown state", okBody({ state: "paid", paid_until_utc: null })],
      ["time without offset", okBody({ state: "active", paid_until_utc: "2026-11-15 10:00:00" })],
      ["club claims coffee data", okBody({ state: "active", paid_until_utc: "2026-11-15T10:00:00+00:00" }, { club: { coffee_today: "available", card: "sample_only" } })],
      ["club claims a live QR", okBody({ state: "active", paid_until_utc: "2026-11-15T10:00:00+00:00" }, { club: { coffee_today: "not_connected", card: "live" } })],
      ["other contract", okBody({ state: "active", paid_until_utc: "2026-11-15T10:00:00+00:00" }, { contract: "customer-me-v2" })],
      ["not an object", ["ok"]],
    ];
    for (const [label, body] of rejected) assert.equal(parseMe(body), null, label);
  });
});

describe("STAGE-01 live view", () => {
  it("shows the exact Athens end and marks Club data unavailable", () => {
    const now = Date.parse("2026-10-20T06:00:00Z"); // 09:00 Athens
    const view = toLiveView({ displayName: "Ελένη Μαρκοπούλου", email: "eleni@example.org", state: "active", paidUntilUtc: "2026-11-15T10:00:00+00:00" }, now);
    assert.deepEqual([view.state, view.paidUntil, view.paidUntilTime, view.daysLeft, view.today], ["active", "2026-11-15", "12:00", 26, "2026-10-20"]);
    assert.equal(view.live, true);
    assert.equal(view.clubData, "unavailable");
    assert.deepEqual([view.history, view.usual, view.coffeeUsedToday, view.visitsThisMonth], [[], null, false, 0]);
    assert.equal(view.member.phoneMasked, "", "no phone is invented");
    assert.equal(view.member.memberSince, "", "no member-since date is invented");
    assert.equal(toLiveView({ displayName: "", email: "x@example.org", state: "pending_payment", paidUntilUtc: null }, now).state, "pending");
  });
});
