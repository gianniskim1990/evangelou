# Safe fulfillment contract (UI-03D.C)

Status: **pure PHP, definitions only, NOT wired.**

- No SQL, migration, REST endpoint, hook or UI exists.
- The only implementations of the store and the authorizer are test fakes. The
  packager rejects any shipped implementation and any
  `new EVC_Handoff_Service` / `new EVC_Fulfillment_Read_Model`.
- The one shipped late-confirmation policy (`EVC_No_Late_Handoff_Policy`)
  refuses everything.
- The staff flag stays off, production redemption stays HTTP 503, and
  migration 003 stays unapproved.

## 1. What software can and cannot know

| Fact | Source | Meaning |
|---|---|---|
| entitlement consumed | ledger row (unique member + benefit + Athens day) | today's (or the original day's) free coffee is used |
| recording | `recorded_now` (this request committed it) / `previously_recorded` (a replay) | when and by which request the entitlement was consumed |
| fulfillment `unconfirmed` | no handoff record exists | **No handoff confirmation has been recorded.** This is NOT proof that no coffee was handed over |
| fulfillment `confirmed` | one handoff record exists | **Staff recorded a handoff confirmation.** This is NOT proof that a coffee changed hands |
| physical handoff | the counter | **not observable by software**; exactly-once physical delivery cannot be guaranteed by database idempotency |

## 2. Redemption instruction (`EVC_Prepared_Redemption_Outcome::instruction()`)

| Outcome | recording | fulfillment | instruction |
|---|---|---|---|
| fresh commit (`redeemed`) | recorded_now | unconfirmed | **prepare**: the ONLY outcome that instructs preparation, through its own fresh result |
| replay, today, no handoff recorded | previously_recorded | unconfirmed | **verify_handoff**: check with the customer and the bar before anything is prepared |
| replay, today, handoff recorded | previously_recorded | confirmed | **do_not_prepare** |
| replay of an EARLIER Athens business date | previously_recorded | either | **do_not_prepare_for_today**: today's coffee needs a NEW intent, decided on its own |
| every other outcome, including `benefit_already_redeemed`, `outcome_unknown`, `server_error` and `record_integrity_failure` | — | — | **do_not_prepare** |

- `entitlement_consumed()` is true for a fresh or replayed commit, false for a
  definitive refusal, and **null (unknown)** for an unresolved outcome or a
  damaged record.
- A replay reads the clock once, only to compare the stored business date with
  today's Athens date. It never re-decides eligibility, catalogue or
  membership.
- A replay also verifies every handoff record linked to the redemption. A
  damaged one gives `record_integrity_failure`.

## 3. Handoff confirmation (`EVC_Handoff_Service::confirm()`)

The input is the member id (URL), `EVC_Staff_Actor` (server session), and a
body with **exactly** `handoff_request_id`, `redemption_request_id` and `claim`.
An optional `device_ref` (32 hex) is audit-only. It is never part of the
fingerprint, the uniqueness or any decision.

Decision order:

1. **Permission:** `evc_confirm_handoff`, checked before any read, otherwise
   `forbidden`.
2. **Shape:** time, actor, member, entitlement, fulfillment or free-text keys in
   the body give `invalid_request` before any read.
3. **Exact retry:** an existing handoff with this handoff id. The same
   fingerprint gives `handoff_replayed`, after re-verifying the records (this
   works after midnight and from another tablet). A different fingerprint gives
   `idempotency_key_reused`, and nothing is disclosed.
4. **Redemption lookup:** bound to the member of the URL. An unknown redemption
   and another member's redemption both give `redemption_not_found`, the same
   answer.
5. **Integrity:** the ledger must be v2 with exactly one intact snapshot and at
   most one intact, correctly bound handoff. Otherwise `record_integrity_failure`.
   A v1 row is refused with reason `fingerprint_version`.
6. **Already confirmed:** if the stored confirmation carries THIS handoff id
   (another tablet committed the same request after our lookup), go back to
   step 3. Otherwise `handoff_already_confirmed`, reporting the ORIGINAL
   statement. Never a second record.
7. **Athens date:** the same business date is allowed. An earlier date only
   when the injected `EVC_Late_Handoff_Policy` allows it; the shipped policy
   refuses, giving `late_confirmation_not_authorised`.
8. **One atomic transaction:** one handoff record plus one `handoff` audit event
   (`EVC_Handoff_Write` refuses a disagreeing pair). The ledger, snapshot and
   entitlement are never written.
   - A duplicate key is classified by fresh reads as a replay, a conflict or
     already confirmed.
   - A deadlock retries the whole transaction (at most 3 attempts).
   - A lost COMMIT gives `outcome_unknown`. It is never claimed as confirmed
     and never retried in the call. Recovery uses the SAME handoff id, then the
     read model.

Claims and times:

| Claim | `handoff_at_utc` | `handoff_time` | `confirmation_recorded_at_utc` |
|---|---|---|---|
| `handed_now` | = recorded_at (the staff statement is "now") | `stated_at_recording` | server clock |
| `already_handed_earlier` | **null**: the real time is unknown and never invented | `not_recorded` | server clock: when the STATEMENT was recorded |

The handoff fingerprint (`evc-handoff-v1`) covers member, redemption request id,
claim and staff actor. It excludes time, session and device.

**One shared staff account (D2):** every tablet has the same WordPress user. A
staff id therefore never identifies a device, and safety comes from the
database uniqueness, not from the actor:
- one handoff per redemption;
- one handoff per handoff request id.

## 4. Read model (`EVC_Fulfillment_Read_Model`, no endpoint)

Member-linked history is personal data. Every query needs a capability. Results
never contain internal ids, staff ids, session or device references, or
fingerprints.

| Query | Capability | Returns |
|---|---|---|
| `redemption_status(member, redemption_request_id)` | `evc_view_redemption_status` | redemption request id, business date, `redeemed_at_utc`, `is_current_business_date`, coffee and option labels, fulfillment, handoff view. Another member's redemption gives `not_found`, indistinguishable from an unknown one. A damaged record gives `record_integrity_failure` with no data |
| `unconfirmed_today()` | `evc_view_redemption_status` | today's unconfirmed redemptions: request id, time, coffee code. No member id |
| `unconfirmed_for_review(date)` | `evc_review_fulfillment` (managers) | the same plus the opaque member id, a count of integrity failures and a count of v1 rows without a preparation |

A status is **information only**. It never authorises a redemption or a
confirmation; both re-check everything.

## 5. Tests (in-memory store)

`HandoffFulfillmentTest` has 62 cases, plus the updated
`PreparedRedemptionTransactionTest`. They cover:

- a fresh result as the only `prepare`;
- replays in every state, including a duplicate submit from a second till;
- atomicity at every failure point, and an untouched entitlement;
- deadlocks;
- exact retries after midnight, re-login or another tablet;
- never confirmed twice;
- payload conflicts;
- a lost COMMIT, committed and not committed, with device-loss recovery;
- the Athens midnight boundary and the injected late policy;
- shared-account races in three schedules, for the same id and for different ids;
- stale status;
- nine kinds of corruption, failing closed in the replay, the confirmation and
  the read model;
- v1 rows;
- permissions;
- wrong-member indistinguishability;
- body authority keys;
- data minimisation.

The mutation guards are H1–H27 in `tests/mutation/run-membership-mutations.php`.

## 6. SQL requirements for later (NOT implemented; needs a separately authorised migration, not 003)

- **The table:** `evc_redemption_handoffs`, with:
  - `id` as the primary key;
  - `redemption_id` UNIQUE, a foreign key to `evc_redemptions(id)`,
    `ON DELETE RESTRICT`;
  - `handoff_request_id` CHAR(36) UNIQUE;
  - `member_id` as a foreign key;
  - `redemption_request_id`;
  - `claim` as an ENUM or CHECK of the two values;
  - `handoff_fingerprint` CHAR(64), plus `fingerprint_version`;
  - `staff_wp_user_id`;
  - `recorded_at_utc` DATETIME(6) NOT NULL and `handoff_at_utc` DATETIME(6) NULL.
- **Its CHECK:** `(claim = 'handed_now' AND handoff_at_utc = recorded_at_utc)
  OR (claim = 'already_handed_earlier' AND handoff_at_utc IS NULL)`.
- **No free text:** there is no free-text column. The device reference lives
  only in `evc_audit_events.details_json`.
- **The transaction:** `INSERT handoff`, then `INSERT audit`, then `COMMIT`,
  under READ COMMITTED.
  - 1062: roll back, then fresh reads.
  - 1213 / 1205: retry the whole transaction.
  - A COMMIT failure becomes `outcome_unknown`.
- **Grants:** the runtime user gets INSERT and SELECT only (immutability).
- **Reads:** the replay and status reads LEFT JOIN handoffs and must return
  every row, so `handoff_conflict` stays detectable.
- **Index:** one on the ledger business date, for the unconfirmed lists.

## 7. Open owner decisions (nothing here activates them)

- Whether handoff confirmation is a mandatory staff step.
- The grace window for late confirmation. None is approved; the shipped policy
  refuses.
- The manager reconciliation process for unconfirmed redemptions.
- Override permissions.
- Device identification. `device_ref` is audit-only.
- Retention of member-linked history (D9).
- The customer dispute policy.

## UI-04B: strict UTC timestamps

`EVC_Utc_Timestamp::is_canonical()` accepts only canonical DATETIME(6) UTC text
`YYYY-MM-DD HH:MM:SS.ffffff`. It checks real calendar dates (leap years
included), hours ≤ 23, minutes and seconds ≤ 59, and requires exactly six
fraction digits. It rejects any `T`, `Z`, offset or surrounding whitespace,
and years before 1970.

Applied on read:
- A v2 ledger row's `redeemed_at_utc` must be canonical AND fall on its stored
  Europe/Athens `business_date`; otherwise `ledger_time_invalid`.
- A handoff's `recorded_at_utc` must be canonical; otherwise `handoff_corrupt`.
- A handoff must not be recorded before the redemption it confirms; otherwise
  `handoff_conflict`.
- `already_handed_earlier` still stores no handoff time.
- v1 rows keep their own `fingerprint_version` refusal.

Applied on write:
- `EVC_Prepared_Redemption_Write` requires canonical redemption and
  membership-expiry times, the Athens business date of the redemption time,
  and expiry strictly after redemption.
- `EVC_Handoff_Write` requires a canonical confirmation time.
