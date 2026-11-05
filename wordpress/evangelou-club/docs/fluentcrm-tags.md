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
| PHP's mbstring is installed (Greek names are compared lower-cased; intl's `Normalizer`, when present, also folds them to Unicode NFC) | `mbstring_missing` |
| the six constants exist; IDs are ints ≥ 1; names are non-empty strings; all distinct | `tags_not_configured`, `tag_id_invalid`, `tag_name_invalid`, `tags_not_distinct` |
| no configured ID is **1** (`PROTECTED_IDS`: the «Ευαγγέλου Club» tag, refused by ID whatever it is called now), checked in the configuration and again before any write | `protected_tag_id` |
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
| active row past its paid end **with an unpaid renewal order** (`renewal_pending`) | Pending payment |
| `expired` | Inactive |
| `expired` **with an unpaid renewal order** (`renewal_pending`, before PMPro's expiry cron has run) | Pending payment (round 2: the same answer as after the cron, which makes it `pending_payment`) |
| `cancelled` | Inactive |
| `refunded` (a recorded refund correction) | Inactive |
| `payment_failed` (only cancelled or failed orders, "voided") | Inactive |
| `indeterminate` with `payment_reversed` (a recorded void or reversal: the card is **frozen**) | Inactive |
| `pending_payment` (a Club order awaiting the bank transfer, no paid time) | Pending payment |
| legacy: an active PMPro row with **no** recorded Club payment and a Club order awaiting the transfer (`pending_payment`, `no_payment_evidence` + `renewal_pending`) | Pending payment |
| `none` **with `club_order_voided`** (positive evidence: no PMPro row at all, and every payment fact is a Club order that failed or was cancelled unpaid) and the contact carries **Active or Pending payment** | **Inactive** (rounds 2–3): a first bank-transfer order put on hold (Pending) and then cancelled or voided unpaid must not leave Pending behind forever |
| `none` with `club_order_voided`, contact with only Inactive, none of the three, or no contact | no write; counted as `not_member` |
| any other `none`: no Club history (e.g. someone tagged by hand who buys ordinary coffee), a membership of another level, no order at all | **no write**; counted as `not_member` (round 3: the spec's "non-member → no write") |
| any other `indeterminate` state | no write; counted as `state_unknown` |
| an active row with an unverified payment or an unknown end | no write; counted as `state_unknown` |

Examples of the other indeterminate states:

- a PMPro membership the Club has no recorded payment for (for example the add-on issued it on its
  own while the WooCommerce-completion path was off), or PMPro dates that disagree with the recorded
  period;
- a partial refund, whose policy is still open;
- an order cancelled or refunded before its correction was recorded;
- source errors.

Edge cases:

- **Confirmed payments are Active (correction, round 2).** The production confirmation path is the
  STAGE-03D WooCommerce completion (`EVC_CLUB_WC_COMPLETION_ENABLED`): it records the payment and then
  issues PMPro's dates from the recorded period itself, so a member confirmed that way reads as
  active, and the sync tags Active. This is proven on the real stack (`wp-commerce-fluentcrm`). The
  date conflict ("Date conflict" in `docs/commerce-membership-source.md`) only arises when the add-on
  issues the membership by itself, without the Club's record: then the member is `indeterminate` and
  the sync writes nothing (`state_unknown`), never a guessed Active (also proven on the real stack).
  Round 1 wrongly said that every confirmed member was in the conflict.
- **First order cancelled unpaid.** On hold → Pending payment; cancelled or failed → Inactive (the
  rule above), only on the mapper's `club_order_voided` evidence. A contact that never had one of the
  three tags is not given Inactive.
- **A wrong Club level setting fails closed.** The commerce reader reports the mismatch with the
  add-on's product → level map and the level itself as a source conflict, so every member reads
  `indeterminate` and nothing is written (proven on the real stack). The voided evidence also requires
  that the user has no PMPro row of any level.
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

Residual risk: FluentCRM documents no way, short of its raw model query builder (deliberately unused),
to ask whether **two** contacts carry the same `user_id`; `getContactByUserId()` returns one of them.
If the e-mail lookup finds a different contact, the match is refused; if a second linked contact has
another e-mail, it is not seen.

`getContactByUserRef` is never used, because it writes the user link when it matches by e-mail.

**No contact is ever created** (owner decision D11, below). A member without a contact is counted as
`no_contact`. The Tools page shows that count.

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
  writes nothing. It is protected by a lock row in `wp_options` that holds `<until>|<random token>`
  (round 2):
  - taken with `INSERT IGNORE` on the unique option name, and held only when exactly one row was
    inserted (`rows_affected === 1`);
  - a stale lock (until ≤ now: a crashed run, after 15 minutes) is taken over with a conditional
    `UPDATE … WHERE option_value = <the stale value read>`, so of two runs racing for it only one wins;
  - released with `DELETE … WHERE option_value = <our own value>`, never someone else's lock.

  - (round 3) after every batch the run moves the lock's end forward with the same compare-and-swap
    (a new value each time), so a run longer than 15 minutes is never taken over; if the swap fails the
    lock is no longer ours and the run stops cleanly (cursor saved, nothing released or scheduled).

  It works in batches of 50 and handles at most 500 members per run, resuming from a saved cursor with
  a follow-up run 5 minutes later. If the member query fails, the counts so far are kept and a
  follow-up run is scheduled — at most 3 in a row, after 5, 10 and 20 minutes (round 3); then it waits
  for the next daily run, which starts with a fresh budget. A run that completes clears the budget.

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

## Go-live checklist (owner + technician; nothing here is done by this stage)

1. Confirm the three tag IDs and names **on the production site** (2/3/4 are staging-only and
   unverified) and set the six constants. ID 1 is refused.
2. **Review FluentCRM → Automations (and funnels) for any trigger on tags 2, 3 or 4** ("tag applied" /
   "tag removed"): they will fire when the sync changes these tags, including on the first reconcile,
   which may change many contacts at once. Pause or adjust them first.
3. On **staging** with a **test contact**: the admin notice is gone, the Tools page shows counts, and
   one reconcile moves the test contact's tag as expected and nothing else.
4. Check that `FLUENTCRM_PLUGIN_VERSION` is defined and ≥ 3.2.5 (otherwise the notice says
   `api_unavailable`) and that PHP has mbstring.
5. Only then set `EVC_CLUB_FLUENTCRM_SYNC_ENABLED` to `true` (owner approval required).

## Owner risk: automations

The plugin sends no e-mail and starts no automation itself. However, any FluentCRM automation or
funnel that **listens for "tag applied" or "tag removed"** on Active, Inactive or Pending payment
**will fire** when the sync changes those tags.

This includes the first reconcile after enabling, which may tag many contacts at once. Before
enabling, the owner and technician check FluentCRM → Automations for triggers on these three tags.

## Owner decision D11: contacts are never created

**Decided by the owner (STAGE-06 round 2):** Club members who are not FluentCRM contacts are **not**
created as contacts, now or later. They keep being counted as `no_contact` (Tools page, counts only).
The plugin has no code path that creates a contact, and the packager bans `createOrUpdate` and the
user-link writer in every shipped file.

> **Απόφαση ιδιοκτήτη (D11):** Τα μέλη του Club που δεν είναι επαφές στο FluentCRM **δεν**
> δημιουργούνται ως επαφές, ούτε τώρα ούτε αργότερα. Απλώς μετριούνται («no_contact»).

## Code

- `includes/class-evc-fluentcrm-tags.php` is the pure core: tag config, mapping, the gateway
  interface and the sync. It contains definitions only.
- `includes/fluentcrm/class-evc-fluentcrm-feature.php` holds the flag and the constants.
- `includes/fluentcrm/class-evc-fluentcrm-api-gateway.php` is the **only** file that may call
  FluentCRM.
- `includes/fluentcrm/class-evc-fluentcrm-plugin.php` handles boot, events, reconcile, the lock, the
  notice and the Tools page. It is the only construction site.

The packager (`app/scripts/php-guards.mjs`, STAGE-06 rules, with self-tested negative fixtures)
enforces the following (round 2 additions marked +):

- nothing outside the gateway names `FluentCrmApi`, the `FluentCrm\` namespace, `FLUENTCRM*`
  constants or `fluentcrm_*` / `fluent_crm/*` hooks;
- the gateway calls only the six documented methods and fires or hooks nothing;
- no shipped file names `createOrUpdate`, `getContactByUserRef`, lists, `updateStatus`, double
  opt-in, custom fields, `importBulk` or the event switch;
- the gateway and the sync are constructed only in `wire()`, behind the gate;
- no shipped file defines the flag or a tag constant;
- \+ no shipped file names a FluentCRM table (`fc_subscriber*`, `fc_tag*`, `fc_list*`) inside a string;
- \+ in the gateway: static calls only to its own helpers and the feature guard; `FluentCrmApi(` only
  with the literal `'contacts'` or `'tags'`; no `call_user_func*` / `forward_static_call*`; no property
  write (plain, compound, `??=`, `++`/`--`, array or dynamic) except on `$this`'s own properties — so
  also none chained off `$this->x(...)->prop` or `$this->a->b`; comparisons (`<=`, `>=`, `<=>`) are not
  writes;
- \+ (round 3) in the gateway: no variable member (`->$x`), no call of an expression result (`](` or
  `)(`, casts excepted), no variable function call (`$f(`), and `new` only of its own exceptions
  (`RuntimeException`, `InvalidArgumentException`).

Limitation: a token guard cannot follow names assembled at run time from string pieces (for example
`'fc_' . 'tags'`). The gateway's small reviewed surface and the test-only fake FluentCRM, which fails on
any undocumented call, cover that gap.

## Tests

- **Unit** (`tests/unit/FluentcrmTagSyncTest.php`, fake gateway) covers:
  - config parsing;
  - the full mapping table and the exclusive paid end;
  - every verification failure, which means no write and no read;
  - the protected newsletter tag (by ID and by name), mbstring, Unicode NFC;
  - a non-member's left-over Active / Pending becoming Inactive;
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
- **Real stack** (round 2): suite `wp-commerce-fluentcrm` in the CI commerce job (HPOS and posts
  storage, PHP 7.4 / 8.2 / 8.4) uses the real WooCommerce + PMPro + add-on + Club DB + reader + mapper,
  the production wiring of the plugin and the DEFAULT member query; only FluentCRM is the fake. It
  covers: on-hold → Pending; cancelled unpaid → Inactive (not Pending); confirmed by shop staff →
  Active; full refund → Inactive; completed without the Club's confirmation → no write
  (`state_unknown`); the reconcile finding an order-only customer and a PMPro member, and its second
  pass writing nothing; (round 3) an outsider tagged by hand buying a non-Club product → untouched; a
  wrong Club level setting → no writes.
- **Mutants:**
  - FT1–FT40 in `run-membership-mutations.php` (mapping incl. the renewal cases, the voided evidence and
    retiring only on it, ID verification incl. tag 1 by ID, mbstring, no defaults, never-touch,
    no-create, matching);
  - FW1–FW28 in `run-wp-mutations.php`, in modes `fluentcrm` and `disabled` (flag, gate, construction
    guard, deferral, caps, the atomic token lock and its per-batch refresh, bound, bounded retries with
    backoff, logging, notice, gateway);
  - FC1–FC3 in mode `commerce_fluentcrm` (`--mode=commerce`, the CI commerce job): the default member
    query, the order-status events, and retiring only on the voided evidence, on the real stack.
