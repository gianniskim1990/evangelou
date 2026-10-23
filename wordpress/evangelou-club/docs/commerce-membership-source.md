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

## Order lookup is bounded

The reader now selects only the customer's own Club orders in SQL (HPOS
`wc_orders.customer_id` or legacy `_customer_user`), never every Club order of
the shop; a wrong-customer order would be a conflict, not skipped.
