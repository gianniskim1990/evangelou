# UI-04O — integrated staff counter-flow preview (LOCAL ONLY)

An interactive walk-through of the complete staff counter workflow, built
from the existing application code and run against a deterministic fake
server. It is a review tool, **not** an integration: the real StaffApp at
`/club-admin/`, the WordPress plugin, real authentication, real redemption
(HTTP 503) and every payment path stay exactly as they were.

```
cd app
npm run counter:preview      # opens http://127.0.0.1:5173/counter-preview.html
```

The page refuses to run outside the Vite dev server (`import.meta.env.DEV`),
is the input of no production build (neither `vite.config.ts` nor
`vite.staff.config.ts` names it) and is unreachable from both shipped entry
points (`tests/staff/counterPreviewIsolation.test.ts` inspects the real
esbuild module graphs). Fake members only; no request leaves the page (the DOM
tests probe `fetch`, XHR, `sendBeacon` and WebSocket). The page loads the
approved design preview's web font, like `/club-preview`.

## What is real and what is fake

| Part | Source | Real or fake |
|---|---|---|
| Session states, fail-closed 401/403, lock, stale-reply discarding | `StaffController` | **real** code, fake session API |
| Redemption / handoff lifecycle, original ids, status before action, prepare once, persistence before send | `StaffRedemptionMachine` | **real** |
| Reply parsing and HTTP classification | `wire.ts` (`parseRedemptionReply`, `parseStatusReply`, `parseHandoffReply`, `parseLookupReply`, `classifyHttpReply`) | **real** |
| Per-tab recovery store (ids only) | `createSessionRecoveryStorage` | **real** adapter over an in-memory tab store |
| Redemption lifecycle screen | `RecoveryScreen` + `describeRecovery` (UI-04M/N, approved) | **real** |
| Dashboard, scan, phone, member, coffee, confirmation | UI-02 approved components (`StaffFrame`, `CoffeeConfigurator`, `OrderSummary`, `DefRows`, `StateMark`) | real components, new composition |
| Order → contract payload | `orderPayload.ts` (the UI-03C parity mapping) checked by the real `validatePreparation` | real contract |
| Server | `fakeServer.ts`: one coffee per member per Athens business day, request-id replay, lookup proof, handoff idempotency, v2 bodies with contract statuses | **fake** |
| Members | the approved UI-02 synthetic members, with fake ids, QR tokens and phones | **fake** |
| Member summary (name, membership, today's benefit) | v1 domain types (`ClubMember`, `MemberBenefitStatus`) | **fake, NO v2 route** (see below) |

The orchestration (`counterFlow.ts`) owns no business rule: it maps each
recovery-screen action name to exactly one machine method and adds navigation
guards only (no new member or order while an intent is open; member data in
memory only, dropped on session loss, reload and re-login).

## Interactive screens

1. **Dashboard**: scan, phone lookup, session status, lock, today's
   server-confirmed redemptions ("καταχώρηση", never "δόθηκε").
2. **Scan** (simulated cards) and **phone keypad** (fake numbers; Greek
   mobiles only, +30 / 0030 accepted).
3. **Member**: eligibility verdict with icon and text; coffee selection only
   when the summary says the benefit is available today.
4. **Coffee** (approved configurator, structured choices only) and
   **confirmation** (full order, Athens day).
5. **Redemption lifecycle**: the approved RecoveryScreen for every machine
   state. The order to prepare appears beside it ONLY while a fresh commit's
   single "prepare" prompt is due.
6. **Recovery, handoff statement, finish**, session expiry, permission loss,
   lock and manager escalation through the same screen.

The tooling bar (outside the staff chrome) selects 16 scenarios with steps
and the guarantee each one shows, and offers: simulated page reload, lock,
session end, +1 minute (Athens midnight), a failing tab store, a scripted
fault for the next request, the fake server's request log (with request ids,
so reuse of the ORIGINAL id can be seen) and the tab store's content (ids only).

## Guarantees demonstrated (and tested)

`tests/staff/counterFlow.test.ts` (30) and `tests/staff/counterPreview.test.ts`
(11, jsdom):

- one entitlement per member per Athens business day (also across midnight:
  yesterday's lost commit replays as an earlier date; today needs a NEW intent);
- original request ids reused on retry, resend after "not recorded", handoff
  retry and recovery after reload or re-login;
- never a second redemption automatically; while an intent is open the counter
  cannot start another one;
- never "prepare" after a replay, a status read or an unknown outcome; the
  order to prepare is shown once, for a fresh commit only;
- a redemption confirmation is not proof of handoff; a handoff statement is a
  staff statement; an earlier handoff never gets an invented time;
- unknown outcomes stay explicitly uncertain; an unreadable status stays
  unresolved with the same ids;
- authorization failures fail closed: 401 → expired, 403 → forbidden, lock →
  server session ended; member data leaves memory and the DOM; late replies
  are discarded; after a re-login only a status read is possible;
- no request without a durable recovery context (failing tab store);
- after a reload the order is not in memory: the same intent cannot be
  resent, nothing is sent, the request can only be closed.

## Findings for the real WordPress integration (not solved here)

1. **No v2 member summary.** The v2 lookup returns identity + proof only (by
   design). The counter needs name, masked phone, membership state and today's
   benefit before coffee selection; the preview uses the v1 domain types as a
   stand-in. A v2 summary route (shape, capability, proof, privacy) needs a
   design decision.
2. **Lookup-proof expiry looks like permission loss.** A missing or expired
   proof on `redeem` is a 403 `forbidden` envelope, which the controller must
   treat as authorization loss (session ends). The real integration needs a
   distinguishable outcome (or a proof TTL longer than any counter visit).
3. **Domain `forbidden` on status / handoff** blocks the machine while the
   session controller stays ready; the integration must decide how the app
   reacts.
4. **Capabilities still pending**: `evc_confirm_handoff`,
   `evc_view_redemption_status`, `evc_view_usual_coffee` (UI-04I plan only).
5. Real transport (`staffFetch` + `classifyHttpReply`), REST v2 routes,
   SQL / migrations, approved catalogue (D3), pricing (D-PRICING), proof TTL,
   rate limits, mandatory handoff, manager workflow and real device /
   screen-reader testing remain open.

## Files

`src/staff/counterPreview/` (`counterFlow.ts`, `fakeServer.ts`,
`fakeDirectory.ts`, `previewTransport.ts`, `previewSession.ts`,
`orderPayload.ts`, `previewCatalogue.ts`, `scenarios.ts`, `CounterApp.tsx`,
`CounterPreview.tsx`, `counterPreview.css`, `main.tsx`) and
`app/counter-preview.html`. Styling of the tooling is plain CSS with the
`evc-cf` prefix; the staff screens reuse class names that already exist, so
the shipped stylesheets stay byte-identical (checked by
`npm run check:isolated-build`).
