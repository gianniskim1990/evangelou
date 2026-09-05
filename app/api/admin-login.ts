import type { VercelRequest, VercelResponse } from "@vercel/node";

// Verifies the shared admin password (set as the ADMIN_PASSWORD env var in
// the Vercel project — never in code or git). This is a lightweight gate for
// a small-business internal tool, not a real auth system: there is no user
// account, no hashing, no rate limiting. Good enough to keep casual visitors
// out of /admin; not meant to withstand a determined attacker.
export default async function handler(req: VercelRequest, res: VercelResponse) {
  if (req.method !== "POST") {
    res.status(405).json({ error: "Method not allowed" });
    return;
  }

  const adminPassword = process.env.ADMIN_PASSWORD;
  if (!adminPassword) {
    res.status(500).json({ error: "Το ADMIN_PASSWORD δεν έχει ρυθμιστεί στο Vercel project." });
    return;
  }

  const password = typeof req.body?.password === "string" ? req.body.password : "";
  if (password !== adminPassword) {
    res.status(401).json({ error: "Λάθος κωδικός." });
    return;
  }

  res.status(200).json({ ok: true });
}
