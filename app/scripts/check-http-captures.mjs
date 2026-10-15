// UI-04H (CI only): feeds raw replies captured from the disposable WordPress
// site running the TEST-ONLY v2 harness (wordpress/evangelou-club/tests/http/
// run-http-checks.php harness) through the REAL staff-app code:
// classifyHttpReply() and the strict wire.ts parsers. No network access here.
//
//   node scripts/check-http-captures.mjs <captures.json>
import { build } from "esbuild";
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const appDir = join(dirname(fileURLToPath(import.meta.url)), "..");
const file = process.argv[2];
if (!file) {
  console.error("usage: node scripts/check-http-captures.mjs <captures.json>");
  process.exit(2);
}
const captures = JSON.parse(readFileSync(file, "utf8"));
if (captures.schema !== "evc-http-captures-v1" || !Array.isArray(captures.captures)) {
  console.error("not an evc-http-captures-v1 file");
  process.exit(2);
}

const outDir = mkdtempSync(join(tmpdir(), "evc-http-captures-"));
let failed = 0;
try {
  await build({
    entryPoints: [join(appDir, "src/staff/redemptionRecovery/wire.ts")],
    bundle: true,
    platform: "node",
    format: "esm",
    outfile: join(outDir, "wire.mjs"),
    logLevel: "warning",
  });
  const wire = await import(pathToFileURL(join(outDir, "wire.mjs")).href);
  const parse = (surface, body, sent) => {
    switch (surface) {
      case "redemption":
        return wire.parseRedemptionReply(body, { requestId: sent.request_id, memberId: sent.member_id });
      case "handoff":
        return wire.parseHandoffReply(body, { handoffRequestId: sent.handoff_request_id, redemptionRequestId: sent.redemption_request_id, claim: sent.claim });
      case "status": {
        const r = wire.parseStatusReply(body, { redemptionRequestId: sent.redemption_request_id, memberId: sent.member_id });
        return r.kind === "unavailable" ? null : r;
      }
      case "lookup":
        return wire.parseLookupReply(body);
      case "usual":
        return wire.parseUsualCoffeeReply(body);
      default:
        return null;
    }
  };
  let domain = 0;
  let transport = 0;
  for (const c of captures.captures) {
    // Exactly what a browser fetch() would hand the app: JSON only for a JSON content type.
    let body = c.body;
    if (/application\/json/i.test(c.content_type)) {
      try {
        body = JSON.parse(c.body);
      } catch {
        body = c.body;
      }
    }
    const routed = wire.classifyHttpReply(c.surface, c.status, body);
    let ok;
    let detail;
    if (c.expect.kind === "domain") {
      const parsed = routed.kind === "domain" ? parse(c.surface, routed.body, c.sent ?? {}) : null;
      ok = parsed !== null && parsed.kind === c.expect.parsed;
      detail = `routed=${routed.kind} parsed=${parsed ? parsed.kind : "null"}`;
      domain++;
    } else {
      ok = routed.kind === "transport" && routed.failure === c.expect.failure;
      detail = `routed=${JSON.stringify(routed.kind === "transport" ? routed : { kind: "domain" })}`;
      transport++;
    }
    if (!ok) failed++;
    console.log(`${ok ? "PASS" : "FAIL"} ${c.name} [${c.status}] ${ok ? "" : detail}`);
  }
  if (domain < 8 || transport < 15) {
    console.log(`FAIL too few captures (domain=${domain}, transport=${transport})`);
    failed++;
  }
  console.log(`[evidence] http-captures-ts: captures=${captures.captures.length} domain=${domain} transport=${transport} failed=${failed}`);
} finally {
  rmSync(outDir, { recursive: true, force: true });
}
process.exit(failed === 0 ? 0 : 1);
