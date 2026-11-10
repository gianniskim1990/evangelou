# Go-live runbook (STAGE-08)

One place for the order of switching the Club on, what to check after each step, the pilot, the rollback and the
known risks. The details stay in the per-feature documents linked below; this file does not repeat them.

**Nothing here has been done.** Every flag is OFF by default. Each step is done by the technician with the owner's
approval: first on **staging**, and on **production** only after the owner approves the pilot.

| Feature | Document |
|---|---|
| Club database and migrations | [club-db-migrations.md](club-db-migrations.md) |
| Membership source (WooCommerce + PMPro, BACS) | [commerce-membership-source.md](commerce-membership-source.md) |
| Member area and member QR | [member-qr.md](member-qr.md), [club-members.md](club-members.md), [member-sync.md](member-sync.md) |
| Till (staff app) and redemption | [staff-redemption.md](staff-redemption.md), [member-lookup.md](member-lookup.md), [usual-coffee.md](usual-coffee.md) |
| In-store QR onboarding | [store-onboarding.md](store-onboarding.md) |
| FluentCRM tags | [fluentcrm-tags.md](fluentcrm-tags.md) |
| Admin pages (till account, readiness) | [admin-tools.md](admin-tools.md) |

## 1. Before anything: the open technician blockers

| # | Blocker | What closes it |
|---|---|---|
| T1 | **MySQL error 1044** (access denied to the Club database) seen on staging | **Reported fixed, to verify (2026-11-10):** the technician states the Club DB user has full access to the Club database. Verify on the **migrations status page** (Tools → «Evangelou Club — Βάση δεδομένων», with `EVC_CLUB_MIGRATIONS_ENABLED`): every separation and schema check passes, and the approved apply on staging succeeds. A green readiness row «Βάση δεδομένων του Club» proves only that a connection opens and SELECTs run; it does **not** prove the CREATE rights whose absence gave 1044, so it does not close T1. |
| T2 | **WordPress timezone setting**: Settings → General → Timezone is a fixed UTC+3, not `Europe/Athens` | **Open.** Settings → General → Timezone = «Athens» (Europe/Athens), after checking the impact on existing orders. Readiness: «Ζώνη ώρας του ιστότοπου: Εντάξει». This is the **WordPress** setting (`timezone_string`), which the Club's day boundary and the shop checks read. It is **separate from the MySQL server's timezone**: the technician reported (2026-11-10) that the MySQL server runs in Europe/Athens, which does **not** close T2. |
| T3 | **The exposed database passwords** | **Club DB: closed (2026-11-10)** — the owner reports that the technician rotated the exposed Club DB password. **WordPress DB: still to be confirmed** — the technician confirms that the WordPress database password was rotated too (or was never the exposed one), wp-config.php updated, the site and the readiness page work. |
| T4 | **Terms URL** (D13) | `define('EVC_CLUB_TERMS_URL', 'https://…/…')` with the existing Club terms page. |
| T5 | **FluentCRM tag IDs** | The six `EVC_CLUB_FLUENTCRM_TAG_*` constants with the real IDs and names ([fluentcrm-tags.md](fluentcrm-tags.md)). |
| T6 | **Checkout type**: Block Checkout or classic | Record which one the live site uses (step 6 below). Both are guarded; staging must use the same one. |
| T7 | **Migration 003** | **Staging: approved (owner decision D19, 2026-11-10)** — `define('EVC_CLUB_MIGRATIONS_TARGET', 3);` on staging only, after a backup. **Production:** 001, 002 and 003 each still need the owner's own approval. Without 003 the target stays 2 and the member QR / scan stay unavailable. |
| T8 | **The staging plugin build** | The plugin is already installed on the staging WordPress (using the staging Club DB), but it is an **earlier build**. The technician replaces it with the package built from the reviewed, CI-green commit before any step below. |

### Technician answers received 2026-11-10 (no secrets recorded here)

- **SFTP host:** `linuxzone146.grserver.gr` (port 22 on `evangelouprofiterole.gr` times out).
- **Club database access:** there is no phpMyAdmin for the Club DB; use **MySQL Workbench** to `linuxzone146.grserver.gr`.
- **The Club DB is the STAGING Club database** (confirmed 2026-11-10). The staging WordPress, with an earlier build of
  the plugin installed, uses it (T8).
- **Club DB user:** the technician states it "has full access" to its Club database (T1: reported fixed, verify on the
  migrations status page). The user name is not recorded in the repository.
- **Club DB password:** it had been the same as the plugin password previously shared in chat (exposed); the owner
  reports the technician has **rotated** it (T3 closed for the Club DB). The WordPress DB password rotation is still to
  be confirmed (T3).
- **Timezone:** the **MySQL server** runs in `Europe/Athens`. The **WordPress setting** (Settings → General) is still a
  fixed UTC+3 until the technician changes it: T2 stays open.
- **Migration 003:** approved for staging only (D19).
- **The developer has no server access:** no SFTP or MySQL connection is made from the development side; every step is
  done by the technician.

## 2. Enable order, and what to check after each step

Make one change at a time, open **Evangelou Club → Ετοιμότητα** after each, and continue only when that step's rows
read «Εντάξει» (or the «manual» items are confirmed).

| Step | wp-config.php | Then check |
|---|---|---|
| 0 | Backups of both databases. | Restore tested. |
| 1 | `define('EVC_CLUB_ADMIN_TOOLS_ENABLED', true);` | The «Evangelou Club» menu appears for administrators only. Readiness shows the current state. |
| 2 | The `EVC_CLUB_DB_*` constants; `define('EVC_CLUB_MIGRATIONS_ENABLED', true);`; on **staging** also `define('EVC_CLUB_MIGRATIONS_TARGET', 3);` (D19) | Tools → «Evangelou Club — Βάση δεδομένων»: all checks green (this also verifies T1); apply 001 + 002 (approved for staging on 2026-10-27) and, on staging, 003 (D19, 2026-11-10). **Production:** 001, 002 and 003 each need the owner's own approval. Then remove `EVC_CLUB_MIGRATIONS_ENABLED`. Readiness: «Βάση δεδομένων του Club» and «Έκδοση σχήματος» «Εντάξει» (a «Δεν διαβάζεται» schema row means the migrations table could not be read: go back to the migrations page checks). |
| 3 | `EVC_CLUB_MEMBERSHIP_SOURCE`, `EVC_CLUB_PMPRO_LEVEL_ID`, `EVC_CLUB_WC_PRODUCT_ID`, `EVC_CLUB_EVIDENCE_KEY`, `EVC_CLUB_PAYMENT_PROVENANCE = 'club_db_v1'` | Readiness «Κατάστημα»: all «Εντάξει» (pinned versions, product 20.00 €, sold individually, one-month level, Athens clock, BACS on), **including «Καταγραφή πληρωμών στη βάση του Club (EVC_CLUB_PAYMENT_PROVENANCE)»**: «Εντάξει» only for the exact `'club_db_v1'`; «Λάθος» or «Λείπει» means payment confirmation, the member QR and redemption stay closed. |
| 4 | `define('EVC_CLUB_WC_COMPLETION_ENABLED', true);` | One test order by bank transfer, marked «Ολοκληρωμένη» by the shop: the member reads active ([commerce-membership-source.md](commerce-membership-source.md)). |
| 5 | `define('EVC_CLUB_CUSTOMER_ENABLED', true);` and `EVC_CLUB_LOOKUP_KEY` (a random secret of at least 32 bytes, for the QR scan) | Readiness «Κλειδί σκαναρίσματος QR (EVC_CLUB_LOOKUP_KEY)»: «Εντάξει» (the value is never shown). `/club-member/` shows the test member as active; the QR appears only after 003. |
| 6 | `define('EVC_CLUB_STAFF_ENABLED', true);` `define('EVC_CLUB_REDEMPTION_SOURCE', 'club_db_v1');` | Create the shared till account (Evangelou Club → Λογαριασμός ταμείου, D18); sign the tablet in at `/club-admin/`; redeem one coffee for the test member; a second one the same day is refused. |
| 7 | `EVC_CLUB_TERMS_URL`; `define('EVC_CLUB_ONBOARDING_ENABLED', true);` | Record the checkout type: edit the WooCommerce checkout page; a «Checkout» block = Block Checkout, `[woocommerce_checkout]` or «Classic Checkout» = classic. Then the staging checks of [store-onboarding.md](store-onboarding.md) (REMOTE_ADDR, FluentCRM settings, page cache, BACS-only checkout). |
| 8 | The six tag constants; `define('EVC_CLUB_FLUENTCRM_SYNC_ENABLED', true);` | Readiness «Ετικέτες FluentCRM: Εντάξει» (verified); Tools → «Evangelou Club — FluentCRM» shows counts only. |

## 3. Pilot plan

1. **Staging first**: steps 0–8 with test accounts and test bank transfers, until everything reads «Εντάξει».
2. **Production only with the owner's written approval**, the same steps in the same order.
3. **A small group** of real members (for example 10–20 regulars) for **1–2 weeks**, before the poster goes up for
   everyone.
4. **Every day** during the pilot, the owner or the technician looks at:
   - the readiness page (anything not «Εντάξει» that was before);
   - admin notices: the hourly new-account limit, the failure-row ceiling, FluentCRM problems;
   - customer reports of «Η εγγραφή δεν είναι δυνατή αυτή τη στιγμή» (`try_later`) or «Πολλές προσπάθειες»;
   - failed redemptions at the till (the staff app's errors, and members who could not get their coffee);
   - WooCommerce orders of the Club product waiting in «Σε αναμονή» (two for one customer: confirm one, cancel the
     other);
   - the FluentCRM counts on Tools → «Evangelou Club — FluentCRM» (no errors, numbers that match the members).
5. End of pilot: the owner decides to continue, change limits, or roll back.

## 4. Rollback (per feature: remove its line from wp-config.php)

| Feature off | What stops at once | What stays |
|---|---|---|
| FluentCRM sync | No more tag changes; no FluentCRM call. | Tags already set stay on the contacts; the counts option stays. |
| Onboarding | The join page, the poster and the join routes are gone; the cart / checkout rules stop. | Accounts created stay; the terms acceptance stays on the user; counter rows `evc_join_rl_*` stay in `wp_options` until deleted by hand (they hold only counts and times). |
| Staff + redemption | The till app's routes answer 404 (staff off) or 503 (redemption source off). | The shared account, the redemption ledger and the audit in the Club database. |
| Customer | `/club-member/` and its routes are gone. | Members, QR tokens and history in the Club database. |
| WooCommerce completion | «Ολοκληρωμένη» no longer confirms Club payments; the 15-minute recovery stops (a leftover cron event finds no handler and does nothing). | Orders, recorded payments and markers. An order marked «Ολοκληρωμένη» while it was off is NOT a Club confirmation: ask the developer before turning it back on ([commerce-membership-source.md](commerce-membership-source.md)). |
| Commerce source constants | Memberships read «unavailable»: no redemption, no confirmation. | Everything stored. |
| Migrations page | The page is gone. | The tables (never dropped automatically). |
| Admin pages | The «Evangelou Club» menu is gone. | The till account, its audit and last-sign-in time. |

Turning a flag back on resumes from the stored state; nothing is lost by turning a flag off.

## 5. Known risks

Each with the owner decision that accepted it or the mitigation in place.

| # | Risk | Decision / mitigation |
|---|---|---|
| K1 | A **shared mobile /48**: 30 failed sign-ups in 5 minutes from one carrier /48 block the whole /48 for 5 minutes (429), including unrelated customers. | Coordinator decision (STAGE-07 round 4, with N1). **Monitoring:** during the pilot ask the till to note any customer who sees «Πολλές προσπάθειες»; the technician can count `evc_join_rl_m_%` rows. The limit is the code constant `MAX_NETWORK_FAILURES`. |
| K2 | The **total sign-up cap** (100 per hour) can be used to stop QR sign-up for an hour. | Kept blocking on purpose; one log line and an admin notice per hour; the technician tunes `EVC_CLUB_JOIN_MAX_PER_HOUR` ([store-onboarding.md](store-onboarding.md)). |
| K3 | **Enumeration and pre-registration** of e-mails at sign-up. | Owner decision D14 (no e-mail confirmation); recovery by password reset; a squatter's order stays unpaid and unconfirmed. |
| K4 | **REMOTE_ADDR behind a proxy/CDN** would make all customers one client. | Go-live check (readiness «manual» + store-onboarding checklist). |
| K5 | **Two checkouts at the same instant** (two devices) can leave two unpaid Club orders. | Accepted (STAGE-07 R6): the shop's manual confirmation is the final gate; one transfer never funds two orders; the Greek operator note says to cancel the other. |
| K6 | **The failure-row ceiling**: a flood from many addresses stops counting failures of NEW clients for up to 5 minutes. | Sign-up is never refused because of it (STAGE-07 B1); admin notice; rows expire after 5 minutes. The expired-row cleanup runs at most once per request. |
| K7 | **The till password**: successful re-logins are not limited; anyone who knows it can log in again. | Owner decision «Όχι, μένει έτσι» (2026-11-04); change the password when staff leave (D18 operator note). |
| K8 | **The shared till account** (D18): redemptions are not attributed to a person. | Owner decision D18. |
| K9 | **The member QR token in the browser** (localStorage) can be read by any script on the site. | Owner decision «Ναι, να τον θυμάται» (2026-11-04); one coffee per Athens day and the staff's first-name check limit the damage ([member-qr.md](member-qr.md)). |
| K10 | **FluentCRM**: two contacts on the same WordPress user cannot be detected through the documented API. | Documented residual risk; a mismatching e-mail is refused ([fluentcrm-tags.md](fluentcrm-tags.md)). |
| K11 | **The evidence key cannot be rotated**: changing `EVC_CLUB_EVIDENCE_KEY` invalidates stored payment intents (recovery stops for a manager, never accepts). | Documented; never change it after go-live without a developer ([commerce-membership-source.md](commerce-membership-source.md)). |
| K12 | **Month-end dates**: a calendar month after 31 January ends on 28 February, and the next on 28 March. | Owner decision D5 (calendar-month clamp). |
| K13 | **A tablet that does not confirm sign-out** keeps its server session until the 30-minute inactivity limit. | Bounded by the session policy; «Αποσύνδεση από όλες τις συσκευές» ends it at once. |
| K14 | **A page cache** could serve the join page to the wrong visitor. | `DONOTCACHEPAGE` + no-cache headers; readiness lists page-cache plugins; exclude `/club/join/` and `/club-member/` by hand if the host cache ignores them. |

## 6. Shipped-code TODO / FIXME

A search of the shipped plugin (`includes/`, `evangelou-club.php`) and the app sources (`app/src`) on 2026-11-06 found
**no** `TODO`, `FIXME`, `XXX` or `HACK` markers.

## Ελληνικά — για τον ιδιοκτήτη και τον τεχνικό

**Τι είναι αυτό.** Ο οδηγός για να ανοίξει το Club, βήμα βήμα. Τίποτα δεν έχει ενεργοποιηθεί ακόμη. Κάθε βήμα το
κάνει ο τεχνικός, πρώτα στο **staging**, και στο κανονικό site **μόνο με την έγκρισή σας**.

**Πριν ξεκινήσουμε, ο τεχνικός πρέπει να κλείσει:**
1. το σφάλμα MySQL 1044: ο τεχνικός λέει ότι διορθώθηκε (πλήρης πρόσβαση στη βάση του Club)· το επιβεβαιώνουμε στη
   σελίδα των μεταβάσεων (Εργαλεία → «Evangelou Club — Βάση δεδομένων»)·
2. τη ζώνη ώρας **του WordPress** σε «Αθήνα» (Ρυθμίσεις → Γενικά· σήμερα σταθερό UTC+3). Ο διακομιστής MySQL είναι
   ήδη σε ώρα Αθήνας, αλλά αυτό είναι άλλη ρύθμιση και δεν αρκεί·
3. κωδικοί που είχαν εκτεθεί: της βάσης του Club **άλλαξε** (2026-11-10)· μένει να επιβεβαιωθεί και ο κωδικός της
   βάσης του WordPress·
4. τη διεύθυνση της σελίδας όρων του Club (D13)·
5. τους αριθμούς (ID) των ετικετών του FluentCRM·
6. ποιο checkout χρησιμοποιεί το site (Block ή κλασικό)·
7. η μετάβαση 003: εγκρίθηκε **μόνο για το staging** (D19)· για το κανονικό site θέλει ξεχωριστή έγκρισή σας (όπως και
   οι 001, 002)·
8. το plugin στο staging είναι παλιότερη έκδοση: ο τεχνικός το αντικαθιστά με το νέο πακέτο.

**Απαντήσεις τεχνικού (2026-11-10):** SFTP στο `linuxzone146.grserver.gr`· η βάση του Club είναι η βάση του
**staging**, χωρίς phpMyAdmin (MySQL Workbench στο `linuxzone146.grserver.gr`)· ο διακομιστής MySQL είναι σε ώρα
Αθήνας.

**Η σειρά ενεργοποίησης** (μία αλλαγή τη φορά, και μετά από κάθε μία ανοίγουμε **Evangelou Club → Ετοιμότητα**):
σελίδες διαχείρισης → βάση του Club → ρυθμίσεις καταστήματος → επιβεβαίωση πληρωμής από το WooCommerce → σελίδα
μέλους → ταμείο και εξαργύρωση (και δημιουργία του κοινού λογαριασμού ταμείου) → εγγραφή με QR στο κατάστημα →
ετικέτες FluentCRM.

**Πιλοτικά.** Πρώτα staging. Μετά, με την έγκρισή σας, το κανονικό site με λίγα μέλη (10–20 τακτικούς) για 1–2
εβδομάδες. Κάθε μέρα κοιτάμε: τη σελίδα «Ετοιμότητα», τις ειδοποιήσεις στο WordPress, αν πελάτες είδαν «Η εγγραφή
δεν είναι δυνατή αυτή τη στιγμή» ή «Πολλές προσπάθειες», αποτυχημένες εξαργυρώσεις στο ταμείο, παραγγελίες συνδρομής
σε αναμονή (δύο για τον ίδιο πελάτη: επιβεβαιώνετε μόνο αυτή με την κατάθεση, ακυρώνετε την άλλη) και τους αριθμούς
του FluentCRM.

**Αν κάτι πάει στραβά**, ο τεχνικός σβήνει τη γραμμή του συγκεκριμένου κομματιού από το wp-config.php και αυτό
σταματά αμέσως. Δεν χάνεται τίποτα: μέλη, πληρωμές, εξαργυρώσεις και λογαριασμοί μένουν, και όλα συνεχίζουν όταν
ξανανοίξει.

**Γνωστοί κίνδυνοι** που έχετε αποφασίσει ή έχουν μέτρα: δείτε τον πίνακα «Known risks» (K1–K14) παραπάνω· ο
τεχνικός μπορεί να σας τους εξηγήσει έναν έναν.
