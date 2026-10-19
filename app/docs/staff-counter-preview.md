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

## Fast counter (owner decision, 2026-10-19)

The standard successful visit has exactly these steps:

1. Scan the QR or search by phone.
2. Eligibility is checked automatically; an eligible member goes **straight to
   the coffee configurator**, with name, masked phone and "Δικαιούται καφέ
   σήμερα" kept visible above it.
3. Choose the coffee and its structured preparation (approved configurator).
4. Tap **one** final button: **«Καταχώρηση καφέ»** (enabled only for a
   complete order; busy and ignored on a second tap while the request runs).
5. Exactly one redemption intent is sent (original request id, persistence
   before send — unchanged).
6. Only when the server authoritatively confirms a **fresh** redemption
   (machine phase `recorded_now`, i.e. a `redeemed` reply — never a replay,
   a status read or a refusal): a short notice **«Ο καφές καταχωρήθηκε»**
   (polite live region, no button, gone after 2.5 s) and the dashboard, ready
   for the next customer.

Removed from the normal path: the separate confirmation page, the «Ετοιμάζεται
ο καφές» acknowledgement, the handoff declaration buttons and the manual
«Ολοκλήρωση». A not-eligible member sees the reason and «Επόμενος πελάτης».

**Machine contract change (the only one):** `StaffRedemptionMachine.finish()`
now accepts a server-confirmed fresh commit (`recorded_now`) whether or not
the prepare prompt was acknowledged. It records nothing: no preparation, no
handoff statement, no request; it clears the settled tab context. The flow
never calls `acknowledgePrepare()` and never creates a handoff claim; a
redemption record is not evidence of physical delivery. `availableActions()`
is unchanged, so the standalone RecoveryScreen still offers its single prepare
prompt and every recovery scenario as before. Every other state (unknown
outcome, replay, refusal, persistence block, storage, integrity or
authorization failure) stays on the RecoveryScreen and can never be closed as
a success. A fresh commit produced by a recovery action (resend after an
authoritative "not recorded", a retry whose first attempt never committed, a
retry after a persistence block) is closed the same way. The handoff
statement remains available, optional, on the recovery screen (e.g. after a
replay with manual verification).

## What is real and what is fake

| Part | Source | Real or fake |
|---|---|---|
| Session states, fail-closed 401/403, lock, stale-reply discarding | `StaffController` | **real** code, fake session API |
| Redemption / handoff lifecycle, original ids, status before action, prepare once, persistence before send | `StaffRedemptionMachine` | **real** |
| Reply parsing and HTTP classification | `wire.ts` (`parseRedemptionReply`, `parseStatusReply`, `parseHandoffReply`, `parseLookupReply`, `classifyHttpReply`) | **real** |
| Per-tab recovery store (ids only) | `createSessionRecoveryStorage` | **real** adapter over an in-memory tab store |
| Redemption lifecycle screen | `RecoveryScreen` + `describeRecovery` (UI-04M/N, approved) | **real** |
| Dashboard, scan, phone, not-eligible member, coffee with the final button | UI-02 approved components (`StaffFrame`, `CoffeeConfigurator`, `OrderSummary`, `DefRows`, `StateMark`) | real components, new composition |
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
3. **Not eligible**: the reason with icon and text, and «Επόμενος πελάτης».
4. **Coffee** (approved configurator, structured choices only) with the
   customer's identity and eligibility above it and the single final button
   «Καταχώρηση καφέ»; it stays visible while the request runs.
5. **Success**: «Ο καφές καταχωρήθηκε» over the dashboard (fresh commit only).
6. **Redemption lifecycle / recovery**: the approved RecoveryScreen for every
   other machine state — unknown outcome, replay, refusal, persistence block,
   session loss, integrity, manager escalation, the optional handoff
   statement.

The tooling bar (outside the staff chrome) selects 16 scenarios with steps
and the guarantee each one shows, and offers: simulated page reload, lock,
session end, +1 minute (Athens midnight), a failing tab store, a scripted
fault for the next request, the fake server's request log (with request ids,
so reuse of the ORIGINAL id can be seen) and the tab store's content (ids only).

## Guarantees demonstrated (and tested)

`tests/staff/counterFlow.test.ts` and `tests/staff/counterPreview.test.ts` (jsdom),
plus the machine decision tests in `redemptionRecovery.test.ts`:

- fast counter: one tap, one request; a double tap sends nothing more; a
  partial order is never sent; only a fresh commit closes with the success
  notice; unknown outcome, refusal, integrity failure, 401/403 and a stale
  reply after a lock never close and never show it; a reload after the close
  starts clean (no replay, no status read, no second request); the close sends
  nothing and records no handoff;

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
