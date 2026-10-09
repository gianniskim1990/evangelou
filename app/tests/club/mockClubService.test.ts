import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { createMockClubService } from "../../src/club/clubService";
import { DEMO_COFFEE_OPTIONS } from "../../src/club/mockCoffeeCatalog";
import { LEGACY_MOCK_STORE_KEY, MOCK_STORE_KEY } from "../../src/club/mockRedemptionStore";
import { newRequestId } from "../../src/club/requestId";
import { ClubApiError, type RedeemBenefitRequest } from "../../src/club/types";
import { FakeClock, MemoryStorage } from "./helpers";

function setup(iso = "2026-10-09T10:00:00Z") {
  const storage = new MemoryStorage();
  const clock = new FakeClock(iso);
  const service = createMockClubService({ storage, now: clock.now, latencyMs: 0 });
  return { storage, clock, service };
}

function req(coffeeCode = "espresso", memberId = "member-1", requestId = newRequestId()): RedeemBenefitRequest {
  return { memberId, benefitType: "free_coffee", coffeeCode, requestId };
}

async function code(promise: Promise<unknown>): Promise<ClubApiError> {
  try {
    await promise;
  } catch (err) {
    assert.ok(err instanceof ClubApiError);
    return err;
  }
  assert.fail("expected rejection");
}

describe("mockClubService", () => {
  it("records the selected coffee and exposes it on lookup", async () => {
    const { service } = setup();
    const outcome = await service.redeemBenefit(req("greek_coffee"));
    assert.equal(outcome.replayed, false);
    assert.equal(outcome.benefit.coffeeCode, "greek_coffee");
    assert.equal(outcome.benefit.businessDate, "2026-10-09");
    const lookup = await service.findMemberByPhone("6900000001");
    assert.equal(lookup.benefit?.state, "used");
    assert.equal(lookup.benefit?.coffeeCode, "greek_coffee");
  });

  it("allows one coffee per member per Athens day; a different request gets already_redeemed", async () => {
    const { service } = setup();
    const first = await service.redeemBenefit(req("espresso"));
    const err = await code(service.redeemBenefit(req("cappuccino")));
    assert.equal(err.code, "benefit_already_redeemed");
    assert.deepEqual(err.details, { businessDate: "2026-10-09", redeemedAt: first.benefit.redeemedAt as string, coffeeCode: "espresso" });
  });

  it("replays the same request id with the original result", async () => {
    const { service, clock } = setup();
    const r = req("espresso");
    const first = await service.redeemBenefit(r);
    clock.set("2026-10-09T11:00:00Z");
    const again = await service.redeemBenefit(r);
    assert.equal(again.replayed, true);
    assert.deepEqual(again.benefit, first.benefit);
  });

  it("rejects request id reuse with a different coffee or member", async () => {
    const { service } = setup();
    const r = req("espresso");
    await service.redeemBenefit(r);
    assert.equal((await code(service.redeemBenefit({ ...r, coffeeCode: "cappuccino" }))).code, "idempotency_key_reused");
    const other = await code(service.redeemBenefit({ ...r, memberId: "member-2" }));
    assert.equal(other.code, "idempotency_key_reused");
    assert.equal(other.details, undefined, "never leaks the first member's redemption");
  });

  it("rejects inactive memberships, unknown coffee, unknown member and malformed ids", async () => {
    const { service } = setup();
    const inactive = await code(service.redeemBenefit(req("espresso", "member-3")));
    assert.equal(inactive.code, "membership_inactive");
    assert.deepEqual(inactive.details, { status: "expired" });
    assert.equal((await code(service.redeemBenefit(req("mocha_latte")))).code, "invalid_coffee");
    assert.equal((await code(service.redeemBenefit(req("espresso", "member-99")))).code, "member_not_found");
    assert.equal((await code(service.redeemBenefit(req("espresso", "member-1", "not-a-uuid")))).code, "invalid_request");
  });

  it("uses the Europe/Athens business day, not UTC or the device zone", async () => {
    const { service, clock } = setup("2026-01-01T21:30:00Z"); // 23:30 Athens, 1 Jan
    assert.equal((await service.redeemBenefit(req())).benefit.businessDate, "2026-01-01");
    clock.set("2026-01-01T22:30:00Z"); // 00:30 Athens, 2 Jan — same UTC date
    assert.equal((await service.redeemBenefit(req())).benefit.businessDate, "2026-01-02");
    clock.set("2026-01-02T09:00:00Z"); // same Athens day
    assert.equal((await code(service.redeemBenefit(req()))).code, "benefit_already_redeemed");
  });

  it("member 2 starts each day with a seeded, already-used coffee", async () => {
    const { service } = setup();
    const lookup = await service.findMemberByPhone("6900000002");
    assert.equal(lookup.benefit?.state, "used");
    assert.equal(lookup.benefit?.coffeeCode, "freddo_espresso");
  });

  it("serves the DEMO coffee catalogue (copies, not the shared array)", async () => {
    const { service } = setup();
    const options = await service.getCoffeeOptions();
    assert.deepEqual(options, DEMO_COFFEE_OPTIONS);
    options[0].label = "changed";
    assert.notEqual(DEMO_COFFEE_OPTIONS[0].label, "changed");
  });

  it("tolerates corrupt storage and imports valid legacy v1 data once", async () => {
    const { service, storage } = setup();
    storage.setItem(MOCK_STORE_KEY, "{not json");
    storage.setItem(
      LEGACY_MOCK_STORE_KEY,
      JSON.stringify({
        "member-1": { dateKey: "2026-10-09", redeemedAtISO: "2026-10-09T07:00:00.000Z" },
        "member-3": { dateKey: "garbage", redeemedAtISO: 5 },
      }),
    );
    const lookup = await service.findMemberByPhone("6900000001");
    assert.equal(lookup.benefit?.state, "used");
    assert.equal(lookup.benefit?.redeemedAt, "2026-10-09T07:00:00.000Z");
    assert.equal(lookup.benefit?.coffeeCode, null);
    assert.equal(storage.getItem(LEGACY_MOCK_STORE_KEY), null, "legacy key removed after import");

    storage.setItem(MOCK_STORE_KEY, JSON.stringify({ version: 2, byDay: { x: { memberId: 1 } }, byRequest: [] }));
    assert.equal((await service.findMemberByPhone("6900000003")).benefit?.state, "unavailable");
  });

  it("keeps working when storage writes fail (private mode)", async () => {
    const { service, storage } = setup();
    storage.failWrites = true;
    const outcome = await service.redeemBenefit(req());
    assert.equal(outcome.benefit.state, "used");
  });

  it("works with no storage at all (non-browser environment)", async () => {
    const service = createMockClubService({ storage: null, latencyMs: 0 });
    assert.equal((await service.findMemberByPhone("6900000001")).member?.id, "member-1");
  });
});
