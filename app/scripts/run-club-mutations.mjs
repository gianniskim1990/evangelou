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
const src = (f) => join(appDir, "src", f.includes("/") ? f : `club/${f}`);

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
  // Task 1C-D: real staff app (/club-admin/).
  {
    id: "S1-idle-lock-boundary-off-by-one",
    file: "staff/idleLock.ts",
    search: "if (!this.locked && this.clock.now() - this.last >= this.timeoutMs) {",
    replace: "if (!this.locked && this.clock.now() - this.last > this.timeoutMs) {",
  },
  {
    id: "S2-idle-lock-bypassed",
    file: "staff/idleLock.ts",
    search: "    if (!this.locked && this.clock.now() - this.last >= this.timeoutMs) {\n      this.lock();\n    }\n",
    replace: "",
  },
  {
    id: "S3-late-touch-revives-expired-session",
    file: "staff/idleLock.ts",
    search: "    if (this.check()) return; // interaction after the deadline cannot revive the session\n",
    replace: "",
  },
  {
    id: "S4-mousemove-counts-as-activity",
    file: "staff/idleLock.ts",
    search: '["pointerdown", "keydown", "touchstart", "wheel"] as const;',
    replace: '["pointerdown", "keydown", "touchstart", "wheel", "mousemove", "scroll", "focus", "visibilitychange", "pageshow"] as const;',
  },
  {
    id: "S5-stale-responses-not-discarded",
    file: "staff/staffController.ts",
    search: "    this.epoch++;\n",
    replace: "",
  },
  {
    id: "S6-reload-bypasses-lock",
    file: "staff/staffController.ts",
    search: 'if (ref !== "" && marker === ref) {',
    replace: "if (false) {",
  },
  {
    id: "S7-lock-keeps-server-session",
    file: "staff/staffController.ts",
    search: "        await this.deps.api.endSession();\n",
    replace: "",
  },
  {
    id: "S8-unlock-in-place-without-login",
    file: "staff/staffController.ts",
    search: "    this.deps.navigate(this.deps.config.loginUrl);\n",
    replace: "    this.setState({ phase: \"loading\" });\n",
  },
  {
    id: "S9-refreshed-nonce-ignored",
    file: "staff/staffApi.ts",
    search: '    nonce.update(res.headers.get("X-WP-Nonce"));\n',
    replace: "",
  },
  {
    id: "S10-cross-origin-config-accepted",
    file: "staff/config.ts",
    search: "    if (url.origin !== origin) return null;\n",
    replace: "",
  },
  {
    id: "S11-staff-bundle-imports-mock",
    file: "staff/staffApi.ts",
    search: 'import { isValidNonce, type StaffConfig } from "./config";',
    replace: 'import { isValidNonce, type StaffConfig } from "./config";\nimport { mockClubService as __demo } from "../club/clubService";\nexport const __leak = __demo;',
  },
  {
    id: "S12-401-not-treated-as-expired",
    file: "staff/staffController.ts",
    search: 'if (error.kind === "unauthorized") {',
    replace: 'if (error.kind === "never") {',
  },
  {
    id: "S13-nonce-sent-cross-origin",
    file: "staff/staffApi.ts",
    search: '    if (url.origin !== origin) throw new StaffApiError("invalid_response");\n',
    replace: "",
  },
  {
    id: "S14-session-request-timeout-removed",
    file: "staff/staffApi.ts",
    search: "    const timer = timers.setTimeout(() => {\n      timedOut = true;\n      controller.abort();\n    }, timeoutMs);\n",
    replace: "    const timer = undefined;\n",
  },
  {
    id: "S15-sensitive-data-in-forbidden-offline",
    file: "staff/staffController.ts",
    search: "    if (!this.isReady) return false;\n",
    replace: "",
  },
  {
    id: "S16-protected-op-runs-when-not-ready",
    file: "staff/staffController.ts",
    search: '    if (!this.isReady) return { status: "stale" };\n    const result = await this.track(fn, () => this.isReady);',
    replace: "    const result = await this.track(fn, () => true);",
  },
  {
    id: "S17-late-private-response-restored",
    file: "staff/staffController.ts",
    search: '      if (epoch !== this.epoch || !stillValid()) return { status: "stale" };\n      return { status: "ok", value };',
    replace: '      return { status: "ok", value };',
  },
  {
    id: "S18-unknown-logout-reported-as-success",
    file: "staff/staffController.ts",
    search: "      } catch {\n        // Outcome unknown (or session possibly still alive): never claim it.\n",
    replace: "      } catch {\n        confirmed = true;\n",
  },
  {
    id: "S19-timeout-reported-as-network",
    file: "staff/staffApi.ts",
    search: '      if (timedOut) throw new StaffApiError("timeout");\n',
    replace: "",
  },
  // ---- Task 1D-C: protected 401/403 gate + latest-request-wins status checks
  {
    id: "S20-protected-auth-loss-ignored",
    file: "staff/staffController.ts",
    search: "    if (loss !== null) {",
    replace: "    if (false) {",
  },
  {
    id: "S21-protected-club-401-ignored",
    file: "staff/staffController.ts",
    search: "    if (error.code === \"unauthorized\" && error.httpStatus === 401) return \"unauthorized\";\n",
    replace: "",
  },
  {
    id: "S22-protected-club-403-ignored",
    file: "staff/staffController.ts",
    search: "    if (error.code === \"forbidden\" && error.httpStatus === 403) return \"forbidden\";\n",
    replace: "",
  },
  {
    id: "S23-409-treated-as-auth-failure",
    file: "staff/staffController.ts",
    search: "    if (error.code === \"forbidden\" && error.httpStatus === 403) return \"forbidden\";\n",
    replace: "    if (error.code === \"forbidden\" && error.httpStatus === 403) return \"forbidden\";\n    if (error.httpStatus === 409) return \"unauthorized\";\n",
  },
  {
    id: "S24-auth-loss-keeps-sensitive-data",
    file: "staff/staffController.ts",
    search: "    this.sensitive = null;\n",
    replace: "",
  },
  {
    id: "S25-older-status-success-overwrites-newer",
    file: "staff/staffController.ts",
    search: "      if (!superseded) this.setState({ phase: \"ready\", session: result.value });",
    replace: "      this.setState({ phase: \"ready\", session: result.value });",
  },
  {
    id: "S26-older-status-failure-overwrites-newer",
    file: "staff/staffController.ts",
    search: "      if (!superseded || authLossOf(result.error) !== null) this.handleError(result.error);",
    replace: "      this.handleError(result.error);",
  },
  {
    id: "S27-superseded-401-ignored",
    file: "staff/staffController.ts",
    search: "      if (!superseded || authLossOf(result.error) !== null) this.handleError(result.error);",
    replace: "      if (!superseded) this.handleError(result.error);",
  },
  {
    id: "S28-superseded-check-not-aborted",
    file: "staff/staffController.ts",
    search: "        this.statusRequest?.abort();\n",
    replace: "",
  },
  {
    id: "S29-untrusted-staff-error-without-status",
    file: "staff/staffController.ts",
    search: "    if (error.kind === \"unauthorized\" && error.status === 401) return \"unauthorized\";",
    replace: "    if (error.kind === \"unauthorized\") return \"unauthorized\";",
  },
  {
    id: "S30-client-made-club-error-trusted",
    file: "staff/staffController.ts",
    search: "    if (error.code === \"unauthorized\" && error.httpStatus === 401) return \"unauthorized\";",
    replace: "    if (error.code === \"unauthorized\") return \"unauthorized\";",
  },
];

const runSuite = () =>
  spawnSync(process.execPath, [join(appDir, "scripts", "run-club-tests.mjs")], {
    env: { ...process.env, EVC_QUIET_TESTS: "1" },
    stdio: "ignore",
    // A mutant that hangs the suite (e.g. a removed timeout) counts as killed.
    timeout: 180_000,
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
