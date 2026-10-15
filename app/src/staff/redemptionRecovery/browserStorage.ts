import { parsePersisted, type RecoveryStorage } from "./machine";

/**
 * UI-04J — per-tab `sessionStorage` adapter for StaffRedemptionMachine.
 *
 * NOT wired: nothing in the staff app imports this module (the staff bundle
 * is unchanged). It stores ONE value under ONE namespaced key: the machine's
 * strict v2 envelope (ids, SHA-256 order identity, stage, handoff claim).
 *
 * - sessionStorage only: never localStorage, cookies or IndexedDB, and no
 *   network. Browser storage is reached LAZILY through `provider` on every
 *   call, so importing this module never touches `window` (Node tests, SSR).
 * - Fail closed: an unavailable store, a throwing getter (SecurityError) or a
 *   failing read is an ERROR, never "nothing saved" — `null` would let the
 *   machine boot to idle as if no unresolved intent existed. Write and remove
 *   failures throw too; the machine decides what they mean.
 * - Data minimisation at the boundary: `write` refuses anything that is not
 *   a valid v2 envelope, so no nonce, token, QR text, lookup proof, phone,
 *   name, payment data, order payload, free text or manual-verification flag
 *   can be stored through it, even by a future caller's mistake.
 * - Values and error details are never logged or put into error messages.
 *
 * sessionStorage is NOT encrypted and is readable by any script running on
 * the page (XSS); it only holds non-secret ids. It is also NOT a tab
 * identity: a window opened with an opener may start with a COPY of the
 * opener's storage. The server (idempotent request ids, one coffee per
 * member per day) stays the authority. See docs/staff-recovery-storage.md.
 */

/** Distinct from staffController's LOCK_MARKER_KEY ("evc-staff-locked-session"). */
export const RECOVERY_STORAGE_KEY = "evc-staff-redemption-recovery:v2";

/** Generous ceiling: a valid v2 envelope is ~330 characters. */
export const MAX_RECOVERY_VALUE_LENGTH = 1024;

export type StorageFailure = "unavailable" | "security" | "quota" | "refused" | "failed";

export class RecoveryStorageError extends Error {
  readonly failure: StorageFailure;

  constructor(failure: StorageFailure) {
    // Never the stored value, the key's content or the browser's message.
    super(`recovery storage: ${failure}`);
    this.failure = failure;
  }
}

/** Returns the tab's Storage, or null/undefined when there is none. May throw (SecurityError). */
export type StorageProvider = () => Storage | null | undefined;

/** Lazy default: `window.sessionStorage`, evaluated only when an operation runs. */
export const browserSessionStorage: StorageProvider = () => {
  if (typeof window === "undefined") return null;
  return window.sessionStorage;
};

function failureOf(err: unknown): StorageFailure {
  const name = typeof err === "object" && err !== null && "name" in err ? (err as { name: unknown }).name : null;
  if (name === "SecurityError") return "security";
  if (name === "QuotaExceededError" || name === "NS_ERROR_DOM_QUOTA_REACHED") return "quota";
  return "failed";
}

function storageOf(provider: StorageProvider): Storage {
  let s: Storage | null | undefined;
  try {
    s = provider();
  } catch (err) {
    throw new RecoveryStorageError(failureOf(err) === "security" ? "security" : "unavailable");
  }
  if (!s || typeof s.getItem !== "function" || typeof s.setItem !== "function" || typeof s.removeItem !== "function") {
    throw new RecoveryStorageError("unavailable");
  }
  return s;
}

export function createSessionRecoveryStorage(provider: StorageProvider = browserSessionStorage): RecoveryStorage {
  return {
    read(): string | null {
      const s = storageOf(provider);
      let value: string | null;
      try {
        value = s.getItem(RECOVERY_STORAGE_KEY);
      } catch (err) {
        throw new RecoveryStorageError(failureOf(err));
      }
      if (value !== null && typeof value !== "string") throw new RecoveryStorageError("failed");
      // An oversized value is handed on as-is: the machine's strict parser marks it corrupt.
      return value;
    },
    write(value: string): void {
      if (typeof value !== "string" || value.length > MAX_RECOVERY_VALUE_LENGTH) throw new RecoveryStorageError("refused");
      const parsed = parsePersisted(value);
      if (parsed === null || parsed === "outdated") throw new RecoveryStorageError("refused");
      const s = storageOf(provider);
      try {
        s.setItem(RECOVERY_STORAGE_KEY, value);
      } catch (err) {
        throw new RecoveryStorageError(failureOf(err));
      }
    },
    remove(): void {
      const s = storageOf(provider);
      try {
        s.removeItem(RECOVERY_STORAGE_KEY);
      } catch (err) {
        throw new RecoveryStorageError(failureOf(err));
      }
    },
  };
}
