# Βάση δεδομένων του Club — μεταβάσεις (STAGE-04A)

Status: implemented and tested in disposable CI / local databases only. **Not run on any real
database.** Running it on staging or production needs the owner's explicit approval at that time.

---

## Για τον διαχειριστή (Ελληνικά)

### Τι είναι

Το Club κρατά τα δικά του στοιχεία (κάρτες, εξαργυρώσεις, πληρωμές Club) σε **μια ξεχωριστή βάση
δεδομένων**, όχι στη βάση του WordPress. Η βάση αυτή στην αρχή είναι άδεια. Οι «μεταβάσεις» είναι
τα αρχεία του plugin που δημιουργούν τους πίνακές της:

- `001` — βασικοί πίνακες (μέλη, κωδικοί QR, εξαργυρώσεις, ημερολόγιο ελέγχου)
- `002` — πίνακες πληρωμών του Club

Η σελίδα **Εργαλεία → «Evangelou Club — Βάση δεδομένων»** δείχνει σε ποια κατάσταση είναι η βάση και,
όταν όλα είναι εντάξει, εφαρμόζει τις μεταβάσεις που λείπουν. Δεν διαγράφει και δεν αλλάζει τίποτα
που υπάρχει ήδη, και δεν δέχεται κανένα δικό σας κείμενο SQL.

### Πότε

- **Μία φορά**, όταν ο τεχνικός έχει φτιάξει την ξεχωριστή, άδεια βάση του Club.
- Ξανά **μόνο** όταν μια νέα έκδοση του plugin φέρνει νέα μετάβαση (θα το δείτε ως «Εκκρεμείς μεταβάσεις»).
- Πάντα **μετά από έγκριση** του ιδιοκτήτη για το συγκεκριμένο site (staging ή παραγωγή).

### Ποιος

- Μόνο **διαχειριστής** του WordPress (δικαίωμα «manage_options»). Ο υπεύθυνος καταστήματος
  (shop manager), ο κοινός λογαριασμός του Club και οι πελάτες **δεν** βλέπουν τη σελίδα.
- Εναλλακτικά ο τεχνικός από τον διακομιστή με WP-CLI (δείτε παρακάτω).

### Τι πρέπει να έχει γίνει πριν (από τον τεχνικό)

1. Μια **νέα, άδεια** βάση μόνο για το Club (όχι η βάση του WordPress).
2. Στο `wp-config.php`: `EVC_CLUB_DB_HOST`, `EVC_CLUB_DB_NAME`, `EVC_CLUB_DB_USER`,
   `EVC_CLUB_DB_PASSWORD` (και προαιρετικά `EVC_CLUB_DB_PORT`).
3. Για να εμφανιστεί η σελίδα: `define('EVC_CLUB_MIGRATIONS_ENABLED', true);` στο `wp-config.php`.
   Χωρίς αυτή τη γραμμή η σελίδα και η εντολή δεν υπάρχουν καθόλου.
4. **Αντίγραφο ασφαλείας** της βάσης του Club (ακόμη κι αν είναι άδεια) και του WordPress.

### Πώς

1. Ανοίξτε **Εργαλεία → Evangelou Club — Βάση δεδομένων**.
2. Δείτε την ενότητα «Έλεγχοι ασφαλείας». Όλες οι γραμμές πρέπει να λένε **«Εντάξει»**.
3. Στις «Εκκρεμείς μεταβάσεις» θα δείτε π.χ. `001 — initial` και `002 — payment_provenance`.
4. Γράψτε τη λέξη **ΕΦΑΡΜΟΓΗ** (κεφαλαία, χωρίς τόνο) στο πεδίο και πατήστε
   **«Εφαρμογή εκκρεμών μεταβάσεων»**.
5. Πρέπει να δείτε: «Η βάση του Club ενημερώθηκε. Εφαρμόστηκαν οι μεταβάσεις: 001, 002.» και
   «Η βάση του Club είναι ενημερωμένη.» Οι μεταβάσεις φαίνονται πλέον στις «Εφαρμοσμένες» με
   «Ίδιο (εντάξει)».
6. Αν το ξαναπατήσετε, δεν γίνεται τίποτα («Δεν υπάρχει καμία εκκρεμής μετάβαση»).

Η σελίδα ποτέ δεν δείχνει τον κωδικό, τον χρήστη ή τον διακομιστή της βάσης· το όνομα της βάσης
φαίνεται μόνο κρυμμένο (π.χ. `e•••b (14)`).

### Τι σημαίνουν οι έλεγχοι

| Έλεγχος | Τι ελέγχει |
|---|---|
| Στοιχεία σύνδεσης στο wp-config.php | Ότι υπάρχουν και είναι έγκυρα τα `EVC_CLUB_DB_*`. |
| Σύνδεση με τη βάση του Club | Ότι το plugin συνδέεται στη βάση του Club. |
| Ξεχωριστή από τη βάση του WordPress | Ότι η βάση του Club **δεν** είναι η βάση του WordPress (με βάση τις ρυθμίσεις και με βάση τον ίδιο τον διακομιστή). |
| Χωρίς πίνακες του WordPress | Ότι στη βάση του Club δεν υπάρχουν πίνακες όπως `wp_options`, `wp_posts`, `wp_users`. |
| Χωρίς πίνακες που δεν ανήκουν στο Club | Ότι δεν υπάρχει κανένας πίνακας που δεν αρχίζει με `evc_`. |
| Οι πίνακες του Club ταιριάζουν με τις καταγεγραμμένες μεταβάσεις | Κάθε πίνακας `evc_` έχει φτιαχτεί από μετάβαση που έχει καταγραφεί, και κανένας δεν λείπει. |
| Οι εφαρμοσμένες μεταβάσεις ταιριάζουν με τα αρχεία του plugin | Τίποτα δεν άλλαξε μετά την εφαρμογή (αποτύπωμα SHA-256), τίποτα δεν λείπει, σωστή σειρά. |

### Τι κάνω σε κάθε άρνηση

| Μήνυμα (σύντομα) | Τι κάνετε |
|---|---|
| Δεν έχουν οριστεί τα στοιχεία σύνδεσης… | Ο τεχνικός προσθέτει τα `EVC_CLUB_DB_*` στο `wp-config.php`. |
| Τα στοιχεία σύνδεσης… δεν είναι έγκυρα | Ο τεχνικός ελέγχει το όνομα βάσης (μόνο γράμματα, αριθμοί, `_`) και τη θύρα. |
| Δεν έγινε σύνδεση με τη βάση του Club | Ο τεχνικός ελέγχει ότι η βάση υπάρχει, ότι ο χρήστης έχει δικαιώματα και ότι ο κωδικός είναι σωστός. Ο «κωδικός σφάλματος» (π.χ. 1045 = λάθος χρήστης/κωδικός, 1049 = δεν υπάρχει η βάση, 2002 = δεν βρέθηκε ο διακομιστής) βοηθά τον τεχνικό. |
| Η βάση του Club είναι η ίδια με τη βάση του WordPress | **Σταματήστε.** Ο τεχνικός πρέπει να φτιάξει **νέα** βάση για το Club και να αλλάξει το `EVC_CLUB_DB_NAME`. |
| Δεν μπόρεσε να επιβεβαιωθεί ότι η βάση… είναι διαφορετική | Ενημερώστε τον τεχνικό/προγραμματιστή (το WordPress δεν απάντησε στον έλεγχο). |
| Η βάση του Club περιέχει πίνακες του WordPress | **Σταματήστε.** Οι ρυθμίσεις δείχνουν σε λάθος βάση. Ο τεχνικός διορθώνει το `EVC_CLUB_DB_NAME`. |
| …πίνακες που δεν ανήκουν στο Club | Χρειάζεται **άδεια** βάση μόνο για το Club. Ο τεχνικός δίνει νέα βάση (δεν σβήνουμε τίποτα από εδώ). |
| Ο πίνακας που καταγράφει τις μεταβάσεις… απρόσμενη μορφή | Μην προχωρήσετε· ενημερώστε τον προγραμματιστή. |
| Μια μετάβαση… δεν ταιριάζει με τα αρχεία του plugin | Μην προχωρήσετε· ενημερώστε τον προγραμματιστή (κάποιος άλλαξε αρχείο ή βάση με το χέρι, ή εγκαταστάθηκε λάθος έκδοση). |
| Υπάρχουν πίνακες του Club που δεν έχουν καταγραφεί | Μην προχωρήσετε· η βάση φτιάχτηκε με άλλο τρόπο ή έμεινε μισή. Ενημερώστε τον προγραμματιστή· συνήθως λύση είναι επαναφορά του αντιγράφου ή νέα άδεια βάση. |
| Λείπουν πίνακες του Club… | Μην προχωρήσετε· ενημερώστε τον προγραμματιστή (επαναφορά αντιγράφου). |
| Τα αρχεία μεταβάσεων του plugin δεν είναι σωστά | Εγκαταστήστε ξανά το plugin από το επίσημο πακέτο. |
| Ο έλεγχος της βάσης… δεν ολοκληρώθηκε | Δοκιμάστε ξανά σε λίγο· αν επαναληφθεί, ενημερώστε τον τεχνικό. |
| Τρέχει ήδη μια άλλη εφαρμογή μεταβάσεων | Περιμένετε λίγα λεπτά και ξαναδοκιμάστε (κάποιος άλλος πάτησε ήδη ή τρέχει το WP-CLI). |
| Η μετάβαση NNN απέτυχε… | **Μην ξαναπατήσετε.** Επαναφέρετε το αντίγραφο ασφαλείας της βάσης του Club και ενημερώστε τον προγραμματιστή. |
| Η λέξη επιβεβαίωσης δεν είναι σωστή | Γράψτε ακριβώς `ΕΦΑΡΜΟΓΗ` (ελληνικά κεφαλαία, χωρίς τόνο). |
| Δεν έχετε δικαίωμα για αυτή τη σελίδα | Χρειάζεται λογαριασμός διαχειριστή. |

### Για τον τεχνικό: WP-CLI

```
wp evc-club migrations status
wp evc-club migrations apply --yes
```

Υπάρχει μόνο όταν `EVC_CLUB_MIGRATIONS_ENABLED` είναι `true`. Κάνει τους ίδιους ελέγχους· χωρίς
`--yes` αρνείται. Με `--user=<διαχειριστής>` ο χρήστης καταγράφεται στο ημερολόγιο ελέγχου.

### Μετά

Όταν η βάση είναι ενημερωμένη, η γραμμή `EVC_CLUB_MIGRATIONS_ENABLED` μπορεί να αφαιρεθεί από το
`wp-config.php` (η σελίδα εξαφανίζεται· τα δεδομένα μένουν). Η ενεργοποίηση του plugin **ποτέ** δεν
εφαρμόζει μεταβάσεις μόνη της.

---

## For developers (English)

### Files

- `includes/admin/class-evc-club-migrations-feature.php` — opt-in constant `EVC_CLUB_MIGRATIONS_ENABLED`
  (exact boolean `true`), `assert_constructible()` (no test override: the tests that construct
  run with the constant ON).
- `includes/admin/class-evc-club-migrations-plugin.php` — `boot()` (gate first, then `build()` →
  `wire()`); the only place that constructs the service, page, CLI command and `EVC_Migrator`.
  Hooked on `plugins_loaded` from `evangelou-club.php`. The activation hook is unchanged.
- `includes/admin/class-evc-club-migrations-service.php` — `status()` (read-only) and
  `apply(int $actor, 'admin'|'cli')`; Greek messages; masking.
- `includes/admin/class-evc-club-migrations-admin-page.php` — Tools page (`add_management_page`,
  `manage_options`), POST handling.
- `includes/admin/class-evc-club-migrations-cli.php` — `wp evc-club migrations status|apply --yes`
  (registered only under WP-CLI with the constant ON).

### Decisions

- **Access:** `manage_options` AND not `evc_club_staff` (a staff account given `manage_options` is
  still refused). Checked on every render and again after the nonce.
- **Apply request:** POST + `check_admin_referer('evc_club_migrations_apply')` + capability re-check +
  typed word `ΕΦΑΡΜΟΓΗ` (exact, case-sensitive; surrounding whitespace ignored).
- **Lock:** `GET_LOCK(CONCAT('evc_migrations_', SHA1(DATABASE())), 0)` taken first (no wait →
  `lock_busy`) and held across inspection + `EVC_Migrator::migrate()`. GET_LOCK is re-entrant per
  connection on MySQL ≥ 5.7 / MariaDB ≥ 10.0.2, so the migrator's own lock nests inside it.
- **Separation checks** (`inspect()`, all read-only; any failure refuses):
  - configured name + normalised host equal to `DB_NAME` + `DB_HOST` → refused **before connecting**
    (every loopback spelling counts as the same host; ports / sockets ignored: over-refusal is safe);
  - server identity `@@hostname`, `@@port`, `DATABASE()` of the Club connection equal to the
    WordPress connection's → refused; WordPress identity unreadable → refused;
  - tables (`information_schema.TABLES`, views included): `$wpdb->base_prefix*` or a core WordPress
    name (`options`, `posts`, `users`, … with any prefix) → `wordpress_tables_present`; not `evc_*`
    → `unknown_tables_present`;
  - `evc_schema_migrations` read with a plain SELECT (never created by status); misshapen →
    `bookkeeping_table_invalid`; recorded rows vs. shipped files (checksum + name) and order →
    `drift`; `evc_*` tables not created by a recorded migration (parsed from its `CREATE TABLE`
    statements) → `unrecorded_club_tables`; recorded tables missing → `missing_club_tables`.
- **Idempotency:** nothing pending → `nothing_pending`, no migrator call, no audit row (log line only).
- **Audit:** after a migration attempt, one `evc_audit_events` row (`event_type`
  `club_db_migration`, outcome `applied` / `failed`, `staff_wp_user_id` = actor or NULL,
  details `{channel, versions[, failed_version]}`) when that table exists; always one
  `error_log` line: `Evangelou Club: Club DB migrations apply via <channel> by user #<id>:
  result=<r> versions=<…> [failed=NNN] [reasons=<codes>] audit=<written|not_written|no_audit_table|failed>`.
- **Secrets:** reports carry no host / user / password; the database name only as
  `first•••last (length)`; exceptions are mapped to codes (driver code only for connection
  failures); `EVC_Club_Db` already drops driver messages.
- **Failure mid-way:** MySQL DDL auto-commits; a failed migration is not recorded, the result is
  `failed` with the first unrecorded version, and the operator restores the backup (as documented in
  `EVC_Migrator`).

### Packager guard

`app/scripts/php-guards.mjs` `MIGRATIONS_RULES`: guarded classes `EVC_Club_Migrations_Service`,
`EVC_Club_Migrations_Admin_Page`, `EVC_Club_Migrations_Cli`, `EVC_Migrator` (constructed only inside
`EVC_Club_Migrations_Plugin::wire()`; `boot()` must start with the real gate; `build()` / `wire()` only
from the bootstrap); their names only in six allowlisted files; no shipped file may
`define('EVC_CLUB_MIGRATIONS_ENABLED', …)`. Negative fixtures run on every package.

### Tests

- `tests/wordpress/migrations/ClubMigrationsTest.php` (suite `wp-migrations`, process with
  `EVC_TEST_CLUB_MIGRATIONS_FLAG=enabled`; CI step in the `wordpress` job on PHP 7.4/8.2/8.4 ×
  MySQL 8.0 and PHP 8.2 × MariaDB 10.11). Each test creates a disposable `evc_test_*` Club database
  and a random MySQL user/password with rights only on it; the WordPress test database lives on the
  same server, so the separation checks are exercised for real.
- `tests/wordpress/common/ClubMigrationsOffTest.php` (every default suite): nothing hooked, no menu,
  no route, `boot()` silent, construction refused, only `true` enables.
- WP-CLI itself is not loaded in tests: the command class is tested with recording output callables;
  the `WP_CLI::add_command` registration line is not executed by any test.
- Mutants `MG1`–`MG27` in `tests/mutation/run-wp-mutations.php` (mode `migrations`, OFF-gate ones in
  mode `disabled`), each with named killer tests.

### Not covered

- No real staging/production run; no real WP-CLI run; multisite not considered (single site);
  a migration that fails half-way is not exercised end to end (the migrator's own tests cover its
  failure path); a class name assembled from string pieces at run time escapes the static guard
  (the constructors refuse while the constant is off).
