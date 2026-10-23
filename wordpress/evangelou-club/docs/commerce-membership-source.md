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
