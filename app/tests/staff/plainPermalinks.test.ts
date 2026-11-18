import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { createRestClubService } from "../../src/club/restClubService";
import { restRouteUrl, sameOriginRestBase } from "../../src/lib/restRoute";
import { parseStaffConfig, STAFF_NAMESPACE, type StaffConfig } from "../../src/staff/config";
import { createMemberApi } from "../../src/staff/member/memberApi";
import { createStaffApi } from "../../src/staff/staffApi";
import { fakeFetch, json } from "../club/helpers";

/*
 * STAGE-08 round 4: the staging site ran with Settings → Permalinks = «Plain» (2026-11-18). rest_url() then gives
 * …/index.php?rest_route=/evangelou-club/v1 and the staff app refused it («Η εφαρμογή δεν είναι διαθέσιμη»). Both
 * permalink forms are now accepted (same origin, the exact namespace only) and every staff request URL is built in
 * the base's own form.
 */

const ORIGIN = "https://shop.example";
const PRETTY = `${ORIGIN}/sub/wp-json/evangelou-club/v1`;
const PLAIN_INDEX = `${ORIGIN}/sub/index.php?rest_route=/evangelou-club/v1`;
const PLAIN_ROOT = `${ORIGIN}/?rest_route=/evangelou-club/v1`;
const PLAIN_SUBDIR = `${ORIGIN}/sub/?rest_route=/evangelou-club/v1`;
// rest_route NOT last: text concatenation would append the route to the other parameter, not to rest_route.
const PLAIN_EXTRA = `${ORIGIN}/sub/index.php?rest_route=/evangelou-club/v1&lang=el`;
const paramsOf = (url: string) => [...new URL(url).searchParams.entries()];
const TOKEN = "evc_" + "0123456789abcdef".repeat(3);

const VALID = {
  nonce: "0a1b2c3d4e",
  loginUrl: `${ORIGIN}/sub/wp-login.php?redirect_to=x&reauth=1`,
  shellUrl: `${ORIGIN}/sub/?evc_club_admin=1`,
  idleLockSeconds: 300,
  sessionRef: "",
  appVersion: "t",
  features: {},
};
const parse = (restBase: unknown) => parseStaffConfig(JSON.stringify({ ...VALID, restBase }), ORIGIN);

function config(restBase: string): StaffConfig {
  const r = parse(restBase);
  assert.ok(r.ok, `accepted: ${restBase}`);
  return r.config;
}

const routeOf = (url: string) => new URL(url).searchParams.get("rest_route");

describe("STAGE-08 round 4: staff REST base in both permalink forms", () => {
  it("accepts pretty and plain permalinks (site root, sub-folder, index.php)", () => {
    assert.equal(config(PRETTY).restBase, PRETTY);
    assert.equal(config(`${PRETTY}/`).restBase, PRETTY, "trailing slash dropped");
    for (const base of [PLAIN_INDEX, PLAIN_ROOT, PLAIN_SUBDIR]) {
      assert.equal(routeOf(config(base).restBase), STAFF_NAMESPACE, base);
    }
    assert.ok(config(`${ORIGIN}/sub/index.php?rest_route=%2Fevangelou-club%2Fv1`), "an encoded rest_route reads the same");
  });

  it("refuses every other plain or pretty base (fail closed)", () => {
    const bad: [string, unknown][] = [
      ["another namespace", `${ORIGIN}/?rest_route=/wp/v2`],
      ["the customer namespace", `${ORIGIN}/?rest_route=/evangelou-club-customer/v1`],
      ["a route below the namespace", `${ORIGIN}/?rest_route=/evangelou-club/v1/session`],
      ["a namespace prefix", `${ORIGIN}/?rest_route=/evangelou-club/v10`],
      ["a trailing slash in rest_route", `${ORIGIN}/?rest_route=/evangelou-club/v1/`],
      ["two rest_route values", `${ORIGIN}/?rest_route=/evangelou-club/v1&rest_route=/wp/v2`],
      ["an empty rest_route", `${ORIGIN}/?rest_route=`],
      ["another script", `${ORIGIN}/wp-login.php?rest_route=/evangelou-club/v1`],
      ["a page path", `${ORIGIN}/shop?rest_route=/evangelou-club/v1`],
      ["cross-origin plain", `https://evil.example/?rest_route=/evangelou-club/v1`],
      ["cross-origin pretty", `https://evil.example/wp-json/evangelou-club/v1`],
      ["another port", `https://shop.example:8443/?rest_route=/evangelou-club/v1`],
      ["http on an https page", `http://shop.example/?rest_route=/evangelou-club/v1`],
      ["javascript:", "javascript:alert(1)//?rest_route=/evangelou-club/v1"],
      ["a fragment", `${ORIGIN}/?rest_route=/evangelou-club/v1#x`],
      ["credentials", `https://u:p@shop.example/?rest_route=/evangelou-club/v1`],
      ["pretty, another namespace", `${ORIGIN}/wp-json/wp/v2`],
      ["pretty, look-alike namespace", `${ORIGIN}/wp-json/x-evangelou-club/v1`],
      ["not a string", 42],
      ["empty", ""],
    ];
    for (const [label, base] of bad) assert.equal(parse(base).ok, false, label);
    assert.equal(sameOriginRestBase(`${ORIGIN}/?rest_route=/evangelou-club/v1`, ORIGIN, "/evangelou-club-customer/v1"), null);
  });

  it("builds request URLs in the base's own form, keeping other query parameters", () => {
    assert.equal(restRouteUrl(PRETTY, "/session"), `${PRETTY}/session`);
    assert.equal(restRouteUrl(`${PRETTY}/`, "/session"), `${PRETTY}/session`);
    const plain = restRouteUrl(`${ORIGIN}/sub/index.php?lang=el&rest_route=/evangelou-club/v1`, "/members/lookup");
    const url = new URL(plain);
    assert.equal(url.pathname, "/sub/index.php");
    assert.equal(url.searchParams.get("rest_route"), "/evangelou-club/v1/members/lookup");
    assert.equal(url.searchParams.get("lang"), "el", "other parameters kept");
    assert.equal([...url.searchParams.keys()].length, 2);
    assert.equal(restRouteUrl("/wp-json/evangelou-club/v1/", "/coffee-options"), "/wp-json/evangelou-club/v1/coffee-options", "relative demo base unchanged");
  });

  it("the session client calls rest_route=/evangelou-club/v1/session(/end) on a plain base", async () => {
    const f = fakeFetch(() => json(200, { authenticated: true, session: { expires_at: "2026-10-10T22:00:00+03:00", idle_timeout_seconds: 1800 }, features: {} }));
    const api = createStaffApi(config(PLAIN_EXTRA), f);
    await api.getSession();
    assert.deepEqual(paramsOf(f.calls[0].url), [["rest_route", "/evangelou-club/v1/session"], ["lang", "el"]]);
    const first = new URL(f.calls[0].url);
    assert.equal(first.origin, ORIGIN);
    assert.equal(first.pathname, "/sub/index.php");
    assert.equal(first.searchParams.get("rest_route"), "/evangelou-club/v1/session");
    assert.ok(!f.calls[0].url.includes(VALID.nonce), "nonce never in the URL");
    const g = fakeFetch(() => json(200, { ended: true }));
    await createStaffApi(config(PLAIN_EXTRA), g).endSession().catch(() => undefined);
    assert.deepEqual(paramsOf(g.calls[0].url), [["rest_route", "/evangelou-club/v1/session/end"], ["lang", "el"]]);
  });

  it("the QR lookup and the redeem client use the plain form too", async () => {
    const f = fakeFetch(() => json(404, { error: { code: "member_not_found" } }));
    const api = createStaffApi(config(PLAIN_EXTRA), f);
    await createMemberApi(api, config(PLAIN_EXTRA).restBase).lookupQr(TOKEN, new AbortController().signal).catch(() => undefined);
    assert.deepEqual(paramsOf(f.calls[0].url), [["rest_route", "/evangelou-club/v1/members/lookup"], ["lang", "el"]]);
    assert.equal(new URL(f.calls[0].url).pathname, "/sub/index.php");
    const s = fakeFetch(() => json(404, { error: { code: "member_not_found" } }));
    await createMemberApi(createStaffApi(config(PLAIN_SUBDIR), s), config(PLAIN_SUBDIR).restBase).lookupQr(TOKEN, new AbortController().signal).catch(() => undefined);
    assert.equal(routeOf(s.calls[0].url), "/evangelou-club/v1/members/lookup");
    assert.equal(new URL(s.calls[0].url).pathname, "/sub/");

    const r = fakeFetch(() => json(200, []));
    const rest = createRestClubService({ baseUrl: PLAIN_EXTRA, fetchImpl: r });
    await rest.getCoffeeOptions().catch(() => undefined);
    assert.deepEqual(paramsOf(r.calls[0].url), [["rest_route", "/evangelou-club/v1/coffee-options"], ["lang", "el"]]);
    const id = "mem_" + "a".repeat(32);
    await rest.redeemBenefit({ memberId: id, benefitType: "free_coffee", requestId: "00000000-0000-4000-8000-000000000000", coffeeCode: "espresso" } as never).catch(() => undefined);
    assert.deepEqual(paramsOf(r.calls[1].url), [["rest_route", `/evangelou-club/v1/members/${id}/benefits/free_coffee/redeem`], ["lang", "el"]]);
  });
});
