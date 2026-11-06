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
POST with the WordPress nonce; the capability is checked again inside the service; every action and every refusal is
audited.

| Action | What happens |
|---|---|
| Create | Only when no account with the staff role exists. Role `evc_club_staff` only, no e-mail, display name «Evangelou Club — ταμείο», login name chosen by the administrator (default `club-tameio`; 4–60 lower-case letters, digits, `- _ .`). A generated password is shown once. If anything grants the new account more than the staff role, it stays disabled and the page says so. |
| New password | A generated 24-character password (lower and upper case letters and digits), shown once in the answer to that POST and never stored, logged or cached by the plugin. Every session of the account ends. |
| Disable / enable | `EVC_Staff_Auth::set_disabled` (disabling also ends every session). |
| Sign out everywhere | `EVC_Staff_Session::revoke_all_staff_sessions`. |
| Status | Disabled or not, open sessions, last sign-in time (recorded on `wp_login` while the flag is on, or the newest open session). No IP address, no personal data. |

- **More than one staff account:** a warning on the page and «wrong» on the readiness page. Nothing is ever deleted
  automatically; the administrator disables the extra accounts and deletes them by hand if wanted.
- **Audit:** the newest 100 entries in the option `evc_club_staff_admin_audit` (autoload off): time, action, outcome,
  the administrator's user ID and the account's user ID. Never a password or a login name. The page shows the last 20.
- **No leak:** the packager refuses `error_log`, `print_r`, `var_export`, `var_dump`, `set_transient`, `setcookie` and
  `wp_cache_set` in the two till-account files, and `wp_set_password` anywhere else.

## «Ετοιμότητα» (readiness, read-only)

Every Club flag and configuration check, each «Εντάξει» (OK), «Λείπει» (missing), «Λάθος» (wrong), «Έλεγχος με το
χέρι» (manual) or «Ανενεργό» (off, the default). It never changes anything and never shows a secret (no password, key,
host, user, database name, IP address or header value).

| Section | Checks (reusing the existing code) |
|---|---|
| Club database | the `EVC_CLUB_DB_*` constants; a connection (`EVC_Club_Db::connect`); the applied schema version against `EVC_Club_Migrations_Feature::target_version()`; the server version |
| Flags | customer, staff, redemption source, onboarding (also needs the customer flag), FluentCRM sync, WooCommerce completion, migrations, admin pages: each with its own feature parser |
| Shop | the commerce constants; `EVC_Wp_Commerce_Pmpro_Reader::environment_conflicts()` (pinned versions, product, price 20.00, sold individually, one-month level); `EVC_Bacs_Payment_Issuer::site_problem()` (Athens clock, level group); BACS enabled (D15); the terms URL (D13) |
| FluentCRM | the tag IDs (verified read-only through `EVC_Fluentcrm_Plugin::problem()` when the sync is on); three manual checks: no contacts on user registration, no «New User Sign Up» automations, the WooCommerce integration off |
| Server | proxy headers in this request (names only; `REMOTE_ADDR` is a manual check); page-cache plugins; the Athens timezone; PHP and MySQL / MariaDB against the tested matrix (PHP 7.4 / 8.2 / 8.4; MySQL 8.0 / 8.4, MariaDB 10.11) |
| Till | exactly one shared staff account, restricted, enabled |

The packager enforces that the two readiness files contain no write: no option, meta, transient, user or post
writer, no hook, and no `query / insert / update / delete / execute` on a database handle.

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
  only staff accounts as targets, every refusal for editors, shop managers, the staff account, visitors and an
  administrator without `evc_manage_club`, a missing or wrong nonce changes nothing, the duplicate-account warning,
  an account given extra capabilities stays disabled, the last sign-in time, the audit (no secrets, newest 100).
- `wp-admin-tools` `ReadinessTest`: each status of the Club database (missing, unreachable, empty, behind, at target),
  the flags, the shop / terms / CRM without configuration, proxy header NAMES only, page-cache plugins, timezone,
  versions, the till account; no write on WordPress or the Club database, no form, no secret, administrators only.
- `wp-commerce-admin-tools` `ReadinessCommerceTest` (real WooCommerce + PMPro): the configured shop reads OK; a price
  of 25, a product not sold individually, bank transfer off and a non-Athens timezone each read «wrong» with the
  existing reader's own codes.
- Mutants AT1–AT12 (WordPress) and the packager self-tests.
