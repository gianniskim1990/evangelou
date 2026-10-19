# Customer Club journey — LOCAL-ONLY interactive preview (CUSTOMER-01)

```
cd app
npm run customer:preview   # http://127.0.0.1:5173/customer-preview.html (dev server only)
```

## What this is, and what it is not

Three different things carry the word "Club" in this app. Do not confuse them:

| Route / page | What it really is | Status |
|---|---|---|
| `/club` (`src/club/`) | The original **mock staff lookup** demo: QR scan or phone search over mock members, then a demo redemption. It is **not** the customer membership app. | Public demo, unchanged |
| `/club-preview/customer/*` (`src/preview/`) | The **approved UI-02 design screens** for the customer (10 screens), with a sample-state switch. Static: every screen is reached from the preview's own menu. | Design preview, approved 2026-10-13, compiled out of production |
| `customer-preview.html` (`src/customerPreview/`) | **This page**: the same approved customer screens connected into one interactive journey over a simulated server. | LOCAL ONLY, dev server only |

Nothing here registers anyone, takes a payment, sends an e-mail, issues a QR
credential or records a coffee. There is no connection to WordPress,
WooCommerce, PMPro, FluentCRM or Amazon SES. All members are fictional; the QR
on the card is the approved non-encoded sample pattern stamped
"ΔΕΙΓΜΑ / ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ". Nothing is written to browser storage.

## Journeys you can test

Pick a scenario in the top tool bar ("Σενάριο"); "Οδηγίες" shows what to try
and what must hold; "Εργαλεία" holds what the **shop** does (never the
customer):

1. **New customer** — welcome → registration (name, Greek mobile, e-mail,
   validated with clear Greek messages) → two independent choices (Club terms
   required, newsletter optional) → the €20 offer → "Πληρωμή 20 €" leads to an
   honest step "Η πληρωμή δεν έγινε": nothing is charged, the membership waits
   for payment confirmation → the member dashboard (pending).
2. **Pending payment** — no coffee until the shop confirms the payment.
3. **Active member** — the app opens directly on the membership card.
4. **Today's coffee already recorded** — "Τον απολαύσατε σήμερα" until Athens
   midnight.
5. **Expiring tomorrow** — early renewal from the profile.
6. **Expired** — renewal after expiry.
7. **Cancelled** — never a coffee.

Simulation tools: move the clock (+1 hour, +1 day, one minute before Athens
midnight, one minute before the paid end, the exact paid end), confirm a
pending payment, record a coffee at the counter, cancel the membership, and
switch the open e-mail decision (D7). The panel also shows what the simulated
server knows (paid periods, pending payment, today's coffee) and its event
journal.

## Business rules the simulation follows

The simulated server (`customerServer.ts`) stands in for the future server
and is the only place that decides:

- **€20 for one calendar month**, manual renewal, no automatic billing.
- A membership is **active only while a confirmed paid period runs**. Pending
  payment, expired and cancelled memberships can never take a coffee.
- **One free coffee per Europe/Athens calendar day**; a new one at Athens
  midnight, not 24 hours later.
- Time rules mirror the PHP `EVC_Membership_Calendar` (owner decisions of
  2026-10-10): D1 the period ends at the exact Athens wall-clock time one
  calendar month later (exclusive end); D5 calendar month clamped to the last
  day; D2 early renewal starts at the current paid end, late renewal at the
  payment confirmation; a pending renewal grants nothing; DST times resolve
  conservatively.
- Only the **shop** confirms a payment (tools); the customer's own screens can
  only create an unpaid request.
- The newsletter consent is separate and never changes the membership.
- "Usual coffee" (D-USUAL) is derived from the member's own records, and
  shown only with at least 3 of them (a preview simplification; the server
  owns the real policy).

## How it reuses the approved design

The member screens are the approved components themselves: `MemberPass`,
`Dashboard`, `Today`, `History`, `Profile`, `MemberChrome`, `Onboarding`,
`Offer` and the `ds.tsx` primitives. They now render a `CustomerView`
(`src/preview/customerView.ts`); the design preview passes the approved
sample (`sampleCustomerView`), the journey passes one built from the
simulated server. Additive, backward-compatible changes only:

- `Field` gains an optional editable mode (label, required marker,
  `aria-invalid`, described error); without it, the approved read-only
  markup is unchanged.
- `StateMark` knows "cancelled" (used only by the journey).
- `Btn` accepts `type="submit"`; `Offer` accepts an optional pay action and
  secondary link (journey only).
- The approved onboarding copy moved to `src/preview/onboardingCopy.ts` and is
  shared, so the wording cannot drift.

Evidence that the approved screens did not change: all 26 design-preview
screens, every sample state (76 server renders, frozen clock), produce
byte-identical HTML before and after this change.

New screens exist only where the design preview had none: the real form, the
"payment is not connected" step and the sign-in placeholder ("the way members
sign in has not been decided").

## Tests

- `tests/club/customerJourneyModel.test.ts` — calendar rules (month end,
  leap year, chaining drift, DST repeated/skipped times, Athens midnight),
  server eligibility (pending, one per Athens day, exact expiry, renewals,
  cancelled, terms required, newsletter independence), registration rules,
  deterministic scenarios.
- `tests/club/customerJourney.test.ts` (jsdom) — the full new-customer
  journey with accessible validation and focus, the e-mail switch, sign-in
  placeholder, card-first opening for an active member and one-tap
  navigation, blocked states, renewal as a request only, the shop tools,
  midnight and exact expiry; no screen may claim a payment or registration
  succeeded; zero network, zero storage writes.
- `tests/club/customerPreviewIsolation.test.ts` — no shipped entry (ordering
  with the preview on or off, real staff app) reaches `src/customerPreview/`;
  the production ordering graph has no customer preview module; the journey
  graph reaches no service, transport, staff or ordering code and no network
  or storage API; no build config names the page; it refuses to run outside
  the dev server.
- Mutation guards K1–K13 (`scripts/run-club-mutations.mjs`).

## Open decisions (not simulated, not invented)

- D7: e-mail optional or required (switch in the tools; default required,
  matching the approved register copy).
- D8: how members sign in and receive their QR card.
- D6 and the real payment methods: who confirms cash and bank transfers, the
  checkout itself.
- D10: what happens to the remaining paid time on cancellation.
- D3/D4: refunds and coffees already given.
- Foreign mobile numbers (only Greek 69… accepted).
- D3 coffee catalogue and D-PRICING (coffees shown are illustrative).
- D9: history retention.
