# Staff redemption and handoff recovery state machine (UI-03D.D)

**Status: pure TypeScript, NOT wired.** The code lives in
`src/staff/redemptionRecovery/` (`machine.ts`, `wire.ts`). Neither `/club-admin/`
(`src/staff/main.tsx`) nor the ordering app (`src/main.tsx`) imports it, and a
bundle-graph test enforces that.

- No request is made: the transport, the per-tab storage and the id generator
  are all injected.
- There is no `fetch`, `sessionStorage`, `localStorage`, DOM or wall clock in
  the core.
- There is no PHP, REST, WordPress, SQL or UI change.
- The staff flag stays off and real redemption stays HTTP 503.

## Business facts kept apart

| Fact | Meaning |
|---|---|
| redemption committed | today's entitlement is consumed (server ledger, one per member per Athens day) |
| handoff confirmation recorded | staff recorded a statement (`handed_now` / `already_handed_earlier`) |
| `unconfirmed` | no confirmation is recorded. This is **not** proof that no coffee was handed over |
| physical handoff | not observable by software; neither fact above proves it |

## States

| Phase | Meaning | Offered actions |
|---|---|---|
| `idle` | no open intent | start_redemption |
| `submitting_redemption` / `checking_status` / `handoff_confirmation_pending` | a request is in flight | none (double taps are refused) |
| `redemption_unresolved` | outcome unknown (lost response, 5xx, invalid reply, `outcome_unknown`) | retry_redemption (SAME id), check_status |
| `recorded_now` | a fresh commit (`redeemed` + `prepare`) | acknowledge_prepare **once**; then confirm_handed_now / confirm_handed_earlier / finish |
| `previously_recorded_unconfirmed` | replay or recovery, today, no confirmation recorded | **Never prepare.** First `confirm_manual_verification` (UI-04B, see below), check_status or finish; only after verification: confirm_handed_now / confirm_handed_earlier |
| `previously_recorded_confirmed` | replay or recovery, today, confirmation recorded | finish, check_status |
| `previous_business_date` | the request belongs to an earlier Athens day | finish. Today's coffee needs a NEW intent |
| `redemption_refused` | definitive refusal (e.g. `benefit_already_redeemed`) | finish |
| `handoff_unresolved` | handoff outcome unknown | retry_handoff (SAME handoff id), check_status |
| `handoff_confirmed` | confirmation recorded (ours, replayed, already recorded by another tablet, or learned from status) | finish, check_status |
| `handoff_refused` | `late_confirmation_not_authorised` / `idempotency_key_reused` / `invalid_request` | check_status, manager_review |
| `blocked_integrity` | the server reported `record_integrity_failure` | manager_review only |
| `blocked_authorization` | 401, 403, lock, logout or expiry | reauthenticate only |
| `recovery_required` | `not_recorded` (resubmit the SAME intent, or abandon), `session_changed` (explicit resume), `storage_corrupt` (abandon or manager), `status_unavailable` (check again), `redemption_missing` | as listed |

## Recovery safety invariants

1. **Prepare is offered only once, and only from a fresh commit's own reply.**
   It is offered nowhere else: not after a reload, a replay, a status read, an
   unknown outcome or a refusal. Reloading right after a commit gives the
   manual-verification state.
2. **No new id is created automatically.** Retries and recovery reuse the
   original redemption request id and the original handoff request id. After
   an authoritative "not recorded", only an identical payload may be resent,
   and it keeps the same id. The order is bound by the SHA-256 of its canonical
   text (UI-04B, injected hasher); the server's fingerprint v2 stays the
   authority.
3. **The server status is read before anything is offered after a reload or
   an uncertain outcome.**
   - If the status cannot be read, the machine stays unresolved and keeps both
     ids (fail closed).
   - A lost response is never treated as a failed transaction.
   - An unresolved intent cannot be abandoned and blocks a new order. Overrides
     are an owner decision.
4. **Late replies are ignored.** An operation sequence number discards every
   reply from an older operation, and every reply after a session end.
5. **A recorded confirmation is never downgraded.** A later `unconfirmed` read
   (for example from a lagging replica) does not undo it.
6. **Server dates are authoritative.** A replay or status of an earlier
   Europe/Athens business date is never today's coffee.
   - The parser checks that every `redeemed_at_utc` falls on the stated Athens
     day, including the summer/winter time changes.
   - A reply that crosses midnight keeps the commit's own date.
7. **Authorization and integrity failures block the machine.** A 401, 403,
   `forbidden`, lock, logout or expiry blocks it. A record integrity failure
   blocks it for management review.
8. **Only parsed server values set the fulfillment status.** The parsers reject
   unknown outcomes, missing or extra keys, ids that do not echo the request,
   and every contradiction, for example:
   - a replay with `prepare`;
   - `confirmed` without a handoff;
   - a handoff time invented for `already_handed_earlier`;
   - "already confirmed" carrying our own handoff id.
9. **The device reference is audit-only.** It is sent with a handoff, never
   persisted and never part of any decision. Tablets on the shared staff
   account are not told apart by staff id.

## Per-tab persistence (`RecoveryStorage`, injected)

The persisted record (format **v2**, UI-04B) has exactly `v`, `session_ref`,
`member_id` (opaque `mem_…`), `redemption_request_id`, `payload_sha256`,
`stage`, `handoff_request_id` and `claim`. A v1 record (32-bit FNV-1a) gives
`recovery_required(storage_outdated)` and is never trusted or queried.

- **Never persisted:** tokens, nonces, names, phone numbers, preparation
  payloads, server replies or the device reference.
- **Corruption:** a corrupt or unreadable record gives
  `recovery_required(storage_corrupt)`.
- **Write failures** never change a decision.
- **Session change:** a record from another login session needs an explicit
  `resume_recovery`, which re-binds it.
- **sessionStorage:** a future adapter may use it only after a separate review.

## Required REST additions (future task)

- Every redemption reply needs `server_business_date`, the server's Athens
  date at decision time. The parser uses it to check the instruction.
- The redemption, handoff and status replies must use the snake_case shapes
  that `wire.ts` validates. They mirror the PHP accessors on PR #14 and #15.

## Open owner decisions (not activated)

- **Mandatory handoff confirmation.** The machine offers it but does not require
  it. `finish` closes a recorded but unconfirmed redemption, which stays
  `unconfirmed` on the server for management review, and never restores an
  entitlement.
- **The late-confirmation grace period.** The server refuses; the machine shows
  `handoff_refused`.
- **Manager override policy.** `manager_review` is only a state label.
- **Dispute resolution.**
- **Device identification.**
- **Retention.**

## UI-04B hardening

**Explicit manual verification.** `previously_recorded_unconfirmed` (a replay
or recovery, with no confirmation recorded) no longer allows a handoff claim
directly.

- Staff first call `confirmManualVerification()`: they state they checked the
  situation with the customer and/or the counter.
- This is LOCAL workflow state (`manuallyVerified`). It is never persisted
  or sent, and it is **not proof of a physical handoff**.
- It never prepares, redeems, or restores an entitlement.
- It is voided by any status read (authoritative or unreadable), a
  reload or new machine instance, a session end (logout, lock, expiry,
  403), and a member change (finish, then a new intent).
- The fresh-redemption flow (`recorded_now` → acknowledge prepare → confirm)
  is unchanged.
- Handoff confirmation stays optional: `finish` remains available, and
  whether it should be is an OPEN owner policy.

**Exact payload identity.**
- `freezePayload()` validates every code against the canonical id pattern, so
  the canonical text `evc-staff-intent-v2\n…` is unambiguous. It also freezes a
  deep copy, so later changes to the caller's object never reach a retry.
- `MachineDeps.sha256Hex` (async, injected; a future browser adapter would use
  `crypto.subtle.digest`) produces the 64-hex identity.
- A failing or malformed digest refuses to start: no id is minted and nothing
  is sent.
- A session end during hashing makes the start stale.
- The server's fingerprint v2 remains the authority. Even a colliding client
  hash cannot reuse a request id for another order (`idempotency_key_reused`).
