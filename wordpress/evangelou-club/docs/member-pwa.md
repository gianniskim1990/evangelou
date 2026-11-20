# Member app on the home screen (STAGE-08 round 11, owner decision D26 part 1)

The member page (`/club-member/`) can be installed on a phone's home screen. **No push notifications yet**: push is
round 12 with migration 005. Nothing here is personal, nothing here needs a new constant, and nothing is stored on
the server.

## What is served, and where

| What | Pretty permalinks | Plain permalinks | Type |
|---|---|---|---|
| Member page | `/club-member/` | `/?evc_club_member=1` | `text/html` (unchanged; private, no-store) |
| Web app manifest | `/club-member/manifest.webmanifest` | `/?evc_club_member=manifest` | `application/manifest+json` |
| Service worker | `/club-member/sw.js` | **none (404)** | `text/javascript` |
| Icons | `<plugin>/customer-app/pwa/icon-192.png`, `icon-512.png`, `icon-maskable-512.png`, `apple-touch-icon.png` | same | `image/png` (static files) |

- All of it exists only while `EVC_CLUB_CUSTOMER_ENABLED` is on (404 otherwise). The manifest and the worker are
  public: a browser fetches the manifest without cookies, and neither carries a nonce, a name or an account.
- Manifest: name «Ευαγγέλου Club», short name «Club», `display: standalone`, portrait, theme and background colour
  `#1e1713` (`--roast`, the member card), `start_url` = the member page in the site's permalink form, `id` = the same.
  `scope` = the member page path: `/club-member/` (or `/<sub-folder>/club-member/`); with plain permalinks the member
  page's path IS the home path, so the scope can only be the home path there.
- The member page's `<head>` adds: the manifest link, `theme-color`, `apple-touch-icon`,
  `apple-mobile-web-app-capable`, `mobile-web-app-capable`, `apple-mobile-web-app-title` («Ευαγγέλου Club»),
  `apple-mobile-web-app-status-bar-style` (`default`). The staff app (`/club-admin/`) and the join and poster pages get
  none of these (tests + packager guard).
- Icons: the wing mark of the bundled logo (`app/src/preview/assets/logo-evaggelou-color.png`) in brass on the roast
  background, made by `app/scripts/generate-pwa-icons.mjs` (committed PNG files, RGB without transparency). The
  maskable icon keeps the mark inside the central 60 %.
- Same origin: the icons come from `plugins_url()`. If a CDN rewrites plugin URLs to another host, the icons follow it
  (the browser accepts cross-origin icons); the worker and the manifest are always served by WordPress itself.

## Why the worker exists only with pretty permalinks (the scope decision)

A service worker's scope is a path prefix; a query string cannot limit it. With pretty permalinks the script is
served at `/club-member/sw.js`, so its default maximum scope is `/club-member/` and it is registered with exactly that
scope: it controls the member page and nothing else of the site. **No `Service-Worker-Allowed` header is sent** (the
packager refuses one in any shipped file). The script is served only at that exact path: `/?evc_club_member=sw`
(which would allow a scope of the whole site) is a 404.

With plain permalinks the member page is `/?evc_club_member=1`, whose path is the home path. A worker there would
control the WHOLE site: the staff app (`/?evc_club_admin=1`), the shop, the checkout, wp-admin. So with plain
permalinks the page names no worker and the plugin serves none; the manifest and the iOS tags still work, so the app
can still be installed (current Chrome does not require a worker for that). Staging and production should use
«Post name» permalinks anyway (go-live T9).

## What the worker does (app/src/customer/pwa/swRules.ts, tested rule by rule)

Checked in this order; the first rule that applies decides:

1. **REST is network-only**: any URL with a `rest_route` parameter, or a path under a REST prefix (`/wp-json/` under
   the home path, and the site's own `rest_url()` path): the worker does not answer at all (no `respondWith`), so
   the browser fetches it normally and nothing is ever looked up or stored. This covers `/me`, `/me/qr`,
   `/me/coffees`, `/me/history` and every other route.
2. Not `GET`, another origin, or a URL carrying `_wpnonce`, `nonce`, `_wp_http_referer`: not answered.
3. A navigation to exactly the member page (the scope path, no query): straight from the network, **never stored**
   (its HTML carries the REST nonce and the logout link). Only when the network fails, the worker answers with an
   offline page it builds itself: the app's cached script and styles and a bootstrap that says only
   `{"mode":"offline"}`, with the member page's CSP. The app then shows «Δεν υπάρχει σύνδεση» and «Δοκιμάστε ξανά».
4. A `GET` of one of the app's hashed build files under `customer-app/assets/` (no query): from the cache, else from
   the network and then stored (only a complete same-origin 200 without `no-store` / `private`).
5. Everything else (other navigations, the manifest, the icons, the staff app's files): not answered.

The cache is `evc-club-member-<plugin version>-<hash of the build files and the worker>`: a new package means a new
cache, and on `activate` the worker deletes its own older caches (never another plugin's). The entry script and
styles are stored at `install`. The worker uses no other storage, no push, no sync, loads no other script (packager
guard on the built `customer-app/pwa/sw.js`).

**Offline, honestly:** the offline page knows nothing about the member, so it cannot show a card or a QR. The
«unchecked QR» state (round 7) needs `GET /me` to have answered (it shows only the signed-in account's kept code); a
cold start without any network shows the offline page. Showing the kept code without `/me` would be a new owner
decision (a shared phone could show the last member's code).

## «Εγκατάσταση στο κινητό» (app/src/customer/pwa/installHint.ts)

- Shown below the member area (never over the card or the QR), only on the member area itself (not on the sign-in,
  error or offline pages), never in standalone mode.
- Android / Chrome: the browser's `beforeinstallprompt` is kept (its mini bar is replaced by the hint) and shown on
  «Εγκατάσταση». Declined = never asked again on this device.
- iPhone / iPad Safari: «Στο Safari πατήστε «Κοινή χρήση» και μετά «Προσθήκη στην οθόνη Αφετηρίας».» (Apple's own Greek
  labels). Other iOS browsers (Chrome, Firefox, Edge…) get nothing: their menus differ.
- «Όχι τώρα» / «Εντάξει» closes it for good on this device: one fixed key `evc-club-install-hint:v1` = `"1"` in
  localStorage, through `qrStore.ts createDeviceFlag()` (still the only file of the customer app allowed to touch
  storage; the packager's localStorage count stays 1). If storage fails, it stays closed until the page is closed.

## Renewal and payment on the card (owner decision D27, same round)

- **Expired**: «Ανανέωση 20 €» on the dashboard and the card opens the join flow (`joinUrl`), the same payment as
  joining. The join flow already leads an expired customer through the terms to the checkout, and
  `blocks_new_order` refuses a second order while one is open. Without the join flow (`EVC_CLUB_ONBOARDING_ENABLED`
  off) there is no button, only «Για να ανανεώσετε τη συνδρομή σας, μιλήστε με το κατάστημα.»
- **Active**: no renewal anywhere (no early renewal from the card; the server's renewal / expiry rules are unchanged).
- **Pending** (bank transfer on hold): no button. Plain text: the payment waits for the bank transfer, the bank
  details are in the order confirmation e-mail, and the membership starts once the shop confirms the transfer. **No
  link to the order page:** WooCommerce 11.2.1 shows the bank details only on the «order received» page and in the
  e-mails (`WC_Gateway_BACS`: `woocommerce_thankyou_bacs`, `woocommerce_email_before_order_table`), not on
  My account → view order, so a link there would not help.
- **Cancelled**: no button (unchanged). The server would accept a new order (`blocks_new_order` allows a cancelled
  customer), but a cancellation is the shop's decision, so the card does not invite one: «μιλήστε με το κατάστημα».
- **Unverified**: no button (unchanged), consistent with `blocks_new_order`, which refuses a new order then.
- The preview tooltip «Μη διαθέσιμο στην προεπισκόπηση» exists only in the design preview; the live app has no
  disabled preview control (the «Επεξεργασία στοιχείων» sample button is not on the live app).

## Owner test steps on real phones (staging, «Post name» permalinks)

**Android, Chrome:**
1. Open `https://<staging>/club-member/`, sign in as a test member. Below the card: «Εγκατάσταση στο κινητό» with
   «Εγκατάσταση» and «Όχι τώρα». (If it does not appear at once, reload once: Chrome offers the install a moment
   after the first visit.)
2. Tap «Εγκατάσταση» → Chrome's own dialog → «Εγκατάσταση». The icon (golden wing on dark brown) is on the home screen.
3. Open the app from the icon: no address bar, the card with the QR; the hint is not shown.
4. Turn on airplane mode, close the app completely, open it again: «Δεν υπάρχει σύνδεση» with «Δοκιμάστε ξανά» (no
   card). Turn airplane mode off, tap «Δοκιμάστε ξανά»: the card again.
5. Chrome → ⋮ → Settings → Site settings → the site: uninstall later if needed. In a normal Chrome tab, «Όχι τώρα»
   must hide the hint for good on that phone (reload: still hidden).

**iPhone, Safari:**
1. Open `https://<staging>/club-member/` in Safari, sign in. Below the card: «Εγκατάσταση στο κινητό» with the
   «Κοινή χρήση» → «Προσθήκη στην οθόνη Αφετηρίας» steps and «Εντάξει».
2. Do exactly that: the share button, «Προσθήκη στην οθόνη Αφετηρίας», «Προσθήκη». The icon is on the home screen,
   named «Ευαγγέλου Club» (or «Club»).
3. Open it from the icon. **iOS keeps the installed app's sign-in apart from Safari: you may have to sign in again
   inside the app.** Check that the sign-in page opens and that after signing in you return to the card inside the
   app (not to a Safari page). If the sign-in does not stick (a loop back to the sign-in), report it: the fix is a
   wider manifest scope for navigation (not for the worker), which needs your decision.
4. Airplane mode, close the app from the app switcher, open it: «Δεν υπάρχει σύνδεση». Back online: the card.
5. In Safari (not the app), «Εντάξει» hides the steps for good on that iPhone.

**Both:** the till page (`/club-admin/`) on the tablet is unchanged (no install hint, no manifest); an expired test
member sees «Ανανέωση 20 €» that opens the join page; a member with a pending bank transfer sees the text about the
transfer and no button.

## Tests and guards

- PHP: `CustomerShellTest` (manifest content and type, head tags in both permalink forms, worker only at its pretty
  path, its configuration, no `Service-Worker-Allowed`, rewrite rules, a new build = a new version),
  `StaffShellTest` and `OnboardingTest` (no manifest / worker / iOS tags on the staff, join and poster pages),
  `CustomerFeatureOffTest` (404 with the flag off). WP mutants R11W1–R11W12.
- App: `tests/club/pwa.test.ts` (every worker rule, the worker in a fake worker scope: REST never answered, looked up
  or stored, the member page never stored, the offline page, cleanup; registration; the install hint; the icons),
  `customerLiveApp.test.ts` (the hint in the member area, the offline page, D27). Frontend mutants S8R11-W*, S8R11-S*,
  S8R11-I*, S8R11-R*, S8R11-N*, S8R11-C2.
- Packager (`app/scripts/php-guards.mjs`, self-tested on every package): `pwaProblemsFor` (only
  `EVC_Customer_Pwa` writes the manifest link, the iOS tags and the worker; only the member page calls it; no
  `Service-Worker-Allowed` anywhere); `workerProblems` on the built worker (no storage other than its cache, no
  `importScripts` / `eval` / push / sync / messaging, the REST rule present, exactly two cache writes and two
  answers); the staff bundle may not contain `serviceWorker`, `beforeinstallprompt`, `webmanifest` or the hint's
  title; the icons must be PNG files of 192 / 512 / 512 / 180 px.

## Not verified

Nothing was run on a real phone or on staging. Chrome's install prompt timing, iOS's separate sign-in for installed
web apps, and the offline start are described from the browsers' documented behaviour and tested only in jsdom /
Node with a fake worker scope.
