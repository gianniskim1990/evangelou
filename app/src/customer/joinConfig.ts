import { CUSTOMER_NAMESPACE } from "./config";

/*
 * STAGE-07 bootstrap configuration of the join page and the tablet poster, delivered by the WordPress pages
 * (EVC_Onboarding_Shell) as inert JSON in the same element as the member area's. Fail closed: anything malformed,
 * and ANY URL that is not this site, is refused (the app then shows a configuration error): no open redirect can
 * come in through the bootstrap either.
 */

export type JoinStep = "register" | "terms" | "offer" | "member" | "not_customer" | "unavailable";
export const JOIN_STEPS_ALLOWED: readonly JoinStep[] = ["register", "terms", "offer", "member", "not_customer", "unavailable"];

export interface JoinConfig {
  restBase: string;
  nonce: string;
  step: JoinStep;
  joinUrl: string;
  memberUrl: string;
  loginUrl: string;
  logoutUrl: string;
  lostPasswordUrl: string;
  /** Same-site links, or "" when the site has none configured. */
  privacyUrl: string;
  termsUrl: string;
  appVersion: string;
}

export interface PosterConfig {
  joinUrl: string;
  appVersion: string;
}

export type BootMode = "member" | "join" | "poster";
export type Parsed<T> = { ok: true; config: T } | { ok: false; reason: string };

const NONCE_RE = /^[0-9a-f]{10}$/;
const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === "object" && v !== null && !Array.isArray(v);

function readJson(raw: string | null | undefined): Record<string, unknown> | null {
  if (!raw) return null;
  try {
    const data: unknown = JSON.parse(raw);
    return isRecord(data) ? data : null;
  } catch {
    return null;
  }
}

/** Absolute http(s) URL on exactly this origin, else null. */
export function sameOrigin(value: unknown, origin: string): string | null {
  if (typeof value !== "string" || value === "") return null;
  try {
    const url = new URL(value, origin);
    if (url.origin !== origin || (url.protocol !== "https:" && url.protocol !== "http:")) return null;
    return url.href;
  } catch {
    return null;
  }
}

/** Which app the page asks for; the member area when "mode" is absent (the STAGE-01 bootstrap has none). */
export function bootMode(raw: string | null | undefined): BootMode | null {
  const data = readJson(raw);
  if (!data) return null;
  if (!("mode" in data)) return "member";
  return data.mode === "join" || data.mode === "poster" ? data.mode : null;
}

function restBaseOk(value: unknown, origin: string): string | null {
  const href = sameOrigin(value, origin);
  if (!href) return null;
  const url = new URL(href);
  const route = url.searchParams.has("rest_route") ? url.searchParams.get("rest_route")!.replace(/\/+$/, "") === CUSTOMER_NAMESPACE : url.pathname.replace(/\/+$/, "").endsWith(CUSTOMER_NAMESPACE);
  return route ? href : null;
}

export function parseJoinConfig(raw: string | null | undefined, origin: string): Parsed<JoinConfig> {
  const d = readJson(raw);
  if (!d || d.mode !== "join") return { ok: false, reason: "not_join" };
  const restBase = restBaseOk(d.restBase, origin);
  if (!restBase) return { ok: false, reason: "rest_base" };
  if (typeof d.nonce !== "string" || !NONCE_RE.test(d.nonce)) return { ok: false, reason: "nonce" };
  if (typeof d.step !== "string" || !(JOIN_STEPS_ALLOWED as readonly string[]).includes(d.step)) return { ok: false, reason: "step" };
  const urls: Record<string, string> = {};
  for (const key of ["joinUrl", "memberUrl", "loginUrl", "logoutUrl", "lostPasswordUrl"]) {
    const href = sameOrigin(d[key], origin);
    if (!href) return { ok: false, reason: key };
    urls[key] = href;
  }
  const optional: Record<string, string> = {};
  for (const key of ["privacyUrl", "termsUrl"]) {
    if (d[key] === "" || d[key] === undefined) optional[key] = "";
    else {
      const href = sameOrigin(d[key], origin);
      if (!href) return { ok: false, reason: key };
      optional[key] = href;
    }
  }
  return {
    ok: true,
    config: {
      restBase,
      nonce: d.nonce,
      step: d.step as JoinStep,
      joinUrl: urls.joinUrl,
      memberUrl: urls.memberUrl,
      loginUrl: urls.loginUrl,
      logoutUrl: urls.logoutUrl,
      lostPasswordUrl: urls.lostPasswordUrl,
      privacyUrl: optional.privacyUrl,
      termsUrl: optional.termsUrl,
      appVersion: typeof d.appVersion === "string" ? d.appVersion : "",
    },
  };
}

/** The poster carries ONLY the public join URL (same site, no credentials, nothing about anyone). */
export function parsePosterConfig(raw: string | null | undefined, origin: string): Parsed<PosterConfig> {
  const d = readJson(raw);
  if (!d || d.mode !== "poster") return { ok: false, reason: "not_poster" };
  const keys = Object.keys(d).sort().join(",");
  if (keys !== "appVersion,joinUrl,mode") return { ok: false, reason: "unexpected_keys" };
  const joinUrl = sameOrigin(d.joinUrl, origin);
  if (!joinUrl) return { ok: false, reason: "join_url" };
  const url = new URL(joinUrl);
  if (url.username !== "" || url.password !== "" || url.hash !== "") return { ok: false, reason: "join_url" };
  for (const [name, value] of url.searchParams) {
    if (!(name === "evc_club_join" && value === "1") && !(name === "src" && value === "store")) return { ok: false, reason: "join_url" };
  }
  return { ok: true, config: { joinUrl, appVersion: typeof d.appVersion === "string" ? d.appVersion : "" } };
}
