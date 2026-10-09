# Evangelou Club — WordPress plugin (foundation / DEVELOPMENT ONLY)

This is the isolated backend foundation for the approved Phase 1 Evangelou Club.
Do NOT deploy or activate on the real WordPress site yet. The existing React
ordering demo and the existing mock /club flow remain unchanged.

## Confirmed ownership and systems

- Website technician installs/configures WooCommerce + Paid Memberships Pro,
  membership product 20 EUR/month, gateways, staging WordPress and Club database.
- No automatic renewal in the initial 3 months. Manual renewal / cash / bank
  deposit grants membership ONLY once payment is confirmed.
- PMPro is the authoritative membership/entitlement source. CRM tags alone
  must never grant coffee. FluentCRM owns newsletter tags and contact marketing
  status. Amazon SES provides outbound email, not contact tagging.
- The technician prefers a SEPARATE database for Club tables. Do not copy
  WooCommerce orders, FluentCRM contacts or PMPro membership data into it.
- Staff authentication and role-based authorization are mandatory before
  enabling any production lookup/redemption REST endpoints.
- The fixed WP UTC+3 timezone is not sufficient for winter; Club uses
  Europe/Athens for the calendar day and stores UTC event timestamps.

## Files delivered in milestone 0

- Main plugin entrypoint: evangelou-club.php (intentionally inert).
- includes/class-evc-clock.php: DST-correct Greek business day.
- includes/class-evc-qr-token.php: high-entropy opaque QR generation/hash.
- database/001_initial.sql: proposed staging-only schema (NOT executed).
  DB-level unique constraints prevent two redemptions per member per day
  and provide retry idempotency.
- tests/smoke.php: stand-alone smoke tests (PHP CLI).

Run tests from repository root:
  php wordpress/evangelou-club/tests/smoke.php

## Future milestones (NOT IMPLEMENTED YET)

1. Build isolated, backed-up staging + separate staging DB, suppress real email,
   disable live gateways, isolate webhooks, sanitize copied customer records.
2. Approve dedicated SQL schema and connection details stored securely on
   hosting, never in the repository or frontend.
3. Connect trusted WordPress staff authentication / roles and CSRF controls.
4. Build PMPro membership eligibility adapter; reject pending/unpaid, cancelled
   and expired memberships. Test manual renewals, partial refunds and expiry.
5. Build member identity linking, QR enrollment/delivery and token rotation.
6. Implement atomic benefit redemption with retry idempotency, selectable coffee,
   immutable coffee history and audit logs. Test simultaneous till usage.
7. Implement FluentCRM tag sync with consent-respecting newsletter enrollment.
8. Implement published v1 REST contract in app/docs/evangelou-club-api.md;
   update the contract for coffee selection without breaking existing clients.
9. Switch React /club from the mock service only after auth, staging tests,
   operational logs, backups and UAT have passed.

Never send hosting passwords, database keys or personal customer details
through chat, frontend build variables or a GitHub commit.
