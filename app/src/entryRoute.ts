/**
 * Which top-level app a pathname renders (pure, unit-tested).
 *
 * `/club-preview` (UI-01 design preview) is matched EXACTLY as a path
 * segment and only when the build enables it; it is checked BEFORE the
 * existing `/club` prefix match, which is left unchanged. When the preview
 * is disabled (production builds), every path resolves exactly as before.
 */
export type EntryApp = "admin" | "club" | "club-preview" | "ordering";

const CLUB_PREVIEW_PATH = /^\/club-preview(?:\/|$)/;

export function isClubPreviewPath(pathname: string): boolean {
  return CLUB_PREVIEW_PATH.test(pathname);
}

export function resolveEntry(pathname: string, previewEnabled: boolean): EntryApp {
  if (pathname.startsWith("/admin")) return "admin";
  if (previewEnabled && isClubPreviewPath(pathname)) return "club-preview";
  if (pathname.startsWith("/club")) return "club";
  return "ordering";
}
