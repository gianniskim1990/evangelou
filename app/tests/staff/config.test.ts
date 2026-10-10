import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { MAX_IDLE_LOCK_SECONDS, parseStaffConfig } from "../../src/staff/config";

const ORIGIN = "https://shop.example";
const VALID = {
  restBase: "https://shop.example/wp-json/evangelou-club/v1",
  nonce: "0a1b2c3d4e",
  loginUrl: "https://shop.example/wp-login.php?redirect_to=%2Fclub-admin%2F&reauth=1",
  shellUrl: "https://shop.example/club-admin/",
  idleLockSeconds: 300,
  sessionRef: "0123456789abcdef0123456789abcdef",
  appVersion: "0.1.0-dev",
  features: { qrLookup: false, phoneLookup: false, coffeeRedemption: false, history: false },
};

function parse(overrides: Record<string, unknown> = {}) {
  return parseStaffConfig(JSON.stringify({ ...VALID, ...overrides }), ORIGIN);
}

describe("staff bootstrap configuration", () => {
  it("accepts the protected shell's configuration", () => {
    const result = parse();
    assert.ok(result.ok);
    assert.equal(result.config.restBase, VALID.restBase);
    assert.equal(result.config.nonce, VALID.nonce);
    assert.equal(result.config.idleLockSeconds, 300);
    assert.deepEqual(result.config.features, VALID.features);
  });

  it("parses the server's \\u-escaped JSON (XSS-safe encoding)", () => {
    const raw = JSON.stringify(VALID).replace(/&/g, "\\u0026");
    const result = parseStaffConfig(raw, ORIGIN);
    assert.ok(result.ok);
    assert.ok(result.config.loginUrl.includes("&reauth=1"));
  });

  const rejected: [string, string | null][] = [
    ["missing element", null],
    ["empty", ""],
    ["not JSON", "{not json"],
    ["array", "[]"],
  ];
  for (const [name, raw] of rejected) {
    it(`rejects ${name}`, () => assert.equal(parseStaffConfig(raw, ORIGIN).ok, false));
  }

  const bad: [string, Record<string, unknown>][] = [
    ["cross-origin REST base", { restBase: "https://evil.example/wp-json/evangelou-club/v1" }],
    ["REST base outside the Club namespace", { restBase: "https://shop.example/wp-json/wp/v2" }],
    ["javascript: login URL", { loginUrl: "javascript:alert(1)" }],
    ["cross-origin login URL", { loginUrl: "https://evil.example/wp-login.php" }],
    ["malformed nonce", { nonce: "abc" }],
    ["nonce with markup", { nonce: "<script>1" }],
    ["missing nonce", { nonce: undefined }],
    ["non-integer idle lock", { idleLockSeconds: 12.5 }],
    ["idle lock too short", { idleLockSeconds: 5 }],
    ["malformed session ref", { sessionRef: "raw-session-token" }],
  ];
  for (const [name, overrides] of bad) {
    it(`fails closed on ${name}`, () => assert.equal(parse(overrides).ok, false));
  }

  it("never waits longer than the approved 5 minutes", () => {
    const result = parse({ idleLockSeconds: 3600 });
    assert.ok(result.ok);
    assert.equal(result.config.idleLockSeconds, MAX_IDLE_LOCK_SECONDS);
  });

  it("treats unknown or missing feature flags as disabled", () => {
    const result = parse({ features: { qrLookup: "yes", coffeeRedemption: 1 } });
    assert.ok(result.ok);
    assert.deepEqual(result.config.features, { qrLookup: false, phoneLookup: false, coffeeRedemption: false, history: false });
  });
});
