# Staff recovery: per-tab storage adapter (UI-04J)

**Status: implemented and tested, NOT wired.**

- The code is `app/src/staff/redemptionRecovery/browserStorage.ts`.
- `StaffApp`, `staffController`, `staffApi` and `main.tsx` do not import it.
- An import-graph test proves that neither shipped entry reaches it, and the
  staff bundle hash is unchanged (`staff-CrPA0l2H.js`).

## Design

`createSessionRecoveryStorage(provider = browserSessionStorage)` returns
the machine's `RecoveryStorage` (`read`, `write`, `remove`).

- **One key.** `evc-staff-redemption-recovery:v2`. It is distinct from
  `staffController`'s `LOCK_MARKER_KEY` (`evc-staff-locked-session`). Other
  keys are never read, written or cleared.
- **Lazy.** `provider()` is called on every operation, never at import or
  construction time. The default provider returns `null` when there is no
  `window` (Node, SSR). Its handle is never cached.
- **sessionStorage only.** No localStorage, cookies, IndexedDB,
  BroadcastChannel, network or logging. The import-graph test greps for all
  of these.
- **Minimal data, enforced at the boundary.** `write()` accepts only a
  value that the machine's own strict v2 validator (`parsePersisted`)
  accepts:

  | Field | Content |
  |---|---|
  | version | `2` |
  | `session_ref` | non-secret 32-hex reference |
  | `member_id` | opaque member id |
  | `redemption_request_id` | the original request id |
  | `payload_sha256` | SHA-256 order identity |
  | `stage` | recovery stage |
  | `handoff_request_id` | the original handoff id |
  | `claim` | handoff claim |

  Anything else is refused before it reaches the browser: a nonce, token,
  cookie, QR text, lookup proof, phone, name, email, payment data, the
  order payload, free text, the manual-verification flag, a v1 envelope, or
  a value over 1024 characters.
- **Errors, never "nothing saved".**
  - A missing store, a throwing getter (`SecurityError`), or any
    `getItem` / `setItem` / `removeItem` exception throws
    `RecoveryStorageError` with one of `unavailable | security | quota |
    refused | failed`.
  - The message never contains the stored value or the browser's text.
  - `read()` returns `null` **only** when the store works and holds nothing.
- **Not secure storage.** sessionStorage is not encrypted. Any script on
  the page can read it (XSS). It holds only the non-secret ids above.

## Machine fix (narrow, adversarially tested)

**The problem.** Before this task, a failed `read()` at boot became
`storage_corrupt`, which offers `abandon_intent`. Abandoning calls
`remove()`. If the read failure was transient (a `SecurityError`, an
unavailable store), `remove()` could succeed and delete a **valid stored
unresolved intent** that was never read. That broke the rule "never clear
an unresolved intent because storage is unavailable".

**The fix:**
- A read failure is now `recovery_required` / `storage_unavailable`.
- Its actions are `retry_restore` and `manager_review` only. Abandon,
  finish and starting a new intent are rejected, and nothing is sent.
- `restore()` is boot-only: it is accepted from `idle` (and again from
  `storage_unavailable`), never over a live flow.
- When the store is readable again, the retried restore recovers the
  **original** ids through the server.
- `storage_corrupt` (unreadable *content*) is unchanged.

**Guards:** Y1–Y6 (machine) and Y7–Y12 (adapter).

## Recovery behaviour (unchanged machine invariants, now through real storage)

- **Same-tab reload:**
  - the v2 context is read and validated;
  - it rebinds only when `session_ref` matches;
  - the server status is read **before** any action;
  - a recorded redemption is `previously_recorded_*` (never "prepare");
  - no id is minted.
- **Different login session:**
  - `session_changed`, with no request until the explicit
    `resume_recovery`;
  - then a status read, and only then is the context rebound to the new
    session.
- **Logout or lock** (`sessionEnded`):
  - in-flight replies become stale;
  - the stored ids are kept for an authorised recovery, never discarded;
  - a nonce is never stored, so it can never be reused.
- **Unknown redemption outcome:**
  - the original request id is kept across reloads;
  - a lost response is never treated as a failure;
  - `not_recorded` allows only the **same** order (hash-bound) under the
    **same** id.
- **Unknown handoff outcome:**
  - the original handoff id is kept, and the status read decides;
  - a handoff is never invented;
  - a retry uses the same id.
- **Manual verification** is never written, and resets on reload and on
  every status read.
- **Cross-member:** an envelope edited to another member is `not_recorded`
  for that member, and a resubmission is refused (`idempotency_key_reused`).

## Cross-tab behaviour and limitations

**sessionStorage is not a tab identity.** A window opened *with an opener*
may start with a **copy** of the opener's storage. Two such tabs share the
same `session_ref`, because one WordPress login is shared by every window.

What protects the coffee is the **server**, not the storage:
- request ids are idempotent;
- one coffee per member per Athens day;
- one handoff per redemption.

Tested:
- **A clone made while the original request is in flight.** The clone sees
  `not_recorded`; if it resubmits the same order, it gets an idempotent
  replay. Exactly **one** "prepare" across both tabs.
- **A clone made after the commit.** It is `previously_recorded_unconfirmed`
  (never prepare). A new intent in the clone is refused
  (`benefit_already_redeemed`).
- **Concurrent resumes of one saved redemption.** One `handoff_confirmed`,
  one `handoff_already_confirmed`; the server records one handoff.
- **Two independent tabs.** Independent state; the second intent is refused.
- **Two tablets on the shared account (different sessions).** No adoption
  without the explicit, server-checked resume.
- **Stale replies.** A status reply that arrives after a lock is `stale` and
  never changes the newer state.

**Limitations to handle at browser integration (future, separately
reviewed):**
1. A cloned context can make two tabs work on the same intent. This is safe
   (server idempotency) but confusing. Future options:
   - open the staff app only with `rel="noopener"` /
     `window.open(..., "noopener")`;
   - detect a clone with a per-tab random id held in memory only;
   - show "this redemption is open in another tab".

   Do not use localStorage or a shared cross-tab key.
2. **Durability loss on a failed write.** A `QuotaExceededError` during
   `persist()` is swallowed (by design, the decision is unchanged), so a
   reload cannot recover that id. The server still prevents a second coffee
   (a new intent is refused), but the staff lose the recovery link.
   **Proposed fix (not implemented; owner/UX decision):** expose a
   `recoveryDurable` flag and either warn before sending, or refuse to
   *start* a new intent while the store is not writable. It is not
   implemented because no unsafe transition results; it changes service
   availability.
3. A failed `remove()` after `finish()` leaves a **settled** context. After a
   reload it resolves through the server to a recorded state and never
   prepares. Cosmetic only.
4. **Permanently unavailable storage** (e.g. a locked-down browser profile)
   keeps the tablet at `storage_unavailable` until a manager intervenes.
   Fail-closed by design: the staff tablet must provide sessionStorage.

## Tests

- **`app/tests/staff/recoveryStorage.test.ts`** (22 tests, fake browser
  `Storage` with failure injection and opener cloning):
  - **boundary:** laziness and no `window`, key isolation, data
    minimisation, error mapping, no logging;
  - **recovery:** reload, unknown redemption and handoff outcomes, manual
    verification, session change and logout, cross-member;
  - **cross-tab:** independent tabs, cloned opener (in flight and after
    commit), concurrent resumes, two tablets, stale replies;
  - **failures:**
    - unavailable store, `SecurityError` getter, read error;
    - restore over a live flow;
    - malformed, truncated, v1, bad hash, wrong member or session format,
      oversized, foreign session;
    - write quota, remove failure;
    - everything written is a minimal v2 envelope.
- **`tests/staff/isolation.test.ts`:** the adapter's import graph and API
  surface, and that neither shipped entry imports it.
- **`tests/staff/redemptionRecovery.test.ts`:** the unreadable-store test
  was tightened (the stored intent is untouched; retry recovers it).
  Fixtures moved unchanged to `tests/staff/support/recoveryFixtures.ts`.
