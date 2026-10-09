// Targeted mutation tests for the Club retry/idempotency and error-mapping
// rules. Each mutant applies ONE exact source edit, runs the Club test suite
// and must make it FAIL. Sources are restored in `finally` and verified by
// SHA-256 at the end. Line endings (LF/CRLF) are handled transparently.
import { spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import { readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const appDir = join(dirname(fileURLToPath(import.meta.url)), "..");
const src = (f) => join(appDir, "src", "club", f);

const mutants = [
  {
    id: "F1-new-uuid-on-every-retry",
    file: "redemptionIntent.ts",
    search: "      return open;\n",
    replace: "      this.intent = { ...payload, requestId: this.makeId(), attempts: 0 };\n      return this.intent;\n",
  },
  {
    id: "F2-network-error-closes-intent",
    file: "errors.ts",
    search: '  "network_error",\n  "invalid_response",\n  "server_error",',
    replace: '  "invalid_response",\n  "server_error",',
  },
  {
    id: "F3-server-error-closes-intent",
    file: "errors.ts",
    search: '  "invalid_response",\n  "server_error",\n  "rate_limited",',
    replace: '  "invalid_response",\n  "rate_limited",',
  },
  {
    id: "F4-coffee-change-reuses-old-id",
    file: "redemptionIntent.ts",
    search: " &&\n      open.coffeeCode === payload.coffeeCode",
    replace: "",
  },
  {
    id: "F5-member-reset-is-noop",
    file: "redemptionIntent.ts",
    search: "  reset(): void {\n    this.intent = null;\n  }",
    replace: "  reset(): void {\n  }",
  },
  {
    id: "F6-details-not-mapped-to-camelCase",
    file: "restClubService.ts",
    search: "if (isInstant(raw.redeemed_at)) details.redeemedAt = raw.redeemed_at;",
    replace: "if (isInstant(raw.redeemedAt)) details.redeemedAt = raw.redeemedAt;",
  },
  {
    id: "F7-bare-404-treated-as-member-not-found",
    file: "restClubService.ts",
    search: '  return clubError("invalid_response", undefined, status);\n}',
    replace: '  return clubError(status === 404 ? "member_not_found" : "invalid_response", undefined, status);\n}',
  },
  {
    id: "F8-ignore-status-envelope-consistency",
    file: "restClubService.ts",
    search: "EXPECTED_STATUS[envelope.code](status)",
    replace: "true",
  },
  {
    id: "F9-show-server-message",
    file: "restClubService.ts",
    search: "return new ClubApiError(envelope.code, CLUB_ERROR_MESSAGES[envelope.code], details, status);",
    replace: "return new ClubApiError(envelope.code, String(envelope.message), details, status);",
  },
  {
    id: "F10-skip-request-id-echo-check",
    file: "restClubService.ts",
    search: " || request_id !== request.requestId",
    replace: "",
  },
  {
    id: "F11-accept-naive-timestamps",
    file: "restClubService.ts",
    search: "(?:Z|[+-]\\d{2}:\\d{2})$/;",
    replace: "(?:Z|[+-]\\d{2}:\\d{2})?$/;",
  },
  {
    id: "F12-transport-error-not-mapped",
    file: "restClubService.ts",
    search: '      throw clubError("network_error");',
    replace: '      throw new Error("network down");',
  },
  {
    id: "F13-business-date-in-utc",
    file: "format.ts",
    search: "    timeZone: BUSINESS_TIME_ZONE,\n    year:",
    replace: '    timeZone: "UTC",\n    year:',
  },
  {
    id: "F14-mock-ignores-idempotency-conflict",
    file: "clubService.ts",
    search: '        throw clubError("idempotency_key_reused");',
    replace: "        return outcomeFrom(previous, requestId, true);",
  },
  {
    id: "F15-mock-allows-second-coffee-per-day",
    file: "clubService.ts",
    search: "      if (winner) {",
    replace: "      if (winner && false) {",
  },
  {
    id: "F16-unknown-throwable-closes-intent",
    file: "errors.ts",
    search: "return !(err instanceof ClubApiError) || RETRY_SAME_INTENT.has(err.code);",
    replace: "return err instanceof ClubApiError && RETRY_SAME_INTENT.has(err.code);",
  },
];

const runSuite = () =>
  spawnSync(process.execPath, [join(appDir, "scripts", "run-club-tests.mjs")], {
    env: { ...process.env, EVC_QUIET_TESTS: "1" },
    stdio: "ignore",
  }).status;

const sha = (path) => createHash("sha256").update(readFileSync(path)).digest("hex");
const touched = new Map([...new Set(mutants.map((m) => src(m.file)))].map((p) => [p, sha(p)]));

console.log("Baseline (unmutated) run...");
if (runSuite() !== 0) {
  console.error("ABORT: baseline Club tests are not green; mutation results would be meaningless.");
  process.exit(1);
}
console.log("Baseline: PASS");

const survivors = [];
let killed = 0;
for (const m of mutants) {
  const path = src(m.file);
  const original = readFileSync(path, "utf8");
  const eol = original.includes("\r\n") ? "\r\n" : "\n";
  const search = m.search.replaceAll("\n", eol);
  const count = original.split(search).length - 1;
  if (count !== 1) {
    console.error(`ABORT: ${m.id} pattern found ${count} times in ${m.file} (expected 1).`);
    process.exit(1);
  }
  let status;
  try {
    writeFileSync(path, original.replace(search, m.replace.replaceAll("\n", eol)));
    status = runSuite();
  } finally {
    writeFileSync(path, original);
  }
  if (status !== 0) killed++;
  else survivors.push(m.id);
  console.log(`${m.id.padEnd(44)} ${status !== 0 ? "KILLED" : "SURVIVED"} (exit ${status})`);
}

let restored = true;
for (const [path, hash] of touched) {
  if (sha(path) !== hash) {
    restored = false;
    console.error(`NOT RESTORED: ${path}`);
  }
}
console.log(
  `[evidence] club-frontend-mutation: total=${mutants.length} killed=${killed} survived=${survivors.length} sources_restored=${restored ? "yes" : "NO"}`,
);
if (survivors.length) console.error(`Surviving mutants: ${survivors.join(", ")}`);
process.exit(survivors.length || !restored ? 1 : 0);
