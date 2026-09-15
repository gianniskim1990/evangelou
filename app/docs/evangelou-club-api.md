# Evangelou Club API — v1 contract

**Status:** design only. No backend exists yet. This document specifies the
REST API a future custom WordPress plugin must implement so the existing
`/club` React interface (currently backed by `mockClubService`, see
`src/club/clubService.ts`) can be pointed at it later by swapping one
export — no screen should need to change.

## Architecture

```text
Evangelou React App  (/club, Vercel)
        │  authenticated staff session (see §7 Authentication)
        ▼
Custom Evangelou Club REST API   ── OUR contract, this document ──
  namespace: evangelou-club/v1
        │  in-process PHP calls (same WordPress install — no HTTP
        │  round-trip back to itself)
        ▼
Custom WordPress plugin (integration layer)
        │
        ├── Paid Memberships Pro   → membership status, dates
        ├── FluentCRM              → contact identity (name/phone/email)
        ├── WooCommerce            → customer/order context where relevant
        └── Club redemptions table → OUR data, owned by the plugin (§10)
```

The React app **only ever calls `evangelou-club/v1`**. It never calls
FluentCRM's, Paid Memberships Pro's, or WooCommerce's REST APIs directly,
and never holds credentials for any of them. Because the plugin runs
inside the same WordPress process as those systems, it should prefer their
internal PHP APIs/functions/models over making WordPress issue HTTP
requests back to itself.

---

## 1. Base URL & versioning

```text
https://<store-domain>/wp-json/evangelou-club/v1
```

All endpoints below are relative to this base. The namespace is versioned
(`v1`) precisely so it doesn't have to stay this way forever: **a breaking
change to any request/response shape, status code, or error code ships as
`evangelou-club/v2`, not as a silent change to `v1`.** Additive,
backward-compatible changes (a new optional field, a new error `code` the
client doesn't recognize yet) are fine within `v1`.

## 2. Opaque identifiers

The frontend never needs to know a WordPress user ID, FluentCRM subscriber
ID, PMPro membership ID, or WooCommerce customer ID. The plugin mints and
owns one opaque, application-level identifier per member:

```json
{ "member_id": "mem_7gH29xQ4kR" }
```

`member_id` may internally map to a WordPress user (and probably will),
but the client must treat it purely as an opaque string — never parse it,
never assume a format beyond "string." The same applies to QR identity
(§14): a scanned token is just another opaque string the client passes
back verbatim.

## 3. Authentication

**In production, no endpoint below is public.** Both `/members/lookup` and
the redeem endpoint expose membership information and can create a
real-world side effect (giving away a free coffee), so both require an
authenticated staff session:

```text
React Club Staff App
        │  authenticated staff session
        ▼
Evangelou Club API
```

`Authentication required in production.` applies to every endpoint in
this document. The exact mechanism (WordPress cookie session via a staff
login screen, a short-lived token issued after login, etc.) is an
implementation decision left for the phase that builds real staff auth —
**out of scope here.** What's already decided, and non-negotiable:

- **No static WordPress Application Password, API key, or any other
  long-lived secret may ever ship inside the Vercel/browser bundle.**
  Anything in frontend JavaScript is public by definition.
- The skeleton in `src/club/restClubService.ts` calls `fetch(..., {
  credentials: "include" })`, i.e. it assumes a same-site session cookie
  set by a real login flow — not an `Authorization` header holding a
  fixed value.
- Unauthenticated or expired-session requests return `401` with
  `error.code: "unauthorized"`; authenticated-but-not-permitted requests
  (wrong role) return `403` with `"forbidden"`.

The demo (`mockClubService`) has no auth at all — that's expected and
fine for a sales demo; it stops being fine the moment this API is real.

## 4. Endpoints

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/members/lookup` | Resolve a member by phone or QR token |
| `POST` | `/members/{member_id}/benefits/{benefit_type}/redeem` | Redeem today's benefit |

### 4.1 `POST /members/lookup`

Phone numbers are **never** put in a URL query string — they'd end up in
server access logs and browser history. Both lookup methods are `POST`
with a JSON body, and the body supports **exactly one** method at a time;
sending both `phone` and `qr_token` (or neither) is a `400
invalid_request`.

**By phone:**

```json
{
  "method": "phone",
  "phone": "+30 690 000 0001"
}
```

**By QR:**

```json
{
  "method": "qr",
  "qr_token": "evc_opaque_token_here"
}
```

The plugin normalizes Greek mobile numbers server-side regardless of what
the client sent (see §13). The frontend's own `normalizeGreekPhone()`
(`src/club/format.ts`) exists purely so the search button/hint text feels
responsive — **it is UX only and must never be trusted as the
authoritative validation layer.** A malformed phone after server-side
normalization is a `400` with `error.code: "invalid_phone"`; an
unrecognized/malformed QR token is `400 invalid_qr`.

**Success — `200 OK`:**

```json
{
  "member": {
    "member_id": "mem_abc123",
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
      "state": "available",
      "business_date": "2026-09-15",
      "redeemed_at": null
    }
  }
}
```

- Timestamps are ISO 8601 with an explicit offset (`+03:00` for
  Europe/Athens; `+02:00` in winter — always compute and send the offset,
  never assume the client will).
- `business_date` fields are plain `YYYY-MM-DD`, computed in the
  **Europe/Athens** business timezone, not UTC and not the client's local
  date. This matters for "today's benefit" logic close to midnight.
- The response returns **only** what the Club UI needs. No email, no full
  phone, no WordPress/PMPro/FluentCRM/WooCommerce internal IDs, no raw
  PMPro objects — see §13.

**Member not found — `404 Not Found`:**

```json
{
  "error": {
    "code": "member_not_found",
    "message": "Δεν βρέθηκε μέλος."
  }
}
```

Production returns a real `404` here, **not** `200` with `member: null`.
(`mockClubService` currently resolves to `{ member: null, benefit: null }`
instead of throwing — that's an acceptable mock-only shortcut documented
in §11, not something to replicate in the real API. The
`restClubService.ts` skeleton shows the adapter that absorbs this
difference so every screen's existing `if (!member)` check keeps working
unchanged either way.)

### 4.2 `POST /members/{member_id}/benefits/{benefit_type}/redeem`

```text
POST /wp-json/evangelou-club/v1/members/mem_abc123/benefits/free_coffee/redeem
```

`benefit_type` is `free_coffee` today; the path segment exists so adding a
second benefit later doesn't change the URL shape.

**Request:**

```json
{ "request_id": "3fa85f64-5717-4562-b3fc-2c963f66afa6" }
```

`request_id` is a client-generated UUID (`crypto.randomUUID()`) and is
**required** — it makes the operation idempotent. If a till's network
times out after the redemption actually succeeded server-side and it
retries the exact same request, the server must recognize the repeated
`request_id` and return the **original** result rather than creating a
second redemption. See §5 for how this is enforced at the data level.

**Success:** choose one HTTP convention and hold to it across the whole
API — this contract uses **`200 OK`** for the redeem endpoint (it's
updating/reading the state of an existing benefit resource, not creating
a new "thing" from the caller's perspective; `201 Created` is reserved for
endpoints that mint a brand-new resource, which this one conceptually
doesn't — a redemption row is an implementation detail, not something the
client addresses afterward).

```json
{
  "member_id": "mem_abc123",
  "benefit_type": "free_coffee",
  "state": "used",
  "business_date": "2026-09-15",
  "redeemed_at": "2026-09-15T12:08:22+03:00"
}
```

**Already redeemed today — `409 Conflict`:**

If a different till (or a retried request with a *different*
`request_id` — a genuinely separate attempt, not a retry) already redeemed
this member's coffee today:

```json
{
  "error": {
    "code": "benefit_already_redeemed",
    "message": "Η σημερινή παροχή έχει ήδη χρησιμοποιηθεί.",
    "details": {
      "business_date": "2026-09-15",
      "redeemed_at": "2026-09-15T10:42:16+03:00"
    }
  }
}
```

**How the frontend must interpret this:** this is not an unknown failure.
`ClubMemberResult.tsx`'s `confirmRedeem` already demonstrates the correct
handling — on `benefit_already_redeemed`, read `error.details` and call
`onRedeemed({ state: "used", ... })` to sync the UI to the real state
(closing the confirm dialog gracefully) instead of showing a scary error.
The mock reproduces this exact code path today (see `clubService.ts`'s
`redeemBenefit`, which throws `ClubApiError("benefit_already_redeemed",
...)` when a second redemption attempt hits an existing same-day record).

**Inactive membership — `409 Conflict`:**

```json
{
  "error": {
    "code": "membership_inactive",
    "message": "Η συνδρομή δεν είναι ενεργή.",
    "details": { "status": "expired" }
  }
}
```

`409` (not `403`) because the *request itself* is well-formed and the
caller is authorized to attempt it — the conflict is with the current
state of the membership resource, same category as
`benefit_already_redeemed`. **The backend always re-checks eligibility at
redemption time**, regardless of what the lookup response said a moment
earlier or what the UI button's disabled state implies. This is what
protects against:

- a stale UI (member's tab open for minutes before pressing redeem),
- multiple devices/tills racing each other,
- a membership expiring in the gap between lookup and redemption.

The frontend must never treat "the button was rendered enabled" as proof
the redemption will succeed.

## 5. Concurrency: one-per-day enforcement is server-authoritative

Production correctness must **not** rely on frontend state — `localStorage`
is a demo-only stand-in (`src/club/clubService.ts`) and disappears
entirely once this API is real. The invariant the plugin must enforce:

```text
one member + one benefit type + one business date
  = maximum one successful redemption
```

This must be **concurrency-safe**: two tills pressing "Καταχώρηση" within
the same second must not both succeed. The naive approach —

```php
if (!$already_used) {
    insert_redemption();
}
```

— has a race condition (both requests can read `$already_used = false`
before either writes). **Recommended fix: a database-level unique
constraint**, not an application-level check-then-insert:

```text
UNIQUE (member_ref, benefit_type, business_date)
```

Let the second concurrent `INSERT` fail on the constraint, catch that
specific failure, and translate it into the `409 benefit_already_redeemed`
response above (looking up the winning row's `redeemed_at` for the
`details`). This is the only fully race-free approach; anything based on a
prior `SELECT` is not.

`request_id` gets its own uniqueness handling too (§7/§20) — it protects
against the *same* logical attempt being retried by a flaky network,
which is a different failure mode than two *different* tills racing.

## 6. Membership status contract

```ts
type MembershipStatus = "active" | "expired" | "cancelled" | "inactive";
```

This is the **backend-authoritative** status — Paid Memberships Pro is the
source of truth for it, and the plugin should pass through whatever PMPro
state machine produces (mapped into these four buckets; PMPro's own
statuses may be more granular internally, e.g. distinguishing an
admin-cancelled membership from a payment-failed one — both land on
`"cancelled"` or `"inactive"` here unless a real product reason emerges
to split them further).

This is deliberately richer than what a cashier needs to *see*. The
**cashier UI state** is a presentation-level simplification of this value,
not a separate wire type:

| Backend `status` | Cashier sees |
|---|---|
| `active` | "Ενεργό μέλος" badge; benefit section is live |
| `expired` | "Η συνδρομή έχει λήξει" badge + expiry date |
| `cancelled` | falls back to a generic "Μη ενεργό μέλος" treatment (same as `inactive`) |
| `inactive` | same generic "Μη ενεργό μέλος" treatment |

(`src/club/format.ts`'s `membershipBadgeLabel()` implements exactly this
table.) **Only `"active"` may redeem a benefit** — everything else is a
`membership_inactive` redemption failure regardless of which of the three
non-active values it is.

**`valid_until` is nullable — this is load-bearing, not an edge case.**
Paid Memberships Pro supports an active recurring membership with no fixed
end date at all. The API must be able to return:

```json
{ "status": "active", "valid_until": null }
```

and the UI renders this as **"Ενεργή συνδρομή"** rather than assuming
every active member has an expiration date to show. The three presentation
cases the UI actually implements (`ClubMemberResult.tsx`):

| `status` | `valid_until` | Cashier sees |
|---|---|---|
| `active` | a date | "Ενεργό έως 15 Οκτωβρίου 2026" |
| `active` | `null` | "Ενεργή συνδρομή" |
| `expired` | a date | "Η συνδρομή έληξε στις 31 Αυγούστου 2026" |

(A non-`active`, non-`expired` status with a `null` `valid_until` falls
back to a generic "Η συνδρομή δεν είναι ενεργή" — not a scenario any demo
member currently exercises, but the component handles it.)

## 7. Benefit contract

```ts
type BenefitType = "free_coffee";
```

One value today, but every endpoint above is already shaped around
`benefit_type` as a path/response field specifically so a second benefit
doesn't change the contract's *shape* later — only its data.

```ts
type BenefitState = "available" | "used" | "unavailable";
```

```ts
// The UI must never invent a reason string — this is the closed set.
type BenefitUnavailableReason = "membership_inactive";
```

**Available:**

```json
{ "state": "available", "business_date": "2026-09-15", "redeemed_at": null }
```

**Used:**

```json
{
  "state": "used",
  "business_date": "2026-09-15",
  "redeemed_at": "2026-09-15T10:42:16+03:00"
}
```

**Unavailable** (membership isn't active — `state` and `reason` are
independent fields precisely so a future second reason doesn't require a
new `state` value):

```json
{
  "state": "unavailable",
  "business_date": "2026-09-15",
  "redeemed_at": null,
  "reason": "membership_inactive"
}
```

Note `business_date` and `redeemed_at` are **always present** in every
shape above (never an omitted key) — `redeemed_at` is `null` until the
benefit is actually used. This is also how the TypeScript domain type
models it (`MemberBenefitStatus.redeemedAt: string | null`, not optional).

## 8. Standard error envelope

Every error response across the whole API, regardless of endpoint or
status code, uses exactly one shape:

```json
{
  "error": {
    "code": "machine_readable_code",
    "message": "Safe Greek cashier-facing message",
    "details": {}
  }
}
```

`code` is what the frontend branches on (`ClubApiError.code` in
`src/club/types.ts`); `message` is copy-ready Greek text the UI can
display as-is; `details` is optional, structured, and code-specific (e.g.
`benefit_already_redeemed`'s `redeemed_at`).

| HTTP | `code` | When |
|---|---|---|
| 400 | `invalid_request` | Malformed body — both/neither lookup method, missing `request_id`, etc. |
| 400 | `invalid_phone` | Phone doesn't normalize to a plausible Greek mobile number |
| 400 | `invalid_qr` | QR token isn't a recognizable token shape |
| 404 | `member_not_found` | No member matches the phone/QR |
| 409 | `membership_inactive` | Redemption attempted on a non-active membership |
| 409 | `benefit_already_redeemed` | Redemption attempted on an already-used-today benefit |
| — | `benefit_not_available` | Reserved for a future benefit-level gate beyond membership status (unused by `free_coffee` today — nothing currently returns it) |
| 401 | `unauthorized` | No/expired staff session |
| 403 | `forbidden` | Authenticated but not permitted |
| 429 | `rate_limited` | Too many requests (brute-force phone/QR guessing protection) |
| 500 | `server_error` | Anything unexpected |

**The React UI must never display a raw PHP error, stack trace, SQL error,
PMPro error, or FluentCRM error.** Every failure path funnels through this
envelope; anything the plugin can't cleanly map becomes `500
server_error` with a generic Greek message, never the underlying
exception text.

## 9. Source-of-truth mapping

**Customer/contact identity** — FluentCRM (contact record: name, email,
phone, linked WordPress `user_id`) is primary, with WordPress user data
and WooCommerce billing data as secondary/fallback sources depending on
how a given member originally became known to the store. **The React app
must never know which of these the phone was actually found in** — the
plugin's lookup strategy is entirely its own implementation detail. It
returns one normalized `member` object; that's the whole contract.

**Membership eligibility** — Paid Memberships Pro is authoritative for
active/inactive, status, start date, and end date (when one exists). The
plugin translates PMPro's structures into the stable `membership` schema
in §6. **Raw PMPro objects are never returned to React.**

**Club benefit redemption** — this is Evangelou Club's own business logic,
not FluentCRM's or PMPro's. Daily coffee usage is **not** stored as a
FluentCRM custom field or a PMPro meta value merely because those plugins
happen to be present — the plugin owns a dedicated redemptions table for
it (§10). Mixing this into CRM/membership plugin data would make it
fragile against those plugins' own updates and unrelated to what they're
actually for.

## 10. Recommended data model (design only — no migration yet)

A dedicated table the plugin owns, conceptually:

```text
evc_redemptions
  id              bigint, PK
  member_ref      varchar   -- however the plugin internally identifies a
                             -- member (e.g. wp_users.ID) — never the
                             -- public member_id directly if that's
                             -- deliberately a separate opaque value
  benefit_type    varchar   -- "free_coffee", ...
  business_date   date      -- Europe/Athens business date, not UTC
  redeemed_at     datetime  -- exact timestamp, with timezone handling
  request_id      varchar   -- the idempotency key from §7
  staff_user_id   bigint    -- which till/staff session redeemed it
  created_at      datetime

  UNIQUE (member_ref, benefit_type, business_date)   -- §5's race-free guarantee
  UNIQUE (request_id)                                -- idempotent retries
```

The two unique constraints solve two different problems: the first
enforces "max one redemption per member per benefit per day" even under
concurrent requests; the second makes a retried request with the same
`request_id` a safe no-op (catch the duplicate-key error, return the
original row's result) rather than a second attempt that then correctly
fails on the first constraint anyway — belt and suspenders, and the
`request_id` one is what makes a *client-side* timeout-and-retry safe
specifically.

## 11. QR architecture (design only — no generation implemented yet)

A member's QR encodes **only** an opaque, high-entropy token:

```text
evc_qr_9fJ2kX7mQpL4...
```

It must **not** encode name, email, phone, WordPress user ID, membership
expiry, or FluentCRM ID — anyone who photographs or screenshots the QR
gets nothing but an opaque string. The backend resolves that token to a
member via `/members/lookup` (`method: "qr"`, §4.1). Tokens should be:

- **difficult to guess** — long, random, not derived from the member ID
  or anything else guessable,
- **revocable** — issuing a new token for a member must invalidate the
  old one (lost card, compromised token),
- **replaceable** — a member should be able to get a fresh QR without
  losing their membership history.

Nothing about token generation, storage, or the physical/digital card
itself is implemented in this phase — this section specifies the contract
the eventual generator must satisfy, not the generator.

## 12. Privacy and logging

- Never log full phone numbers unless there's a specific operational need
  (and then, redact all but the digits already visible to the cashier —
  i.e. log at most what `phone_masked` shows).
- Phone numbers never appear in a URL (§4.1) — they can't end up in access
  logs or browser history if they're never in a URL to begin with.
- Email addresses are never sent to the Club UI at all — the interface
  has no use for them, so the API simply never includes an `email` field
  anywhere in this contract.
- QR tokens are identifiers/secrets, not analytics data — don't write them
  to analytics events, error trackers, or logs casually.
- Every response returns only the fields the Club interface actually
  needs (§2, §4.1) — resist the temptation to pass through "the whole
  PMPro/FluentCRM object, just in case."
- Server logs should avoid logging full request/response payloads where
  practical, specifically because those payloads contain the same
  phone/membership data this section is protecting.

## 13. TypeScript domain types

`src/club/types.ts` is the frontend's idiomatic (camelCase) mirror of this
contract — screens consume these types, never raw REST JSON field names.
`src/club/restClubService.ts` (currently inert, not wired into the running
app) shows the exact DTO interfaces and mapper functions that translate
between the two, e.g. `valid_until` (wire) ↔ `validUntil` (domain).

```ts
type MembershipStatus = "active" | "expired" | "cancelled" | "inactive";

interface ClubMember {
  id: string;
  name: string;
  phoneMasked: string;
  status: MembershipStatus;
  validUntil: string | null;
}

// Mirrors the REST response's nested `membership` object one-to-one;
// used by the DTO mapper, not currently embedded in ClubMember itself.
interface MembershipInfo {
  status: MembershipStatus;
  startedAt: string | null;
  validUntil: string | null;
}

type BenefitType = "free_coffee";
type BenefitState = "available" | "used" | "unavailable";
type BenefitUnavailableReason = "membership_inactive";

interface MemberBenefitStatus {
  state: BenefitState;
  businessDate: string;
  redeemedAt: string | null;
  reason?: BenefitUnavailableReason;
}

interface MemberLookup {
  member: ClubMember | null;
  benefit: MemberBenefitStatus | null;
}

type ApiErrorCode =
  | "invalid_request" | "invalid_phone" | "invalid_qr"
  | "member_not_found" | "membership_inactive" | "benefit_already_redeemed"
  | "benefit_not_available" | "unauthorized" | "forbidden"
  | "rate_limited" | "server_error";

interface ApiError {
  code: ApiErrorCode;
  message: string;
  details?: Record<string, unknown>;
}

// Thrown by both the mock and the future REST client, so UI code
// branches on `.code` the same way regardless of which is active.
class ClubApiError extends Error implements ApiError { code, details, ... }
```

## 14. What stays a demo-only shortcut

Documented explicitly so nobody "fixes" these back into `mockClubService`
by accident — they're deliberate simplifications of a mock, not bugs:

- **Member-not-found returns `{ member: null }` instead of throwing.**
  Production is a real `404` (§4.1); `restClubService.ts`'s adapter
  absorbs the difference so the UI doesn't need to know which is active.
- **"Already used today" is tracked in `localStorage`.** Production is
  server-authoritative (§5); this disappears entirely once
  `restClubService` replaces the mock.
- **No authentication at all.** Production requires a staff session on
  every endpoint (§3); the demo's `/club` entry gate is cosmetic.
- **No real network calls, ever.** The mock simulates latency with
  `setTimeout` so the UI's loading states are demonstrable, but nothing
  leaves the browser.
