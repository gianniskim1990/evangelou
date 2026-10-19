import type { ClubMember, MemberBenefitStatus } from "../../club/types";
import { StaffApiError } from "../staffApi";
import type { StaffController } from "../staffController";
import { StaffTransportError, type StaffRedemptionTransport, type TransportFailureKind } from "../redemptionRecovery/machine";
import { classifyHttpReply, parseLookupReply, type LookupReply, type ReplySurface } from "../redemptionRecovery/wire";
import { TransportLoss, type FakeClubServer, type HttpReply } from "./fakeServer";

/**
 * UI-04O LOCAL-ONLY transport between the REAL client code and the fake
 * server. It is shaped like the future real transport would be:
 *
 *  1. every member-data call runs inside StaffController.runProtected, so a
 *     trustworthy 401 / 403 ends the staff session (fail closed) and a reply
 *     that arrives after lock / expiry / logout is discarded as stale;
 *  2. every reply goes through classifyHttpReply: a body counts as a v2
 *     DOMAIN reply only when its outcome belongs to the surface AND its HTTP
 *     status is exactly the contract status; anything else is a transport
 *     failure that never says whether something was committed;
 *  3. a lost reply surfaces as a network / timeout failure, never as a
 *     refusal, so the machine keeps the ORIGINAL request id.
 *
 * Nothing leaves the page: the "server" is an in-memory object.
 */

export interface PreviewTransportDeps {
  server: FakeClubServer;
  /** The CURRENT staff controller (a simulated re-login replaces it). */
  controller: () => StaffController;
  /** Non-secret reference of the current login session (the fake stands in for the WordPress cookie). */
  sessionRef: () => string;
  /** Lookup proof of the member being served, held in memory only. */
  lookupRefFor: (memberId: string) => string | null;
  /** Simulated round trip, split around the server's work. 0 in tests. */
  latencyMs: number;
  sleep: (ms: number, signal: AbortSignal) => Promise<void>;
}

export type MemberSummary = { member: ClubMember; benefit: MemberBenefitStatus };

export type Protected<T> = { kind: "ok"; value: T } | { kind: "auth_lost" } | { kind: "stale" } | { kind: "failed"; failure: TransportFailureKind };

/** Marks a 429 so it survives runProtected (which keeps only StaffApiError / ClubApiError). */
const RATE_LIMITED_STATUS = 429;

function failureOf(error: unknown): TransportFailureKind {
  if (error instanceof StaffApiError) {
    if (error.kind === "timeout") return "timeout";
    if (error.kind === "network") return "network";
    if (error.status === RATE_LIMITED_STATUS) return "rate_limited";
  }
  return "unavailable";
}

export function abortableSleep(ms: number, signal: AbortSignal): Promise<void> {
  if (ms <= 0) return signal.aborted ? Promise.reject(new StaffApiError("aborted")) : Promise.resolve();
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      signal.removeEventListener("abort", onAbort);
      resolve();
    }, ms);
    const onAbort = () => {
      clearTimeout(timer);
      reject(new StaffApiError("aborted"));
    };
    if (signal.aborted) onAbort();
    else signal.addEventListener("abort", onAbort, { once: true });
  });
}

export function createPreviewTransport(deps: PreviewTransportDeps) {
  /** One protected round trip; `surface` null = the preview-only member summary (no v2 contract). */
  async function exchange(surface: ReplySurface | null, call: () => HttpReply): Promise<Protected<unknown>> {
    const half = deps.latencyMs / 2;
    const result = await deps.controller().runProtected(async (signal) => {
      await deps.sleep(half, signal);
      let reply: HttpReply;
      try {
        reply = call();
      } catch (err) {
        if (err instanceof TransportLoss) throw new StaffApiError(err.kind);
        throw err;
      }
      await deps.sleep(half, signal);
      if (surface === null) {
        if (reply.status === 401) throw new StaffApiError("unauthorized", 401);
        if (reply.status === 403) throw new StaffApiError("forbidden", 403);
        if (reply.status !== 200) throw new StaffApiError("server", reply.status);
        return reply.body;
      }
      const classified = classifyHttpReply(surface, reply.status, reply.body);
      if (classified.kind === "domain") return classified.body;
      if (classified.failure === "unauthorized") throw new StaffApiError("unauthorized", 401);
      if (classified.failure === "forbidden") throw new StaffApiError("forbidden", 403);
      if (classified.failure === "rate_limited") throw new StaffApiError("invalid_response", RATE_LIMITED_STATUS);
      throw new StaffApiError("server", reply.status);
    });
    switch (result.status) {
      case "ok":
        return { kind: "ok", value: result.value };
      case "auth_lost":
        return { kind: "auth_lost" };
      case "stale":
        return { kind: "stale" };
      case "error":
        return { kind: "failed", failure: failureOf(result.error) };
    }
  }

  /** For the machine: a value or a StaffTransportError, exactly what it expects of a real transport. */
  async function forMachine(surface: ReplySurface, call: () => HttpReply): Promise<unknown> {
    const r = await exchange(surface, call);
    if (r.kind === "ok") return r.value;
    if (r.kind === "auth_lost") {
      // The controller already left "ready"; tell the machine which kind of loss it was.
      throw new StaffTransportError(deps.controller().getState().phase === "forbidden" ? "forbidden" : "unauthorized");
    }
    // A stale reply belongs to an operation the machine has already abandoned (session ended).
    throw new StaffTransportError(r.kind === "stale" ? "network" : r.failure);
  }

  const redemption: StaffRedemptionTransport = {
    redeem: ({ memberId, requestId, payload }) =>
      forMachine("redemption", () =>
        deps.server.redeem(deps.sessionRef(), memberId, {
          request_id: requestId,
          catalogue_version: payload.catalogueVersion,
          coffee_code: payload.coffeeCode,
          preparation: { modifiers: payload.preparation.modifiers.map((m) => ({ group: m.group, option: m.option })), quick_notes: [...payload.preparation.quick_notes] },
          // The route requires the lookup proof; without it nothing is recorded (403).
          lookup_ref: deps.lookupRefFor(memberId) ?? "",
        }),
      ),
    confirmHandoff: ({ memberId, handoffRequestId, redemptionRequestId, claim }) =>
      forMachine("handoff", () => deps.server.handoff(deps.sessionRef(), memberId, redemptionRequestId, { handoff_request_id: handoffRequestId, claim })),
    redemptionStatus: ({ memberId, redemptionRequestId }) => forMachine("status", () => deps.server.status(deps.sessionRef(), memberId, redemptionRequestId)),
  };

  return {
    redemption,

    /** v2 lookup: identity + proof only. A malformed reply is treated as unavailable (fail closed). */
    async lookup(by: { qrToken: string } | { phone: string }): Promise<Protected<LookupReply>> {
      const r = await exchange("lookup", () => deps.server.lookup(deps.sessionRef(), by));
      if (r.kind !== "ok") return r;
      const parsed = parseLookupReply(r.value);
      return parsed ? { kind: "ok", value: parsed } : { kind: "failed", failure: "unavailable" };
    },

    /** PREVIEW-ONLY member summary (no v2 route; see fakeServer.memberSummary). */
    async summary(memberId: string, lookupRef: string): Promise<Protected<MemberSummary>> {
      const r = await exchange(null, () => deps.server.memberSummary(deps.sessionRef(), memberId, lookupRef));
      if (r.kind !== "ok") return r;
      const v = r.value as Partial<MemberSummary> | null;
      return v && v.member && v.benefit && v.member.id === memberId ? { kind: "ok", value: v as MemberSummary } : { kind: "failed", failure: "unavailable" };
    },
  };
}

export type PreviewTransport = ReturnType<typeof createPreviewTransport>;
