# Μέλη του Club μετά από επιβεβαιωμένη πληρωμή (STAGE-05B)

**Κατάσταση:** ανάπτυξη μόνο. ΔΕΝ είναι ενεργό πουθενά. Δεν υπάρχει νέα σταθερά: λειτουργεί μόνο
όταν είναι ενεργή η λειτουργία «Ολοκληρωμένη = πληρωμή» (`EVC_CLUB_WC_COMPLETION_ENABLED`), η οποία
χρειάζεται ξεχωριστή έγκριση του ιδιοκτήτη. Χωρίς αυτήν δεν αλλάζει τίποτα.

## Για τον τεχνικό / τον ιδιοκτήτη (ελληνικά)

### Τι κάνει

Για να πάρει κάποιος τον δωρεάν καφέ, το ταμείο χρειάζεται τον **αριθμό μέλους** του (ένας τυχαίος
κωδικός `mem_…`, χωρίς όνομα, τηλέφωνο ή e-mail). Μέχρι τώρα κανένα μέρος του plugin δεν τον
δημιουργούσε. Τώρα:

1. Όταν το Club **καταγράψει** μια πληρωμή συνδρομής (το προσωπικό του καταστήματος έβαλε
   «Ολοκληρωμένη» από την οθόνη διαχείρισης του WooCommerce και το Club την έγραψε), ο πελάτης
   παίρνει **έναν** αριθμό μέλους στη βάση του Club (πίνακας `evc_members`).
2. Ο αριθμός μέλους **δεν δίνει μόνος του καφέ**. Σε κάθε «Δωρεάν καφές» το plugin ελέγχει ξανά αν
   υπάρχει καταγεγραμμένη, πληρωμένη και ενεργή συνδρομή (ίδιοι κανόνες με πριν: μία ημέρα Αθήνας,
   ακύρωση / επιστροφή χρημάτων = παγωμένη κάρτα).
3. Ο ίδιος πελάτης έχει πάντα **τον ίδιο** αριθμό: ανανέωση, δεύτερο κλικ, επανάληψη, δύο
   ταυτόχρονες ενέργειες → πάλι ένας αριθμός. Ακύρωση ή επιστροφή χρημάτων **δεν** σβήνει τον αριθμό·
   απλώς δεν δίνεται καφές.
4. Λογαριασμοί προσωπικού, διαχειριστών ή άλλοι με δικαιώματα (π.χ. συντάκτες, διαχειριστές
   καταστήματος, ο κοινός λογαριασμός του ταμείου) **δεν** γίνονται ποτέ μέλη, ακόμη κι αν πληρώσουν.
   Μέλη γίνονται μόνο απλοί πελάτες (ρόλος «Πελάτης» ή «Συνδρομητής» χωρίς άλλα δικαιώματα).
5. Δεν δημιουργείται QR και δεν στέλνεται τίποτα στο FluentCRM.

### Αν κάτι αποτύχει

Η πληρωμή **μένει πάντα** καταγεγραμμένη, όπως πριν. Αν η βάση του Club δεν απαντά τη στιγμή που
φτιάχνεται ο αριθμός μέλους, ο αριθμός φτιάχνεται αυτόματα σε έναν από τους επόμενους ελέγχους
ανάκτησης (κάθε 15 λεπτά, WP-Cron· 20 πελάτες ανά έλεγχο, με τη σειρά, ώστε να φτάνει σε όλους) ή στην
επόμενη πληρωμή του πελάτη. Δεν χρειάζεται καμία ενέργεια από το
κατάστημα. Στο αρχείο σφαλμάτων του PHP γράφεται μία γραμμή χωρίς ονόματα ή κωδικούς, π.χ.

    Evangelou Club: member sync unavailable (EVC_Db_Exception).

| Αιτία στη γραμμή | Τι σημαίνει |
|---|---|
| `unavailable` | Η βάση του Club δεν απαντούσε· θα ξαναδοκιμαστεί αυτόματα. |
| `reconcile_unavailable` | Ο έλεγχος ανάκτησης δεν μπόρεσε να διαβάσει τη βάση· θα ξαναδοκιμαστεί. |
| `member_sync_membership` | Δεν διαβάστηκαν τα στοιχεία συνδρομής (WooCommerce / PMPro)· θα ξαναδοκιμαστεί. |
| `member_sync_account` | Δεν διαβάστηκε ο λογαριασμός WordPress του πελάτη· θα ξαναδοκιμαστεί. |
| `member_sync_sync` | Απρόσμενο σφάλμα· θα ξαναδοκιμαστεί (αν επαναλαμβάνεται, ειδοποιήστε τον προγραμματιστή). |
| `member_sync_commit_unknown` | Η βάση δεν επιβεβαίωσε την εγγραφή· ο επόμενος έλεγχος το διορθώνει χωρίς διπλό μέλος. |
| `audit_failed` | Το ημερολόγιο ελέγχου δεν γράφτηκε (το μέλος γράφτηκε κανονικά). |

Αν μια γραμμή επαναλαμβάνεται για ώρες, ειδοποιήστε τον προγραμματιστή.

### Τι χρειάζεται για να δουλέψει (ΜΟΝΟ με έγκριση του ιδιοκτήτη, πρώτα στο staging)

Ό,τι ήδη χρειάζεται η λειτουργία «Ολοκληρωμένη = πληρωμή» (STAGE-03D) και οι πίνακες 001 + 002 στη
βάση του Club. **Καμία νέα σταθερά, κανένας νέος πίνακας.** Ο χρήστης της βάσης του Club πρέπει να
μπορεί να κάνει `SELECT` / `INSERT` στον πίνακα `evc_members` (όπως ήδη στους πίνακες πληρωμών).

---

## Developer notes (English)

**Scope (owner-approved roadmap step, development only):** when a Club membership payment is
recorded, the paying WordPress user gets exactly one `evc_members` row (migration 001, unchanged; no
new migration). Not in scope: QR tokens, lookup route, staff UI wiring, v2 redemption, FluentCRM,
account status changes, enabling anything.

**Pieces.**
- `EVC_Sql_Member_Identity_Store` (`includes/class-evc-sql-member-identity-store.php`): the only
  shipped `EVC_Member_Identity_Store`. `create()` = one INSERT in its own transaction (status
  `enabled`, created = updated = the sync clock). Uniqueness comes from `uq_members_wp_user` /
  `uq_members_public_id` (duplicate → `EVC_Db_Duplicate_Key_Exception`, the sync re-reads); a lost
  COMMIT → `EVC_Db_Commit_Unknown_Exception`. Rows read back only if exactly well-formed (else
  `UnexpectedValueException`, fail closed). 001 has no `status_version` / reason columns: rows read
  as version 1 / no reason, and `set_status()` throws `LogicException` (nothing shipped calls it;
  `EVC_Member_Account_Service` stays packager-banned).
- `EVC_Wp_Club_Account_Directory` (`includes/customer/class-evc-club-member-sync.php`): CUSTOMER only
  for an existing, non-super-admin user whose roles are all in {`customer`, `subscriber`}, without the
  Club staff role and without any of `edit_posts`, `edit_shop_orders`, `manage_woocommerce`,
  `manage_options`, `list_users`, `edit_users`, `evc_confirm_bank_payment`, `evc_manage_club`.
  Everything else that exists is RESTRICTED; absent users are MISSING.
- `EVC_Club_Member_Sync` (same file): runs the reviewed UI-04F `EVC_Member_Sync_Service` (built ONLY in
  its `service()`) over the SQL store, the directory and the SAME membership source as the STAGE-05A
  redeem route (`EVC_Pmpro_Membership_Adapter` over `EVC_Wp_Commerce_Pmpro_Reader` +
  `EVC_Store_Club_Payment_Provenance` / `EVC_Sql_Provenance_Store`). A row is created only for a
  CUSTOMER with a verified, currently eligible membership (UI-04F rule, unchanged). It uses the confirmer's
  lazily opened, request-scoped, shared audit connection (see Connection below); it is itself the sync's clock (the confirmer's `$now`).

**Where sync happens (decision).** After the confirmation is final and the member lock released:
`EVC_Wc_Completion_Confirmer::confirm()` and `::recover()` call `sync_member()` only for
`recorded` / `already_recorded`; the result is stored in `$result->member` and never changes the
outcome, follow-up index, notes or audit of the confirmation. The manager BACS paths
(`EVC_Bacs_Payment_Confirmation::confirm()` when `funded()`, `EVC_Bacs_Payment_Finalizer::finalize()`
and `EVC_Bacs_Payment_Issuer::issue()` through the finalizer's `sync_member()`, for `recorded` /
`already_recorded`) take an optional `EVC_Club_Member_Sync` constructor argument (default null); they
are still never constructed by shipped code. Why outside the lock and after the outcome: a member row
is not part of the payment; the payment transaction and the Club ledger must never wait for, roll back
with or be reported differently because of it.

**Latency (honest bound).** The member step runs synchronously in the same admin «Ολοκληρωμένη»
request (and in the recovery run), after the confirmation is final, so it does add time to that
request: a few SELECTs on WordPress (account, PMPro rows) and on the Club DB (member row, recorded
provenance), at most one INSERT and one audit INSERT. On the shared, already open connection that is
milliseconds; worst cases are bounded by the Club DB's own limits — a connect timeout of 5 s if the
audit connection was not open yet (PDO `ATTR_TIMEOUT`), and a row-lock wait of at most 5 s for the
INSERT (`innodb_lock_wait_timeout = 5`, set on the connection by the provenance reads). The recovery
run does at most 20 member steps per tick.

**Connection (decision).** The member step uses the confirmer's lazily opened, request-scoped audit
connection (`EVC_Wc_Completion_Plugin::wire()`), so a request opens no extra Club DB connection (a
dedicated connection per wiring exhausted `max_connections` in the CI commerce suite). There is NO
reconnect inside a request: if that connection breaks, the remaining member steps of the request
fail (`unavailable` / `server_error`) and the next recovery run (a new request) retries. The only
transaction the member step opens on it is the one-INSERT `create()`, always committed or rolled back
before returning (tested with a COMMIT that fails on the shared connection: the next order's audit row
persists and no transaction is left open). Side effect, accepted: the provenance reads set
`SESSION innodb_lock_wait_timeout = 5` on the shared connection; the confirmer's audit INSERTs take no
contended row locks, so it changes nothing for them. The provenance store's `$reconnect` (contract: a
NEW connection, used only to recover a failed provenance COMMIT) throws in the member step, which never
writes provenance.

**Failure recovery (decision).** No new state on the order. `EVC_Wc_Completion_Recovery::run()` (the
existing 15-minute WP-Cron step) now ends with `EVC_Club_Member_Sync::reconcile()`: candidates are the
customers whose LATEST recorded paid period (`evc_pay_member_heads` → `evc_pay_periods.end_utc > now`)
has not ended and who have no `evc_members` row; each is synced again (same rules). It runs even when
the open-order scan fails. The customer's next confirmation also syncs. The scan is a keyset cursor in
ascending `(updated_at_utc, wp_user_id)` order, kept in the WordPress option
`evc_club_member_sync_cursor` (not autoloaded, `<instant>|<user id>`), at most 20 per run; a short batch
wraps the cursor to the start. So EVERY candidate is reached within ceil(candidates / 20) + 1 runs, no
matter how many hopeless candidates precede or follow it. Hopeless candidates are: refused / privileged
accounts, deleted WordPress users, and refunded / cancelled (or otherwise not eligible) customers who
never got a row while their period runs — they are re-tried once per full cycle, not every run.
(The first version scanned newest-first with no cursor, so 20 or more NEWER hopeless candidates would
have starved an older paying customer forever; fixed in the STAGE-05B review.) If the option cannot be
written, the next run restarts from the stored position (progress may stall but nothing is lost or
guessed). Chosen over keeping the order's follow-up index open (that would have changed the STAGE-03D
`recorded` = closed contract and its retry counting) and over a new cron hook (more surface). A frozen
early renewal (paid while a cancelled period still runs) is `not_eligible` until the new period
starts; the scan picks it up then. Customers whose latest period ended are never scanned.

**Idempotency / concurrency.** Only the database unique keys decide (no check-then-insert); a loser
gets the duplicate-key exception and re-reads the winner (`already_member`). Proven with interleaved
connections and with separate processes released by a time barrier (integration
`SqlMemberIdentityStoreTest`, `tests/bin/member-sync-worker.php`).

**Audit / logs / PII.** `evc_audit_events` `club_member_sync`: every confirmation-triggered attempt and
every reconcile attempt that created a row; `member_id` = the row id when there is a row, else
`details.customer` = numeric WordPress user id; `details.trigger` = `confirmation` | `reconcile`.
Never names, e-mail addresses, phones, payment data or exception texts. When the Club DB connection
cannot be obtained at all, NO `club_member_sync` row is written (only the log line). Logs: a fixed
reason and an exception class only. `evc_members` holds exactly the 001 columns.

**Gating.** No new constant. `EVC_Club_Member_Sync` is a STAGE-03D guarded class: constructed only in
`EVC_Wc_Completion_Plugin::wire()` (behind `EVC_CLUB_WC_COMPLETION_ENABLED` and the commerce + Club DB
configuration); its constructor refuses while the constant is off. Packager (`app/scripts/php-guards.mjs`,
STAGE-05B rule set + fixtures): `EVC_Member_Sync_Service`, `EVC_Sql_Member_Identity_Store` and
`EVC_Wp_Club_Account_Directory` are constructed only in `EVC_Club_Member_Sync::service()`, once each;
only the store file implements `EVC_Member_Identity_Store` and only the member-sync file implements
`EVC_Wp_Account_Directory` (no interface extends them); names allowlisted. The bans on the account
status service, the QR coordinator, the status / QR-delivery policies and every QR / lookup / v2
construction are unchanged. `EVC_Member_Sync_Service` and `EVC_Sql_Member_Identity_Store` do NOT check
the constant themselves: the only runtime gate is `EVC_Club_Member_Sync`'s constructor
(`EVC_Wc_Completion_Feature::assert_constructible`) plus the packager guard. The STAGE-03D name allowlist
(`GUARDED_NAME_FILES`) is per file, so `class-evc-bacs-payment-confirmation.php`, now on it, may name every
STAGE-03D guarded class; construction of all of them is still restricted to `wire()`.

**Tests.** Integration (local + CI): `SqlMemberIdentityStoreTest` (12). WordPress `wp-common`:
`ClubAccountDirectoryTest` (6; the privileged capabilities are reached through a direct user grant or a
customer / subscriber role that gains one, both tested against a literal list). Unit: `WcCompletionConstructionTest::test_the_member_sync_refuses_while_off`.
Commerce, CI only (real WooCommerce / PMPro, HPOS and posts): `MemberSyncCommerceTest` (13: completion →
one row + audit; replays / double submit / early renewal → one row; member DB down → payment stays
recorded, recovery run creates the row, ended periods never scanned; three instances → one row; log
secret-free; unpaid / unattested / refused → no row; privileged payer → no row; cancellation keeps the
row and freezes the card; a claimed but deferred completion runs no member step; issuer and legacy
confirmation paths; the recovery scan reaches an older eligible customer behind 22 older + 22 newer
hopeless candidates with the batch limit, ascending order and wrap pinned; a COMMIT failing inside
`create()` on the shared connection leaves the next order's audit row intact) and `MemberSyncRedeemTest` in
`wp-commerce-redeem` (admin completion → member → redeem 200, same Athens day 409, next day 200;
unconfirmed orders → no member, unknown id 404; cancellation → 409 membership_inactive).
Mutants: MS1–MS5 (SQL store, provenance-SQL runner), MS6 (construction guard, membership runner),
MS10–MS13 + MS37 (account directory, WP runner mode `disabled`), MS15–MS36 (commerce / commerce_redeem,
CI only; MS30–MS36: scan order, cursor advance / read / wrap, batch clamp, LIMIT, reconcile audit condition).
No reconnect mutant: the reconnect was removed.
