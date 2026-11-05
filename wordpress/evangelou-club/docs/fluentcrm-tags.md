# FluentCRM membership tags (STAGE-06)

**Status: built and tested, default OFF.** Nothing is hooked, scheduled or called unless wp-config.php
defines `EVC_CLUB_FLUENTCRM_SYNC_ENABLED` as the boolean `true`. Enabling it on any real site is a
separate owner and technician step. It has not been done.

## What it does

Each Club member who already has a FluentCRM contact carries **exactly one** of three tags, chosen
from the Club's own membership state:

| Tag (staging, unverified) | When |
|---|---|
| **Active** (2) | a verified, current paid period |
| **Inactive** (3) | expired, cancelled, voided, refunded or frozen |
| **Pending payment** (4) | a Club order awaiting its bank transfer (unpaid BACS), with no paid time |

Applying one tag removes the other two. Nothing else in FluentCRM is touched:

- not tag 1 «Ευαγγέλου Club»;
- not any list;
- not the subscription or consent status, or double opt-in;
- not names or any other field;
- no contact is created.

The membership state always comes from the authoritative Club / PMPro side: the WooCommerce + PMPro
reader, the Club's recorded payments and `EVC_Pmpro_Entitlement_Mapper`. It never comes from FluentCRM.

## Configuration (wp-config.php, no defaults)

```php
define('EVC_CLUB_FLUENTCRM_SYNC_ENABLED', true);           // exact boolean true only
define('EVC_CLUB_FLUENTCRM_TAG_ACTIVE_ID', 2);             // int >= 1, verified on the site first
define('EVC_CLUB_FLUENTCRM_TAG_ACTIVE_NAME', 'Active');    // the tag's exact title OR slug
define('EVC_CLUB_FLUENTCRM_TAG_INACTIVE_ID', 3);
define('EVC_CLUB_FLUENTCRM_TAG_INACTIVE_NAME', 'Inactive');
define('EVC_CLUB_FLUENTCRM_TAG_PENDING_ID', 4);
define('EVC_CLUB_FLUENTCRM_TAG_PENDING_NAME', 'Pending payment');
```

The IDs 2/3/4 were reported by the technician for **staging** and are **unverified**; production may
differ. The plugin ships no value for any of these constants, and the packager refuses a shipped file
that defines one.

Even with the flag ON, nothing is written unless **all** of the following hold. Otherwise there are no
writes, an administrator notice shows the reason code, and the log holds a class name only, at most
once an hour.

| Check | Code when it fails |
|---|---|
| the six constants exist; IDs are ints ≥ 1; names are non-empty strings; all distinct | `tags_not_configured`, `tag_id_invalid`, `tag_name_invalid`, `tags_not_distinct` |
| no configured name, verified title or verified slug is «Ευαγγέλου Club» / `ευαγγέλου-club` / «Evangelou Club» | `protected_tag_configured` |
| FluentCRM is active with the documented API, `FLUENTCRM_PLUGIN_VERSION` ≥ 3.2.5 | `api_unavailable` |
| each configured ID exists | `tag_missing` |
| each ID's tag has that ID, and its title **or** slug equals the configured name (trimmed, case-insensitive) | `tag_mismatch` |
| the tag check itself did not fail | `tag_check_failed` |
| the Club membership source is configured: `EVC_CLUB_MEMBERSHIP_SOURCE` and the rest, `EVC_CLUB_PAYMENT_PROVENANCE = 'club_db_v1'`, and `EVC_CLUB_DB_*` | `membership_source_not_configured` |

The tags are verified before any write, once per request or cron run.

## Mapping table

The rules are in `EVC_Fluentcrm_Tag_Map::target()`, evaluated at "now":

| Club state (EVC_Entitlement) | Tag |
|---|---|
| active, verified payment, now < paid end | Active |
| active with an **early renewal** confirmed (the chain continues) | Active |
| active with a **pending renewal** order (unpaid) | Active (the paid period still runs) |
| active row, but now ≥ paid end (the end is exclusive) | Inactive |
| `expired` | Inactive |
| `cancelled` | Inactive |
| `refunded` (a recorded refund correction) | Inactive |
| `payment_failed` (only cancelled or failed orders, "voided") | Inactive |
| `indeterminate` with `payment_reversed` (a recorded void or reversal: the card is **frozen**) | Inactive |
| `pending_payment` (a Club order awaiting the bank transfer, no paid time) | Pending payment |
| `none` (never a Club member) | no write; counted as `not_member` |
| any other `indeterminate` state | no write; counted as `state_unknown` |
| an active row with an unverified payment or an unknown end | no write; counted as `state_unknown` |

Examples of the other indeterminate states:

- the add-on date conflict;
- a partial refund, whose policy is still open;
- an order cancelled or refunded before its correction was recorded;
- source errors.

Edge cases:

- **Today's add-on date conflict.** Until the owner approves aligning the PMPro dates with the
  recorded Club period (`docs/commerce-membership-source.md`, "Date conflict"), a confirmed member
  reads as `indeterminate` (`verified_paid_period_conflict`). The sync then leaves their tags
  untouched (`state_unknown`). It never guesses Active.
- **Cancelled or refunded before the correction is recorded** (for example, the Club DB was down).
  This reads as a source conflict, so there is no write. When the recovery step records the correction,
  the next event or the daily reconcile sets Inactive.
- **Partial refund.** No write until the owner's partial-refund policy exists.
- **A contact with other tags.** Only the three configured IDs are ever attached or detached. Every
  other tag stays.

## Matching a member to a contact

A member is matched only in two ways:

- by the FluentCRM contact linked to the WordPress user (`getContactByUserId`, read-only);
- by the WordPress user's e-mail, trimmed and case-insensitive (`getContact(email)`, read-only).

Members are never matched by name or phone.

The match is refused, and counted as `ambiguous_contact`, when:

- the link and the e-mail point at two different contacts;
- the e-mail's contact is linked to another WordPress user;
- the API answers with a contact that does not fit the request.

`getContactByUserRef` is never used, because it writes the user link when it matches by e-mail.

**No contact is ever created.** A member without a contact is counted as `no_contact`. The Tools page
shows that count.

## When it runs

- **Lifecycle events.** These events only *note* the customer:
  - WooCommerce order status `completed` (the BACS confirmation), `processing`, `on-hold`, `pending`,
    `cancelled`, `failed` or `refunded`;
  - `woocommerce_order_refunded`;
  - `pmpro_after_change_membership_level`, which includes PMPro's expiry and cancellation.

  The sync runs at `shutdown`, after the payment, membership or redemption work of the request is
  finished, for at most 20 customers per request. A failure there is swallowed and counted. It never
  blocks or rolls back a payment, membership or redemption.
- **Daily reconcile on WP-Cron** (`evc_club_fluentcrm_reconcile`). It is idempotent: a second pass
  writes nothing. It is protected by an atomic option lock (`add_option`); a stale lock older than 15
  minutes is taken over. It works in batches of 50 and handles at most 500 members per run, resuming
  from a saved cursor with a follow-up run 5 minutes later.

  The members are WordPress users with a PMPro row on the Club level, or with a WooCommerce order of
  the Club product in any status. Time-based expiry is caught here, because no event fires at the paid
  end.
- On deactivation, both cron events are cleared.

## Privacy

- The log holds class names only: no e-mail, name, user ID or tag title.
- The status option `evc_club_fluentcrm_status` and the Tools page «Evangelou Club — FluentCRM»
  (`manage_options`) show counts per outcome code only.
- The administrator notice shows a reason code only.

## FluentCRM API: what was and wasn't verified

**Verified** against the FluentCRM developer documentation (developers.fluentcrm.com):

- the "Contact API Function" page;
- the "Tag API Function" page;
- the Subscriber and Tag model pages;
- the "Contacts" action hooks page.

What those pages confirmed:

- `FluentCrmApi('contacts')` returns `FluentCrm\App\Api\Classes\Contacts`, with:
  - `getContact($idOrEmail)`, returning a Subscriber or false;
  - `getContactByUserId($userId)`, with no e-mail fallback;
  - `getContactByUserRef()`, which links `user_id` on an e-mail match, so it is **avoided**;
  - `createOrUpdate()`, which creates contacts and writes status, so it is **avoided**.
- `FluentCrmApi('tags')` proxies `find($id)` to the Tag model through `__call`. The Tag table has
  `id`, `title`, `slug` and `description`.
- Subscriber has `id`, `user_id`, `email` and `status`, plus:
  - `hasAnyTagId(array)`, returning bool;
  - `attachTags(array)` and `detachTags(array)`, which accept IDs, slugs or titles. **Only verified
    integer IDs are ever passed**, because `createOrUpdate` creates unknown titles and a title passed
    to `attachTags` may do the same.
- Tag hooks: `fluent_crm/contact_added_to_tags` and `fluent_crm/contact_removed_from_tags` (plus the
  legacy `fluentcrm_*` ones) fire **only for rows that actually changed**. Re-attaching a tag is
  silent. They are suppressed only by the global `FLUENTCRM_DISABLE_TAG_LIST_EVENTS` constant, which
  this plugin never defines.

**Not verified** (no FluentCRM source or install was reachable from the build environment):

- that the documentation pages describe exactly **3.2.5**, since none of them states a version;
- the version constant's name, `FLUENTCRM_PLUGIN_VERSION`. If it is named differently, the sync fails
  closed with `api_unavailable`;
- the runtime types: whether `id` and `user_id` come back as strings, which the gateway accepts;
- whether `getContact()` compares e-mails case-insensitively. The plugin lower-cases them, and
  FluentCRM's column collation normally does too.

**Before enabling it on a real site**, the technician checks on **staging** with a **test contact**:

1. The admin notice is gone.
2. The Tools page shows counts.
3. One reconcile moves the test contact's tag as expected and nothing else.

## Owner risk: automations

The plugin sends no e-mail and starts no automation itself. However, any FluentCRM automation or
funnel that **listens for "tag applied" or "tag removed"** on Active, Inactive or Pending payment
**will fire** when the sync changes those tags.

This includes the first reconcile after enabling, which may tag many contacts at once. Before
enabling, the owner and technician check FluentCRM → Automations for triggers on these three tags.

## Owner question: creating contacts (not built)

Creating FluentCRM contacts for members who are not contacts yet is a separate decision. Today they are
only counted (`no_contact`).

> **Ερώτηση προς τον ιδιοκτήτη:** Υπάρχουν μέλη του Club που δεν είναι επαφές στο FluentCRM (τα
> μετράμε, αλλά δεν τα αγγίζουμε). Θέλετε να δημιουργούνται αυτόματα ως νέες επαφές, ώστε να παίρνουν
> κι αυτά την ετικέτα Ενεργό / Ανενεργό / Αναμονή πληρωμής; Αν ναι: (α) με ποια κατάσταση εγγραφής —
> χωρίς συγκατάθεση για newsletter (μόνο για ενημερώσεις συνδρομής) ή «εκκρεμεί επιβεβαίωση» με e-mail
> διπλής επιβεβαίωσης; (β) με ποια στοιχεία — μόνο e-mail ή και όνομα; Μέχρι να απαντήσετε, δεν
> δημιουργείται καμία επαφή.

## Code

- `includes/class-evc-fluentcrm-tags.php` is the pure core: tag config, mapping, the gateway
  interface and the sync. It contains definitions only.
- `includes/fluentcrm/class-evc-fluentcrm-feature.php` holds the flag and the constants.
- `includes/fluentcrm/class-evc-fluentcrm-api-gateway.php` is the **only** file that may call
  FluentCRM.
- `includes/fluentcrm/class-evc-fluentcrm-plugin.php` handles boot, events, reconcile, the lock, the
  notice and the Tools page. It is the only construction site.

The packager (`app/scripts/php-guards.mjs`, STAGE-06 rules, with self-tested negative fixtures)
enforces the following:

- nothing outside the gateway names `FluentCrmApi`, the `FluentCrm\` namespace, `FLUENTCRM*`
  constants or `fluentcrm_*` / `fluent_crm/*` hooks;
- the gateway calls only the six documented methods and fires or hooks nothing;
- no shipped file names `createOrUpdate`, `getContactByUserRef`, lists, `updateStatus`, double
  opt-in, custom fields, `importBulk` or the event switch;
- the gateway and the sync are constructed only in `wire()`, behind the gate;
- no shipped file defines the flag or a tag constant.

## Tests

- **Unit** (`tests/unit/FluentcrmTagSyncTest.php`, fake gateway) covers:
  - config parsing;
  - the full mapping table and the exclusive paid end;
  - every verification failure, which means no write and no read;
  - the protected newsletter tag;
  - exactly one tag, idempotency and attach-before-detach;
  - matching by e-mail or link, ambiguity and no-create;
  - swallowed failures.
- **WordPress** suite `wp-fluentcrm` (`EVC_TEST_FLUENTCRM_FLAG=enabled`) runs the real shipped gateway
  over a TEST-ONLY fake `FluentCrmApi`. Any undocumented or never-touch method call is recorded and
  fails the test. It covers:
  - the flag;
  - the held-back states and the notice;
  - class-name-only logs;
  - end-of-request sync and the per-request cap;
  - reconcile batching, bound, resume, idempotency and the lock;
  - schedule and deactivation;
  - the counts-only Tools page;
  - the gateway's surface.

  The `wp-common` test `FluentcrmOffTest` proves that nothing exists while the flag is off.
- **Mutants:**
  - FT1–FT26 in `run-membership-mutations.php` (mapping, ID verification, no defaults, never-touch,
    no-create, matching);
  - FW1–FW16 in `run-wp-mutations.php`, in modes `fluentcrm` and `disabled` (flag, gate, construction
    guard, deferral, caps, lock, bound, logging, notice, gateway).
