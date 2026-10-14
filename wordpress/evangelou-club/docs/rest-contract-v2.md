# Staff REST contract v2 — pure response mapping (UI-04G)

**Status: pure PHP definitions and tests, NOT wired.** The code is in
`includes/class-evc-response-contract.php`.

- There is no route registration, `WP_REST_Response`, hook, SQL, WooCommerce
  or PMPro integration, staff transport adapter or UI change.
- The existing production redemption controller stays on
  `EVC_Unavailable_Redemption_Backend` (503).
- `EVC_CLUB_STAFF_ENABLED` stays OFF.

## Two separate steps

| Step | API | Output |
|---|---|---|
| Payload | `EVC_Redemption_Response::payload($outcome, $sent_request_id, $clock)`, `EVC_Handoff_Response::payload($outcome, $sent_handoff_request_id)`, `EVC_Status_Response::payload($read)`, `EVC_Lookup_Response::payload($result)`, `EVC_Usual_Coffee_Response::payload($result)` | Exact snake_case JSON body |
| Status | `EVC_Contract_Http::status($surface, $body)` | Recommended HTTP status for that body |

**The body is the source of truth.** The status is a recommendation that the
client never needs in order to decide what happened.

**Mappers never invent a success.** In the following cases a mapper returns
`server_error` together with the **original** id as the retry id (fail closed):
- an outcome whose own facts disagree with its kind;
- a record of another request;
- a non-canonical or off-date timestamp;
- a handoff view that does not belong to the sent request.

A client id is echoed only when it is a well-formed UUID v4; otherwise it is
returned as `null`, never as arbitrary input.

## Bodies

The redemption, handoff and status bodies are **exactly** what the strict
parsers in `app/src/staff/redemptionRecovery/wire.ts` accept. That parser was
not weakened.

### A. Prepared redemption

The body has exactly these keys:
- `outcome`
- `request_id`
- `recording`
- `fulfillment`
- `instruction`
- `entitlement_consumed`
- `server_business_date`
- `redemption` (`request_id`, `member_id`, `business_date`, `redeemed_at_utc`)
- `handoff`
- `retry_request_id`, only for `outcome_unknown` and `server_error`

| outcome | instruction | entitlement_consumed | server_business_date |
|---|---|---|---|
| `redeemed` (fresh commit) | `prepare`, the **only** body that may say it | true | The commit's own Athens date. A response built after midnight still matches the record. |
| `replayed`, same day, no handoff | `verify_handoff` | true | The record's date |
| `replayed`, same day, handoff recorded | `do_not_prepare` | true | The record's date |
| `replayed`, earlier Athens day | `do_not_prepare_for_today` | true | The authoritative server clock. It must be later than the record, or the mapper fails closed. |
| the 9 refusals | `do_not_prepare` | false | Server clock |
| `record_integrity_failure` | `do_not_prepare` | null | Server clock |
| `outcome_unknown` / `server_error` | `do_not_prepare` | null | Server clock, plus `retry_request_id` = the **sent** id |

The 9 refusals are `invalid_request`, `invalid_preparation`, `invalid_coffee`,
`catalogue_version_stale`, `catalogue_not_authorised`, `member_not_found`,
`membership_inactive`, `benefit_already_redeemed` and `idempotency_key_reused`.

### B. Handoff confirmation

The body has exactly these keys:
- `outcome`
- `handoff_request_id`
- `fulfillment`
- `handoff`
- `retry_handoff_request_id`, only for `outcome_unknown` and `server_error`

**Recorded outcomes** carry `fulfillment` = `confirmed` and the validated
handoff view:
- `handoff_confirmed` and `handoff_replayed` must carry **our** statement.
- `handoff_already_confirmed` must carry **another** statement.

**The handoff view** has the keys `handoff_request_id`,
`redemption_request_id`, `claim`, `confirmation_recorded_at_utc`,
`handoff_at_utc` and `handoff_time`:
- `handed_now`: `handoff_at_utc` equals the recording time, and `handoff_time`
  is `stated_at_recording`.
- `already_handed_earlier`: `handoff_at_utc` is null and `handoff_time` is
  `not_recorded`. A time is **never** fabricated.

The UI-04B manual verification requirement is unchanged. A replay without a
recorded handoff still says `verify_handoff`.

### C. Redemption status

The body is `{result, data}`.

`data` has exactly these keys:
- `redemption_request_id`
- `business_date`
- `redeemed_at_utc`
- `is_current_business_date`
- `coffee_label`
- `modifiers`, a JSON object even when empty
- `quick_notes`
- `fulfillment`
- `handoff`

The mapper fails closed when:
- a staff field is present;
- `redeemed_at_utc` is off its Athens date;
- `fulfillment` contradicts `handoff`.

### D. Member lookup

The body is `{result, member}`. On success `member` holds **only**
`member_public_id`, `lookup_ref` and `lookup_ref_expires_at_utc`.

Any other field, such as a phone, name or e-mail, turns the body into
`unavailable`, and so does a non-canonical expiry.

`lookup_ref` matches `lkp1.<kid>.<payload>.<sig43>` and is at most 512
characters. It is opaque to the client, which never decodes it.

### E. Usual coffee

The body is `{result, reason, suggestion, confirmed_order}`. `confirmed_order`
is **always false**: a suggestion is never an order.

## HTTP status recommendations (domain bodies)

| Class | Redemption | Handoff | Status | Lookup | Usual |
|---|---|---|---|---|---|
| Success | 201 redeemed, 200 replayed | 201 confirmed, 200 replayed or already_confirmed | 200 | 200 | 200 suggested or no_suggestion |
| Invalid | 400 invalid_* | 400 | 400 | 400 invalid_phone | 400 |
| Forbidden (domain) | — | 403 | 403 | 403 | 403 |
| Not found | 404 member_not_found | 404 redemption_not_found | 404 | 404 | 404 |
| Conflict | 409 membership_inactive, benefit_already_redeemed, idempotency_key_reused, catalogue_version_stale | 409 late_confirmation_not_authorised, idempotency_key_reused | — | 409 ambiguous | — |
| Rate limited | — | — | — | 429 | — |
| Integrity failure | 500 | 500 | 500 | — | — |
| Temporarily unavailable | 503 catalogue_not_authorised, server_error | 503 server_error | 503 server_error | 503 unavailable | 503 server_error |
| **Unknown outcome** | **503** outcome_unknown, with the same-id retry | **503** outcome_unknown | — | — | — |

- An uncertain commit is **never** a definite 4xx failure.
- An unknown outcome or surface gets 500, never a success.

## Envelope boundary

There are two kinds of failure response:
- **Transport and auth failures before any service ran** (no session,
  missing capability, bad nonce, revoked session, request rate limit, staff
  flag OFF, route unavailable) keep the existing v1 envelope:
  `EVC_Rest_Security::error_response()` gives `{"error":{"code","message"}}`
  with a 401, 403, 429 or 503 status.
- **v2 domain bodies** are recognised by
  `EVC_Contract_Envelope_Rule::is_domain_body()`. It checks for one of 7 exact
  key shapes and rejects any `error` key. The future wiring must make
  `filter_post_dispatch` leave these bodies untouched; today it rewrites every
  body of 400 or above that has no `error` key.

The TypeScript side (`classifyHttpReply`):
- A body with `outcome` or `result` and no `error` key goes to the strict
  parser **whatever the status**. A 503 `outcome_unknown` keeps its same-id
  retry.
- A v1 envelope or a non-JSON reply becomes a transport failure:
  `unauthorized`, `forbidden`, `rate_limited` or `unavailable`.
- An unknown error code never borrows the auth meaning of its status.
- A transport failure never says whether anything was committed. The machine
  recovers by status or by a same-id retry.

## Future auth boundary (NOT implemented)

Every v2 staff route must check, in this order, before any service runs:
1. An **active staff session**: the D1 shared staff account with a
   server-side session row.
2. **Capability** (`EVC_Staff_Role`): `evc_redeem_benefit` for redemption,
   handoff and status; `evc_lookup_member` for lookup; `evc_view_usual_coffee`
   for the usual coffee. `evc_manage_club` is never implied.
3. The **WP REST nonce** (`X-WP-Nonce`, `wp_rest`) bound to the session.
4. A **session revocation check** on every request. A revoked or idle-expired
   session gives 401 and never reaches a service.
5. **Rate limiting** per session and per device. The lookup limiter fails
   closed: if it is unavailable the result is `unavailable`, never "allowed".
6. **Lookup proof verification**:
   - Usual coffee and any member-scoped read require a valid, unexpired,
     same-staff, same-session `lookup_ref` for that member.
   - The redemption request carries the member id that the proof was issued
     for.

Failures in steps 1–5 use the v1 envelope. A missing or invalid lookup proof
is a domain `forbidden`.

## Privacy

- Bodies carry ids, dates, canonical times and fixed codes only. They never
  contain free text, phones, names, e-mails, tokens, passwords, payment ids or
  staff ids.
- Refusals carry no member data.
- Display labels in status and usual coffee come from the catalogue snapshot,
  not from input.
- Mapper failures are not logged by the mappers. Service reporters log only
  the stage and the exception class.

## Cross-language vectors

- `tests/support/class-evc-contract-vector-builder.php` drives the **real**
  services through fakes, with fixed clocks and sequential UUIDs.
- It generates `tests/fixtures/contract/vectors.json`: 41 positive vectors and
  18 negative vectors, which are mutated real bodies. Regenerate it with
  `php tests/bin/write-contract-vectors.php`.
- `tests/unit/ContractVectorsTest.php` fails on drift, checks every status,
  and checks the domain shapes and fail-closed mapping.
- `app/tests/staff/contractVectors.test.ts` feeds every vector into the real
  TS parsers:
  - positives must parse to the expected kind;
  - negatives must be rejected;
  - every positive is also rejected when replayed against another request or
    member.
- The builder is test-only. The packager rejects
  `class EVC_Contract_Vector_Builder`.
