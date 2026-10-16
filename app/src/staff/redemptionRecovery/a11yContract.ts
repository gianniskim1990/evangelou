import type { Action } from "./machine";
import { ACTION_LABELS, type RecoveryView } from "./viewModel";

/**
 * UI-04M — accessible presentation CONTRACT for the future Staff Recovery
 * Screen. Pure data and pure functions: no DOM, no React, no focus calls, no
 * requests. NOT imported by the staff app (isolation test).
 *
 * It describes HOW a RecoveryView should be rendered for a touch tablet at
 * the counter, keyboard users and screen readers:
 *  - one labelled region with a level-2 heading (the view's headline);
 *  - interactive controls are EXACTLY view.actions, in the machine's order
 *    (no action, permission or authority is added here);
 *  - announcements: polite role="status" for ordinary updates, assertive
 *    role="alert" only for an authorization or integrity block; never
 *    repeated without a meaningful transition (a different case key);
 *  - focus: on a meaningful transition focus moves to the heading (or the
 *    busy status while waiting) — NEVER automatically to a button, so a
 *    stray Enter can never send a redemption, retry or statement; on a
 *    re-render of the same case focus is kept if the focused action still
 *    exists, otherwise it falls back to the heading;
 *  - meaning is never carried by colour alone: every tone has an icon and
 *    uncertain outcomes carry a text marker.
 *
 * Unit tests prove only the contract. Real WCAG 2.2 AA conformance
 * (contrast, visible focus, screen-reader output, target spacing) must be
 * tested on the integrated screen with real devices and assistive tech.
 */

export const TOUCH_TARGET = Object.freeze({
  /** WCAG 2.2 SC 2.5.8 minimum is 24px; the counter tablet contract asks for 44px at least … */
  minimumCssPx: 44,
  /** … and 48px for every recovery action (matches existing min-h-12 buttons). */
  preferredCssPx: 48,
  /** Space between adjacent actions so a wet or hurried tap hits only one. */
  minimumGapCssPx: 12,
});

/** Icon per tone, so a tone is never conveyed by colour alone. */
export const TONE_ICON = Object.freeze({
  neutral: "info",
  positive: "check",
  attention: "attention",
  warning: "warning",
  blocked: "stop",
} as const);

/** Text marker shown (and read) with every uncertain outcome; reuses UI-04L wording. */
export const UNCERTAIN_MARKER = "Δεν έχει επιβεβαιωθεί";

export type AnnouncementPriority = "none" | "polite" | "assertive";

export interface Announcement {
  readonly priority: Exclude<AnnouncementPriority, "none">;
  /** role="alert" (assertive) or role="status" + aria-live="polite". */
  readonly role: "alert" | "status";
  readonly ariaLive: "assertive" | "polite";
  /** Static copy only (headline + first body sentence group); never ids, tokens or errors. */
  readonly text: string;
}

export type FocusIntent =
  /** Leave focus where it is (same case re-rendered, focused control still present). */
  | { readonly kind: "keep" }
  /** Move focus to the region heading (tabindex=-1); never activates anything. */
  | { readonly kind: "heading" }
  /** Move focus to the busy status (waiting states have no actions). */
  | { readonly kind: "status" };

export type ButtonVariant = "filled" | "bordered" | "text";

export interface ActionControl {
  readonly action: Action;
  /** Visible label AND accessible name (Greek, from ACTION_LABELS). */
  readonly label: string;
  readonly variant: ButtonVariant;
  /** 0-based keyboard / reading order (= the machine's order). */
  readonly order: number;
  readonly minTargetCssPx: number;
}

export interface RecoveryScreenContract {
  readonly caseKey: string;
  readonly region: { readonly element: "section"; readonly labelledBy: "headline"; readonly headingLevel: 2 };
  readonly heading: string;
  readonly body: readonly string[];
  /** aria-busy on the region while a request is in flight; input is locked (no actions exist). */
  readonly busy: boolean;
  readonly inputLocked: boolean;
  /** Priority this case gets when it is ENTERED (see announcementFor for de-duplication). */
  readonly priority: AnnouncementPriority;
  readonly icon: (typeof TONE_ICON)[keyof typeof TONE_ICON];
  /** Present iff a settled outcome is uncertain (waiting states use the busy status instead). */
  readonly uncertainMarker: string | null;
  /** True only for a fresh commit's single prepare prompt (exactly view.prepareAllowed). */
  readonly showPrepare: boolean;
  readonly actions: readonly ActionControl[];
  readonly escalation: {
    readonly presentation: "hidden" | "panel_secondary" | "panel_primary";
    /** Reuses the manager_review label; steps are the view's own static steps. */
    readonly heading: string | null;
    readonly steps: readonly string[];
  };
}

/** Case families that justify an assertive alert: the screen can no longer act safely. */
function isUrgentBlock(caseKey: string): boolean {
  return caseKey.startsWith("blocked_authorization:") || caseKey === "blocked_integrity";
}

function priorityOf(view: RecoveryView): AnnouncementPriority {
  if (view.caseKey === "idle") return "none";
  return isUrgentBlock(view.caseKey) ? "assertive" : "polite";
}

function variantOf(emphasis: RecoveryView["actions"][number]["emphasis"]): ButtonVariant {
  return emphasis === "primary" ? "filled" : emphasis === "secondary" ? "bordered" : "text";
}

/** Rendering contract for one view. Pure; actions are exactly view.actions. */
export function screenContract(view: RecoveryView): RecoveryScreenContract {
  const actions = view.actions.map((a, order) =>
    Object.freeze({ action: a.action, label: a.label, variant: variantOf(a.emphasis), order, minTargetCssPx: TOUCH_TARGET.preferredCssPx }),
  );
  const presentation = view.escalation.level === "none" ? "hidden" : view.escalation.level === "required" ? "panel_primary" : "panel_secondary";
  return Object.freeze({
    caseKey: view.caseKey,
    region: Object.freeze({ element: "section", labelledBy: "headline", headingLevel: 2 }),
    heading: view.headline,
    body: view.body,
    busy: view.waiting,
    inputLocked: view.waiting,
    priority: priorityOf(view),
    icon: TONE_ICON[view.tone],
    uncertainMarker: view.certainty === "uncertain" && !view.waiting ? UNCERTAIN_MARKER : null,
    showPrepare: view.prepareAllowed,
    actions: Object.freeze(actions),
    escalation: Object.freeze({
      presentation,
      heading: presentation === "hidden" ? null : ACTION_LABELS.manager_review,
      steps: view.escalation.steps,
    }),
  });
}

/**
 * A success that arrives straight after an authorization block cannot come
 * from a legitimate flow (re-authentication goes through recovery and a
 * status check): treat it as stale — announce nothing, move focus to the
 * heading so staff read the screen rather than hear "success".
 */
function suspectAfterBlock(prev: RecoveryView | null, next: RecoveryView): boolean {
  return prev !== null && prev.caseKey.startsWith("blocked_authorization:") && next.tone === "positive";
}

/**
 * What to announce when the screen moves from `prev` to `next`. Null when
 * nothing should be said: idle, a re-render of the same case (polling,
 * re-renders), or a suspect success after a block.
 */
export function announcementFor(prev: RecoveryView | null, next: RecoveryView): Announcement | null {
  const priority = priorityOf(next);
  if (priority === "none") return null;
  if (prev !== null && prev.caseKey === next.caseKey) return null;
  if (suspectAfterBlock(prev, next)) return null;
  const text = [next.certainty === "uncertain" && !next.waiting ? UNCERTAIN_MARKER + "." : null, next.headline + ".", next.body[0]]
    .filter((p): p is string => p !== null)
    .join(" ")
    .replace(/…\./g, "…");
  return Object.freeze(
    priority === "assertive"
      ? { priority, role: "alert", ariaLive: "assertive", text }
      : { priority, role: "status", ariaLive: "polite", text },
  );
}

/**
 * Where focus should go when `next` is rendered after `prev`, given which
 * action control (if any) currently has focus. Never targets a button.
 */
export function focusFor(prev: RecoveryView | null, next: RecoveryView, focusedAction: Action | null): FocusIntent {
  const sameCase = prev !== null && prev.caseKey === next.caseKey;
  if (sameCase && !suspectAfterBlock(prev, next)) {
    if (focusedAction === null) return { kind: "keep" };
    return next.actions.some((a) => a.action === focusedAction) ? { kind: "keep" } : { kind: "heading" };
  }
  return next.waiting ? { kind: "status" } : { kind: "heading" };
}
