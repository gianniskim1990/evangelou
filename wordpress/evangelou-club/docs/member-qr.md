# QR μέλους: QR του πελάτη και σάρωση στο ταμείο (STAGE-05C)

**Κατάσταση:** ανάπτυξη μόνο. ΔΕΝ είναι ενεργό πουθενά. Χωρίς τις σταθερές που ήδη υπάρχουν για το
Club (βλ. «Πώς ενεργοποιείται»), οι νέες διαδρομές **δεν υπάρχουν καν** και το plugin συμπεριφέρεται
ακριβώς όπως πριν.

## Για τον τεχνικό / τον ιδιοκτήτη (ελληνικά)

### Αποφάσεις ιδιοκτήτη (2026-11-04)

| | Απόφαση |
|---|---|
| **D6** διάρκεια / αλλαγή | «Ίδιος μέχρι να ζητηθεί νέος». Ο QR δεν λήγει. Αλλάζει μόνο όταν ο πελάτης πατήσει «νέο QR» ή όταν ο διαχειριστής ακυρώσει τον παλιό. Το κινητό τον κρατά. |
| **D7** στιγμιότυπο οθόνης | «Ναι, το δεχόμαστε». Μια φωτογραφία οθόνης του QR δουλεύει. Ο ένας καφές ανά ημέρα Αθήνας περιορίζει κάθε απώλεια. |
| **D8** τι βλέπει το ταμείο | «Μικρό όνομα + κατάσταση»: «ενεργό», «όχι ενεργό» ή «ο καφές πάρθηκε σήμερα». Ποτέ επώνυμο, τηλέφωνο, e-mail ή άλλα στοιχεία. |
| **D9** λάθος σαρώσεις | Περίπου **10 αποτυχίες ανά 5 λεπτά, ανά tablet**. Ο μετρητής ζει σε έναν νέο μικρό πίνακα (μετάβαση 003). Εγκρίθηκε ο **κώδικας**. Η εφαρμογή του 003 στο staging/παραγωγή χρειάζεται **ξεχωριστή** έγκριση. |

### Τι κάνει

1. **Ο πελάτης** (με αριθμό μέλους, δηλαδή με καταγεγραμμένη πληρωμή, STAGE-05B) ανοίγει την κάρτα του
   και παίρνει **έναν** QR. Αν τον ξαναζητήσει, παίρνει την απάντηση «υπάρχει ήδη QR» και ο QR **δεν**
   αλλάζει. Μόνο με «νέο QR» αλλάζει, και τότε ο παλιός σταματά αμέσως να δουλεύει.
2. Στη βάση **δεν** γράφεται ποτέ ο ίδιος ο QR, μόνο ένα «αποτύπωμά» του. Γι' αυτό ο QR δίνεται στο
   κινητό **μόνο τη στιγμή που δημιουργείται** και το κινητό τον κρατά.
3. **Το ταμείο** σαρώνει τον QR και βλέπει το μικρό όνομα και την κατάσταση. Ο QR **δεν δίνει μόνος του
   καφέ**: όταν ο υπάλληλος πατήσει «Δωρεάν καφές», ο έλεγχος γίνεται ξανά από την αρχή (πληρωμένη και
   καταγεγραμμένη συνδρομή, ένας καφές ανά ημέρα Αθήνας, ακύρωση / επιστροφή χρημάτων = παγωμένη κάρτα).
   Παγωμένη ή ληγμένη κάρτα φαίνεται «όχι ενεργή».
4. Ένας άγνωστος, αντικατεστημένος ή ακυρωμένος QR δίνει **την ίδια** απάντηση «δεν βρέθηκε μέλος». Το
   ταμείο δεν μαθαίνει ποτέ αν ένας κωδικός υπήρξε κάποτε.
5. Μετά από **10 αποτυχημένες σαρώσεις σε 5 λεπτά**, **αυτό** το tablet περιμένει ως το τέλος του
   5λεπτου. Τα άλλα tablets δουλεύουν κανονικά.
6. **Χαμένο κινητό:** ο πελάτης συνδέεται από το νέο κινητό και πατά «νέο QR». Ο **διαχειριστής** του
   WordPress μπορεί επίσης να ακυρώσει τον QR ενός πελάτη (με τον αριθμό χρήστη WordPress). Ο κοινός
   λογαριασμός του ταμείου **δεν** μπορεί.

### Πώς ενεργοποιείται (ΜΟΝΟ με έγκριση του ιδιοκτήτη, πρώτα στο staging)

- **QR πελάτη:** `EVC_CLUB_CUSTOMER_ENABLED = true` **και** `EVC_CLUB_PAYMENT_PROVENANCE = 'club_db_v1'`
  (+ `EVC_CLUB_DB_*`). Η βάση του Club πρέπει να έχει τουλάχιστον τις μεταβάσεις 001 + 002.
- **Σάρωση στο ταμείο:** ό,τι ήδη χρειάζεται ο δωρεάν καφές (STAGE-05A: `EVC_CLUB_STAFF_ENABLED`,
  `EVC_CLUB_REDEMPTION_SOURCE = 'club_db_v1'`, πηγή συνδρομών, `EVC_CLUB_PAYMENT_PROVENANCE`, `EVC_CLUB_DB_*`)
  **και** τη μετάβαση **003** **και** ένα νέο μυστικό στο `wp-config.php`:

      define('EVC_CLUB_LOOKUP_KEY', '...τουλάχιστον 32 τυχαίοι χαρακτήρες...');

  Το βάζει **ο τεχνικός**, ένα διαφορετικό για κάθε περιβάλλον (staging ≠ παραγωγή). Δεν μπαίνει ποτέ
  σε git, e-mail ή chat. Π.χ. `php -r "echo bin2hex(random_bytes(32));"` (64 χαρακτήρες). Αν λείπει
  ή είναι μικρότερο από 32 bytes, η σάρωση απαντά «η υπηρεσία δεν είναι διαθέσιμη».

### Δικαιώματα στη βάση του Club (όταν ενεργοποιηθεί)

| Πίνακας | Δικαιώματα |
|---|---|
| `evc_members` | `SELECT` |
| `evc_qr_tokens` | `SELECT`, `INSERT`, `UPDATE (status, revoked_at_utc)`. Ποτέ `DELETE`. |
| `evc_lookup_failures` (003) | `SELECT`, `INSERT`, `UPDATE`, `DELETE` (μόνο για καθαρισμό γραμμών πάνω από μίας ημέρας) |

### Τι βλέπει ο τεχνικός στο αρχείο σφαλμάτων

Μία γραμμή χωρίς κωδικούς, ονόματα ή QR, π.χ.

    Evangelou Club: staff QR unavailable (lookup_key_missing).
    Evangelou Club: customer QR unavailable (club_db_schema_too_old).

| Αιτία | Τι κάνετε |
|---|---|
| `lookup_key_missing` | Λείπει ή είναι μικρό το `EVC_CLUB_LOOKUP_KEY`. |
| `club_db_schema_too_old` | Ταμείο: δεν εφαρμόστηκε το 003. Πελάτης: λείπουν 001 + 002. |
| `payment_provenance_not_recorded` | Λείπει ή είναι λάθος το `EVC_CLUB_PAYMENT_PROVENANCE`. |
| άλλες (`staff_flag_off`, `redemption_source_off`, `commerce_source_not_configured`, `club_db_not_configured`, `club_db_unavailable`) | Όπως στο `docs/staff-redemption.md`. |

### Ανοιχτό ερώτημα (δεν εμποδίζει)

Να μπορεί και ο **κοινός λογαριασμός του ταμείου** να ακυρώνει QR (π.χ. όταν ο υπάλληλος βλέπει ότι
κάποιος δείχνει φωτογραφία άλλου); Σήμερα μόνο ο διαχειριστής (ασφαλέστερο, σύμφωνα με τον
εγκεκριμένο σχεδιασμό UI-04C).

---

## Developer notes (English)

### Pieces and gates

| Piece | File | Constructed (packager rule set `qrProblemsFor`) |
|---|---|---|
| `EVC_Sql_Qr_Token_Store` (UI-04C `EVC_Qr_Token_Store`, 001) | `includes/class-evc-sql-qr-token-store.php` | only `EVC_Club_Qr_Services::store()` |
| `EVC_Sql_Lookup_Rate_Limiter` (UI-04C `EVC_Lookup_Rate_Limiter`, 003) | `includes/class-evc-sql-lookup-rate-limiter.php` | only `EVC_Club_Qr_Services::lookup_service()` |
| `EVC_Club_Qr_Services` + `EVC_Wp_Club_Staff_Authorizer`, `EVC_No_Member_Phone_Directory`, `EVC_First_Issue_Only_Qr_Store` | `includes/class-evc-club-qr-services.php` | the one site for `EVC_Qr_Token_Service`, `EVC_Member_Lookup_Service`, `EVC_Lookup_Key_Ring`, `EVC_Lookup_Context_Policy` |
| `EVC_Customer_Qr_Backend` + `EVC_Rest_Me_Qr_Controller` | `includes/customer/` | only `EVC_Customer_Plugin::register_routes()` inside `if (EVC_Customer_Qr_Backend::provenance_recorded()) { … }` (after the customer flag gate) |
| `EVC_Club_Lookup_Backend` + `EVC_Rest_Lookup_Controller` | `includes/staff/` | only `EVC_Staff_Plugin::register_routes()` inside `if (EVC_Club_Redemption_Feature::enabled()) { … }` (after the staff gate) |

Each backend re-checks everything per request before reading, and opens no Club DB connection before
its last config check:
- **Customer:** customer flag `true` → provenance `'club_db_v1'` → `EVC_Db_Config` → connect → schema `>= 2`.
- **Staff:** staff flag → `EVC_CLUB_REDEMPTION_SOURCE` → commerce config → provenance → `EVC_Db_Config`
  → `EVC_CLUB_LOOKUP_KEY` `>= 32` bytes → connect → schema `>= 3`.

Any failure → 503. Both connections run `SET SESSION innodb_lock_wait_timeout = 5`
(`EVC_Club_Qr_Services::prepare_connection`), like the 05A / 05B paths.

### Packager guard (`app/scripts/php-guards.mjs`, `qrProblemsFor` + `qrSelfTest`, 58 fixtures)

The guard replaces the blanket string bans on:
- `new EVC_Qr_Token_Service`, `new EVC_Member_Lookup_Service`, `new EVC_Lookup_Key_Ring`;
- `implements EVC_Qr_Token_Store | EVC_Member_Phone_Directory | EVC_Lookup_Rate_Limiter | EVC_Club_Staff_Authorizer`.

It enforces these rules:
- **One site per class:** each class is constructed only at its single site, at most once there.
- **Exact wiring:** the two backends are wired by the exact standalone statement
  `if (<gate>) { (new <Controller>(new <Backend>())) ->register_routes(); }`, with no test-seam
  arguments.
- **Exact customer gate:** the body of `provenance_recorded()` must be exactly
  `return defined('EVC_CLUB_PAYMENT_PROVENANCE') && constant('EVC_CLUB_PAYMENT_PROVENANCE') === self::PROVENANCE;`
  with `PROVENANCE = 'club_db_v1'`.
- **Implementations:** each interface is implemented only by its own `class` (no enum, trait,
  interface or anonymous class), and no shipped interface extends one.
- **Names:** each QR class name, as an identifier or inside a string, appears only in its
  allowlisted files. This includes the reviewed `EVC_Qr_Token_Service`, `EVC_Member_Lookup_Service` and
  `EVC_Lookup_Key_Ring`, which only `class-evc-member-lookup.php` and the services file may name. So
  `X::class` followed by `new $c` cannot reach them from any other shipped file.
- **Name fragments:** no other shipped string may contain a QR class-name fragment
  (`qr_token_store`, `member_lookup_service`, …).
- **No dynamic construction in the QR files:** `new $x`, `new (…)`, `new self|static|parent|class`,
  `class_alias`, `Reflection*`, `newInstance*` and `unserialize` are refused. `new $x`, `new (…)` and
  `new self|static|parent` are also refused in the staff and customer plugin files, which may name
  their backend (review: `EVC_Customer_Qr_Backend::class` + `new $c`).

Not covered statically: a class name assembled at run time, outside the QR files, from pieces that are
not QR name fragments. The `new EVC_Signed_Member_Context_Verifier` ban and every v2 ban are unchanged.

### Token store (`evc_qr_tokens`, 001 unchanged)

**Storage.** Digests only. The store refuses a plaintext token passed as a digest (mutant QS10) and
never returns a digest from a lookup. `find_member_by_wp_user()` was added for the backends; it is not
part of the UI-04C interface.

**rotate() and revoke().** Each is ONE READ COMMITTED transaction:
1. `SELECT token_hash … WHERE active_member_id = ? FOR UPDATE` on the one-active key.
2. A `hash_equals` compare-and-set against the expected digest (none vs. some counts as a mismatch).
3. A guarded UPDATE: `… WHERE active_member_id = ? AND token_hash = ? AND status = 'active'`,
   which must affect exactly 1 row.
4. rotate() only: the INSERT of the new row.

**Failure outcomes.**
- A duplicate key rolls back, including the revocation.
- A deadlock or lock-wait timeout → `EVC_Qr_Rotation_Conflict`.
- A lost COMMIT → `EVC_Db_Commit_Unknown_Exception`.

**Locks.** The store never takes an explicit lock on `evc_members`. The FK check on the INSERT does
take a **shared** lock on the member row for the rest of the transaction; only a concurrent writer of
that member row could wait on it, and nothing shipped writes member rows on this path. The 5 s session
lock-wait bounds any wait.

**Defence in depth.**
- For **rotate()**, any one of these stops a late writer on its own: the row lock, the digest compare,
  the guarded UPDATE and the unique key.
- For **revoke()**, which has no INSERT so the unique key does not apply, the row lock, the digest
  compare and the guarded UPDATE each stop it.
- Removing only the lock, or only the row-count check, is therefore not observable (equivalent mutants,
  not listed).
- Proven with real separate processes in CI:
  - `test_simultaneous_writers_…` (first issuance and rotation);
  - `test_simultaneous_revoke_and_rotate_…` (exactly one winner);
  - `test_a_writer_blocked_by_an_uncommitted_replacement_…`: another connection really holds the row
    and has replaced it, uncommitted. A writer in a separate process announces that its next statement
    is the store call and is still blocked 1.5 s later. After the commit it re-reads and loses.
    `INNODB_TRX` is not the proof: on the CI MySQL 8 containers it listed only the holder while the
    writer was blocked.

### Customer route (`evangelou-club-customer/v1/me/qr`)

**GET** → `{"result":"ok","qr":{"state":…}}`. The possible states:
- `active`, with `issued_at` in Athens RFC 3339;
- `none`;
- `not_member`;
- `member_disabled`.

GET never returns a token.

**POST**, with body `{}` or `{"replace": bool}` (anything else → 400):

| Answer | Meaning |
|---|---|
| 200 with `token` + `issued_at` | The ONLY time the plaintext leaves the server. |
| 409 `qr_exists` | A QR is active and `replace` was not `true`; nothing changes (D6). |
| 409 `concurrent_change` | Another device wrote first. |
| 404 `not_a_member` | No member row. |
| 403 `member_disabled` | The member is disabled. |
| 503 | Unavailable, including an unknown COMMIT outcome (recovery: POST `replace: true`). |

**Rules.**
- Without `replace`, the service runs over `EVC_First_Issue_Only_Qr_Store`, so its rotation is
  `expected = null`. The store's compare-and-set then refuses if another device just got a QR: a request
  that did not ask to replace never replaces.
- The member is always `get_current_user_id()` (`member_self`).
- Customer accounts only (`EVC_Customer_Auth`).
- Responses are no-store.

**How the customer app keeps the QR (for 05D, not changed here).** The customer bundle
(`app/src/customer/`) is unchanged in this stage; the packager still forbids `localStorage` in it.
05D should:
- add ONE module (e.g. `src/customer/qrStorage.ts`) that lifts that ban for itself only;
- store `{token, issued_at}` under one key per signed-in account (e.g.
  `evc-club-qr:v1:<hash of the user id from the bootstrap>`), wrapping every access in try/catch;
- clear it on sign-out;
- on each open, `GET /me/qr`, then:

| Server says | What the app does |
|---|---|
| `active` with the same `issued_at` | Show the stored QR. |
| `none` | `POST {}`. |
| `active` with nothing (or a different `issued_at`) stored on this device | Offer «Νέο QR» → `POST {"replace": true}`. |
| `not_member` | Explain that the card appears after the payment is confirmed. |

### Staff routes (`evangelou-club/v1`)

**`POST /members/lookup`** (shared staff account, `evc_lookup_member`). Body
`{"method":"qr","qr_token":…}`; phone lookup is not offered (400 `invalid_request`).

| Status | Body / code |
|---|---|
| 200 | `{"member":{"member_id","first_name"},"status":"active"\|"used_today"\|"inactive"}` |
| 400 | `invalid_qr` (counted as a failure) |
| 404 | `member_not_found`: one byte-identical answer for unknown, replaced, revoked and disabled-member tokens |
| 429 | `rate_limited`, `details.retry_after_seconds = 300`, `Retry-After: 300` |
| 503 | unavailable |

- **Identity:** the reviewed `EVC_Member_Lookup_Service` (capability + session ref before any read;
  limiter before any read, failing closed; `EVC_Qr_Token_Policy::without_expiry()` per D6).
- **Status:** decided by `EVC_Club_Lookup_Backend` from EXACTLY the 05A redeem route's membership
  source (WooCommerce / PMPro read-only + the Club's recorded provenance, no fallback):
  - `denial_reason(now) !== null` → `inactive`. This covers frozen, refunded, cancelled, expired (the
    exclusive end), unverified, pending and none.
  - Otherwise, a ledger row for today's Athens day → `used_today`; else `active`.
- **First name (D8 «μικρό όνομα»):** the FIRST WORD of WP `first_name`, else of WooCommerce
  `billing_first_name`, else `null`; at most 40 characters. Body keys may come in any order.
- **Known gap, left as is (review):** the scan does not look at the coffee catalogue. If the approved
  catalogue were ever empty, the scan would still say `active` while the redeem route answers 503
  (`coffee_catalogue_unavailable`). The shipped v1 catalogue is never empty.
- **The lookup proof:** the reviewed service computes its proof, but the v1 route does not return it
  and the v1 redeem route does not need it. Its TTL (300 s, `EVC_Club_Qr_Services`) therefore has no
  effect today; decide it with v2 prepared redemption.

**`POST /admin/members/qr/revoke`** (D6). Users with `evc_manage_club` (administrators). Body
`{"wp_user_id": int}`. The route itself refuses, BEFORE the service:
- any account that carries the Club staff role, even an administrator (403);
- an Application Password request (401);
- a request without a cookie session and a valid `wp_rest` nonce (401).

These route checks are NOT repeated by the service, so each one has its own test and mutant (QL13,
QL14).

| Status | Body / code |
|---|---|
| 200 | `{"revoked":true\|false}` |
| 404 | `member_not_found` |
| 409 | `concurrent_change` |

Only the capability itself is re-checked: the service's administrator path re-checks `evc_manage_club`
through `EVC_Wp_Club_Staff_Authorizer`, which answers ONLY `evc_lookup_member` and `evc_manage_club` (any other capability, e.g. the v2
hand-off ones, is false even if the user has it).

### Failed-scan limit (D9, migration 003 `evc_lookup_failures`)

**What is counted.**
- Only `session:<session_ref>` keys are counted, i.e. one tablet session. The shared account's
  `actor:` key is ignored, so one tablet never blocks the others.
- A call without a session key is refused.

**The window.**
- Fixed 300 s windows aligned to the epoch.
- `allow()` refuses while the current window holds ≥ 10 failures. Across a window boundary up to 19
  can happen in 5 minutes. Scans of the same tablet running IN PARALLEL can each pass `allow()` before
  either records its failure, so a burst may exceed 10 by the number of parallel scans. This is the
  "about 10" the owner decided.
- Successes are not counted.
- **A new login is a fresh count.** The session ref is derived on the server from the WordPress
  session token (`EVC_Staff_Session::session_ref`), so logging the tablet in again starts a new
  count of 10. No extra cap was added (that would be a new business rule). How often this can happen
  is bounded only by the existing staff login throttle (`EVC_Login_Throttle`).

**Writes and storage.**
- `record_failure()` is one atomic `INSERT … ON DUPLICATE KEY UPDATE failures = failures + 1` per key.
- It reads the clock once, then prunes ≤ 50 rows older than 1 day, oldest first
  (`ORDER BY window_start_utc`).
- Stored key: `sha256("evc-lookup-limit-v1|<action>|session:<ref>")`. No token, id or name is stored.
- **Fail closed, both ways (review MAJOR 1).** A read error in `allow()` makes the lookup
  `unavailable`. So does an error while RECORDING a failure (missing grant, lock timeout, broken
  table): `EVC_Member_Lookup_Service::failed()` then answers `unavailable` instead of `not_found`, so
  an uncountable failure can never be retried without limit. Tests:
  - `SqlLookupRateLimiterTest::test_a_failure_that_cannot_be_written_makes_every_failed_scan_unavailable`
    (only the INSERT fails: 30 bad scans, all `unavailable`);
  - `MemberLookupTest::test_a_failure_that_cannot_be_counted_makes_the_lookup_unavailable`.
  - Mutants L27 and LR8.

**Migration 003.**
- Plain `CREATE TABLE` (fails loudly on a differently shaped table).
- Applied only through `EVC_Migrator` / the STAGE-04A page behind `EVC_CLUB_MIGRATIONS_ENABLED`, whose
  separation checks also verify it (`created_tables()` reads its `CREATE TABLE`).
- **Never applied by accident (review MINOR 5).** The page and the command apply only up to
  `EVC_CLUB_MIGRATIONS_TARGET`:
  - undefined → **2**, so 003 is shown as «ΕΚΚΡΕΜΕΙ, ΔΕΝ ΕΧΕΙ ΕΓΚΡΙΘΕΙ», there is no apply button for
    it, and an apply request (form or CLI) changes nothing;
  - a positive integer (exact type) → that version;
  - anything else → refusal `migrations_target_invalid`.

  The technician sets `define('EVC_CLUB_MIGRATIONS_TARGET', 3);` ONLY after the owner's go-ahead.
  `EVC_Migrator::migrate($up_to)` stops at the target.
- Tests in `ClubMigrationsTest`:
  - `test_by_default_migration_003_is_shown_as_not_approved_and_never_applied`;
  - `…with_target_3_a_database_with_001_and_002_gets_only_003`;
  - `…fresh_database_with_target_3_…_target_1_only_001`;
  - `…invalid_target_is_refused…`;
  - `MigratorTest::test_an_upper_bound_…`.
- Mutants MG37–MG42.
- **Why a constant rather than another mechanism.** It is set by the technician in `wp-config.php`,
  the same place as every other Club switch. The administrator page cannot change it, and its default
  is the safe one. A separate "approve 003" button would put the owner's approval inside the same
  one-click page whose accidental use is the risk.

### Logs and personal data

Logs carry fixed reasons, stages and exception classes only; tests assert that no token, secret or name
appears. The staff answer carries the first name only (D8).

### Tests and mutants

**Integration.**
- `SqlQrTokenStoreTest` (23):
  - a writer really blocked by an uncommitted replacement, lost COMMIT for rotate AND revoke;
  - revoke-vs-rotate and writer races (multi-process);
  - first-issuance decorator;
  - 5 s lock wait;
  - least-privilege grants;
  - the services over SQL;
  - frozen / expired cards never redeem.
- `SqlLookupRateLimiterTest` (10):
  - 10 → block, per tablet / action;
  - window end; no session key; nothing personal stored;
  - prune; failure propagation in `allow()`; an INSERT-only failure → unavailable; grants;
  - the lookup service over SQL;
  - multi-process increments.
- `MigratorTest` / `SqlProvenanceStoreTest` updated for 003.

**WordPress.**
- `StaffQrLookupTest` (wp-redemption, 11):
  - shipped wiring → 503; auth; every gate without connection;
  - the D8 body, first word only, any key order;
  - inactive / used_today / Athens midnight; byte-identical not-found;
  - D9; the authorizer;
  - D6 revocation; staff-role administrator and Application Password refused.
- `CustomerQrTest` (wp-customer, 6).
- `ClubRedemptionBackendTest::test_without_the_constant_no_qr_route_is_registered` (wp-enabled).
- `ClubMigrationsTest` (003 not approved by default; target 3 / 1 / invalid).

**Commerce (CI only).**
- `StaffQrCommerceTest` (wp-commerce-redeem): admin completion → member → QR → scan active + first name
  → redeem → used_today → Athens midnight → cancellation → inactive + redeem 409; an unpaid member scans
  inactive.
- `CustomerQrCommerceTest` (wp-commerce): the shipped customer route with the real constants.

**Mutants.**
- SQL runner: QS1–QS12, LR1–LR8.
- Membership runner: L27 (uncounted failure).
- WP runner, each with named killer tests:
  - QL1–QL14 (modes enabled / redemption);
  - QC1–QC7 (mode customer);
  - MG37–MG42 (migration target).
- Equivalent, not listed:
  - the lock-only and row-count-only store mutants (see above);
  - the backend's second disabled-member check (the service already refuses);
  - the revoke route's own `evc_manage_club` test alone, because the service re-checks the capability
    (the staff-role and Application Password refusals are NOT re-checked: QL13, QL14).

### Not done here

- The staff UI wiring (STAGE-05D).
- Changes to the customer bundle (05D: the storage module above).
- Phone lookup; v2 prepared redemption.
- Applying 003 anywhere real.
- `GET /session` still reports `features.qr_lookup: false` (05D).
