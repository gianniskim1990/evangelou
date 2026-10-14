# Member identity and trusted synchronisation (UI-04F)

**Status: pure PHP, definitions only, NOT wired.** The code is
`includes/class-evc-member-sync.php`.

- There are no WordPress hooks, PMPro/WooCommerce/FluentCRM integration, SQL,
  REST routes or UI.
- The packager rejects any shipped identity store, account directory, status
  or QR delivery policy, and any construction of the sync, account or
  coordinator services.

## Five concepts that stay separate

| Concept | Owner | Changed by |
|---|---|---|
| A. WordPress user | WordPress | WordPress. **Not** evidence of a paid membership. |
| B. Club member identity (`member_public_id`, exactly one WP user) | `evc_members` | Created once, at the first eligible activation; **never** replaced |
| C. Account status (enabled / disabled) | `evc_members.status` | **Only** `EVC_Member_Account_Service`: capability `evc_manage_club` and a reason from the owner's policy |
| D. Paid membership period | PMPro mapper + verified payment provenance (`EVC_Membership_Adapter`) | Verified payment facts. Decided when redeeming, never stored on the identity. |
| E. Today's free coffee | Redemption engine | One per Europe/Athens day |

## Trusted synchronisation (`EVC_Member_Sync_Service::sync()`)

**Signals are hints.** `EVC_Member_Sync_Signal` carries only:
- `source`: pmpro, woocommerce, gateway or scheduled_reconcile. There is **no**
  FluentCRM or newsletter source.
- `kind`;
- `wp_user_id`;
- an optional `member_public_id` hint.

It carries no status, amount, date, e-mail or phone, so a checkout success page
or an unverified payment event can never act as proof. Every sync re-reads
*current verified facts*, which makes duplicate and out-of-order signals
harmless by construction.

**Order:**
1. **Account check.** The WordPress account must be an ordinary customer
   account.
   - Staff, administrators and privileged accounts: `refused_account`.
   - Unknown account or directory failure: `identity_unavailable`.
2. **Existing identity.**
   - It is never replaced, disabled or re-enabled. The result is
     `already_member`, or `member_disabled` if an administrator disabled it.
   - Payment state is **not even read** for an existing member.
   - A mismatched or foreign hint gives `identity_conflict`.
3. **No identity yet.** The verified membership is read at one clock instant.
   - Only an eligible membership with verified payment provisions a member.
   - Pending, unverified, expired, cancelled, refunded, failed or
     indeterminate memberships give `not_eligible`, and **nothing is created**.
   - If the source is unavailable: `membership_unavailable`.
4. **Creation.** A fresh `EVC_Member_Id`, inserted enabled.
   - A duplicate key, meaning a concurrent sync won, triggers a re-read: one
     identity remains.
   - A lost COMMIT gives `outcome_unknown`. Recovery is to sync again: it reads
     by WP user first, so it **never creates a second identity** and never
     mints again if the first insert committed.

**Results** carry only `member_public_id` and `account_status`.
`grants_entitlement()` and `issued_qr()` are always false. Reports carry only
the stage and the exception class.

**Zero legacy paid members:** no import path exists, and newsletter or
FluentCRM contacts are never a source. Marketing consent is never read or
changed.

## Account status (`EVC_Member_Account_Service`)

- **Permissions and reasons:**
  - `disable` and `enable` need `evc_manage_club`; the shared staff role does
    not have it.
  - They need a reason code from the injected `EVC_Member_Status_Policy`. The
    reasons are an **OPEN owner decision**, and no default ships.
  - Free text is rejected, and a disable reason cannot enable.
- **Storage:** a compare-and-set on `status_version`, plus an append-only
  status event (member, status, reason, actor, time) in one transaction.
- **Failures:**
  - a concurrent change gives `concurrent_change`;
  - a lost COMMIT gives `outcome_unknown`, and a retry answers
    `already_in_state` if the change had committed.
- **Effect:** a disabled account fails closed for lookup (`not_found`) and
  redemption (`member_not_found`), even with a paid membership. A payment never
  re-enables it.

## QR coordination (`EVC_Member_Qr_Coordinator`, D8 OPEN)

- Synchronisation **never issues, rotates or revokes** a token, and renewals
  never touch it.
- `after_sync()` only reports one of:
  - `has_active_qr`;
  - `offer_first_qr`, when the injected `EVC_Qr_Delivery_Policy` allows
    prompting a newly provisioned member;
  - `no_action`, the default (no policy is approved);
  - `not_applicable`.
- Issuance itself stays with `EVC_Qr_Token_Service` and its own rules:
  `member_self` or an administrator, compare-and-set rotation.
- Holding a QR never proves payment.

## Future SQL dependencies (NOT implemented; migration numbering unapproved)

**`evc_members` (001)** already has the unique keys on `member_public_id` and
`wp_user_id`. The identity contract additionally needs (additive):
- `status_version` INT UNSIGNED NOT NULL DEFAULT 1;
- `status_reason_code` VARCHAR(32) ascii NULL;
- `status_changed_at_utc` and `status_changed_by_wp_user_id`;
- an append-only `evc_member_status_events` table (member, status, reason,
  actor, time; FK member).

**Creation:** `INSERT`, where 1062 on `uq_members_wp_user` means rollback and
re-read. No row locks are needed, because the unique key decides.

**Status change** (one transaction):
1. `UPDATE evc_members SET status=?, status_version=status_version+1, …
   WHERE id=? AND status_version=?`. Zero affected rows means a conflict.
2. `INSERT` the status event.
3. `COMMIT`.

**Grants:** the sync writer gets SELECT and INSERT on `evc_members`, plus
`UPDATE(status, status_version, status_reason_code, status_changed_*)` only
for the account administration service. There is no DELETE.

## Open owner decisions

- Disable and enable reason codes.
- Who holds `evc_manage_club`.
- QR delivery and prompting (D8).
- Partial refund, refund and cancellation policy (D3, D4, D10). The
  entitlement mapper keeps those cases fail-closed.
- History retention (D9).
- Whether a member may self-close their Club identity.
