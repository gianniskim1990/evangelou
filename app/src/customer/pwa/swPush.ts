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
 *   Round 18 (owner decision D30): a notification sent from «Ειδοποιήσεις» names its in-app message by number
 *                      ({"title", "body", "message": <id>}). The worker checks it (a positive whole number, at most
 *                      MAX_MESSAGE_ID; anything else is ignored and the tap opens the member page as before), keeps it
 *                      in the notification's tag («evc-message-<id>», set by the worker itself), and the tap opens the
 *                      registration's own scope + «#message=<id>»: same origin, inside the scope, built here from the
 *                      checked number. An open window of the member page is sent to that address and brought forward.
 */

export const PUSH_FALLBACK = { title: "Ευαγγέλου Club", body: "Έχετε νέα ειδοποίηση από το κατάστημα." } as const;
export const MAX_TITLE = 50;
export const MAX_BODY = 150;
/** Round 18 (D30): the largest message number a payload may name (EVC_Push_Message::MAX_MESSAGE_ID). */
export const MAX_MESSAGE_ID = 2147483647;
const TAG_PREFIX = "evc-message-";

// Deliberate: control and format characters are refused in a notification's text (as EVC_Push_Message does).
// oxlint-disable-next-line no-control-regex
const CONTROL = /[\u0000-\u001f\u007f-\u009f​-‏‪-‮⁠-⁤﻿]/;
const length = (s: string) => Array.from(s).length;

export interface PushPayload {
  title: string;
  body: string;
  /** Round 18 (D30): the in-app message this notification opens (absent = the member page as before). */
  message?: number;
}

/** A message number a payload may name. */
export function isPushMessageId(v: unknown): v is number {
  return typeof v === "number" && Number.isInteger(v) && v >= 1 && v <= MAX_MESSAGE_ID;
}

/** The title and body to show for a push message's text (the decrypted payload), and its checked message number. */
export function parsePushPayload(text: string | null | undefined): PushPayload {
  if (typeof text !== "string" || text.length > 4000) return { ...PUSH_FALLBACK };
  let data: unknown;
  try {
    data = JSON.parse(text);
  } catch {
    return { ...PUSH_FALLBACK };
  }
  if (typeof data !== "object" || data === null || Array.isArray(data)) return { ...PUSH_FALLBACK };
  const { title, body, message } = data as Record<string, unknown>;
  if (typeof title !== "string" || typeof body !== "string") return { ...PUSH_FALLBACK };
  const t = title.trim();
  const b = body.trim();
  if (t === "" || b === "" || length(t) > MAX_TITLE || length(b) > MAX_BODY || CONTROL.test(t) || CONTROL.test(b)) return { ...PUSH_FALLBACK };
  // Round 18 (D30): a message number that is not a positive whole number in range is ignored (the text still shows).
  return isPushMessageId(message) ? { title: t, body: b, message } : { title: t, body: b };
}

export interface NotificationPlan {
  title: string;
  options: { body: string; lang: "el"; dir: "ltr"; icon?: string; badge?: string; tag?: string };
}

/**
 * showNotification()'s arguments: text, language and the app icon. No data, no actions, no URL. Round 18 (D30): the
 * checked message number travels in the tag («evc-message-<id>»), which the worker itself writes.
 */
export function notificationFor(payload: PushPayload, icon: string): NotificationPlan {
  const options: NotificationPlan["options"] = { body: payload.body, lang: "el", dir: "ltr" };
  if (icon !== "") {
    options.icon = icon;
    options.badge = icon;
  }
  if (isPushMessageId(payload.message)) options.tag = TAG_PREFIX + String(payload.message);
  return { title: payload.title, options };
}

/** Round 18 (D30): the message number of a notification's tag, or null (any other tag, or none). */
export function messageOfTag(tag: string | null | undefined): number | null {
  if (typeof tag !== "string") return null;
  const m = /^evc-message-([1-9][0-9]{0,9})$/.exec(tag);
  if (!m) return null;
  const id = Number(m[1]);
  return isPushMessageId(id) ? id : null;
}

/**
 * Which window a tap opens: the first open window whose address is inside the worker's scope (focus it), else the scope
 * itself (open it). $scope is the registration's own scope URL (e.g. https://shop.gr/club-member/), never message data.
 * Round 18 (D30): with a checked message number the target is the scope + «#message=<id>»; an open window is sent there
 * (`navigate`) before it is brought forward.
 */
export function clickTarget(windowUrls: string[], scope: string, message: number | null = null): { focus: number; navigate?: string } | { open: string } {
  let base: URL;
  try {
    base = new URL(scope);
  } catch {
    return { open: "/" };
  }
  const target = base.origin + base.pathname + (isPushMessageId(message) ? `#message=${message}` : "");
  const index = windowUrls.findIndex((u) => {
    try {
      const url = new URL(u);
      return url.origin === base.origin && url.pathname.startsWith(base.pathname);
    } catch {
      return false;
    }
  });
  if (index < 0) return { open: target };
  return isPushMessageId(message) ? { focus: index, navigate: target } : { focus: index };
}
