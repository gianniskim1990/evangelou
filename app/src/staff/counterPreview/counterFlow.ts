import { EMPTY_ORDER, isComplete, orderReducer, type CoffeeOrder, type OrderAction } from "../../preview/coffeeConfig";
import type { PreviewMember } from "../../preview/previewData";
import { StaffController, type StaffState } from "../staffController";
import { createSessionRecoveryStorage } from "../redemptionRecovery/browserStorage";
import { StaffRedemptionMachine, type Action, type BlockReason, type MachineState, type PreparedOrderPayload, type Result } from "../redemptionRecovery/machine";
import type { LookupReply } from "../redemptionRecovery/wire";
import { entryById } from "./fakeDirectory";
import type { FakeClubServer } from "./fakeServer";
import { toPreparedPayload } from "./orderPayload";
import { previewConfig, previewStaffApi, type PreviewTabStorage } from "./previewSession";
import { createPreviewTransport, type MemberSummary, type PreviewTransport } from "./previewTransport";

/**
 * UI-04O LOCAL-ONLY orchestration of the integrated staff counter flow.
 *
 * It owns no business rule. It wires together, unchanged:
 *  - the REAL StaffController (session states, fail-closed authorization
 *    loss, lock) over a fake session API;
 *  - the REAL StaffRedemptionMachine (one intent at a time, original ids on
 *    every retry, status before action after uncertainty, prepare only after
 *    a fresh commit, persistence before send) over the preview transport;
 *  - the REAL per-tab recovery storage adapter over an in-memory tab store;
 *  - the approved UI-02 order reducer (structured choices only).
 *
 * Its own rules are navigation guards only:
 *  - while the machine has an open intent (any phase but idle), the counter
 *    shows the recovery screen and nothing else: no new member, no new order;
 *  - member data (summary, lookup proof, order) lives in memory only and is
 *    dropped whenever the session leaves "ready" and on every simulated
 *    reload or re-login;
 *  - leaving "ready" for lock / expiry / permission loss ends the machine's
 *    session too (sessionEnded), so late replies are discarded.
 */

export type Screen = "dashboard" | "scan" | "phone" | "member" | "coffee" | "confirm" | "redemption";

export type LookupNotice = Exclude<LookupReply["kind"], "found"> | "network" | "timeout";

export interface CounterFlowState {
  /** What the counter shows. "redemption" whenever the machine is not idle or the session was lost. */
  readonly screen: Screen;
  readonly session: StaffState;
  readonly machine: MachineState;
  readonly summary: MemberSummary | null;
  /** The approved synthetic person behind the summary (for the configurator's sample "usual" box). */
  readonly person: PreviewMember | null;
  readonly order: CoffeeOrder;
  readonly busy: boolean;
  readonly lookupNotice: LookupNotice | null;
  /** A local, non-business notice (e.g. the order is no longer in memory after a reload). */
  readonly notice: string | null;
  readonly managerNotified: boolean;
  /** Increases on every simulated reload / re-login (a fresh page). */
  readonly generation: number;
  readonly sessionRef: string;
}

export interface CounterFlowDeps {
  server: FakeClubServer;
  storage: PreviewTabStorage;
  now: () => Date;
  newId: () => string;
  newSessionRef: () => string;
  sha256Hex: (text: string) => Promise<string>;
  latencyMs: number;
  sleep: (ms: number, signal: AbortSignal) => Promise<void>;
  /** Audit-only tablet reference (never an authentication or decision input). */
  deviceRef: string;
}

/** Copy shown when the order of an intent is no longer in memory (simulated reload). */
export const ORDER_NOT_IN_MEMORY =
  "Η αρχική παραγγελία δεν υπάρχει πια στη μνήμη της οθόνης (ανανέωση σελίδας). Δεν στέλνεται τίποτα. Εγκαταλείψτε το αίτημα και ξεκινήστε από την αρχή με τον πελάτη.";
export const MANAGER_NOTIFIED = "Η ειδοποίηση του υπευθύνου καταγράφηκε στην προεπισκόπηση. Δεν άλλαξε τίποτα στην καταχώρηση.";

const BLOCK_OF: Partial<Record<StaffState["phase"], BlockReason>> = { locked: "locked", expired: "expired", forbidden: "forbidden" };

export class CounterFlow {
  private readonly deps: CounterFlowDeps;
  private readonly listeners = new Set<() => void>();
  private controller!: StaffController;
  private machine!: StaffRedemptionMachine;
  private transport!: PreviewTransport;
  private unsubscribe: (() => void)[] = [];
  private sessionRef: string;
  private generation = 0;

  // Member context: memory only, never persisted, dropped on session loss / reload.
  private screen: Screen = "dashboard";
  private summary: MemberSummary | null = null;
  private lookupRef: string | null = null;
  private orderState = { memberId: "", order: EMPTY_ORDER };
  /** The exact order of the open intent (needed only to resend the SAME request). */
  private intentPayload: PreparedOrderPayload | null = null;
  private busy = false;
  private lookupNotice: LookupNotice | null = null;
  private notice: string | null = null;
  private managerNotified = false;
  private snapshot: CounterFlowState;

  constructor(deps: CounterFlowDeps) {
    this.deps = deps;
    this.sessionRef = deps.newSessionRef();
    this.snapshot = this.build();
  }

  getState = (): CounterFlowState => this.snapshot;

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  };

  /** The machine instance currently in use (tests and the preview inspector). */
  currentMachine(): StaffRedemptionMachine {
    return this.machine;
  }

  currentController(): StaffController {
    return this.controller;
  }

  // ---- page lifecycle (simulated) ---------------------------------------------------------------

  /** First load: the WordPress login happened before the shell was served. */
  async start(): Promise<void> {
    this.deps.server.startSession(this.sessionRef);
    await this.boot();
  }

  /** Same tab, same login session, fresh page: memory is gone, the tab store and the server remain. */
  async reload(): Promise<void> {
    await this.boot();
  }

  /** A real WordPress login (after lock, expiry or permission loss) creates a NEW session. */
  async signIn(): Promise<void> {
    this.sessionRef = this.deps.newSessionRef();
    this.deps.server.startSession(this.sessionRef);
    await this.boot();
  }

  private async boot(): Promise<void> {
    for (const off of this.unsubscribe) off();
    this.unsubscribe = [];
    this.generation++;
    this.clearMemberContext();
    this.intentPayload = null;
    this.notice = null;
    this.managerNotified = false;
    this.screen = "dashboard";

    const sessionRef = this.sessionRef;
    const tabStore = this.deps.storage;
    const controller = new StaffController({
      api: previewStaffApi(this.deps.server, sessionRef, this.deps.now),
      config: previewConfig(sessionRef),
      navigate: () => void this.signIn(),
      storage: tabStore,
    });
    this.controller = controller;
    this.transport = createPreviewTransport({
      server: this.deps.server,
      controller: () => controller,
      sessionRef: () => sessionRef,
      lookupRefFor: (memberId) => (this.summary?.member.id === memberId ? this.lookupRef : null),
      latencyMs: this.deps.latencyMs,
      sleep: this.deps.sleep,
    });
    this.machine = new StaffRedemptionMachine({
      transport: this.transport.redemption,
      storage: createSessionRecoveryStorage(() => tabStore as unknown as Storage),
      newId: this.deps.newId,
      sessionRef,
      deviceRef: this.deps.deviceRef,
      sha256Hex: this.deps.sha256Hex,
    });
    const machine = this.machine;
    this.unsubscribe.push(controller.subscribe(() => this.onSession(controller, machine)));
    this.unsubscribe.push(machine.subscribe(() => this.emit()));
    this.emit();

    await controller.boot();
    // Only a verified session may read the stored recovery context (it asks the server for status).
    if (controller.getState().phase === "ready") await machine.restore();
    this.emit();
  }

  private onSession(controller: StaffController, machine: StaffRedemptionMachine): void {
    if (controller !== this.controller) return;
    const phase = controller.getState().phase;
    if (phase !== "ready" && phase !== "loading") {
      // Fail closed: member data goes, and the redemption machine stops accepting replies.
      this.clearMemberContext();
      this.intentPayload = null;
      const reason = BLOCK_OF[phase];
      if (reason && machine.getState().phase !== "blocked_authorization") machine.sessionEnded(reason);
    }
    this.emit();
  }

  // ---- counter navigation -----------------------------------------------------------------------

  private canNavigate(): boolean {
    return this.controller?.getState().phase === "ready" && this.machine.getState().phase === "idle" && !this.busy;
  }

  open(screen: "scan" | "phone"): void {
    if (!this.canNavigate()) return;
    this.clearMemberContext();
    this.screen = screen;
    this.emit();
  }

  /** Back to the dashboard for the next customer. Never while an intent is open. */
  nextCustomer(): void {
    if (!this.canNavigate()) return;
    this.clearMemberContext();
    this.screen = "dashboard";
    this.emit();
  }

  async lookup(by: { qrToken: string } | { phone: string }): Promise<void> {
    if (!this.canNavigate()) return;
    this.busy = true;
    this.lookupNotice = null;
    this.emit();
    const found = await this.transport.lookup(by);
    this.busy = false;
    if (found.kind !== "ok") {
      if (found.kind === "failed") this.lookupNotice = found.failure === "network" || found.failure === "timeout" ? found.failure : found.failure === "rate_limited" ? "rate_limited" : "unavailable";
      this.emit();
      return;
    }
    if (found.value.kind !== "found") {
      this.lookupNotice = found.value.kind;
      this.emit();
      return;
    }
    const { memberPublicId, lookupRef } = found.value;
    this.busy = true;
    this.emit();
    const summary = await this.transport.summary(memberPublicId, lookupRef);
    this.busy = false;
    if (summary.kind !== "ok") {
      if (summary.kind === "failed") this.lookupNotice = "unavailable";
      this.emit();
      return;
    }
    this.summary = summary.value;
    this.lookupRef = lookupRef;
    this.orderState = orderReducer(this.orderState, { type: "open-member", memberId: memberPublicId });
    this.screen = "member";
    this.emit();
  }

  /** Coffee selection is offered only for a member the (stand-in) summary shows as available today. */
  chooseCoffee(): void {
    if (!this.canNavigate() || !this.summary || this.summary.benefit.state !== "available") return;
    this.screen = "coffee";
    this.emit();
  }

  backToMember(): void {
    if (!this.canNavigate() || !this.summary) return;
    this.screen = "member";
    this.emit();
  }

  dispatchOrder(action: OrderAction): void {
    if (!this.canNavigate() || !this.summary) return;
    if (action.type === "open-member" || action.type === "reset") return;
    this.orderState = orderReducer(this.orderState, action);
    this.emit();
  }

  review(): void {
    if (!this.canNavigate() || !this.summary || !isComplete(this.orderState.order)) return;
    this.screen = "confirm";
    this.emit();
  }

  /** "Επιβεβαίωση": ONE new logical intent, sent through the real machine. */
  async confirm(): Promise<Result> {
    if (!this.canNavigate() || !this.summary || this.screen !== "confirm") return "rejected";
    const payload = toPreparedPayload(this.orderState.order);
    if (!payload) return "rejected";
    this.intentPayload = payload;
    this.notice = null;
    this.managerNotified = false;
    const result = await this.machine.startRedemption(this.summary.member.id, payload);
    if (result === "rejected") this.intentPayload = null;
    this.emit();
    return result;
  }

  /** The recovery screen's buttons: each action NAME maps to exactly one machine method. */
  async act(action: Action): Promise<Result> {
    const m = this.machine;
    this.notice = null;
    let result: Result;
    switch (action) {
      case "start_redemption":
        result = "ignored";
        this.nextCustomer();
        break;
      case "acknowledge_prepare":
        result = m.acknowledgePrepare();
        break;
      case "confirm_manual_verification":
        result = m.confirmManualVerification();
        break;
      case "retry_redemption":
        result = await m.retryRedemption();
        break;
      case "resubmit_same_intent":
        if (!this.intentPayload) {
          this.notice = ORDER_NOT_IN_MEMORY;
          result = "rejected";
        } else {
          result = await m.resubmitSameIntent(this.intentPayload);
        }
        break;
      case "check_status":
        result = await m.checkStatus();
        break;
      case "resume_recovery":
        result = await m.resumeRecovery();
        break;
      case "confirm_handed_now":
        result = await m.confirmHandoff("handed_now");
        break;
      case "confirm_handed_earlier":
        result = await m.confirmHandoff("already_handed_earlier");
        break;
      case "retry_handoff":
        result = await m.retryHandoff();
        break;
      case "retry_persistence":
        result = await m.retryPersistence();
        break;
      case "retry_restore":
        result = await m.restore();
        break;
      case "finish":
        result = m.finish();
        if (result === "applied") this.afterIntent();
        break;
      case "abandon_intent":
        result = m.abandonIntent();
        if (result === "applied") this.afterIntent();
        break;
      case "manager_review":
        // Preview: the escalation is only recorded locally. It never changes the redemption.
        this.managerNotified = true;
        this.notice = MANAGER_NOTIFIED;
        result = "ignored";
        break;
      case "reauthenticate":
        // A real WordPress login (the controller navigates to wp-login.php?reauth=1).
        this.controller.reauthenticate();
        result = "ignored";
        break;
    }
    this.emit();
    return result;
  }

  /** Staff lock the screen (same path as the idle lock). */
  async lock(): Promise<void> {
    await this.controller.lock();
  }

  /** Preview tool: the WordPress session ends on the server (expiry / idle / admin revocation), noticed at the next check. */
  async expireSession(): Promise<void> {
    this.deps.server.endSession(this.sessionRef);
    await this.controller.refreshStatus();
  }

  private afterIntent(): void {
    this.intentPayload = null;
    this.managerNotified = false;
    this.clearMemberContext();
    this.screen = "dashboard";
  }

  private clearMemberContext(): void {
    this.summary = null;
    this.lookupRef = null;
    this.orderState = { memberId: "", order: EMPTY_ORDER };
    this.lookupNotice = null;
    this.busy = false;
    if (this.screen !== "scan" && this.screen !== "phone") this.screen = "dashboard";
  }

  private build(): CounterFlowState {
    const session: StaffState = this.controller ? this.controller.getState() : { phase: "loading" };
    const machine: MachineState = this.machine ? this.machine.getState() : { phase: "idle" };
    const lost = session.phase !== "ready" && session.phase !== "loading";
    const screen: Screen = machine.phase !== "idle" || lost ? "redemption" : this.screen;
    const person = this.summary ? (entryById(this.summary.member.id)?.person ?? null) : null;
    return Object.freeze({
      screen,
      session,
      machine,
      summary: this.summary,
      person,
      order: this.orderState.order,
      busy: this.busy,
      lookupNotice: this.lookupNotice,
      notice: this.notice,
      managerNotified: this.managerNotified,
      generation: this.generation,
      sessionRef: this.sessionRef,
    });
  }

  private emit(): void {
    this.snapshot = this.build();
    for (const l of this.listeners) l();
  }
}
