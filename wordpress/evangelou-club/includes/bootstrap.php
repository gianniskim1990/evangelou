<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

// Loads the Club engine classes in dependency order. Loading registers no
// hooks, opens no connection and writes nothing.
$evc_includes = __DIR__ . '/';
require_once $evc_includes . 'class-evc-clock.php';
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
require_once $evc_includes . 'class-evc-coffee-catalog.php';
require_once $evc_includes . 'class-evc-request-fingerprint.php';
require_once $evc_includes . 'class-evc-redemption-request.php';
require_once $evc_includes . 'class-evc-redemption-result.php';
require_once $evc_includes . 'interface-evc-audit-log.php';
require_once $evc_includes . 'class-evc-db-audit-log.php';
require_once $evc_includes . 'class-evc-redemption-store.php';
require_once $evc_includes . 'class-evc-redemption-service.php';
unset($evc_includes);
