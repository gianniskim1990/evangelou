/**
 * Owner-approved 5-minute tablet idle lock (client side; the server keeps
 * its own 12 h absolute / 30 min inactivity policy).
 *
 * - Only GENUINE interaction counts (pointer/touch/key/wheel). Timers, REST
 *   responses, polling and visibility changes never count as activity.
 * - Decisions use elapsed wall-clock time (now - lastActivity), so a tablet
 *   that slept (timers paused) locks immediately on resume.
 * - Once locked it stays locked: activity is ignored until the page is
 *   replaced by a real WordPress re-login.
 */

export const IDLE_LOCK_MS = 300_000;
export const CHECK_INTERVAL_MS = 1_000;
/** Genuine user-interaction events. Deliberately excludes mousemove/scroll/focus. */
export const ACTIVITY_EVENTS = ["pointerdown", "keydown", "touchstart", "wheel"] as const;

export interface Clock {
  now(): number;
}

export class IdleLock {
  private last: number;
  private locked = false;
  private readonly timeoutMs: number;
  private readonly clock: Clock;
  private readonly onLock: () => void;

  constructor(options: { timeoutMs: number; clock: Clock; onLock: () => void }) {
    this.timeoutMs = Math.min(options.timeoutMs, IDLE_LOCK_MS);
    this.clock = options.clock;
    this.onLock = options.onLock;
    this.last = this.clock.now();
  }

  get isLocked(): boolean {
    return this.locked;
  }

  /** Call ONLY for genuine user interaction. Ignored once locked. */
  recordActivity(): void {
    if (this.locked) return;
    if (this.check()) return; // interaction after the deadline cannot revive the session
    this.last = this.clock.now();
  }

  /** Evaluates elapsed time; locks (once) when the limit is reached. */
  check(): boolean {
    if (!this.locked && this.clock.now() - this.last >= this.timeoutMs) {
      this.lock();
    }
    return this.locked;
  }

  /** Immediate lock (manual lock, server says session ended, …). */
  lock(): void {
    if (this.locked) return;
    this.locked = true;
    this.onLock();
  }

  msUntilLock(): number {
    return this.locked ? 0 : Math.max(0, this.timeoutMs - (this.clock.now() - this.last));
  }
}

export interface IdleLockEnvironment {
  addEventListener(type: string, listener: () => void, options?: AddEventListenerOptions): void;
  removeEventListener(type: string, listener: () => void, options?: AddEventListenerOptions): void;
  setInterval(fn: () => void, ms: number): unknown;
  clearInterval(id: unknown): void;
  isVisible(): boolean;
}

/**
 * Wires an IdleLock to the page. Visibility/pageshow/interval only CHECK
 * elapsed time; only ACTIVITY_EVENTS record activity, and only while the
 * page is visible. Returns a disposer.
 */
export function installIdleLock(lock: IdleLock, env: IdleLockEnvironment): () => void {
  const onActivity = () => {
    if (env.isVisible()) lock.recordActivity();
  };
  const onCheck = () => {
    lock.check();
  };
  const opts: AddEventListenerOptions = { capture: true, passive: true };
  for (const type of ACTIVITY_EVENTS) env.addEventListener(type, onActivity, opts);
  env.addEventListener("visibilitychange", onCheck, opts);
  env.addEventListener("pageshow", onCheck, opts);
  env.addEventListener("focus", onCheck, opts);
  const timer = env.setInterval(onCheck, CHECK_INTERVAL_MS);
  return () => {
    for (const type of ACTIVITY_EVENTS) env.removeEventListener(type, onActivity, opts);
    env.removeEventListener("visibilitychange", onCheck, opts);
    env.removeEventListener("pageshow", onCheck, opts);
    env.removeEventListener("focus", onCheck, opts);
    env.clearInterval(timer);
  };
}
