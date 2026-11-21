<?php
defined('ABSPATH') || exit;
// STAGE-08 round 12 (owner decision D26 part 2): member web push notifications. Loading registers nothing. Only
// EVC_Push_Plugin constructs these classes, and only behind EVC_CLUB_PUSH_ENABLED (exact boolean true; default OFF).
$evc_push = __DIR__ . '/';
require_once $evc_push . 'class-evc-push-feature.php';
require_once $evc_push . 'class-evc-push-backend.php';
require_once $evc_push . 'class-evc-push-sender.php';
require_once $evc_push . 'class-evc-push-campaign.php';
require_once $evc_push . 'class-evc-push-admin-page.php';
require_once $evc_push . 'class-evc-push-auto.php';
require_once $evc_push . 'class-evc-rest-me-push-controller.php';
require_once $evc_push . 'class-evc-push-plugin.php';
unset($evc_push);
