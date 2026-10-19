import type { StaffConfig } from "../config";
import { StaffApiError, type SessionStatus, type StaffApi } from "../staffApi";
import { RECOVERY_STORAGE_KEY } from "../redemptionRecovery/browserStorage";
import type { FakeClubServer } from "./fakeServer";

/**
 * UI-04O LOCAL-ONLY stand-ins for what the WordPress /club-admin/ shell would
 * provide: a fake StaffApi (session status + session end) for the REAL
 * StaffController, a fake bootstrap config, and an in-memory "tab storage"
 * (the preview's sessionStorage) that survives simulated reloads and
 * re-logins like the real per-tab storage does.
 *
 * No WordPress, no cookie, no nonce exchange, no request of any kind.
 */

/** Same shape as the real one; the values are obviously fake and never sent anywhere. */
export function previewConfig(sessionRef: string): StaffConfig {
  return {
    restBase: "http://127.0.0.1/preview-only/evangelou-club/v1",
    nonce: "0a1b2c3d4e",
    loginUrl: "http://127.0.0.1/preview-only/wp-login.php?reauth=1",
    shellUrl: "http://127.0.0.1/preview-only/club-admin/",
    idleLockSeconds: 300,
    sessionRef,
    appVersion: "preview",
    features: { qrLookup: true, phoneLookup: true, coffeeRedemption: true, history: false },
  };
}

/** The staff session endpoints, answered by the fake server's session table. */
export function previewStaffApi(server: FakeClubServer, sessionRef: string, now: () => Date): StaffApi {
  return {
    staffFetch: () => Promise.reject(new StaffApiError("network")),
    currentNonce: () => "0a1b2c3d4e",
    async getSession(): Promise<SessionStatus> {
      if (!server.isSessionActive(sessionRef)) throw new StaffApiError("unauthorized", 401);
      return {
        expiresAt: new Date(now().getTime() + 30 * 60_000).toISOString(),
        idleTimeoutSeconds: 300,
        features: { qrLookup: true, phoneLookup: true, coffeeRedemption: true, history: false },
      };
    },
    async endSession(): Promise<void> {
      server.endSession(sessionRef);
    },
  };
}

function storageError(name: string): Error {
  const err = new Error(name);
  err.name = name;
  return err;
}

/**
 * The preview tab's sessionStorage. `failRecoveryWrites` / `failReads` let a
 * scenario reproduce a full or blocked browser store (QuotaExceededError /
 * SecurityError) for the recovery context only; the lock marker keeps working.
 */
export class PreviewTabStorage {
  private readonly items = new Map<string, string>();
  failRecoveryWrites = false;
  failReads = false;

  setFailRecoveryWrites(fail: boolean): void {
    this.failRecoveryWrites = fail;
  }

  get length(): number {
    return this.items.size;
  }

  clear(): void {
    this.items.clear();
  }

  key(index: number): string | null {
    return [...this.items.keys()][index] ?? null;
  }

  getItem(key: string): string | null {
    if (this.failReads && key === RECOVERY_STORAGE_KEY) throw storageError("SecurityError");
    return this.items.get(key) ?? null;
  }

  setItem(key: string, value: string): void {
    if (this.failRecoveryWrites && key === RECOVERY_STORAGE_KEY) throw storageError("QuotaExceededError");
    this.items.set(key, String(value));
  }

  removeItem(key: string): void {
    this.items.delete(key);
  }

  /** What the recovery adapter stored (ids only), for the preview's inspector. */
  recoveryValue(): string | null {
    return this.items.get(RECOVERY_STORAGE_KEY) ?? null;
  }
}
