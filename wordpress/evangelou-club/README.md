# Evangelou Club — WordPress plugin (DEVELOPMENT ONLY)

Isolated backend for the approved Phase 1 Evangelou Club. **Do NOT deploy or
activate on the real WordPress site.** Since Task 1C-C the plugin registers
role-scoped staff hooks (they only affect users holding `evc_club_staff`),
but **no REST route exists unless `EVC_CLUB_STAFF_ENABLED` is the boolean
`true`**, and even then redemption fails closed (no production membership
adapter). No database connection or migration is opened automatically. The
React ordering demo and the mock `/club` flow are unchanged.

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

## Staff authentication (Task 1C-C — owner-approved D1 Option A, D2 one shared account)

- **Hosting (D1):** the future staff app is served by this plugin on the
  WordPress origin at `/club-admin/` (not built yet). Same origin only: no
  cross-origin credentials, no bearer tokens, no secrets in any bundle.
- **One shared, restricted account (D2):** role `evc_club_staff` with exactly
  `evc_lookup_member`, `evc_redeem_benefit`, `evc_view_redemption_history`.
  No `read`, no admin/WooCommerce/PMPro/FluentCRM capabilities (tested). The
  role is created on plugin activation (idempotent, extra caps stripped) and
  removed on deactivation; users and history are never deleted. Administrators
  get `evc_manage_club` only. **No user is created by the plugin.**
- **Login:** core `wp-login.php`, core auth cookies and session tokens; no
  second password store. Staff-role logins are redirected to `/club-admin/`,
  the admin bar is hidden and `/wp-admin` (except `admin-ajax.php`)
  redirects away.
- **Sessions:** 12 h absolute (cookie + session token issued for 12 h,
  "remember me" ignored, re-checked on every protected request) and 30 min
  server inactivity tracked **per session** (one usermeta row per session,
  keyed by an HMAC of the token), so simultaneous tablets never refresh each
  other. Missing or malformed session data fails closed and destroys the
  session. The 5-minute tablet screen lock belongs to the later staff-frontend
  task (requirement recorded).
- **Revocation:** core logout ends the current session; administrators can
  revoke every staff session (`EVC_Staff_Session::revoke_all_staff_sessions()`,
  `admin-post.php?action=evc_revoke_staff_sessions` with nonce) and disable
  the shared account (`EVC_Staff_Auth::set_disabled()`). Password rotation
  invalidates existing auth cookies (tested).
- **REST authorization** (`EVC_Staff_Auth::authorize`, used by
  `permission_callback` AND again inside the callback): flag on, cookie user
  (core drops it without a `wp_rest` nonce), own `X-WP-Nonce` verification,
  no Application Passwords, staff role + capability, account not disabled,
  session policy OK. The staff id passed to the engine is always
  `get_current_user_id()`; path ids come only from the URL.
- **Audit:** `staff_wp_user_id` identifies the shared account, never a
  person. An optional `session_ref` (HMAC of the session token, 32 hex) is
  stored in audit `details_json` only: never the token, never in the
  idempotency fingerprint, so a retry after re-login still replays.
- **Login throttling** (shared account only): 5 failures / 15 min per IP ->
  15 min IP lock; 30 failures / 15 min across IPs -> 15 min account lock.
  Uses `REMOTE_ADDR` only (proxy headers untrusted).
- **REST hardening (namespace only):** v1 error envelope with client-safe
  messages (core nonce/permission/param errors mapped), `Cache-Control:
  no-store, private`, `nosniff`, `no-referrer`, `X-Frame-Options: DENY`, a JSON
  CSP; the CORS headers WordPress core reflects are removed for
  `evangelou-club/v1`. `EVC_Rest_Security::SHELL_CSP` is the planned CSP for
  the future HTML shell.

### Feature flag

    define('EVC_CLUB_STAFF_ENABLED', true); // wp-config.php: ONLY this exact boolean enables it

Undefined / null / 0 / 1 / "true" / anything else = disabled: no Club REST
routes, no namespace filters, direct class invocation is refused. **Do not
enable it on staging or production** until every prerequisite below is met.

### Activation prerequisites (all required, none met yet)

1. Technician-provided isolated staging over HTTPS, with backups.
2. WordPress/PHP versions, `pdo_mysql`, rewrite and cache rules confirmed.
3. Login throttling verified on staging together with Really Simple Security
   (edition/settings unknown) and the real client-IP source (CDN/proxy?).
4. Real CORS/cache/WAF behaviour checked with `curl` from a foreign Origin.
5. A reviewed production membership adapter (PMPro) and an approved coffee
   list; until then the production backend is always unavailable (503).
6. The shared account created manually (strong password, owner recovery
   mailbox) and verified to hold only the Club role.

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

Full suite (PHPUnit is a **dev-only** dependency; production needs no Composer).
Tooling is pinned by the committed `composer.lock` (resolved for PHP 7.4.33 via
`config.platform`, used unchanged on 7.4 / 8.2 / 8.4):

    cd wordpress/evangelou-club
    composer install
    export EVC_TEST_DB_HOST=127.0.0.1 EVC_TEST_DB_PORT=3306 EVC_TEST_DB_USER=root EVC_TEST_DB_PASSWORD=...
    export EVC_REQUIRE_DB=1          # fail instead of skipping when no DB is configured
    vendor/bin/phpunit --testsuite unit
    vendor/bin/phpunit --testsuite integration
    php tests/mutation/run-mutations.php   # Linux/LF checkouts; restores sources afterwards

The test harness only accepts a loopback DB host, creates a fresh
`evc_test_<random>` database per test and drops it afterwards. Without
`EVC_TEST_DB_*` the integration tests are skipped with an explicit message
(or fail when `EVC_REQUIRE_DB=1`, as in CI).

Real WordPress integration suite (WordPress core 7.1 + wp-phpunit, disposable
database; the installer DROPS all tables in `evc_wp_tests`):

    export EVC_WP_TEST_DB_NAME=evc_wp_tests
    php tests/bin/create-wp-test-db.php
    EVC_TEST_STAFF_FLAG=disabled vendor/bin/phpunit -c phpunit-wp.xml.dist --testsuite wp-common,wp-disabled
    EVC_TEST_STAFF_FLAG=enabled  vendor/bin/phpunit -c phpunit-wp.xml.dist --testsuite wp-common,wp-enabled
    php tests/mutation/run-wp-mutations.php

CI (`.github/workflows/evangelou-club.yml`): engine job PHP 7.4 / 8.2 / 8.4 ×
MySQL 8.0 / MariaDB 10.11 (lint, smoke, unit, integration incl. multi-process
concurrency 25 × 8, engine mutations on 8.2 + MySQL); WordPress job PHP 7.4 /
8.2 / 8.4 × MySQL 8.0 plus 8.2 × MariaDB 10.11 (both flag modes, auth
mutations on 8.2 + MySQL).

## Security boundaries

- With the flag off (default) nothing is reachable over HTTP. With it on,
  only the redeem route exists and it fails closed (503) in production.
- `staff_wp_user_id` comes from the authenticated WordPress session only.
- Exceptions carry only SQLSTATE + driver codes; results carry no SQL text,
  exception messages or other members' data.
- No names, phones, e-mails, credentials or payment data in the Club DB.

## Known deployment blockers

- Hosting PHP version, MySQL/MariaDB version and pdo_mysql availability.
- Separate Club DB + least-privilege users + backup/restore, provided by technician.
- Staging WordPress with WooCommerce + PMPro configured (1-month expiry,
  completed-payment activation, renewal extension, refund handling).
- PMPro membership adapter (not implemented), plugin-served staff app and
  PHP shell (later task), lookup/history endpoints, final coffee list (D3),
  membership end boundary (D4), QR delivery (D5), retention policy (D7),
  recovery mailbox for the shared account.
- Staging verification of login throttling, CORS/cache/WAF, cookie flags and
  multi-tablet sessions (see Activation prerequisites).

## Future milestones (NOT IMPLEMENTED YET)

1. Staging + separate staging DB with mail suppression and test gateways.
2. Plugin-served `/club-admin/` staff app + protected PHP shell (5-minute
   client lock, nonce bootstrap, no-store); the auth foundation is Task 1C-C.
3. Lookup and history REST endpoints.
4. PMPro membership adapter verified on staging.
5. Member enrollment, QR issuance/rotation/recovery.
6. FluentCRM tag sync with consent kept separate from eligibility.
7. Switch React `/club` from the mock only after auth, staging tests, backups and UAT.

Never send hosting passwords, database keys or personal customer details
through chat, frontend build variables or a GitHub commit.
