<?php
/**
 * WordPress test-suite configuration for the Evangelou Club plugin.
 * DISPOSABLE TEST DATABASE ONLY: the WordPress test installer DROPS every
 * table in DB_NAME. Loopback hosts and the evc_wp_tests* name are enforced.
 */
$evc_host = getenv('EVC_TEST_DB_HOST') ?: '127.0.0.1';
$evc_name = getenv('EVC_WP_TEST_DB_NAME') ?: 'evc_wp_tests';
if (!in_array($evc_host, array('127.0.0.1', 'localhost', '::1'), true) || strpos($evc_name, 'evc_wp_tests') !== 0) {
    fwrite(STDERR, "Refusing to run WordPress tests against a non-loopback host or non-test database.\n");
    exit(1);
}

define('ABSPATH', dirname(__DIR__, 2) . '/vendor/johnpbloch/wordpress-core/');
define('WP_DEFAULT_THEME', 'default');
define('WP_DEBUG', true);

define('DB_NAME', $evc_name);
define('DB_USER', getenv('EVC_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('EVC_TEST_DB_PASSWORD') ?: '');
define('DB_HOST', $evc_host . ':' . (getenv('EVC_TEST_DB_PORT') ?: '3306'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// Test-only salts (never used anywhere else).
define('AUTH_KEY', 'evc-test-only-auth-key');
define('SECURE_AUTH_KEY', 'evc-test-only-secure-auth-key');
define('LOGGED_IN_KEY', 'evc-test-only-logged-in-key');
define('NONCE_KEY', 'evc-test-only-nonce-key');
define('AUTH_SALT', 'evc-test-only-auth-salt');
define('SECURE_AUTH_SALT', 'evc-test-only-secure-auth-salt');
define('LOGGED_IN_SALT', 'evc-test-only-logged-in-salt');
define('NONCE_SALT', 'evc-test-only-nonce-salt');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Evangelou Club Test');
define('WP_PHP_BINARY', 'php');
define('WPLANG', '');
