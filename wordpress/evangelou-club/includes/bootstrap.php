<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

// Loads the Club engine classes in dependency order. Loading registers no
// hooks, opens no connection and writes nothing.
$evc_includes = __DIR__ . '/';
require_once $evc_includes . 'class-evc-clock.php';
// UI-04B strict canonical UTC timestamps (DATETIME(6) text).
require_once $evc_includes . 'class-evc-utc-timestamp.php';
require_once $evc_includes . 'interface-evc-clock-source.php';
require_once $evc_includes . 'class-evc-system-clock.php';
require_once $evc_includes . 'class-evc-qr-token.php';
require_once $evc_includes . 'class-evc-member-id.php';
require_once $evc_includes . 'class-evc-db-exception.php';
require_once $evc_includes . 'class-evc-db-config.php';
require_once $evc_includes . 'class-evc-club-db.php';
require_once $evc_includes . 'class-evc-migrator.php';
require_once $evc_includes . 'class-evc-payment-evidence.php';
require_once $evc_includes . 'class-evc-entitlement.php';
require_once $evc_includes . 'interface-evc-membership-adapter.php';
// Task 1D-B: pure membership-time rules + PMPro fact contract and mapper.
// Definitions only; nothing here is wired to the production backend.
require_once $evc_includes . 'class-evc-membership-calendar.php';
require_once $evc_includes . 'class-evc-evidence-ref.php';
require_once $evc_includes . 'class-evc-membership-source-exception.php';
require_once $evc_includes . 'class-evc-pmpro-membership-row.php';
require_once $evc_includes . 'class-evc-pmpro-payment-fact.php';
require_once $evc_includes . 'class-evc-pmpro-snapshot.php';
require_once $evc_includes . 'interface-evc-pmpro-reader.php';
require_once $evc_includes . 'class-evc-pmpro-mapper-config.php';
require_once $evc_includes . 'class-evc-pmpro-entitlement-mapper.php';
require_once $evc_includes . 'class-evc-pmpro-membership-adapter.php';
// Task 1D-E: pure payment-provenance recorder core. Definitions only; no
// store, hook or gateway is wired anywhere.
require_once $evc_includes . 'class-evc-movement-alias.php';
require_once $evc_includes . 'class-evc-verified-movement.php';
require_once $evc_includes . 'class-evc-funded-period.php';
require_once $evc_includes . 'class-evc-movement-correction.php';
require_once $evc_includes . 'class-evc-movement-reconciliation.php';
require_once $evc_includes . 'class-evc-provenance-write.php';
require_once $evc_includes . 'interface-evc-provenance-store.php';
require_once $evc_includes . 'class-evc-record-outcome.php';
require_once $evc_includes . 'class-evc-provenance-recorder.php';
// Task 1D-G: SQL store definition only; nothing constructs it outside tests.
require_once $evc_includes . 'class-evc-sql-provenance-store.php';
// Task 1D-I: canonical payment identity core. Definitions only: no verifier
// implementation, key material or payment flow exists in the plugin.
require_once $evc_includes . 'class-evc-payment-source-ref.php';
require_once $evc_includes . 'class-evc-payment-key-ring.php';
require_once $evc_includes . 'class-evc-payment-identity.php';
require_once $evc_includes . 'class-evc-payment-evidence-gate.php';
require_once $evc_includes . 'class-evc-payment-identity-resolver.php';
require_once $evc_includes . 'class-evc-coffee-catalog.php';
require_once $evc_includes . 'class-evc-request-fingerprint.php';
require_once $evc_includes . 'class-evc-redemption-request.php';
require_once $evc_includes . 'class-evc-redemption-result.php';
require_once $evc_includes . 'interface-evc-audit-log.php';
require_once $evc_includes . 'class-evc-db-audit-log.php';
require_once $evc_includes . 'class-evc-redemption-store.php';
require_once $evc_includes . 'class-evc-redemption-service.php';
// UI-03C preparation contract core: definitions only, NOT wired to REST or the
// engine. Structured options only (no free text); the only catalogue is a
// test-only illustrative fixture, which the precheck refuses outside tests.
require_once $evc_includes . 'class-evc-prep-catalogue.php';
require_once $evc_includes . 'class-evc-prep-contract.php';
require_once $evc_includes . 'class-evc-prep-redeem-precheck.php';
// UI-03D.A atomic redemption + preparation transaction model: definitions only,
// NOT wired. No store or catalogue authority implementation ships (the packager
// rejects one); only test fakes exist.
require_once $evc_includes . 'class-evc-prep-snapshot.php';
require_once $evc_includes . 'class-evc-prepared-redemption.php';
// UI-03D.C safe fulfillment contract: handoff confirmations are a separate
// statement about a redemption, never a coffee. Definitions only, NOT wired.
require_once $evc_includes . 'class-evc-handoff.php';
// UI-03E usual-coffee recommendation core: derived from verified history only,
// read-only, never an order. Definitions only, NOT wired.
require_once $evc_includes . 'class-evc-usual-coffee.php';
// UI-04C QR tokens, secure member lookup and signed lookup-context proof:
// definitions only, NOT wired (no keys, store, rate limiter, route or UI).
require_once $evc_includes . 'class-evc-member-lookup.php';
// UI-04F member identity + trusted synchronisation core: signals are hints,
// identity never follows payment state. Definitions only, NOT wired.
require_once $evc_includes . 'class-evc-member-sync.php';
// UI-04G pure v2 JSON response contracts + HTTP status recommendations.
// Definitions only: no route, no WP_REST_Response, no hook.
require_once $evc_includes . 'class-evc-response-contract.php';
// UI-04H v2 route table + route-aware envelope policy (pure, never registered).
require_once $evc_includes . 'class-evc-rest-v2-boundary.php';
unset($evc_includes);
