# QR μέλους: αποθήκη κωδικών στη βάση του Club (STAGE-05C)

**Κατάσταση:** ανάπτυξη μόνο. ΔΕΝ είναι ενεργό πουθενά και **δεν συνδέεται με τίποτα**. Δεν
υπάρχει νέα σταθερά, νέα σελίδα, νέα διαδρομή REST ή νέος πίνακας. Το plugin συμπεριφέρεται ακριβώς
όπως πριν.

## Για τον τεχνικό / τον ιδιοκτήτη (ελληνικά)

### Τι προστέθηκε

Μόνο το «ντουλάπι» όπου θα φυλάσσονται οι κωδικοί QR των μελών, στον πίνακα `evc_qr_tokens` που
υπάρχει ήδη από τη μετάβαση 001 (καμία νέα μετάβαση):

- Ο κωδικός QR είναι ένας τυχαίος αριθμός χωρίς όνομα, τηλέφωνο ή e-mail.
- Στη βάση **δεν** γράφεται ποτέ ο ίδιος ο κωδικός, μόνο ένα «αποτύπωμά» του (SHA-256). Αν κάποιος
  δει τη βάση, δεν μπορεί να φτιάξει από εκεί έναν QR που δουλεύει.
- Κάθε μέλος έχει το πολύ **έναν** ενεργό QR. Όταν βγαίνει νέος, ο παλιός ακυρώνεται στην ίδια
  κίνηση. Αν δύο συσκευές ζητήσουν νέο QR την ίδια στιγμή, κερδίζει μία· η άλλη δεν παίρνει QR.
- Ένας QR **δεν δίνει μόνος του καφέ**. Λέει μόνο «ποιο μέλος είναι αυτό». Ο δωρεάν καφές
  ελέγχεται πάντα ξανά από το ταμείο (πληρωμένη και καταγεγραμμένη συνδρομή, μία φορά ανά ημέρα
  Αθήνας, ακύρωση / επιστροφή χρημάτων = παγωμένη κάρτα). Αυτό ελέγχεται με τεστ.

### Τι ΔΕΝ έγινε ακόμα και γιατί

Η οθόνη QR του πελάτη και η σάρωση από το ταμείο **δεν** φτιάχτηκαν, γιατί χρειάζονται πρώτα
αποφάσεις του ιδιοκτήτη (βλ. «Ερωτήσεις προς τον ιδιοκτήτη» παρακάτω): πόσο ισχύει ένας QR, αν
αλλάζει, αν μια φωτογραφία οθόνης (screenshot) του QR πρέπει να δουλεύει, και τι βλέπει ο υπάλληλος
για τον πελάτη.

### Δικαιώματα στη βάση (όταν ενεργοποιηθεί, όχι τώρα)

Ο χρήστης της βάσης του Club θα χρειάζεται: `SELECT` στο `evc_members`, και `SELECT` / `INSERT` /
`UPDATE` (στήλες `status`, `revoked_at_utc`) στο `evc_qr_tokens`. Ποτέ `DELETE`.

### Ερωτήσεις προς τον ιδιοκτήτη (ανοιχτές)

1. **Πόσο ισχύει ένας QR;** (α) μέχρι να ζητήσει ο πελάτης νέο ή να τον ακυρώσει το κατάστημα·
   (β) Χ ημέρες· (γ) λίγα λεπτά (κωδικός που αλλάζει συνεχώς στην οθόνη).
2. **Αλλάζει ο QR;** Μόνο όταν το ζητήσει ο πελάτης («νέος QR» στο προφίλ), ή κάθε φορά που ανοίγει
   την κάρτα;
3. **Να δουλεύει μια φωτογραφία οθόνης του QR;** Αν ναι, ένας φίλος του μέλους με τη φωτογραφία θα
   μπορεί να πάρει τον καφέ της ημέρας στη θέση του.
4. **Τι βλέπει ο υπάλληλος μετά τη σάρωση;** Μόνο «ενεργό / όχι ενεργό / ο καφές πάρθηκε σήμερα», ή
   και το μικρό όνομα του πελάτη (για να επιβεβαιώσει ότι είναι ο ίδιος);
5. **Όριο λάθος σαρώσεων** (π.χ. πόσες αποτυχημένες σαρώσεις ανά λεπτό από ένα tablet πριν σταματήσει
   προσωρινά). Τεχνικά χρειάζεται και ένας μυστικός κωδικός στο `wp-config.php` από τον τεχνικό.

---

## Developer notes (English)

**Scope (development only).** `EVC_Sql_Qr_Token_Store` (`includes/class-evc-sql-qr-token-store.php`),
the only shipped `EVC_Qr_Token_Store` (the UI-04C interface in `includes/class-evc-member-lookup.php`),
over `evc_qr_tokens` + `evc_members` from migration 001 (unchanged; no new table, column or
migration). Built and tested only. The customer QR route and the staff lookup route were NOT built:
they need owner decisions (token lifetime, rotation trigger, screenshot policy, what staff see,
lookup rate limits) and a server secret for the reviewed lookup proof / phone pseudonyms; see
`docs/member-lookup.md` "Open decisions". No construction site exists.

**Storage.** Digests only: `token_hash` = `EVC_Qr_Token::digest()` (SHA-256 hex of
`evc_` + 48 hex). The store accepts only 64-hex digests (a plaintext token passed as a digest is
`InvalidArgumentException`, mutant QS10) and never returns a digest from a lookup:
`find_token_by_hash()` answers `{member_id, status, issued_at_utc, revoked_at_utc}`;
`active_token_hash()` is used only by `EVC_Qr_Token_Service` for its compare-and-set and is never
returned to a caller. The hash-only design means the plaintext exists only at issuance
(`EVC_Qr_Token_Result::issued`, "returned ONCE"); a customer screen that shows the QR again later
must therefore either keep it on the device or rotate, which is exactly the open owner decision.

**Rotation / revocation (one READ COMMITTED transaction each).**
1. `SELECT token_hash FROM evc_qr_tokens WHERE active_member_id = ? FOR UPDATE` (the unique key on the
   stored generated column, so at most one row);
2. compare with the expected digest (`hash_equals`; none vs some is a mismatch) → else
   `EVC_Qr_Rotation_Conflict`;
3. `UPDATE ... SET status = 'revoked', revoked_at_utc = ? WHERE active_member_id = ? AND token_hash = ?
   AND status = 'active'`, affected rows must be exactly 1;
4. (rotate) `INSERT` the new active row.

Outcomes: a mismatch → `EVC_Qr_Rotation_Conflict`; a duplicate on `uq_qr_tokens_hash` or
`uq_qr_tokens_one_active` (a concurrent first issuance) → `EVC_Db_Duplicate_Key_Exception`, rolled
back (the revocation too); deadlock / lock-wait timeout → rolled back and reported as
`EVC_Qr_Rotation_Conflict` (a concurrent writer; the service answers `concurrent_change`, no token);
lost COMMIT → `EVC_Db_Commit_Unknown_Exception` (`outcome_unknown`, the service never returns that
token; recovery = issue again). `evc_members` is never locked, so no UPDATE grant on it is needed.
Defence in depth, documented as equivalent mutants: the row lock, the affected-row check, the digest
compare and the unique key each stop a late writer on their own, so removing only the lock or only
the row-count check is not observable; the compare (QS2/QS3) and the key (QS1) are.

**Reads fail closed.** A member row that is not exactly well-formed, or a token row whose status and
`revoked_at_utc` disagree (active with a time, revoked without one), throws
`UnexpectedValueException` (the services turn it into `server_error` / `unavailable`).

**Business rules unchanged.** A QR is a lookup handle only (`grants_entitlement()` false). Tested on a
real database: a member found by QR whose card is cancelled / refunded / ends exactly now (exclusive
end) / unverified still gets `membership_inactive` from `EVC_Redemption_Service`; after one coffee, a
second scan + redeem the same Athens day is `benefit_already_redeemed`; rotated, revoked, too-old
(injected `EVC_Qr_Token_Policy`, none approved) and disabled-member tokens are `not_found`.

**Logs.** The store logs nothing. The services report stage + exception class only (UI-04C).

**Gating / packager (`app/scripts/php-guards.mjs`, STAGE-05C rule set `qrStoreProblemsFor` + 18
fixtures).** The blanket string ban on `implements EVC_Qr_Token_Store` is replaced by: only the
store file implements it, and only the class `EVC_Sql_Qr_Token_Store` (no second or anonymous
class, no interface extends it); `EVC_Sql_Qr_Token_Store` is constructed nowhere (not in its own file,
no `new self|static|parent` there) and is named only in its own file. Every other QR / lookup / v2
ban is unchanged (`new EVC_Qr_Token_Service`, `new EVC_Member_Lookup_Service`,
`new EVC_Lookup_Key_Ring`, `new EVC_Signed_Member_Context_Verifier`, the phone directory and rate
limiter implementations, the fakes). The package fails if the store file is missing.

**Tests.** Integration (local MariaDB 10.11 + CI engines): `SqlQrTokenStoreTest` (20): reads and
fail-closed rows; first issuance + rotation keep one active digest and no plaintext; stale / missing
expectations change nothing; CAS revocation; a digest collision rolls back the revocation too; the
unique key alone refuses a second active row; invalid input before SQL; another connection rotating
in between, and a writer behind a committed winner, lose; deadlock → conflict, rolled back; lost
COMMIT before / after; every operation under exactly `RUNTIME_GRANTS` (DELETE, digest / member
changes and other tables refused); the UI-04C token service (member self only, disabled member, concurrent
rotation → `concurrent_change` without token) and lookup service (rotated / revoked / expired /
disabled → `not_found`, identity-only result) over the SQL store; frozen / expired cards never
redeem; a multi-process race (`tests/bin/qr-token-worker.php`, 10 rounds × 6 workers, first
issuance and rotation) leaves exactly one active row and exactly one winner. WordPress:
`RedemptionSourceOnTest::test_no_lookup_or_qr_route_is_registered` (staff + redemption ON) and
`CustomerMeTest::test_no_qr_route_is_registered` (customer ON). Mutants QS1–QS10 in
`tests/mutation/run-provenance-sql-mutations.php` (`'command' => 'qr'`). The test class is `@group qr-store`, excluded from the engine
mutation runner `run-mutations.php` (none of its mutants touch this store; with it, the mysql:8.0
engine job exceeded its 25-minute timeout).

**Not done (needs owner decisions first).** Customer QR route / screen, staff lookup route, rate
limiter, phone directory, lookup keys and TTL, staff UI wiring (STAGE-05D).
