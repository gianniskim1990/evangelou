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
// STAGE-08 round 5: the shell sends the site's home path (wp_parse_url(home_url('/'), PHP_URL_PATH)).
const parse = (restBase: unknown, homePath: unknown = "/sub/") => parseStaffConfig(JSON.stringify({ ...VALID, restBase, homePath }), ORIGIN);

function config(restBase: string, homePath = "/sub/"): StaffConfig {
  const r = parse(restBase, homePath);
  assert.ok(r.ok, `accepted: ${restBase}`);
  return r.config;
}

const routeOf = (url: string) => new URL(url).searchParams.get("rest_route");

describe("STAGE-08 round 4: staff REST base in both permalink forms", () => {
  it("accepts pretty and plain permalinks (site root, sub-folder, index.php)", () => {
    assert.equal(config(PRETTY).restBase, PRETTY);
    assert.equal(config(`${PRETTY}/`).restBase, PRETTY, "trailing slash dropped");
    for (const [base, home] of [[PLAIN_INDEX, "/sub/"], [PLAIN_ROOT, "/"], [PLAIN_SUBDIR, "/sub/"]]) {
      assert.equal(routeOf(config(base, home).restBase), STAFF_NAMESPACE, base);
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
    for (const [label, base] of bad) assert.equal(parse(base, "/").ok, false, label);
    assert.equal(sameOriginRestBase(`${ORIGIN}/?rest_route=/evangelou-club/v1`, ORIGIN, "/evangelou-club-customer/v1"), null);
  });

  it("round 5: refuses any parameter PHP reads as rest_route (plain and pretty bases)", () => {
    const plain = `${ORIGIN}/?rest_route=/evangelou-club/v1`;
    const aliases: [string, string][] = [
      ["rest.route after rest_route (PHP: later key wins)", `${plain}&rest.route=/wp/v2/users`],
      ["rest.route before rest_route", `${ORIGIN}/?rest.route=/wp/v2/users&rest_route=/evangelou-club/v1`],
      ["rest%20route", `${plain}&rest%20route=/wp/v2/users`],
      ["rest+route", `${plain}&rest+route=/wp/v2/users`],
      ["rest[route (unmatched [)", `${plain}&rest[route=/wp/v2/users`],
      ["rest%5Broute (encoded unmatched [)", `${plain}&rest%5Broute=/wp/v2/users`],
      ["leading space", `${plain}&%20rest_route=/wp/v2/users`],
      ["array form rest_route[]", `${plain}&rest_route[]=/wp/v2/users`],
      ["array form rest.route[x]", `${plain}&rest.route[x]=/wp/v2/users`],
      ["only an alias, no rest_route", `${ORIGIN}/?rest.route=/evangelou-club/v1`],
      ["pretty base with ?rest.route=", `${PRETTY}?rest.route=/wp/v2/users`],
      ["pretty base with ?rest%20route=", `${PRETTY}?rest%20route=/wp/v2/users`],
      ["pretty base with ?rest[route=", `${PRETTY}?rest[route=/wp/v2/users`],
    ];
    for (const [label, base] of aliases) assert.equal(sameOriginRestBase(base, ORIGIN, STAFF_NAMESPACE, "/"), null, label);
    // Names PHP does NOT turn into rest_route stay allowed.
    assert.ok(sameOriginRestBase(`${plain}&rest-route=x&lang=el`, ORIGIN, STAFF_NAMESPACE, "/"));
    assert.ok(sameOriginRestBase(`${PRETTY}?lang=el`, ORIGIN, STAFF_NAMESPACE));
  });

  it("round 5: a plain base sits exactly at the shell's home path (or its index.php)", () => {
    const at = (base: string, home: unknown) => sameOriginRestBase(base, ORIGIN, STAFF_NAMESPACE, home);
    assert.ok(at(`${ORIGIN}/index.php?rest_route=/evangelou-club/v1`, "/"));
    assert.ok(at(`${ORIGIN}/?rest_route=/evangelou-club/v1`, "/"));
    assert.ok(at(PLAIN_INDEX, "/sub/"));
    const refused: [string, string, unknown][] = [
      ["wp-admin/index.php", `${ORIGIN}/wp-admin/index.php?rest_route=/evangelou-club/v1`, "/"],
      ["any page folder", `${ORIGIN}/any/page/?rest_route=/evangelou-club/v1`, "/"],
      ["a sub-folder of the home path", `${ORIGIN}/sub/x/?rest_route=/evangelou-club/v1`, "/sub/"],
      ["the parent of the home path", `${ORIGIN}/?rest_route=/evangelou-club/v1`, "/sub/"],
      ["another folder's index.php", PLAIN_INDEX, "/"],
      ["no home path", PLAIN_INDEX, undefined],
      ["home path not a string", PLAIN_INDEX, 1],
      ["home path without a leading slash", PLAIN_INDEX, "sub/"],
      ["home path without a trailing slash", `${ORIGIN}/sub?rest_route=/evangelou-club/v1`, "/sub"],
      ["protocol-relative home path", `${ORIGIN}//evil/?rest_route=/evangelou-club/v1`, "//evil/"],
      ["home path with a dot-segment", `${ORIGIN}/?rest_route=/evangelou-club/v1`, "/sub/../"],
      ["home path with a query", PLAIN_INDEX, "/sub/?x/"],
    ];
    for (const [label, base, home] of refused) assert.equal(at(base, home), null, label);
    const withoutHome = (restBase: string) => parseStaffConfig(JSON.stringify({ ...VALID, restBase }), ORIGIN);
    assert.equal(withoutHome(PLAIN_INDEX).ok, false, "the staff config without homePath refuses a plain base");
    assert.equal(parse(PLAIN_INDEX, null).ok, false, "homePath null");
    assert.ok(withoutHome(PRETTY).ok, "a pretty base needs no homePath");
  });

  it("round 5: refuses an empty fragment and raw dot-segments", () => {
    const at = (base: string) => sameOriginRestBase(base, ORIGIN, STAFF_NAMESPACE, "/sub/");
    for (const base of [`${PLAIN_INDEX}#`, `${PLAIN_SUBDIR}#`, `${PRETTY}#`, `${PRETTY}/#`]) assert.equal(at(base), null, base);
    const dots = [
      `${ORIGIN}/sub/wp-json/x/../evangelou-club/v1`,
      `${ORIGIN}/sub/wp-json/x/%2e%2e/evangelou-club/v1`,
      `${ORIGIN}/sub/wp-json/x/%2E%2E/evangelou-club/v1`,
      `${ORIGIN}/sub/wp-json/x/.%2e/evangelou-club/v1`,
      `${ORIGIN}/sub/./wp-json/evangelou-club/v1`,
      `${ORIGIN}/sub/wp-json/%2e/evangelou-club/v1`,
      `${ORIGIN}/sub/x/../index.php?rest_route=/evangelou-club/v1`,
    ];
    for (const base of dots) assert.equal(at(base), null, base);
    assert.ok(at(PRETTY), "no dot-segment, accepted");
  });

  it("round 6: tab, CR and LF are refused anywhere in the base (the URL parser would strip them past the dot-segment check)", () => {
    const at = (base: string, home: unknown = "/sub/") => sameOriginRestBase(base, ORIGIN, STAFF_NAMESPACE, home);
    const sneaky = [
      `${ORIGIN}/sub/wp-json/x/.\t./evangelou-club/v1`,
      `${ORIGIN}/sub/wp-json/x/.\n./evangelou-club/v1`,
      `${ORIGIN}/sub/wp-json/x/\r../evangelou-club/v1`,
      `${ORIGIN}/sub/x/.\t./index.php?rest_route=/evangelou-club/v1`,
      `${ORIGIN}/sub/index.php?rest_route=/evangelou-club/v1\n`,
      `${ORIGIN}/sub/index.php?rest\t_route=/evangelou-club/v1`,
      `\t${PRETTY}`,
      `${PRETTY}\r\n`,
      `${ORIGIN}/s\tub/wp-json/evangelou-club/v1`,
    ];
    assert.equal(new URL(sneaky[0]).pathname, "/sub/wp-json/evangelou-club/v1", "why: the parser strips the tab, then resolves «..»");
    for (const base of sneaky) assert.equal(at(base), null, JSON.stringify(base));
    assert.equal(at(PLAIN_INDEX, "/s\tub/"), null, "nor in the home path");
    assert.throws(() => restRouteUrl(`${PRETTY}\t`, "/session"), TypeError);
    assert.ok(at(PRETTY) && at(PLAIN_INDEX), "the clean bases still pass");
  });

  it("round 6: with a valid homePath a pretty base must sit under it (staging: /prometheus/)", () => {
    const at = (base: string, home: unknown) => sameOriginRestBase(base, ORIGIN, STAFF_NAMESPACE, home);
    const staging = `${ORIGIN}/prometheus/wp-json/evangelou-club/v1`;
    assert.equal(at(staging, "/prometheus/"), staging, "the staging pretty base passes");
    assert.equal(at(`${staging}/`, "/prometheus/"), staging);
    assert.ok(at(`${ORIGIN}/wp-json/evangelou-club/v1`, "/"), "a root install");
    assert.ok(at(staging, "/"), "the root home path covers every folder");
    for (const [label, base, home] of [
      ["another folder", `${ORIGIN}/other/wp-json/evangelou-club/v1`, "/prometheus/"],
      ["the site root under a sub-folder install", `${ORIGIN}/wp-json/evangelou-club/v1`, "/prometheus/"],
      ["a look-alike folder", `${ORIGIN}/prometheus-x/wp-json/evangelou-club/v1`, "/prometheus/"],
      ["the folder without its slash", `${ORIGIN}/prometheuswp-json/evangelou-club/v1`, "/prometheus/"],
    ] as const) {
      assert.equal(at(base, home), null, label);
    }
    assert.equal(at(`${ORIGIN}/prometheus/wp-json/wp/v2`, "/prometheus/"), null, "the namespace suffix is still required");
    assert.ok(at(staging, undefined), "no homePath (an older shell): the suffix check alone, as before");
    assert.ok(at(staging, "not-a-path"), "an invalid homePath is ignored for a pretty base (it is refused for a plain one)");
    assert.equal(at(`${ORIGIN}/prometheus/?rest_route=/evangelou-club/v1`, "not-a-path"), null);
    const nonAscii = `${ORIGIN}/%CE%BA%CE%B1%CF%86%CE%AD%CF%82/wp-json/evangelou-club/v1`;
    assert.equal(at(`${ORIGIN}/καφές/wp-json/evangelou-club/v1`, "/%CE%BA%CE%B1%CF%86%CE%AD%CF%82/"), nonAscii, "a non-ASCII home path in the encoded form PHP now sends");
    assert.equal(at(`${ORIGIN}/καφές/index.php?rest_route=/evangelou-club/v1`, "/%CE%BA%CE%B1%CF%86%CE%AD%CF%82/") !== null, true);
    assert.equal(at(`${ORIGIN}/καφές/index.php?rest_route=/evangelou-club/v1`, "/καφές/"), null, "the raw form never matches URL.pathname");
  });

  it("round 6: a relative base must be root-relative (exactly one leading slash)", () => {
    assert.equal(restRouteUrl("/wp-json/evangelou-club/v1", "/session"), "/wp-json/evangelou-club/v1/session");
    for (const base of ["wp-json/evangelou-club/v1", "./wp-json/evangelou-club/v1", "//evil.example/wp-json/evangelou-club/v1", "/\\evil.example/wp-json/evangelou-club/v1", "\\evil.example/x", "?rest_route=/evangelou-club/v1", ""]) {
      assert.throws(() => restRouteUrl(base, "/session"), TypeError, JSON.stringify(base));
    }
  });

  it("round 6: a refused relative base reaches no server: the redeem client reports a network error", async () => {
    const f = fakeFetch(() => json(200, {}));
    const rest = createRestClubService({ baseUrl: "//evil.example/wp-json/evangelou-club/v1", fetchImpl: f });
    await assert.rejects(rest.redeemBenefit({ memberId: "mem_x", benefitType: "free_coffee", requestId: "00000000-0000-4000-8000-000000000000", coffeeCode: "espresso" } as never), (e: { code?: string }) => e.code === "network_error");
    assert.equal(f.calls.length, 0);
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
    // Round 5: a relative base is built by the same code (and encoded the same way) as an absolute one.
    const relativePlain = restRouteUrl("/index.php?rest_route=/evangelou-club/v1&lang=el", "/session");
    assert.equal(relativePlain, "/index.php?rest_route=%2Fevangelou-club%2Fv1%2Fsession&lang=el");
    assert.equal(`${ORIGIN}${relativePlain}`, restRouteUrl(`${ORIGIN}/index.php?rest_route=/evangelou-club/v1&lang=el`, "/session"));
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
