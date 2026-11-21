/*
 * STAGE-08 round 12 (owner decision D26 part 2): what the member app's service worker does with a push message and a tap
 * on its notification. Pure, so that the tests check every rule (tests/club/push.test.ts); sw.ts applies them.
 *
 *   push               the decrypted payload is JSON {"title", "body"} (EVC_Push_Message: at most 50 / 150 characters);
 *                      anything else shows a fixed Greek line instead, because every push MUST show a notification
 *                      (userVisibleOnly). The icon is the app's own (the worker's configuration, same origin).
 *   notificationclick  focuses an open window of the member page, else opens the member page: always the worker's own
 *                      registration scope (/club-member/). NEVER an address from the payload (there is none to read:
 *                      the notification carries no data).
 */

export const PUSH_FALLBACK = { title: "Ευαγγέλου Club", body: "Έχετε νέα ειδοποίηση από το κατάστημα." } as const;
export const MAX_TITLE = 50;
export const MAX_BODY = 150;

// Deliberate: control and format characters are refused in a notification's text (as EVC_Push_Message does).
// oxlint-disable-next-line no-control-regex
const CONTROL = /[\u0000-\u001f\u007f-\u009f​-‏‪-‮⁠-⁤﻿]/;
const length = (s: string) => Array.from(s).length;

/** The title and body to show for a push message's text (the decrypted payload). */
export function parsePushPayload(text: string | null | undefined): { title: string; body: string } {
  if (typeof text !== "string" || text.length > 4000) return { ...PUSH_FALLBACK };
  let data: unknown;
  try {
    data = JSON.parse(text);
  } catch {
    return { ...PUSH_FALLBACK };
  }
  if (typeof data !== "object" || data === null || Array.isArray(data)) return { ...PUSH_FALLBACK };
  const { title, body } = data as Record<string, unknown>;
  if (typeof title !== "string" || typeof body !== "string") return { ...PUSH_FALLBACK };
  const t = title.trim();
  const b = body.trim();
  if (t === "" || b === "" || length(t) > MAX_TITLE || length(b) > MAX_BODY || CONTROL.test(t) || CONTROL.test(b)) return { ...PUSH_FALLBACK };
  return { title: t, body: b };
}

export interface NotificationPlan {
  title: string;
  options: { body: string; lang: "el"; dir: "ltr"; icon?: string; badge?: string };
}

/** showNotification()'s arguments: text, language and the app icon. No data, no actions, no URL. */
export function notificationFor(payload: { title: string; body: string }, icon: string): NotificationPlan {
  const options: NotificationPlan["options"] = { body: payload.body, lang: "el", dir: "ltr" };
  if (icon !== "") {
    options.icon = icon;
    options.badge = icon;
  }
  return { title: payload.title, options };
}

/**
 * Which window a tap opens: the first open window whose address is inside the worker's scope (focus it), else the scope
 * itself (open it). $scope is the registration's own scope URL (e.g. https://shop.gr/club-member/), never message data.
 */
export function clickTarget(windowUrls: string[], scope: string): { focus: number } | { open: string } {
  let base: URL;
  try {
    base = new URL(scope);
  } catch {
    return { open: "/" };
  }
  const index = windowUrls.findIndex((u) => {
    try {
      const url = new URL(u);
      return url.origin === base.origin && url.pathname.startsWith(base.pathname);
    } catch {
      return false;
    }
  });
  return index >= 0 ? { focus: index } : { open: base.origin + base.pathname };
}
