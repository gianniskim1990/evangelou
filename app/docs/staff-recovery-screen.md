# Staff Recovery Screen — standalone React component (UI-04N)

**Status:** standalone component and a local-only preview. It is **not**
imported by the real `StaffApp`, the ordering app or any production entry,
it makes no request, and the staff feature flag stays off (real redemption
still returns HTTP 503).

## What it is

`app/src/staff/recoveryScreen/RecoveryScreen.tsx` renders one `RecoveryView`
(UI-04L `describeRecovery()`) through its accessibility contract (UI-04M
`screenContract()` / `announcementFor()` / `focusFor()`):

```tsx
<RecoveryScreen view={describeRecovery(machineState)} onAction={(action) => /* host decides */} />
```

| Part | Rendering |
|---|---|
| Region | `<section aria-labelledby>` with an `<h2>` headline (`tabindex="-1"` so it can receive focus) |
| Tone | one distinct icon per tone (info, check, attention, warning, stop), `aria-hidden` |
| Uncertainty | visible dashed marker "Δεν έχει επιβεβαιωθεί" on settled uncertain outcomes |
| Body | the view's own Greek sentences; for a fresh confirmed redemption the single prepare prompt gets a bordered callout |
| Busy | `aria-busy`, a spinner (`aria-hidden`), the wait instruction, **no buttons**; the block is the focus target |
| Actions | `<button type="button">`, exactly `contract.actions`: same order, same labels, `data-action` = machine action name; filled / bordered / text variants |
| Manager | `<aside>` with an `<h3>` "Ενημέρωση υπευθύνου" and the view's static steps; primary panel when review is required, secondary when recommended |
| Live regions | two persistent, visually removed regions: `role="status"` polite and `role="alert"` assertive |

## Guarantees

- **Actions:** only what the view offers. A click calls the injected
  `onAction(name)` once and does nothing else: no request, no retry, no
  timer, no storage. Nothing is disabled, hidden or faked.
- **Focus:** opening the screen focuses the heading (or the busy block). A
  change to a different case moves focus to the heading or busy block, never
  to a button. A re-render of the same case keeps focus; if the focused
  action is no longer offered, focus falls back to the heading. A success
  straight after a lock goes to the heading and is not announced.
- **Announcements:** polite status for ordinary updates (including every
  unknown outcome, starting "Δεν έχει επιβεβαιωθεί."), assertive alert only
  for authorization and integrity blocks, silence for idle and for a repeat
  of the same case.
- **Prepare:** shown only for `recorded_now:prepare`; never after
  acknowledgement, reload or replay.
- **Privacy:** only the static Greek copy is rendered; no ids, hashes, tokens
  or raw errors appear in the text, markup or announcements.
- **Touch targets:** the section carries `--evc-rs-target: 48px` and
  `--evc-rs-gap: 12px`; the stylesheet gives every control `min-height` /
  `min-width` of that size and the actions that gap.

## Styling and build isolation

The component uses **plain CSS** (`recoveryScreen.css`), every selector
prefixed `evc-rs`, with the app's existing theme variables (`--color-*`, which
flip in dark mode) and the approved design-system accent tones. It adds no
Tailwind utility, because Tailwind scans every file under `app/` and a new
utility in a not-yet-shipped file would change the shipped stylesheet (see
`staff-recovery-a11y-contract.md`). `npm run check:isolated-build -- --base
<ref>` rebuilds the staff and ordering apps with and without this change's
non-shipped files and requires byte-identical output. The unwired component,
its preview, its tests and `recovery-preview.html` are all classed as
non-shipped by that check.

## Local preview

```bash
cd app
npm run recovery:preview
```

This starts the Vite **dev server** on `127.0.0.1` and opens
`/recovery-preview.html`. Pick a scenario at the top (no pending request,
waiting, request not sent, server outcome unknown, already recorded, fresh
confirmed redemption, storage unavailable, session expired, manager review
required, handoff statement recorded); switching scenarios exercises the real
focus and announcement logic, "Dark mode" flips the theme, and the log below
the screen shows which action name a button would send ("nothing is sent").

The preview uses only the fake states in `previewScenarios.ts` (synthetic
ids), never connects to members, WordPress or payments, is not an input of any
production build, and refuses to render outside the dev server
(`import.meta.env.DEV`). There is no public preview route.

## Checked in a real browser (headless Edge, local preview)

All ten preview scenarios were opened in headless Edge at 1024 px (light and
dark) and 390 px (30 screens): every action button measured 52 px high (the
contract asks for at least 48), there was no horizontal scrolling, focus landed
on the heading (or the busy block while waiting), and the live regions held the
expected text. This is a developer sanity check of the layout, not an
accessibility audit.

## What is and is not proven

Unit/DOM tests (jsdom) prove structure, the exact action set and order, click
wiring, focus, live-region updates and the absence of network access. They do
**not** prove WCAG 2.2 AA conformance: contrast, rendered focus indicators,
real target sizes and spacing on the counter tablet, and screen-reader output
in Greek must be tested on the integrated screen with real devices and
assistive technology.

## Open owner decisions

- Wording: "Ο πελάτης έχει ήδη καφέ για σήμερα" (`benefit_already_redeemed`)
  may read as a physical handoff although the server only confirms the
  redemption; final wording of the uncertain marker; the "Ενέργειες" group
  label.
- Visual: icons, accent colours, whether counter buttons should be larger
  than 48 px.
