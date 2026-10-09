# Evangelou Club API — v1 contract

**Status:** pre-release design. No WordPress REST controller exists yet. The
PHP domain engine behind the redeem endpoint exists and is tested in
isolation (Task 1B, `wordpress/evangelou-club/`), but it is not wired to
WordPress. The React `/club` screens run on `mockClubService`; the REST
adapter (`src/club/restClubService.ts`) is implemented and tested against a
fake `fetch` only, and is **not enabled**.

There is no live v1 consumer, so pre-release changes are allowed — every
change is listed in §15 rather than made silently.

## Architecture and translation boundary

```text
React /club screens            camelCase domain types (src/club/types.ts)
      ▲
React DTO mapper               src/club/restClubService.ts — the ONLY code that
      ▲                        sees wire JSON; validates, then maps snake → camel
Public JSON (this document)    namespace evangelou-club/v1, snake_case
      ▲
WordPress REST controller      FUTURE: auth, nonce, rate limit, HTTP status,
      ▲                        UTC → ISO 8601, field renaming (§12)
PHP domain engine              EVC_Redemption_Service → EVC_Redemption_Result
      ▲                        (Task 1B, internal field names)
Separate Club database         ledger, idempotency, audit (Task 1B schema)
```

The PHP result fields are **internal**; they are not the public API. The
controller translates them (§12), and the React mapper translates the
public JSON into domain types. Neither side leaks its internal names.

The React app **only ever calls `evangelou-club/v1`**. It never calls
FluentCRM, Paid Memberships Pro or WooCommerce APIs and never holds
credentials for them.

---

## 1. Base URL & versioning

```text
https://<store-domain>/wp-json/evangelou-club/v1
```

A breaking change to a request/response shape, status code or error code
after first release ships as `evangelou-club/v2`. Additive changes (a new
optional field, a new error code) are fine within v1. Clients must ignore
unknown response fields.

## 2. Opaque identifiers

```json
{ "member_id": "mem_0123456789abcdef0123456789abcdef" }
```

`member_id` is the stable public id minted by the Club plugin
(`evc_members.member_public_id`: `mem_` + 32 lowercase hex). It never
changes for a member and never reveals a WordPress, FluentCRM, PMPro or
WooCommerce id. Clients treat it as an opaque string (URL-encoded in paths).
QR tokens (§11) are likewise opaque strings passed back verbatim.

## 3. Authentication (server side implemented in Task 1C-C, disabled by default)

**Owner decisions:** D1 = Option A (staff app served by the plugin on the
WordPress origin at `/club-admin/`); D2 = ONE shared, restricted Club staff
WordPress account for all employees/tablets. Audit identifies the shared
account and a session reference, **never a human employee**.

**No endpoint is public.** The routes exist only when wp-config.php defines
`EVC_CLUB_STAFF_ENABLED` as boolean `true`; every request must then pass:

1. WordPress cookie session (core `wp-login.php`, auth cookie, session token);
2. `X-WP-Nonce` header with a valid `wp_rest` nonce (core drops the cookie
   user without it; the plugin verifies it again);
3. role `evc_club_staff` + the endpoint's capability (`evc_redeem_benefit`, …);
4. shared account not disabled;
5. session policy: 12 h absolute lifetime, 30 min server inactivity (per
   session; tablets never extend each other).

Failures: `401 unauthorized` (no/expired/idle session, missing or invalid
nonce — core's `rest_cookie_invalid_nonce` is mapped to this envelope) and
`403 forbidden` (wrong role/capability, disabled account). The staff id is
taken from the session, never from the body; path ids only from the URL.

- **No static Application Password, API key or other secret may ever ship
  in the browser bundle.** Application Passwords are refused by the Club
  routes. The adapter sends no `Authorization` header.
- Club responses carry `Cache-Control: no-store, private`, `nosniff`,
  `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY` and a JSON CSP; the
  CORS headers core reflects are removed for this namespace (same origin only).
- **Still open (client side, later task):** the plugin-served staff bundle
  with an absolute same-origin base URL, nonce bootstrap from the protected
  PHP shell, refreshed nonce from the `X-WP-Nonce` response header, and the
  5-minute tablet screen lock. The public Vercel deployment still rewrites
  `/wp-json/*` to `index.html`; the adapter treats that HTML as
  `invalid_response`.

## 4. Endpoints

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/members/lookup` | Resolve a member by phone or QR token |
| `POST` | `/members/{member_id}/benefits/{benefit_type}/redeem` | Redeem today's benefit with a selected coffee |
| `GET`  | `/coffee-options` | Coffees staff may record (proposed, §7.1) |

### 4.1 `POST /members/lookup`

Phone numbers are **never** put in a URL. The body carries exactly one
method; both or neither is `400 invalid_request`.

```json
{ "method": "phone", "phone": "+30 690 000 0001" }
```

```json
{ "method": "qr", "qr_token": "evc_<48 lowercase hex>" }
```

The server normalises Greek mobiles; the frontend's `normalizeGreekPhone()`
is UX only. Malformed phone → `400 invalid_phone`; malformed QR →
`400 invalid_qr`.

**`200 OK`:**

```json
{
  "member": {
    "member_id": "mem_0123456789abcdef0123456789abcdef",
    "display_name": "Μαρία Παπαδοπούλου",
    "phone_masked": "69••••••01"
  },
  "membership": {
    "status": "active",
    "started_at": "2026-09-15T00:00:00+03:00",
    "valid_until": "2026-10-15T23:59:59+03:00"
  },
  "benefits": {
    "free_coffee": {
      "state": "used",
      "business_date": "2026-10-09",
      "redeemed_at": "2026-10-09T10:42:16+03:00",
      "coffee_code": "freddo_espresso"
    }
  }
}
```

- `business_date`: `YYYY-MM-DD`, Europe/Athens calendar day.
- Every instant carries an explicit offset (§12). The React mapper
  **rejects** offset-less timestamps as `invalid_response`.
- `coffee_code` is present when `state` is `used` (may be `null` for
  legacy records); omitted or `null` otherwise.
- No email, full phone, internal ids or raw PMPro objects (§13).

**Member not found — `404`** with `error.code: "member_not_found"`. The
React adapter turns exactly this (envelope + 404) into `{ member: null }`;
a bare 404 without the envelope (missing route, proxy page) is
`invalid_response`, never "not found".

### 4.2 `POST /members/{member_id}/benefits/{benefit_type}/redeem`

```text
POST /wp-json/evangelou-club/v1/members/mem_0123…cdef/benefits/free_coffee/redeem
```

**Request:**

```json
{
  "request_id": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
  "coffee_code": "freddo_espresso"
}
```

| Field | Rule |
|---|---|
| `request_id` | Required. Lowercase RFC 4122 **v4** UUID. One per logical redemption intent; reused unchanged on every retry of that intent (§5). |
| `coffee_code` | Required. Must be in the server's allowlist (§7.1), else `400 invalid_coffee`. |

The staff actor is taken from the authenticated session server-side —
**never** from the request body.

**Success — `200 OK`** (also for a replay of an already-committed request):

```json
{
  "member_id": "mem_0123456789abcdef0123456789abcdef",
  "benefit_type": "free_coffee",
  "state": "used",
  "business_date": "2026-10-09",
  "redeemed_at": "2026-10-09T13:00:00+03:00",
  "coffee_code": "freddo_espresso",
  "request_id": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
  "replayed": false
}
```

- `replayed: true` means the server recognised a retry of a committed
  request and returned the **original** result (same `business_date`,
  `redeemed_at`, `coffee_code`), even if the retry arrives after Athens
  midnight or after the membership lapsed.
- The React mapper checks that `member_id`, `benefit_type`, `request_id`
  and `coffee_code` echo the request; any mismatch is `invalid_response`.

**Failures** (envelope §8):

| HTTP | `code` | Meaning for this request | `details` |
|---|---|---|---|
| 400 | `invalid_request` | Malformed body / ids | — |
| 400 | `invalid_coffee` | Coffee not in allowlist | — |
| 404 | `member_not_found` | Unknown or disabled member | — |
| 409 | `membership_inactive` | Not an active, verified-paid, unexpired membership at server time | `status` |
| 409 | `benefit_already_redeemed` | Today's benefit already used by **another** request | `business_date`, `redeemed_at`, optional `coffee_code` |
| 409 | `idempotency_key_reused` | `request_id` was already used with a **different** payload (member, coffee or staff actor) | none — never another request's data |
| 401 / 403 | `unauthorized` / `forbidden` | §3 | — |
| 429 | `rate_limited` | Not processed; retry later | optional `retry_after_seconds` (+ `Retry-After` header) |
| 500 | `server_error` | Includes an **unknown COMMIT outcome**: retry with the same `request_id` | — |
| 503 | `server_error` | Redemption backend unavailable (no production membership adapter yet): fails closed, grants nothing | — |

`benefit_already_redeemed` example:

```json
{
  "error": {
    "code": "benefit_already_redeemed",
    "message": "Η σημερινή παροχή έχει ήδη χρησιμοποιηθεί.",
    "details": {
      "business_date": "2026-10-09",
      "redeemed_at": "2026-10-09T10:42:16+03:00",
      "coffee_code": "cappuccino"
    }
  }
}
```

The UI syncs to the "used" state using these details. If `redeemed_at` is
absent or malformed, the UI shows "used" **without** a time — it never
invents one. (The Task 1B engine does not currently return the winner's
coffee in its result details, so `coffee_code` is optional.)

**The backend re-checks eligibility at redemption time**, regardless of
what lookup said earlier.

## 5. Idempotency, retries and concurrency

Invariant (database-enforced in Task 1B by
`UNIQUE (member_id, benefit_type, business_date)`):

```text
one member + one benefit type + one Europe/Athens business date
  = at most one successful redemption
```

`request_id` (UNIQUE in the ledger, bound to a SHA-256 fingerprint of
member + benefit + coffee + staff actor; **no date**) makes retries safe.

**Client rules** (`src/club/redemptionIntent.ts`):

- Exactly one UUID per logical intent (member + benefit + coffee). The
  caller owns it; `redeemBenefit` never generates one.
- Re-renders never regenerate it; it is independent of the clock, so a
  retry after Athens midnight reuses it.
- Outcome unknown → keep the intent open and retry with the **same** id:
  `network_error`, `invalid_response`, `server_error`, `rate_limited`,
  `unauthorized`.
- Definitive answer → close the intent: success, `benefit_already_redeemed`,
  `membership_inactive`, `invalid_coffee`, `idempotency_key_reused`,
  `invalid_request`, `member_not_found`, `forbidden`.
- Changing the coffee or the member opens a **new** intent with a new id.
  If the abandoned intent had committed, the server answers
  `benefit_already_redeemed` — no double coffee.
- No automatic retries. Retry is an explicit staff action ("Επανάληψη").
- One attempt in flight per screen; double taps are ignored.

## 6. Membership status contract

```ts
type MembershipStatus = "active" | "expired" | "cancelled" | "inactive";
```

Mapped by the controller from the engine's `EVC_Entitlement`
(`public_status()`):

| Engine state | Public `status` |
|---|---|
| active + verified payment + known expiry + now < expiry | `active` |
| expired, or active past its end date | `expired` |
| cancelled | `cancelled` |
| pending payment, payment failed, refunded, unverified payment, unknown expiry, no membership | `inactive` |

Only `active` may redeem. The engine's finer `reason`
(`pending_payment`, `payment_unverified`, …) is **not** exposed publicly.
`valid_until` is nullable in the schema, but Phase 1 manual one-month
memberships always have one; a missing expiry is never eligible.

## 7. Benefit contract

```ts
type BenefitType = "free_coffee";
type BenefitState = "available" | "used" | "unavailable";
type BenefitUnavailableReason = "membership_inactive";
```

`business_date` and `redeemed_at` are always present (`redeemed_at: null`
until used). `coffee_code` accompanies `used`.

### 7.1 Coffee options (proposed)

```text
GET /coffee-options  →  200 { "coffee_options": [ { "code": "espresso", "label": "Espresso" } ] }
```

The server's allowlist is authoritative; the client never validates codes
beyond "came from this list". **The production list is not decided (D3).**
The demo uses `src/club/mockCoffeeCatalog.ts`, clearly marked as demo data,
with the same codes as the Task 1B PHP test fixture.

## 8. Standard error envelope

```json
{ "error": { "code": "machine_readable_code", "message": "Greek text", "details": {} } }
```

- `details` keys are **snake_case** on the wire, always. Known keys:
  `business_date`, `redeemed_at`, `coffee_code`, `status`,
  `retry_after_seconds`.
- The React adapter maps them to camelCase (`businessDate`, `redeemedAt`,
  `coffeeCode`, `status`, `retryAfterSeconds`), validates each, and drops
  unknown or malformed ones.
- The adapter shows its **own** Greek message per code. Server `message`
  text is never displayed, so PHP/SQL/proxy text cannot reach staff.
- An envelope code is trusted only with its expected HTTP status (table in
  §4.2); otherwise the status alone decides (`401`→`unauthorized`,
  `403`→`forbidden`, `429`→`rate_limited`, `5xx`→`server_error`, anything
  else → `invalid_response`).

| HTTP | `code` |
|---|---|
| 400 | `invalid_request`, `invalid_phone`, `invalid_qr`, `invalid_coffee` |
| 401 | `unauthorized` |
| 403 | `forbidden` |
| 404 | `member_not_found` |
| 409 | `membership_inactive`, `benefit_already_redeemed`, `idempotency_key_reused`, `benefit_not_available` (reserved, unused) |
| 429 | `rate_limited` |
| 5xx | `server_error` |

**Client-only codes** (never sent by the server): `network_error` (no
response — outcome unknown) and `invalid_response` (non-JSON, wrong shape,
echo mismatch, offset-less timestamp — outcome unknown).

## 9. Source-of-truth mapping

- **Identity** — WordPress user, linked to FluentCRM contact. The lookup
  strategy is the plugin's internal detail.
- **Eligibility** — Paid Memberships Pro + WooCommerce (verified payment),
  through the membership adapter. Never FluentCRM tags.
- **Redemptions, coffee, idempotency, audit** — the separate Club database
  (Task 1B schema). Not stored in CRM or PMPro meta.

## 10. Data model

Implemented as migration `001_initial.sql` (Task 1B, CI-only so far):
`evc_members`, `evc_qr_tokens`, `evc_redemptions` (ledger row includes
`coffee_code`, `request_id`, `request_fingerprint`, staff id, membership
snapshot, `redeemed_at_utc`), `evc_audit_events`, `evc_schema_migrations`.
See `wordpress/evangelou-club/README.md`.

## 11. QR architecture (design only)

A QR encodes only an opaque token `evc_` + 48 lowercase hex (192-bit
random). Only its SHA-256 is stored; at most one active token per member;
rotation/revocation is a later task.

## 12. Controller translation rules (future WordPress REST controller)

| Engine (`EVC_Redemption_Result`) | HTTP | Public JSON |
|---|---|---|
| `redeemed` | 200 | success body, `replayed: false` |
| `replayed` | 200 | success body, `replayed: true` |
| `invalid_request` | 400 | `invalid_request` |
| `invalid_coffee` | 400 | `invalid_coffee` |
| `member_not_found` | 404 | `member_not_found` |
| `membership_inactive` | 409 | `membership_inactive`, `details.status` (drop engine `reason`) |
| `benefit_already_redeemed` | 409 | `details.business_date`, `details.redeemed_at` |
| `idempotency_key_reused` | 409 | no details |
| `server_error` | 500 | no details, generic message |

Field renames: `member_public_id` → `member_id`; `redeemed_at_utc` →
`redeemed_at`.

**Timestamps:** the engine stores and returns `redeemed_at_utc` as
`Y-m-d H:i:s.u` **in UTC without a zone designator**. The controller must
parse it explicitly as UTC and emit RFC 3339 with an explicit offset —
Europe/Athens local time with its offset (e.g. `2026-10-09T13:00:00+03:00`),
or UTC with `Z`. It must never emit the raw database string. Formatting must
be deterministic so a replay returns byte-identical values.

The controller also: takes the staff id from the session; validates the
nonce; rate-limits; maps any unexpected exception to `500 server_error`.

## 13. Privacy and logging

- No full phone numbers, emails or internal ids in responses or logs.
- Phone numbers and QR tokens never in URLs, analytics or error trackers.
- No full request/response payload logging.

## 14. TypeScript domain types

`src/club/types.ts` is the camelCase mirror: `ClubMember`,
`MemberBenefitStatus` (with optional `coffeeCode`), `CoffeeOption`,
`RedeemBenefitRequest` (`memberId`, `benefitType`, `coffeeCode`,
caller-owned `requestId`), `RedemptionOutcome` (`benefit`, `requestId`,
`replayed`), `ClubErrorCode` (API codes + client codes),
`ClubErrorDetails` (camelCase, validated), and `ClubApiError`.

## 15. Pre-release changelog

**Task 1C-A (2026-10-09):**

- Redeem request now requires `coffee_code` (was only `request_id`).
- Redeem success adds `coffee_code`, `request_id` and `replayed`.
- Lookup benefit adds `coffee_code` for used benefits.
- New error codes: `invalid_coffee` (400), `idempotency_key_reused` (409).
- `details` defined as snake_case on the wire, camelCase in the client
  (fixes the earlier mismatch where the UI read camelCase keys that the
  wire never sent).
- `request_id` ownership moved to the caller; the adapter no longer creates
  a new UUID per call (fixes retries defeating idempotency).
- All instants must carry an explicit offset; the client rejects others.
- Proposed `GET /coffee-options`.
- Client-only codes `network_error`, `invalid_response` documented.
- Broken internal section references fixed.

**Task 1C-C (2026-10-09):**

- §3 rewritten for the approved D1/D2 design and the implemented (flag-off)
  server authorization; no wire-shape changes for existing endpoints.
- Redeem may answer `503 server_error` while no production membership
  adapter exists (fail closed). Clients already map any 5xx to
  `server_error`.
- WordPress-native auth/param errors inside the namespace are returned in
  the v1 envelope (`unauthorized`, `forbidden`, `invalid_request`).

## 16. Demo-only shortcuts (deliberate)

- Mock "member not found" returns `{ member: null }` (production: 404).
- Mock redemptions live in `localStorage` (`mockRedemptionStore.ts`):
  per-browser, **not concurrency-safe**, not shared between devices.
  Production enforcement is the database (§5).
- No authentication in the demo; the `/club` entry gate is cosmetic.
- No network calls in the demo.
