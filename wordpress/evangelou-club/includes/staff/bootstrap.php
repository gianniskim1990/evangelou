<?php
defined('ABSPATH') || exit;

// WordPress-only staff authentication foundation (Task 1C-C). Loaded by the
// plugin entrypoint, never by the standalone engine tests.
$evc_staff = __DIR__ . '/';
require_once $evc_staff . 'class-evc-staff-feature.php';
require_once $evc_staff . 'class-evc-staff-role.php';
require_once $evc_staff . 'class-evc-staff-session.php';
require_once $evc_staff . 'class-evc-staff-auth.php';
require_once $evc_staff . 'class-evc-login-throttle.php';
require_once $evc_staff . 'class-evc-staff-admin-guard.php';
require_once $evc_staff . 'class-evc-rest-security.php';
require_once $evc_staff . 'class-evc-rest-v2-security.php';
require_once $evc_staff . 'class-evc-redemption-backend.php';
require_once $evc_staff . 'class-evc-club-redemption-feature.php';
require_once $evc_staff . 'class-evc-redemption-catalogue-source.php';
require_once $evc_staff . 'class-evc-club-redemption-backend.php';
require_once $evc_staff . 'class-evc-rest-redeem-controller.php';
// STAGE-05C: staff QR scan + administrator QR revocation (routes only with EVC_CLUB_REDEMPTION_SOURCE).
require_once $evc_staff . 'class-evc-club-lookup-backend.php';
require_once $evc_staff . 'class-evc-rest-lookup-controller.php';
require_once $evc_staff . 'class-evc-rest-session-controller.php';
require_once $evc_staff . 'class-evc-staff-assets.php';
require_once $evc_staff . 'class-evc-staff-shell.php';
require_once $evc_staff . 'class-evc-staff-plugin.php';
unset($evc_staff);
