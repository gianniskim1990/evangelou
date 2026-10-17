import type { HandoffIntent, MachineState, RedemptionIntent } from "../redemptionRecovery/machine";
import type { HandoffView, RecordedRedemption } from "../redemptionRecovery/wire";

/**
 * UI-04N deterministic FAKE states for the local-only visual preview and the
 * component tests. Every id is a synthetic constant; nothing here is a real
 * member, request or payment, and nothing ever reaches a server.
 */

const intent: RedemptionIntent = {
  memberId: "mem_00000000000000000000000000000001",
  requestId: "00000000-0000-4000-8000-000000000001",
  payloadSha256: "00".repeat(32),
};
const handoffIntent: HandoffIntent = { handoffRequestId: "00000000-0000-4000-8000-000000000002", claim: "handed_now" };
const record: RecordedRedemption = { requestId: intent.requestId, memberId: intent.memberId, businessDate: "2000-01-01", redeemedAtUtc: "2000-01-01 08:00:00.000000" };
const handoff: HandoffView = {
  handoffRequestId: handoffIntent.handoffRequestId,
  redemptionRequestId: intent.requestId,
  claim: "handed_now",
  confirmationRecordedAtUtc: "2000-01-01 08:05:00.000000",
  handoffAtUtc: "2000-01-01 08:05:00.000000",
  handoffTime: "stated_at_recording",
};

export interface PreviewScenario {
  readonly id: string;
  /** Developer-facing label of the scenario picker (English; not shown on the staff screen). */
  readonly label: string;
  readonly state: MachineState;
}

export const PREVIEW_SCENARIOS: readonly PreviewScenario[] = [
  { id: "idle", label: "No pending request", state: { phase: "idle" } },
  { id: "waiting", label: "Waiting for the server", state: { phase: "submitting_redemption", intent } },
  { id: "not-sent", label: "Request not sent", state: { phase: "persistence_blocked", operation: "redemption", intent, mayHaveBeenSent: false } },
  { id: "unknown", label: "Server outcome unknown", state: { phase: "redemption_unresolved", intent, reason: "timeout" } },
  { id: "already", label: "Already recorded", state: { phase: "previously_recorded_unconfirmed", intent, record, manuallyVerified: false } },
  { id: "fresh", label: "Fresh confirmed redemption", state: { phase: "recorded_now", intent, record, prepareAcknowledged: false } },
  { id: "storage", label: "Storage unavailable", state: { phase: "recovery_required", intent, handoffIntent: null, reason: "storage_unavailable" } },
  { id: "expired", label: "Session expired", state: { phase: "blocked_authorization", reason: "expired" } },
  { id: "manager", label: "Manager review required", state: { phase: "blocked_integrity", redemptionRequestId: intent.requestId } },
  { id: "handoff", label: "Handoff statement recorded", state: { phase: "handoff_confirmed", intent, record, handoff, via: "confirmed" } },
];
