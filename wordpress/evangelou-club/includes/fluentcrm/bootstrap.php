<?php
defined('ABSPATH') || exit;
// STAGE-06: FluentCRM membership tags. Loading registers nothing. Only EVC_Fluentcrm_Plugin constructs
// the gateway and the sync, and only behind EVC_CLUB_FLUENTCRM_SYNC_ENABLED (exact boolean true; default OFF).
$evc_fluentcrm = __DIR__ . '/';
require_once $evc_fluentcrm . 'class-evc-fluentcrm-feature.php';
require_once $evc_fluentcrm . 'class-evc-fluentcrm-api-gateway.php';
require_once $evc_fluentcrm . 'class-evc-fluentcrm-plugin.php';
unset($evc_fluentcrm);
