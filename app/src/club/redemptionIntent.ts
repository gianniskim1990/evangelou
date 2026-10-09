import type { ClubService } from "./clubService";
import { clubError, keepsIntentOpen } from "./errors";
import { newRequestId } from "./requestId";
import { ClubApiError, type BenefitType, type RedemptionOutcome } from "./types";

/**
 * Redemption intents — exactly ONE request_id (UUID v4) per logical
 * "give this member this coffee" action.
 *
 * Rules (see docs/evangelou-club-api.md §5):
 * - A new intent gets a fresh UUID; retries of the same intent reuse it.
 * - The intent survives re-renders (the tracker lives in a ref) and is
 *   independent of the clock, so a retry after Athens midnight keeps it.
 * - An unknown outcome (lost response, 5xx, unreadable reply, 429, 401)
 *   keeps the intent OPEN: the next attempt replays the same UUID, and the
 *   server returns the original result if it had committed.
 * - Success or a definitive rejection CLOSES the intent.
 * - A different coffee or member never reuses an open intent's UUID: it
 *   opens a new intent (the server's one-per-day rule still protects
 *   against a double coffee if the abandoned intent had committed).
 * - Nothing is retried automatically; retry is an explicit staff action.
 */

export interface IntentPayload {
  memberId: string;
  benefitType: BenefitType;
  coffeeCode: string;
}

export interface RedemptionIntent extends IntentPayload {
  readonly requestId: string;
  readonly attempts: number;
}

export class RedemptionIntentTracker {
  private intent: RedemptionIntent | null = null;
  private inFlight = false;
  private readonly makeId: () => string;

  constructor(makeId: () => string = newRequestId) {
    this.makeId = makeId;
  }

  get current(): RedemptionIntent | null {
    return this.intent;
  }

  get busy(): boolean {
    return this.inFlight;
  }

  /** The open intent for exactly this payload, or a NEW one replacing any other. */
  intentFor(payload: IntentPayload): RedemptionIntent {
    const open = this.intent;
    if (
      open &&
      open.memberId === payload.memberId &&
      open.benefitType === payload.benefitType &&
      open.coffeeCode === payload.coffeeCode
    ) {
      return open;
    }
    this.intent = { ...payload, requestId: this.makeId(), attempts: 0 };
    return this.intent;
  }

  /** Closes the intent if it is still the one identified by requestId. */
  close(requestId: string): void {
    if (this.intent?.requestId === requestId) this.intent = null;
  }

  /** Member changed / screen left: drop any pending intent. */
  reset(): void {
    this.intent = null;
  }

  /** @internal used by submitRedemption */
  beginAttempt(intent: RedemptionIntent): RedemptionIntent | null {
    if (this.inFlight || this.intent?.requestId !== intent.requestId) return null;
    this.inFlight = true;
    this.intent = { ...intent, attempts: intent.attempts + 1 };
    return this.intent;
  }

  /** @internal used by submitRedemption */
  endAttempt(): void {
    this.inFlight = false;
  }
}

export type RedemptionAttempt =
  /** Committed (first time or replay of a committed request). Intent closed. */
  | { kind: "redeemed"; outcome: RedemptionOutcome }
  /** Server says today's benefit is already used (another till/intent). Intent closed. */
  | { kind: "already_redeemed"; error: ClubApiError }
  /** Definitive rejection for this payload (inactive, invalid coffee, …). Intent closed. */
  | { kind: "rejected"; error: ClubApiError }
  /** Outcome unknown or not processed: retry reuses `requestId`. Intent kept open. */
  | { kind: "retry_needed"; error: ClubApiError; requestId: string }
  /** An attempt for this tracker is already in flight; nothing was sent. */
  | { kind: "busy" };

export async function submitRedemption(
  service: Pick<ClubService, "redeemBenefit">,
  tracker: RedemptionIntentTracker,
  payload: IntentPayload,
): Promise<RedemptionAttempt> {
  if (tracker.busy) return { kind: "busy" };
  const intent = tracker.beginAttempt(tracker.intentFor(payload));
  if (!intent) return { kind: "busy" };
  const { requestId } = intent;

  try {
    const outcome = await service.redeemBenefit({
      memberId: intent.memberId,
      benefitType: intent.benefitType,
      coffeeCode: intent.coffeeCode,
      requestId,
    });
    tracker.close(requestId);
    return { kind: "redeemed", outcome };
  } catch (err) {
    // Anything that is not a mapped ClubApiError is treated as "outcome unknown".
    const error = err instanceof ClubApiError ? err : clubError("invalid_response");
    if (keepsIntentOpen(error)) {
      return { kind: "retry_needed", error, requestId };
    }
    tracker.close(requestId);
    if (error.code === "benefit_already_redeemed") return { kind: "already_redeemed", error };
    return { kind: "rejected", error };
  } finally {
    tracker.endAttempt();
  }
}
