# Staff Recovery Screen — accessibility contract (UI-04M)

**Status:** pure contract only. `app/src/staff/redemptionRecovery/a11yContract.ts`
is not imported by the staff app (or the ordering app). There are no
buttons, no React screen, no DOM or focus calls and no requests. The staff
feature flag stays off and real redemption still returns HTTP 503.

The contract turns a `RecoveryView` (UI-04L, `describeRecovery()`) into
rendering intentions for the future recovery screen on the counter tablet.

> Unit tests prove the **contract**, not conformance. Real WCAG 2.2 AA
> results (contrast, visible focus, target spacing, screen-reader output in
> Greek) must be tested on the integrated screen with real devices and
> assistive technology. Nothing here claims WCAG compliance.

## Interface

```ts
screenContract(view: RecoveryView): RecoveryScreenContract
announcementFor(prev: RecoveryView | null, next: RecoveryView): Announcement | null
focusFor(prev: RecoveryView | null, next: RecoveryView, focusedAction: Action | null): FocusIntent
TOUCH_TARGET, TONE_ICON, UNCERTAIN_MARKER
```

`RecoveryScreenContract`:

| Field | Meaning |
|---|---|
| `region` | `<section aria-labelledby=…>` with a level-2 heading |
| `heading`, `body` | the view's own static Greek copy |
| `busy` / `inputLocked` | `aria-busy` while a request is in flight; no control exists then |
| `priority` | `none` (idle), `assertive` (authorization / integrity block), else `polite` |
| `icon` | one distinct icon per tone (`info`, `check`, `attention`, `warning`, `stop`) |
| `uncertainMarker` | "Δεν έχει επιβεβαιωθεί" on every settled uncertain outcome |
| `showPrepare` | exactly `view.prepareAllowed` (fresh commit only) |
| `actions[]` | exactly `view.actions` in the machine's order: label = accessible name, `variant` filled / bordered / text, `order`, `minTargetCssPx` 48 |
| `escalation` | hidden, secondary panel (recommended) or primary panel (required), headed "Ενημέρωση υπευθύνου", the view's own steps |

## Action safety

- Controls are **exactly** `view.actions` = `availableActions(state)`. Nothing
  is added, removed, reordered or relabelled, and there is no disabled
  "extra" control.
- At most one filled (primary) control. `manager_review` is always a quiet
  text control and always last.
- After a lock or session block, the only control is `reauthenticate`.
  Integrity blocks offer only `manager_review`.
- Uncertain outcomes never offer `start_redemption`, `acknowledge_prepare`
  or, when the redemption itself is unknown, a handoff statement.
- Prepare is shown only for a fresh commit's single prompt, never after a
  reload or replay.
- Nothing retries automatically. The contract never triggers an action.

## Focus intentions

| Situation | Intention |
|---|---|
| Opening the screen, or returning from manager escalation | heading (`tabindex=-1`), or the busy status while waiting |
| Any transition to a different case (pending → unknown outcome, persistence blocked, storage unavailable, session changed, expiry/revocation, confirmed redemption, confirmed handoff, integrity block) | heading, or status while waiting |
| Same case re-rendered or polled | keep (no focus stealing) |
| Same case, but the focused action is no longer offered | heading (never a hidden or disabled control) |
| Success arriving directly after an authorization block | heading, and nothing is announced (treated as stale) |

Focus is **never moved automatically to a button**, so a stray Enter or tap
cannot send a redemption, retry or statement. Keyboard order is the action
order. Nothing traps focus. Focus never triggers a request.

## Announcements

- `role="status"` + `aria-live="polite"` for ordinary updates, including every
  unknown transaction outcome ("Δεν έχει επιβεβαιωθεί. …", never success or
  failure wording).
- `role="alert"` (assertive) only for `blocked_authorization:*` and
  `blocked_integrity`.
- Idle is silent. A re-render of the same case announces nothing.
- A success right after a lock or revocation is never announced.
- Text = optional uncertain marker + headline + first body sentence. Static
  copy only: no ids, tokens, hashes, numbers or raw errors.

## Tablet requirements (for the integration task)

- Targets: at least 44×44 CSS px, and 48×48 for every recovery action
  (matches the existing `min-h-12` buttons). At least 12 px between actions.
- Visible keyboard focus on every control. No gesture-only action.
- Meaning is never carried by colour alone (icon + text marker). Uncertain
  states must look different from confirmed ones (they never use the
  positive tone or the check icon).
- While busy, input is locked so a second tap cannot duplicate a request.
- WCAG 2.2 AA acceptance at integration: 1.4.3/1.4.11 contrast, 2.4.7/2.4.11
  focus visible and not obscured, 2.5.8 target size, 4.1.3 status messages,
  1.3.1 headings and regions. Each needs a real test, not just a unit test.

## Build isolation note

Tailwind scans every source file for class-name candidates. A first draft
used the string value `"outline"`, which added an `.outline` utility to the
shipped staff CSS and changed its hash. The value is now `"bordered"`, and the
shipped bundle is again `staff-CrPA0l2H.js` / `staff-DtlY_JJO.css`. Future
pure modules must avoid bare Tailwind utility names as string values, or
check the build hash.

## Open owner decisions

- Copy observation (unchanged here): "Ο πελάτης έχει ήδη καφέ για σήμερα"
  (`benefit_already_redeemed`) may read as a physical handoff, whereas the
  server only confirms the redemption. Proposed for owner wording review;
  no redemption semantics change.
- Whether the uncertain marker text "Δεν έχει επιβεβαιωθεί" is the final
  wording, and the icon set and tone→colour tokens at integration.
- Whether recovery actions should be 48 px everywhere, or larger (e.g.
  `min-h-16` like the preview) for the counter.
