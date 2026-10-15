# Staff REST v2 — route table, auth boundary and envelope security (UI-04H)

**Status: design + inert definitions + test-only harness. NOT wired.**

- `includes/class-evc-rest-v2-boundary.php` (pure): `EVC_Rest_V2_Routes`
  (the designated route table) and `EVC_Rest_V2_Envelope_Policy` (the
  fail-closed exemption decision).
- `includes/staff/class-evc-rest-v2-security.php` (WordPress adapter):
  `EVC_Rest_V2_Domain_Response` (the sealed response source) and
  `EVC_Rest_V2_Security::filter_post_dispatch`.
- **Nothing in shipped code registers a v2 route, hooks the v2 filter or
  constructs a sealed response.** `EVC_Staff_Plugin` is unchanged; the v1
  filter, v1 routes and the 503 redemption controller are unchanged;
  `EVC_CLUB_STAFF_ENABLED` stays OFF. The packager and a CI grep reject
  `new EVC_Rest_V2_Domain_Response`, `register_rest_route(EVC_Rest_V2_Routes`,
  `'EVC_Rest_V2_Security'` (hook registration), and `EVC_Test_V2_Harness`.

## 1. Route table (namespace `evangelou-club/v2`)

Path ids are validated by the route regex itself, so a malformed id never
reaches a controller (core answers 404, mapped to the v1 envelope).

- `{member}` = `mem_` + 32 hex.
- `{rid}` = a lowercase UUID v4.

| Id | Method + path | Request body (exact keys) | Reply body (UI-04G) |
|---|---|---|---|
| `lookup_qr` | `POST /lookup/qr` | `qr_token` | lookup `{result, member}` |
| `lookup_phone` | `POST /lookup/phone` | `phone` | lookup `{result, member}` |
| `redeem` | `POST /members/{member}/redemptions` | `request_id`, `catalogue_version`, `coffee_code`, `preparation`, `lookup_ref` | redemption body |
| `status` | `GET /members/{member}/redemptions/{rid}` | none | `{result, data}` |
| `handoff` | `POST /members/{member}/redemptions/{rid}/handoff` | `handoff_request_id`, `claim` | handoff body |
| `usual` | `POST /members/{member}/usual-coffee` | `lookup_ref` | `{result, reason, suggestion, confirmed_order}` |

Notes:

- **Lookup by POST, never GET.** A QR token, phone or lookup proof never
  appears in a URL or access log.
- **Usual coffee is POST** for the same reason: it carries the lookup proof.
- **The URL is the authority** for the member and the redemption id. A
  handoff body containing a *different* `redemption_request_id` is
  malformed: the domain reply is `invalid_request`, never redirected.
- **No route opts into the core batch endpoint** (`allow_batch` is left at
  its default, false).
- The staff id is always `get_current_user_id()`; the session reference is
  the keyed hash of the current session token. Neither ever comes from the
  request.

### Per-route rules

| Id | Capability | Lookup proof | Idempotency | Rate-limit bucket |
|---|---|---|---|---|
| `lookup_qr` | `evc_lookup_member` | issues one | none (read) | `lookup`: per actor + session, plus the domain lookup limiter |
| `lookup_phone` | `evc_lookup_member` | issues one | none (read) | `lookup`: also keyed by the phone pseudonym |
| `redeem` | `evc_redeem_benefit` | **required**, bound to staff + session + member, checked **before any write** | `request_id`: a replay never prepares; an unknown outcome → same-id retry | `write` |
| `status` | `evc_view_redemption_status` (**PENDING**) | not required (unguessable id bound to the member; recovery must survive proof expiry) | none (read) | `read` |
| `handoff` | `evc_confirm_handoff` (**PENDING**) | not required (bound to an existing redemption of this member) | `handoff_request_id` | `write` |
| `usual` | `evc_view_usual_coffee` (**PENDING**) | **required** (checked by the service) | none (read) | `read` |

PENDING = not granted by `EVC_Staff_Role`; see §5.

### Error responses

**Transport (v1 envelope `{"error":{code,message}}`, no `details`).** These
happen before any service ran:

| Status | Code | Cause |
|---|---|---|
| 401 | `unauthorized` | no session, missing or invalid nonce (core's 403 `rest_cookie_invalid_nonce` is mapped), revoked, expired or idle session |
| 403 | `forbidden` | wrong role or capability, privileged staff-tagged account, disabled account; for `redeem` also a missing or invalid lookup proof (nothing recorded) |
| 429 | `rate_limited` | per-session request budget |
| 400 | `invalid_request` | WordPress argument validation, or an output-shaping parameter (`_envelope`, `_jsonp`, `_embed`, `_fields`) |
| 404 / 405 | `invalid_request` | unknown route or method |
| 500 | `server_error` | any unsealed or unexpected reply, WP_Error or exception |
| 503 | `server_error` | unavailable backend |

**Domain.** Exactly the UI-04G bodies and statuses (`docs/rest-contract-v2.md`).

## 2. Auth boundary (order is fixed)

1. **Active staff session.** Cookie auth only; Application Passwords are
   refused.
2. **WP REST nonce.** `X-WP-Nonce` / `wp_rest` is checked by core and again
   by `EVC_Staff_Auth`.
3. **Restricted account and capability.** `EVC_Staff_Role` gives the
   exclusive shared account; it must not be disabled and must hold the
   route capability.
4. **Session revocation check** on every request. `EVC_Staff_Session` covers
   the 12 h absolute and 30 min idle limits; a destroyed token is refused
   (logout, emergency revocation, password rotation).
5. **Rate limit.**
   - It is consumed **once per handled request, inside the controller**.
     Core re-runs permission callbacks for the `Allow` header (via
     `rest_send_allow_header`), so a limiter in the permission callback
     would count each request several times. A harness test asserts
     exactly one decrement per request.
   - The lookup limiter fails closed: if it is unavailable, the domain
     result is `unavailable`.
6. **Lookup proof.** It must verify for exactly this staff member, session,
   member and purpose; otherwise:
   - `redeem` → 403 envelope;
   - `usual` → domain `forbidden`.

Steps 1–5 run in the permission callback **and again** at the start of the
callback (defense in depth, as the v1 controller does). Failures in steps
1–5 are always transport envelopes.

## 3. Envelope security: route-aware, fail-closed exemption

**The gap (UI-04G).** `EVC_Contract_Envelope_Rule::is_domain_body()`
recognised a body by its JSON key set only. A key set is not
authorization: any route, plugin filter or forged array could produce it.

**The mechanism.**

- **A sealed source.** A v2 controller returns
  `new EVC_Rest_V2_Domain_Response($route_id, $payload)`. The constructor:
  - accepts only a body of that route's surface (exact per-surface shape
    **and** a known outcome);
  - sets the status from `EVC_Contract_Http`;
  - stores a seal {route id, surface, body, status}.
  No other object carries a seal, so plain arrays, `WP_REST_Response`s,
  `WP_Error`s and core errors never do.
- **The filter** (`rest_post_dispatch`, priority 98, i.e. before v1's 99)
  hands `EVC_Rest_V2_Envelope_Policy::decide()` the request route and
  method, WordPress's matched route, the current status and data, the seal,
  whether a user is authenticated, and whether output-shaping query
  parameters are present.

**The decision, in order:**

| Situation | Result |
|---|---|
| Outside the exact v2 namespace (not a prefix match; `evangelou-club/v2x` and `…/v2-shadow` are outside), no seal | **ignored**: v1 and core behaviour unchanged |
| Outside the exact v2 namespace, sealed | `server_error` 500 (a wrong namespace never gains the exemption) |
| HTTP 401 | **always** `unauthorized` 401 (never an authenticated domain body) |
| Unsealed | mapped to a minimal transport envelope (table above); core and WP_Error codes and messages never pass |
| Sealed, but output-shaping parameters present | `invalid_request` 400 |
| Sealed, not authenticated | `unauthorized` 401 |
| Matched route + method is not the designated route the seal names (same surface too) | `server_error` 500 |
| Data or status changed after sealing; body not of the surface; status ≠ `EVC_Contract_Http::status()` | `server_error` 500 |
| Otherwise | **pass**, with security headers |

**Consequences:**

- **Domain failures are never turned into successes.** A sealed 409/503
  rewritten to 200 by any later filter becomes a 500 envelope.
- **503 `outcome_unknown` passes only sealed,** with its same-id retry
  intact. A forged one becomes a plain 503 envelope with no retry claim.
- **Internal errors never leak.** Only the code and the fixed Greek
  message are sent.

**Residual risk (documented, not solved here).** Core applies
`rest_envelope_response` and `rest_pre_echo_response` *after*
`rest_post_dispatch`.

- `_envelope` and `_jsonp` are refused (400). Core still wraps or encodes
  that refusal:
  - `_envelope` gives HTTP 200 around `{body, status, headers}`;
  - `_jsonp` gives a JavaScript body.
- The client therefore never trusts such a reply: `classifyHttpReply`
  treats a missing contract status/body pair or a non-JSON body as a
  transport failure.
- A third-party plugin filtering `rest_pre_echo_response` cannot be
  excluded by a filter. It is a deployment review item.

## 4. Client side (`app/src/staff/redemptionRecovery/wire.ts`)

`classifyHttpReply(surface, status, body)` returns DOMAIN only when **all**
of these hold:

- the body has no `error` key;
- the status is not 401;
- its `outcome` (redemption/handoff) or `result` (others) belongs to *that
  surface* (own keys only, never prototype keys);
- the HTTP status equals `CONTRACT_STATUS[surface][key]`.

`CONTRACT_STATUS` mirrors `EVC_Contract_Http`. The vectors file exports the
PHP map (`http_map`) and the TS test requires deep equality.

Everything else is a transport failure:

| Reply | Failure |
|---|---|
| Contradictory status/body pair | `unavailable` |
| v1 envelope | by code: `unauthorized`, `forbidden`, `rate_limited`, else `unavailable` |
| Non-JSON reply, `_envelope` wrapper, proxy page | by status, else `unavailable` |

A transport failure never says whether anything was committed. Recovery is
by status read or same-id retry, as the UI-04B machine already does.

## 5. Capability mismatch audit

**Verified gap.** The staff role grants exactly `evc_lookup_member`,
`evc_redeem_benefit` and `evc_view_redemption_history`. The domain services
require:

| Service | Capability | Granted? |
|---|---|---|
| `EVC_Member_Lookup_Service` | `evc_lookup_member` | yes |
| prepared redemption (route gate) | `evc_redeem_benefit` | yes |
| `EVC_Handoff_Service::confirm` | `evc_confirm_handoff` | **no** |
| `EVC_Fulfillment_Read_Model::redemption_status` | `evc_view_redemption_status` | **no** |
| `EVC_Fulfillment_Read_Model` review | `evc_review_fulfillment` | **no** (manager) |
| `EVC_Usual_Coffee_Service` | `evc_view_usual_coffee` | **no** |

`EVC_Staff_Auth::authorize()` also refuses any capability outside the staff
set. So today handoff, status and usual coffee are unreachable for staff
(403 at the gate, and domain `forbidden` even past it). This **fails
closed**, but it would break the UI-04B recovery flow (status) and the
mandatory-handoff design. `RestV2HarnessTest::test_capability_mismatch_…`
documents this with a regression test.

**Options for usual coffee:**

| | A. Reuse `evc_view_redemption_history` | B. Dedicated `evc_view_usual_coffee` |
|---|---|---|
| Least privilege | Weaker: the usual-coffee suggestion is a derived preference profile, and history is a superset. Granting either grants both, and the history screen cannot be disabled while keeping suggestions (or vice versa). | Stronger: each can be granted or revoked independently. |
| Role migration | No role change; the service constant changes instead. | Add the capability to `EVC_Staff_Role::staff_capabilities()`. `register()` runs **only on activation**, so existing installs need re-activation or an explicit, idempotent upgrade routine. The strict allowlist (`restricted_account_violation`) follows `staff_capabilities()` automatically. |
| Privacy | Preference profiling rides along with every history grant; it cannot be switched off (D9 retention questions apply to both). | The owner can disable profiling-like suggestions without touching history, and it can be audited separately. |
| Backwards compatibility | Changes the meaning of an existing capability. | Purely additive; existing grants unchanged; unknown capability still fails closed until granted. |

**Recommendation: B, dedicated capabilities.** Apply the same reasoning to
`evc_confirm_handoff` and `evc_view_redemption_status`. These are needed by
every staff tablet once the flow is live: grant them to the staff role
together, in one owner-approved role revision, delivered through a
versioned role-upgrade step rather than relying on activation. Keep
`evc_review_fulfillment` manager-only.

**No role was changed in UI-04H** (owner approval required).

## 6. Test-only harness

- **Where it lives.** `tests/wordpress/support/class-evc-test-v2-harness.php`
  plus the CI-only mu-plugin `tests/http/mu-plugins/evc-v2-harness.php`. It
  registers the designated routes with test controllers that follow §2 and
  drive the **real** services over in-memory fakes. Outcomes go through the
  **real** UI-04G mappers and come back sealed. The harness hooks the
  otherwise inactive v2 filter.
- **Adversarial routes.** Forged key sets (201 and 503), a `WP_Error` with
  secrets, a sealed body on an undesignated v2 route, a sealed body in a
  shadow namespace and in v1, an HTML "proxy" page, and fault injection
  (unknown commit, store down, tamper after sealing, exception, transport
  rate limit).
- **Why it can never load in production.**
  - It lives under `tests/`, and the packager copies only an allowlist and
    rejects `class EVC_Test_`.
  - The mu-plugin is copied into the disposable CI site only for one step
    and deleted afterwards.
  - It refuses to load unless `EVC_HTTP_HARNESS=on`, the database name is
    `evc_wp_tests*`, and the staff flag is on.
- **wp-phpunit** (`tests/wordpress/enabled/RestV2HarnessTest.php`, PHP
  7.4/8.2/8.4 × MySQL/MariaDB) runs core authentication, permission
  callbacks, dispatch and every `rest_post_dispatch` filter.
- **Real HTTP** (`tests/http/run-http-checks.php harness`) logs in through
  `wp-login.php`, uses real cookies and nonces against `php -S`, and runs
  the packaged plugin plus the mu-plugin. Every raw reply is captured and
  then fed to the **real TypeScript** classifier and parsers
  (`app/scripts/check-http-captures.mjs`).
