<?php
/**
 * CI helper: (re)creates the disposable WordPress test database on the
 * loopback test server. Refuses any other host or database name.
 */
$host = (string) getenv('EVC_TEST_DB_HOST');
$name = getenv('EVC_WP_TEST_DB_NAME') ?: 'evc_wp_tests';
if (!in_array($host, array('127.0.0.1', 'localhost', '::1'), true) || !preg_match('/^evc_wp_tests[a-z0-9_]*$/D', $name)) {
    fwrite(STDERR, "Refusing non-loopback host or non-test database name.\n");
    exit(2);
}
$port = getenv('EVC_TEST_DB_PORT') === false ? 3306 : (int) getenv('EVC_TEST_DB_PORT');
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
    (string) getenv('EVC_TEST_DB_USER'),
    (string) getenv('EVC_TEST_DB_PASSWORD'),
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
);
$pdo->exec('DROP DATABASE IF EXISTS `' . $name . '`');
$pdo->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
echo "WordPress test database ready: $name\n";
