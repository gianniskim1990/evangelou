import type {
  BlockReason,
  HandoffIntent,
  MachineState,
  RecoveryReason,
  RedemptionIntent,
  UnresolvedReason,
} from "../../../src/staff/redemptionRecovery/machine";
import type { HandoffRefusal, HandoffView, RecordedRedemption, RedemptionRefusal } from "../../../src/staff/redemptionRecovery/wire";
import { MEMBER_A } from "./recoveryFixtures";

/** Synthetic ids (test-only). Distinctive so tests can prove they never leak into copy. */
export const RID = "3fa85f64-5717-4562-b3fc-2c963f66afa6";
export const HID = "7c9e6679-7425-40de-944b-e07fc1f90ae7";
export const intent: RedemptionIntent = { memberId: MEMBER_A, requestId: RID, payloadSha256: "ab".repeat(32) };
export const handoffIntent: HandoffIntent = { handoffRequestId: HID, claim: "handed_now" };
export const record: RecordedRedemption = { requestId: RID, memberId: MEMBER_A, businessDate: "2026-10-14", redeemedAtUtc: "2026-10-14 07:30:00.000000" };
export const handoff: HandoffView = {
  handoffRequestId: HID,
  redemptionRequestId: RID,
  claim: "handed_now",
  confirmationRecordedAtUtc: "2026-10-14 07:35:00.000000",
  handoffAtUtc: "2026-10-14 07:35:00.000000",
  handoffTime: "stated_at_recording",
};

// Record<..., true> tables: adding a new reason/code without listing it here is a compile error.
export const REDEMPTION_CODES: Record<RedemptionRefusal, true> = {
  invalid_request: true, invalid_preparation: true, invalid_coffee: true, catalogue_version_stale: true, catalogue_not_authorised: true,
  member_not_found: true, membership_inactive: true, benefit_already_redeemed: true, idempotency_key_reused: true,
};
export const HANDOFF_CODES: Record<HandoffRefusal, true> = { invalid_request: true, redemption_not_found: true, late_confirmation_not_authorised: true, idempotency_key_reused: true };
export const BLOCK_REASONS: Record<BlockReason, true> = { unauthorized: true, forbidden: true, locked: true, logged_out: true, expired: true };
export const RECOVERY_REASONS: Record<RecoveryReason, true> = {
  not_recorded: true, redemption_missing: true, session_changed: true, storage_corrupt: true, storage_outdated: true, storage_unavailable: true, status_unavailable: true,
};
export const UNRESOLVED: Record<UnresolvedReason, true> = {
  outcome_unknown: true, server_error: true, network: true, timeout: true, unavailable: true, rate_limited: true, invalid_response: true,
  unexpected_error: true, status_unavailable: true, status_unconfirmed: true,
};

/** One MachineState for EVERY phase and every reason / code / variant. */
export function allRecoveryStates(): MachineState[] {
  const s: MachineState[] = [
    { phase: "idle" },
    { phase: "submitting_redemption", intent },
    { phase: "checking_status", intent, handoffIntent: null },
    { phase: "checking_status", intent, handoffIntent },
    { phase: "recorded_now", intent, record, prepareAcknowledged: false },
    { phase: "recorded_now", intent, record, prepareAcknowledged: true },
    { phase: "previously_recorded_unconfirmed", intent, record, manuallyVerified: false },
    { phase: "previously_recorded_unconfirmed", intent, record, manuallyVerified: true },
    { phase: "previously_recorded_confirmed", intent, record, handoff },
    { phase: "previous_business_date", intent, record, handoff: null },
    { phase: "previous_business_date", intent, record, handoff },
    { phase: "handoff_confirmation_pending", intent, record, handoffIntent },
    { phase: "blocked_integrity", redemptionRequestId: RID },
    { phase: "blocked_integrity", redemptionRequestId: null },
    { phase: "persistence_blocked", operation: "redemption", intent, mayHaveBeenSent: false },
    { phase: "persistence_blocked", operation: "redemption", intent, mayHaveBeenSent: true },
    { phase: "persistence_blocked", operation: "handoff", intent, record, handoffIntent, mayHaveBeenSent: false },
    { phase: "persistence_blocked", operation: "handoff", intent, record, handoffIntent, mayHaveBeenSent: true },
  ];
  for (const reason of Object.keys(UNRESOLVED) as UnresolvedReason[]) {
    s.push({ phase: "redemption_unresolved", intent, reason });
    s.push({ phase: "handoff_unresolved", intent, record, handoffIntent, reason });
  }
  for (const code of Object.keys(REDEMPTION_CODES) as RedemptionRefusal[]) s.push({ phase: "redemption_refused", intent, code });
  for (const code of Object.keys(HANDOFF_CODES) as HandoffRefusal[]) s.push({ phase: "handoff_refused", intent, record, code });
  for (const via of ["confirmed", "replayed", "already_confirmed", "status"] as const) s.push({ phase: "handoff_confirmed", intent, record, handoff, via });
  for (const reason of Object.keys(BLOCK_REASONS) as BlockReason[]) s.push({ phase: "blocked_authorization", reason });
  for (const reason of Object.keys(RECOVERY_REASONS) as RecoveryReason[]) {
    s.push({ phase: "recovery_required", intent, handoffIntent: null, reason });
    s.push({ phase: "recovery_required", intent: null, handoffIntent: null, reason });
  }
  return s;
}
