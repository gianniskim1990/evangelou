import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, it } from "node:test";
import { parseCatalogue, parsePreparation, validatePreparation } from "../../src/club/preparationContract";
import { MEMBERS } from "../../src/preview/previewData";
import { EMPTY_ORDER, orderReducer, usualOrder, type CoffeeOrder, type OrderAction } from "../../src/preview/coffeeConfig";
import { availableActions, shouldOfferPrepare, type MachineState } from "../../src/staff/redemptionRecovery/machine";
import { CONTRACT_STATUS, classifyHttpReply, type ReplySurface } from "../../src/staff/redemptionRecovery/wire";
import { CounterFlow, MANAGER_NOTIFIED, ORDER_NOT_IN_MEMORY } from "../../src/staff/counterPreview/counterFlow";
import { FAKE_DIRECTORY, ROTATED_QR_TOKEN, SHARED_PHONE, canonicalGreekMobile, entryByPreviewId } from "../../src/staff/counterPreview/fakeDirectory";
import { FakeClubServer, type HttpReply } from "../../src/staff/counterPreview/fakeServer";
import { CANONICAL_COFFEE, toPreparedPayload } from "../../src/staff/counterPreview/orderPayload";
import { PREVIEW_CATALOGUE_DOCUMENT } from "../../src/staff/counterPreview/previewCatalogue";
import { PreviewTabStorage } from "../../src/staff/counterPreview/previewSession";
import { abortableSleep } from "../../src/staff/counterPreview/previewTransport";

/*
 * UI-04O — the integrated counter flow, driven end to end through the REAL
 * StaffController, StaffRedemptionMachine, recovery storage adapter, wire
 * parsers and classifyHttpReply over the deterministic fake server. No DOM,
 * no network, no timers (latency 0), fixed clock.
 */

/** Monday 19 October 2026, 10:30 in Athens (EEST, UTC+3). */
const MORNING = "2026-10-19T07:30:00Z";
/** 23:59:30 in Athens on the same day. */
const BEFORE_MIDNIGHT = "2026-10-19T20:59:30Z";

function setup(opts: { at?: string } = {}) {
  let now = new Date(opts.at ?? MORNING);
  const clock = { now: () => now, advance: (ms: number) => (now = new Date(now.getTime() + ms)) };
  const server = new FakeClubServer({ now: clock.now });
  const storage = new PreviewTabStorage();
  let ids = 0;
  let sessions = 0;
  const flow = new CounterFlow({
    server,
    storage,
    now: clock.now,
    newId: () => `00000000-0000-4000-8000-${(0xa00000 + ++ids).toString(16).padStart(12, "0")}`,
    newSessionRef: () => (++sessions).toString(16).padStart(32, "5"),
    sha256Hex: async (text) => createHash("sha256").update(text, "utf8").digest("hex"),
    latencyMs: 0,
    sleep: abortableSleep,
    deviceRef: "d".repeat(32),
  });
  return { flow, server, storage, clock };
}

const member = (previewId: string) => entryByPreviewId(previewId);
const s = (flow: CounterFlow) => flow.getState();
const phase = (flow: CounterFlow) => flow.getState().machine.phase;

function noPrepare(state: MachineState): void {
  assert.equal(shouldOfferPrepare(state), false, `${state.phase} must not offer prepare`);
  assert.ok(!availableActions(state).includes("acknowledge_prepare"), `${state.phase} lists no prepare action`);
}

function requestId(flow: CounterFlow): string {
  const m = s(flow).machine as { intent?: { requestId: string } };
  assert.ok(m.intent, "state carries the intent");
  return m.intent.requestId;
}

const ESPRESSO_ORDER: OrderAction[] = [
  { type: "coffee", coffee: "espresso" },
  { type: "choice", group: "shots", value: "double" },
  { type: "choice", group: "sweetness", value: "none" },
  { type: "quick-note", id: "takeaway" },
];

/** Lookup by QR, open coffee, build a complete order, review and confirm. */
async function orderFor(flow: CounterFlow, previewId: string, actions: OrderAction[] = ESPRESSO_ORDER): Promise<void> {
  flow.open("scan");
  await flow.lookup({ qrToken: member(previewId).qrToken });
  assert.equal(s(flow).screen, "member");
  flow.chooseCoffee();
  assert.equal(s(flow).screen, "coffee");
  for (const a of actions) flow.dispatchOrder(a);
  flow.review();
  assert.equal(s(flow).screen, "confirm");
  await flow.confirm();
}

function redeemCalls(server: FakeClubServer): string[] {
  return server.log().filter((e) => e.operation === "redeem").map((e) => e.ids[0]);
}

describe("UI-04O counter flow: the normal counter path", () => {
  it("dashboard → QR → eligible member → coffee → confirm → prepare once → handed now → finish", async () => {
    const { flow, server } = setup();
    await flow.start();
    assert.equal(s(flow).session.phase, "ready");
    assert.equal(s(flow).screen, "dashboard");
    await orderFor(flow, "m01");
    assert.equal(phase(flow), "recorded_now");
    assert.equal(s(flow).screen, "redemption");
    assert.equal(shouldOfferPrepare(s(flow).machine), true, "a fresh commit offers prepare");
    assert.equal(server.redemptionCount(member("m01").memberId, "2026-10-19"), 1);

    assert.equal(await flow.act("acknowledge_prepare"), "applied");
    noPrepare(s(flow).machine);
    assert.equal(await flow.act("acknowledge_prepare"), "rejected", "the prepare prompt is shown exactly once");
    assert.equal(await flow.act("confirm_handed_now"), "applied");
    assert.equal(phase(flow), "handoff_confirmed");
    const confirmed = s(flow).machine as Extract<MachineState, { phase: "handoff_confirmed" }>;
    assert.equal(confirmed.handoff.claim, "handed_now");
    assert.equal(await flow.act("finish"), "applied");
    assert.equal(s(flow).screen, "dashboard");
    assert.equal(s(flow).summary, null, "member data is dropped after the visit");
  });

  it("the same member cannot get a second coffee the same Athens day: the summary says used and coffee selection stays closed", async () => {
    const { flow, server } = setup();
    await flow.start();
    await orderFor(flow, "m01");
    await flow.act("acknowledge_prepare");
    await flow.act("finish");
    flow.open("scan");
    await flow.lookup({ qrToken: member("m01").qrToken });
    assert.equal(s(flow).summary?.benefit.state, "used");
    flow.chooseCoffee();
    assert.equal(s(flow).screen, "member", "no coffee selection for a used benefit");
    assert.equal(server.redemptionCount(member("m01").memberId, "2026-10-19"), 1);
  });

  it("phone lookup accepts the Greek mobile forms and finds the same member", async () => {
    const { flow } = setup();
    await flow.start();
    const phone = member("m05").phone;
    for (const typed of [phone, phone.replace("+30", "0030"), phone.slice(3).replace(/(\d{4})(\d{3})(\d{3})/, "$1 $2 $3")]) {
      flow.open("phone");
      await flow.lookup({ phone: typed });
      assert.equal(s(flow).screen, "member", typed);
      assert.equal(s(flow).summary?.member.id, member("m05").memberId);
      assert.equal(s(flow).summary?.member.phoneMasked, member("m05").person.phoneMasked, "only the masked phone reaches the screen");
    }
  });

  it("lookup refusals stay on the lookup screen with no member data", async () => {
    const { flow } = setup();
    await flow.start();
    const cases: [{ qrToken: string } | { phone: string }, string][] = [
      [{ qrToken: ROTATED_QR_TOKEN }, "not_found"],
      [{ phone: "2101234567" }, "invalid_phone"],
      [{ phone: "6910000000" }, "not_found"],
      [{ phone: SHARED_PHONE }, "ambiguous"],
    ];
    for (const [by, notice] of cases) {
      flow.open("qrToken" in by ? "scan" : "phone");
      await flow.lookup(by);
      assert.equal(s(flow).lookupNotice, notice);
      assert.equal(s(flow).summary, null);
      assert.notEqual(s(flow).screen, "member");
    }
  });

  it("inactive memberships (expired, payment pending) show the reason and never open coffee selection", async () => {
    const { flow } = setup();
    await flow.start();
    for (const id of ["m03", "m04"]) {
      flow.open("scan");
      await flow.lookup({ qrToken: member(id).qrToken });
      assert.equal(s(flow).summary?.benefit.state, "unavailable", id);
      assert.equal(s(flow).summary?.benefit.reason, "membership_inactive", id);
      flow.chooseCoffee();
      assert.equal(s(flow).screen, "member", id);
    }
  });

  it("members who already had today's coffee (approved synthetic data) show it as used", async () => {
    const { flow } = setup();
    await flow.start();
    for (const m of MEMBERS.filter((p) => p.redeemedToday)) {
      flow.open("scan");
      await flow.lookup({ qrToken: entryByPreviewId(m.id).qrToken });
      assert.equal(s(flow).summary?.benefit.state, "used", m.id);
    }
  });

  it("an incomplete order cannot be reviewed or sent; nothing is filled in automatically", async () => {
    const { flow, server } = setup();
    await flow.start();
    flow.open("scan");
    await flow.lookup({ qrToken: member("m01").qrToken });
    flow.chooseCoffee();
    flow.dispatchOrder({ type: "coffee", coffee: "cappuccino" });
    flow.review();
    assert.equal(s(flow).screen, "coffee");
    assert.equal(await flow.confirm(), "rejected");
    assert.deepEqual(redeemCalls(server), []);
  });
});

describe("UI-04O counter flow: the server stays the authority", () => {
  it("another tablet redeemed first: the stale summary said available, the server refuses and nothing is prepared", async () => {
    const { flow, server } = setup();
    await flow.start();
    server.schedule("redeem", "another_tablet_first");
    await orderFor(flow, "m01");
    assert.equal(phase(flow), "redemption_refused");
    assert.equal((s(flow).machine as Extract<MachineState, { phase: "redemption_refused" }>).code, "benefit_already_redeemed");
    noPrepare(s(flow).machine);
    assert.equal(server.redemptionCount(member("m01").memberId, "2026-10-19"), 1, "only the other tablet's record exists");
  });
});

describe("UI-04O counter flow: uncertain outcomes keep the ORIGINAL id and never prepare twice", () => {
  it("reply lost after the commit: retry sends the same id, the server replays, no prepare, one record", async () => {
    const { flow, server } = setup();
    await flow.start();
    server.schedule("redeem", "lose_reply_after_commit");
    await orderFor(flow, "m01");
    assert.equal(phase(flow), "redemption_unresolved");
    noPrepare(s(flow).machine);
    const rid = requestId(flow);
    assert.equal(await flow.act("retry_redemption"), "applied");
    assert.equal(phase(flow), "previously_recorded_unconfirmed");
    noPrepare(s(flow).machine);
    assert.deepEqual(redeemCalls(server), [rid, rid], "the retry reused the original request id");
    assert.equal(server.redemptionCount(member("m01").memberId, "2026-10-19"), 1);
    // A handoff statement needs the explicit manual check first, and is never proof of delivery.
    assert.equal(await flow.act("confirm_handed_earlier"), "rejected");
    assert.equal(await flow.act("confirm_manual_verification"), "applied");
    assert.equal(await flow.act("confirm_handed_earlier"), "applied");
    const done = s(flow).machine as Extract<MachineState, { phase: "handoff_confirmed" }>;
    assert.equal(done.handoff.claim, "already_handed_earlier");
    assert.equal(done.handoff.handoffAtUtc, null, "no handoff time is invented for an earlier handoff");
  });

  it("request lost before the server: status says not recorded, the SAME intent is resent and only then prepare is offered", async () => {
    const { flow, server } = setup();
    await flow.start();
    server.schedule("redeem", "lose_request");
    await orderFor(flow, "m01");
    assert.equal(phase(flow), "redemption_unresolved");
    const rid = requestId(flow);
    assert.equal(await flow.act("check_status"), "applied");
    assert.equal(phase(flow), "recovery_required");
    assert.equal((s(flow).machine as Extract<MachineState, { phase: "recovery_required" }>).reason, "not_recorded");
    noPrepare(s(flow).machine);
    assert.equal(await flow.act("resubmit_same_intent"), "applied");
    assert.equal(phase(flow), "recorded_now");
    assert.equal(requestId(flow), rid, "the original id is reused after an authoritative not-found");
    assert.equal(shouldOfferPrepare(s(flow).machine), true);
    assert.equal(server.redemptionCount(member("m01").memberId, "2026-10-19"), 1);
  });

  it("outcome unknown after a commit: the status read finds the record, verify-handoff, never prepare", async () => {
    const { flow, server } = setup();
    await flow.start();
    server.schedule("redeem", "outcome_unknown_after_commit");
    await orderFor(flow, "m01");
    assert.equal(phase(flow), "redemption_unresolved");
    assert.equal((s(flow).machine as Extract<MachineState, { phase: "redemption_unresolved" }>).reason, "outcome_unknown");
    await flow.act("check_status");
    assert.equal(phase(flow), "previously_recorded_unconfirmed");
    noPrepare(s(flow).machine);
  });

  it("an unreadable status keeps the outcome uncertain (fail closed) with the same ids", async () => {
    const { flow, server } = setup();
    await flow.start();
    server.schedule("redeem", "lose_reply_after_commit");
    await orderFor(flow, "m01");
    const rid = requestId(flow);
    server.schedule("status", "status_unavailable");
    await flow.act("check_status");
    assert.equal(phase(flow), "redemption_unresolved");
    assert.equal(requestId(flow), rid);
    noPrepare(s(flow).machine);
  });

  it("handoff reply lost: retry reuses the same handoff id; exactly one statement is stored", async () => {
    const { flow, server } = setup();
    await flow.start();
    await orderFor(flow, "m01");
    await flow.act("acknowledge_prepare");
    server.schedule("handoff", "lose_reply_after_commit");
    await flow.act("confirm_handed_now");
    assert.equal(phase(flow), "handoff_unresolved");
    const hid = (s(flow).machine as Extract<MachineState, { phase: "handoff_unresolved" }>).handoffIntent.handoffRequestId;
    await flow.act("retry_handoff");
    assert.equal(phase(flow), "handoff_confirmed");
    const confirmed = s(flow).machine as Extract<MachineState, { phase: "handoff_confirmed" }>;
    assert.equal(confirmed.via, "replayed");
    assert.equal(confirmed.handoff.handoffRequestId, hid);
    assert.equal(server.handoffCount(requestId(flow)), 1);
    assert.deepEqual(server.log().filter((e) => e.operation === "handoff").map((e) => e.ids[1]), [hid, hid]);
  });

  it("a reload in the middle: memory is gone, the tab store survives, the server status is read before anything is offered", async () => {
    const { flow, server, storage } = setup();
    await flow.start();
    server.schedule("redeem", "lose_reply_after_commit");
    await orderFor(flow, "m01");
    const rid = requestId(flow);
    const stored = storage.recoveryValue() ?? "";
    for (const secret of [member("m01").person.name, member("m01").qrToken, member("m01").phone, "lkp1."]) assert.ok(!stored.includes(secret), "the tab store holds ids only");
    await flow.reload();
    assert.equal(s(flow).summary, null, "no member data survives a reload");
    assert.equal(phase(flow), "previously_recorded_unconfirmed");
    assert.equal(requestId(flow), rid);
    assert.equal(server.log().at(-1)?.operation, "status", "the first request after the reload is a status read");
    noPrepare(s(flow).machine);
  });

  it("after a reload the order is no longer in memory: the same intent cannot be resent, nothing is sent", async () => {
    const { flow, server } = setup();
    await flow.start();
    server.schedule("redeem", "lose_request");
    await orderFor(flow, "m01");
    await flow.reload();
    assert.equal((s(flow).machine as Extract<MachineState, { phase: "recovery_required" }>).reason, "not_recorded");
    const before = redeemCalls(server).length;
    assert.equal(await flow.act("resubmit_same_intent"), "rejected");
    assert.equal(s(flow).notice, ORDER_NOT_IN_MEMORY);
    assert.equal(redeemCalls(server).length, before);
    assert.equal(await flow.act("abandon_intent"), "applied");
    assert.equal(s(flow).screen, "dashboard");
  });

  it("Athens midnight: yesterday's lost commit replays as an earlier business date, and today's coffee is a new intent", async () => {
    const { flow, server, clock } = setup({ at: BEFORE_MIDNIGHT });
    await flow.start();
    server.schedule("redeem", "lose_reply_after_commit");
    await orderFor(flow, "m01");
    clock.advance(60_000);
    await flow.act("retry_redemption");
    assert.equal(phase(flow), "previous_business_date");
    noPrepare(s(flow).machine);
    await flow.act("finish");
    await orderFor(flow, "m01");
    assert.equal(phase(flow), "recorded_now");
    assert.equal(server.redemptionCount(member("m01").memberId, "2026-10-19"), 1);
    assert.equal(server.redemptionCount(member("m01").memberId, "2026-10-20"), 1);
  });
});

describe("UI-04O counter flow: authorization loss fails closed", () => {
  it("session expired during a redemption: member data dropped, machine blocked, re-login resumes by status only", async () => {
    const { flow, server } = setup();
    await flow.start();
    server.schedule("redeem", "session_expired");
    await orderFor(flow, "m01");
    assert.equal(s(flow).session.phase, "expired");
    assert.equal(phase(flow), "blocked_authorization");
    assert.equal(s(flow).summary, null);
    assert.deepEqual(availableActions(s(flow).machine), ["reauthenticate"]);
    await flow.act("reauthenticate");
    await new Promise((r) => setImmediate(r));
    assert.equal(s(flow).session.phase, "ready");
    assert.equal(phase(flow), "recovery_required");
    assert.equal((s(flow).machine as Extract<MachineState, { phase: "recovery_required" }>).reason, "session_changed");
    await flow.act("resume_recovery");
    assert.equal((s(flow).machine as Extract<MachineState, { phase: "recovery_required" }>).reason, "not_recorded", "the 401 came before anything was recorded");
    assert.equal(server.redemptionCount(member("m01").memberId, "2026-10-19"), 0);
  });

  it("permission withdrawn (403): the app is forbidden and the machine blocked", async () => {
    const { flow, server } = setup();
    await flow.start();
    server.schedule("redeem", "permission_revoked");
    await orderFor(flow, "m01");
    assert.equal(s(flow).session.phase, "forbidden");
    assert.equal((s(flow).machine as Extract<MachineState, { phase: "blocked_authorization" }>).reason, "forbidden");
  });

  it("lock: the server session ends, the machine blocks, and nothing can be navigated until a real login", async () => {
    const { flow, server } = setup();
    await flow.start();
    flow.open("scan");
    await flow.lookup({ qrToken: member("m01").qrToken });
    const ref = s(flow).sessionRef;
    await flow.lock();
    assert.equal(s(flow).session.phase, "locked");
    assert.equal(server.isSessionActive(ref), false);
    assert.equal(s(flow).summary, null);
    assert.equal((s(flow).machine as Extract<MachineState, { phase: "blocked_authorization" }>).reason, "locked");
    flow.open("phone");
    assert.equal(s(flow).screen, "redemption", "still blocked");
  });

  it("a server-side session end is noticed at the next status check", async () => {
    const { flow } = setup();
    await flow.start();
    await flow.expireSession();
    assert.equal(s(flow).session.phase, "expired");
    assert.equal(phase(flow), "blocked_authorization");
  });
});

describe("UI-04O counter flow: manager intervention and storage failures", () => {
  it("record integrity failure: only the manager path is offered; notifying changes nothing", async () => {
    const { flow, server } = setup();
    await flow.start();
    server.schedule("redeem", "integrity_failure");
    await orderFor(flow, "m01");
    assert.equal(phase(flow), "blocked_integrity");
    assert.deepEqual(availableActions(s(flow).machine), ["manager_review"]);
    const before = s(flow).machine;
    await flow.act("manager_review");
    assert.equal(s(flow).managerNotified, true);
    assert.equal(s(flow).notice, MANAGER_NOTIFIED);
    assert.equal(s(flow).machine, before, "the machine state is unchanged");
    flow.nextCustomer();
    assert.equal(s(flow).screen, "redemption", "a blocked intent cannot be left for a new customer");
  });

  it("the tab store cannot be written: nothing is sent; after it recovers the SAME id is sent", async () => {
    const { flow, server, storage } = setup();
    await flow.start();
    storage.failRecoveryWrites = true;
    await orderFor(flow, "m01");
    assert.equal(phase(flow), "persistence_blocked");
    assert.deepEqual(redeemCalls(server), [], "no request without a durable recovery context");
    const rid = requestId(flow);
    storage.failRecoveryWrites = false;
    await flow.act("retry_persistence");
    assert.equal(phase(flow), "recorded_now");
    assert.deepEqual(redeemCalls(server), [rid]);
  });

  it("while an intent is open the counter cannot start another one", async () => {
    const { flow, server } = setup();
    await flow.start();
    server.schedule("redeem", "lose_reply_after_commit");
    await orderFor(flow, "m01");
    flow.open("scan");
    flow.nextCustomer();
    await flow.lookup({ qrToken: member("m05").qrToken });
    assert.equal(s(flow).screen, "redemption");
    assert.equal(s(flow).summary?.member.id, member("m01").memberId, "the open intent's member stays; no other member is looked up");
    assert.equal(await flow.confirm(), "rejected");
    assert.equal(redeemCalls(server).length, 1);
  });
});

describe("UI-04O fake server: replies are exact v2 contract bodies", () => {
  it("every reply classifies as a v2 domain body (or the v1 transport envelope) with its contract status", async () => {
    const { flow, server } = setup();
    await flow.start();
    const surfaceOf: Record<string, ReplySurface | null> = { redeem: "redemption", handoff: "handoff", status: "status", lookup: "lookup", summary: null };
    const seen: HttpReply[] = [];
    const wrap = <K extends "redeem" | "handoff" | "status" | "lookup">(name: K) => {
      const real = server[name].bind(server) as (...args: unknown[]) => HttpReply;
      (server as unknown as Record<string, unknown>)[name] = (...args: unknown[]) => {
        const reply = real(...args);
        seen.push(reply);
        const c = classifyHttpReply(surfaceOf[name]!, reply.status, reply.body);
        if (c.kind === "domain") {
          const key = String((c.body as Record<string, unknown>).outcome ?? (c.body as Record<string, unknown>).result);
          assert.equal(CONTRACT_STATUS[surfaceOf[name]!][key], reply.status, `${name} ${key}`);
        } else {
          assert.ok(typeof reply.body === "object" && reply.body !== null && "error" in reply.body, `${name}: a non-domain reply is the v1 envelope`);
        }
        return reply;
      };
    };
    for (const name of ["redeem", "handoff", "status", "lookup"] as const) wrap(name);
    await orderFor(flow, "m01");
    await flow.act("acknowledge_prepare");
    await flow.act("confirm_handed_now");
    await flow.act("check_status");
    await flow.act("finish");
    server.schedule("redeem", "outcome_unknown_after_commit");
    await orderFor(flow, "m05");
    await flow.act("retry_redemption");
    await flow.act("check_status");
    await flow.act("finish");
    server.schedule("redeem", "another_tablet_first");
    await orderFor(flow, "m06");
    await flow.act("finish");
    flow.open("phone");
    await flow.lookup({ phone: SHARED_PHONE });
    assert.ok(seen.length >= 10);
  });

  it("an inactive membership is refused by the server itself and consumes nothing", () => {
    const { server } = setup();
    server.startSession("s".repeat(32));
    for (const id of ["m03", "m04"]) {
      const looked = server.lookup("s".repeat(32), { qrToken: member(id).qrToken }).body as { member: { lookup_ref: string } };
      const payload = toPreparedPayload(usualOrder(member(id).person))!;
      const reply = server.redeem("s".repeat(32), member(id).memberId, {
        request_id: "00000000-0000-4000-8000-00000000beef",
        catalogue_version: payload.catalogueVersion,
        coffee_code: payload.coffeeCode,
        preparation: payload.preparation,
        lookup_ref: looked.member.lookup_ref,
      });
      assert.equal(reply.status, 409);
      assert.equal((reply.body as Record<string, unknown>).outcome, "membership_inactive");
      assert.equal((reply.body as Record<string, unknown>).entitlement_consumed, false);
    }
  });

  it("a redemption without a valid lookup proof is forbidden and records nothing", () => {
    const { server } = setup();
    server.startSession("s".repeat(32));
    const payload = toPreparedPayload(usualOrder(member("m01").person))!;
    const reply = server.redeem("s".repeat(32), member("m01").memberId, { request_id: "00000000-0000-4000-8000-00000000cafe", catalogue_version: 1, coffee_code: payload.coffeeCode, preparation: payload.preparation, lookup_ref: "" });
    assert.equal(reply.status, 403);
    assert.equal(server.redemptionCount(member("m01").memberId, "2026-10-19"), 0);
  });

  it("the catalogue copy is the shared illustrative fixture, identical in content", () => {
    const appDir = process.env.EVC_APP_DIR as string;
    const fixture = JSON.parse(readFileSync(join(appDir, "..", "wordpress", "evangelou-club", "tests", "fixtures", "preparation", "catalogue-illustrative.json"), "utf8"));
    assert.deepEqual(PREVIEW_CATALOGUE_DOCUMENT, fixture);
    assert.equal((PREVIEW_CATALOGUE_DOCUMENT as { status: string }).status, "illustrative");
  });
});

describe("UI-04O order payload: approved configurator order → canonical contract payload", () => {
  const catalogue = parseCatalogue(PREVIEW_CATALOGUE_DOCUMENT);

  it("every synthetic member's usual order maps to a payload the real contract validator accepts", () => {
    for (const m of MEMBERS) {
      const order = usualOrder(m);
      const payload = toPreparedPayload(order);
      assert.ok(payload, m.id);
      const parsed = parsePreparation(payload.preparation);
      assert.ok(parsed.ok, m.id);
      const v = validatePreparation(catalogue, payload.coffeeCode, parsed.value);
      assert.ok(v.ok, `${m.id}: ${v.ok ? "" : v.reason}`);
      assert.equal(payload.coffeeCode, CANONICAL_COFFEE[order.coffee!]);
    }
  });

  it("decaf becomes the caffeine group; quick notes keep their typed ids; incomplete orders yield null", () => {
    let order: CoffeeOrder = EMPTY_ORDER;
    const step = (a: OrderAction) => (order = orderReducer({ memberId: "x", order }, a).order);
    step({ type: "coffee", coffee: "cappuccino" });
    assert.equal(toPreparedPayload(order), null);
    step({ type: "choice", group: "shots", value: "single" });
    step({ type: "choice", group: "sweetness", value: "none" });
    step({ type: "choice", group: "milk", value: "plant" });
    step({ type: "extra", extra: "decaf" });
    step({ type: "quick-note", id: "cinnamon" });
    const payload = toPreparedPayload(order)!;
    assert.deepEqual(payload.preparation.modifiers.find((m) => m.group === "caffeine"), { group: "caffeine", option: "decaf" });
    assert.deepEqual(payload.preparation.quick_notes, ["cinnamon"]);
    const parsed = parsePreparation(payload.preparation);
    assert.ok(parsed.ok && validatePreparation(catalogue, payload.coffeeCode, parsed.value).ok);
  });

  it("phone canonicalisation mirrors the v2 rules for Greek mobiles", () => {
    assert.equal(canonicalGreekMobile("6912345678"), "+306912345678");
    assert.equal(canonicalGreekMobile("+30 691 234 5678"), "+306912345678");
    assert.equal(canonicalGreekMobile("0030 6912345678"), "+306912345678");
    for (const bad of ["2101234567", "691234567", "69123456789", "+44 7700 900123", ""]) assert.equal(canonicalGreekMobile(bad), null, bad);
    assert.equal(new Set(FAKE_DIRECTORY.map((e) => e.phone)).size, FAKE_DIRECTORY.length, "fake phones are unique");
    for (const e of FAKE_DIRECTORY) assert.ok(canonicalGreekMobile(e.phone), e.phone);
  });
});
