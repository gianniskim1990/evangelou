# Evangelou Club API

A standalone WordPress plugin implementing the server side of the
`evangelou-club/v1` REST API documented in
[`app/docs/evangelou-club-api.md`](../../app/docs/evangelou-club-api.md)
in this repository. It resolves Club members (FluentCRM first, WooCommerce
billing phone as a fallback), checks Paid Memberships Pro eligibility, and
enforces "one free coffee per member per business day" with a
database-level unique constraint.

**This plugin is not yet connected to the React `/club` app.** The
frontend still runs entirely on `mockClubService`
(`app/src/club/clubService.ts`) and will keep doing so until a separate,
later task deliberately switches it over. Installing and even activating
this plugin has zero effect on the live demo.

## Requirements

- WordPress 6.0+, PHP 7.4+
- **Paid Memberships Pro** — required. Without it, membership eligibility
  can never be determined; the REST endpoints will respond with a generic
  `server_error` rather than fail loudly, and an admin notice explains why.
- **FluentCRM** — preferred for member identity resolution. Optional; the
  plugin falls back to WooCommerce billing data if it's inactive.
- **WooCommerce** — optional, used only as a phone-number fallback source
  (`billing_phone` user meta).

None of these are hard-required for the plugin to activate — see
"Dependency behavior" below.

## What this plugin does NOT do (yet)

- It does not talk to Vercel, the React app, or the public internet at all.
- It does not generate or print QR codes (the token generate/resolve/revoke
  *methods* exist — see `Identifiers` — but nothing calls
  `generate_qr_token_for_user()` from the UI yet; that's a future task).
  A future task also still needs to give staff a way to *print or view*
  the raw token this method returns — it's captured nowhere else, by
  design (see the API doc's "revocable" requirement).
- It does not solve cross-origin browser authentication for Vercel. Every
  endpoint requires a same-site, cookie-authenticated WordPress user with
  the `use_evangelou_club` capability — see "Authentication" below.

## Installation

1. Copy the `evangelou-club-api` folder into `wp-content/plugins/` (or zip
   it — see "Building a zip" below — and upload via Plugins → Add New →
   Upload Plugin).
2. Activate **Evangelou Club API** from Plugins → Installed Plugins.
   Activation creates the `{prefix}evc_redemptions` table and grants the
   `use_evangelou_club` capability to Administrators, plus adds a minimal
   `evangelou_club_staff` role (read + `use_evangelou_club` only — no
   WordPress admin capabilities).
3. Open **Settings → Evangelou Club**.

## Post-installation checklist

Work through this in order on the real Evangelou WordPress install:

1. Activate **Evangelou Club API**.
2. Open **Settings → Evangelou Club**.
3. Check the Diagnostics table: confirm **Paid Memberships Pro** shows
   "Εντοπίστηκε" (Detected).
4. Confirm **FluentCRM** shows Detected (if Evangelou uses it — expected).
5. Confirm **WooCommerce** shows Detected (if applicable).
6. In the "Επίπεδο συνδρομής Evangelou Club" section, select the actual
   PMPro membership level(s) that represent Club membership on the live
   site, from the list PMPro itself reports — nothing is pre-filled or
   guessed.
7. Click **Αποθήκευση** (Save).
8. Re-check Diagnostics: "Επίπεδο συνδρομής Club" should now show
   "Ρυθμισμένο" (Configured), and "Πίνακας καταχωρήσεων" should show
   "Έτοιμος" (Ready).
9. In the "Δοκιμαστικός έλεγχος μέλους" (member lookup test) section,
   enter a known Club customer's phone number and click **Έλεγχος
   μέλους**.
10. Confirm the result resolves to the expected WordPress user (check the
    displayed name and WordPress user ID against what you expect), via
    the identity source you expect (FluentCRM or WooCommerce).
11. Confirm the membership status shows **active** for a current member,
    and **expired**/other for a lapsed one, with a sensible "Ισχύς έως"
    date (or "(χωρίς λήξη)" for an open-ended recurring membership).
12. **Do not connect Vercel yet.** That is a deliberately separate,
    later task.

### What to send back after installation

So the next step (connecting the real frontend, or fixing anything that
doesn't match the live PMPro/FluentCRM data) can be planned correctly,
please send:

- A screenshot of the **Diagnostics** table (Settings → Evangelou Club).
- A screenshot of the **Επίπεδο συνδρομής Club** section showing which
  level(s) you selected and what PMPro calls them.
- The result of the **member lookup test** for at least:
  - one currently active Club member,
  - one expired/former Club member,
  - one phone number that should NOT match anyone.
- Confirmation of which identity source(s) actually matched during
  testing — FluentCRM, WooCommerce, or "not found" — since this depends
  on how Evangelou's real customer data is actually structured (FluentCRM
  phone field populated? WooCommerce accounts used?).
- Whether the site's PHP error log shows anything unexpected after these
  tests (should be silent unless `WP_DEBUG`/`EVC_DEBUG_LOG` is on).

## Dependency behavior

The plugin never hard-fails WordPress if PMPro, FluentCRM, or WooCommerce
is temporarily inactive:

- Missing **PMPro** (required): an error-level admin notice appears
  site-wide for administrators; REST lookups/redemptions return a generic
  `server_error` rather than crash.
- Missing **FluentCRM** or **WooCommerce** (optional): no error notice;
  member resolution simply skips that source. If neither is active, phone
  lookups will not find anyone (there's no third fallback).
- Missing **Club level configuration**: a warning-level admin notice
  appears; lookups still work but every member resolves as `inactive`
  (no configured level, nothing can match it) until levels are selected.

## Authentication (this phase)

Every REST endpoint requires **an authenticated WordPress session** (the
standard cookie auth WordPress already provides) **and** the
`use_evangelou_club` capability — enforced as the very first step inside
each handler (`Rest_Controller::check_auth()`), so every failure mode,
including "not logged in," returns this plugin's documented JSON error
envelope rather than WordPress's generic REST error shape.

This deliberately does **not** solve cross-origin authentication from the
Vercel-hosted React app — that's a separate, later phase. What's already
guaranteed:

- No static Application Password, API key, or other long-lived secret is
  embedded anywhere in this plugin or shipped to a browser.
- No `Access-Control-Allow-Origin: *` (or any CORS header at all) is set
  by this plugin.
- Cookie-based REST auth still requires WordPress's own `X-WP-Nonce`
  anti-CSRF check — this plugin does not disable or bypass it.

### Manually testing the endpoints right now

Two supported ways, both from *your own* authenticated access — neither
requires disabling any WordPress protection:

**A. Application Password + curl/Postman (recommended for quick testing)**

1. In wp-admin, go to your user profile → **Application Passwords**,
   generate one, and copy it immediately (it's shown once).
2. Use it as HTTP Basic Auth in a local, one-off request — **never paste
   it into any file that gets committed**:

   ```bash
   curl -u "yourusername:xxxx xxxx xxxx xxxx xxxx xxxx" \
     -H "Content-Type: application/json" \
     -d '{"method":"phone","phone":"6900000001"}' \
     https://your-site.example/wp-json/evangelou-club/v1/members/lookup
   ```
3. Revoke the Application Password from your profile when done testing.

Application Passwords use HTTP Basic Auth, not the cookie session, so
they are exempt from the `X-WP-Nonce` check by design — this is
WordPress's own supported mechanism for exactly this kind of testing, not
a bypass.

**B. Browser console, while logged into wp-admin**

If a nonce is available on the page (e.g. `wpApiSettings.nonce` on
screens that enqueue `wp-api`), or after fetching one yourself via a
one-off `wp_create_nonce('wp_rest')` snippet:

```js
fetch('/wp-json/evangelou-club/v1/members/lookup', {
  method: 'POST',
  credentials: 'same-origin',
  headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<paste nonce>' },
  body: JSON.stringify({ method: 'phone', phone: '6900000001' }),
}).then(r => r.json()).then(console.log);
```

## Architecture

```text
evangelou-club-api.php          Bootstrap: header, constants, requires, activation hooks
includes/
  class-logger.php              Restrained, privacy-conscious logging (off by default)
  class-timezone.php            All business_date / ISO-8601 math, fixed to Europe/Athens
  class-identifiers.php         Phone normalize/mask, opaque member_id, QR token hash
  class-settings.php            Stores which PMPro level(s) count as Club membership
  class-redemption-repository.php  Owns evc_redemptions table + the race-free insert
  class-membership-adapter.php  PMPro -> our 4-state membership contract, in one place
  class-member-resolver.php     Phone/QR -> WordPress user (FluentCRM first, WC fallback)
  class-errors.php              The one {"error":{code,message,details}} envelope
  class-rest-controller.php     The two documented REST routes
  class-admin.php               Settings → Evangelou Club: config, diagnostics, test tool
  class-plugin.php              Wires everything together, dependency detection
uninstall.php                   Conservative — deletes nothing unless explicitly opted in
```

## Redemptions table

```sql
CREATE TABLE {prefix}evc_redemptions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_ref varchar(64) NOT NULL,      -- the opaque member_id (mem_...), not a WP user ID
  benefit_type varchar(32) NOT NULL,    -- "free_coffee"
  business_date date NOT NULL,          -- Europe/Athens business date
  redeemed_at datetime NOT NULL,
  request_id varchar(64) NOT NULL,      -- client-generated idempotency key
  staff_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY evc_member_benefit_date (member_ref,benefit_type,business_date),
  UNIQUE KEY evc_request_id (request_id)
);
```

`member_ref` deliberately stores the plugin's own opaque `member_id`
rather than the raw `wp_users.ID` — since that ID is permanent per user
(minted once, never rotated), this keeps the plugin's own business data
decoupled from WordPress's internal ID scheme without any downside.

Created via `dbDelta()` on activation (`Redemption_Repository::install()`),
with the schema version tracked in the `evc_db_version` option so a future
migration can run safely.

## Uninstall behavior

Deactivating the plugin does nothing destructive. **Deleting** it via
Plugins → Installed Plugins normally does nothing destructive either —
`uninstall.php` only drops the redemptions table and related user meta if
an administrator has explicitly added this to `wp-config.php` beforehand:

```php
define( 'EVC_UNINSTALL_DELETE_DATA', true );
```

## Static verification performed

Done without a live WordPress install (see the parent task's report for
the full list): `php -l` on every file, a standalone PHP test harness
exercising phone normalization (all 4 documented formats), masking, QR
token shape/hashing, Athens timezone math (including the summer/winter
`+03:00`/`+02:00` DST boundary), the error envelope's exact shape/status
codes, and the redemption repository's duplicate-key detection logic
(simulated `$wpdb` failures) — 38 assertions, all passing. Plus static
greps confirming: every file has an `ABSPATH` guard, no
`Access-Control-Allow-Origin`, no embedded credentials/secrets, every
`$wpdb` query is parameterized, both REST routes gate on
`use_evangelou_club`, and no `wp_user_id`/PMPro level ID/email/QR token
appears in any public REST response array.

## Must be verified after installing on the real WordPress site

This cannot be confirmed without a live install and is the priority list
for the post-installation checklist above:

- **FluentCRM's actual phone field/schema** — `Member_Resolver` queries
  `\FluentCrm\App\Models\Subscriber` directly (FluentCRM's official PHP
  API doesn't expose a phone-search method), which is the one integration
  point most likely to need adjustment for the FluentCRM version actually
  installed.
- **PMPro's exact object shape** — `pmpro_getMembershipLevelsForUser()`'s
  returned objects' `startdate`/`enddate` format, and the
  `pmpro_memberships_users.status` values actually present in Evangelou's
  data, should be spot-checked against the mapping in
  `Membership_Adapter::map_pmpro_status()`.
- **WooCommerce `billing_phone` coverage** — whether Evangelou's real
  customers reliably have this set.
- Whatever the diagnostics screen and member lookup test tool report once
  pointed at real data.
