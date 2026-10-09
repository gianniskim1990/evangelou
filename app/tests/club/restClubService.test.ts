import assert from "node:assert/strict";
import { afterEach, describe, it } from "node:test";
import {
  createRestClubService,
  mapErrorDetails,
  mapErrorResponse,
  mapLookupResponse,
  mapRedeemResponse,
} from "../../src/club/restClubService";
import { ClubApiError, type ClubErrorCode, type RedeemBenefitRequest } from "../../src/club/types";
import { CLUB_ERROR_MESSAGES } from "../../src/club/errors";
import { apiError, fakeFetch, json, text } from "./helpers";

const REQ: RedeemBenefitRequest = {
  memberId: "mem_0123456789abcdef0123456789abcdef",
  benefitType: "free_coffee",
  coffeeCode: "freddo_espresso",
  requestId: "3fa85f64-5717-4562-b3fc-2c963f66afa6",
};

const REDEEM_OK = {
  member_id: REQ.memberId,
  benefit_type: "free_coffee",
  state: "used",
  business_date: "2026-10-09",
  redeemed_at: "2026-10-09T13:00:00+03:00",
  coffee_code: "freddo_espresso",
  request_id: REQ.requestId,
  replayed: false,
};

const LOOKUP_OK = {
  member: { member_id: REQ.memberId, display_name: "Μαρία", phone_masked: "69••••••01" },
  membership: { status: "active", started_at: "2026-09-15T00:00:00+03:00", valid_until: "2026-10-15T23:59:59+03:00" },
  benefits: { free_coffee: { state: "available", business_date: "2026-10-09", redeemed_at: null } },
};

async function rejection(promise: Promise<unknown>): Promise<ClubApiError> {
  try {
    await promise;
  } catch (err) {
    assert.ok(err instanceof ClubApiError, `expected ClubApiError, got ${String(err)}`);
    return err;
  }
  assert.fail("expected the call to fail");
}

async function redeemCode(response: Response | (() => Response | Promise<Response>)): Promise<ClubApiError> {
  const fetchImpl = fakeFetch(typeof response === "function" ? response : () => response);
  return rejection(createRestClubService({ fetchImpl }).redeemBenefit(REQ));
}

describe("restClubService redeem: request shape", () => {
  it("POSTs request_id + coffee_code to the encoded benefit path with cookie credentials and no secrets", async () => {
    const fetchImpl = fakeFetch(() => json(200, REDEEM_OK));
    const outcome = await createRestClubService({ fetchImpl, baseUrl: "https://shop.test/wp-json/evangelou-club/v1/" }).redeemBenefit(REQ);

    assert.equal(fetchImpl.calls.length, 1);
    const { url, init } = fetchImpl.calls[0];
    assert.equal(url, `https://shop.test/wp-json/evangelou-club/v1/members/${REQ.memberId}/benefits/free_coffee/redeem`);
    assert.equal(init.method, "POST");
    assert.equal(init.credentials, "include");
    assert.deepEqual(JSON.parse(String(init.body)), { request_id: REQ.requestId, coffee_code: "freddo_espresso" });
    const headers = init.headers as Record<string, string>;
    assert.equal(headers.Authorization, undefined, "no static credential is ever sent");
    assert.deepEqual(outcome, {
      benefit: { state: "used", businessDate: "2026-10-09", redeemedAt: "2026-10-09T13:00:00+03:00", coffeeCode: "freddo_espresso" },
      requestId: REQ.requestId,
      replayed: false,
    });
  });

  it("URL-encodes member ids", async () => {
    const odd = { ...REQ, memberId: "a/b?c" };
    const fetchImpl = fakeFetch(() => json(200, { ...REDEEM_OK, member_id: "a/b?c" }));
    await createRestClubService({ fetchImpl }).redeemBenefit(odd);
    assert.ok(fetchImpl.calls[0].url.includes("/members/a%2Fb%3Fc/benefits/"));
  });

  it("maps a replayed success", async () => {
    const fetchImpl = fakeFetch(() => json(200, { ...REDEEM_OK, replayed: true }));
    assert.equal((await createRestClubService({ fetchImpl }).redeemBenefit(REQ)).replayed, true);
  });

  it("refuses to send a malformed request id (no network call)", async () => {
    const fetchImpl = fakeFetch(() => json(200, REDEEM_OK));
    const err = await rejection(createRestClubService({ fetchImpl }).redeemBenefit({ ...REQ, requestId: "member-1-espresso" }));
    assert.equal(err.code, "invalid_request");
    assert.equal(fetchImpl.calls.length, 0);
  });
});

describe("restClubService redeem: error mapping", () => {
  it("409 benefit_already_redeemed: snake_case details -> camelCase", async () => {
    const err = await redeemCode(
      apiError(409, "benefit_already_redeemed", {
        business_date: "2026-10-09",
        redeemed_at: "2026-10-09T10:42:16+03:00",
        coffee_code: "cappuccino",
      }),
    );
    assert.equal(err.code, "benefit_already_redeemed");
    assert.deepEqual(err.details, { businessDate: "2026-10-09", redeemedAt: "2026-10-09T10:42:16+03:00", coffeeCode: "cappuccino" });
    assert.equal(err.httpStatus, 409);
  });

  it("drops malformed/ambiguous details instead of inventing values", async () => {
    const err = await redeemCode(
      apiError(409, "benefit_already_redeemed", { business_date: "09/10/2026", redeemed_at: "2026-10-09 10:42:16", businessDate: "2026-10-09" }),
    );
    assert.equal(err.code, "benefit_already_redeemed");
    assert.equal(err.details, undefined, "naive timestamp and camelCase wire keys are not accepted");
  });

  it("409 membership_inactive carries the public status", async () => {
    const err = await redeemCode(apiError(409, "membership_inactive", { status: "expired", reason: "pending_payment" }));
    assert.equal(err.code, "membership_inactive");
    assert.deepEqual(err.details, { status: "expired" });
  });

  const cases: [string, Response, ClubErrorCode][] = [
    ["409 idempotency_key_reused", apiError(409, "idempotency_key_reused"), "idempotency_key_reused"],
    ["400 invalid_coffee", apiError(400, "invalid_coffee"), "invalid_coffee"],
    ["401 envelope", apiError(401, "unauthorized"), "unauthorized"],
    ["401 without envelope (e.g. WP HTML)", text(401, "<html>login</html>"), "unauthorized"],
    ["403 envelope", apiError(403, "forbidden"), "forbidden"],
    ["403 without envelope", text(403, ""), "forbidden"],
    ["500 envelope", apiError(500, "server_error"), "server_error"],
    ["502 HTML from a proxy", text(502, "<html>Bad Gateway</html>"), "server_error"],
    ["404 without envelope is NOT member_not_found", text(404, "Not Found"), "invalid_response"],
    ["409 without envelope is NOT already-redeemed", text(409, "conflict"), "invalid_response"],
    ["5xx claiming a definitive code is still a server error", apiError(500, "benefit_already_redeemed"), "server_error"],
    ["unknown envelope code", apiError(400, "totally_new_code"), "invalid_response"],
    ["200 with HTML (SPA rewrite of /wp-json)", text(200, "<!doctype html><html></html>"), "invalid_response"],
    ["200 with wrong shape", json(200, { ok: true }), "invalid_response"],
    ["200 echoing a different request_id", json(200, { ...REDEEM_OK, request_id: "11111111-1111-4111-8111-111111111111" }), "invalid_response"],
    ["200 for a different member", json(200, { ...REDEEM_OK, member_id: "mem_other" }), "invalid_response"],
    ["200 with a different coffee", json(200, { ...REDEEM_OK, coffee_code: "espresso" }), "invalid_response"],
    ["200 with a naive timestamp", json(200, { ...REDEEM_OK, redeemed_at: "2026-10-09 10:00:00" }), "invalid_response"],
    ["200 with an ISO timestamp lacking an offset", json(200, { ...REDEEM_OK, redeemed_at: "2026-10-09T10:00:00" }), "invalid_response"],
    ["200 with state available", json(200, { ...REDEEM_OK, state: "available" }), "invalid_response"],
  ];
  for (const [name, response, code] of cases) {
    it(`${name} -> ${code}`, async () => {
      assert.equal((await redeemCode(response)).code, code);
    });
  }

  it("429 reads Retry-After when the body has no retry hint", async () => {
    const err = await redeemCode(apiError(429, "rate_limited", undefined, "slow down"));
    assert.equal(err.code, "rate_limited");
    const withHeader = await redeemCode(json(429, { error: { code: "rate_limited", message: "x" } }, { "Retry-After": "30" }));
    assert.deepEqual(withHeader.details, { retryAfterSeconds: 30 });
  });

  it("never shows server message text to staff", async () => {
    const err = await redeemCode(apiError(500, "server_error", undefined, "SQLSTATE[23000] Duplicate entry for key uq_redemptions"));
    assert.equal(err.message, CLUB_ERROR_MESSAGES.server_error);
    assert.ok(!err.message.includes("SQLSTATE"));
    const html = await redeemCode(text(500, "<b>Fatal error</b>: Uncaught PDOException in /var/www/wp-content/plugins"));
    assert.ok(!html.message.includes("PDOException"));
  });

  it("network failure -> network_error", async () => {
    const err = await redeemCode(() => {
      throw new TypeError("Failed to fetch");
    });
    assert.equal(err.code, "network_error");
  });

  it("a hung request times out as network_error", async () => {
    const fetchImpl = fakeFetch(
      ({ init }) =>
        new Promise<Response>((_, reject) => {
          init.signal?.addEventListener("abort", () => reject(new DOMException("aborted", "AbortError")));
        }),
    );
    const err = await rejection(createRestClubService({ fetchImpl, timeoutMs: 20 }).redeemBenefit(REQ));
    assert.equal(err.code, "network_error");
  });

  it("a body stream cut mid-response -> network_error", async () => {
    const broken = new Response(
      new ReadableStream({
        start(controller) {
          controller.error(new Error("connection reset"));
        },
      }),
      { status: 200 },
    );
    assert.equal((await redeemCode(broken)).code, "network_error");
  });
});

describe("restClubService lookup and catalogue", () => {
  it("maps a lookup response to domain types", async () => {
    const fetchImpl = fakeFetch(() => json(200, LOOKUP_OK));
    const result = await createRestClubService({ fetchImpl }).findMemberByPhone("6900000001");
    assert.deepEqual(result, {
      member: { id: REQ.memberId, name: "Μαρία", phoneMasked: "69••••••01", status: "active", validUntil: "2026-10-15T23:59:59+03:00" },
      benefit: { state: "available", businessDate: "2026-10-09", redeemedAt: null, coffeeCode: null },
    });
    assert.deepEqual(JSON.parse(String(fetchImpl.calls[0].init.body)), { method: "phone", phone: "6900000001" });
    assert.ok(!fetchImpl.calls[0].url.includes("6900000001"), "phone never in the URL");
  });

  it("404 member_not_found becomes { member: null }; other failures propagate", async () => {
    const notFound = fakeFetch(() => apiError(404, "member_not_found"));
    assert.deepEqual(await createRestClubService({ fetchImpl: notFound }).findMemberByQrToken("evc_x"), { member: null, benefit: null });
    const bare404 = fakeFetch(() => text(404, "nope"));
    assert.equal((await rejection(createRestClubService({ fetchImpl: bare404 }).findMemberByQrToken("evc_x"))).code, "invalid_response");
  });

  it("rejects lookup DTOs with unknown statuses or naive dates", () => {
    assert.throws(() => mapLookupResponse({ ...LOOKUP_OK, membership: { ...LOOKUP_OK.membership, status: "gold" } }), ClubApiError);
    assert.throws(() => mapLookupResponse({ ...LOOKUP_OK, membership: { ...LOOKUP_OK.membership, valid_until: "2026-10-15" } }), ClubApiError);
    assert.throws(() => mapLookupResponse(null), ClubApiError);
  });

  it("maps the coffee catalogue", async () => {
    const fetchImpl = fakeFetch(() => json(200, { coffee_options: [{ code: "espresso", label: "Espresso" }] }));
    assert.deepEqual(await createRestClubService({ fetchImpl }).getCoffeeOptions(), [{ code: "espresso", label: "Espresso" }]);
    assert.equal(fetchImpl.calls[0].init.method, "GET");
  });
});

describe("pure mappers", () => {
  it("mapErrorDetails keeps only validated, known fields", () => {
    assert.deepEqual(mapErrorDetails({ business_date: "2026-10-09", redeemed_at: "2026-10-09T07:42:16Z", coffee_code: "espresso", status: "cancelled", retry_after_seconds: 5, sql: "x" }), {
      businessDate: "2026-10-09",
      redeemedAt: "2026-10-09T07:42:16Z",
      coffeeCode: "espresso",
      status: "cancelled",
      retryAfterSeconds: 5,
    });
    assert.equal(mapErrorDetails({ status: "gold", retry_after_seconds: -1 }), undefined);
    assert.equal(mapErrorDetails({ redeemed_at: "2026-10-09T10:42:16" }), undefined, "offset-less instant is ambiguous across DST");
    assert.equal(mapErrorDetails("x"), undefined);
  });

  it("mapErrorResponse tolerates empty and non-JSON bodies", () => {
    assert.equal(mapErrorResponse(500, "", null).code, "server_error");
    assert.equal(mapErrorResponse(418, "{", null).code, "invalid_response");
  });

  it("mapRedeemResponse accepts UTC Z and offset instants", () => {
    assert.equal(mapRedeemResponse({ ...REDEEM_OK, redeemed_at: "2026-10-09T10:00:00.123Z" }, REQ).benefit.redeemedAt, "2026-10-09T10:00:00.123Z");
  });
});

describe("no accidental live calls", () => {
  const realFetch = globalThis.fetch;
  afterEach(() => {
    globalThis.fetch = realFetch;
  });

  it("the app's clubService is the mock, and importing the REST module performs no request", async () => {
    let calls = 0;
    globalThis.fetch = (async () => {
      calls++;
      throw new Error("live network call attempted");
    }) as typeof fetch;

    const mod = await import("../../src/club/clubService");
    const rest = await import("../../src/club/restClubService");
    assert.equal(mod.clubService, mod.mockClubService);
    assert.notEqual(mod.clubService, rest.restClubService);
    const options = await mod.clubService.getCoffeeOptions();
    assert.ok(options.length > 0);
    assert.equal(calls, 0);
  });
});
