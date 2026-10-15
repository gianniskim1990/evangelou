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
  // ---- UI-01 design preview: routing, build gate, isolation -----------------
  {
    id: "U1-preview-prefix-match-captures-lookalikes",
    file: "./entryRoute.ts",
    search: "const CLUB_PREVIEW_PATH = /^\\/club-preview(?:\\/|$)/;",
    replace: "const CLUB_PREVIEW_PATH = /^\\/club-preview/;",
  },
  {
    id: "U2-club-prefix-checked-before-preview",
    file: "./entryRoute.ts",
    search: "  if (previewEnabled && isClubPreviewPath(pathname)) return \"club-preview\";\n  if (pathname.startsWith(\"/club\")) return \"club\";",
    replace: "  if (pathname.startsWith(\"/club\")) return \"club\";\n  if (previewEnabled && isClubPreviewPath(pathname)) return \"club-preview\";",
  },
  {
    id: "U3-preview-route-ignores-build-gate",
    file: "./entryRoute.ts",
    search: "  if (previewEnabled && isClubPreviewPath(pathname)) return \"club-preview\";",
    replace: "  if (isClubPreviewPath(pathname)) return \"club-preview\";",
  },
  {
    id: "U4-preview-built-into-vercel-production",
    file: "preview/buildFlag.ts",
    search: "  if (env.VERCEL_ENV === \"production\") return false;\n",
    replace: "",
  },
  {
    id: "U5-preview-code-not-gated-out-of-public-bundle",
    file: "./main.tsx",
    search: "const ClubPreviewApp = __EVC_CLUB_PREVIEW__\n  ? lazy(",
    replace: "const ClubPreviewApp = true\n  ? lazy(",
  },
  {
    id: "U6-preview-makes-network-call",
    file: "preview/ClubPreviewApp.tsx",
    search: "  const today = useMemo(() => athensToday(), []);",
    replace: "  const today = useMemo(() => athensToday(), []);\n  useEffect(() => { void fetch(\"/api/orders\"); }, []);",
  },
  {
    id: "U7-welcome-says-membership-lasts-30-days",
    file: "preview/CustomerSignature.tsx",
    search: "Η συνδρομή ισχύει έναν ημερολογιακό μήνα και λήγει με ώρα Ελλάδας.",
    replace: "Πληρώνετε μία φορά για 30 ημέρες.",
  },
  {
    id: "U8-pass-renewal-says-30-days",
    file: "preview/CustomerSignature.tsx",
    search: "€ / ημερολογιακό μήνα",
    replace: "€ / 30 ημέρες",
  },
  {
    id: "U9-coffee-list-advertised-as-final",
    file: "preview/CustomerSignature.tsx",
    search: 'title: "ενδεικτικές επιλογές", body: `Για παράδειγμα: ${COFFEES.map((c) => c.label).join(", ")}. Ο τελικός κατάλογος δεν έχει οριστικοποιηθεί.`',
    replace: 'title: "επιλογές", body: `${COFFEES.map((c) => c.label).join(", ")}.`',
  },
  {
    id: "U10-offer-payment-button-enabled",
    file: "preview/CustomerPreview.tsx",
    search: '<Btn tone="brass" disabled title="Η πληρωμή δεν είναι διαθέσιμη στην προεπισκόπηση"',
    replace: '<Btn tone="brass" title="Η πληρωμή δεν είναι διαθέσιμη στην προεπισκόπηση"',
  },
  {
    id: "U11-offer-says-30-days",
    file: "preview/CustomerPreview.tsx",
    search: '<span className="t-body text-[var(--milk)]/70">για έναν ημερολογιακό μήνα</span>',
    replace: '<span className="t-body text-[var(--milk)]/70">για 30 ημέρες</span>',
  },
  {
    id: "U12-today-coffee-list-not-illustrative",
    file: "preview/CustomerPreview.tsx",
    search: '<h2 className="t-title mt-10 text-[var(--ink)]">Ενδεικτικές επιλογές</h2>',
    replace: '<h2 className="t-title mt-10 text-[var(--ink)]">Επιλογές</h2>',
  },
  {
    id: "U13-staff-confirm-drops-not-recorded-notice",
    file: "preview/StaffPreview.tsx",
    search: "Δεν καλείται κανένα API και δεν καταχωρείται εξαργύρωση.",
    replace: "Ο καφές καταχωρήθηκε.",
  },
  {
    id: "U14-sweetener-shown-without-sweetness",
    file: "preview/coffeeConfig.ts",
    search: '(g !== "sweetener" || (order.choices.sweetness !== undefined && order.choices.sweetness !== "none"))',
    replace: "true",
  },
  {
    id: "U15-coffee-change-keeps-incompatible-options",
    file: "preview/coffeeConfig.ts",
    search: "  return normalize({ ...order, coffee });",
    replace: "  return ({ ...order, coffee });",
  },
  {
    id: "U16-new-member-keeps-previous-order",
    file: "preview/coffeeConfig.ts",
    search: "      return action.memberId === state.memberId ? state : { memberId: action.memberId, order: EMPTY_ORDER };",
    replace: "      return { ...state, memberId: action.memberId };",
  },
  {
    id: "U17-required-choices-assumed",
    file: "preview/coffeeConfig.ts",
    search: ".filter((g) => GROUPS[g].required && order.choices[g] === undefined)",
    replace: ".filter((g) => false && GROUPS[g].required && order.choices[g] === undefined)",
  },
  {
    id: "U18-prefill-bypasses-normalisation",
    file: "preview/coffeeConfig.ts",
    search: "order: normalize({ coffee: action.order.coffee, choices: { ...action.order.choices }, extras: [...action.order.extras], quickNotes: [...action.order.quickNotes] })",
    replace: "order: { ...action.order }",
  },
  {
    id: "U19-continue-enabled-for-incomplete-order",
    file: "preview/StaffOrder.tsx",
    search: "disabled={!ready} onClick={onContinue}",
    replace: "disabled={false} onClick={onContinue}",
  },
  {
    id: "U21-cold-drinks-offered-hot-notes",
    file: "preview/coffeeConfig.ts",
    search: '(q.when === "hot" && !cold)',
    replace: '(q.when === "hot")',
  },
  {
    id: "U22-foam-notes-offered-everywhere",
    file: "preview/coffeeConfig.ts",
    search: '(q.when === "foam" && foam)',
    replace: '(q.when === "foam")',
  },
  {
    id: "U23-coffee-change-keeps-incompatible-quick-notes",
    file: "preview/coffeeConfig.ts",
    search: "resolveExclusive(order.quickNotes.filter((q) => allowedNotes.includes(q)))",
    replace: "resolveExclusive(order.quickNotes)",
  },
  {
    id: "U24-normalize-keeps-extra-keys-of-input",
    file: "preview/coffeeConfig.ts",
    search: "  return { coffee, choices, extras: order.extras.filter",
    replace: "  return { ...order, coffee, choices, extras: order.extras.filter",
  },
  {
    id: "U25-reducer-accepts-unknown-actions",
    file: "preview/coffeeConfig.ts",
    search: "      // Unknown actions (including any attempt to add text) change nothing.\n      return state;",
    replace: "      return { ...state, order: { ...state.order, ...(action as object) } as CoffeeOrder };",
  },
  {
    id: "U30-completeness-ignores-structured-choices",
    file: "preview/coffeeConfig.ts",
    search: "  return missing(order).length === 0;",
    replace: "  return true;",
  },
  {
    id: "U31-snapshot-carries-a-note-field",
    file: "preview/coffeeConfig.ts",
    search: ".map((q) => ({ id: q.id, label: q.label })) };",
    replace: ".map((q) => ({ id: q.id, label: q.label })), note: null } as RedemptionPreparationSnapshot;",
  },
  {
    id: "U32-summary-drops-quick-notes",
    file: "preview/coffeeConfig.ts",
    search: '  if (quickNoteLabels(order).length) rows.push(["Οδηγίες", quickNoteLabels(order).join(", ")]);',
    replace: "",
  },
  {
    id: "U33-configurator-renders-a-text-input",
    file: "preview/StaffOrder.tsx",
    search: '                  <div className="flex flex-wrap gap-2" aria-label="Γρήγορες οδηγίες για αυτόν τον καφέ">',
    replace: '                  <input aria-label="x" /><div className="flex flex-wrap gap-2" aria-label="Γρήγορες οδηγίες για αυτόν τον καφέ">',
  },
  {
    id: "U34-state-keeps-a-text-field",
    file: "preview/coffeeConfig.ts",
    search: "export const EMPTY_ORDER: CoffeeOrder = { coffee: null, choices: {}, extras: [], quickNotes: [] };",
    replace: 'export const EMPTY_ORDER: CoffeeOrder = { coffee: null, choices: {}, extras: [], quickNotes: [], note: "" } as CoffeeOrder;',
  },
  {
    id: "U26-inapplicable-quick-note-accepted",
    file: "preview/coffeeConfig.ts",
    search: "  if (!quickNotesFor(order.coffee).some((q) => q.id === id)) return order;\n",
    replace: "",
  },
  {
    id: "U27-toggle-allows-contradictory-temperatures",
    file: "preview/coffeeConfig.ts",
    search: ": resolveExclusive([...order.quickNotes, id]);",
    replace: ": [...order.quickNotes, id];",
  },
  {
    id: "U28-normalize-keeps-contradictory-temperatures",
    file: "preview/coffeeConfig.ts",
    search: "quickNotes: resolveExclusive(order.quickNotes.filter((q) => allowedNotes.includes(q)))",
    replace: "quickNotes: order.quickNotes.filter((q) => allowedNotes.includes(q))",
  },
  {
    id: "U29-lukewarm-not-in-temperature-group",
    file: "preview/coffeeConfig.ts",
    search: '{ id: "lukewarm", label: "χλιαρός", when: "hot", exclusive: "temperature" }',
    replace: '{ id: "lukewarm", label: "χλιαρός", when: "hot" }',
  },
  // UI-03C preparation contract (client mirror; shared vectors with PHP).
  {
    id: "V1-free-text-note-accepted",
    file: "preparationContract.ts",
    search: 'if ("note" in preparation && preparation.note !== null) return reject("invalid_preparation", "free_text_not_supported");',
    replace: "",
  },
  {
    id: "V2-modifiers-not-sorted",
    file: "preparationContract.ts",
    search: "modifiers: [...modifiers.entries()].sort((a, b) => byteOrder(a[0], b[0]))",
    replace: "modifiers: [...modifiers.entries()]",
  },
  {
    id: "V3-hidden-group-accepted",
    file: "preparationContract.ts",
    search: 'for (const [group] of parsed.modifiers) if (!visible.includes(group)) return reject("invalid_preparation", "hidden_group_present");',
    replace: "",
  },
  {
    id: "V4-temperature-notes-not-exclusive",
    file: "preparationContract.ts",
    search: 'if (sets.has(set)) return reject("invalid_preparation", "exclusive_conflict");',
    replace: "",
  },
  {
    id: "V5-quick-note-applicability-ignored",
    file: "preparationContract.ts",
    search: 'if (!coffee.quickNotes.includes(id)) return reject("invalid_preparation", "quick_note_not_applicable");',
    replace: "",
  },
  {
    id: "V6-catalogue-allows-free-text",
    file: "preparationContract.ts",
    search: 'if (c.free_text_note !== false) fail("free_text_note");',
    replace: "",
  },
  {
    id: "V7-hyphenated-ids-accepted",
    file: "preparationContract.ts",
    search: "export const ID_PATTERN = /^[a-z][a-z0-9_]{1,31}$/;",
    replace: "export const ID_PATTERN = /^[a-z][a-z0-9_-]{1,31}$/;",
  },
  {
    id: "V8-required-group-not-enforced",
    file: "preparationContract.ts",
    search: 'for (const id of visible) if (groups.get(id)!.required && !selections.has(id)) return reject("invalid_preparation", "required_missing");',
    replace: "",
  },
  {
    id: "V9-hot-note-allowed-on-cold-coffee",
    file: "preparationContract.ts",
    search: '(applies === "hot" && cr.temperature === "hot")',
    replace: '(applies === "hot")',
  },
  {
    id: "V10-catalogue-accepts-pricing",
    file: "preparationContract.ts",
    search: 'if (c.pricing_policy !== "undecided") fail("pricing_policy");',
    replace: "",
  },
  {
    id: "V11-label-counts-utf16-units",
    file: "preparationContract.ts",
    search: "return [...v].length <= MAX_LABEL_CODE_POINTS;",
    replace: "return v.length <= MAX_LABEL_CODE_POINTS;",
  },
  {
    id: "U20-summary-drops-extras",
    file: "preview/coffeeConfig.ts",
    search: '  if (order.extras.length) rows.push(["Επιπλέον", order.extras.map((e) => EXTRAS[e].label).join(", ")]);',
    replace: "",
  },
  // UI-03D.D staff redemption + handoff recovery state machine (R1-R28).
  {
    id: "R1-replay-reply-may-say-prepare",
    file: "staff/redemptionRecovery/wire.ts",
    search: "    if (instruction !== expected) return null;\n",
    replace: "",
  },
  {
    id: "R2-replay-state-offers-prepare",
    file: "staff/redemptionRecovery/machine.ts",
    search: "  return { phase: \"previously_recorded_unconfirmed\", intent, record, manuallyVerified: false };\n}",
    replace: "  return { phase: \"recorded_now\", intent, record, prepareAcknowledged: false };\n}",
  },
  {
    id: "R3-status-recovery-offers-prepare",
    file: "staff/redemptionRecovery/machine.ts",
    search: "        this.set({ phase: \"previously_recorded_unconfirmed\", intent, record: status.record, manuallyVerified: false });",
    replace: "        this.set({ phase: \"recorded_now\", intent, record: status.record, prepareAcknowledged: false });",
  },
  {
    id: "R4-unknown-outcome-lists-prepare",
    file: "staff/redemptionRecovery/machine.ts",
    search: "    case \"redemption_unresolved\":\n      return [\"retry_redemption\", \"check_status\"];",
    replace: "    case \"redemption_unresolved\":\n      return [\"acknowledge_prepare\", \"retry_redemption\", \"check_status\"];",
  },
  {
    id: "R5-prepare-offered-outside-fresh-commit",
    file: "staff/redemptionRecovery/machine.ts",
    search: "  return s.phase === \"recorded_now\" && !s.prepareAcknowledged;",
    replace: "  return s.phase === \"recorded_now\" || s.phase === \"redemption_unresolved\";",
  },
  {
    id: "R6-unknown-reply-may-claim-consumption",
    file: "staff/redemptionRecovery/wire.ts",
    search: "    if (!nothingRecorded || consumed !== null || raw.retry_request_id !== sent.requestId) return null;",
    replace: "    if (raw.retry_request_id !== sent.requestId) return null;",
  },
  {
    id: "R7-redemption-retry-mints-new-id",
    file: "staff/redemptionRecovery/machine.ts",
    search: "    if (s.phase !== \"redemption_unresolved\" || this.busy || !this.payload) return \"rejected\";\n    return this.submitRedemption(s.intent);",
    replace: "    if (s.phase !== \"redemption_unresolved\" || this.busy || !this.payload) return \"rejected\";\n    return this.submitRedemption({ ...s.intent, requestId: this.deps.newId() });",
  },
  {
    id: "R8-resubmit-ignores-payload-binding",
    file: "staff/redemptionRecovery/machine.ts",
    search: "    if (digest === null || digest !== s.intent.payloadSha256) return \"rejected\";",
    replace: "    if (digest === null) return \"rejected\";",
  },
  {
    id: "R9-handoff-retry-mints-new-id",
    file: "staff/redemptionRecovery/machine.ts",
    search: "    return this.submitHandoff(s.intent, s.record, s.handoffIntent);",
    replace: "    return this.submitHandoff(s.intent, s.record, { ...s.handoffIntent, handoffRequestId: this.deps.newId() });",
  },
  {
    id: "R10-handoff-reply-accepts-foreign-confirmation",
    file: "staff/redemptionRecovery/wire.ts",
    search: "    if (!ours || handoff.claim !== sent.claim) return null;",
    replace: "    if (handoff.claim !== sent.claim) return null;",
  },
  {
    id: "R11-already-confirmed-may-carry-our-id",
    file: "staff/redemptionRecovery/wire.ts",
    search: "      if (ours) return null;\n",
    replace: "",
  },
  {
    id: "R12-stale-reply-applied",
    file: "staff/redemptionRecovery/machine.ts",
    search: "  private current(op: number): boolean {\n    return op === this.seq;",
    replace: "  private current(op: number): boolean {\n    return op === op;",
  },
  {
    id: "R13-session-end-keeps-in-flight-ops",
    file: "staff/redemptionRecovery/machine.ts",
    search: "  private block(reason: BlockReason): void {\n    this.seq++;\n",
    replace: "  private block(reason: BlockReason): void {\n",
  },
  {
    id: "R14-known-confirmation-downgraded",
    file: "staff/redemptionRecovery/machine.ts",
    search: "        const handoff = status.handoff ?? (known && known.redemptionRequestId === intent.requestId ? known : null);",
    replace: "        const handoff = known === undefined ? null : status.handoff;",
  },
  {
    id: "R15-401-not-blocking",
    file: "staff/redemptionRecovery/machine.ts",
    search: "    if (err.kind === \"unauthorized\") return \"unauthorized\";\n",
    replace: "",
  },
  {
    id: "R16-403-status-not-blocking",
    file: "staff/redemptionRecovery/machine.ts",
    search: "      case \"forbidden\":\n        this.block(\"forbidden\");\n        return \"applied\";\n      case \"integrity_failure\":\n        this.payload = null;",
    replace: "      case \"forbidden\":\n        this.set(unresolvedAfterStatus(intent, handoffIntent, before, \"status_unavailable\"));\n        return \"applied\";\n      case \"integrity_failure\":\n        this.payload = null;",
  },
  {
    id: "R17-earlier-day-replay-parsed-as-today",
    file: "staff/redemptionRecovery/wire.ts",
    search: "    const expected = record.businessDate < today ? \"do_not_prepare_for_today\" : handoff ? \"do_not_prepare\" : \"verify_handoff\";",
    replace: "    const expected = handoff ? \"do_not_prepare\" : \"verify_handoff\";",
  },
  {
    id: "R18-earlier-day-status-treated-as-today",
    file: "staff/redemptionRecovery/machine.ts",
    search: "        if (!status.isCurrentBusinessDate) {",
    replace: "        if (false) {",
  },
  {
    id: "R19-earlier-day-replay-mapped-as-today",
    file: "staff/redemptionRecovery/machine.ts",
    search: "        this.set(recordedState(intent, reply.record, reply.instruction === \"do_not_prepare_for_today\", reply.handoff));",
    replace: "        this.set(recordedState(intent, reply.record, false, reply.handoff));",
  },
  {
    id: "R20-athens-date-of-instant-unchecked",
    file: "staff/redemptionRecovery/wire.ts",
    search: "  if (athensDateOfStamp(v.redeemed_at_utc) !== v.business_date) return null;\n",
    replace: "",
  },
  {
    id: "R21-unresolved-intent-abandonable",
    file: "staff/redemptionRecovery/machine.ts",
    search: "    if (s.phase !== \"recovery_required\" || (s.reason !== \"not_recorded\" && s.reason !== \"storage_corrupt\" && s.reason !== \"storage_outdated\") || this.busy) return \"rejected\";",
    replace: "    if (this.busy) return \"rejected\";",
  },
  {
    id: "R22-new-order-while-intent-open",
    file: "staff/redemptionRecovery/machine.ts",
    search: "    if (this.state.phase !== \"idle\" || this.busy || !MEMBER_ID.test(memberId)) return \"rejected\";",
    replace: "    if (this.busy || !MEMBER_ID.test(memberId)) return \"rejected\";",
  },
  {
    id: "R23-session-binding-ignored",
    file: "staff/redemptionRecovery/machine.ts",
    search: "    if (ctx.session_ref !== this.deps.sessionRef) {",
    replace: "    if (false) {",
  },
  {
    id: "R24-persisted-extra-keys-accepted",
    file: "staff/redemptionRecovery/machine.ts",
    search: "  if (keys.length !== PERSISTED_KEYS.length || !PERSISTED_KEYS.every((k) => keys.includes(k))) return null;\n",
    replace: "",
  },
  {
    id: "R25-lost-response-treated-as-failure",
    file: "staff/redemptionRecovery/machine.ts",
    search: "    this.set({ phase: \"redemption_unresolved\", intent, reason: unresolvedOf(err) });",
    replace: "    this.forget();\n    this.set({ phase: \"redemption_refused\", intent, code: \"invalid_request\" });",
  },
  {
    id: "R28-unresolved-intent-finishable",
    file: "staff/redemptionRecovery/machine.ts",
    search: "    if (!settled || this.busy) return \"rejected\";",
    replace: "    if (this.busy) return \"rejected\";",
  },
  {
    id: "R26-earlier-claim-may-carry-a-time",
    file: "staff/redemptionRecovery/wire.ts",
    search: "  } else if (at !== null || time !== \"not_recorded\") {",
    replace: "  } else if (time !== \"not_recorded\") {",
  },
  {
    id: "R27-handoff-double-tap-sent",
    file: "staff/redemptionRecovery/machine.ts",
    search: "    if (this.busy || !HANDOFF_CLAIMS.includes(claim)) return \"rejected\";\n    const allowed = (s.phase === \"recorded_now\" && s.prepareAcknowledged) || (s.phase === \"previously_recorded_unconfirmed\" && s.manuallyVerified);",
    replace: "    if (!HANDOFF_CLAIMS.includes(claim)) return \"rejected\";\n    const allowed = (s.phase === \"recorded_now\" && s.prepareAcknowledged) || (s.phase === \"previously_recorded_unconfirmed\" && s.manuallyVerified) || s.phase === \"handoff_confirmation_pending\";",
  },
  // UI-04B pure-core hardening: manual verification + SHA-256 payload identity (W1-W10).
  {
    id: "W1-replay-handoff-without-manual-verification",
    file: "staff/redemptionRecovery/machine.ts",
    search: "(s.phase === \"previously_recorded_unconfirmed\" && s.manuallyVerified)",
    replace: "s.phase === \"previously_recorded_unconfirmed\"",
  },
  {
    id: "W2-status-read-marks-verified",
    file: "staff/redemptionRecovery/machine.ts",
    search: "        this.set({ phase: \"previously_recorded_unconfirmed\", intent, record: status.record, manuallyVerified: false });",
    replace: "        this.set({ phase: \"previously_recorded_unconfirmed\", intent, record: status.record, manuallyVerified: true });",
  },
  {
    id: "W3-verification-survives-unreadable-status",
    file: "staff/redemptionRecovery/machine.ts",
    search: "  if (before.phase === \"previously_recorded_unconfirmed\") return { ...before, manuallyVerified: false };",
    replace: "  if (before.phase === \"previously_recorded_unconfirmed\") return before;",
  },
  {
    id: "W4-unverified-replay-lists-handoff-actions",
    file: "staff/redemptionRecovery/machine.ts",
    search: "        : [\"confirm_manual_verification\", \"check_status\", \"finish\"];",
    replace: "        : [\"confirm_handed_now\", \"confirm_handed_earlier\", \"check_status\", \"finish\"];",
  },
  {
    id: "W5-malformed-digest-accepted",
    file: "staff/redemptionRecovery/machine.ts",
    search: "      return typeof digest === \"string\" && SHA256_HEX.test(digest) ? digest : null;",
    replace: "      return typeof digest === \"string\" ? digest : null;",
  },
  {
    id: "W6-outdated-record-not-identified",
    file: "staff/redemptionRecovery/machine.ts",
    search: "  if (o.v === 1) return \"outdated\";\n",
    replace: "",
  },
  {
    id: "W7-retry-uses-callers-mutable-object",
    file: "staff/redemptionRecovery/machine.ts",
    search: "    this.payload = frozen;\n    return this.submitRedemption({ memberId, requestId, payloadSha256: digest });",
    replace: "    this.payload = payload;\n    return this.submitRedemption({ memberId, requestId, payloadSha256: digest });",
  },
  {
    id: "W8-session-end-during-hashing-ignored",
    file: "staff/redemptionRecovery/machine.ts",
    search: "    if (guard !== this.seq || this.state.phase !== \"idle\") return \"stale\";",
    replace: "    if (this.state.phase === \"submitting_redemption\") return \"stale\";",
  },
  {
    id: "W9-double-tap-during-hashing",
    file: "staff/redemptionRecovery/machine.ts",
    search: "    this.busy = true;\n    const guard = this.seq;\n    const digest = await this.identityOf(frozen);\n    this.busy = false;\n    if (guard !== this.seq || this.state.phase !== \"idle\") return \"stale\";",
    replace: "    const guard = this.seq;\n    const digest = await this.identityOf(frozen);\n    this.busy = false;\n    if (guard !== this.seq || this.state.phase !== \"idle\") return \"stale\";",
  },
  {
    id: "W10-non-canonical-codes-accepted",
    file: "staff/redemptionRecovery/machine.ts",
    search: " || typeof o.coffeeCode !== \"string\" || !CODE.test(o.coffeeCode)) return null;",
    replace: " || typeof o.coffeeCode !== \"string\") return null;",
  },
  // UI-04G lookup / usual coffee parsers and HTTP reply classification (X1-X15).
  {
    id: "X1-forged-lookup-ref-accepted",
    file: "staff/redemptionRecovery/wire.ts",
    search: " || ref.length > 512 || !LOOKUP_REF.test(ref) ||",
    replace: " || ref.length > 512 ||",
  },
  {
    id: "X2-oversized-lookup-ref-accepted",
    file: "staff/redemptionRecovery/wire.ts",
    search: " || ref.length > 512 || !LOOKUP_REF",
    replace: " || !LOOKUP_REF",
  },
  {
    id: "X3-lookup-member-extra-fields",
    file: "staff/redemptionRecovery/wire.ts",
    search: "if (!isObj(member) || !hasKeys(member, [\"member_public_id\", \"lookup_ref\", \"lookup_ref_expires_at_utc\"])) return null;",
    replace: "if (!isObj(member)) return null;",
  },
  {
    id: "X4-lookup-refusal-with-member",
    file: "staff/redemptionRecovery/wire.ts",
    search: "  if (member !== null || typeof result",
    replace: "  if (typeof result",
  },
  {
    id: "X5-lookup-expiry-not-canonical",
    file: "staff/redemptionRecovery/wire.ts",
    search: " || !isUtcStamp(expires)) return null;",
    replace: ") return null;",
  },
  {
    id: "X6-usual-confirmed-order-accepted",
    file: "staff/redemptionRecovery/wire.ts",
    search: " || raw.confirmed_order !== false) return null;",
    replace: ") return null;",
  },
  {
    id: "X7-usual-basis-impossible",
    file: "staff/redemptionRecovery/wire.ts",
    search: " || (occurrences as number) > (considered as number)) return null;",
    replace: ") return null;",
  },
  {
    id: "X8-usual-modifier-codes-unchecked",
    file: "staff/redemptionRecovery/wire.ts",
    search: "typeof m.group !== \"string\" || !CODE.test(m.group) || typeof m.option !== \"string\" || !CODE.test(m.option) ||",
    replace: "typeof m.group !== \"string\" || typeof m.option !== \"string\" ||",
  },
  {
    id: "X9-usual-free-text-reason",
    file: "staff/redemptionRecovery/wire.ts",
    search: "USUAL_REASONS.includes(reason) ?",
    replace: "reason.length > 0 ?",
  },
  {
    id: "X10-error-envelope-routed-as-domain",
    file: "staff/redemptionRecovery/wire.ts",
    search: "if (isObj(body) && !(\"error\" in body) && ",
    replace: "if (isObj(body) && ",
  },
  {
    id: "X11-unknown-error-code-borrows-auth-status",
    file: "staff/redemptionRecovery/wire.ts",
    search: "if (code === \"unauthorized\" || (code === null && status === 401))",
    replace: "if (code === \"unauthorized\" || status === 401)",
  },
  {
    id: "X12-rate-limit-reported-unavailable",
    file: "staff/redemptionRecovery/wire.ts",
    search: "(code === null && status === 429)) return { kind: \"transport\", failure: \"rate_limited\" };",
    replace: "(code === null && status === 429)) return { kind: \"transport\", failure: \"unavailable\" };",
  },
  {
    id: "X13-contradictory-status-routed-as-domain",
    file: "staff/redemptionRecovery/wire.ts",
    search: "return map[key] === status ? { kind: \"domain\", body } : { kind: \"transport\", failure: \"unavailable\" };",
    replace: "return { kind: \"domain\", body };",
  },
  {
    id: "X14-usual-suggestion-with-reason",
    file: "staff/redemptionRecovery/wire.ts",
    search: "    if (reason !== null || !isObj(s) || !hasKeys(s, [\"catalogue_version\"",
    replace: "    if (!isObj(s) || !hasKeys(s, [\"catalogue_version\"",
  },
  {
    id: "X15-no-suggestion-carrying-suggestion",
    file: "staff/redemptionRecovery/wire.ts",
    search: "  if (s !== null) return null;\n  if (result === \"no_suggestion\")",
    replace: "  if (result === \"no_suggestion\")",
  },
  // UI-04H surface-aware status/body consistency (X13 re-aimed, X16-X17).
  {
    id: "X16-http-401-accepted-as-domain",
    file: "staff/redemptionRecovery/wire.ts",
    search: "if (isObj(body) && !(\"error\" in body) && status !== 401) {",
    replace: "if (isObj(body) && !(\"error\" in body)) {",
  },
  {
    id: "X17-body-of-another-surface-routed",
    file: "staff/redemptionRecovery/wire.ts",
    search: "    const key = surface === \"redemption\" || surface === \"handoff\" ? body.outcome : body.result;\n    const map = CONTRACT_STATUS[surface];",
    replace: "    const key = surface === \"redemption\" || surface === \"handoff\" ? body.outcome : body.result;\n    const map = { ...CONTRACT_STATUS.redemption, ...CONTRACT_STATUS.lookup };",
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
