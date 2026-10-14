# Usual coffee recommendation core (UI-03E)

**Status: pure PHP, definitions only, NOT wired.** The code lives in
`includes/class-evc-usual-coffee.php`.

- There is no SQL reader, REST route, hook or staff UI.
- Only test fakes implement `EVC_Redemption_History_Source` and
  `EVC_Member_Context_Verifier`.
- The packager rejects any shipped implementation and any
  `new EVC_Usual_Coffee_Service`.
- The staff flag stays off and redemption stays HTTP 503.
- The preview's synthetic `usualOrder()` is unchanged and stays illustrative;
  it never feeds this engine.

## Owner decision D-USUAL (applied)

The usual coffee is **derived** from the member's own committed redemption
snapshots.

- There is no stored or editable preference.
- No member record is touched.
- Nothing is written anywhere.

A suggestion is **not an order**: `is_confirmed_order()` is always false.

- It never redeems, changes an entitlement, creates a transaction or confirms
  a handoff.
- To use it, staff review it, may change it, and submit it through the normal
  redemption flow, which re-validates everything. The one-coffee-per-day rule
  still applies; this is tested.
- It is independent of the UI-03D.D recovery machine.

## Algorithm (deterministic)

1. **Authorise:** the staff actor must hold `evc_view_usual_coffee`, and a
   server-side `EVC_Member_Context_Verifier` must confirm that **this staff
   session** just looked up **this member** (QR scan or phone search). The
   shared staff account is never permission to enumerate members: a lookup of
   another member, or a lookup from another session, is `forbidden`. Nothing is
   read before both checks pass.
2. **Window:** read the member's committed redemptions whose Europe/Athens
   business date falls in the lookback window. Dates come from the server
   clock; date arithmetic uses calendar days, so DST has no effect.
3. **Keep only intact v2 records of this member.**
   - The ledger must carry this member's internal id AND public id.
   - The record must pass `EVC_Handoff_Service::record_problem`: exactly one
     verified snapshot, consistent hashes, and an intact handoff if any.
   - Dropped: v1 rows (no preparation), damaged or incomplete rows, foreign
     rows, rows outside the window, and every row of a request id that appears
     twice.
   - Failed or refused redemptions never create rows.
   - Free text cannot exist in a verified snapshot.
4. **Recency:** sort by business date, then `redeemed_at`, then request id,
   newest first. Keep the `max_considered` most recent, so a recent change of
   habit wins.
5. **Group by the complete observed preparation:** the coffee plus every
   modifier plus every quick note. Only codes count, and the catalogue version
   is excluded, so the same preparation under different catalogue versions is
   one habit. Modifiers from different redemptions are never combined.
6. **Pick the winner:**
   - the highest count wins;
   - a tie goes to the most recent occurrence;
   - if still tied, byte order of the preparation key decides.

   A suggestion needs at least `min_valid` considered redemptions, at least
   `min_occurrences`, and at least `min_share_percent` of those considered.
   Otherwise the result is `no_suggestion` (`insufficient_history` /
   `no_clear_usual`).
7. **Current catalogue compatibility:** the winner must validate **exactly**
   (`EVC_Prep_Validator`) against the current **server-authorised** catalogue:
   approved status plus a pinned owner digest, as for redemptions.

## Provisional parameters (NOT owner-approved)

| Parameter | Default | Meaning |
|---|---|---|
| `lookback_days` | 90 | Athens business days, including today |
| `max_considered` | 10 | most recent valid redemptions considered |
| `min_valid` | 3 | fewer valid redemptions: no suggestion |
| `min_occurrences` | 2 | a preparation seen once is never "usual" |
| `min_share_percent` | 50 | the winner's share of the considered redemptions |

`EVC_Usual_Coffee_Policy` validates the ranges; the defaults live in
`EVC_Usual_Coffee_Policy::provisional()`. Since UI-04B the policy is
**immutable**: private fields, read through getters (`lookback_days()`,
`max_considered()`, `min_valid()`, `min_occurrences()`, `min_share_percent()`),
and any property assignment throws.

## Catalogue compatibility rules

- Historical snapshots are immutable: codes come from history, and nothing is
  rewritten.
- **Retired coffee, removed option, a quick note no longer offered for that
  coffee, or a tightened rule** (for example a newly required group) gives
  `no_suggestion` / `not_offerable_with_current_catalogue`.
  - **Never substituted:** there is no fallback to the runner-up preparation,
    and no nearest-option matching.
- **Renamed labels and a new catalogue version:** the suggestion carries the
  CURRENT labels and catalogue version, so a submitted suggestion is
  validated against the current menu.
- **Unauthorised active catalogue:** gives `no_suggestion` /
  `catalogue_not_authorised`.

## Privacy safeguards

- The output holds exactly `catalogue_version`, `coffee_code`, `coffee_label`,
  `modifiers[{group, option, group_label, label}]`, `quick_notes[{id, label}]`
  and `basis{occurrences, considered}`.
- It never contains member ids, names, phone numbers, dates, request ids, staff
  or session data, or hashes.
- Refusals carry no data: `forbidden`, `invalid_request`, `member_not_found`
  and `server_error`.
- No logging of history: the error reporter receives only
  `{stage, exception class}`.
- The history source is read-only, and the service has no mutating operation.
- Member-linked preparation history is personal data; retention (D9) stays
  open.

## Future SQL reader (NOT implemented)

The future reader serves `member_redemptions(member_id, from, to)`.

- It runs on the redemption ledger joined to its snapshot and handoff rows,
  filtered by `member_id` and `business_date`, using the existing
  `ix_redemptions_member_time` index or a `(member_id, business_date)` index.
- It must return every linked snapshot and handoff row, so the integrity checks
  keep their meaning.

The future lookup context (`EVC_Member_Context_Verifier`) needs a short-lived,
server-side record of "session S looked up member M". This could be a signed
or stored lookup reference issued by the lookup endpoint. Its TTL is an owner
and technician decision.

## Open owner decisions

- Thresholds, ranking weights and the lookback limit (provisional above).
- Whether quick notes such as "takeaway" belong in a usual, or only the
  coffee and its modifiers.
- Whether to fall back to the runner-up when the usual is no longer offerable.
  Currently there is no fallback.
- The lookup-context TTL.
- Retention (D9).
- Whether members can opt out of suggestions.
