# Δωρεάν καφές από το ταμείο — πραγματικές συνδρομές (STAGE-05A)

**Κατάσταση:** ανάπτυξη μόνο. ΔΕΝ είναι ενεργό πουθενά. Χωρίς τη νέα σταθερά η εφαρμογή του
ταμείου απαντά όπως πριν («η υπηρεσία δεν είναι διαθέσιμη», HTTP 503).

## Για τον τεχνικό / τον ιδιοκτήτη (ελληνικά)

### Τι κάνει

Όταν ο υπάλληλος πατά «Δωρεάν καφές» για ένα μέλος, το plugin:

1. ελέγχει στο WooCommerce / Paid Memberships Pro αν το μέλος έχει **πληρωμένη** συνδρομή, και
   μόνο αν η πληρωμή έχει **καταγραφεί από το Club** στη βάση του Club. Μια παραγγελία σε
   «Ολοκληρωμένη» **μόνη της δεν αρκεί**: μετράει μόνο όταν το Club έχει γράψει την πληρωμή, δηλαδή
   (α) με την επιβεβαίωση τραπεζικής μεταφοράς από υπεύθυνο, ή (β) όταν είναι ενεργή η λειτουργία
   «Ολοκληρωμένη = πληρωμή» (`EVC_CLUB_WC_COMPLETION_ENABLED`, ξεχωριστή έγκριση) και το
   προσωπικό του καταστήματος έβαλε «Ολοκληρωμένη» από την οθόνη διαχείρισης. Παραγγελία που
   περιμένει πληρωμή, ακυρωμένη ή με επιστροφή χρημάτων δεν δίνει ποτέ καφέ·
2. επιτρέπει **έναν δωρεάν καφέ ανά ημέρα Αθήνας** (η ημέρα αλλάζει στις 00:00 ώρα Ελλάδας, και
   τη μέρα αλλαγής ώρας)·
3. γράφει τον καφέ και τον υπάλληλο στη χωριστή βάση του Club.

Ο τύπος του καφέ **δεν** επηρεάζει το δικαίωμα (απόφαση ιδιοκτήτη D3, 2026-11-03: «όλοι οι
καφέδες ότι και να είναι»). Η λίστα v1 (`evc-coffee-v1-2026-11-03`): Espresso, Freddo Espresso,
Cappuccino, Freddo Cappuccino, Ελληνικός, Φίλτρου και **Άλλος καφές** (για κάθε άλλον καφέ του
καταστήματος). Νέα ονόματα προστίθενται με μια μικρή, ελεγμένη αλλαγή κώδικα.

### Πώς ενεργοποιείται (ΜΟΝΟ με έγκριση του ιδιοκτήτη, πρώτα στο staging)

Στο `wp-config.php` πρέπει να υπάρχουν **όλα** τα παρακάτω:

    define('EVC_CLUB_STAFF_ENABLED', true);              // το ταμείο του Club
    define('EVC_CLUB_REDEMPTION_SOURCE', 'club_db_v1');   // ΝΕΟ: ο πραγματικός δωρεάν καφές
    define('EVC_CLUB_PAYMENT_PROVENANCE', 'club_db_v1');  // οι καταγεγραμμένες πληρωμές του Club
    // + οι σταθερές της πηγής συνδρομών (EVC_CLUB_MEMBERSHIP_SOURCE, _PMPRO_LEVEL_ID,
    //   _WC_PRODUCT_ID, _EVIDENCE_KEY) και της βάσης του Club (EVC_CLUB_DB_*)

και η βάση του Club να έχει τους πίνακες 001 + 002 (Εργαλεία → «Evangelou Club — Βάση δεδομένων»).

Η τιμή γράφεται **ακριβώς** `'club_db_v1'` (μικρά γράμματα, χωρίς κενά). `true`, `'CLUB_DB_V1'`,
`' club_db_v1'` = ΑΝΕΝΕΡΓΟ.

### Τι βλέπει το ταμείο όταν κάτι λείπει

Αν λείπει οποιοδήποτε κομμάτι (σταθερά, πηγή συνδρομών, καταγραφή πληρωμών, βάση, πίνακες 002) ή
η βάση δεν απαντά, το ταμείο λέει «η υπηρεσία δεν είναι διαθέσιμη» και **δεν δίνει καφέ**. Στο
αρχείο σφαλμάτων του PHP γράφεται μία γραμμή χωρίς κωδικούς ή ονόματα, π.χ.

    Evangelou Club: staff redemption unavailable (club_db_unavailable, EVC_Db_Exception).

| Αιτία στη γραμμή | Τι κάνετε |
|---|---|
| `staff_flag_off` | Το `EVC_CLUB_STAFF_ENABLED` δεν είναι `true`. |
| `redemption_source_off` | Η σταθερά `EVC_CLUB_REDEMPTION_SOURCE` λείπει ή είναι γραμμένη λάθος. |
| `commerce_source_not_configured` | Λείπει / είναι λάθος μια σταθερά της πηγής συνδρομών. |
| `payment_provenance_not_recorded` | Λείπει ή είναι λάθος το `EVC_CLUB_PAYMENT_PROVENANCE`. |
| `club_db_not_configured` | Λείπουν ή είναι λάθος οι `EVC_CLUB_DB_*`. |
| `club_db_unavailable` | Η βάση του Club δεν απαντά (διακομιστής, κωδικός, όνομα βάσης). |
| `club_db_schema_too_old` | Δεν έχουν εφαρμοστεί οι πίνακες 001 + 002. |
| `coffee_catalogue_unavailable` | Η λίστα καφέδων λείπει ή είναι κενή (δεν συμβαίνει με την εγκεκριμένη λίστα· ειδοποιήστε τον προγραμματιστή). |
| `environment_unreadable` | Μια σταθερά έχει μη έγκυρη τιμή (π.χ. λάθος θύρα στο `EVC_CLUB_DB_PORT`). |
| `build_failed` | Απρόσμενο σφάλμα κατά την προετοιμασία· ειδοποιήστε τον προγραμματιστή. |

### Πώς απενεργοποιείται

Αφαιρέστε (ή σχολιάστε) τη γραμμή `EVC_CLUB_REDEMPTION_SOURCE`. Το ταμείο επιστρέφει αμέσως στο
«δεν είναι διαθέσιμη». Τα καταγεγραμμένα δεδομένα μένουν στη βάση του Club.

### Τι ΔΕΝ κάνει ακόμα

Δεν δημιουργεί μέλη του Club (η γραμμή `evc_members` θα έρθει με το QR / την εγγραφή στο κατάστημα),
δεν έχει QR, αναζήτηση μέλους ή οθόνη ταμείου συνδεδεμένη — αυτά είναι επόμενα βήματα.

## Developer notes (English)

- **Opt-in:** `EVC_Club_Redemption_Feature` (`EVC_CLUB_REDEMPTION_SOURCE === 'club_db_v1'`, exact).
  `EVC_Staff_Plugin::register_routes()` (after its staff gate) picks
  `new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source())` when it is on, else
  `EVC_Unavailable_Redemption_Backend` (unchanged default; shipped behaviour without the constant is
  the same 503 as before).
- **Lazy build:** `EVC_Club_Redemption_Backend::service()` builds `EVC_Redemption_Service` per redeem
  request (after the controller's auth and body checks). Order: staff flag → opt-in → commerce config →
  `EVC_CLUB_PAYMENT_PROVENANCE === 'club_db_v1'` → `EVC_Db_Config` → catalogue non-empty → connect →
  `MAX(version) >= 2`. Nothing connects before the connect step; any failure → null (503) with
  `last_reason()`; any throw → `build_failed`. Logs: a fixed reason + exception class only; the engine's
  reporter logs stage / class / SQLSTATE / driver code only.
- **Membership:** `EVC_Pmpro_Membership_Adapter(new EVC_Wp_Commerce_Pmpro_Reader($commerce,
  new EVC_Store_Club_Payment_Provenance(... EVC_Sql_Provenance_Store on the same connection ...)),
  $commerce->mapper())`. Unlike `EVC_Customer_Plugin::payment_provenance()` there is NO fallback to
  `EVC_Unrecorded_Club_Payment_Provenance`.
- **Shared connection, deliberate:** the provenance store reads on the SAME Club DB connection as the
  ledger. Its first read (`ensure_ready()`) runs `SET SESSION innodb_lock_wait_timeout = 5`, so for the
  rest of the request the ledger INSERT also waits at most 5 s for a row lock (the server default is
  50 s). The engine retries deadlock / lock-wait errors up to 3 times (`MAX_TRANSACTION_ATTEMPTS`), so a
  contended redeem gives up after about 15 s with `server_error` (500); retrying with the SAME
  `request_id` is idempotent. This is kept on purpose: a till should fail fast rather than hang. The
  only real lock contention is two tills redeeming the same member on the same day, where the
  unique-key winner commits in milliseconds. One connection per request also means a single connect
  failure is a clean 503 before anything is read. (A redeem that finds no member never reads
  provenance; then the session keeps the server default.)
- **Catalogue:** `EVC_Redemption_Catalogue_Source`; the shipped `EVC_Approved_Coffee_Catalogue_Source`
  (VERSION `evc-coffee-v1-2026-11-03`, 7 codes incl. the catch-all `other_coffee`). The catalogue version
  is not stored in the ledger (no new migration); the codes are. Changing the list = edit `ITEMS` +
  `VERSION` + the exact-list test + this doc, one reviewed PR.
- **Test seams:** the backend constructor's `$environment`, `$connect`, `$clock`, `$log`. The packager
  (`app/scripts/php-guards.mjs`, `REDEMPTION_RULES`) only allows the exact statement
  `$backend = EVC_Club_Redemption_Feature::enabled() ? new EVC_Club_Redemption_Backend(new
  EVC_Approved_Coffee_Catalogue_Source()) : new EVC_Unavailable_Redemption_Backend();` (standalone, so the
  condition cannot be widened) in `register_routes()` after the staff gate, with `$backend` assigned once
  and passed to exactly one `EVC_Rest_Redeem_Controller`, only the catalogue file may build `EVC_Coffee_Catalog` /
  implement `EVC_Redemption_Catalogue_Source`, only the two backend files implement
  `EVC_Redemption_Backend`, the parser must stay `$value === self::VALUE`, and no shipped file may
  define `EVC_CLUB_REDEMPTION_SOURCE` / `EVC_CLUB_PAYMENT_PROVENANCE`. All earlier rule sets unchanged.
- **Tests:** `tests/wordpress/enabled/ClubRedemptionBackendTest.php` (matrix through the seams, no
  connection on early refusals, catalogue), `tests/wordpress/redemption/RedemptionSourceOnTest.php`
  (constant ON, nothing else: 503, nothing built at registration), and
  `tests/wordpress/commerce-redeem/CommerceRedemptionTest.php` (CI only: real WooCommerce / PMPro, both
  order storages; 200, replay, one per Athens day across midnight and the 2026-10-25 DST change,
  exclusive end (exactly at the recorded end: expired; one second before: allowed), recorded refund /
  reversal freezes the card, pending / unrecorded never eligible, unknown / disabled member, Club DB
  down 503, provenance read failing 500). Mutants RB1–RB30 in `tests/mutation/run-wp-mutations.php` (modes `enabled`,
  `redemption`, `commerce_redeem`).
- **Not done here:** member rows / sync (`EVC_Member_Sync_Service` stays forbidden), QR tokens, lookup
  route, staff UI wiring, v2 prepared redemption, new migrations.
