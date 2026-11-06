<?php
defined('ABSPATH') || exit;
// STAGE-07: in-store QR onboarding. Loading registers nothing. Only EVC_Onboarding_Plugin constructs these classes,
// and only behind EVC_CLUB_ONBOARDING_ENABLED (exact boolean true; default OFF) + EVC_CLUB_CUSTOMER_ENABLED.
$evc_onboarding = __DIR__ . '/';
require_once $evc_onboarding . 'class-evc-onboarding-feature.php';
require_once $evc_onboarding . 'class-evc-onboarding-registration.php';
require_once $evc_onboarding . 'class-evc-onboarding-handoff.php';
require_once $evc_onboarding . 'class-evc-onboarding-shell.php';
require_once $evc_onboarding . 'class-evc-rest-join-controller.php';
require_once $evc_onboarding . 'class-evc-onboarding-checkout-guard.php';
require_once $evc_onboarding . 'class-evc-onboarding-plugin.php';
unset($evc_onboarding);
