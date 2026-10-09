# Evangelou Club — WordPress plugin (DEVELOPMENT ONLY)

Isolated backend for the approved Phase 1 Evangelou Club. **Do NOT deploy or
activate on the real WordPress site.** The plugin is inert: it loads classes
but registers no hooks, REST routes, database connections or migrations. The
existing React ordering demo and the mock `/club` flow are unchanged.

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

## Implemented architecture (Task 1B — redemption engine, not wired to WordPress)

```text
EVC_Redemption_Service  (validation → idempotency → member → eligibility → atomic insert)
  ├─ EVC_Clock_Source          injected "now" (EVC_System_Clock in production), read ONCE per request
  ├─ EVC_Clock                 Europe/Athens business date, independent of PHP default timezone
  ├─ EVC_Membership_Adapter    interface; only a TEST mock exists (tests/support). PMPro adapter = later task
  ├─ EVC_Entitlement           fail-closed eligibility: active AND verified payment AND known expiry AND now < expiry
  ├─ EVC_Coffee_Catalog        explicit allowlist; NO production menu yet (fixtures are test-only)
  ├─ EVC_Request_Fingerprint   SHA-256 of member + benefit + coffee + staff (no date, so retries after midnight replay)
  ├─ EVC_Redemption_Store      prepared SQL for members + ledger
  ├─ EVC_Audit_Log             EVC_Db_Audit_Log writes evc_audit_events
  └─ EVC_Club_Db               PDO gateway: native prepares, message-free exceptions, rollback-first transactions
EVC_Migrator                   versioned + checksummed migrations, drift detection, GET_LOCK
```

### Redemption guarantees

- **One per member + benefit + Europe/Athens business date**, enforced by the
  database (`UNIQUE uq_redemptions_one_per_day`), not by SELECT-then-INSERT.
- **Coffee is part of the ledger row**: one INSERT records the redemption and
  the coffee served; a committed redemption without a coffee cannot exist.
- **Audit event is in the same transaction**: if it fails, the redemption is
  rolled back.
- **Idempotency**: `UNIQUE uq_redemptions_request` + stored fingerprint.
  Same key + same payload → original result (`replayed`), even after Athens
  midnight or after the membership lapsed. Same key + different payload
  (coffee, member or staff) → `idempotency_key_reused`, with no details.
- **Duplicate-key recovery**: the transaction is rolled back first; fresh READ
  COMMITTED reads then classify the conflict (concurrent replay / key reuse /
  `benefit_already_redeemed`).
- **Deadlock / lock-wait**: whole transaction retried, max 3 attempts.
- **Unknown COMMIT outcome**: returns `server_error`; retrying the same
  `request_id` replays the committed result or redeems once.
- Membership is evaluated server-side at redemption time against the same
  instant used for the business date. Missing expiry, pending/failed/refunded
  payment, cancelled, expired, unverified → `membership_inactive`.
- This is local to the Club database. It is **not** atomic with PMPro or
  WooCommerce, which use their own storage and transactions.

## Database requirements

- Separate MySQL **8.0** or MariaDB **10.11** database (the versions tested in
  CI). InnoDB, utf8mb4. Uses DATETIME(6), CHECK constraints (enforced on
  MySQL >= 8.0.16 / MariaDB >= 10.2), stored generated columns, foreign keys.
- PHP with **pdo_mysql**. Hosting support for PDO/pdo_mysql is **NOT yet
  confirmed** — a deployment blocker to verify with the technician.
- Credentials: server-side constants in `wp-config.php` only
  (`EVC_CLUB_DB_HOST`, `EVC_CLUB_DB_PORT`, `EVC_CLUB_DB_NAME`,
  `EVC_CLUB_DB_USER`, `EVC_CLUB_DB_PASSWORD`). Never in Git, frontend bundles,
  chat or logs. Recommended: a runtime user with SELECT/INSERT on `evc_*`
  only, and a separate DDL user used only for migrations.
- Schema: `database/001_initial.sql` — `evc_members` (stable public
  `member_public_id`, unique `wp_user_id`), `evc_qr_tokens` (structure only;
  at most one active token per member), `evc_redemptions` (ledger + coffee +
  fingerprint + staff + membership snapshot), `evc_audit_events`,
  `evc_schema_migrations` (version + checksum).

## Migration procedure (isolated environments only)

1. Take and verify a backup of the target Club database.
2. Run `EVC_Migrator::migrate()` with the DDL user. It verifies every applied
   migration's checksum, rejects missing/out-of-order files, holds a named
   lock, applies pending files in order and records version + checksum.
3. DDL auto-commits in MySQL/MariaDB: a failed migration may leave partial
   DDL. It is not recorded as applied — restore the backup, fix, re-run.
4. Never edit an applied migration; add `002_*.sql` instead.

During Task 1B migrations have run **only** in disposable CI containers.

## Running the tests

Dependency-free smoke checks (static only):

    php wordpress/evangelou-club/tests/smoke.php

Full suite (PHPUnit is a **dev-only** dependency; production needs no Composer):

    cd wordpress/evangelou-club
    composer update
    export EVC_TEST_DB_HOST=127.0.0.1 EVC_TEST_DB_PORT=3306 EVC_TEST_DB_USER=root EVC_TEST_DB_PASSWORD=...
    export EVC_REQUIRE_DB=1          # fail instead of skipping when no DB is configured
    vendor/bin/phpunit --testsuite unit
    vendor/bin/phpunit --testsuite integration
    php tests/mutation/run-mutations.php   # Linux/LF checkouts; restores sources afterwards

The test harness only accepts a loopback DB host, creates a fresh
`evc_test_<random>` database per test and drops it afterwards. Without
`EVC_TEST_DB_*` the integration tests are skipped with an explicit message
(or fail when `EVC_REQUIRE_DB=1`, as in CI).

CI (`.github/workflows/evangelou-club.yml`): PHP 7.4 / 8.2 / 8.4 × MySQL 8.0 /
MariaDB 10.11 containers; lint, smoke, unit, integration (including
multi-process concurrency: 25 rounds × 8 workers by default), and mutation
tests on PHP 8.2 + MySQL 8.0.

## Security boundaries

- No REST endpoints, staff login, nonces or rate limiting yet: nothing is
  reachable over HTTP.
- `staff_wp_user_id` must come from the authenticated server session in the
  future REST layer, never from a client body.
- Exceptions carry only SQLSTATE + driver codes; results carry no SQL text,
  exception messages or other members' data.
- No names, phones, e-mails, credentials or payment data in the Club DB.

## Known deployment blockers

- Hosting PHP version, MySQL/MariaDB version and pdo_mysql availability.
- Separate Club DB + least-privilege users + backup/restore, provided by technician.
- Staging WordPress with WooCommerce + PMPro configured (1-month expiry,
  completed-payment activation, renewal extension, refund handling).
- PMPro membership adapter (not implemented), staff auth / REST layer (D1, D2),
  final coffee list (D3), membership end boundary (D4), QR delivery (D5),
  retention policy (D7).

## Future milestones (NOT IMPLEMENTED YET)

1. Staging + separate staging DB with mail suppression and test gateways.
2. Staff authentication, roles/capabilities, nonces, rate limiting (D1/D2).
3. REST v1 wiring incl. `coffee_code` and `idempotency_key_reused` contract changes.
4. PMPro membership adapter verified on staging.
5. Member enrollment, QR issuance/rotation/recovery.
6. FluentCRM tag sync with consent kept separate from eligibility.
7. Switch React `/club` from the mock only after auth, staging tests, backups and UAT.

Never send hosting passwords, database keys or personal customer details
through chat, frontend build variables or a GitHub commit.
