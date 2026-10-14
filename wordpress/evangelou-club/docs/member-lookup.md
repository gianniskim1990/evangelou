# QR tokens, secure member lookup and lookup-context proof (UI-04C)

**Status: pure PHP, definitions only, NOT wired.** The code is
`includes/class-evc-member-lookup.php`.

- There are no SQL store, rate-limit adapter, keys, REST routes, hooks or UI.
- The packager rejects any shipped store, phone directory or rate-limiter
  implementation, and any construction of `EVC_Member_Lookup_Service`,
  `EVC_Qr_Token_Service`, `EVC_Lookup_Key_Ring` or
  `EVC_Signed_Member_Context_Verifier`.
- That verifier is the first real implementation of
  `EVC_Member_Context_Verifier` (UI-03E). It ships as a definition only, so
  the old ban on `implements EVC_Member_Context_Verifier` became a ban on
  constructing it.
- The staff flag stays off, redemption stays HTTP 503, and migration 003
  stays unapproved.

## What a QR token is (and is not)

A QR token is a **member lookup handle**.

- It is **not** staff authentication, payment confirmation, an entitlement,
  or permission to redeem.
- A successful lookup establishes *which member is at the counter*.
  `grants_entitlement()` is always false.
- Verified payment, the active month and today's coffee are decided only by the
  redemption engine, which a lookup never calls or changes. This is tested: a
  member without payment is refused, a scan after today's coffee consumes
  nothing, and a second coffee stays `benefit_already_redeemed`.

**Token format and storage:**
- `EVC_Qr_Token` gives 192 random bits as `evc_` + 48 hex characters, with no
  personal data.
- Only `sha256(token)` is stored (`evc_qr_tokens.token_hash`).
- The plaintext is returned **once**, at issuance. No result ever contains a
  hash.

## Issuance, rotation, revocation (`EVC_Qr_Token_Service`)

| Requester | Rule |
|---|---|
| `member_self(wp_user_id)` (server-side WordPress session) | Only the member's **own** record. Any other id, existing or not, is `forbidden`, so existence is not revealed. |
| `administrator(actor)` | Needs `evc_manage_club`, checked **before any read**. The shared staff role does not have it (`evc_lookup_member`, `evc_redeem_benefit` and `evc_view_redemption_history` only). |

| Situation | Outcome |
|---|---|
| First issuance, re-issuance, rotation | One operation: an atomic **compare-and-set rotation**. If the active hash is still the one read, revoke it and insert the new token; at most one token is ever active. |
| Revocation | Compare-and-set revoke; `nothing_to_revoke` when no token is active |
| Old or rotated token | Lookup `not_found` |
| Disabled member | `member_disabled`; no token |
| Concurrent rotation, revoke-versus-rotate, or a concurrent first issuance (unique key) | The loser gets `concurrent_change` with **no token**; exactly one active token |
| Lost COMMIT | `outcome_unknown` with **no token returned**. Recovery: issue again, which rotates whatever was stored |
| Bad generator output or store failure | `server_error`; the reporter gets the exception class only |
| Expiry | `EVC_Qr_Token_Policy` (`without_expiry()` or `max_age_days(n)`) is injected. **No age limit is approved**; tests inject one. |

## Lookup (`EVC_Member_Lookup_Service`)

Every call follows the same order:

1. **Permission and session.** The actor needs `evc_lookup_member` and a
   session reference. Otherwise `forbidden`, with **no read and no
   rate-limiter call**.
2. **Rate limit before any read.** Keys are `actor:<id>`, which spans every
   tablet of the shared account, and `session:<ref>`. A phone lookup adds
   `phone:<HMAC pseudonym>`. If the limit is hit, the answer is `rate_limited`.
   If the limiter is unavailable, the answer is `unavailable`
   (**fail closed**).
3. **The lookup itself.**

**QR lookup.** These all give the same `not_found`: malformed, unknown,
revoked, rotated or expired tokens, and disabled or unknown members. Every
failure is recorded for abuse limiting.

**Phone lookup:**
- **Canonicalisation (`EVC_Phone_Canonical`):** spaces, dots, dashes and
  parentheses are ignored, and an optional `+30` / `0030` prefix is accepted.
  The number must be exactly 10 digits: a mobile `69…` or a landline `2…`. The
  canonical form is `+30…`. Anything else is `invalid_phone`, with no
  directory read. Foreign numbers are an **open decision**.
- **Matching:** numbers are **never assumed unique**.
  - Zero enabled members gives `not_found`.
  - Two or more gives `ambiguous`, with **no data**; staff use the member's
    QR instead.
  - Exactly one gives `found`.
  - Disabled members never count, and duplicate ids are one member.
- **Privacy:** plaintext numbers never reach logs, the reporter or rate-limit
  keys. Only a keyed HMAC pseudonym does.

`found` returns only `member_public_id`, `lookup_ref` and
`lookup_ref_expires_at_utc`. It carries no name, phone, payment, membership,
eligibility or history.

## Lookup-context proof (`EVC_Lookup_Context`, `EVC_Signed_Member_Context_Verifier`)

```
lkp1.<key_id>.<base64url(payload)>.<base64url(HMAC-SHA256)>
payload = {v:1, staff, session, member, purpose, iat, exp, nonce}   (exact keys and order)
MAC     = HMAC-SHA256(subkey(key_id, "lookup-context"), "evc-lookup-context-v1\n" + key_id + "\n" + payload)
```

A proof verifies only when **all** of these hold:
- the MAC matches (checked in constant time) under a key id still in the ring;
- the payload has exactly the expected shape;
- it names the same staff user and the exact same session (`session_ref`);
- it names the same member and the same purpose;
- `iat ≤ now < exp`, and `exp − iat` is not above the **current** policy TTL.

So a lookup of member A never authorises member B. Another tablet on the shared
account, which has a different session, does not inherit it. The proof is
reusable within its lifetime by the same session and fails without saying why.

**TTL:** `EVC_Lookup_Context_Policy` allows 1 to 3600 seconds and **has no
default**. The lifetime is an **open owner decision**; tests inject 300 s.

**Keys (`EVC_Lookup_Key_Ring`):**
- Keys are injected; there are **no keys in code**.
- Each needs at least 32 bytes and an id matching `[a-z0-9]{1,16}`.
- One key is active; older keys verify only, and removing a key retires it.
- Domain-separated subkeys are used for proofs and for phone pseudonyms.
- `__debugInfo` never dumps secrets.

**Key management requirements (future):**
- secrets come from server configuration outside git and the database;
- they rotate by adding a new active key and later removing the old one;
- they are separate per environment;
- they are never logged.

## Future SQL requirements (NOT implemented; migration authorisation required)

**`evc_qr_tokens` (migration 001) already has** `uq_qr_tokens_hash` and
`uq_qr_tokens_one_active` (a stored generated column).

**Rotation**, in one READ COMMITTED transaction:
1. `SELECT … FOR UPDATE` the member's active row.
2. Compare it with the expected hash.
3. `UPDATE` it to revoked (`revoked_at_utc`).
4. `INSERT` the new active row.
5. `COMMIT`.

How each failure maps:
- a mismatch is a conflict;
- 1062 on either unique key means rollback, then `concurrent_change`;
- a lost COMMIT means `outcome_unknown`.

**Revocation** is the same transaction without the insert.

**Runtime grants:** SELECT, INSERT and UPDATE on `evc_qr_tokens` only for
status and `revoked_at_utc`. No DELETE.

**Rate limiter:** an atomic increment-with-expiry store, such as a
`(key, action, window)` table with `INSERT … ON DUPLICATE KEY UPDATE` or an
object cache. Thresholds and windows are open decisions. If it is unavailable,
lookups are refused.

**Phone directory:** WordPress or WooCommerce verified phone data mapped to
`evc_members` ids. Which field is authoritative, and whether it is verified,
are technician and owner decisions.

## Open decisions

- Lookup-proof TTL.
- QR token maximum age, if any.
- Rate-limit thresholds and windows.
- Accepting foreign phone numbers.
- Which phone field is authoritative and verified.
- Who may administer tokens (the `evc_manage_club` holders).
- QR delivery (D8).
- Retention (D9).
