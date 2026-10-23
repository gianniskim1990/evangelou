# STAGE-01 — real customer app, read-only (flag OFF by default)

The approved customer screens, served by the Evangelou Club WordPress plugin
and fed by WordPress itself. Nothing is activated by this change: both new
pieces exist only when `wp-config.php` contains

```php
define('EVC_CLUB_CUSTOMER_ENABLED', true); // exact boolean true; anything else = off
```

`EVC_CLUB_STAFF_ENABLED` is independent and stays off. Redemption stays 503.

## What exists

| Piece | Where | Behaviour |
|---|---|---|
| Member page | `/club-member/` (pretty permalinks) or `/?evc_club_member=1` (plain permalinks, the current staging) | Signed out → native `wp-login.php` (e-mail or username + password). Staff, administrators, editors, shop managers → 403. Customer → the app, with the REST base and a `wp_rest` nonce in an inert JSON element. Strict self-only CSP, no-store, noindex. |
| `GET /me` | `evangelou-club-customer/v1/me` | Cookie session + `X-WP-Nonce`, customer accounts only, the user is always `get_current_user_id()` (the route reads nothing from the request). Answers the customer's own name, e-mail and membership state. |
| Customer app | `app/src/customer/`, built by `vite.customer.config.ts`, packaged in `customer-app/` | The approved card, dashboard, today, history and profile screens. |

No Club password store and no alternative login: WordPress authenticates.

## Membership states (contract `customer-me-v1`)

`active` only when the existing PMPro entitlement rules confirm a verified
payment, a known expiry and a current paid period (the mapper's recorded
provenance, D1/D2/D5 time rules, refunds and conflicts all fail closed).
Then `expired`, `cancelled`, `pending_payment`, `none`, and `unverified`
for anything that cannot be confirmed. `paid_until_utc` only for active and
expired. A source that cannot be read answers `503 {"result":"unavailable"}`,
and the app says the membership cannot be checked right now.

## What is deliberately NOT connected

- **Membership source:** production uses `EVC_Unavailable_Membership_Adapter`,
  so `GET /me` answers 503 "unavailable" for everyone until a concrete PMPro /
  WooCommerce reader is written and verified against the exact versions on
  staging (see the open items below). Nothing is faked.
- **Club database:** today's coffee, history and visits are shown as "not yet
  available", never as known. The card keeps the approved non-redeemable sample
  QR stamped "ΔΕΙΓΜΑ / ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ".
- **Payments, renewal, newsletter consent:** not read and not offered here.

## Needed from the technician before a real reader

1. Exact WordPress, WooCommerce, Paid Memberships Pro and PMPro WooCommerce add-on versions.
2. The Club membership level id(s) and the product to level mapping (product 24435).
3. How a confirmed payment is recorded per paid period (order completed, manual confirmation for cash or bank transfer), so the reader can bind each payment to the period it funds.
4. The site timezone set to `Europe/Athens` (a fixed `UTC+3` makes the mapper answer "unverified" by design).
5. Staging isolation confirmed (separate database, sandbox gateways, no outgoing e-mail or webhooks).
6. Pretty permalinks, if the staff page `/club-admin/` is to be used on staging (its app requires them).

## Tests

- PHP unit: `tests/unit/CustomerMembershipTest.php` (real mapper over the PMPro fixtures).
- Real WordPress: `tests/wordpress/customer/` (customer flag on, staff flag off), `tests/wordpress/common/CustomerFeatureOffTest.php`.
- Frontend: `tests/club/customerLiveApi.test.ts`, `customerLiveApp.test.ts`, `customerLiveIsolation.test.ts`.
- Mutation guards: CM1–CM3 (PHP unit), W36–W43 (real WordPress), S1–S7 (frontend).
