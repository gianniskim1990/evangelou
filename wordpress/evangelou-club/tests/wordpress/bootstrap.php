<?php
/**
 * Bootstrap for the REAL WordPress integration suite (wp-phpunit + a
 * disposable MySQL/MariaDB database). The staff feature flag is fixed per
 * PHPUnit process by EVC_TEST_STAFF_FLAG=enabled|disabled, exactly like
 * wp-config.php would fix it on a server; production code has no override.
 */
$evc_root = dirname(__DIR__, 2);
require $evc_root . '/vendor/autoload.php';

$evc_mode = getenv('EVC_TEST_STAFF_FLAG');
if ($evc_mode === 'enabled') {
    define('EVC_CLUB_STAFF_ENABLED', true);
} elseif ($evc_mode !== 'disabled') {
    fwrite(STDERR, "Set EVC_TEST_STAFF_FLAG=enabled or EVC_TEST_STAFF_FLAG=disabled.\n");
    exit(1);
}

define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php');
define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $evc_root . '/vendor/yoast/phpunit-polyfills');

$evc_tests_dir = getenv('WP_PHPUNIT__DIR') ?: $evc_root . '/vendor/wp-phpunit/wp-phpunit';
require_once $evc_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function () use ($evc_root) {
    require $evc_root . '/evangelou-club.php';
});
// CLI tests must never try to emit real cookies.
tests_add_filter('send_auth_cookies', '__return_false');

require $evc_tests_dir . '/includes/bootstrap.php';

// Engine test support (real Club DB harness + TEST-ONLY mock adapter).
require_once $evc_root . '/tests/support/class-evc-fixed-clock.php';
require_once $evc_root . '/tests/support/class-evc-mock-membership-adapter.php';
require_once $evc_root . '/tests/support/class-evc-test-database.php';
require_once __DIR__ . '/class-evc-wp-test-case.php';
