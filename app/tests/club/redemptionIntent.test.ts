import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { createMockClubService, type ClubService } from "../../src/club/clubService";
import { clubError, keepsIntentOpen } from "../../src/club/errors";
import { RedemptionIntentTracker, submitRedemption, type IntentPayload } from "../../src/club/redemptionIntent";
import { newRequestId } from "../../src/club/requestId";
import { ClubApiError, type ClubErrorCode, type RedeemBenefitRequest, type RedemptionOutcome } from "../../src/club/types";
import { FakeClock, MemoryStorage, UUID_V4 } from "./helpers";

const MARIA: IntentPayload = { memberId: "member-1", benefitType: "free_coffee", coffeeCode: "espresso" };

function okOutcome(req: RedeemBenefitRequest, replayed = false): RedemptionOutcome {
  return {
    benefit: { state: "used", businessDate: "2026-10-09", redeemedAt: "2026-10-09T10:00:00Z", coffeeCode: req.coffeeCode },
    requestId: req.requestId,
    replayed,
  };
}

/** Service whose redeemBenefit fails with the given codes, then succeeds. */
function scriptedService(failures: (ClubErrorCode | "throw-plain")[]) {
  const requests: RedeemBenefitRequest[] = [];
  const service: Pick<ClubService, "redeemBenefit"> = {
    async redeemBenefit(req) {
      requests.push(req);
      const next = failures.shift();
      if (next === "throw-plain") throw new TypeError("boom");
      if (next) throw clubError(next);
      return okOutcome(req);
    },
  };
  return { service, requests };
}

/** Wraps a real (mock) service: the redemption COMMITS, but the response is lost. */
function losingFirstResponse(inner: ClubService) {
  let lost = false;
  const requests: RedeemBenefitRequest[] = [];
  const service: Pick<ClubService, "redeemBenefit"> = {
    async redeemBenefit(req) {
      requests.push(req);
      const outcome = await inner.redeemBenefit(req);
      if (!lost) {
        lost = true;
        throw clubError("network_error");
      }
      return outcome;
    },
  };
  return { service, requests };
}

describe("request ids", () => {
  it("are random lowercase UUID v4 values, never derived from the payload", () => {
    const ids = new Set(Array.from({ length: 200 }, () => newRequestId()));
    assert.equal(ids.size, 200);
    for (const id of ids) {
      assert.match(id, UUID_V4);
      assert.ok(!id.includes("member") && !id.includes("espresso"));
    }
  });

  it("fall back to getRandomValues when randomUUID is unavailable (non-secure context)", () => {
    const id = newRequestId({ getRandomValues: (a) => globalThis.crypto.getRandomValues(a) });
    assert.match(id, UUID_V4);
  });
});

describe("keepsIntentOpen", () => {
  it("keeps the intent open for unknown outcomes, including non-ClubApiError throwables", () => {
    assert.equal(keepsIntentOpen(new TypeError("Failed to fetch")), true);
    assert.equal(keepsIntentOpen("weird"), true);
    for (const code of ["network_error", "invalid_response", "server_error", "rate_limited", "unauthorized"] as const) {
      assert.equal(keepsIntentOpen(clubError(code)), true, code);
    }
    for (const code of ["invalid_coffee", "membership_inactive", "benefit_already_redeemed", "idempotency_key_reused", "forbidden"] as const) {
      assert.equal(keepsIntentOpen(clubError(code)), false, code);
    }
  });
});

describe("RedemptionIntentTracker + submitRedemption", () => {
  it("keeps ONE request_id across retries of the same intent", async () => {
    const tracker = new RedemptionIntentTracker();
    const { service, requests } = scriptedService(["network_error", "server_error", "invalid_response", "rate_limited", "unauthorized"]);
    const kinds: string[] = [];
    for (let i = 0; i < 6; i++) kinds.push((await submitRedemption(service, tracker, MARIA)).kind);

    assert.deepEqual(kinds, ["retry_needed", "retry_needed", "retry_needed", "retry_needed", "retry_needed", "redeemed"]);
    assert.equal(requests.length, 6);
    assert.equal(new Set(requests.map((r) => r.requestId)).size, 1, "every attempt reused the same UUID");
    assert.match(requests[0].requestId, UUID_V4);
    assert.equal(tracker.current, null, "success closes the intent");
  });

  it("does not regenerate the id when intentFor is called repeatedly (re-renders)", () => {
    const tracker = new RedemptionIntentTracker();
    const first = tracker.intentFor(MARIA);
    for (let i = 0; i < 10; i++) assert.equal(tracker.intentFor({ ...MARIA }).requestId, first.requestId);
  });

  it("gives a new logical intent a fresh id after success", async () => {
    const tracker = new RedemptionIntentTracker();
    const { service, requests } = scriptedService([]);
    await submitRedemption(service, tracker, MARIA);
    await submitRedemption(service, tracker, MARIA);
    assert.notEqual(requests[0].requestId, requests[1].requestId);
  });

  it("closes the intent on definitive rejections", async () => {
    for (const code of ["invalid_coffee", "membership_inactive", "idempotency_key_reused", "forbidden", "member_not_found", "invalid_request"] as const) {
      const tracker = new RedemptionIntentTracker();
      const { service, requests } = scriptedService([code]);
      const attempt = await submitRedemption(service, tracker, MARIA);
      assert.equal(attempt.kind, "rejected", code);
      assert.equal(tracker.current, null, `${code} closes the intent`);
      await submitRedemption(service, tracker, MARIA);
      assert.notEqual(requests[0].requestId, requests[1].requestId, `${code}: next attempt is a new intent`);
    }
  });

  it("reports benefit_already_redeemed separately and closes the intent", async () => {
    const tracker = new RedemptionIntentTracker();
    const { service } = scriptedService(["benefit_already_redeemed"]);
    const attempt = await submitRedemption(service, tracker, MARIA);
    assert.equal(attempt.kind, "already_redeemed");
    assert.equal(tracker.current, null);
  });

  it("treats unexpected non-ClubApiError failures as an unknown outcome", async () => {
    const tracker = new RedemptionIntentTracker();
    const { service, requests } = scriptedService(["throw-plain"]);
    const attempt = await submitRedemption(service, tracker, MARIA);
    assert.equal(attempt.kind, "retry_needed");
    assert.ok(attempt.kind === "retry_needed" && attempt.error instanceof ClubApiError && attempt.error.code === "invalid_response");
    await submitRedemption(service, tracker, MARIA);
    assert.equal(requests[0].requestId, requests[1].requestId);
  });

  it("changing the coffee opens a NEW intent instead of reusing the old id", async () => {
    const tracker = new RedemptionIntentTracker();
    const { service, requests } = scriptedService(["network_error"]);
    await submitRedemption(service, tracker, MARIA);
    await submitRedemption(service, tracker, { ...MARIA, coffeeCode: "cappuccino" });
    assert.notEqual(requests[0].requestId, requests[1].requestId);
    assert.equal(requests[1].coffeeCode, "cappuccino");
  });

  it("reset (member change) drops the pending intent", async () => {
    const tracker = new RedemptionIntentTracker();
    const { service, requests } = scriptedService(["network_error"]);
    await submitRedemption(service, tracker, MARIA);
    assert.notEqual(tracker.current, null);
    tracker.reset();
    assert.equal(tracker.current, null);
    await submitRedemption(service, tracker, MARIA);
    assert.notEqual(requests[0].requestId, requests[1].requestId);
  });

  it("a different member never reuses another member's pending id", async () => {
    const tracker = new RedemptionIntentTracker();
    const { service, requests } = scriptedService(["network_error"]);
    await submitRedemption(service, tracker, MARIA);
    await submitRedemption(service, tracker, { ...MARIA, memberId: "member-2" });
    assert.notEqual(requests[0].requestId, requests[1].requestId);
  });

  it("refuses a concurrent second submit while one is in flight (double tap)", async () => {
    const tracker = new RedemptionIntentTracker();
    let release!: () => void;
    const gate = new Promise<void>((r) => (release = r));
    let calls = 0;
    const service: Pick<ClubService, "redeemBenefit"> = {
      async redeemBenefit(req) {
        calls++;
        await gate;
        return okOutcome(req);
      },
    };
    const first = submitRedemption(service, tracker, MARIA);
    const second = await submitRedemption(service, tracker, MARIA);
    assert.equal(second.kind, "busy");
    release();
    assert.equal((await first).kind, "redeemed");
    assert.equal(calls, 1);
  });

  it("recovers a lost response by replaying the SAME id: exactly one redemption", async () => {
    const storage = new MemoryStorage();
    const clock = new FakeClock("2026-10-09T10:00:00Z");
    const mock = createMockClubService({ storage, now: clock.now, latencyMs: 0 });
    const { service, requests } = losingFirstResponse(mock);
    const tracker = new RedemptionIntentTracker();

    const lost = await submitRedemption(service, tracker, MARIA);
    assert.equal(lost.kind, "retry_needed");
    const retry = await submitRedemption(service, tracker, MARIA);

    assert.equal(retry.kind, "redeemed");
    assert.ok(retry.kind === "redeemed" && retry.outcome.replayed, "server replayed the committed result");
    assert.equal(requests[0].requestId, requests[1].requestId);
    const store = JSON.parse(storage.getItem("evaggelou-club-redemptions-v2") as string);
    assert.equal(Object.keys(store.byRequest).length, 1);
  });

  it("a retry after Athens midnight keeps the original id and the original business date", async () => {
    const storage = new MemoryStorage();
    const clock = new FakeClock("2026-10-09T20:59:00Z"); // 23:59 Athens
    const mock = createMockClubService({ storage, now: clock.now, latencyMs: 0 });
    const { service, requests } = losingFirstResponse(mock);
    const tracker = new RedemptionIntentTracker();

    await submitRedemption(service, tracker, MARIA);
    clock.set("2026-10-09T21:01:00Z"); // 00:01 Athens, next day
    const retry = await submitRedemption(service, tracker, MARIA);

    assert.equal(requests[0].requestId, requests[1].requestId);
    assert.ok(retry.kind === "redeemed");
    assert.equal(retry.outcome.benefit.businessDate, "2026-10-09");
    assert.equal(retry.outcome.replayed, true);
  });

  it("if an abandoned intent had committed, a new coffee choice gets already_redeemed (no double coffee)", async () => {
    const storage = new MemoryStorage();
    const mock = createMockClubService({ storage, now: new FakeClock("2026-10-09T10:00:00Z").now, latencyMs: 0 });
    const { service } = losingFirstResponse(mock);
    const tracker = new RedemptionIntentTracker();

    await submitRedemption(service, tracker, MARIA);
    const other = await submitRedemption(service, tracker, { ...MARIA, coffeeCode: "cappuccino" });

    assert.equal(other.kind, "already_redeemed");
    assert.ok(other.kind === "already_redeemed");
    assert.equal(other.error.details?.coffeeCode, "espresso");
  });
});
