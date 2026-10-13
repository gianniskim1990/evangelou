/**
 * Whether the UI-01 /club-preview design preview is compiled in (used by
 * vite.config.ts as the __EVC_CLUB_PREVIEW__ define). Off for Vercel
 * PRODUCTION builds and when EVC_CLUB_PREVIEW=off; on for local dev and
 * Vercel preview deployments.
 */
export function clubPreviewEnabled(env: Record<string, string | undefined>): boolean {
  if (env.VERCEL_ENV === "production") return false;
  if (env.EVC_CLUB_PREVIEW === "off") return false;
  return true;
}
