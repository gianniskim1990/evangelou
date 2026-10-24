<?php
defined('ABSPATH') || exit;
// WordPress-only customer surface (STAGE-01). Loaded by the plugin entrypoint
// after the staff foundation (it reuses EVC_Staff_Role, EVC_Rest_Security and
// EVC_Staff_Assets), never by the standalone engine tests.
$evc_customer = __DIR__ . '/';
require_once $evc_customer . 'class-evc-customer-feature.php';
require_once $evc_customer . 'class-evc-customer-auth.php';
require_once $evc_customer . 'class-evc-unavailable-membership-adapter.php';
require_once $evc_customer . 'class-evc-commerce-source-config.php';
require_once $evc_customer . 'class-evc-club-payment-provenance.php';
require_once $evc_customer . 'class-evc-wp-commerce-pmpro-reader.php';
require_once $evc_customer . 'class-evc-store-club-payment-provenance.php';
require_once $evc_customer . 'class-evc-bacs-payment-confirmation.php';
// STAGE-03B.2: intent persistence, member lock and read-only recovery inspection. Nothing constructs them.
require_once $evc_customer . 'class-evc-member-lock.php';
require_once $evc_customer . 'class-evc-wc-bacs-intent-store.php';
require_once $evc_customer . 'class-evc-bacs-recovery-inspector.php';
// STAGE-03B.3a: internal issued -> recorded finalization. Nothing constructs it.
require_once $evc_customer . 'class-evc-bacs-payment-finalizer.php';
require_once $evc_customer . 'class-evc-customer-rest-security.php';
require_once $evc_customer . 'class-evc-rest-me-controller.php';
require_once $evc_customer . 'class-evc-customer-shell.php';
require_once $evc_customer . 'class-evc-customer-plugin.php';
unset($evc_customer);
