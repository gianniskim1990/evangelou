import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { ACTIVITY_EVENTS, IDLE_LOCK_MS, IdleLock, installIdleLock, type IdleLockEnvironment } from "../../src/staff/idleLock";

class FakeClock {
  t = 1_800_000_000_000;
  now = () => this.t;
  advance(ms: number) {
    this.t += ms;
  }
}

function setup(timeoutMs = IDLE_LOCK_MS) {
  const clock = new FakeClock();
  let locks = 0;
  const lock = new IdleLock({ timeoutMs, clock, onLock: () => locks++ });
  return { clock, lock, locks: () => locks };
}

class FakeEnv implements IdleLockEnvironment {
  listeners = new Map<string, Set<() => void>>();
  intervals: (() => void)[] = [];
  visible = true;
  addEventListener(type: string, fn: () => void) {
    if (!this.listeners.has(type)) this.listeners.set(type, new Set());
    this.listeners.get(type)!.add(fn);
  }
  removeEventListener(type: string, fn: () => void) {
    this.listeners.get(type)?.delete(fn);
  }
  setInterval(fn: () => void) {
    this.intervals.push(fn);
    return this.intervals.length;
  }
  clearInterval() {
    this.intervals = [];
  }
  isVisible() {
    return this.visible;
  }
  fire(type: string) {
    for (const fn of this.listeners.get(type) ?? []) fn();
  }
  tick() {
    for (const fn of this.intervals) fn();
  }
}

describe("5-minute idle lock", () => {
  it("is exactly 300 seconds", () => {
    assert.equal(IDLE_LOCK_MS, 300_000);
  });

  it("does not lock at 4m59.999s and locks at exactly 5m00s", () => {
    const { clock, lock, locks } = setup();
    clock.advance(299_999);
    assert.equal(lock.check(), false);
    clock.advance(1);
    assert.equal(lock.check(), true);
    assert.equal(locks(), 1);
  });

  it("genuine activity at 4m59s restarts the five minutes", () => {
    const { clock, lock } = setup();
    clock.advance(299_000);
    lock.recordActivity();
    clock.advance(299_999);
    assert.equal(lock.check(), false);
    clock.advance(1);
    assert.equal(lock.check(), true);
  });

  it("checks (timers, polling) never count as activity", () => {
    const { clock, lock } = setup();
    for (let i = 0; i < 299; i++) {
      clock.advance(1_000);
      lock.check();
    }
    clock.advance(1_000);
    assert.equal(lock.check(), true);
  });

  it("locks on resume after sleep even if no timer fired meanwhile", () => {
    const { clock, lock } = setup();
    clock.advance(45 * 60_000); // device asleep, no ticks
    lock.recordActivity(); // first touch after waking
    assert.equal(lock.isLocked, true, "interaction after the deadline cannot revive the session");
  });

  it("stays locked: later activity is ignored and onLock fires once", () => {
    const { clock, lock, locks } = setup();
    clock.advance(IDLE_LOCK_MS);
    lock.check();
    lock.recordActivity();
    clock.advance(10);
    lock.check();
    lock.lock();
    assert.equal(lock.isLocked, true);
    assert.equal(locks(), 1);
  });

  it("never waits longer than five minutes even if configured longer", () => {
    const { clock, lock } = setup(3_600_000);
    clock.advance(IDLE_LOCK_MS);
    assert.equal(lock.check(), true);
  });

  it("wires only genuine interaction events as activity", () => {
    assert.deepEqual([...ACTIVITY_EVENTS], ["pointerdown", "keydown", "touchstart", "wheel"]);
    const { clock, lock } = setup();
    const env = new FakeEnv();
    installIdleLock(lock, env);
    for (const type of ["mousemove", "scroll", "focus", "visibilitychange", "pageshow"]) {
      clock.advance(60_000);
      env.fire(type);
    }
    assert.equal(lock.isLocked, true, "non-interaction events only check; after 5 min they lock");
  });

  it("activity while the page is hidden does not count; visibility change re-checks", () => {
    const { clock, lock } = setup();
    const env = new FakeEnv();
    installIdleLock(lock, env);
    clock.advance(200_000);
    env.visible = false;
    env.fire("pointerdown");
    clock.advance(100_000);
    env.visible = true;
    env.fire("visibilitychange");
    assert.equal(lock.isLocked, true);
  });

  it("interval ticks lock on time; disposer removes every listener", () => {
    const { clock, lock } = setup();
    const env = new FakeEnv();
    const dispose = installIdleLock(lock, env);
    clock.advance(150_000);
    env.fire("touchstart");
    clock.advance(299_999);
    env.tick();
    assert.equal(lock.isLocked, false);
    clock.advance(1);
    env.tick();
    assert.equal(lock.isLocked, true);
    dispose();
    assert.equal([...env.listeners.values()].reduce((n, s) => n + s.size, 0), 0);
    assert.equal(env.intervals.length, 0);
  });
});
