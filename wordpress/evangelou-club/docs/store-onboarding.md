# In-store QR onboarding (STAGE-07)

**Status: built and tested, default OFF.** Nothing is hooked, registered or served unless wp-config.php defines
`EVC_CLUB_ONBOARDING_ENABLED` as the boolean `true` **and** the customer surface is on
(`EVC_CLUB_CUSTOMER_ENABLED === true`, because the flow ends in the member area). Enabling it on any real site is a
separate owner and technician step. It has not been done.

## What it does

A customer at the till scans the QR on the shop tablet. A short mobile flow follows, with the approved UI-02
onboarding screens (register → consents → offer) connected to the real site:

1. **Account.** Name, Greek mobile, e-mail (required) and password, by the existing CUSTOMER-01 rules. A customer
   who already has an account signs in instead, on the normal WordPress login, which returns to the join page.
2. **Terms.** Only the Club terms, required. **No marketing or newsletter choice is shown** (owner decision D12).
3. **Offer.** "Πληρωμή 20 €" opens the shop's existing WooCommerce checkout with the existing Club product in the
   cart. The payment is by bank transfer, as today, and stays pending until the shop confirms it through the
   existing STAGE-03D path. Nothing new about payments.
4. **Member area** (`/club-member/`, STAGE-05D). It shows "pending payment" and, once the shop confirms, the
   member QR.

A signed-in customer who already has an active membership, a membership the Club cannot verify yet, or an open Club
order is **never** sent to pay again. They go to the member area instead.

## Owner decision D12

**Decided by the owner (2026-11-06):** the onboarding has no newsletter checkbox for now. It does not create, change
or tag any FluentCRM contact. The STAGE-06 tag sync, when the owner enables it, is the only FluentCRM path.

> **Απόφαση ιδιοκτήτη (D12):** Στην εγγραφή στο κατάστημα δεν υπάρχει επιλογή για newsletter. Η εγγραφή δεν
> δημιουργεί, δεν αλλάζει και δεν βάζει ετικέτες σε επαφές του FluentCRM.

This rests on one FluentCRM setting that must stay as audited: **FluentCRM → Settings → General → "create new
FluentCRM contacts on WP user registration" must remain unchecked** (it was unchecked in the audit). If someone
checks it, FluentCRM itself would create a contact for every new account. This plugin never calls FluentCRM; the
packager guard enforces that.

## Configuration (wp-config.php)

```php
define('EVC_CLUB_CUSTOMER_ENABLED', true);      // already required by the member area
define('EVC_CLUB_ONBOARDING_ENABLED', true);    // exact boolean true only
define('EVC_CLUB_TERMS_URL', 'https://…/oroi-club/'); // optional: the Club terms page, same site only
```

The checkout hand-off also needs the existing commerce source constants (`EVC_CLUB_MEMBERSHIP_SOURCE`, the level,
the product and the evidence key). Without them, the join flow says "unavailable" and never opens a checkout. The
privacy link is WordPress's own privacy policy page, when one is set.

## Pages and routes

| What | Where | Who |
|---|---|---|
| Join page | `/club/join/` (plain permalinks: `/?evc_club_join=1`) | anyone |
| Tablet poster | `/club/join/poster/` (plain: `/?evc_club_join=poster`) | the shared Club staff account, shop managers (`manage_woocommerce`), administrators; signed out → WordPress login |
| `POST …/evangelou-club-customer/v1/join/account` | public | creates the account |
| `POST …/join/terms` | customer (cookie + nonce) | records the current Club terms |
| `POST …/join/checkout` | customer | `member` or `checkout`, with a server-built URL |

All pages are private and no-store, with `noindex`, no-referrer, nosniff, `frame-ancestors 'none'` and a self-only
CSP. The poster's QR encodes **only** the public join URL plus the fixed marker `src=store`: no secret, no nonce and
nothing about anyone. The front end refuses any other parameter. The poster needs no network once loaded and has a
reload button.

## Security

- **Accounts.** Created with WordPress core (`wp_insert_user`, role `customer`), then signed in
  (`wp_set_auth_cookie`). No e-mail is sent by this step. The login name is random (`club_` + 12 hex), so it reveals
  nothing. The billing name, e-mail, phone (`+3069…`) and country are prefilled for the checkout.
- **Existing e-mail.** It is never attached to, signed into or changed. The answer is the neutral `check_account`
  ("Ίσως έχετε ήδη λογαριασμό — συνδεθείτε ή επαναφέρετε τον κωδικό"), the same for every reason an e-mail cannot be
  used (it exists as an e-mail or as a user name, or the account could not be created). It takes the same password
  hashing work, so the timing does not tell the cases apart. **Limit:** a successful registration necessarily shows
  that the e-mail was free. Avoiding that needs an e-mail confirmation step (owner question 2).
- **Password policy** (the server; the browser only hints): 10–128 characters, not a known common password, not one
  repeated character, and not containing the e-mail's name or the mobile number.
- **Abuse protection** (the D9 style): at most 10 failures per 5 minutes per client address, then 429 until the
  window ends. Failures are invalid input, an existing e-mail, the honeypot and a refused origin. The key is an HMAC
  of `REMOTE_ADDR`; no IP address is stored. There is also a hidden honeypot field and no CAPTCHA service. The
  counter is a transient, so two exactly parallel requests may both count as the 9th; that is accepted.
- **CSRF.** Every write needs the page's `wp_rest` nonce. WordPress core verifies it before the route for signed-out
  visitors too. An `Origin` header, when sent, must be this site. The terms and checkout routes use the customer
  authorization of the member area (cookie, own nonce check, customer accounts only).
- **No open redirect.** Every URL the flow uses comes from the server's bootstrap or answer and is checked to be this
  site, on the server and again in the browser. `?redirect_to=` and `?next=` on the join page are ignored.
- **No personal data in logs.** Only class names on a wiring failure; the tests assert it.
- **Construction and guards.** The registration, hand-off, shell and REST controller are constructed only in
  `EVC_Onboarding_Plugin::wire()`, behind the gate. They also refuse construction while the flag is off. The packager
  enforces that creating or signing in a user happens only in the registration file, and that an add-to-cart URL is
  built only in the hand-off file (`app/scripts/php-guards.mjs`, STAGE-07 rules, with self-tests).

## Tests

- **PHP unit** `OnboardingRulesTest`: name, mobile, required e-mail, password policy, failure window, client key.
- **WordPress** `wp-onboarding` (customer + onboarding flags):
  - the pages, steps and poster access;
  - private headers;
  - a new account as a signed-in customer;
  - an existing e-mail never attached or revealed;
  - field codes, honeypot, bad nonce and foreign origin;
  - the rate limit, including the window and another address;
  - no personal data in logs or options;
  - terms with version;
  - authorization of the signed-in routes;
  - no checkout without a configured product.

  `wp-common` `OnboardingOffTest` proves that nothing exists while the flag is off.
- **Real WooCommerce** `wp-commerce-onboarding` (CI commerce job, HPOS and posts storage):
  - the whole journey: new account → terms → checkout hand-off → on-hold order → member area pending → the shop
    confirms → active → member QR;
  - an existing e-mail creates nothing;
  - an active member goes straight to the member area;
  - pending, on-hold and processing orders are never doubled;
  - an ended membership with a waiting renewal is never doubled;
  - a cancelled order or a non-Club order leaves the offer open;
  - another customer's order never counts.
- **Frontend**:
  - `joinModel.test.ts`: rules pinned to the approved CUSTOMER-01 rules, same-site configs, strict join-v1 client;
  - `joinFlow.test.ts`: jsdom flow, accessibility (labels, described errors, focus), D12 with a single checkbox,
    double tap, poster QR.
- **Mutants**:
  - OR1–OR10 (rules);
  - OB1–OB18 (WordPress; OB2/OB10 not added: the customer-flag requirement is not observable in any suite, and the
    origin comparison is covered by OB9);
  - OC1–OC5 (real commerce);
  - JM1–JM15 (frontend).

## Operator note (Greek): how to put up the tablet poster

> **Οδηγίες για το κατάστημα — η αφίσα εγγραφής στο tablet**
>
> 1. Στο tablet του ταμείου ανοίξτε τον browser και συνδεθείτε με τον λογαριασμό του Club (ή με λογαριασμό
>    διαχειριστή του καταστήματος).
> 2. Ανοίξτε τη διεύθυνση **…/club/join/poster/** (ή **…/?evc_club_join=poster**, αν ο ιστότοπος δεν έχει «όμορφα»
>    links). Θα δείτε ένα μεγάλο QR και τον τίτλο «Ένας καφές κάθε μέρα. Γραφτείτε τώρα.»
> 3. Αφήστε τη σελίδα ανοιχτή σε πλήρη οθόνη, με την οθόνη να μη σβήνει (Ρυθμίσεις → Οθόνη → «Αυτόματο κλείδωμα:
>    Ποτέ» όσο είναι ανοιχτό το κατάστημα).
> 4. Ο πελάτης σκανάρει το QR με την κάμερα του κινητού του και ακολουθεί τα βήματα στο δικό του κινητό. Στο tablet
>    δεν πληκτρολογεί τίποτα.
> 5. Αν το tablet χάσει το internet, το QR **συνεχίζει να λειτουργεί**: είναι σταθερό και δεν περιέχει κανένα
>    προσωπικό στοιχείο. Αν η σελίδα κολλήσει, πατήστε **«Ανανέωση»**.
> 6. Η πληρωμή γίνεται με τραπεζική μεταφορά, όπως σήμερα. Όταν δείτε τη μεταφορά, ολοκληρώστε την παραγγελία στο
>    WooCommerce («Ολοκληρωμένη») και ο πελάτης βλέπει αμέσως την κάρτα μέλους του.
> 7. Αν πελάτης πει ότι «υπάρχει ήδη λογαριασμός», να συνδεθεί ή να πατήσει «Επαναφορά κωδικού». Το κατάστημα δεν
>    αλλάζει ποτέ λογαριασμούς πελατών με βάση το τηλέφωνο ή το όνομα.

## Open owner questions (Greek)

1. **Όροι του Club:** Πού βρίσκεται το κείμενο των όρων του Club που αποδέχεται ο πελάτης; Χρειαζόμαστε μια σελίδα
   στον ιστότοπο (θα μπει στη ρύθμιση `EVC_CLUB_TERMS_URL`). Μέχρι τότε εμφανίζεται μόνο η πρόταση των όρων και ο
   σύνδεσμος της πολιτικής απορρήτου.
2. **Επιβεβαίωση e-mail:** Θέλετε ο νέος λογαριασμός να επιβεβαιώνει το e-mail του (με σύνδεσμο στο e-mail) πριν
   πάει στην πληρωμή; Είναι πιο ασφαλές (κανείς δεν μαθαίνει αν ένα e-mail είναι ήδη γραμμένο), αλλά προσθέτει ένα
   βήμα στο ταμείο.
3. **Τρόποι πληρωμής στο checkout:** Στη σελίδα πληρωμής του WooCommerce ο πελάτης βλέπει όσους τρόπους πληρωμής
   είναι ενεργοί. Να μείνει ενεργή μόνο η τραπεζική μεταφορά για τη συνδρομή;
4. **Wi-Fi του καταστήματος:** Όσοι πελάτες είναι στο ίδιο Wi-Fi φαίνονται από την ίδια διεύθυνση. Το όριο είναι 10
   λανθασμένες προσπάθειες ανά 5 λεπτά (όχι οι επιτυχημένες εγγραφές). Σας φτάνει αυτό, ή προτιμάτε μεγαλύτερο όριο
   στο Wi-Fi του καταστήματος;

## Go-live checklist (owner + technician; nothing here is done by this stage)

1. The owner answers the questions above (at least the terms page).
2. On **staging**: the customer flag and the onboarding flag; pretty permalinks or the `?evc_club_join=` links;
   the poster on a test tablet; one test account through the whole flow with a test bank transfer that the shop
   confirms.
3. Confirm that FluentCRM's "create new contacts on WP user registration" is still unchecked (D12).
4. Confirm the WooCommerce checkout offers the payment methods the owner wants for the Club product.
5. Only then set `EVC_CLUB_ONBOARDING_ENABLED` to `true` in production (owner approval required).
