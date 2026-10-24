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

## Order lookup is bounded

The reader now selects only the customer's own Club orders in SQL (HPOS
`wc_orders.customer_id` or legacy `_customer_user`), never every Club order of
the shop; a wrong-customer order would be a conflict, not skipped.
