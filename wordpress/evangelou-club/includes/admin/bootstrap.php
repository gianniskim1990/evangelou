<?php
defined('ABSPATH') || exit;
// STAGE-04A: Club-DB migration status + guarded apply for administrators (Tools page, WP-CLI).
// Loading registers nothing. Only EVC_Club_Migrations_Plugin constructs these classes, and only
// behind EVC_CLUB_MIGRATIONS_ENABLED (exact boolean true; default OFF).
$evc_admin = __DIR__ . '/';
require_once $evc_admin . 'class-evc-club-migrations-feature.php';
require_once $evc_admin . 'class-evc-club-migrations-service.php';
require_once $evc_admin . 'class-evc-club-migrations-admin-page.php';
require_once $evc_admin . 'class-evc-club-migrations-cli.php';
require_once $evc_admin . 'class-evc-club-migrations-plugin.php';
unset($evc_admin);
