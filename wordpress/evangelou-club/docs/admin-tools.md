# Club admin pages (STAGE-08)

**Status: built and tested, default OFF.** Nothing exists unless wp-config.php defines
`EVC_CLUB_ADMIN_TOOLS_ENABLED` as the boolean `true`. Then administrators get an «Evangelou Club» menu with two
pages. Single-site WordPress only.

## Owner decision D18

**Decided by the owner (2026-11-06):** one shared staff account on the till tablet; no per-person accounts or PINs.
This matches the existing design: the `evc_club_staff` role with only the three Club capabilities, the shared-account
login throttle, and the 12-hour / 30-minute session policy.

> **Απόφαση ιδιοκτήτη (D18):** Ένας κοινός λογαριασμός για το tablet του ταμείου. Χωρίς προσωπικούς λογαριασμούς
> ή PIN για κάθε υπάλληλο.

Consequence (accepted with D18): a redemption is attributed to the shared account, not to a person.

## «Λογαριασμός ταμείου» (the shared till account)

Who: an administrator with `manage_options` **and** `evc_manage_club`, never a Club staff account. Each action is a
POST with a WordPress nonce **bound to that action and that account** (`evc_club_staff_account|<action>|<user ID>`;
0 for create and sign-out-everywhere), so the nonce of one form cannot be replayed as another action or against
another account. The page checks the nonce first; then the service checks the capability again and acts.

What is audited: every request that reaches the service (each action and each capability refusal, with its outcome).
**A request with a missing or wrong nonce is refused by the page before the service and is NOT audited.**

| Action | What happens |
|---|---|
| Create | Only when no account with the staff role exists. Role `evc_club_staff` only, no e-mail, display name «Evangelou Club — ταμείο», login name chosen by the administrator (default `club-tameio`; 4–60 lower-case letters, digits, `- _ .`). A generated password is shown once. If anything grants the new account more than the staff role, it stays disabled and the page says so. |
| New password | A generated 24-character password (lower and upper case letters and digits), shown once in the answer to that POST (sent with `nocache_headers()` and an explicit `Cache-Control: no-store`) and never stored, logged or cached by the plugin. Every session of the account ends. Refused (`not_restricted`) for an account that holds more than the staff role. If the generator gives no strong password in 10 tries (e.g. a `random_password` filter weakens it), the action fails and nothing changes. |
| Disable / enable | `EVC_Staff_Auth::set_disabled` (disabling also ends every session). Enable is refused (`not_restricted`) for an account that holds more than the staff role; disable is always allowed. |
| Sign out everywhere | `EVC_Staff_Session::revoke_all_staff_sessions`. |
| Status | Disabled or not, open sessions, last sign-in time (recorded on `wp_login` while the flag is on, or the newest open session). No IP address, no personal data. |

- **More than one staff account:** a warning on the page and «wrong» on the readiness page. Nothing is ever deleted
  automatically; the administrator disables the extra accounts and deletes them by hand if wanted.
- **Audit:** the newest 100 entries in the option `evc_club_staff_admin_audit` (autoload off): time, action, outcome,
  the administrator's user ID and the account's user ID. Never a password or a login name. The page shows the last 20.
- **No leak (packager, `adminToolsProblemsFor`):** in the two till-account files (service and page) it refuses every
  call of `error_log`, `print_r`, `var_export`, `var_dump`, `set_transient`, `set_site_transient`, `setcookie`,
  `wp_cache_set`, `add_query_arg`, `wp_redirect` and `wp_safe_redirect`, and every call of `update_option`,
  `add_option`, `update_site_option`, `update_user_meta`, `add_user_meta`, `update_user_option`, `wp_cache_add`,
  `wp_cache_replace`, `header`, `do_action`, `do_action_ref_array`, `apply_filters` and `apply_filters_ref_array` whose
  arguments carry the password (a variable whose name contains `pass`, or a `'password'` string such as
  `$result['password']`). `wp_set_password` is refused in every file but the service. A value copied into a variable
  with another name before such a call is not caught statically; the tests check that no option holds the password.
- **Accepted / informational:**
  - showing the generated password (once, in the answer to the POST) is not audited separately; the `password` action
    that produced it is;
  - an administrator without `evc_manage_club` (or any user who reaches the page's POST with a valid nonce) can fill
    the audit with `forbidden` entries; it keeps the newest 100, so such entries can push older ones out;
  - a nonce stays valid for WordPress's usual nonce lifetime, so the same form can be re-sent for the same action and
    account within it (resubmitting «Νέος κωδικός» gives yet another password and is audited).

## «Ετοιμότητα» (readiness, read-only)

Every Club flag and configuration check, each «Εντάξει» (OK), «Λείπει» (missing), «Λάθος» (wrong), «Έλεγχος με το
χέρι» (manual) or «Ανενεργό» (off, the default). It never changes anything and never shows a secret (no password, key,
host, user, database name, IP address or header value).

| Section | Checks (reusing the existing code) |
|---|---|
| Club database | the `EVC_CLUB_DB_*` constants; a connection (`EVC_Club_Db::connect`); the applied schema version against `EVC_Club_Migrations_Feature::target_version()` (no `evc_schema_migrations` table = «Λείπει», nothing applied; a table that exists but cannot be read, or a check that throws = «Δεν διαβάζεται», never "nothing applied": use the migrations page checks); the server version. A green connection row does not prove the CREATE rights a migration needs (MySQL error 1044): the migrations status page does. |
| Flags | customer, staff, redemption source, onboarding (also needs the customer flag), FluentCRM sync, WooCommerce completion, migrations, admin pages: each with its own feature parser |
| Shop | the commerce constants; `EVC_Wp_Commerce_Pmpro_Reader::environment_conflicts()` (pinned versions, product, price 20.00, sold individually, one-month level); `EVC_Bacs_Payment_Issuer::site_problem()` (Athens clock, level group); `EVC_CLUB_PAYMENT_PROVENANCE` («Εντάξει» only for the exact `'club_db_v1'`, the rule of `EVC_Customer_Qr_Backend::provenance_recorded()` and the redemption / completion backends; the value is never shown); `EVC_CLUB_LOOKUP_KEY` (present and valid by `EVC_Club_Qr_Services::is_lookup_secret()`, at least 32 bytes; never shown, nor its length); BACS enabled (D15); the terms URL (D13) |
| FluentCRM | the tag IDs (verified read-only through `EVC_Fluentcrm_Plugin::problem()` when the sync is on); three manual checks: no contacts on user registration, no «New User Sign Up» automations, the WooCommerce integration off |
| Server | proxy headers in this request (names only; `REMOTE_ADDR` is a manual check); page-cache plugins; the Athens timezone; PHP and MySQL / MariaDB against the tested matrix (PHP 7.4 / 8.2 / 8.4; MySQL 8.0 / 8.4, MariaDB 10.11) |
| Till | exactly one shared staff account, restricted, enabled (read through the read-only `EVC_Staff_Account_Reader`) |

Statuses: «Εντάξει», «Λείπει», «Λάθος», «Έλεγχος με το χέρι», «Ανενεργό (προεπιλογή)» and «Δεν διαβάζεται».

`EVC_Readiness` holds **no till-account service**: the shared account is read through the static, read-only
`EVC_Staff_Account_Reader` (the service uses the same reader). What the packager enforces in the three read-only files
(the readiness checks, the readiness page and the reader):
- no call of a WordPress writer or hook (options, site and user options, user / post / term meta, transients, object
  cache writes, users, passwords, auth cookies, posts, cron, add/remove/do action and filter, rewrite rules,
  `error_log`), no `call_user_func*` / `forward_static_call*`, no `$var::`, and no string equal to one of those names
  or to a writer method (so no callable string or array reaches one);
- no `->` or `::` call of a writer method, whatever the receiver: `query`, `insert`, `update`, `delete`, `replace`,
  `execute`, `exec_raw`, `transactional`, `save`, `create`, `set_password`, `set_disabled`, `revoke_all`,
  `record_login`, `process`, `handle_load`, `delete_all_activity`, `revoke_all_staff_sessions`, `destroy`,
  `destroy_all`, `destroy_others`, `add_cap`, `remove_cap`, `set_role`, `add_role`, `remove_role` (this covers
  `EVC_Staff_Session::delete_all_activity` and `EVC_Staff_Auth::set_disabled`);
- no string carrying an SQL write (`INSERT INTO`, `UPDATE … SET`, `DELETE FROM`, `REPLACE INTO`, `ALTER / DROP /
  CREATE / TRUNCATE / RENAME TABLE|DATABASE|…`, `GRANT`, `INTO OUTFILE`, `LOAD DATA`, `LOCK TABLES`), whichever method
  receives it (so `$wpdb->get_var("DELETE …")` is refused);
- `EVC_Staff_Account_Service` only as `EVC_Staff_Account_Service::may_manage(…)` (the page's access check);
  `EVC_Admin_Tools_Plugin` not at all; `EVC_Club_Qr_Services` only as `EVC_Club_Qr_Services::is_lookup_secret(…)`
  (and only in the readiness checks file).

Not covered statically: a writer name or an SQL statement assembled at run time from pieces. The test
`ReadinessTest::test_the_page_changes_nothing_and_shows_no_secret` watches every query of a full render.

## Operator note (Greek): ο κοινός λογαριασμός του ταμείου

> **Οδηγίες για τον ιδιοκτήτη — ο λογαριασμός του tablet**
>
> Όλα γίνονται από το WordPress: **Evangelou Club → Λογαριασμός ταμείου** (μόνο διαχειριστής).
>
> **Χάθηκε ή κλάπηκε το tablet**
> 1. Πατήστε **«Αποσύνδεση του ταμείου από όλες τις συσκευές»**.
> 2. Πατήστε **«Νέος κωδικός»** και σημειώστε τον κωδικό που εμφανίζεται (φαίνεται μόνο μία φορά).
> 3. Στο νέο tablet συνδεθείτε με τον νέο κωδικό. Το παλιό tablet δεν μπορεί πια να μπει.
>
> **Έφυγε υπάλληλος που ήξερε τον κωδικό**
> 1. Πατήστε **«Νέος κωδικός»** την ίδια μέρα (το tablet αποσυνδέεται αυτόματα).
> 2. Συνδέστε ξανά το tablet με τον νέο κωδικό και δώστε τον μόνο σε όσους δουλεύουν τώρα στο ταμείο.
>
> **Πότε αλλάζω τον κωδικό**
> - όταν φεύγει υπάλληλος ή χάνεται το tablet (αμέσως)·
> - αν υποψιαστείτε ότι τον έμαθε κάποιος άλλος·
> - αλλιώς κάθε 3 μήνες.
>
> **Για λίγο κλείσιμο** (π.χ. διακοπές) πατήστε **«Απενεργοποίηση»** και μετά **«Ενεργοποίηση»** όταν ανοίξετε.
>
> Μην φτιάχνετε δεύτερο λογαριασμό ταμείου ούτε δίνετε στο tablet λογαριασμό διαχειριστή. Αν η σελίδα δείχνει
> «υπάρχουν 2 λογαριασμοί», ενημερώστε τον τεχνικό.

## Tests

- `wp-common` `AdminToolsOffTest`: nothing exists while the flag is off; the classes refuse construction.
- `wp-admin-tools` `StaffAccountAdminTest`: create once (staff role only, no e-mail, strong password shown once, never
  stored), invalid or taken login names, a new password signs the tablet out, disable / enable / sign out everywhere,
  only staff accounts as targets, every service refusal for editors, shop managers, the staff account, visitors and an
  administrator without `evc_manage_club` (audited), a missing, wrong or unbound nonce changes nothing (not audited),
  a nonce bound to its action and account (the page's own form nonce works only for its action and account), the
  `no-store` header on the password answer only, an over-privileged account gets no password and is not re-enabled
  (disable still works; the page offers neither), a `random_password` filter that weakens every try fails closed after
  exactly 10 tries (no account, old password and sessions kept) while one that weakens the first 3 still succeeds, the
  duplicate-account warning, an account given extra capabilities at creation stays disabled, the last sign-in time,
  the audit (no secrets, newest 100).
- `wp-admin-tools` `ReadinessTest`: each status of the Club database (missing, unreachable, empty, behind, at target,
  and an unreadable migrations table = «Δεν διαβάζεται»), the flags, the shop / terms / CRM without configuration,
  `EVC_CLUB_PAYMENT_PROVENANCE` (only the exact `'club_db_v1'` is OK; value never shown), `EVC_CLUB_LOOKUP_KEY`
  (OK only by the lookup backend's own rule; value and length never shown), proxy header NAMES only, page-cache
  plugins, timezone, versions, the till account, the readiness holding no service; no write on WordPress or the Club
  database, no form, no secret, administrators only.
- `wp-commerce-admin-tools` `ReadinessCommerceTest` (real WooCommerce + PMPro): the configured shop reads OK, including
  provenance and the lookup key; a price of 25, a product not sold individually, bank transfer off and a non-Athens
  timezone each read «wrong» with the existing reader's own codes; with the shop configured, a wrong provenance or a
  short lookup key still reads «wrong» (never an all-green page).
- Mutants AT1–AT30 (no AT5) in the WordPress runner (modes disabled / admin_tools), ATC1–ATC2 (commerce, CI only), and
  the packager self-tests (a negative case for every rule above).
