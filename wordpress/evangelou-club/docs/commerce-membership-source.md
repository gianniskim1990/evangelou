# STAGE-02 — real, read-only membership source for GET /me

The customer endpoint `GET evangelou-club-customer/v1/me` (STAGE-01) can now
read the customer's own membership from the real shop:
**WooCommerce 11.2.1 + Paid Memberships Pro 3.8.9 + PMPro WooCommerce Add On
1.11**, one simple €20 product (24435) mapped to PMPro level 1 (one month, no
recurring billing), bank transfer (BACS) only, manual monthly renewal.

It is **off by default** and never reports anyone as paid until the Club's own
payment record exists (see *Dependency* below).

## Enabling (server configuration only)

All four constants in `wp-config.php`, with exactly these types; anything
missing or malformed leaves `/me` on the always-unavailable source (503):

```php
define('EVC_CLUB_MEMBERSHIP_SOURCE', 'pmpro_woocommerce_bacs_v1');
define('EVC_CLUB_PMPRO_LEVEL_ID', 1);
define('EVC_CLUB_WC_PRODUCT_ID', 24435);
define('EVC_CLUB_EVIDENCE_KEY', '...');   // >= 32 random bytes, not in the database, not in Git
```

`EVC_CLUB_CUSTOMER_ENABLED` must also be `true` for `/me` to exist. Nothing in
this task sets any of them anywhere.

## What was verified against the real plugin sources

The three exact versions were read and run (CI job `wordpress-commerce`;
`tests/bin/fetch-commerce-plugins.sh` pins their SHA-256):

- PMPro 3.8.9 defines `PMPRO_VERSION`, keeps memberships in
  `{prefix}pmpro_memberships_users` (`status`, `startdate`, `enddate` as
  site-local DATETIME strings) and records expiry as
  `pmpro_cancelMembershipLevel(level, user, 'expired')`.
- PMPro WooCommerce 1.11 grants the level only on
  `woocommerce_order_status_completed`, cancels it on `refunded`, `failed`,
  `cancelled` (despite its comment, NOT on `on-hold` / `processing`), keeps
  its product → level map in the option `_pmprowoo_product_levels`, and
  computes the end as `date("Y-m-d H:i:00", strtotime("+ 1 Month",
  current_time('timestamp')))` (early renewal: from the current end).
- With PMPro 3.x the add-on calls PMPro's own deprecated shim
  `pmprommpu_addMembershipLevel()`, which forwards to
  `pmpro_changeMembershipLevel()` (same result; a deprecation notice is logged
  when `WP_DEBUG` is on).
- PMPro and the PMPro WooCommerce add-on are no longer distributed through
  wordpress.org (closed by their author in October 2024); their sources come
  from the vendor's GitHub tags `3.8.9` and `1.11`. WooCommerce 11.2.1 comes
  from wordpress.org.

## How the facts are read (`EVC_Wp_Commerce_Pmpro_Reader`)

Read-only (SELECTs and getters only; a test proves no write query runs).
Facts only — `EVC_Pmpro_Entitlement_Mapper` decides, unchanged except one rule.

| WooCommerce Club order status | Fact |
|---|---|
| `pending`, `on-hold` (bank transfer awaiting payment), `processing` | pending (no membership issued yet) |
| `failed`, `cancelled`, `refunded` | failed |
| `completed` | **none**: the add-on issued the PMPro row, but a shop status is not evidence that money arrived |
| `checkout-draft`, `trash` | ignored (not a placed order) |
| anything else | conflict → unverified |

Club orders are found through the order-items table (both HPOS and the legacy
posts storage), so an order is never skipped because of its status.
Confirmed payments come ONLY from `EVC_Club_Payment_Provenance`.

Verified on every read (any difference → conflict → **unverified**, never
paid): the exact three versions; EUR store; product published, simple, sold
individually, €20, no add-on autocomplete; the add-on maps this product to
this level and no other product grants it; the level lasts one month with no
recurring billing; every Club order is the customer's own, BACS, EUR, one Club
item of quantity 1. The site timezone is reported as is; the mapper requires
`Europe/Athens` (the staging site currently reports a fixed UTC+3 offset).

Mapper change (the only one): without an active Club row, a well-formed live
pending Club payment gives `pending_payment` (the add-on creates no row before
completion). Mutation guards AP1–AP3, C1–C12.

## Resulting customer states today

| Shop situation | `/me` state |
|---|---|
| no Club order | none |
| bank transfer awaiting payment (`on-hold` / `pending` / `processing`) | pending_payment |
| order completed (PMPro row active) | **unverified** (no Club payment record) |
| completed then cancelled / refunded | cancelled |
| expired (PMPro) | expired |
| expired + renewal awaiting payment | pending_payment |
| any configuration drift, other gateway, unknown status, non-Athens timezone | unverified |
| database read error | 503 unavailable |

## Dependency (NOT implemented, not invented)

"This money funded exactly this Athens period" cannot be read from WooCommerce
or PMPro. It needs a **trusted write path** at confirmation time:

1. an authorised manager (never the shared staff account) confirms receipt of
   the bank transfer;
2. at that moment the Club records the payment and its period with
   `EVC_Provenance_Recorder` (approved Athens rules D1/D2/D5) in a persistent
   provenance store — the SQL store/migration is **not approved** for any
   environment;
3. the PMPro end date must equal the recorded Athens period end (the add-on's
   own minute-rounded PHP "+1 month" differs; a test shows "active" needs an
   exact match), e.g. through the add-on's `pmprowoo_checkout_level` filter.

Until then production uses `EVC_Unrecorded_Club_Payment_Provenance` and nobody
is "active". A test with a TEST-ONLY provenance record shows the full chain
reaches "active" only with an exact period match.

# STAGE-03 — verified bank-transfer confirmation (code and isolated CI only)

## The trusted boundary: `EVC_Bacs_Payment_Confirmation`

A WooCommerce status is never the proof. The proof is an explicit **manager
statement from the bank statement**: the bank's transfer reference, the amount
(€20.00) and the value date. The boundary checks, in this order, and refuses
(nothing written) on the first failure:

1. the actor holds `evc_confirm_bank_payment`, is NOT the shared Club staff
   account, and is not the order's own customer;
2. the statement: reference of 6–64 letters/digits (spaces, dashes, dots,
   slashes ignored), amount exactly 2000 cents, EUR, real value date between
   the order date and today (Athens);
3. the order: the customer's own account, `completed` (the shop has issued
   the PMPro membership), bank transfer, EUR, total €20.00, exactly one Club
   item of quantity 1;
4. the customer has exactly ONE active PMPro row on the Club level.

It then records ONE movement through the existing `EVC_Provenance_Recorder`
into the existing `EVC_Sql_Provenance_Store` (migration 002, no schema
change): movement id = keyed HMAC of the normalised **bank reference**; the
only order anchor = the WooCommerce order; the PMPro row; the level; €20 EUR;
authority `manual_admin`; evidence `manual_confirmation`; verification instant
= now (whole second). The recorder computes the period with the approved
Athens rules (D1 exact instant, D2 early/late renewal, D5 clamped month).

| Situation | Outcome |
|---|---|
| first confirmation | newly_recorded + the Athens period |
| the same confirmation again, any time later (any spacing / case of the reference) | already_recorded, same period (the recorded instant is reused) |
| the same bank transfer for a second order | conflict, nothing written |
| a second transfer for the same order | conflict (`alias_conflict`) |
| two payments of one customer verified in the same second | indeterminate (`same_instant_payment`), nothing written |
| store unreachable / commit outcome unknown | unavailable / unknown — safe to retry |

Every attempt (including refusals) is written to the Club audit trail
(`evc_audit_events`: actor id, outcome, reason, opaque movement/order refs —
no reference, e-mail, name or raw order number).

**Inaccessible by design**: the plugin grants the capability to NO role and
no shipped code constructs the class (packager-enforced). Owner decision
needed: who holds the capability, and where the action lives (e.g. a button
on the WooCommerce order screen).

## Reading it back: `EVC_Store_Club_Payment_Provenance`

Each recorded movement of the customer on the Club level becomes a confirmed
payment fact exactly as recorded (order anchor ref, movement id, PMPro row,
period, verification instant); a recorded refund / partial refund / reversal
/ void changes its status. Enabled ONLY by
`define('EVC_CLUB_PAYMENT_PROVENANCE', 'club_db_v1')` + the `EVC_CLUB_DB_*`
constants; otherwise nobody is active (STAGE-02 default). The store is opened
lazily per read; any read error → 503 "unavailable".

## Date conflict — OWNER DECISION REQUIRED (approved rules unchanged)

The approved rules (Task 1D-B, unchanged) require the last recorded paid
period to end EXACTLY where the active PMPro row ends, and the PMPro start not
to precede the paid run. With the verified shop flow they can never both hold:

- PMPro WooCommerce 1.11 starts the row when the order is **completed**
  (before the manager's Club confirmation) and sets the end to
  `date("Y-m-d H:i:00", strtotime("+ 1 Month", completion))`: minute-rounded,
  PHP month arithmetic (31 Jan -> 3 Mar), counted from completion;
- the Club period starts at the manager's confirmation, to the exact second,
  and follows D1/D2/D5 (31 Jan -> 28 Feb).

Behaviour now (fail closed, nothing invented): a VERIFIED paid period whose
PMPro dates disagree is `indeterminate` with the internal flag
`verified_paid_period_conflict` (plus `pmpro_period_mismatch` or
`membership_start_before_paid_run`) — distinct from missing evidence
(`no_payment_evidence`). The customer sees `unverified`. No paid time is
shortened, no unpaid PMPro time is granted, PMPro data is never written.
Tests prove `/me` becomes `active` with the exact approved Athens end as soon
as PMPro's dates equal the recorded period.

(An earlier STAGE-03 draft granted the intersection min(Club end, PMPro end).
It was withdrawn before commit: it could silently remove legitimately paid
time and was an unapproved policy.)

Options for the owner (none implemented):

1. **Align PMPro to the Club record at completion (recommended).** The
   manager's Club confirmation completes the order itself, in the same action,
   and the add-on's own extension point `pmprowoo_checkout_level` sets
   `startdate`/`enddate` to the period the recorder computed. PMPro is written
   only by PMPro, prospectively, never retroactively; existing rows untouched.
   Needs: approval to let the Club confirmation complete orders and to use the
   filter; a decision for orders already completed without confirmation.
2. **Club record authoritative for the end, PMPro only for "still held".**
   Requires an approved rule change (what happens if PMPro ends earlier).
3. **Keep the approved exact rule** and manually edit PMPro dates per member
   (operationally error-prone; not recommended).

## STAGE-03B.1: advisory pre-issuance planning (Option 1 groundwork, NOT wired)

The owner approved Option 1 as the direction. Before PMPro can be given the
Club's dates, the Club must know the period BEFORE PMPro creates its row.
`EVC_Provenance_Recorder::plan(EVC_Prospective_Payment)` computes it:

- `EVC_Prospective_Payment` carries the same payment facts as
  `EVC_Verified_Movement` and is validated by the SAME shape rules
  (`EVC_Verified_Movement::assert_payment_fields` / `alias_map`), but has NO
  PMPro row reference (the row does not exist yet; no placeholder is ever
  invented) and NO confirmation state. It cannot be recorded: `record()` and
  `reconcile()` only accept a row-bound `EVC_Verified_Movement`.
- `plan()` runs the SAME rule code as `record()` (shared private methods
  `candidate_refusal()` and `chain_position()`): environment / level / amount
  / exactly one order anchor; already recorded (same facts → its recorded
  period; other facts or another order → conflict); unresolved correction;
  alias owned elsewhere (same order funded twice); same-instant and
  out-of-order payments; D1/D2/D5 via `EVC_Membership_Calendar::next_period`
  (initial / early / late, predecessor). DST and month-end behaviour are the
  existing calendar's, unchanged.
- It only READS: it never calls `commit()`, never adds the new aliases that
  `record()` would add on a repeat, and works with a SELECT-only database
  account (integration test). A store failure → `unavailable`; contradictory
  store data (a recorded movement without its period, a latest period of
  another member or without its movement) → `unavailable / store_inconsistent`.
- The result `EVC_Period_Plan` is ADVISORY and may be stale. It is not a
  payment record, has no eligibility or confirmation API, and `record()` never
  consumes it: `record()` re-reads the store and re-applies every rule, and the
  store re-checks I1-I8 at commit time. A future orchestrator must compare the
  period `record()` returns with `matches_recorded()` and stop on any
  difference.

Nothing calls `plan()` in production code yet (no route, hook, UI or role).

## Early-renewal failure risk — MUST be solved before any orchestrator (STAGE-03B.2/3)

Option 1 completes the order and lets PMPro issue the row BEFORE the Club
records the movement (the movement must be bound to the new row id). For an
EARLY renewal this replaces the member's current, already paid row first.
Exact path in the pinned sources:

1. `WC_Order::update_status('completed')` → `save()` persists the status, THEN
   `status_transition()` fires `woocommerce_order_status_completed`
   (WooCommerce 11.2.1 `includes/class-wc-order.php` 283-286, 433-457).
   Exceptions from hooks are caught and only logged as an order note
   (~501-514), so the caller is not told.
2. PMPro WooCommerce 1.11 `pmprowoo_add_membership_from_order`
   (`pmpro-woocommerce.php` 207, hooked at 325) → with PMPro 3.x the
   deprecated shim `pmprommpu_addMembershipLevel` (PMPro
   `includes/deprecated.php` 460, no return value) → `pmpro_changeMembershipLevel`
   (`includes/functions.php` 1284). The add-on ignores the result (312-318).
3. `pmpro_changeMembershipLevel`, with `pmpro_deactivate_old_levels` true by
   default (1383), FIRST cancels the current row for the same level with status
   `changed` (1400-1403 → `pmpro_cancelMembershipLevel`, 1200: `status` and
   `enddate = current_time('mysql')`, 1234-1237), THEN inserts the new row
   (1421/1441); an insert failure only returns `false` (1460-1463).

Consequence with the approved, unchanged mapper: from step 3 until the Club
records the new movement bound to the NEW row, the member's last recorded
payment is bound to a row that is no longer active →
`pmpro_period_mismatch` → `unverified`. The already paid time "disappears" for
that window. If the Club write fails (database down, commit outcome unknown,
crash, timeout) it stays lost until recovered; if the PMPro insert fails after
the cancel, the member has no active row at all. Fail closed (no unpaid
coffee) but it harms a paying member, and PMPro has already rewritten the old
row's end date (its history is not evidence; the Club record is).

Requirements / proposed mitigations (owner review; none implemented):

- **R1 durable intent before step 1 (STAGE-03B.2):** the plan, the bank
  statement facts, the verification instant and the previous row id are made
  durable (signed order meta or a migration-003 table — owner decision) so a
  retry resumes with the SAME instant and period instead of planning anew.
- **R2 resume / reconcile:** an idempotent resume that, for a durable intent,
  re-reads PMPro, binds the movement to the new row and records it
  (`already_recorded` on repeat); and a read-only report of "intent issued,
  Club movement missing" and "active row ≠ row of the latest Club movement".
- **R3 pre-flight just before step 1:** fresh `plan()` equal to the durable
  plan, Club store ready with write grants, order still awaiting payment,
  existing active row equal to the Club chain; otherwise do not complete.
- **R4 post-issuance verification:** re-read the PMPro row (exactly one active
  row, new id, dates == plan) instead of trusting return values (they are
  discarded upstream), then record immediately.
- **R5 window policy (owner decision):** either accept that an early renewal
  is shown `unverified` for the seconds between steps 3 and the Club commit
  (and for longer only on failure, until R2 runs), or approve a schema change
  that records the payment before issuance and binds the row as a separate
  append-only fact. A mapper "grace" that trusts a `changed` row would be a
  rule change and is NOT proposed silently.

Until R1-R5 are designed, tested and approved, Option 1 is NOT production-safe.

## STAGE-03B.2: durable signed BACS intent + recovery contract (NOT wired)

Owner decision O1: the attempt is made durable in **signed private
WooCommerce order metadata** (no migration 003, no Club DB intent table).
An intent is never payment evidence and never grants anything.

### Schema (`EVC_Bacs_Intent`, meta key `_evc_club_bacs_intent`)

Compact JSON, fields in this exact order, then `sig`:

| field | meaning |
|---|---|
| `v` | schema version (1) |
| `environment` | `live` |
| `order_id`, `customer_id`, `level_id`, `actor_id` | numeric WordPress ids (actor ≠ customer) |
| `movement_id` | HMAC of the bank transfer reference (never the reference itself) |
| `order_anchor` | HMAC of the WooCommerce order |
| `verified_at` | the manager's confirmation instant, UTC, whole second |
| `period_start`, `period_end`, `period_kind`, `predecessor_movement_id` | the plan fixed at that instant (D1/D2/D5; end = one approved calendar month) |
| `run_start` | start of the contiguous paid run (`EVC_Paid_Run`) = the start PMPro must show |
| `previous_row_ref` | HMAC of the active PMPro row being renewed (early renewal), else null |
| `new_row_ref` | HMAC of the NEW PMPro row, only once verified (`issued`, `recorded`) |
| `state`, `revision` | lifecycle hint (+1 per step) |

Lifecycle, forward only: `planned → issued → recorded`;
`planned → issuance_mismatch → recovery_required`;
`planned|issued → recovery_required`. The constructor enforces the
invariants (initial/late periods start at the confirmation instant; an
early renewal continues a running paid row; issued/recorded name a new row).
Never stored: bank reference, IBAN, names, e-mail addresses, keys.

### Signature

`sig = HMAC-SHA256(K, "EVC-BACS-INTENT\0" . v . "\0" . canonical JSON)`,
`K = HMAC-SHA256(EVC_CLUB_EVIDENCE_KEY, "evangelou-club/bacs-intent-signing-key\0v1")`:
its own key and message domain, so it can never equal an `EVC_Evidence_Ref`.
Decoding is strict, in this order: string ≤ 4 KiB, JSON object with exactly
the schema keys + `sig` (64 hex), known version, schema invariants,
signature (constant-time), byte-identical canonical re-encoding, then the
binding to the order it is read from (`order_id`, its customer,
environment). A valid intent copied to another order is `cross_order`.
**Key lifecycle limitation:** one key (the wp-config evidence key), no
rotation; changing it turns every stored intent into `signature_mismatch`
and recovery stops for a manager (never accepts, never deletes).

### Persistence (`EVC_Wc_Bacs_Intent_Store`) — HPOS and legacy alike

WooCommerce CRUD only (`update_meta_data` + `save`), so HPOS writes
`wc_orders_meta` and legacy storage `postmeta` (both verified in CI).
Verified WooCommerce 11.2.1 behaviour it defends against:
- `WC_Data::save_meta_data()` ignores the data store's result → every write
  is read back (`read_meta_data(true)`, bypassing the meta cache) and
  compared byte for byte: `written`, `write_failed` (provably not stored) or
  `outcome_unknown` (could not be proven either way — read again later);
- a failed meta query is returned by WooCommerce as "no metadata" → a
  database error during a read is `unavailable`, never `missing`;
- meta keys are not unique → two rows are `invalid` (never guessed);
- `get_meta(..., false)` keeps the meta list's indices (a real bug found by
  the tests and fixed).
A different intent already stored (e.g. a second bank reference) is a
`conflict`; an unverifiable stored value is never overwritten or cleaned up.
Exposure: the Store API and `/me` never return it; customers cannot read
`/wc/v3/orders`; shop managers/admins can (opaque values only).

### Concurrency (`EVC_Member_Lock`) — what is and is not guaranteed

Order metadata is not compare-and-set. Writes therefore require the
member's MySQL named lock (`GET_LOCK`, name scoped to database + table
prefix + member, bounded wait ≤ 10 s), owned by THIS session
(`IS_USED_LOCK = CONNECTION_ID()`, same connection id as at acquisition),
re-checked before and after each write, plus compare-and-set on the exact
stored bytes. Verified against the real server: a second manager is
excluded; the bounded wait is honoured; a killed process releases its lock
with its connection; a silently reconnected wpdb no longer owns it.
NOT guaranteed: it is advisory (an admin or another plugin writing the meta
is detected — signature, duplicates, CAS — not prevented), per database
server (re-verify behind a proxy/replicas), and not durable (durability is
the meta itself). No schema change is needed for this guarantee.

### Recovery decision (`EVC_Bacs_Recovery_Policy`, read by `EVC_Bacs_Recovery_Inspector`)

Pure decision over FRESH facts: the signed intent, the order (BACS, EUR,
20.00, one Club item, status), all PMPro rows of the customer, the Club
record (movement, period, corrections, owner of the order anchor) or a
fresh plan. Outcomes: `resume` (`complete_order` / `confirm_issuance` /
`record_movement`), `already_complete`, `manager_required`, `conflict`,
`unavailable`, `refused` (actor lacks the capability, is the shared staff
account, or is the customer). The stored state is never trusted alone
(e.g. "recorded" without a Club movement is a conflict); a completed order
without an intent is never paid; a replay of a recorded payment is
`already_complete` and the recorder answers `already_recorded`; a stale
plan, the add-on's own dates, a missing/extra/old row, a correction or an
ended order stop for a manager. **Nothing executes a step in STAGE-03B.2.**

### Early renewal (Task D) — reproduced, NOT solved, owner decision needed

The real-plugin test reproduces the STAGE-03B.1 finding: completing the
renewal order makes PMPro mark the paid row `changed` and insert the new
row; until the Club records the renewal bound to the new row `/me` shows
`unverified` (fail closed — no free coffee, but a paying member is told
"unverified"). Recovery flags this as `paid_time_at_risk`; once recorded,
the old paid time is preserved and exactly one month is added. Options for
the owner (none implemented; mapper and PMPro rows unchanged):

1. **Accept the window**, with STAGE-03B.3 issuing and recording in ONE
   request under the member lock (seconds), recovery prioritising
   `paid_time_at_risk`, and staff told to retry rather than refuse. No rule
   change.
2. **Keep the old row active until after the insert** with PMPro's own
   filters (`pmpro_deactivate_old_levels`, then
   `pmpro_remove_duplicate_membership_entries` moves the older row to
   `changed` only after the new row exists, functions.php 1383/1473). This
   removes the "no active row at all" failure if the insert fails, but NOT
   the `unverified` window (the active row is still not the recorded one).
3. **A new entitlement rule** (e.g. trust a `changed` paid row while a
   signed `issued` intent exists and the old Club period still runs) — a
   business-policy change; not proposed silently, not recommended.
4. **Record before issuance** — impossible with the approved schema (a
   movement must name its PMPro row); would need a schema change.

## STAGE-03B.3a: internal issued → recorded finalization (NOT wired)

`EVC_Bacs_Payment_Finalizer::finalize(actor, order, attestation)` turns an
**issued** signed intent into a recorded Club payment. Nothing constructs it
(packager forbids it); no route, button, hook, cron or role. It never
completes orders and never issues, cancels or edits PMPro memberships.

**Payment evidence = a TRUSTED MANUAL bank-statement attestation**, given
anew by an authorised manager for this finalization (transfer reference as
printed on the statement, amount, currency, value date). It is **not** a
verification against the bank's API. A signed intent or a completed order is
never evidence of money. Binding: the reference is normalised exactly like
the existing rule, its HMAC (`bacs_transfer`) must equal the signed intent's
movement id (constant-time compare); 2000 cents EUR exactly; value date
between the order date and Athens today AND not after the confirmation
instant fixed in the intent. The raw reference is only hashed — never
stored, logged or returned (reason codes only).

Under the member lock (ownership checked before reading and again before
recording) every fact is re-read: signed intent from order meta, actor
(capability, never the shared staff account, never the customer), order
(completed, BACS, EUR 20.00, one Club item), PMPro rows (exactly one active
Club row = the intent's new row, start = paid-run start and end = planned
end, exact Athens resolution), Club record (movement, period, corrections,
order-anchor owner, fresh plan) → `EVC_Bacs_Recovery_Policy`. Only
`resume: record_movement` on an `issued` intent proceeds; an already
recorded payment whose intent is still `issued` only finishes the intent.

**A stale or changed period can never be committed:**
`EVC_Provenance_Recorder::record_expecting(movement, signed plan)` compares
the period computed from the current chain with the signed one BEFORE
`commit()` (`expected_period_changed`, nothing written), and the SQL store
re-checks inside the commit transaction, under the member-head row lock, that
the chain head is still the expected predecessor (I4 compare-and-set) and
recomputes the D1/D2/D5 period from the stored predecessor end and the
confirmation instant — a writer that wins the race in between makes the
commit fail (`concurrent_update`). No schema change was needed. Tests inject
both races against the real SQL store.

Idempotency / recovery (all with the real plugins, HPOS and legacy):
identical retries and other managers get `already_recorded` (one period);
a concurrent manager gets `busy`; a failed commit → `unavailable`, nothing
stored; an unknown commit outcome is resolved by reading the Club record back
(written → `recorded`; not found → `unavailable`; unreadable → stays
unknown, intent untouched); a crash after the Club commit, a failed intent
write or a lost lock during the commit leave the payment recorded and the
intent `issued` — the next attempt answers `already_recorded` and finishes
the intent without a second month. Every attempt is audited with opaque
references only.

**Remaining risk (NOT solved here):** the early-renewal window during PMPro
issuance (see above) still exists: between PMPro replacing the paid row and
this finalization the member is shown "unverified"; if finalization keeps
failing the window stays open (detected, `paid_time_at_risk`). Finalizing an
issued early renewal preserves the whole previous period and appends exactly
one approved calendar month. Not production-safe until the owner decides the
window policy and STAGE-03B.3b (completion + issuance) is reviewed.

## STAGE-03B.3b: internal issuance orchestrator, Option 2 (NOT wired)

Owner decision 2026-10-26: Option 2. `EVC_Bacs_Payment_Issuer::issue(actor,
order, attestation)` does the whole BACS confirmation in ONE request under
ONE member lock. Nothing constructs it or its guard (the packager forbids
`new EVC_Bacs_Payment_Issuer` and `new EVC_Bacs_Issuance_Guard`). There is no
route, button, hook at load, cron, role grant, migration or flag change.

1. **Admission** (shared with the finalizer, `admit()`): the actor holds
   `evc_confirm_bank_payment` and is not the shared staff account; the
   attestation is well-formed (normalised reference → HMAC at once, 2000 cents
   EUR, a real value date); the order exists with a customer account.
2. **One lock.** `EVC_Bacs_Payment_Finalizer::with_member_lock()` is still the
   only code that calls `EVC_Member_Lock::acquire(` (packager rule unchanged).
   The issuer runs inside it and calls the finalization step through
   `finalize_holding()`, which never takes or releases the lock and re-checks
   ownership.
3. **Pre-flight (nothing written yet):** site timezone `Europe/Athens`; the
   pinned commerce environment exactly as the reader verifies it
   (`EVC_Wp_Commerce_Pmpro_Reader::environment_conflicts()`: exact plugin
   versions, product → Club level mapping with no other product granting the
   level, 1 Month level without recurring billing, no autocomplete, …; any
   difference → `commerce_environment_conflict`, re-checked immediately before
   arming); the Club level does not share a single-selection PMPro level group with another
   level (read with plain SELECTs, because PMPro's own group getters may create
   a default group); order awaiting payment (pending / on-hold), BACS, EUR
   20.00, one Club item, not the actor's own; value date within the window;
   fresh `plan()` (prospective payment: movement = HMAC of the reference,
   anchor = HMAC of the order, verified at = now, whole second, no PMPro row);
   paid-run start; PMPro must match the plan: initial/late → no active Club
   row; early → exactly one, and it must be the row the last recorded payment
   is bound to, with start = paid-run start and end = plan start; both
   instants must be **exactly writable** as PMPro local dates
   (`EVC_Pmpro_Local_Time::exact()`; a confirmation inside the first repeated
   autumn hour is refused: `period_not_representable_in_pmpro`). Then the
   signed `planned` intent is created (read back).
4. **Armed completion.** Only while the request still holds the lock of the
   intent's customer, `EVC_Bacs_Issuance_Guard` is armed for the intent's
   ORDER, customer, level and exact local dates, then
   `$order->update_status('completed')` runs, and the guard is disarmed in
   `finally` (and by a `shutdown` callback at priority 0 if a fatal error ends
   the request while armed). While armed: `woocommerce_order_status_completed`
   at priority 1 (before the add-on's priority-10 issuance) records which
   order is being processed (the re-issue path names it itself), because
   `pmprowoo_checkout_level` carries no order; the dates are replaced only
   while that is the armed order, so a second Club order of the same customer
   completed in the same request keeps the add-on's own dates;
   `pmprowoo_checkout_level` at priority 1000 (after the add-on's own
   priority-10 extension) sets `startdate` = `'<run start>'` (single-quoted, as
   the add-on passes it; PMPro strips single quotes) and `enddate` = `<planned
   end>`, only for that customer and level; `pmpro_deactivate_old_levels`
   returns false only for the level change that follows that replacement for
   that customer and level (tracked via `pmpro_before_change_membership_level`),
   once. No callback writes to the database (`$wpdb->insert_id` hazard). If
   the dates were not replaced exactly once (`applied() !== 1`), the fresh
   re-read still runs but nothing is confirmed or recorded
   (`issuance_not_guarded_once`, manager). So the
   paid row stays `active` until PMPro's INSERT; PMPro's own duplicate removal
   then sets it `changed`. Unarmed completions (e.g. a manual "Completed") see
   no callback at all.
5. **Fresh re-read → policy.** Only `resume: confirm_issuance` moves the intent
   planned → issued (row ref from the re-read, compare-and-set);
   `issuance_mismatch` → intent `issuance_mismatch` (manager); anything else
   stops with the policy's reason. Upstream return values and exceptions are
   ignored; only the re-read decides.
6. **Finalization** inside the same lock (record_expecting, issued →
   recorded), unchanged from STAGE-03B.3a.
7. **Resume / re-issue:** with an intent stored, each step follows the policy
   on fresh facts. `membership_not_reissued` (completed order, the paid row
   still the only active one — e.g. the PMPro INSERT failed, or the request
   crashed before the add-on ran) is re-issued on the NEXT request by calling
   the add-on's own `pmprowoo_add_membership_from_order($order_id)` under the
   armed guard (it reads the order and calls the same shim; it does not check
   the order status; it is only called when the re-read shows no new row). At
   most one issuance action per request.

**Effects (tested with the real plugins, HPOS and legacy storage):** initial
purchase → active with the exact Athens end; early renewal → old row
`changed`, new row start = paid-run start, end = previous paid end + one
calendar month, `/me` active; PMPro INSERT failure → old row still ACTIVE,
`/me` still active, intent `planned`, `membership_not_reissued`, nothing
recorded, and the next call re-issues and records; failed duplicate removal →
two active rows → policy conflict, `/me` unverified; crashes between steps
resume via `complete_order` / `confirm_issuance` / `record_movement`; the
guard is disarmed after an exception; DST-crossing and 31-January plans are
exact.

**Mutation note (corrected in the review):** `PRIORITY = 10` for the guard's
`pmprowoo_checkout_level` callback is NOT an equivalent mutant. At 10 it
still runs after the add-on's own extension only because it is registered
later (registration order, not priority), and it would run BEFORE any
third-party callback at 11–999 instead of after it. With only the shipped
callbacks no test can tell the difference; a later third-party date change
would still be caught by the fresh re-read (`issuance_mismatch`, fail-closed),
but the two are not equivalent. Dropping the start's single quotes stays
equivalent (PMPro strips them).

**Late renewal right after the old end (manager usability, fail-closed):** a
renewal confirmed minutes after the previous paid end is a LATE plan, which
requires no active Club row. PMPro only moves the old row to `expired` when
its own expiry cron runs, so until then the old row is still `active` and the
issuer refuses with `unexpected_membership_row` (conflict) before anything is
written. Nothing is lost: the manager retries after PMPro's expiry cron has
run. Not changed (no business-rule change).

**Unexpected errors:** any Throwable inside `issue()` fails closed as
`unavailable` / `unexpected_error`, the lock is released, the guard disarmed,
and the attempt is still audited with opaque references only (never the
exception or its message).

**Not changed:** the reader treats WooCommerce `processing` as pending, the
recovery policy as unknown. BACS orders are never `processing` in this flow
(the issuer only accepts pending / on-hold), so neither rule was changed.
An initial purchase whose PMPro INSERT failed (`membership_row_missing`) is
NOT re-issued automatically (ambiguous: a manager must look).

## STAGE-03D: WooCommerce «Ολοκληρωμένη» by shop staff = Club payment confirmation (OFF by default)

Owner decision 2026-10-26 (STAGE-03C Option A): (1) a Club order set to
«Ολοκληρωμένη» (Completed) in WooCommerce by a shop user with their own account
IS the Club payment confirmation (only Completed; never Processing / On hold /
Pending); (2) cancellation or refund of such an order freezes the Club card
(no free coffee) until the customer pays again.

### Enabling (server configuration only)

```php
define('EVC_CLUB_WC_COMPLETION_ENABLED', true);   // boolean true only; default OFF
```

plus the STAGE-02 commerce constants and the STAGE-03 Club database
(`EVC_CLUB_PAYMENT_PROVENANCE = 'club_db_v1'`, `EVC_CLUB_DB_*`). OFF (or any
of them missing): `EVC_Wc_Completion_Plugin::boot()` returns before anything is
hooked, scheduled, constructed or connected, and PMPro WooCommerce behaves
exactly as shipped (a test proves no STAGE-03D callback exists at load). The
packager allows `new EVC_Wc_Completion_Confirmer / _Recovery / _Markers`,
`new EVC_Wc_Bacs_Intent_Store`, `new EVC_Bacs_Recovery_Inspector` and
`new EVC_Bacs_Issuance_Guard` ONLY in `class-evc-wc-completion-plugin.php`, which
must keep the constant gate; `new EVC_Bacs_Payment_Issuer / _Finalizer /
_Confirmation` stay forbidden everywhere; the member lock may be taken only in
the finalizer and the confirmer.

### Who confirms (review fixes: only a human click in the WooCommerce admin)

`woocommerce_order_status_completed` at priority 5 (before the add-on's own
`pmprowoo_add_membership_from_order` at 10), one callback that catches every
Throwable (WooCommerce 11.2.1 `status_transition()` catches only `Exception`).
A completion is a payment confirmation only when ALL hold:

1. **The channel is a manual WooCommerce-admin status change.** WooCommerce
   passes `{from, to, note, manual}` as the third hook argument; `manual` is
   `true` for the order edit screen, the bulk action and the order-list
   «complete» button (admin-ajax), and `false` for REST and plain programmatic
   `update_status()` (a CI test proves it on the pinned WooCommerce, and a second
   one reads the three admin entry points in its source). The Club also requires
   `is_admin()` and rejects REST (consumer key, application password, the
   WooCommerce mobile app), WP-CLI, WP-Cron, storefront AJAX and any front-end
   call, even when they run as a real shop user with `edit_shop_orders`
   (`EVC_Wc_Completion_Context`; fail closed: anything not positively an admin
   click is not attested).
2. **The actor** is a logged-in user with `edit_shop_orders`, not the order's
   customer, not the shared Club staff account.

| completed by / through | result |
|---|---|
| a shop user, order edit screen / bulk action / list button | confirmation |
| the same user through REST (API key, app password, mobile app) | `not_attested` / `channel_rest` |
| WP-CLI / WP-Cron / storefront AJAX / any other call | `not_attested` / `channel_cli`, `channel_cron`, `channel_ajax`, `channel_other` |
| admin screen but not a manual change (programmatic `update_status`) | `not_attested` / `not_a_manual_status_change` |
| nobody (user 0) | `not_attested` / `no_user` |
| the order's customer (even with the capability) | `not_attested` / `customer` |
| the shared Club staff account (even with the capability) | `not_attested` / `club_staff_account` |
| a user without `edit_shop_orders` | `not_attested` / `no_capability` |

Every attempt, attested or not, is audited with the attempting user id
(`staff_wp_user_id`, null for nobody) and the channel (`details.channel`), and an
unattested or refused one leaves a private Greek note on the order. The
WooCommerce mobile app is therefore **not** a confirmation channel (default;
owner question 1).

The order must be a Club bank-transfer order exactly as the reader accepts it
(BACS, EUR, total 20.00, exactly one Club item of quantity 1, a customer
account). A refusal now says why: `order_not_bank_transfer`, `order_not_eur`,
`order_not_a_single_club_item` (extra item or quantity 2),
`order_total_changed_by_tax_or_discount` (tax / fees / a discount changed the
total), `order_total_not_20_eur`. It must also never have been cancelled /
refunded / failed (the transition's `from` status, a private
`_evc_club_wc_ended` flag written while the feature is on, or any refund,
partial ones included) — otherwise `completed_after_cancellation_or_refund`,
flagged for a manager, nothing recorded.

### What happens (one request, one member lock)

pre-flight shared verbatim with the BACS issuer (`site_problem()`: site time
zone `Europe/Athens`, the pinned commerce environment, the Club level alone in
its level group; `membership_problem()`: PMPro shows exactly what the plan
continues) → fresh plan with confirmation instant = now (whole second) → signed
`planned` intent (movement id = keyed HMAC of the ORDER in its own namespace
`wc_order_completion`; authority `manual_admin`, evidence `wc_order`; order
anchor as before) → armed `EVC_Bacs_Issuance_Guard` + a DIRECT
`pmprowoo_add_membership_from_order($order_id)` with `guard->completing($order_id)`
(the re-issue path of STAGE-03B.3b; no callback is added to the running hook)
→ fresh re-read → recovery policy → `issued` → `record_expecting` → `recorded`.
Early renewals keep the paid row `active` until PMPro's INSERT (Option 2) and
continue at the previous paid end (31 January + 1 month = 28 February). No bank
reference / value-date attestation is used on this path (those classes remain,
unused here). The audit (`wc_completion_confirmation`) records the staff user
(`staff_wp_user_id`), opaque order / movement refs, steps and outcome; never
names, e-mail addresses, notes or exception texts.

### The add-on's own callback is withheld (review fix M1)

The earlier design tried to neutralise the add-on's run after the fact (an
integer-level return from `pmpro_change_level`). It is gone: it could leave a
PMPro row with `enddate = '0000-00-00 00:00:00'` (a membership without end) and
depended on a race. Now, **for every completion of an order containing the Club
product**, `on_completed` (priority 5, first statement) REMOVES the add-on's
priority-10 callback from `woocommerce_order_status_completed`, and
`after_add_on` (priority 11) puts it back for the next order of the same
request; `forget()` on `shutdown` puts it back whatever happened. (WP_Hook
source checked: a callback removed at priority 5 is not run for the current hook
and one added back at 11 is not re-run for it, but is present for the next hook
run.) An ordinary (non-Club) order gets the add-on back at once and behaves as
shipped. Only the claimed, attested completion issues a membership, through this
path (armed, exact dates).

Consequence, deliberately accepted (document, owner question 1): a Club-order
completion that is **refused, not attested (REST, mobile app, cron, wrong user),
a toggle after the paid period ended, or a re-completion** gives the customer no
membership at all, from either path, and leaves no stray row that could block a
later legitimate confirmation. Staff set it right with an on-hold → Completed in
the admin. Earlier text said the add-on "behaves as shipped" for those cases;
that is no longer true while the feature is ON and the environment is pinned.

Gate: the add-on is withheld only when
`EVC_Bacs_Payment_Issuer::environment_problem()` is empty (pinned PMPro /
add-on versions, product mapped to the Club level only). Outside the pinned
environment the Club does not take over (`commerce_environment_conflict`,
`addon: shipped`, flagged for a manager). If that check itself throws, the
add-on stays withheld (fail closed; the recovery re-checks).

Tripwire (priority 11, after the add-on's slot): an **active Club row without
an end date** for the customer → audit `endless_membership_row`, follow-up
`manager:endless_membership_row`, note on the order.

### Idempotency

Completed → processing / on-hold / pending → completed again, a second staff
user, or a double submit of two stale order screens: `already_recorded`, the
add-on suppressed, no second month, nothing recorded twice.

### Claim before the pipeline, deferred completions and the recovery step

For an attested, well-formed, not-ended Club order the Club first **claims** the
completion: the signed marker `_evc_club_wc_completion` ("completed by user X at
T", immutable once stored) and the open follow-up index `_evc_club_wc_followup`
= `open:<n>` are written BEFORE the pipeline runs, so a crash, a lock timeout or
a fatal error at any later point leaves something the recovery finds
(`planned` intents and open markers are never stranded). The same claim is
written from the catch of `on_completed` for an error before the claim. The
index is removed on `recorded` / `already_recorded`; the marker stays. Refused or
unattested completions write no marker (a refused completion must never poison a
later legitimate one; a deliberate narrowing of "attested and Club order").

Deferred (fail closed now, finished later): member lock busy, Club database
down / unreadable, a confirmation inside the repeated autumn hour, a late
renewal before PMPro's own (quarter-hourly) expiry marked the old row `expired`,
the same customer twice in one second (bulk action), lock lost, intent write not
proven, unexpected errors. `EVC_Wc_Completion_Recovery::run()` (WP-Cron
`evc_club_wc_completion_recovery` every 15 minutes, registered only under the
constant) re-reads marker, order, PMPro and Club record under the lock and
confirms. Configuration problems (time zone, environment, level group) and
contradictions stop for a manager (`manager:<reason>`, a note on the order).

Behaviour of the recovery (documented, not changed):

- **The confirmation instant is the recovery's `now`** (the instant is part of
  the paid period). Example: the customer is paid until 10 Nov 12:00; staff
  complete the renewal on 10 Nov 11:59 but the member lock is busy; the recovery
  runs at 12:15 and records the renewal at 12:15, which is after the old end, so
  it is a LATE renewal (new run from 12:15) instead of an early one continuing at
  12:00, and 12:00-12:15 is not paid time.
- **`MAX_ATTEMPTS` (96) counts real attempts only.** Ticks during an outage
  (`member_locked`, `club_unavailable`, `club_record_unavailable`,
  `marker_unreadable`, `membership_unreadable`, `plan_unavailable`, `lock_lost`)
  do not use one (test). After the 96th real attempt: `manager:attempts_exhausted`
  and the order is no longer picked up. Reset path: put the order on hold and
  complete it again in the admin (a fresh `open:0`; test).
- **A staff user demoted or deleted before the recovery ran** is not re-trusted:
  the recovery stops with a refusal, `manager:<reason>`, a note on the order, and
  nothing is issued (test). The marker's user is the one who is checked.
- The unused default-off policy facts `reissue_if_missing` / `replaceable_row_ref`
  of the first version remain in the policy class; this path sets
  `reissue_if_missing` (the add-on never issues for a claimed completion) and no
  longer uses a replaceable row.

### Cancellation / refund (owner decision 2)

`woocommerce_order_status_cancelled` / `_failed` → correction `voided`;
`_refunded` and a FULL refund (`woocommerce_order_refunded` with nothing left)
→ correction `refunded`, through `EVC_Provenance_Recorder::correct()` (authority
`order_state`, correction id = HMAC of order + kind; repeats are
`already_corrected`). The mapper (unchanged) then shows the card as not eligible
(`payment_reversed` → unverified, `refunded`). A correction that cannot be
recorded now is retried by the recovery step; meanwhile the reader reports a
cancelled / refunded / failed order whose recorded payment has no correction as
a conflict (`club_order_ended_after_recorded_payment`) → unverified. Partial
refunds: audited `manager_required` / `partial_refund`, no automatic change.

**Not solved — owner decision needed (no rule invented):** with the approved D2
rule, a new paid order confirmed WHILE a frozen (voided/refunded) period is
still running is an EARLY renewal of that same paid run, and the approved
mapper rule 5 ("no payment in the current run refunded / reversed") keeps the
card frozen for the whole run, although the customer paid again. Only a renewal
after the frozen period ended (late renewal = new run) unfreezes the card.
Making "a new paid order" end the freeze earlier needs a rule change (D2 / mapper
rule 5) that the owner has to approve.

### Reader / policy changes

- Reader: a Club order whose total is not 20.00 is a source conflict
  (`club_order_total_not_20_eur`); valid orders unchanged.
- Recovery policy: `processing` is awaiting payment (as in the reader); the
  BACS issuer still never confirms `processing` (`order_status_unknown`), and
  this path confirms only `completed`.

### Things that cannot be undone or are not reconciled (review items, documented)

- **Un-completing a recorded order** (Completed → processing / on-hold / pending)
  does not reverse anything: the card stays active, the payment stays recorded
  (the shop's own mistake to fix by a cancellation / refund if the money did not
  arrive). Completing it again is `already_recorded`, never a second month.
- **A mis-click to «Ακυρωμένη» / «Επιστροφή χρημάτων»** freezes the card at once
  and a later «Ολοκληρωμένη» is refused (`completed_after_cancellation_or_refund`).
  There is no undo; the customer needs a NEW order (owner question 2).
- **Frozen period and early renewal**: see "Not solved" above (owner question 3).
- **Switching the feature OFF** (constant removed) while recorded payments exist:
  nothing reconciles. Cancellations / refunds made while OFF are not corrected
  (the flag and corrections are not written), and a completion while OFF is
  not a confirmation (the add-on issues its own row as shipped). **OFF must not be used with
  live recorded payments.** The reader still reports an ended order whose
  recorded payment has no correction as `club_order_ended_after_recorded_payment`
  (unverified), which surfaces the problem but does not repair it. Off-switch
  procedure when orders are open: first wait until no order has an open
  follow-up (`_evc_club_wc_followup` = `open:<n>`) or a `manager:` flag
  unresolved; while OFF the recovery does not run, so open claims simply wait
  and resume when the constant is switched ON again (the immutable marker stays).
- **Markers are immutable**: a stored marker is never replaced; a second
  completion by another user keeps the first user (the recovery uses the first
  user's marker). A marker that is present but invalid (tampered) stops with
  `marker_conflict` for a manager and issues nothing.
- **Self-dealing is inherent, and audited**: a shop user can complete the order
  of a friend's bank transfer that never arrived. The Club cannot know about the
  bank; the staff user id and channel are in the audit for every attempt, and the
  customer's own completion is refused.
- **Dormant bank-statement path** (`EVC_Bacs_Payment_Confirmation` etc.): it
  stays unreferenced from this path. An intent written by it for the same order
  is never mixed with this path (`intent_from_bank_confirmation`, manager flag).
  The two paths use different movement namespaces; they are not designed to run
  together.
- `membership_issuance_unavailable` (add-on function missing) is unreachable on
  the pinned environment (the gate fails first); kept as a defensive stop.

### What the shop and the technician must do

- Site time zone **Europe/Athens** (named zone, not "UTC+3").
- Confirm a bank transfer by setting the order to **«Ολοκληρωμένη»** in the
  WooCommerce admin on a computer (order screen, bulk action or the list button)
  with your **own** shop account. Never the shared Club account, never an API
  key, application password, the WooCommerce mobile app, WP-CLI or automation:
  those completions are NOT confirmations and give the customer no membership.
- **Never re-complete a cancelled / refunded Club order**: create a new order.
- WP-Cron must run (real cron hitting `wp-cron.php` recommended).
- Keep the product / add-on settings as verified (no autocomplete, product
  mapped only to the Club level); separate Club database + constants on staging.

### Tests and mutants

`tests/wordpress/commerce/WcCompletionTest.php` (real plugins, HPOS and legacy
storage in CI), `tests/wordpress/commerce-on/WcCompletionOnTest.php` (a separate
PHPUnit process with the constant ON: real `boot()`, cron schedule,
`run_recovery()`, `deactivate()`), `CommerceMembershipSourceTest` (total),
`BacsIssuerTest`, unit `WcCompletionContextTest`, `BacsRecoveryPolicyTest`,
`WcCompletionMarkerTest`, `MutationEvidenceTest`. Mutants: RP19-RP23, MK1-MK3
(membership runner); WC1-WC52 (commerce runner, named killers; WC49-WC51 run in
the constant-ON process). A commerce mutant with named killers runs exactly those
tests (a mutant counts as killed only if each killer fails an assertion). Known
equivalent (not mutated): the `disarm()` in `on_completed`'s `finally` is a second
layer behind the one in `armed()`.

The packager's construction guard is token based (`app/scripts/php-guards.mjs`:
comments, strings, `new \EVC_X`, spacing and case variants) and runs negative
fixtures on every package.

## Order lookup is bounded

The reader now selects only the customer's own Club orders in SQL (HPOS
`wc_orders.customer_id` or legacy `_customer_user`), never every Club order of
the shop; a wrong-customer order would be a conflict, not skipped.
