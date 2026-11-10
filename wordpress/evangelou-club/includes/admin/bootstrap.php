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
// STAGE-08: the Club admin pages (shared till account, read-only readiness), only behind EVC_CLUB_ADMIN_TOOLS_ENABLED.
require_once $evc_admin . 'class-evc-admin-tools-feature.php';
require_once $evc_admin . 'class-evc-staff-account-reader.php';
require_once $evc_admin . 'class-evc-staff-account-service.php';
require_once $evc_admin . 'class-evc-staff-account-page.php';
require_once $evc_admin . 'class-evc-readiness.php';
require_once $evc_admin . 'class-evc-readiness-page.php';
require_once $evc_admin . 'class-evc-admin-tools-plugin.php';
unset($evc_admin);
