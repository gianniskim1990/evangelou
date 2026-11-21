# Member push notifications (STAGE-08 round 12, owner decision D26 part 2)

Short notifications to members' phones, sent **from the shop's own server** (no third-party push service): the
member opts in on the member card; an administrator sends from «Evangelou Club → Ειδοποιήσεις»; two automatic
notifications can be switched on separately. Everything is **OFF by default** and fails closed.

## What is needed (all of it, or push stays hidden)

| Piece | Where | Check |
|---|---|---|
| `define('EVC_CLUB_PUSH_ENABLED', true);` (exact boolean) | wp-config.php | readiness «Ειδοποιήσεις μελών» |
| the member page (`EVC_CLUB_CUSTOMER_ENABLED`) and recorded payments (`EVC_CLUB_PAYMENT_PROVENANCE = 'club_db_v1'`) | wp-config.php | existing rows |
| VAPID keys + subject (below) | wp-config.php | readiness «Κλειδιά ειδοποιήσεων» (never shows a value) |
| migration **005** applied (needs its own owner approval) + SELECT, INSERT, UPDATE on `evc_push_subscriptions` | Club DB | readiness «πίνακας συνδρομών (μετάβαση 005)» (reads SHOW GRANTS) |
| «Post name» permalinks (the service worker) and `/club-member/sw.js` reachable | WordPress / web server | readiness «εφαρμογή παρασκηνίου» + docs/member-pwa.md |
| outbound HTTPS to the push services | hosting | technician (2026-11-20: FCM, Apple, Mozilla allowed; Windows `*.notify.windows.com` not confirmed) |

Optional, each exact `true`, each also needs `EVC_CLUB_PUSH_ENABLED`:
`EVC_CLUB_PUSH_AUTO_ACTIVATED` («Η συνδρομή σας ενεργοποιήθηκε» when the shop completes the order) and
`EVC_CLUB_PUSH_AUTO_EXPIRY` («Η συνδρομή σας λήγει σε 3 ημέρες», daily at 10:00 Athens).

## VAPID keys: made where they are used, never sent to anyone

**Choice:** a one-line command, not an admin button. A button would put the private key into a web page (browser
history, caches, screenshots, a shared screen); the command prints it only in the terminal of the person who pastes it
into wp-config.php anyway (the technician). The private key is **never stored in a database** and never shown by the plugin.

On the server over SSH (the hosting has the latest PHP with openssl), or on the technician's own computer with PHP:

```
php -r '$k=openssl_pkey_new(["curve_name"=>"prime256v1","private_key_type"=>OPENSSL_KEYTYPE_EC]);$e=openssl_pkey_get_details($k)["ec"];$b=function($s){return rtrim(strtr(base64_encode($s),"+/","-_"),"=");};$p=function($s){return str_pad($s,32,"\0",STR_PAD_LEFT);};echo "define(\x27EVC_CLUB_PUSH_VAPID_PUBLIC\x27, \x27".$b("\x04".$p($e["x"]).$p($e["y"]))."\x27);\ndefine(\x27EVC_CLUB_PUSH_VAPID_PRIVATE\x27, \x27".$b($p($e["d"]))."\x27);\n";'
```

It prints two `define(...)` lines: paste both into wp-config.php, add the subject line, and close the terminal:

```
define('EVC_CLUB_PUSH_SUBJECT', 'mailto:<the shop's real e-mail address>');
```

(The command is tested exactly as written here: `tests/unit/PushVapidTest.php`.) Staging and production get
**different** key pairs. Changing the keys later means every member taps «Θέλω ειδοποιήσεις» again: the app notices that
its subscription was made for other keys and makes a new one at the next open, if permission is still granted.

`EVC_Push_Vapid::keys_problem()` checks the public key is a P-256 point, the private key a valid scalar, that the public
key **is** the private key's (OpenSSL derives it), and the subject a `mailto:` of a real domain. Any problem: no
`pushPublicKey` in the member page, `/me/push` answers 503, nothing is sent.

## Crypto: written on openssl, no library

`EVC_Push_Crypto` implements RFC 8291 (aes128gcm message encryption, ECDH P-256 + HKDF-SHA-256 + AES-128-GCM, one
4096-octet record) and `EVC_Push_Vapid` RFC 8292 (ES256 JWT, `Authorization: vapid t=…, k=…`) directly on PHP's
openssl extension (`openssl_pkey_derive`, `openssl_sign`, `openssl_encrypt`, `hash_hkdf`); no gmp / bcmath needed.
**Why not a library** (e.g. minishlink/web-push, MIT): it pulls a dependency tree (guzzle, jwt-framework,
spomky-labs/base64url, …) that would have to be vendored, pinned, licence-checked and packaged into a plugin that today
ships no third-party PHP at all, for about 200 lines of well-specified crypto. The RFC 8291 example (section 5) and its
intermediate values (Appendix A) are reproduced byte for byte in `tests/unit/PushCryptoTest.php`; the JWT is verified
with OpenSSL against the public key in `PushVapidTest`. The subscriber's key is checked to be on the curve (RFC 8291
section 6: OpenSSL refuses an off-curve point when parsing).

## Storage: migration 005 (`evc_push_subscriptions`)

Add-only, one table, bounded columns (see the SQL header): member id, endpoint (VARCHAR 1024, needed to send) and its
SHA-256 (unique key), p256dh / auth, coarse `push_service` (fcm / apple / mozilla / windows) and `agent_family`
(chrome / safari / firefox / edge / samsung / other; never the User-Agent text), `consent_at_utc` (the tap: GDPR basis),
created / updated, last success, success and consecutive failure counters, last failure, `revoked_at_utc` +
`revoked_reason` ('member' = stopped or signed out, 'gone' = 404 / 410). Rows are never deleted.
**Runtime grants:** SELECT, INSERT, UPDATE on `evc_push_subscriptions` (SELECT on `evc_members` exists already).

## Customer side

- Shown only when ALL hold: the page has `pushPublicKey`; GET `/me/push` says `ready`; the browser has a service
  worker, Push and Notifications; **on iPhone / iPad only when running from the home screen** (in Safari a hint to
  install first, with Apple's Greek labels); **plain permalinks: no worker, no push** (the control is replaced by a short
  explanation).
- «Θέλω ειδοποιήσεις» + a short explanation. Only that tap calls `Notification.requestPermission()`, then
  `pushManager.subscribe()` on the round-11 worker's registration, then POST `/me/push` with `consent: true`.
  «Διακοπή ειδοποιήσεων» unsubscribes in the browser and DELETEs on the server. Signing out stops them too (so the next
  account on the same phone never gets the previous member's notifications).
- `409 endpoint_in_use` (the browser's subscription is another member's active row): the app drops it, subscribes again
  (a new endpoint) and retries once.
- **Server-side sign-outs do NOT revoke push rows (round 13, N-g; documented, not changed).** Only the app's own
  «Αποσύνδεση» button removes the device's subscription (it DELETEs on the server and unsubscribes in the browser before
  navigating). A sign-out that happens anywhere else — the WordPress session expiring, `wp-login.php?action=logout`
  from another page, «Log out everywhere else», a password change, an administrator destroying the sessions — leaves the
  row active, so that phone keeps receiving the member's notifications (title and text only: never a card, a QR or a
  link other than the member page, which asks for a sign-in again). What limits the effect: (1) when another account
  signs in on the same phone and turns notifications on, the browser's existing subscription is the first member's
  active row → `409 endpoint_in_use` → the app drops that browser subscription and makes a new one, so from then on the
  phone receives only the new member's notifications; (2) the member can tap «Διακοπή ειδοποιήσεων» any time; (3) a
  browser that removes its subscription answers 404 / 410 at the next send and the row is revoked. **Why not revoke on
  `wp_logout`:** the server cannot tell which of the member's rows belongs to the device that is signing out (a row is
  not bound to a login session, and binding it would need a new column, i.e. a migration); revoking ALL the member's rows
  would also silence their other phones. So nothing is revoked server-side.
- The client never prompts on load; the opt-in state is the browser's own subscription (no browser storage is added).

## Service worker

`push`: shows the payload's title and body (validated lengths; otherwise a generic Greek line, because a push must always
show a notification) with the app icon. `notificationclick`: focuses an open member page, else opens the scope
(`/club-member/`); **never a URL from the payload**. Every round-11 caching rule is unchanged.

## Administrator page «Ειδοποιήσεις»

Administrators only (`manage_options` + `evc_manage_club`, never the till role, never multisite); the till account never
sees it. Title ≤ 50, text ≤ 150 characters; audience: all subscribed members / active members / members expiring within
N days (1–30). Preview with recipient count → confirmation → one job sent in batches (25 subscriptions or 15 seconds per
batch, under a database lock; the open page submits the next batch itself, WP-Cron continues if it is closed). Result:
sent / failed / removed (404 / 410 → revoked). Rate limit: one send per 15 minutes, at most 4 per 24 hours. Audit
(newest 100): who, when, audience, counts; never an endpoint, key, member or text.

Round 13:

- **«Διακοπή αποστολής» is never lost.** The stop is first stored as its own row (`evc_club_push_cancel` = the job id,
  one `INSERT … ON DUPLICATE KEY UPDATE`, outside the job option that a running batch rewrites after every message). If no
  batch holds the lock, the job ends at once («Η αποστολή διακόπηκε.»). If a batch is sending at that moment, the page
  says «Η διακοπή καταχωρήθηκε, η αποστολή σταματά σε λίγα δευτερόλεπτα.» and the batch reads the stored stop again
  straight from the database (never the object cache) before every message, then ends the job itself. «Η αποστολή
  διακόπηκε» is shown only when the stored job really is «cancelled»; if the job had finished before the stop applied,
  the page says «Η αποστολή είχε ήδη ολοκληρωθεί πριν από τη διακοπή.». Only the lock holder writes «cancelled», so the
  audit never shows «cancelled» followed by «done». At most one message (the one in flight, ≤ 5 s timeout) goes after
  the tap.
- **Lock («Κλείδωμα»): no named lock = nothing is sent.** Sends use a MySQL / MariaDB named lock (`GET_LOCK`) of the
  WordPress database. If the server answers `NULL` or an error (some clusters / proxies refuse named locks), nothing is
  sent and the page says «Η βάση δεδομένων του ιστότοπου δεν επέτρεψε το κλείδωμα… Για ασφάλεια δεν στάλθηκε τίποτα.».
  The technician must then use a database server that supports `GET_LOCK` (standard MySQL 5.7+ / 8, MariaDB 10.x do).
  (This differs on purpose from the checkout lock of round 11, K5, which keeps working without named locks.)
- **Automatic notifications** run under their own named lock (`evc_club_push_auto_…`), so two WP-Cron runners at once
  never both send «Η συνδρομή σας ενεργοποιήθηκε» / «… λήγει σε 3 ημέρες» to a member; the second answers busy and the
  event comes back a minute later. The «once per period» markers are read from the database under that lock. No named
  lock = nothing is sent.
- **Rate limit store:** the 15-minute / 4-a-day limit is counted from its own option (`evc_club_push_starts`, the newest
  10 start times), not from the audit, which automatic entries can fill.

## Security notes

- SSRF: only `https://` + exactly `fcm.googleapis.com`, `web.push.apple.com`, `updates.push.services.mozilla.com` or
  `<one label>.notify.windows.com`, port 443, no credentials / fragment, printable ASCII, ≤ 1024; checked at subscribe
  AND before every send; `wp_remote_post` with `redirection => 0`, `reject_unsafe_urls`, TLS verified, 5 s timeout.
- Member isolation: the member is always `get_current_user_id()`; DELETE touches only the caller's row; POST never takes
  over another member's ACTIVE row.
- No endpoint, key or text in any log or audit entry. The private key lives only in wp-config.php.
- **Packager guards are accident prevention, not an anti-evasion boundary** (round 13). `pushProblemsFor` refuses, by
  reading the tokens of each shipped file: outgoing HTTP outside the sender (wp_remote_*, curl_*, the Requests library
  also under `WpOrg\Requests`, `WP_Http`, callable strings such as `'Requests::post'`), URL-capable file / stream
  functions (`file_get_contents`, `fopen`, `get_headers`, `stream_socket_client`, `fsockopen`, … — any call in a push
  file, a URL argument elsewhere), variable functions / `call_user_func` / `eval` / Reflection / `Closure::fromCallable`
  in push files, and the sender's real request named as a callable outside the sender. It does not follow data: names
  built by concatenation outside the push files, variable variables, included generated code, callbacks handed to
  WordPress or a third-party library, or a filter that rewrites a request are not detected. Code review stays the
  boundary; the guards stop an honest mistake from shipping unnoticed.

## Οδηγός για τον ιδιοκτήτη (απλά βήματα)

1. **Έγκριση της μετάβασης 005** μόνο για τη βάση δοκιμών (staging). Η παραγωγή θέλει δική της έγκριση αργότερα.
2. **Κλειδιά:** ο τεχνικός τρέχει την εντολή μίας γραμμής που υπάρχει πιο πάνω, στον διακομιστή (μέσω SSH) ή στον
   υπολογιστή του, και επικολλά αμέσως τις δύο γραμμές που τυπώνει στο wp-config.php. Προσθέτει και τη γραμμή
   `define('EVC_CLUB_PUSH_SUBJECT', 'mailto:…');` με το πραγματικό e-mail του καταστήματος. Τα κλειδιά **δεν στέλνονται
   ποτέ** με e-mail ή μήνυμα, σε κανέναν. Η βάση δοκιμών και η παραγωγή παίρνουν διαφορετικά κλειδιά.
3. **Αντίγραφο ασφαλείας** της βάσης του Club, μετά `define('EVC_CLUB_MIGRATIONS_TARGET', 5);` (με το
   `EVC_CLUB_MIGRATIONS_ENABLED` ενεργό για λίγο) και από Εργαλεία → «Evangelou Club — Βάση δεδομένων»: έλεγχοι πράσινοι,
   εφαρμογή (εκκρεμεί μόνο η 005). Μετά αφαιρείται το `EVC_CLUB_MIGRATIONS_ENABLED`.
4. **Δικαιώματα:** ο χρήστης της βάσης του Club χρειάζεται ανάγνωση, καταχώρηση και ενημέρωση (SELECT, INSERT, UPDATE)
   στον πίνακα `evc_push_subscriptions` (ποτέ διαγραφή).
5. `define('EVC_CLUB_PUSH_ENABLED', true);` στο wp-config.php.
6. **Ετοιμότητα:** «Evangelou Club → Ετοιμότητα»: «Ειδοποιήσεις μελών», «Κλειδιά ειδοποιήσεων» και «πίνακας συνδρομών
   (μετάβαση 005)» πρέπει να γράφουν «Εντάξει». Ανοίξτε και τις διευθύνσεις `/club-member/sw.js` και
   `/club-member/manifest.webmanifest` στον περιηγητή: πρέπει να φορτώνουν.
7. **Δοκιμή σε Android (Chrome):** ανοίξτε την κάρτα μέλους, πατήστε «Θέλω ειδοποιήσεις», επιτρέψτε. Από τον υπολογιστή:
   «Evangelou Club → Ειδοποιήσεις», τίτλος και κείμενο, «Όλα τα μέλη…», «Προεπισκόπηση», «Αποστολή». Η ειδοποίηση έρχεται
   στο κινητό· πατώντας την ανοίγει η κάρτα. Δοκιμάστε και «Διακοπή ειδοποιήσεων»: η επόμενη αποστολή δεν έρχεται.
8. **Δοκιμή σε iPhone (iOS 16.4 ή νεότερο):** στο Safari η κάρτα δείχνει μόνο οδηγία εγκατάστασης. Πατήστε «Κοινή χρήση» →
   «Προσθήκη στην οθόνη Αφετηρίας», ανοίξτε την κάρτα από το εικονίδιο (ίσως χρειαστεί ξανά σύνδεση), πατήστε «Θέλω
   ειδοποιήσεις», επιτρέψτε, και στείλτε μια δοκιμαστική όπως στο βήμα 7.
9. **Αυτόματες ειδοποιήσεις** (προαιρετικά, μετά τις δοκιμές): `EVC_CLUB_PUSH_AUTO_ACTIVATED` και `EVC_CLUB_PUSH_AUTO_EXPIRY`,
   η καθεμία με `true`.
10. **Διακοπή αποστολής:** αν πατήσετε «Διακοπή αποστολής» ενώ στέλνεται μια ομάδα μηνυμάτων, η σελίδα γράφει «Η διακοπή
    καταχωρήθηκε, η αποστολή σταματά σε λίγα δευτερόλεπτα». Μετά από λίγα δευτερόλεπτα γράφει «Η αποστολή διακόπηκε».
    Το πολύ ένα μήνυμα, αυτό που έφευγε εκείνη τη στιγμή, φτάνει μετά το πάτημα.
11. **Κλείδωμα της βάσης:** αν η σελίδα γράψει ότι η βάση δεδομένων δεν επέτρεψε το κλείδωμα, δεν στάλθηκε τίποτα.
    Ενημερώστε τον τεχνικό: ο διακομιστής της βάσης πρέπει να υποστηρίζει τα «επώνυμα κλειδώματα» της MySQL / MariaDB.
12. **Αποσύνδεση:** μόνο το κουμπί «Αποσύνδεση» της κάρτας μέλους σταματά τις ειδοποιήσεις στο συγκεκριμένο κινητό. Αν η
    σύνδεση λήξει μόνη της ή γίνει αποσύνδεση από άλλη σελίδα, το κινητό συνεχίζει να λαμβάνει τις ειδοποιήσεις του μέλους
    (μόνο τίτλο και κείμενο), μέχρι το μέλος να πατήσει «Διακοπή ειδοποιήσεων» ή να ενεργοποιήσει ειδοποιήσεις άλλος
    λογαριασμός στο ίδιο κινητό.
