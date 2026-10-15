# Staff capability upgrade: design and rollout plan (UI-04I)

**Status: PROPOSAL + inert pure planner. Nothing is granted.**

- The shipped role is unchanged: `EVC_Staff_Role::staff_capabilities()` is
  still exactly version 1.
- `includes/class-evc-staff-role-upgrade.php` only *computes and verifies*
  plans. It has no applier, writes no option and is not hooked to
  activation, `plugins_loaded`, `admin_init` or REST.
- The packager rejects these strings in shipped code:
  - any call to `EVC_Staff_Role_Upgrade_Planner::`;
  - any `new EVC_Staff_Role_Upgrade_Approval`;
  - any use of `EVC_Staff_Role_Versions::VERSION_OPTION`.
- The applier and the snapshot reader exist **only in tests**
  (`tests/wordpress/support/class-evc-test-role-upgrade.php`).

## 1. Capability matrix

| Capability | Current role (v1, shipped) | Proposed role (v2, NOT approved) | Used by |
|---|---|---|---|
| `evc_lookup_member` | yes | yes | QR / phone lookup |
| `evc_redeem_benefit` | yes | yes | prepared redemption |
| `evc_view_redemption_history` | yes | yes | history screen |
| `evc_confirm_handoff` | **no** | yes | `EVC_Handoff_Service::confirm` |
| `evc_view_redemption_status` | **no** | yes | `EVC_Fulfillment_Read_Model::redemption_status` (UI-04B recovery) |
| `evc_view_usual_coffee` | **no** | yes | `EVC_Usual_Coffee_Service` |
| `evc_review_fulfillment` | no | **no** (manager-only) | fulfillment review |
| `evc_manage_club` | no | **no** (administrators only) | revocation, disabling the account |

**Why three separate capabilities.**
- They are three different data exposures: a write (handoff), a read of one
  redemption (status), and a derived preference profile (usual coffee).
- Each can be withheld or revoked on its own.
- Nothing about history is implied by usual coffee, or the other way round.

**Unchanged in v2:**
- exactly one restricted role;
- no `read`, no wp-admin, no WooCommerce, PMPro, FluentCRM or user
  management;
- the same 12 h / 30 min session policy and emergency revocation;
- the exact allowlist rule (`restricted_account_violation`).

## 2. The upgrade model

**Versions.** `EVC_Staff_Role_Versions` defines `1` (shipped) and `2`
(proposed). The recorded version would live in the option
`evc_staff_role_version`. It does not exist today; activation never writes
it, and a test asserts that.

**Planning.** `EVC_Staff_Role_Upgrade_Planner::plan($snapshot, $target,
$approval)` is a pure dry run. The snapshot holds every role, the staff
role, the recorded version, every staff-tagged account and every user
holding a Club capability directly.

**It refuses (fail closed, proposing no change) when:**

| Condition | Refusal |
|---|---|
| unknown target version | `unknown_target_version` |
| malformed or inconsistent snapshot | `invalid_snapshot` |
| staff role missing (the upgrade never creates roles or users) | `role_missing` |
| recorded version unknown, e.g. written by a newer release (never silently downgraded) | `unknown_stored_version` |
| any denial (`false`) on the staff role | `role_capability_denial` |
| any staff-role capability outside every defined version (e.g. `manage_options`, `read`, WooCommerce/PMPro capabilities, `evc_manage_club`, `evc_review_fulfillment`); it is **never stripped silently**, because escalation needs a human investigation | `unexpected_role_capability` |
| any **other** role (administrator included) holding a staff capability | `staff_capability_on_other_role` |
| any staff-tagged account with another role, any direct capability or denial, or super-admin status | `staff_account_violation` |
| any user holding a Club capability directly | `unauthorized_direct_capability` |
| a plan that would grant anything beyond the shipped set without an `EVC_Staff_Role_Upgrade_Approval` whose version **and exact capability set** match | `not_approved` |

**Otherwise it returns `ready` or `up_to_date`, with:**
- `add` / `remove`: exact capability operations on the staff role only;
- `set_version`: written **last**, only after every capability change;
- `partial`: true when the role is between versions, damaged, or
  disagrees with the recorded version;
- a fingerprint of every *other* role.

**Approval rules:**
- Removing capabilities (rollback) and repairing missing shipped
  capabilities need no approval.
- Completing a partial upgrade that still has grants to make needs the
  approval again.

**Verification.** `verify($after, $plan)` requires:
- the exact target set on the role, with no extra entries or denials;
- the recorded version;
- every other role byte-for-byte unchanged (fingerprint);
- all safety refusals still clear: accounts restricted, no direct grants,
  no leaks to other roles.

**Idempotence.** Re-planning an applied upgrade gives `up_to_date` with no
operations. The test-only applier then performs zero writes, and the stored
roles option is unchanged.

## 3. Findings that shape the rollout (proved by tests)

1. **Role and code must move together.** The shipped allowlist is v1. A role
   upgraded to v2 *without* the matching code release makes
   `restricted_account_violation()` return `capability_not_allowed` for the
   shared account. Every route, old and new, then becomes 403. This fails
   closed (no privilege is gained), but it is an outage. The future release
   must make the allowlist accept v2 **only** when the recorded version is 2
   and the role verifies as exactly v2.
2. **Reactivation silently reverts the role.** `EVC_Staff_Role::register()`
   (activation) forces the exact shipped set, so reactivating after an
   upgrade restores v1 while the option still says 2. The planner reports
   this as `partial`. The future release must make `register()`
   version-aware and never strip an approved, recorded version.
3. **Partial writes are real.** Each `add_cap()` rewrites the roles option,
   so a crash can leave 1 or 2 of the 3 capabilities. The planner detects
   it; the fix is either to complete the upgrade (with the approval again)
   or to roll back. While partial, the v1 allowlist keeps the account
   fail-closed.
4. **Sessions.** Role changes apply to the very next request of every
   existing session. Sessions are neither upgraded nor destroyed by the
   role change itself. Emergency revocation keeps working on an upgraded
   role.

## 4. Future rollout plan (requires owner approval at every marked step)

**A. Before-upgrade checks** (dry run on staging, then production, read only):
- plugin and code version recorded;
- database backup;
- `plan(snapshot, 2, null)` must return `not_approved` and nothing else (any
  other refusal is a blocking investigation);
- exactly one staff-tagged user, restricted, no direct grants;
- no staff capability on any other role;
- the staff flag state and the active staff sessions noted.

**B. Approved capability changes (OWNER).** The owner approves version 2
with exactly these capabilities: lookup, redeem, history,
`evc_confirm_handoff`, `evc_view_redemption_status`,
`evc_view_usual_coffee`. The decision is recorded under a reference (e.g.
`D-CAP-1`), and only that exact set becomes an
`EVC_Staff_Role_Upgrade_Approval`. Any subset or superset is refused.

**C. Upgrade version handling.** A separately reviewed release ships, in
this order:
1. a version-aware allowlist (version 2 only when recorded and verified);
2. a version-aware `register()`;
3. a manual, administrator-only (`evc_manage_club`) upgrade command, with
   CLI or admin-post plus nonce, that runs plan → apply (capabilities, then
   version) → verify and logs only codes and ids.

It is **never** automatic: not on activation, `plugins_loaded`,
`admin_init` or REST.

**D. Verification after upgrading.**
- `verify()` returns empty.
- Reload roles in a new request and verify again.
- The shared account passes `restricted_account_violation() === null`.
- An administrator still has no staff capabilities.
- The staging acceptance list (I) passes.

**E. Partially applied changes.**
- Re-run the dry run; `partial: true` lists exactly what is missing.
- Either complete with the same approval, or roll back.
- Never hand-edit roles.
- The account stays fail-closed while partial.

**F. Safe recovery and rollback.**
- `plan(snapshot, 1, null)` removes exactly the three capabilities and
  records version 1. It needs no approval; verify it.
- If verification ever fails, or any refusal other than `not_approved`
  appears: stop, emergency-revoke all staff sessions, disable the shared
  account (`EVC_Staff_Auth::set_disabled`), and investigate.
- Restore from backup only as a last resort.

**G. Existing active staff sessions.**
- Upgrade in a quiet window.
- Emergency-revoke all staff sessions **after** verification, so every
  tablet logs in again and receives a fresh bootstrap. The same after a
  rollback.
- Never extend session limits for the upgrade.

**H. Role persistence across restart.**
- WordPress stores roles in the `<prefix>user_roles` option.
- After the upgrade, verify from a fresh request (tests reload with
  `wp_roles()->for_site()` and read the option directly).
- After any plugin reactivation, re-run the dry run. Today it reports
  `partial` because of finding 2.

**I. Staging acceptance tests.**
- the full CI suite on the release SHA;
- dry run with no approval → `not_approved` only;
- apply with the approval → verify;
- repeat → `up_to_date`, zero writes;
- crash simulation → `partial` → complete → verify;
- rollback → verify;
- reactivation → planner flags it;
- the shared account: lookup, redeem, status, handoff and usual coffee pass
  through the v2 harness;
- editor, administrator, staff+administrator and direct-grant accounts all
  get 403;
- revocation and the idle/absolute limits behave unchanged.

**J. Production activation approval (OWNER).**
- A written go/no-go after staging acceptance, naming the release SHA, the
  approval reference, the maintenance window and the rollback owner.
- It is separate from, and does not imply, enabling `EVC_CLUB_STAFF_ENABLED`
  or v2 REST wiring.

### The owner must approve, explicitly and separately

1. The capability set of version 2 (each of the three capabilities, or a
   different set).
2. That `evc_review_fulfillment` and `evc_manage_club` stay manager-only.
3. The release that makes the allowlist and `register()` version-aware, and
   the manual upgrade command.
4. Staging execution, then production execution, at a named window, with a
   named rollback owner.
5. The session handling at upgrade time (revoke all staff sessions).

**No automatic grants exist, and none may be added without these approvals.**

## 5. Tests

- **Pure** (`tests/unit/StaffRoleUpgradePlannerTest.php`):
  - definitions and approval validation;
  - v1 → v2 only with the exact approval;
  - new installation, repeats, partial upgrades at every crash point,
    rollback, a damaged role;
  - every refusal: escalation, denial, other roles, misconfigured accounts
    (extra role, direct capability or denial, super admin), direct grants,
    missing role, unknown versions, malformed snapshots;
  - verify detects every unsafe or incomplete result;
  - fingerprint semantics.
- **WordPress** (`tests/wordpress/common/StaffRoleUpgradeTest.php`,
  disposable database, both flag modes):
  - the shipped role and activation are unchanged;
  - an exact upgrade of an existing install, persistence across a role
    reload, repeats as no-ops;
  - a new installation;
  - resume or rollback after crashes;
  - rollback restores access;
  - the role/code coupling fails closed while the active session is kept;
  - revocation works;
  - reactivation hazard detected;
  - escalation attempts (staff role extras, administrator capabilities,
    staff+administrator, multiple roles, direct grants) are refused **with
    zero changes**;
  - unrelated roles and users are untouched.
- **Mutation guards:** RU1–RU20.
