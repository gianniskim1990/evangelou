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
   cart, straight away: there is **no e-mail confirmation step** (owner decision D14). The payment is by bank
   transfer only (owner decision D15) and stays pending until the shop confirms it through the existing STAGE-03D
   path. No new payment code.
4. **Member area** (`/club-member/`, STAGE-05D). It shows "pending payment" and, once the shop confirms, the
   member QR.

A signed-in customer who already has an active membership, a membership the Club cannot verify yet, or an open Club
order is **never** sent to pay again. They go to the member area instead.

## Owner decisions

| | Decision (2026-11-06) | Where it is built |
|---|---|---|
| D12 | No newsletter or marketing choice; the onboarding never touches FluentCRM. | below |
| D13 | The Club terms page already exists. The technician sets `EVC_CLUB_TERMS_URL` to it; no URL ships in code. Without it, the terms step shows the terms sentence and the privacy link only (the current fallback). | Configuration |
| D14 | No e-mail confirmation step: from the account straight to the terms and payment. | Security: what remains possible |
| D15 | Bank transfer (`bacs`) is the ONLY payment method for the Club subscription. If it is not available, no method is offered and a Greek message says so (fail closed). Other products are unchanged. | WooCommerce cart and checkout |
| D16 | The failure limit stays at 10 failures per 5 minutes per client. | Security: abuse protection |

### D12

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
define('EVC_CLUB_TERMS_URL', 'https://…/oroi-club/'); // D13: the EXISTING Club terms page, same site only
// Optional success caps (exact int 1..1000; anything else means the default):
define('EVC_CLUB_JOIN_MAX_PER_CLIENT_HOUR', 3);  // new accounts per client address per hour (default 3)
define('EVC_CLUB_JOIN_MAX_PER_HOUR', 30);        // new accounts per hour in total (default 30)
```

**Shop Wi-Fi.** Customers on the shop's Wi-Fi share one public address, so by default only **3 accounts per hour**
can be created from it (customers on mobile data are separate clients). If the shop expects more sign-ups an hour on
its Wi-Fi, the technician raises `EVC_CLUB_JOIN_MAX_PER_CLIENT_HOUR` (the total cap still applies).

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
CSP. They are never page-cached: `DONOTCACHEPAGE` is defined for caching plugins and WordPress's own no-cache
headers (`wp_get_nocache_headers()`, sent with `nocache_headers()`) are part of every response. The poster's QR encodes **only** the public join URL plus the fixed marker `src=store`: no secret, no nonce and
nothing about anyone. The front end refuses any other parameter. The poster needs no network once loaded and has a
reload button.

## Security

- **Accounts.** Created with WordPress core (`wp_insert_user`, role `customer`), then signed in
  (`wp_set_auth_cookie`). No e-mail is sent by this step. The login name is random (`club_` + 12 hex), so it reveals
  nothing. The billing name, e-mail, phone (`+3069…`) and country are prefilled for the checkout.
- **WordPress's "Anyone can register" setting is bypassed on purpose.** This is a separate, flag-gated sign-up of
  Club customers only (role `customer`, never any other role); it works whether that core setting is on or off, and
  it does not turn on the core registration form.
- **The phone number is unverified.** Anyone can type any number. It is stored only as the WooCommerce billing phone
  to prefill the checkout. It must never identify a member, look up an account, or become a phone directory.
- **Existing e-mail.** It is never attached to, signed into or changed. The answer is the neutral `check_account`
  ("Ίσως έχετε ήδη λογαριασμό — συνδεθείτε ή επαναφέρετε τον κωδικό"), the same for every reason an e-mail cannot be
  used (it exists as an e-mail or as a user name, or the account could not be created). The server hashes the
  password on this path as on the success path; no timing guarantee is claimed.
- **What remains possible (D14, honest limits).** Without an e-mail confirmation step:
  - *Enumeration:* a successful registration necessarily shows that the e-mail was free, and `check_account` hints
    that it may not be. The success and failure caps below slow this down; they do not prevent it.
  - *Pre-registration:* someone can create an account with another person's e-mail before that person does. The
    real owner of the e-mail recovers it with **"Ξέχασα τον κωδικό"** (WordPress password reset, which goes to that
    e-mail). The squatter cannot pay as them without that mailbox, and no membership exists until the shop confirms a
    bank transfer.
- **Password policy** (the server; the browser only hints): 10–128 characters, not a known common password, not one
  repeated character, and not containing the e-mail's name or the mobile number.
- **Password policy** (the server; the browser only hints): 10–128 characters, not a known common password, not one
  repeated character, and not containing the e-mail's name or the mobile number.
- **Abuse protection.**
  - **Failures** (D16): at most 10 per 5 minutes per client, then 429 until the window ends. Failures are invalid
    input, an existing e-mail, the honeypot and a refused origin (with a valid nonce).
  - **Successes:** at most `EVC_CLUB_JOIN_MAX_PER_CLIENT_HOUR` (default 3) new accounts per client per hour and
    `EVC_CLUB_JOIN_MAX_PER_HOUR` (default 30) in total per hour. A slot is reserved **before** the account is
    created; over a cap the answer is the neutral `try_later` ("Η εγγραφή δεν είναι δυνατή αυτή τη στιγμή. Δοκιμάστε
    ξανά αργότερα ή ρωτήστε στο ταμείο."), nothing is created, and it is not counted as a failure.
  - **A request without a nonce is refused and NOT counted** (otherwise anyone could spend a client's budget from
    another site). WordPress core refuses a request with an invalid nonce before the route.
  - **The client** is `REMOTE_ADDR`: an IPv4 address as is, an IPv4-mapped IPv6 address as its IPv4 address, and any
    other IPv6 address by its **/64** network (one household or phone usually holds a whole /64). The key is an HMAC
    of that with a secret **derived** from the WordPress `AUTH_SALT` with its own label
    (`evangelou-club/join-rate-limit-key/v1`), so it is never the salt itself and no address is stored.
  - **Counters are atomic.** Each event is ONE `INSERT … ON DUPLICATE KEY UPDATE` on a row of the WordPress options
    table (`evc_join_rl_<f|s|g>_<key>`, value `count|window_start`, autoload off): parallel requests cannot both see
    the old count. The options table was chosen so that no Club database migration is needed. Expired rows are
    deleted now and then (they hold only a count and a time). A concurrent burst is not driven by a test (the WordPress
    test case runs in one transaction on one connection); the tests assert instead that every counted event is one
    such statement, never a read-modify-write or a transient, which is what makes the burst safe.
  - A hidden honeypot field (`evc_extra`, `autocomplete="off"`, a name browsers do not autofill); no CAPTCHA service.
- **CSRF.** Every write needs the page's `wp_rest` nonce (see above). An `Origin` header, when sent, must be this
  site. The terms and checkout routes use the customer
  authorization of the member area (cookie, own nonce check, customer accounts only).
- **No open redirect.** Every URL the flow uses comes from the server's bootstrap or answer and is checked to be this
  site, on the server and again in the browser. `?redirect_to=` and `?next=` on the join page are ignored.
- **No personal data in logs.** Only class names on a wiring failure; the tests assert it.
- **Construction and guards.** The registration, hand-off, shell, REST controller and checkout guard are constructed
  only in `EVC_Onboarding_Plugin::wire()`, behind the gate. They also refuse construction while the flag is off. The
  packager enforces that creating or signing in a user happens only in the registration file, that an add-to-cart
  URL is built only in the hand-off file, and that only the checkout guard hooks the WooCommerce cart, checkout and
  gateway filters (`app/scripts/php-guards.mjs`, STAGE-07 rules, with self-tests).

## WooCommerce cart and checkout (round 2)

With the flag on, `EVC_Onboarding_Checkout_Guard` applies the Club rules on the shop's own cart and checkout, so they
hold outside the join flow too (a second device, a saved `?add-to-cart=` link). Products other than the Club product
are untouched.

- **Bought alone (mixed-cart decision).** The Club subscription is bought on its own: adding it to a cart that holds
  other products is refused, adding other products to a cart that holds it is refused, and a checkout of a mixed
  cart is refused ("Η συνδρομή του Evangelou Club αγοράζεται μόνη της…"). Reason: the Club verifies a Club order only
  as exactly one Club item at 20.00 EUR (`EVC_Wp_Commerce_Pmpro_Reader`); a mixed order would never become a
  membership. The product is also sold individually (quantity 1).
- **No second order** (`woocommerce_add_to_cart_validation` and `woocommerce_after_checkout_validation`): refused
  while the customer has an open Club order (pending, on-hold, processing) or a membership that reads pending
  payment or unverified, the same rule as the join page. An **active** or ended member may buy it again (early
  renewal, decision D2).
- **An account is required.** A guest Club checkout is refused unless the customer creates an account at that
  checkout; a guest order could never be attributed to a member.
- **Bank transfer only (D15).** `woocommerce_available_payment_gateways`: when the Club product is in the cart (or is
  the order on the "pay for order" page), only `bacs` is offered. If bank transfer is disabled, the list is empty and
  `woocommerce_no_available_payment_methods_message` shows: "Η συνδρομή του Club πληρώνεται μόνο με τραπεζική
  μεταφορά, που δεν είναι διαθέσιμη αυτή τη στιγμή. Ρωτήστε στο κατάστημα."
- **Open orders are read in full.** The check reads all of the customer's open orders (`limit => -1`, ids only), so
  an older open Club order is found however many newer orders exist.

## Tests

- **PHP unit** `OnboardingRulesTest`: name, mobile, required e-mail, password policy, failure window, client key
  (IPv6 /64, IPv4-mapped, invalid input), the derived secret, the configured caps, the stored counter.
- **WordPress** `wp-onboarding` (customer + onboarding flags):
  - the pages, steps and poster access;
  - private headers;
  - a new account as a signed-in customer;
  - an existing e-mail never attached or revealed;
  - field codes, honeypot, bad nonce, missing nonce (not counted) and foreign origin (counted);
  - the failure limit, including the window, a new window and another address;
  - the success caps per client (3) and in total (30) → `try_later`;
  - IPv6 counted by /64, IPv4-mapped counted as IPv4;
  - one atomic statement per counted event, no transient, no address stored;
  - `DONOTCACHEPAGE` and the no-cache headers on every page;
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
  - another customer's order never counts;
  - an older Club order is found behind 55 newer open Club orders of other customers, and behind 51 newer open
    orders of the same customer for other products;
  - the cart: Club product alone, never into or with other products, never a second order (open order), early
    renewal for an active member;
  - the checkout validation: mixed cart, guest without an account, second order;
  - the gateways with the real `WC_Payment_Gateways`: other products keep every gateway, the Club product gets only
    `bacs`, and nothing (with the Greek message) when bank transfer is off;
  - the guard's reader of the WooCommerce cart.
- **Frontend**:
  - `joinModel.test.ts`: rules pinned to the approved CUSTOMER-01 rules, same-site configs, strict join-v1 client;
  - `joinFlow.test.ts`: jsdom flow, accessibility (labels, described errors, focus), D12 with a single checkbox,
    double tap, poster QR, the `try_later` message, the honeypot's name and `autocomplete`.
- **Mutants**:
  - OR1–OR15 (rules);
  - OB1–OB28 (WordPress; OB2/OB10 not added: the customer-flag requirement is not observable in any suite, and the
    origin comparison is covered by OB9);
  - OC1–OC6 (real commerce; round 2 restored OC3, because the order query is now the only customer filter, and added
    OC6 for the page limit) and OG1–OG13 (the checkout guard);
  - JM1–JM18 (frontend).

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

## Owner answers (Greek, 2026-11-06)

1. **Όροι του Club (D13):** Η σελίδα των όρων υπάρχει ήδη. Ο τεχνικός βάζει τη διεύθυνσή της στη ρύθμιση
   `EVC_CLUB_TERMS_URL`. Χωρίς τη ρύθμιση εμφανίζεται μόνο η πρόταση των όρων και ο σύνδεσμος της πολιτικής απορρήτου.
2. **Επιβεβαίωση e-mail (D14):** Όχι. Από τον λογαριασμό κατευθείαν στην πληρωμή. Αν κάποιος γράψει λογαριασμό με
   ξένο e-mail, ο πραγματικός κάτοχος τον παίρνει πίσω με «Ξέχασα τον κωδικό».
3. **Τρόποι πληρωμής (D15):** Για τη συνδρομή μόνο τραπεζική μεταφορά. Αν δεν είναι διαθέσιμη, δεν εμφανίζεται
   κανένας τρόπος πληρωμής και ο πελάτης βλέπει μήνυμα. Τα άλλα προϊόντα δεν αλλάζουν.
4. **Όριο προσπαθειών (D16):** Μένει 10 λανθασμένες προσπάθειες ανά 5 λεπτά. Επιπλέον, από την ίδια διεύθυνση
   γίνονται έως 3 νέοι λογαριασμοί την ώρα (και έως 30 συνολικά την ώρα). Στο Wi-Fi του καταστήματος όλοι οι πελάτες
   φαίνονται από την ίδια διεύθυνση: αν χρειάζεστε περισσότερους, ο τεχνικός αυξάνει τη ρύθμιση
   `EVC_CLUB_JOIN_MAX_PER_CLIENT_HOUR`.

## Go-live checklist (owner + technician; nothing here is done by this stage)

1. Set `EVC_CLUB_TERMS_URL` to the existing Club terms page (D13); decide the success caps for the shop Wi-Fi.
2. **`REMOTE_ADDR` is the real client.** Behind a reverse proxy, load balancer or CDN (Cloudflare and the like),
   `REMOTE_ADDR` is the proxy's address, and then every customer shares ONE budget (3 accounts an hour for the whole
   site, and 10 failures lock everyone out). Before enabling: open a page that shows `$_SERVER['REMOTE_ADDR']` from a
   phone on mobile data and from the shop Wi-Fi, and check that each shows its own public address. If not, the
   server (not this plugin) must restore the client address from the trusted proxy only (nginx `real_ip`, Apache
   `mod_remoteip`, Cloudflare's documented module). This plugin never reads `X-Forwarded-For` itself.
3. **FluentCRM must not see new accounts (D12).** All three must hold:
   - FluentCRM → Settings → General → "create new FluentCRM contacts on WP user registration" stays **unchecked**;
   - no FluentCRM automation funnel with a **"New User Sign Up"** (user registration) trigger is active, or every
     such funnel excludes the `customer` role;
   - the FluentCRM **WooCommerce integration** (customer / order sync into contacts) is **off**, or excludes these
     customers (otherwise the Club checkout itself creates a contact).
4. On **staging**: the customer flag and the onboarding flag; pretty permalinks or the `?evc_club_join=` links; the
   poster on a test tablet; one test account through the whole flow with a test bank transfer that the shop
   confirms; a cart with the Club product shows only bank transfer; a cart with coffee shows the usual methods.
5. Confirm a page cache (plugin or host) does not cache `/club/join/` (it should honour `DONOTCACHEPAGE` and the
   no-cache headers; exclude the path by hand if the host cache ignores them).
6. Only then set `EVC_CLUB_ONBOARDING_ENABLED` to `true` in production (owner approval required).
