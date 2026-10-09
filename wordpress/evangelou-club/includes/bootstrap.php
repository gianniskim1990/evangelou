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
require_once $evc_includes . 'class-evc-entitlement.php';
require_once $evc_includes . 'interface-evc-membership-adapter.php';
require_once $evc_includes . 'class-evc-coffee-catalog.php';
require_once $evc_includes . 'class-evc-request-fingerprint.php';
require_once $evc_includes . 'class-evc-redemption-request.php';
require_once $evc_includes . 'class-evc-redemption-result.php';
require_once $evc_includes . 'interface-evc-audit-log.php';
require_once $evc_includes . 'class-evc-db-audit-log.php';
require_once $evc_includes . 'class-evc-redemption-store.php';
require_once $evc_includes . 'class-evc-redemption-service.php';
unset($evc_includes);
