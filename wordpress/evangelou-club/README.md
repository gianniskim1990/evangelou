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
  ├─ EVC_Membership_Adapter    interface; TEST mock + EVC_Pmpro_Membership_Adapter (Task 1D-B, pure, NOT wired)
  ├─ EVC_Entitlement           fail-closed eligibility: active AND verified payment AND known expiry AND now < expiry
  ├─ EVC_Coffee_Catalog        explicit allowlist; NO production menu yet (fixtures are test-only)
  ├─ EVC_Request_Fingerprint   SHA-256 of member + benefit + coffee + staff (no date, so retries after midnight replay)
  ├─ EVC_Redemption_Store      prepared SQL for members + ledger
  ├─ EVC_Audit_Log             EVC_Db_Audit_Log writes evc_audit_events
  └─ EVC_Club_Db               PDO gateway: native prepares, message-free exceptions, rollback-first transactions
EVC_Migrator                   versioned + checksummed migrations, drift detection, GET_LOCK
```

## Membership entitlement v2 & PMPro normalisation core (Task 1D-B — pure PHP, NOT wired)

Production still has **no** membership backend: `EVC_Unavailable_Redemption_Backend`
supplies no engine (503), no concrete PMPro/WooCommerce reader exists, no
PMPro or WooCommerce code is called, and nothing here can activate a
membership or a coffee.

**Owner-approved rules (2026-10-10), implemented in `EVC_Membership_Calendar`:**

- **D1 exact expiry.** A verified paid period ends at the exact Europe/Athens
  wall-clock time one calendar month after it starts (15 Oct 12:00 → 15 Nov
  12:00). The end is **exclusive**: at that instant eligibility is false. No
  extension to end of day.
- **D5 calendar month with clamp.** One month = one calendar month in the
  Athens calendar, clamped to the last valid day (31 Jan → 28 Feb, or 29 Feb in
  a leap year); never PHP's `+1 month` overflow (31 Jan → 3 Mar), never 30 days.
  Each period is computed from the previous paid **end**, so month-end
  anniversaries drift (31 Jan → 28 Feb → 28 Mar → 28 Apr). This drift is the
  approved rule as specified and is documented/tested; a separate "billing
  anniversary" policy would be a new owner decision.
- **D2 renewals.** A payment confirmed while the current paid period still runs
  (early) adds one month to the current paid end; a payment confirmed at or
  after that end (late) starts a new period at the payment-**confirmation**
  instant (not order creation). A pending or unpaid renewal grants nothing.
- **DST.** Instants are UTC internally. A computed Athens wall-clock time that
  does not exist (spring gap) or occurs twice (autumn) resolves to the first
  instant at which the Athens clock shows that time or later: the transition
  instant, or the first occurrence. This never grants more time than any
  reading of the wall-clock time (PHP's own normalisation would add 30 min to
  a 03:30 gap end).

**Contract and mapper:**

```text
EVC_Pmpro_Reader (interface)        future trusted WordPress reader; only a test fake exists
  └─ EVC_Pmpro_Snapshot             normalised FACTS: contract version, PMPro availability + version,
     ├─ EVC_Pmpro_Membership_Row    site timezone, user exists, raw PMPro rows (site-local date strings),
     └─ EVC_Pmpro_Payment_Fact      payment facts with recorded provenance, reader-detected conflicts
EVC_Pmpro_Entitlement_Mapper        pure decision -> EVC_Entitlement (v2); unknown => never active
EVC_Pmpro_Mapper_Config             approved Club level ids + supported PMPro version range (no defaults)
EVC_Pmpro_Membership_Adapter        EVC_Membership_Adapter: reader + mapper; reader error => exception (engine: server_error)
EVC_Payment_Evidence / EVC_Evidence_Ref   opaque, period-bound evidence; HMAC helper for the future reader
```

`EVC_Entitlement` v2 is additive: optional `started_at_utc`, `payment_evidence`
and internal `diagnostic_flags`, plus status `indeterminate` (never eligible,
public bucket `inactive`). The five-argument constructor, `public_status`, the
ledger snapshot columns and all DB constraints are unchanged.

**Payment-to-period binding (security-critical).** The mapper replays the
CONFIRMED payments in confirmation order with D1/D2/D5 and requires that each
payment's RECORDED provenance (PMPro row + paid period) equals the replayed
period, and that the last period ends exactly where the single active Club row
ends. Therefore an old payment cannot justify a later unpaid period, a pending
renewal neither extends nor invalidates a paid period, repeated notifications
of one payment (same opaque de-duplication key, identical facts) count once,
and inconsistent duplicates, two payments claiming one period, or payments at
the same instant fail closed. Missing provenance is never inferred from user
id, amount or timestamps.

**Fail closed (never active):** unsupported snapshot contract or PMPro
version, PMPro unavailable, non-named or non-Athens site timezone (incl. fixed
offsets), unknown user, reader-reported conflict, non-Club level, more than
one active Club row, unknown PMPro status, missing/zero/"magic"/invalid end,
cancellation with unknown effective time, pending/failed only, malformed,
sandbox/unknown-environment, future-dated or non-de-duplicable payments,
unlinked or mismatching provenance, full refund (`refunded`), partial refund
or reversal in the current paid run (owner decisions open → `indeterminate`),
evaluation instant at/after the paid end (PMPro "active" status and its
~15-minute expiry cron are never trusted), and any unexpected mapper error.
A reader exception propagates as `EVC_Membership_Source_Exception` → the engine
returns `server_error` and grants nothing.

**Task 1D-B.R1 hardening (identity, level, start):**

- *Identity.* Every PMPro row reference must identify exactly one row, and
  every underlying payment reference must always carry the same
  de-duplication key and identical facts. One payment can therefore never be
  replayed as two money movements (e.g. under a second de-duplication key),
  checked before any period is replayed. Identical repeats still count once;
  different payments are never merged because amounts or times match.
- *Level binding.* A payment may only fund a row of its own level
  (`payment.level_id === row.level_id`), and one continuous paid run may not
  mix levels, so money paid for one approved Club level never funds another.
  Several approved levels remain supported when configured explicitly;
  historical rows of another level in a lapsed earlier run are fine.
- *Membership start.* The active row's PMPro start must be a real date
  (missing / zero / "magic" / malformed / impossible → `indeterminate`), must
  be before its end, must have been reached (start is inclusive), and must lie
  inside the current continuous paid run (`run start <= row start`). PMPro
  and the WooCommerce add-on may keep an ORIGINAL start across early renewals
  or restart it at a renewal, so the row start is not required to equal the
  latest funded period; but a row start before the paid run (e.g. spanning an
  unpaid lapse) is a contradiction and fails closed. Starts are resolved with
  the LATEST DST reading (ends with the earliest), so both directions only
  shrink a period. Limitation: there is no tolerance — if the real source
  stamps a row start seconds before the payment-confirmation instant, it will
  be rejected until staging shows the actual behaviour and an explicit,
  reviewed rule is approved.

**What the pure mapper CANNOT verify** (needs the installed plugins and
staging): that a future reader maps PMPro/WooCommerce/gateway states correctly
(confirmed vs pending/processing/on-hold, refunds, chargebacks); that a
trustworthy per-payment period is recorded at confirmation time (PMPro orders
do not carry one; a recorder hook is a future task); that the WooCommerce
add-on follows D2/D5 (its source truncates end times to the minute and uses
PHP `+1 month`, so it would currently be reported as a mismatch); gateway
duplicate-notification behaviour; the real PMPro version range and Club level
ids.

**Open owner decisions (not implemented as policy):** partial refunds, coffees
already served before a refund, who may confirm cash/bank payments, registration
without e-mail, QR delivery, GDPR retention, mid-period cancellation.

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
- **Exclusive least privilege (Task 1C-C.R1):** Club access requires
  `EVC_Staff_Role::is_restricted_staff_account()`: exactly one role
  (`evc_club_staff`), raw user caps exactly `{evc_club_staff: true}` (no direct
  grants or denials), every effective capability on an allowlist (the three
  Club caps), not a super admin, and no dynamically granted privilege
  (`user_has_cap`) on a probe set. Anything else (e.g. staff + administrator,
  staff + direct `manage_options`, a tampered role) gets 403. Defensive
  lifecycle protections (12 h cookies, session marker, login throttling,
  admin guard, emergency revocation, disabling) use the broader
  `has_staff_role()` so a misconfigured staff-tagged account stays protected.
- **Disabling** (`EVC_Staff_Auth::set_disabled`) requires `evc_manage_club` and
  a target that exists and carries the staff role; any other user (admins,
  editors, subscribers, customers, unknown ids) gets `evc_invalid_target` and
  nothing is changed.
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

## Staff app at `/club-admin/` (Task 1C-D — flag-gated, NOT deployed)

**What it is:** a separately built React app (`app/src/staff/`, entry
`app/staff.html`, config `app/vite.staff.config.ts`) served by THIS plugin on
the WordPress origin. It never uses `mockClubService`, demo members, demo QR
tokens or demo storage (enforced by an import-graph test and a bundle scan),
never imports the ordering app, and is never deployed to Vercel. The public
Vercel `/club` demo is unchanged and still runs on the mock.

**Build & package (repeatable, no deployment):**

    cd app
    npm ci
    npm run package:club-plugin     # = vite build (staff config) + assemble + verify

Output `app/dist-plugin/evangelou-club/` contains only runtime files
(`evangelou-club.php`, `includes/`, `database/`, `README.md`) plus
`staff-app/manifest.json`, `staff-app/assets/*` (hashed JS/CSS/logo) and
`staff-app/build-info.json` (source commit). The script fails on any dev file
(tests, vendor, Composer/PHPUnit files), source maps, test-only PHP classes,
demo/mock strings or ordering-app code in the bundle, or a manifest entry whose
file is missing. `staff-app/` is git-ignored in the plugin source tree.

**Routing:** one rewrite rule `^club-admin/?$ → index.php?evc_club_admin=1`,
present only while the flag is on and no page/post owns the `club-admin`
slug (an existing page is never taken over). Rules are flushed only when that
desired state changes (option `evc_club_admin_rewrite_state`), never per
request; a fresh activation with the flag off never flushes.

**Shell authorization (cookie session, no nonce on the navigation):**

| Situation | Response |
|---|---|
| flag off | 404, no shell, no bootstrap |
| not logged in | 302 → `wp-login.php?redirect_to=/club-admin/` |
| wrong role, extra roles, direct caps, disabled account | 403, no bootstrap |
| expired / idle / unmarked session | session destroyed, 302 → `wp-login.php?reauth=1` |
| assets missing, corrupt manifest, stale or unsafe asset path | 503, no bootstrap |
| restricted staff, valid session | 200 shell + bootstrap |

The checks are `EVC_Staff_Auth::check_account()` — the same code the REST
routes use (minus the nonce). Loading the page counts as staff activity.

**Bootstrap:** an inert `<script type="application/json" id="evc-staff-config">`
(JSON with `<`, `>`, `&`, `'`, `"` \u-escaped) carrying `restBase`, the
`wp_rest` nonce, the `reauth=1` login URL, the shell URL, `idleLockSeconds`
(300), the non-secret `sessionRef`, the app version and all-false feature
flags. Only the 200 response contains it; the app removes the element after
reading and keeps the nonce in memory only (never storage, never a URL).
No auth cookie, session token, credential or customer data is exposed.

**Session endpoints (flag-gated, nonce + restricted account + session policy):**
`GET /evangelou-club/v1/session` (status; NEVER extends the 30-minute
inactivity window) and `POST /evangelou-club/v1/session/end` (destroys this
session server-side and clears the auth cookie). There is no unauthenticated
nonce endpoint; WordPress refreshes the nonce via the `X-WP-Nonce` response
header and the app adopts it. The app does not poll.

**5-minute tablet lock (owner-approved):** only pointer/touch/key/wheel input
counts as activity; timers, requests and visibility changes only *check*
elapsed wall-clock time, so a tablet that slept locks on wake. On lock the app
aborts in-flight requests (late responses are discarded by epoch), clears
sensitive state, ends the server session, and records a per-session marker so
a reload of the same session re-locks. Unlocking is only possible through a
real WordPress login at `wp-login.php?reauth=1`, which shows the login form
even if a cookie were still valid. No PIN, no second password store.

**Bounded session requests and honest logout:** every `/session` and
`/session/end` request (headers AND body) is aborted after 10 seconds
(`SESSION_REQUEST_TIMEOUT_MS`); a caller's abort signal is honoured and no
timer or listener outlives the request. The locked screen reports the server
outcome truthfully: "pending", "confirmed" (only a verified `200 {"ended":
true}`) or "unconfirmed" (timeout / network / 5xx — outcome unknown — and also
401/403, since a missing or stale nonce returns them while the session may
still be alive). Unconfirmed keeps the screen locked, says the session will
expire on its own within 30 minutes, and offers an explicit "end session again"
action. The "log in again" button is never disabled. A timeout never unlocks.

**Fail-closed state:** session requests (bootstrap, status re-check, recovery)
may run in loading/ready/offline/error; protected member-data operations and
sensitive data are allowed ONLY in a verified `ready` state. Every transition
out of `ready` (lock, expired, forbidden, offline, error) bumps the epoch,
aborts in-flight requests and clears sensitive data, so a late response is
discarded — also after offline → ready recovery. Offline/error return to ready
only after a successful `GET /session`.

**Authorization-failure gate (Task 1D-C):** a protected (future member) call
that receives a trustworthy 401 or 403 moves the app to "expired" or
"forbidden" with the same invalidation (data cleared, in-flight requests
aborted, late results discarded); only a real WordPress login continues.
"Trustworthy" means our own `StaffApiError` or a `ClubApiError` built from a
real response, with code and HTTP status agreeing — never an arbitrary thrown
object. 400 / 404 / 409 (inactive, already redeemed, idempotency) / 429,
network, timeout and 5xx are returned to the caller and never end the
session; nothing is retried automatically. Session status checks are
latest-request-wins (an older check is aborted and its success or ordinary
failure ignored); a 401/403 from any check still fails closed. No member
endpoint is connected yet.

**Shell headers:** `Cache-Control: no-store, private`, `Pragma: no-cache`,
`nosniff`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`,
`X-Robots-Tag: noindex, nofollow`, and `EVC_Rest_Security::SHELL_CSP`
(self-only scripts/styles/fonts/connect, no `unsafe-inline`/`unsafe-eval`, no
framing). Set only on `/club-admin/` responses. Fonts are not self-hosted yet:
the staff app falls back to system fonts instead of loading Google Fonts.

**Member operations:** QR lookup, phone lookup, coffee redemption and history
are shown as disabled ("Η λειτουργία θα ενεργοποιηθεί μετά τη σύνδεση του
Club."). The redeem route still returns 503 with the production backend.

**Tests:** frontend `npm run test:club` (staff config, transport/nonce,
idle-lock boundaries, bounded session requests (never-settling fetch, stalled
body, caller abort, timer/listener cleanup), controller ready-only/stale-response
and lock/revocation/reauth rules, import-graph
isolation); real WordPress suites (`StaffShellTest`, `RestSessionTest`,
`StaffShellDisabledTest`); and a CI-only real HTTP run (`tests/http/`) that
installs a disposable WordPress, serves it with `php -S`, installs the
**packaged** plugin and checks redirects, real headers, real CORS, cookies,
logout replay and the reauth form with the flag on and off.

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
    php tests/mutation/run-membership-mutations.php   # Task 1D-B rules, unit suite only, no DB

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
concurrency 25 × 8, engine mutations on 8.2 + MySQL, membership-entitlement
mutations on every PHP version with MySQL); WordPress job PHP 7.4 /
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
- Concrete PMPro/WooCommerce reader + per-payment period provenance recorder
  (not implemented; only the pure mapper exists), Club level ids and supported
  PMPro version range, lookup/history endpoints, final coffee list (D3),
  QR delivery (D5), retention policy (D7),
  recovery mailbox for the shared account.
- Staging verification of login throttling, CORS/cache/WAF, cookie flags and
  multi-tablet sessions (see Activation prerequisites).

## Future milestones (NOT IMPLEMENTED YET)

1. Staging + separate staging DB with mail suppression and test gateways.
2. Plugin-served `/club-admin/` staff app + protected PHP shell (5-minute
   client lock, nonce bootstrap, no-store); the auth foundation is Task 1C-C.
3. Lookup and history REST endpoints.
4. Concrete PMPro reader on top of the Task 1D-B mapper, verified against the
   installed plugin versions in disposable CI and then on staging.
5. Member enrollment, QR issuance/rotation/recovery.
6. FluentCRM tag sync with consent kept separate from eligibility.
7. Switch React `/club` from the mock only after auth, staging tests, backups and UAT.

Never send hosting passwords, database keys or personal customer details
through chat, frontend build variables or a GitHub commit.
