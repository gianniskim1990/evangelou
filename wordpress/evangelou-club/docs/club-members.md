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
φτιάχνεται ο αριθμός μέλους, ο αριθμός φτιάχνεται αυτόματα στον επόμενο έλεγχο ανάκτησης (κάθε
15 λεπτά, WP-Cron) ή στην επόμενη πληρωμή του πελάτη. Δεν χρειάζεται καμία ενέργεια από το
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
  CUSTOMER with a verified, currently eligible membership (UI-04F rule, unchanged). It has its own Club
  DB connection, opened lazily; it is itself the sync's clock (the confirmer's `$now`).

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

**Failure recovery (decision).** No new state on the order. `EVC_Wc_Completion_Recovery::run()` (the
existing 15-minute WP-Cron step) now ends with `EVC_Club_Member_Sync::reconcile()`: customers whose
LATEST recorded paid period (`evc_pay_member_heads` → `evc_pay_periods.end_utc > now`) has not ended
and who have no `evc_members` row, newest payments first, at most 20 per run; each is synced again
(same rules). It runs even when the open-order scan fails. The customer's next confirmation also syncs.
Chosen over keeping the order's follow-up index open (that would have changed the STAGE-03D
`recorded` = closed contract and its retry counting) and over a new cron hook (more surface). A frozen
early renewal (paid while a cancelled period still runs) is `not_eligible` until the new period
starts; the scan picks it up then. Customers whose latest period ended are never scanned. Residual:
permanently refused payers (privileged accounts, deleted WordPress users) are re-tried every run;
beyond 20 such customers with running periods, older ones could starve newer ones (not expected).

**Idempotency / concurrency.** Only the database unique keys decide (no check-then-insert); a loser
gets the duplicate-key exception and re-reads the winner (`already_member`). Proven with interleaved
connections and with separate processes released by a time barrier (integration
`SqlMemberIdentityStoreTest`, `tests/bin/member-sync-worker.php`).

**Audit / logs / PII.** `evc_audit_events` `club_member_sync`: every confirmation-triggered attempt and
every reconcile attempt that created a row; `member_id` = the row id when there is a row, else
`details.customer` = numeric WordPress user id; `details.trigger` = `confirmation` | `reconcile`.
Never names, e-mail addresses, phones, payment data or exception texts. Logs: a fixed reason and an
exception class only. `evc_members` holds exactly the 001 columns.

**Gating.** No new constant. `EVC_Club_Member_Sync` is a STAGE-03D guarded class: constructed only in
`EVC_Wc_Completion_Plugin::wire()` (behind `EVC_CLUB_WC_COMPLETION_ENABLED` and the commerce + Club DB
configuration); its constructor refuses while the constant is off. Packager (`app/scripts/php-guards.mjs`,
STAGE-05B rule set + fixtures): `EVC_Member_Sync_Service`, `EVC_Sql_Member_Identity_Store` and
`EVC_Wp_Club_Account_Directory` are constructed only in `EVC_Club_Member_Sync::service()`, once each;
only the store file implements `EVC_Member_Identity_Store` and only the member-sync file implements
`EVC_Wp_Account_Directory` (no interface extends them); names allowlisted. The bans on the account
status service, the QR coordinator, the status / QR-delivery policies and every QR / lookup / v2
construction are unchanged.

**Tests.** Integration (local + CI): `SqlMemberIdentityStoreTest` (12). WordPress `wp-common`:
`ClubAccountDirectoryTest` (5). Unit: `WcCompletionConstructionTest::test_the_member_sync_refuses_while_off`.
Commerce, CI only (real WooCommerce / PMPro, HPOS and posts): `MemberSyncCommerceTest` (completion →
one row + audit; replays / double submit / early renewal → one row; member DB down → payment stays
recorded, recovery run creates the row, ended periods never scanned; three instances → one row; log
secret-free; unpaid / unattested / refused → no row; privileged payer → no row; cancellation keeps the
row and freezes the card; issuer and legacy confirmation paths) and `MemberSyncRedeemTest` in
`wp-commerce-redeem` (admin completion → member → redeem 200, same Athens day 409, next day 200;
unconfirmed orders → no member, unknown id 404; cancellation → 409 membership_inactive).
Mutants: MS1–MS5 (SQL store, provenance-SQL runner), MS6 (construction guard, membership runner),
MS10–MS13 (account directory, WP runner mode `disabled`), MS15–MS29 (commerce / commerce_redeem, CI only).
