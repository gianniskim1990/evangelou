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
| Home-screen app and member push notifications | [member-pwa.md](member-pwa.md), [member-push.md](member-push.md) |

## 1. Before anything: the open technician blockers

| # | Blocker | What closes it |
|---|---|---|
| T1 | **MySQL error 1044** (access denied to the Club database) seen on staging | **Closed on staging (2026-11-18):** migrations 001 + 002 + 003 applied successfully on the staging Club database by the owner. Earlier: **reported fixed, to verify (2026-11-10):** the technician states the Club DB user has full access to the Club database. Verify on the **migrations status page** (Tools → «Evangelou Club — Βάση δεδομένων», with `EVC_CLUB_MIGRATIONS_ENABLED`): every separation and schema check passes, and the approved apply on staging succeeds. A green readiness row «Βάση δεδομένων του Club» proves only that a connection opens and SELECTs run; it does **not** prove the CREATE rights whose absence gave 1044, so it does not close T1. |
| T2 | **WordPress timezone setting**: Settings → General → Timezone is a fixed UTC+3, not `Europe/Athens` | **Closed on staging (2026-11-18):** the staging WordPress timezone is `Europe/Athens`. **Production: check it there too** (readiness «Ζώνη ώρας του ιστότοπου»). Before: Settings → General → Timezone = «Athens» (Europe/Athens), after checking the impact on existing orders. Readiness: «Ζώνη ώρας του ιστότοπου: Εντάξει». This is the **WordPress** setting (`timezone_string`), which the Club's day boundary and the shop checks read. It is **separate from the MySQL server's timezone**: the technician reported (2026-11-10) that the MySQL server runs in Europe/Athens, which does **not** close T2. |
| T3 | **The exposed database passwords** | **Club DB: closed (2026-11-10)** — the owner reports that the technician rotated the exposed Club DB password. **WordPress DB: still to be confirmed** — the technician confirms that the WordPress database password was rotated too (or was never the exposed one), wp-config.php updated, the site and the readiness page work. |
| T4 | **Terms URL** (D13) | `define('EVC_CLUB_TERMS_URL', 'https://…/…')` with the existing Club terms page. |
| T5 | **FluentCRM tag IDs** | The six `EVC_CLUB_FLUENTCRM_TAG_*` constants with the real IDs and names ([fluentcrm-tags.md](fluentcrm-tags.md)). |
| T6 | **Checkout type**: Block Checkout or classic | Record which one the live site uses (step 7 below). Both are guarded; staging must use the same one. **D20 depends on it:** FluentCRM's newsletter checkbox is shown on the **classic** checkout only (FluentCRM support acknowledged the Block Checkout incompatibility in its WordPress.org forum, 2025-09; no fixed version known). With the Block Checkout the checkbox simply does not appear (no consent is collected there): check it on staging. |
| T7 | **Migration 003** | **Staging: approved (owner decision D19, 2026-11-10)** — `define('EVC_CLUB_MIGRATIONS_TARGET', 3);` on staging only, after a backup. **Production:** 001, 002 and 003 each still need the owner's own approval. Without 003 the target stays 2 and the member QR / scan stay unavailable. |
| T9 | **Permalinks** (found on staging 2026-11-18) | Settings → Permalinks = **«Όνομα άρθρου» (Post name)**. With «Plain», `/club-member/`, `/club-admin/` and `/club/join/` are an Apache 404 that never reaches WordPress. Since STAGE-08 round 4 the plugin also builds working plain URLs (`?evc_club_admin=1`, `?evc_club_member=1`, `?evc_club_join=1`) and the apps accept the plain REST form, but the printed poster QR, the tablet bookmark and every test use the pretty URLs, so readiness reads «Μόνιμοι σύνδεσμοι: Λάθος» until it is «Post name». **Staging: closed** (switched to Post name, after which everything worked). **Production: check** before step 5. |
| T10 | **The Club PMPro level on production** | On staging the level had **recurring billing and no one-month expiry**; readiness correctly reported `club_level_not_one_month` / `club_level_has_recurring_billing`, and the owner fixed the level **on staging**. On **production** the same level may be used by other (existing) customers: changing it is an **owner decision** first (who uses it, what happens to their renewals), never a technician's quick fix. |
| T11 | **FluentCRM settings of D20** (2026-11-18) | Pending the **shop owner's consent through the technician** before production: FluentCRM → Settings → Integrations → WooCommerce → **Default Contact Status = «Transactional»** (today «Subscribed»: every shop buyer, including Club joiners, becomes a newsletter subscriber), and FluentCRM → Settings → General → **«Add a subscription checkbox to the WooCommerce checkout page»** on, **not** auto-checked. Readiness rows «Στο FluentCRM: σύνδεση με WooCommerce (D20)» and «…κουτάκι newsletter στο checkout (D20)» stay manual checks (FluentCRM's settings are not read by the plugin). |
| T8 | **The staging plugin build** | The plugin is already installed on the staging WordPress (using the staging Club DB), but it is an **earlier build**. The technician replaces it with the package built from the reviewed, CI-green commit before any step below. |

### Technician answers received 2026-11-10 (no secrets recorded here)

- **SFTP host:** the hosting provider's server, given by the technician privately (not recorded in this public
  repository); port 22 on `evangelouprofiterole.gr` times out.
- **Club database access:** there is no phpMyAdmin for the Club DB; use **MySQL Workbench** to the same
  hosting-provider server.
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

### Owner's first staging run, 2026-11-18 (recorded by the coordinator; no secrets, hosts or user names here)

- **The staging site** is a **WP Staging (free)** clone in a **sub-folder of the live site**. It uses the **same
  WordPress database as live, with a different table prefix**: a backup of that database covers both sites, and a
  mistake in it can touch live too.
- **WP Staging (free) lets only administrators in.** The customer and till tests needed WP Staging's **«Disable admin
  authorization»** for the duration of the test; switch it back on afterwards, so the staging copy is not public.
- **E-mails on staging** were blocked with the **«Disable Emails»** plugin (WP Staging free cannot block them).
  **Recommended for every staging run**, so no test order, password reset or FluentCRM mail reaches a real customer.
- **Permalinks were «Plain»** (T9): `/club-member/` and `/club-admin/` gave an Apache 404; after wp-login the
  redirect went to the pretty `/club-admin/` (404); the till app showed «Η εφαρμογή δεν είναι διαθέσιμη» because it
  refused the plain REST address (`…/?rest_route=/evangelou-club/v1`). The member page worked as `?evc_club_member=1`.
  Switching to «Post name» fixed all of it. Round 4 makes the plain form work as well, and readiness flags «Plain».
  Round 5 tightens the plain form: the pages send the site's home path (`homePath`), and the apps accept a plain REST
  base only exactly at that path or its `index.php`, with one `rest_route` and no other parameter that PHP would read
  as `rest_route` (`rest.route`, `rest route`, `rest[route`); no fragment and no dot-segment in either form.
- **The member card logo did not load** (a broken image showing «Ευαγγέλου»): the customer pages asked for the logo
  at the site root (`/logo-evaggelou-color.png`, a file of the separate Vercel demo). Fixed in round 4: the logo is
  now bundled with the customer app (`customer-app/assets/`), as the till app's already was.
- **The Club PMPro level** had recurring billing and no one-month expiry; readiness reported it; fixed **on staging
  only** (T10).
- **`EVC_CLUB_DB_NAME`** in the staging wp-config.php had **one underscore instead of two**; readiness only said
  «Η σύνδεση απέτυχε (EVC_Db_Exception)… π.χ. 1044». Round 4: the row now shows the MySQL error number and which
  constants to compare letter by letter (never their values); round 5 words it «σφάλμα MySQL 1044», not «κωδικός»
  (which also means password).
- **Done on staging:** migrations 001 + 002 + 003 (T1 closed), WordPress timezone Europe/Athens (T2 closed), and
  **steps 1–6 of the enable order passed**: a test order completed by the shop was recorded in the Club database, the
  member page showed the real QR, the till scanned it and redeemed one coffee, and a second coffee the same day was
  refused.
- **Owner decision D20** (2026-11-18): newsletter consent only through FluentCRM's own checkout checkbox, unchecked by
  default, for everyone (the Club join checkout included), and the WooCommerce integration's Default Contact Status
  «Transactional» (T11). It supersedes D12's "no newsletter checkbox" for the **shop checkout**; the Club code still
  never calls FluentCRM from the onboarding (D11 / D12 code rules stand). Pending the shop owner's consent.
- **Owner decision D21** (2026-11-19, STAGE-08 round 6): the member card shows the QR **always** when the membership
  is active, and the «Νέο QR» button is gone. Its automatic replacement (a device without the current QR asked for a
  new one and retired the old) is **superseded by D22**.
- **Owner decision D22** (2026-11-19, STAGE-08 round 7, «Όχι, να μην αλλάζει ποτέ»): the member's QR is the **same on
  every device and browser**; opening the card anywhere never revokes it; a screenshot works from any device (D6 no
  expiry and D7 stand). The server derives the token (HMAC-SHA256 with the new secret `EVC_CLUB_QR_SECRET`, the
  member's public id and a generation = the member's QR row count) and hands it again to that signed-in member only;
  the database still stores only digests, so the till lookup is unchanged. **No migration 004** (no schema change).
  A new code only after an administrator's revocation (existing REST route, no new screen). Tokens issued on
  staging before D22 are replaced **once** by the stable token at the member's next card open (old screenshots stop
  then, once). Client: never sends `replace`; a failed re-check keeps the QR on screen (round 7); retries pause off
  the card. See [member-qr.md](member-qr.md). Staging check: open the card on two phones: the **same** QR on both;
  scan both at the till: the same member.
- **Owner decision D23** (2026-11-19, STAGE-08 round 8): the member card shows **real** Club DB figures: the free
  coffees of this Athens calendar month («Καφέδες αυτόν τον μήνα») and whether today's coffee (Athens day) was
  already taken (headline «Τον απολαύσατε σήμερα»). New read-only route `GET evangelou-club-customer/v1/me/coffees`
  (same gates as `/me/qr`, no QR secret needed); the Club DB user needs `SELECT` on `evc_redemptions` (the till
  already has it). Fail soft: if the Club DB does not answer, the card shows «—» as before. No migration. Staging
  check: after a till redemption, reopen the card: «Τον απολαύσατε σήμερα» and the month count one higher.
- **Never delete rows of `evc_qr_tokens`** (round 8): a member's QR comes from that member's row count; deleted
  rows can bring a revoked code back or leave the member without a QR (log reason `qr_generation_stuck` beyond 16
  deleted rows of one member). See [member-qr.md](member-qr.md).
- **Owner decision D24** (2026-11-20, round 9a: backend): at the till, after the scan, staff choose the coffee step
  by step (dose incl. **triple**, sugar, sweetener, milk, size, quick notes and a **short staff note**), see the
  member's **last 3 coffees** with a «Το ίδιο με την τελευταία φορά» pre-fill (staff still review and confirm), and the
  member card shows the member's own last coffees. Needs **migration 004** (new `evc_redemption_preparations` table +
  four nullable ledger columns; additive, v1 rows keep working and show as the coffee name only). **004 is approved
  for the STAGING Club DB only (D25, below); production is not approved.** Until it is applied the till keeps the v1 flow (coffee type only), history shows
  coffee names only, and readiness row «Καταγραφή προετοιμασίας καφέ στο ταμείο (μετάβαση 004)» reads «Έλεγχος με το
  χέρι» (not an error). **Owner steps for staging, in this order:** (1) the owner approves 004 for staging (done: D25);
  (2) backup of the staging Club DB; (3) the technician changes `define('EVC_CLUB_MIGRATIONS_TARGET', 3);` to
  `define('EVC_CLUB_MIGRATIONS_TARGET', 4);` in the staging wp-config.php (and has `EVC_CLUB_MIGRATIONS_ENABLED` on
  for the moment); (4) Tools → «Evangelou Club — Βάση δεδομένων»: all checks green, then apply (only 004 is pending);
  (5) remove `EVC_CLUB_MIGRATIONS_ENABLED` again; (6) Readiness: «Έκδοση σχήματος» «Εντάξει» and «Καταγραφή
  προετοιμασίας καφέ» «Εντάξει». The Club DB user needs `SELECT`/`INSERT` on `evc_redemption_preparations` (the
  technician reports full access on the staging Club DB). **Production** needs its own approval of 004 (as of 001-003).
  The staff note has an open retention question: K18.
  **Round 9b (till and card):** after the scan the till shows the member's last 3 coffees (date, coffee,
  preparation, the staff note) and a large «Το ίδιο με την τελευταία φορά» button; the coffee is chosen with one
  large button per step (only the steps of that coffee), then a review screen with the extras (ice, foam, decaf),
  the quick notes and the note (80 characters at most), and «Καταχώρηση καφέ». Older tablets / before 004: the
  coffee type only, as today. The member card's «Ιστορικό» lists the member's own last 5 coffees (no staff note).
  **Staging check after 004:** scan a test member, record a Freddo Espresso «Μεγάλο μέγεθος · Τριπλός» with a
  note; scan again: the coffee appears in «Τελευταίοι καφέδες» with the note, a second coffee is refused; the next
  Athens day «Το ίδιο» fills the order and only «Καταχώρηση καφέ» is needed; the member card's «Ιστορικό» shows the
  coffee without the note.
- **Owner decision D25** (2026-11-20 13:09, relayed by the coordinator): migration **004 is APPROVED for the STAGING
  Club DB only**. The owner / technician applies it (steps (2)-(6) of D24 above); the developer side applies nothing.
  **Production still needs its own approval of 004.** K18 (retention of the staff note, D9) stays open.
- **Owner decision D26 part 1** (2026-11-20, STAGE-08 round 11): the member page can be **installed on the phone's
  home screen** (web app manifest, icons from the logo, iOS tags, a service worker that caches only the app's own
  build files and never REST, `/me`, QR or coffee data or any page with a nonce, and a «Εγκατάσταση στο κινητό» hint).
  **No push yet** (round 12, migration 005). The worker exists only with «Post name» permalinks (scope
  `/club-member/`; with plain permalinks it would control the whole site, the till included, so none is served).
  Nothing new in wp-config.php; after installing the package, open Settings → Permalinks once and save (the two new
  rewrite rules `/club-member/manifest.webmanifest` and `/club-member/sw.js` are also added automatically on the
  first page load). Details and the **real-phone test steps**: [member-pwa.md](member-pwa.md).
- **Owner decision D27** (2026-11-20, round 11): **renewal from the member card**. An EXPIRED member gets
  «Ανανέωση 20 €» on the dashboard and the card; it opens the join flow (the same payment as joining; needs
  `EVC_CLUB_ONBOARDING_ENABLED`, otherwise a line «μιλήστε με το κατάστημα»). No renewal for an active member. A
  pending bank transfer shows plain text (the bank details are in the order e-mail) instead of the old disabled
  «Ολοκλήρωση πληρωμής». Cancelled and unverified: no button (unchanged). The preview tooltip «Μη διαθέσιμο στην
  προεπισκόπηση» is gone from the live app. Two simultaneous Club checkouts of one account are now serialised by a
  per-account database lock (K5). **Staging check:** an expired test member taps «Ανανέωση 20 €» → the join page →
  terms (if not accepted yet) → the checkout with the Club product → bank transfer; the order is «Σε αναμονή»; back
  on the card the text about the bank transfer, no button.
- **Owner decision D26 part 2** (STAGE-08 round 12): **member push notifications**, sent from the shop's own server
  (no third-party service). Everything OFF by default (`EVC_CLUB_PUSH_ENABLED`, exact `true`, plus the member page);
  needs VAPID keys in wp-config.php (made with the one-line command of [member-push.md](member-push.md), never sent to
  anyone) and **migration 005, which is NOT approved for any database yet** (the owner approves staging first; then
  `EVC_CLUB_MIGRATIONS_TARGET` 5 and the migrations page; the Club DB user needs SELECT, INSERT, UPDATE on
  `evc_push_subscriptions`). Until then push is simply hidden. Administrators send from «Evangelou Club →
  Ειδοποιήσεις»; two automatic notifications have their own constants (default OFF). **Staging check:** readiness rows
  «Ειδοποιήσεις μελών», «Κλειδιά ειδοποιήσεων», «πίνακας συνδρομών (μετάβαση 005)» «Εντάξει»; `/club-member/sw.js` and
  `/club-member/manifest.webmanifest` load in a browser (not a 404: [member-pwa.md](member-pwa.md)); then the Android
  and installed-iPhone tests of [member-push.md](member-push.md). K21.
- **On a phone, the offer's «Πληρωμή 20 €» did not look like a button** (the owner only noticed the account link).
  Round 4: a full-width filled button; the account link below it is small.

## 2. Enable order, and what to check after each step

Make one change at a time, open **Evangelou Club → Ετοιμότητα** after each, and continue only when that step's rows
read «Εντάξει» (or the «manual» items are confirmed).

| Step | wp-config.php | Then check |
|---|---|---|
| 0 | Backups of both databases (on a WP Staging clone the WordPress database is shared with live). Settings → Permalinks = «Post name» (T9). On staging: the «Disable Emails» plugin active. | Restore tested. Readiness «Μόνιμοι σύνδεσμοι»: «Εντάξει». Round 12 (N3): open `/club-member/sw.js` and `/club-member/manifest.webmanifest` in a browser once the member page is on (step 5): both must show text, not a 404 (an nginx `location ~* \.(js\|webmanifest)$` rule can 404 them: [member-pwa.md](member-pwa.md)). |
| 1 | `define('EVC_CLUB_ADMIN_TOOLS_ENABLED', true);` | The «Evangelou Club» menu appears for administrators only. Readiness shows the current state. |
| 2 | The `EVC_CLUB_DB_*` constants; `define('EVC_CLUB_MIGRATIONS_ENABLED', true);`; on **staging** also `define('EVC_CLUB_MIGRATIONS_TARGET', 3);` (D19) | Tools → «Evangelou Club — Βάση δεδομένων»: all checks green (this also verifies T1); apply 001 + 002 (approved for staging on 2026-10-27) and, on staging, 003 (D19, 2026-11-10). **004 (D24, round 9) is approved for staging only (D25, 2026-11-20):** the technician sets `EVC_CLUB_MIGRATIONS_TARGET` to 4 and applies it the same way. **Production:** 001, 002, 003 and 004 each need the owner's own approval. Then remove `EVC_CLUB_MIGRATIONS_ENABLED`. Readiness: «Βάση δεδομένων του Club» and «Έκδοση σχήματος» «Εντάξει» (a «Δεν διαβάζεται» schema row means the migrations table could not be read: go back to the migrations page checks). |
| 3 | `EVC_CLUB_MEMBERSHIP_SOURCE`, `EVC_CLUB_PMPRO_LEVEL_ID`, `EVC_CLUB_WC_PRODUCT_ID`, `EVC_CLUB_EVIDENCE_KEY`, `EVC_CLUB_PAYMENT_PROVENANCE = 'club_db_v1'` | Readiness «Κατάστημα»: all «Εντάξει» (pinned versions, product 20.00 €, sold individually, one-month level, Athens clock, BACS on), **including «Καταγραφή πληρωμών στη βάση του Club (EVC_CLUB_PAYMENT_PROVENANCE)»**: «Εντάξει» only for the exact `'club_db_v1'`; «Λάθος» or «Λείπει» means payment confirmation, the member QR and redemption stay closed. |
| 4 | `define('EVC_CLUB_WC_COMPLETION_ENABLED', true);` | One test order by bank transfer, marked «Ολοκληρωμένη» by the shop: the member reads active ([commerce-membership-source.md](commerce-membership-source.md)). |
| 5 | `define('EVC_CLUB_CUSTOMER_ENABLED', true);`, `EVC_CLUB_LOOKUP_KEY` (a random secret of at least 32 bytes, for the QR scan) and (D22) `EVC_CLUB_QR_SECRET` (another, **different** random secret of at least 32 bytes, for the member's QR; never change it without reason: every member's QR changes once when it does; the docs placeholder and a repeated character are refused). Generate each one separately: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` or, in Windows PowerShell 5, `$b=New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); ($b\|%{$_.ToString('x2')}) -join ''` | Readiness «Κλειδί σκαναρίσματος QR (EVC_CLUB_LOOKUP_KEY)» and «Μυστικό κλειδί του κωδικού QR μέλους (EVC_CLUB_QR_SECRET)»: «Εντάξει» (values never shown). `/club-member/` shows the test member as active; the QR appears only after 003, and is the same on a second phone. |
| 6 | `define('EVC_CLUB_STAFF_ENABLED', true);` `define('EVC_CLUB_REDEMPTION_SOURCE', 'club_db_v1');` | Create the shared till account (Evangelou Club → Λογαριασμός ταμείου, D18); sign the tablet in at `/club-admin/`; redeem one coffee for the test member; a second one the same day is refused. |
| 7 | `EVC_CLUB_TERMS_URL`; `define('EVC_CLUB_ONBOARDING_ENABLED', true);` | Record the checkout type: edit the WooCommerce checkout page; a «Checkout» block = Block Checkout, `[woocommerce_checkout]` or «Classic Checkout» = classic. Then the staging checks of [store-onboarding.md](store-onboarding.md) (REMOTE_ADDR, FluentCRM settings of D20, page cache, BACS-only checkout). With D20 in place: the Club join checkout shows FluentCRM's newsletter box **unticked**; a test order with the box unticked leaves the contact «Transactional», ticked makes it subscribed (classic checkout only, T6). |
| 8 | The six tag constants; `define('EVC_CLUB_FLUENTCRM_SYNC_ENABLED', true);` | Readiness «Ετικέτες FluentCRM: Εντάξει» (verified); Tools → «Evangelou Club — FluentCRM» shows counts only. |
| 9 | Only after the owner approved migration 005 for this database: backup, `EVC_CLUB_MIGRATIONS_TARGET` 5 and apply; SELECT, INSERT, UPDATE on `evc_push_subscriptions`; the three VAPID / subject lines (one-line command of [member-push.md](member-push.md)); `define('EVC_CLUB_PUSH_ENABLED', true);`. Later, optionally, `EVC_CLUB_PUSH_AUTO_ACTIVATED` / `EVC_CLUB_PUSH_AUTO_EXPIRY`. | Readiness «Ειδοποιήσεις μελών»: all «Εντάξει» (keys never shown). Android test and installed-iPhone test of [member-push.md](member-push.md): opt in, send from «Ειδοποιήσεις», tap opens the card, «Διακοπή ειδοποιήσεων» stops them. |

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
| Push notifications | No control on the card, `/me/push` answers 404, the «Ειδοποιήσεις» page and every scheduled push event stop (a leftover event finds no handler). The automatic ones stop with their own line too. | The subscriptions in the Club DB (members can stop them later; a dead one is revoked on the next send), the send audit. |

Turning a flag back on resumes from the stored state; nothing is lost by turning a flag off.

## 5. Known risks

Each with the owner decision that accepted it or the mitigation in place.

| # | Risk | Decision / mitigation |
|---|---|---|
| K1 | A **shared mobile /48**: 30 failed sign-ups in 5 minutes from one carrier /48 block the whole /48 for 5 minutes (429), including unrelated customers. | Coordinator decision (STAGE-07 round 4, with N1). **Monitoring:** during the pilot ask the till to note any customer who sees «Πολλές προσπάθειες»; the technician can count `evc_join_rl_m_%` rows. The limit is the code constant `MAX_NETWORK_FAILURES`. |
| K2 | The **total sign-up cap** (100 per hour) can be used to stop QR sign-up for an hour. | Kept blocking on purpose; one log line and an admin notice per hour; the technician tunes `EVC_CLUB_JOIN_MAX_PER_HOUR` ([store-onboarding.md](store-onboarding.md)). |
| K3 | **Enumeration and pre-registration** of e-mails at sign-up. | Owner decision D14 (no e-mail confirmation); recovery by password reset; a squatter's order stays unpaid and unconfirmed. |
| K4 | **REMOTE_ADDR behind a proxy/CDN** would make all customers one client. | Go-live check (readiness «manual» + store-onboarding checklist). |
| K5 | **Two checkouts at the same instant** (two devices) can leave two unpaid Club orders. | Accepted (STAGE-07 R6): the shop's manual confirmation is the final gate; one transfer never funds two orders; the Greek operator note says to cancel the other. **Round 11:** a submitted Club checkout of a signed-in customer (classic checkout validation, Store API POST /checkout) first takes a per-account `GET_LOCK` of the WordPress database (no table, no migration) and reads the open orders only then; the lock lasts until the request ends, so the second checkout waits up to 10 s and then sees the first order («Υπάρχει ήδη…»), or reads «Μια άλλη πληρωμή συνδρομής … είναι σε εξέλιξη». **Residual:** a database server that refuses named locks (`GET_LOCK` answers NULL, e.g. some clusters) runs the check as before (never refuses the checkout); a guest who creates the account at the checkout has no earlier order and is not locked. `add_option()` was not used: WordPress inserts options with ON DUPLICATE KEY UPDATE, so two racing calls can both succeed. |
| K6 | **The failure-row ceiling**: a flood from many addresses stops counting failures of NEW clients for up to 5 minutes. | Sign-up is never refused because of it (STAGE-07 B1); admin notice; rows expire after 5 minutes. The expired-row cleanup runs at most once per request. |
| K7 | **The till password**: successful re-logins are not limited; anyone who knows it can log in again. | Owner decision «Όχι, μένει έτσι» (2026-11-04); change the password when staff leave (D18 operator note). |
| K8 | **The shared till account** (D18): redemptions are not attributed to a person. | Owner decision D18. |
| K9 | **The member QR token in the browser** (localStorage) can be read by any script on the site. | Owner decision «Ναι, να τον θυμάται» (2026-11-04); one coffee per Athens day and the staff's first-name check limit the damage ([member-qr.md](member-qr.md)). |
| K10 | **FluentCRM**: two contacts on the same WordPress user cannot be detected through the documented API. | Documented residual risk; a mismatching e-mail is refused ([fluentcrm-tags.md](fluentcrm-tags.md)). |
| K11 | **The evidence key cannot be rotated**: changing `EVC_CLUB_EVIDENCE_KEY` invalidates stored payment intents (recovery stops for a manager, never accepts). | Documented; never change it after go-live without a developer ([commerce-membership-source.md](commerce-membership-source.md)). |
| K12 | **Month-end dates**: a calendar month after 31 January ends on 28 February, and the next on 28 March. | Owner decision D5 (calendar-month clamp). |
| K13 | **A tablet that does not confirm sign-out** keeps its server session until the 30-minute inactivity limit. | Bounded by the session policy; «Αποσύνδεση από όλες τις συσκευές» ends it at once. |
| K15 | **Plain permalinks** on a site: the pretty Club URLs are a 404. | Round 4: the plugin builds plain URLs then, and readiness reads «Λάθος» with the fix (T9). |
| K16 | **FluentCRM's checkout checkbox is not shown on the Block Checkout** (D20). | Known FluentCRM limitation (acknowledged by its support, 2025-09). On a Block Checkout no consent is collected at the checkout; the D20 «Transactional» default keeps buyers out of the newsletter. Check on staging (T6). |
| K17 | **Resolved by D22 (2026-11-19).** Was: opening the card on a second device retired the first device's QR. Now the QR is the same on every device and only an administrator's revocation changes it. What remains: a screenshot of the QR keeps working on any device until that revocation (D6 no expiry, D7 accepted); `EVC_CLUB_QR_SECRET` is a new secret to keep (a leak lets someone compute members' codes if they also know the member ids; changing it gives every member a new code once); the customer `POST /me/qr` has no server-side rate limit (it now writes only on a first QR, after a revocation, or once for a pre-D22 token), only the app's cap of 8 requests / 3 POSTs per minute. | One coffee per Athens day and the staff's first-name check limit the damage; the secret lives only in wp-config.php (packager guard: no secret in a shipped file) and is never logged or shown. A token lifetime or a server-side limit would be a new owner decision. |
| K18 | **The staff note (D24) is free text linked to a member's redemption, kept with no retention limit.** Staff could write personal data in it (e.g. a health remark). Owner decision **D9 (retention / access / erasure of member-linked history) is still open**; there is no purge mechanism. | Limited to 80 characters, plain text (HTML refused), never logged, never in the audit trail, never shown to the customer (only to the till, in the last 3 coffees). **Owner decision needed:** how long notes are kept (e.g. 12 months), and an instruction to staff: only preparation wishes, never health or personal remarks. Until then, every note stays in the Club DB. |
| K19 | **Older tablets keep the v1 route** (coffee type only). | Deliberate (round 9a): v1 and the prepared route share one ledger and its unique keys, so one coffee per member per Athens day holds across both. The new till app (round 9b) uses the prepared route only when the scan reply says `prepared` (004 applied and the approved catalogue loaded); otherwise, and after a `409 prepared_unavailable`, it records the coffee type through v1 exactly as before. A tablet keeps an old app until its page is reloaded: reload the till page once after installing a new package. The v1 route can be retired later by a separate decision. |
| K20 | **The home-screen app (D26, round 11).** (a) A cold start without any network shows only «Δεν υπάρχει σύνδεση» (the offline page knows nothing about the member, so no card or QR; the «unchecked» QR needs `/me` first). (b) With plain permalinks there is no service worker (no offline page); the app can still be installed. (c) iOS keeps an installed web app's sign-in apart from Safari: the member may have to sign in again inside the app; the sign-in page (`wp-login.php`) is outside the manifest's scope `/club-member/` and has not been tested on a real iPhone. (d) Nothing was tested on real phones. | (a) Showing the kept QR without `/me` would be a new owner decision (shared phones). (b) Use «Post name» (T9). (c) Owner test step on an iPhone ([member-pwa.md](member-pwa.md)); if the sign-in loops, the fix is a wider manifest scope for navigation only (not the worker's), a decision. |
| K21 | **Member push (D26 part 2, round 12).** (a) Outbound HTTPS to the Windows push service (`*.notify.windows.com`, Edge on Windows) was not confirmed by the technician (FCM, Apple, Mozilla were). (b) A push service may delay or drop messages (no delivery guarantee; TTL 24 h). (c) iOS delivers web push only to an app installed on the home screen (iOS 16.4+), and its separate sign-in (K20c) applies. (d) Changing the VAPID keys makes every member opt in again (their old subscriptions fail with 403 and stay counted as failures until the browser re-subscribes). (e) The endpoint is stored (it is needed to send); it is a capability URL, never logged or shown. (f) Nothing was tested on a real phone or against a real push service. (g) Round 13: a sign-out other than the app's «Αποσύνδεση» (session expiry, logout from another page, sessions destroyed) does not revoke the phone's push row. (h) Round 13: a database server without named locks (`GET_LOCK` NULL / error) sends no push at all. (i) Round 14: on a day the automatic run gets no named lock, members whose period ends three days later get no «λήγει σε 3 ημέρες» notice that period; «ενεργοποιήθηκε» is retried for 30 minutes, then dropped. (j) Round 15: an administrator's send that gets no named lock for 10 WP-Cron batches in a row (about 10 minutes) is stopped; the messages not yet sent are not sent. | (a) Technician to allow it, or Edge users simply get no push. (b) Accepted: notifications are a convenience, never the only channel. (c) Owner test on an installed iPhone. (d) Keys are made once per site and never changed without reason. (e) Least-privilege grants, no DELETE, revoked on 404 / 410. (f) Owner tests on Android and iPhone (member-push.md). (g) Accepted and documented: title and text only; another account turning push on gets 409 and a new subscription; «Διακοπή ειδοποιήσεων»; 404 / 410 revoke (the server cannot tell which row is the signing-out device without a migration). (h) Fail closed by design; the technician confirms named locks (standard MySQL / MariaDB have them). (i) Accepted with (h); the card still shows the end date and «Ανανέωση». (j) Accepted with (h): the Club pages show a notice while it is stuck and after it stopped; the administrator sends again once the technician fixed the database. |
| K14 | **A page cache** could serve the join page to the wrong visitor. | `DONOTCACHEPAGE` + no-cache headers; readiness lists page-cache plugins; exclude `/club/join/` and `/club-member/` by hand if the host cache ignores them. |

## 6. Shipped-code TODO / FIXME

A search of the shipped plugin (`includes/`, `evangelou-club.php`) and the app sources (`app/src`) on 2026-11-06 found
**no** `TODO`, `FIXME`, `XXX` or `HACK` markers.

## Ελληνικά — για τον ιδιοκτήτη και τον τεχνικό

**Τι είναι αυτό.** Ο οδηγός για να ανοίξει το Club, βήμα βήμα. Τίποτα δεν έχει ενεργοποιηθεί ακόμη. Κάθε βήμα το
κάνει ο τεχνικός, πρώτα στο **staging**, και στο κανονικό site **μόνο με την έγκρισή σας**.

**Πριν ξεκινήσουμε, ο τεχνικός πρέπει να κλείσει:**
1. το σφάλμα MySQL 1044: **έκλεισε στο staging** (18/11: οι μεταβάσεις 001–003 εφαρμόστηκαν)·
2. τη ζώνη ώρας **του WordPress** σε «Αθήνα» (Ρυθμίσεις → Γενικά): **έγινε στο staging**· στο κανονικό site να
   ελεγχθεί. Ο διακομιστής MySQL σε ώρα Αθήνας είναι άλλη ρύθμιση και δεν αρκεί·
3. κωδικοί που είχαν εκτεθεί: της βάσης του Club **άλλαξε** (2026-11-10)· μένει να επιβεβαιωθεί και ο κωδικός της
   βάσης του WordPress·
4. τη διεύθυνση της σελίδας όρων του Club (D13)·
5. τους αριθμούς (ID) των ετικετών του FluentCRM·
6. ποιο checkout χρησιμοποιεί το site (Block ή κλασικό)·
7. η μετάβαση 003: εγκρίθηκε **μόνο για το staging** (D19)· για το κανονικό site θέλει ξεχωριστή έγκρισή σας (όπως και
   οι 001, 002)·
8. το plugin στο staging είναι παλιότερη έκδοση: ο τεχνικός το αντικαθιστά με το νέο πακέτο·
9. Μόνιμοι σύνδεσμοι = «Όνομα άρθρου» (στο staging έγινε· στο κανονικό site να ελεγχθεί)·
10. το επίπεδο PMPro του Club στο κανονικό site: απόφασή σας πριν από οποιαδήποτε αλλαγή·
11. οι ρυθμίσεις του FluentCRM της απόφασης D20, μετά τη συγκατάθεση του καταστήματος.

**Απαντήσεις τεχνικού (2026-11-10):** SFTP στον διακομιστή του παρόχου φιλοξενίας (τον δίνει ιδιωτικά ο τεχνικός)· η βάση του Club είναι η βάση του
**staging**, χωρίς phpMyAdmin (MySQL Workbench στον ίδιο διακομιστή)· ο διακομιστής MySQL είναι σε ώρα
Αθήνας.

**Η σειρά ενεργοποίησης** (μία αλλαγή τη φορά, και μετά από κάθε μία ανοίγουμε **Evangelou Club → Ετοιμότητα**):
σελίδες διαχείρισης → βάση του Club → ρυθμίσεις καταστήματος → επιβεβαίωση πληρωμής από το WooCommerce → σελίδα
μέλους → ταμείο και εξαργύρωση (και δημιουργία του κοινού λογαριασμού ταμείου) → εγγραφή με QR στο κατάστημα →
ετικέτες FluentCRM → (αφού εγκρίνετε τη μετάβαση 005) ειδοποιήσεις μελών.

**Ειδοποιήσεις μελών (απόφαση D26 μέρος 2, γύρος 12).** Μικρά μηνύματα στο κινητό των μελών, που στέλνει ο δικός μας
διακομιστής (χωρίς ξένη υπηρεσία). Όλα είναι κλειστά μέχρι να τα ανοίξετε. Χρειάζονται:
1. **τη δική σας έγκριση της μετάβασης 005**, πρώτα μόνο για το staging·
2. **κλειδιά**: ο τεχνικός τα φτιάχνει με την εντολή μίας γραμμής του οδηγού member-push.md, στον διακομιστή ή στον
   υπολογιστή του, και τα βάζει κατευθείαν στο wp-config.php. **Δεν στέλνονται ποτέ σε κανέναν**, ούτε σε εμάς·
3. την εφαρμογή της 005 από τη σελίδα μεταβάσεων (με αντίγραφο ασφαλείας πρώτα) και δικαιώματα ανάγνωσης,
   καταχώρησης και ενημέρωσης του χρήστη της βάσης στον νέο πίνακα·
4. τη γραμμή `define('EVC_CLUB_PUSH_ENABLED', true);`·
5. η «Ετοιμότητα» να δείχνει «Εντάξει» στις γραμμές των ειδοποιήσεων, και οι διευθύνσεις `/club-member/sw.js` και
   `/club-member/manifest.webmanifest` να ανοίγουν στον περιηγητή (όχι σφάλμα 404).
Μετά: δοκιμή σε κινητό Android και σε iPhone με την κάρτα εγκατεστημένη στην οθόνη Αφετηρίας (βήματα στο
member-push.md). Οι δύο αυτόματες ειδοποιήσεις («Η συνδρομή σας ενεργοποιήθηκε», «Η συνδρομή σας λήγει σε 3 ημέρες»)
ανοίγουν η καθεμία με δική της γραμμή, όποτε το αποφασίσετε.

**Πιλοτικά.** Πρώτα staging. Μετά, με την έγκρισή σας, το κανονικό site με λίγα μέλη (10–20 τακτικούς) για 1–2
εβδομάδες. Κάθε μέρα κοιτάμε: τη σελίδα «Ετοιμότητα», τις ειδοποιήσεις στο WordPress, αν πελάτες είδαν «Η εγγραφή
δεν είναι δυνατή αυτή τη στιγμή» ή «Πολλές προσπάθειες», αποτυχημένες εξαργυρώσεις στο ταμείο, παραγγελίες συνδρομής
σε αναμονή (δύο για τον ίδιο πελάτη: επιβεβαιώνετε μόνο αυτή με την κατάθεση, ακυρώνετε την άλλη) και τους αριθμούς
του FluentCRM.

**Αν κάτι πάει στραβά**, ο τεχνικός σβήνει τη γραμμή του συγκεκριμένου κομματιού από το wp-config.php και αυτό
σταματά αμέσως. Δεν χάνεται τίποτα: μέλη, πληρωμές, εξαργυρώσεις και λογαριασμοί μένουν, και όλα συνεχίζουν όταν
ξανανοίξει.

**Η πρώτη δοκιμή σας στο staging (18/11/2026):**
- Το staging είναι αντίγραφο WP Staging (δωρεάν) σε υποφάκελο του κανονικού site και **μοιράζεται την ίδια βάση
  WordPress** (με άλλο πρόθεμα πινάκων): το backup αυτής της βάσης καλύπτει και τα δύο.
- Το WP Staging (δωρεάν) αφήνει να μπουν μόνο διαχειριστές· για τις δοκιμές πελάτη και ταμείου χρειάστηκε προσωρινά το
  «Disable admin authorization». Ξαναενεργοποιήστε το μετά τη δοκιμή.
- Στο staging τα e-mail σταμάτησαν με το plugin «Disable Emails». **Το προτείνουμε σε κάθε δοκιμή στο staging.**
- Οι **Μόνιμοι σύνδεσμοι** ήταν «Απλοί» και οι σελίδες του Club έβγαζαν 404. Χρειάζεται: **Ρυθμίσεις → Μόνιμοι
  σύνδεσμοι → Όνομα άρθρου** (και στο κανονικό site). Η σελίδα «Ετοιμότητα» το δείχνει πλέον ως «Λάθος».
- Το λογότυπο στην κάρτα μέλους δεν φόρτωνε· διορθώθηκε.
- Το όνομα της βάσης του Club στο wp-config.php είχε ένα «_» λιγότερο· η «Ετοιμότητα» λέει πλέον ποια στοιχεία να
  ελεγχθούν (χωρίς να δείχνει τις τιμές).
- Το επίπεδο PMPro του Club είχε αυτόματη χρέωση και όχι λήξη ενός μήνα· το διορθώσατε **μόνο στο staging**. Στο
  κανονικό site το ίδιο επίπεδο ίσως το έχουν άλλοι πελάτες: πριν αλλάξει εκεί, χρειάζεται δική σας απόφαση.
- Έγιναν στο staging: μεταβάσεις 001+002+003, ζώνη ώρας Αθήνας, και τα βήματα 1–6 πέτυχαν (πληρωμή, QR, ένας
  καφές στο ταμείο, δεύτερος καφές την ίδια μέρα απορρίφθηκε).
- Το «Πληρωμή 20 €» στο κινητό δεν έμοιαζε με κουμπί· έγινε μεγάλο, γεμάτο κουμπί σε όλο το πλάτος.

**Απόφαση D20 (18/11/2026):** η συγκατάθεση για newsletter δίνεται μόνο με το κουτάκι του ίδιου του FluentCRM στο
checkout του WooCommerce, **άδειο εξ ορισμού**, για όλους (και για την εγγραφή στο Club)· και στη σύνδεση του
FluentCRM με το WooCommerce, το «Default Contact Status» γίνεται **«Transactional»** (σήμερα «Subscribed»: κάθε
αγοραστής γίνεται συνδρομητής newsletter). Περιμένουμε τη συγκατάθεση του καταστήματος μέσω του τεχνικού πριν
γίνει στο κανονικό site. Προσοχή: το FluentCRM δείχνει το κουτάκι μόνο στο κλασικό checkout, όχι στο Block Checkout.

**Απόφαση D21 (19/11/2026):** στην κάρτα μέλους ο QR φαίνεται πάντα και το κουμπί «Νέο QR» καταργήθηκε.

**Απόφαση D22 (19/11/2026):** «Όχι, να μην αλλάζει ποτέ». Ο QR του μέλους είναι **ο ίδιος σε κάθε συσκευή** και
δεν αλλάζει όταν ανοίγει την κάρτα του αλλού. Στιγμιότυπο οθόνης ισχύει από οποιαδήποτε συσκευή. Νέος κωδικός
βγαίνει μόνο αν ο διαχειριστής ακυρώσει τον παλιό (π.χ. χαμένο κινητό). Χρειάζεται **ένα νέο μυστικό** στο
`wp-config.php`, το `EVC_CLUB_QR_SECRET` (τουλάχιστον 32 τυχαίοι χαρακτήρες, **διαφορετικό** από το
`EVC_CLUB_LOOKUP_KEY`)· χωρίς αυτό η κάρτα δεν δείχνει QR. Το παράδειγμα των οδηγιών και ένας ίδιος χαρακτήρας
ξανά και ξανά **δεν** γίνονται δεκτά. Ο τεχνικός φτιάχνει το μυστικό με μία από τις δύο εντολές (64 χαρακτήρες):

    php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

ή σε Windows PowerShell 5:

    $b=New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); ($b|%{$_.ToString('x2')}) -join ''

**Δεν** χρειάζεται νέα μετάβαση βάσης. Στο staging, οι
QR που βγήκαν πριν από το D22 αλλάζουν **μία φορά** στο επόμενο άνοιγμα της κάρτας και μετά μένουν ίδιοι. Έλεγχος
στο δοκιμαστικό αντίγραφο: ανοίξτε την κάρτα σε δύο κινητά· πρέπει να δείχνουν τον ίδιο QR, και η σάρωση και των δύο στο ταμείο
βρίσκει το ίδιο μέλος. **Ποτέ μη σβήνετε γραμμές του πίνακα `evc_qr_tokens`** στη βάση του Club: ο κωδικός κάθε
μέλους βγαίνει από αυτές, και αν σβηστούν, ένας παλιός ακυρωμένος κωδικός μπορεί να ξαναγίνει έγκυρος.

**Απόφαση D23 (19/11/2026):** η κάρτα μέλους δείχνει πια **πραγματικά** στοιχεία: πόσους δωρεάν καφέδες πήρε το
μέλος αυτόν τον μήνα και αν πήρε ήδη τον σημερινό («Τον απολαύσατε σήμερα»). Αν η βάση του Club δεν απαντά, η
κάρτα δείχνει «—» όπως πριν. Δεν χρειάζεται νέα μετάβαση ούτε νέο μυστικό. Έλεγχος στο δοκιμαστικό αντίγραφο: δώστε έναν καφέ στο
ταμείο και ξανανοίξτε την κάρτα· πρέπει να γράφει «Τον απολαύσατε σήμερα» και ο αριθμός του μήνα να έχει
ανέβει κατά ένα.

**Απόφαση D24 (20/11/2026):** στο ταμείο, μετά τη σάρωση, το προσωπικό διαλέγει τον καφέ βήμα βήμα (δόση, και
**τριπλός**, ζάχαρη, γλυκαντικό, γάλα, μέγεθος, γρήγορες σημειώσεις και **μια σύντομη σημείωση**), βλέπει τους
**3 τελευταίους καφέδες** του μέλους με το κουμπί «Το ίδιο με την τελευταία φορά» (το προσωπικό ελέγχει και
επιβεβαιώνει πάντα), και η κάρτα μέλους δείχνει τους δικούς του τελευταίους καφέδες (χωρίς τη σημείωση του
ταμείου). Χρειάζεται τη **μετάβαση 004**, που έχει εγκριθεί **μόνο για το δοκιμαστικό αντίγραφο** (απόφαση D25). Μέχρι να εφαρμοστεί, το ταμείο
καταχωρεί μόνο το είδος του καφέ, όπως σήμερα, και το ιστορικό δείχνει μόνο ονόματα καφέ. **Τα βήματα για το
δοκιμαστικό αντίγραφο:**
1. εγκρίνετε τη μετάβαση 004 για το δοκιμαστικό αντίγραφο (έγινε: απόφαση D25, 20/11/2026)·
2. ο τεχνικός παίρνει αντίγραφο ασφαλείας της βάσης του Club·
3. ο τεχνικός αλλάζει στο wp-config.php του δοκιμαστικού αντιγράφου το `define('EVC_CLUB_MIGRATIONS_TARGET', 3);` σε
   `define('EVC_CLUB_MIGRATIONS_TARGET', 4);` (με το `EVC_CLUB_MIGRATIONS_ENABLED` ενεργό προσωρινά)·
4. Εργαλεία → «Evangelou Club — Βάση δεδομένων»: όλοι οι έλεγχοι πράσινοι, μετά εφαρμογή (εκκρεμεί μόνο η 004)·
5. ο τεχνικός αφαιρεί ξανά το `EVC_CLUB_MIGRATIONS_ENABLED`·
6. στην «Ετοιμότητα», η γραμμή «Καταγραφή προετοιμασίας καφέ στο ταμείο (μετάβαση 004)» γράφει «Εντάξει».

**Τι βλέπετε μετά την εφαρμογή:** στο ταμείο, μετά τη σάρωση, οι 3 τελευταίοι καφέδες του μέλους (ημερομηνία,
καφές, πώς φτιάχτηκε, η σημείωση) και το μεγάλο κουμπί «Το ίδιο με την τελευταία φορά»· ο καφές διαλέγεται με ένα
μεγάλο κουμπί σε κάθε βήμα (μόνο τα βήματα του συγκεκριμένου καφέ), μετά έλεγχος της παραγγελίας με τα επιπλέον
(πάγος, αφρόγαλα, χωρίς καφεΐνη), τις γρήγορες σημειώσεις και τη σημείωση, και «Καταχώρηση καφέ». Στην κάρτα του
μέλους, το «Ιστορικό» δείχνει τους 5 τελευταίους καφέδες του, χωρίς τη σημείωση του ταμείου. **Έλεγχος στο
δοκιμαστικό αντίγραφο:** σαρώστε ένα δοκιμαστικό μέλος, καταχωρήστε Freddo Espresso «Μεγάλο μέγεθος · Τριπλός» με
μια σημείωση· σαρώστε ξανά: ο καφές φαίνεται στους «Τελευταίους καφέδες» με τη σημείωση και δεύτερος καφές δεν
δίνεται· την επόμενη μέρα το «Το ίδιο» συμπληρώνει την παραγγελία και αρκεί το «Καταχώρηση καφέ»· στην κάρτα, το
«Ιστορικό» δείχνει τον καφέ χωρίς τη σημείωση.

**Απόφαση D25 (20/11/2026, 13:09):** εγκρίνατε την εφαρμογή της μετάβασης 004 **μόνο στο δοκιμαστικό αντίγραφο**. Την εφαρμόζει ο τεχνικός με τα βήματα 2 έως 6 παραπάνω. Για το κανονικό site η 004 θέλει ξεχωριστή έγκρισή σας. **Κίνδυνος για απόφαση (K18):** η σημείωση του ταμείου
είναι ελεύθερο κείμενο (έως 80 χαρακτήρες) και μένει στη βάση μαζί με τον καφέ του μέλους χωρίς όριο χρόνου· η
απόφαση D9 (πόσο κρατάμε το ιστορικό) είναι ακόμη ανοιχτή. Προτείνουμε να ορίσετε πόσο καιρό κρατιούνται οι
σημειώσεις και να πείτε στο προσωπικό να γράφει μόνο επιθυμίες για τον καφέ, ποτέ στοιχεία υγείας ή προσωπικά.

**Απόφαση D26, μέρος 1 (20/11/2026):** η σελίδα μέλους μπαίνει στην αρχική οθόνη του κινητού σαν εφαρμογή, με το
δικό της εικονίδιο (το φτερό του λογότυπου). Στο Android το κινητό ρωτά «Εγκατάσταση»· στο iPhone ο πελάτης πατά στο
Safari «Κοινή χρήση» και μετά «Προσθήκη στην οθόνη Αφετηρίας». Ειδοποιήσεις δεν υπάρχουν ακόμη (έρχονται στον επόμενο
γύρο, με τη μετάβαση 005). Χρειάζονται οι «όμορφοι» σύνδεσμοι (Ρυθμίσεις → Μόνιμοι σύνδεσμοι → «Όνομα άρθρου»)·
μετά την εγκατάσταση του νέου πακέτου ανοίξτε αυτή τη σελίδα ρυθμίσεων μία φορά και πατήστε «Αποθήκευση». Τα βήματα
δοκιμής σε πραγματικό Android και iPhone είναι στο [member-pwa.md](member-pwa.md).

**Απόφαση D27 (20/11/2026):** ανανέωση από την κάρτα. Όταν η συνδρομή έχει **λήξει**, το κουμπί «Ανανέωση 20 €»
οδηγεί στην ίδια πληρωμή με την εγγραφή. Όσο η συνδρομή είναι ενεργή, κουμπί ανανέωσης δεν εμφανίζεται. Όταν
περιμένουμε τραπεζική μεταφορά, η κάρτα γράφει ότι τα στοιχεία του λογαριασμού είναι στο μήνυμα της παραγγελίας και ότι
η συνδρομή ενεργοποιείται μόλις το κατάστημα επιβεβαιώσει τη μεταφορά. Σε ακυρωμένη ή μη επιβεβαιωμένη συνδρομή δεν
εμφανίζεται κουμπί· ο πελάτης μιλά με το κατάστημα. Δύο ταυτόχρονες πληρωμές συνδρομής από τον ίδιο λογαριασμό δεν
περνούν πια και οι δύο (K5).

**Γνωστοί κίνδυνοι** που έχετε αποφασίσει ή έχουν μέτρα: δείτε τον πίνακα «Known risks» (K1–K20) παραπάνω· ο
τεχνικός μπορεί να σας τους εξηγήσει έναν έναν.
