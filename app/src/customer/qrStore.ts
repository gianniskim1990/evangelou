import type { QrKeeper } from "./memberQr";

/*
 * STAGE-05D round 2 — owner decision 2026-11-04 «Ναι, να τον θυμάται»: the
 * member's phone remembers the Club QR between visits.
 *
 * THE ONLY FILE OF THE CUSTOMER APP ALLOWED TO TOUCH BROWSER STORAGE
 * (scripts/storage-guard.mjs, run by the packager on the customer bundle's
 * real import graph, and tests/club/customerLiveIsolation.test.ts).
 *
 * - Store: window.localStorage (the owner allowed it "if simpler" than
 *   IndexedDB): synchronous, no extra dependency, testable in jsdom. Both are
 *   equally readable by script on the page; neither is secret storage.
 * - Key:   "evc-club-qr:v1:" + SHA-256("evc-club-qr|" + account + "|" + issued_at)
 *   Value: {"v":1,"a": SHA-256("evc-club-qr-account|" + account),"t": token}
 *   `account` is the signed-in account's e-mail (lower case), never stored in
 *   the clear. Nothing else is stored: no name, no membership, no nonce.
 * - read() returns the token ONLY for the exact (account, issued_at) the
 *   server reports now; every other record of that account is deleted.
 * - The memberQr.ts flow deletes on sign-out, signed_out, not_member,
 *   member_disabled, route off and replace (see there).
 * - Without SHA-256 (no secure context) or without a working localStorage
 *   (private mode, blocked, full), nothing is persisted: the QR lives in
 *   memory only, as before. Every access is wrapped; a failure never shows a
 *   code and never throws into the app.
 * The server never re-sends a token: if this record is lost, the member uses
 * a screenshot or asks for «Νέο QR».
 */

export const QR_STORE_PREFIX = "evc-club-qr:v1:";
const TOKEN_RE = /^evc_[0-9a-f]{48}$/;

type StorageLike = Pick<Storage, "getItem" | "setItem" | "removeItem" | "key" | "length">;
type SubtleLike = Pick<SubtleCrypto, "digest">;

async function sha256(subtle: SubtleLike, text: string): Promise<string> {
  const digest = await subtle.digest("SHA-256", new TextEncoder().encode(text));
  return Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, "0")).join("");
}

function browserStorage(): StorageLike | null {
  try {
    const s = (globalThis as unknown as { localStorage?: StorageLike }).localStorage;
    if (!s) return null;
    // Probe once: Safari private mode and blocked storage throw here.
    const probe = `${QR_STORE_PREFIX}probe`;
    s.setItem(probe, "1");
    s.removeItem(probe);
    return s;
  } catch {
    return null;
  }
}

function browserSubtle(): SubtleLike | null {
  const c = (globalThis as unknown as { crypto?: { subtle?: SubtleLike } }).crypto;
  return c?.subtle && typeof c.subtle.digest === "function" ? c.subtle : null;
}

interface Record_ {
  v: 1;
  a: string;
  t: string;
}

function parse(raw: string | null): Record_ | null {
  if (raw === null) return null;
  try {
    const r = JSON.parse(raw) as Partial<Record_>;
    return r && r.v === 1 && typeof r.a === "string" && /^[0-9a-f]{64}$/.test(r.a) && typeof r.t === "string" && TOKEN_RE.test(r.t) ? (r as Record_) : null;
  } catch {
    return null;
  }
}

/** The persistent keeper over `storage`, or null when it cannot work (then the flow keeps memory only). */
export function createStorageQrKeeper(storage: StorageLike | null = browserStorage(), subtle: SubtleLike | null = browserSubtle()): QrKeeper | null {
  if (!storage || !subtle) return null;
  const ownKeys = (): string[] => {
    const keys: string[] = [];
    try {
      for (let i = 0; i < storage.length; i++) {
        const k = storage.key(i);
        if (k !== null && k.startsWith(QR_STORE_PREFIX)) keys.push(k);
      }
    } catch {
      // unreadable: nothing of ours is visible
    }
    return keys;
  };
  const remove = (k: string) => {
    try {
      storage.removeItem(k);
    } catch {
      // best effort; read() still refuses anything that does not match exactly
    }
  };
  const accountHash = (account: string) => sha256(subtle, `evc-club-qr-account|${account.trim().toLowerCase()}`);
  const recordKey = async (account: string, issuedAt: string) => QR_STORE_PREFIX + (await sha256(subtle, `evc-club-qr|${account.trim().toLowerCase()}|${issuedAt}`));
  /** Deletes every record of the account except `keep`; unreadable records of ours are deleted too. */
  const prune = (a: string, keep: string | null) => {
    for (const k of ownKeys()) {
      let raw: string | null = null;
      try {
        raw = storage.getItem(k);
      } catch {
        raw = null;
      }
      const r = parse(raw);
      if (r === null || (r.a === a && k !== keep)) remove(k);
    }
  };

  return {
    async read(account, issuedAt) {
      try {
        const a = await accountHash(account);
        const want = await recordKey(account, issuedAt);
        prune(a, want);
        const r = parse(storage.getItem(want));
        if (r === null || r.a !== a) {
          remove(want);
          return null;
        }
        return r.t;
      } catch {
        return null;
      }
    },
    async write(account, issuedAt, token) {
      if (!TOKEN_RE.test(token)) return;
      try {
        const a = await accountHash(account);
        prune(a, null);
        storage.setItem(await recordKey(account, issuedAt), JSON.stringify({ v: 1, a, t: token } satisfies Record_));
      } catch {
        // not persisted (full, blocked): the QR is still shown from memory this visit
      }
    },
    async forget(account) {
      try {
        prune(await accountHash(account), null);
      } catch {
        // nothing more can be done
      }
    },
    async forgetAll() {
      for (const k of ownKeys()) remove(k);
    },
  };
}
