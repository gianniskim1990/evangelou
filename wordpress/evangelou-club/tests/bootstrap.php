<?php
// PHPUnit bootstrap for the Evangelou Club plugin (no WordPress needed).
define('EVC_STANDALONE_TEST', true);

// Deliberately hostile default timezone (UTC+14): engine code must never
// depend on PHP's default timezone. Individual tests switch it further.
date_default_timezone_set('Pacific/Kiritimati');

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/includes/bootstrap.php';

require_once __DIR__ . '/support/class-evc-fixed-clock.php';
require_once __DIR__ . '/support/class-evc-mock-membership-adapter.php';
require_once __DIR__ . '/support/class-evc-fake-pmpro-reader.php';
require_once __DIR__ . '/support/class-evc-in-memory-provenance-store.php';
require_once __DIR__ . '/support/class-evc-faulty-pdo.php';
require_once __DIR__ . '/support/class-evc-failing-audit-log.php';
require_once __DIR__ . '/support/class-evc-test-database.php';
require_once __DIR__ . '/support/class-evc-db-test-case.php';
