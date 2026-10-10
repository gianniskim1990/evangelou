import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { createRestClubService } from "../../src/club/restClubService";
import type { StaffConfig } from "../../src/staff/config";
import { NonceStore, StaffApiError, createStaffApi } from "../../src/staff/staffApi";
import { fakeFetch, json, text } from "../club/helpers";

const CONFIG: StaffConfig = {
  restBase: "https://shop.example/wp-json/evangelou-club/v1",
  nonce: "0a1b2c3d4e",
  loginUrl: "https://shop.example/wp-login.php?reauth=1",
  shellUrl: "https://shop.example/club-admin/",
  idleLockSeconds: 300,
  sessionRef: "0123456789abcdef0123456789abcdef",
  appVersion: "test",
  features: { qrLookup: false, phoneLookup: false, coffeeRedemption: false, history: false },
};

const SESSION_OK = {
  authenticated: true,
  session: { expires_at: "2026-10-10T22:00:00+03:00", idle_timeout_seconds: 1800 },
  features: { qr_lookup: false, phone_lookup: false, coffee_redemption: false, history: false },
};

async function kind(promise: Promise<unknown>): Promise<string> {
  try {
    await promise;
  } catch (err) {
    assert.ok(err instanceof StaffApiError);
    return err.kind;
  }
  assert.fail("expected failure");
}

describe("NonceStore", () => {
  it("keeps only well-formed WordPress nonces", () => {
    const store = new NonceStore("0a1b2c3d4e");
    assert.equal(store.update("ffffffffff"), true);
    assert.equal(store.get(), "ffffffffff");
    for (const bad of [null, "", "abc", "<script>12", "FFFFFFFFFF"]) assert.equal(store.update(bad), false);
    assert.equal(store.get(), "ffffffffff");
    assert.throws(() => new NonceStore("nope"));
  });
});

describe("staff API transport", () => {
  it("sends the nonce header with same-origin credentials, never in the URL", async () => {
    const fetchImpl = fakeFetch(() => json(200, SESSION_OK));
    const api = createStaffApi(CONFIG, fetchImpl);
    const session = await api.getSession();
    assert.equal(session.expiresAt, "2026-10-10T22:00:00+03:00");
    assert.deepEqual(session.features, { qrLookup: false, phoneLookup: false, coffeeRedemption: false, history: false });
    const { url, init } = fetchImpl.calls[0];
    assert.equal(url, `${CONFIG.restBase}/session`);
    assert.ok(!url.includes(CONFIG.nonce), "nonce never in the URL");
    assert.equal(new Headers(init.headers).get("X-WP-Nonce"), CONFIG.nonce);
    assert.equal(init.credentials, "same-origin");
    assert.equal(init.cache, "no-store");
    assert.equal(new Headers(init.headers).get("Authorization"), null);
  });

  it("adopts the refreshed X-WP-Nonce WordPress returns and uses it next", async () => {
    const fetchImpl = fakeFetch(
      () => json(200, SESSION_OK, { "X-WP-Nonce": "1111111111" }),
      () => json(200, SESSION_OK),
    );
    const api = createStaffApi(CONFIG, fetchImpl);
    await api.getSession();
    assert.equal(api.currentNonce(), "1111111111");
    await api.getSession();
    assert.equal(new Headers(fetchImpl.calls[1].init.headers).get("X-WP-Nonce"), "1111111111");
  });

  it("ignores a malformed refreshed nonce", async () => {
    const api = createStaffApi(CONFIG, fakeFetch(() => json(200, SESSION_OK, { "X-WP-Nonce": "evil<>" })));
    await api.getSession();
    assert.equal(api.currentNonce(), CONFIG.nonce);
  });

  it("maps WordPress auth failures and transport problems", async () => {
    assert.equal(await kind(createStaffApi(CONFIG, fakeFetch(() => json(401, { error: { code: "unauthorized" } }))).getSession()), "unauthorized");
    assert.equal(await kind(createStaffApi(CONFIG, fakeFetch(() => json(403, { code: "rest_forbidden" }))).getSession()), "forbidden");
    assert.equal(await kind(createStaffApi(CONFIG, fakeFetch(() => text(502, "<html>"))).getSession()), "server");
    assert.equal(await kind(createStaffApi(CONFIG, fakeFetch(() => text(200, "<!doctype html>"))).getSession()), "invalid_response");
    assert.equal(await kind(createStaffApi(CONFIG, fakeFetch(() => json(200, { authenticated: false }))).getSession()), "invalid_response");
    assert.equal(
      await kind(createStaffApi(CONFIG, fakeFetch(() => json(200, { ...SESSION_OK, session: { expires_at: "2026-10-10 22:00:00", idle_timeout_seconds: 1800 } }))).getSession()),
      "invalid_response",
    );
    const offline = fakeFetch(() => {
      throw new TypeError("Failed to fetch");
    });
    assert.equal(await kind(createStaffApi(CONFIG, offline).getSession()), "network");
  });

  it("refuses to send the nonce to another origin", async () => {
    const fetchImpl = fakeFetch(() => json(200, {}));
    const api = createStaffApi(CONFIG, fetchImpl);
    await assert.rejects(() => api.staffFetch("https://evil.example/steal"));
    assert.equal(fetchImpl.calls.length, 0);
  });

  it("ends the session with an authenticated POST", async () => {
    const fetchImpl = fakeFetch(() => json(200, { ended: true }));
    await createStaffApi(CONFIG, fetchImpl).endSession();
    assert.equal(fetchImpl.calls[0].url, `${CONFIG.restBase}/session/end`);
    assert.equal(fetchImpl.calls[0].init.method, "POST");
    assert.equal(new Headers(fetchImpl.calls[0].init.headers).get("X-WP-Nonce"), CONFIG.nonce);
  });

  it("can carry the future member endpoints through restClubService (nonce attached)", async () => {
    const fetchImpl = fakeFetch(() => json(503, { error: { code: "server_error", message: "x" } }));
    const api = createStaffApi(CONFIG, fetchImpl);
    const rest = createRestClubService({ baseUrl: CONFIG.restBase, fetchImpl: api.staffFetch });
    await assert.rejects(() =>
      rest.redeemBenefit({ memberId: "mem_x", benefitType: "free_coffee", coffeeCode: "espresso", requestId: "3fa85f64-5717-4562-b3fc-2c963f66afa6" }),
    );
    assert.equal(new Headers(fetchImpl.calls[0].init.headers).get("X-WP-Nonce"), CONFIG.nonce);
  });
});

class FakeTimers {
  private next = 1;
  readonly pending = new Map<number, { fn: () => void; ms: number }>();
  setTimeout = (fn: () => void, ms: number): unknown => {
    const id = this.next++;
    this.pending.set(id, { fn, ms });
    return id;
  };
  clearTimeout = (id: unknown): void => {
    this.pending.delete(id as number);
  };
  fireAll(): void {
    for (const [id, t] of [...this.pending]) {
      this.pending.delete(id);
      t.fn();
    }
  }
}

/** A fetch that never settles and ignores its AbortSignal (worst case). */
const hangingFetch = (() => new Promise<Response>(() => undefined)) as unknown as typeof fetch;

/** Headers arrive, then the body stalls forever. */
function stalledBodyFetch(): typeof fetch {
  return (async () =>
    new Response(new ReadableStream<Uint8Array>({ start() {} }), {
      status: 200,
      headers: { "Content-Type": "application/json" },
    })) as unknown as typeof fetch;
}

function countingSignal() {
  const controller = new AbortController();
  const counts = { added: 0, removed: 0 };
  const add = controller.signal.addEventListener.bind(controller.signal);
  const remove = controller.signal.removeEventListener.bind(controller.signal);
  controller.signal.addEventListener = ((...args: Parameters<AbortSignal["addEventListener"]>) => {
    counts.added++;
    add(...args);
  }) as AbortSignal["addEventListener"];
  controller.signal.removeEventListener = ((...args: Parameters<AbortSignal["removeEventListener"]>) => {
    counts.removed++;
    remove(...args);
  }) as AbortSignal["removeEventListener"];
  return { controller, counts };
}

// Bounded so a missing request timeout FAILS fast instead of hanging the run.
describe("staff API — bounded session requests", { timeout: 5_000 }, () => {
  it("uses a 10 s bound by default", async () => {
    const timers = new FakeTimers();
    const api = createStaffApi(CONFIG, hangingFetch, { timers });
    const pending = kind(api.getSession());
    assert.deepEqual([...timers.pending.values()].map((t) => t.ms), [10_000]);
    timers.fireAll();
    assert.equal(await pending, "timeout");
  });

  it("a session request that never resolves times out (A)", async () => {
    for (const op of ["getSession", "endSession"] as const) {
      const timers = new FakeTimers();
      const api = createStaffApi(CONFIG, hangingFetch, { timers, timeoutMs: 50 });
      const pending = kind(api[op]());
      timers.fireAll();
      assert.equal(await pending, "timeout", op);
    }
  });

  it("a body that stalls after headers times out (B)", async () => {
    const timers = new FakeTimers();
    const api = createStaffApi(CONFIG, stalledBodyFetch(), { timers, timeoutMs: 50 });
    const pending = kind(api.getSession());
    await new Promise((r) => setImmediate(r)); // headers delivered, body read started
    timers.fireAll();
    assert.equal(await pending, "timeout");
  });

  it("times out with the real timer implementation too", async () => {
    const api = createStaffApi(CONFIG, stalledBodyFetch(), { timeoutMs: 20 });
    assert.equal(await kind(api.endSession()), "timeout");
  });

  it("passes an abortable signal to fetch and aborts it on timeout", async () => {
    const timers = new FakeTimers();
    let seen: AbortSignal | undefined;
    const api = createStaffApi(
      CONFIG,
      ((_: unknown, init?: RequestInit) => {
        seen = init?.signal ?? undefined;
        return new Promise<Response>(() => undefined);
      }) as unknown as typeof fetch,
      { timers },
    );
    const pending = kind(api.getSession());
    assert.equal(seen?.aborted, false);
    timers.fireAll();
    assert.equal(await pending, "timeout");
    assert.equal(seen?.aborted, true);
  });

  it("caller cancellation is reported as aborted, not timeout (G)", async () => {
    const timers = new FakeTimers();
    const api = createStaffApi(CONFIG, hangingFetch, { timers });
    const caller = new AbortController();
    const pending = kind(api.getSession(caller.signal));
    caller.abort();
    assert.equal(await pending, "aborted");

    const already = new AbortController();
    already.abort();
    assert.equal(await kind(api.endSession(already.signal)), "aborted");
  });

  it("leaves no timers or caller listeners behind (H)", async () => {
    const timers = new FakeTimers();
    const outcomes: [string, typeof fetch][] = [
      ["success", fakeFetch(() => json(200, SESSION_OK))],
      ["http error", fakeFetch(() => json(500, { error: { code: "x", message: "m" } }))],
      ["network", (async () => { throw new TypeError("offline"); }) as unknown as typeof fetch],
      ["timeout", hangingFetch],
      ["caller abort", hangingFetch],
    ];
    for (const [name, impl] of outcomes) {
      const api = createStaffApi(CONFIG, impl, { timers });
      const { controller, counts } = countingSignal();
      const pending = api.getSession(controller.signal).catch(() => undefined);
      if (name === "timeout") timers.fireAll();
      if (name === "caller abort") controller.abort();
      await pending;
      assert.equal(timers.pending.size, 0, `${name}: timer cleared`);
      assert.equal(counts.added, counts.removed, `${name}: listener removed`);
      assert.ok(counts.added >= 1, `${name}: caller signal was linked`);
    }
  });
});
