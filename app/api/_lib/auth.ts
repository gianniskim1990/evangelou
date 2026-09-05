import type { VercelRequest } from "@vercel/node";

/** True only if ADMIN_PASSWORD is set server-side and the request supplied a matching header. */
export function isAdminRequest(req: VercelRequest): boolean {
  const adminPassword = process.env.ADMIN_PASSWORD;
  if (!adminPassword) return false;
  const provided = req.headers["x-admin-password"];
  return provided === adminPassword;
}
