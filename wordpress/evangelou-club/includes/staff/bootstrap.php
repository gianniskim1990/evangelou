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
require_once $evc_staff . 'class-evc-redemption-backend.php';
require_once $evc_staff . 'class-evc-rest-redeem-controller.php';
require_once $evc_staff . 'class-evc-staff-plugin.php';
unset($evc_staff);
