<?php
/**
 * Mutation tests for the staff authorization layer, run against the REAL
 * WordPress integration suite. Each mutant applies ONE exact source edit and
 * runs the suite in the flag mode whose tests must catch it; the suite must
 * FAIL. Sources are restored in `finally` and verified by SHA-256.
 *
 * Usage (plugin dir, WordPress test DB env set): php tests/mutation/run-wp-mutations.php
 */
$root = dirname(__DIR__, 2);
$staff = 'includes/staff/';

$mutants = array(
    array('W1-capability-check-bypassed', 'enabled', $staff . 'class-evc-staff-auth.php',
        'if (!EVC_Staff_Role::is_staff_user($user) || !user_can($user, $capability)) {', 'if (false) {'),
    array('W2-own-nonce-check-removed', 'enabled', $staff . 'class-evc-staff-auth.php',
        "if (!is_string(\$nonce) || \$nonce === '' || !wp_verify_nonce(\$nonce, 'wp_rest')) {", 'if (false) {'),
    array('W3-flag-enabled-when-undefined', 'disabled', $staff . 'class-evc-staff-feature.php',
        'return defined(self::CONSTANT) && self::is_enabled_value(constant(self::CONSTANT));', 'return true;'),
    array('W4-flag-accepts-truthy-strings', 'enabled', $staff . 'class-evc-staff-feature.php',
        'return $value === true;', 'return (bool) $value;'),
    array('W5-absolute-expiry-ignored', 'enabled', $staff . 'class-evc-staff-session.php',
        'if ($now >= $login + self::ABSOLUTE_SECONDS || $now >= $expiration) {', 'if (false) {'),
    array('W6-inactivity-timeout-ignored', 'enabled', $staff . 'class-evc-staff-session.php',
        'if ($now - $last >= self::IDLE_SECONDS) {', 'if (false) {'),
    array('W7-disabled-account-accepted', 'enabled', $staff . 'class-evc-staff-auth.php',
        'if (self::is_disabled((int) $user->ID)) {', 'if (false) {'),
    array('W8-staff-id-from-client', 'enabled', $staff . 'class-evc-rest-redeem-controller.php',
        'get_current_user_id(),', "isset(\$body['staff_wp_user_id']) ? (int) \$body['staff_wp_user_id'] : get_current_user_id(),"),
    array('W9-unavailable-backend-gate-bypassed', 'enabled', $staff . 'class-evc-rest-redeem-controller.php',
        "            if (\$service === null) {\n                return EVC_Rest_Security::error_response('server_error', 503);\n            }\n", ''),
    array('W10-permission-callback-allows-all', 'enabled', $staff . 'class-evc-rest-redeem-controller.php',
        "    public function permission(WP_REST_Request \$request) {\n        return EVC_Staff_Auth::authorize(\$request, EVC_Staff_Role::CAP_REDEEM);",
        "    public function permission(WP_REST_Request \$request) {\n        return true;"),
    array('W11-activity-shared-across-sessions', 'enabled', $staff . 'class-evc-staff-session.php',
        "hash_hmac('sha256', 'evc-session-activity|' . \$token, wp_salt('auth'))", "hash_hmac('sha256', 'evc-session-activity|', wp_salt('auth'))"),
    array('W12-path-member-overridable-by-body', 'enabled', $staff . 'class-evc-rest-redeem-controller.php',
        "isset(\$url['member_id']) ? (string) \$url['member_id'] : '',", "(string) \$request->get_param('member_id'),"),
    array('W13-login-throttle-disabled', 'enabled', $staff . 'class-evc-login-throttle.php',
        'if (self::is_locked((int) $staff->ID, self::client_ip(), time())) {', 'if (false) {'),
    array('W14-session-check-skipped', 'enabled', $staff . 'class-evc-staff-auth.php',
        'if (EVC_Staff_Session::verify_current((int) $user->ID) !== EVC_Staff_Session::OK) {', 'if (false) {'),
);

function evc_wp_suite(string $root, string $mode): int {
    $suite = $mode === 'enabled' ? 'wp-common,wp-enabled' : 'wp-common,wp-disabled';
    $env = array_merge(getenv(), array('EVC_TEST_STAFF_FLAG' => $mode));
    $proc = proc_open(
        array(PHP_BINARY, $root . '/vendor/bin/phpunit', '-c', $root . '/phpunit-wp.xml.dist', '--testsuite', $suite),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $root,
        $env
    );
    if (!is_resource($proc)) {
        return 255;
    }
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc);
}

$touched = array();
foreach ($mutants as $m) {
    $touched[$root . '/' . $m[2]] = hash_file('sha256', $root . '/' . $m[2]);
}

foreach (array('disabled', 'enabled') as $mode) {
    echo "Baseline ($mode)...\n";
    if (evc_wp_suite($root, $mode) !== 0) {
        fwrite(STDERR, "ABORT: baseline WordPress suite ($mode) is not green.\n");
        exit(1);
    }
}
echo "Baseline: PASS\n";

$survivors = array();
$killed = 0;
foreach ($mutants as $m) {
    list($id, $mode, $file, $search, $replace) = $m;
    $path = $root . '/' . $file;
    $original = file_get_contents($path);
    $count = substr_count($original, $search);
    if ($count !== 1) {
        fwrite(STDERR, sprintf("ABORT: %s pattern found %d times in %s (expected 1).\n", $id, $count, $file));
        exit(1);
    }
    try {
        file_put_contents($path, str_replace($search, $replace, $original));
        $exit = evc_wp_suite($root, $mode);
    } finally {
        file_put_contents($path, $original);
    }
    if ($exit !== 0) {
        $killed++;
    } else {
        $survivors[] = $id;
    }
    printf("%-42s [%s] %s (exit %d)\n", $id, $mode, $exit !== 0 ? 'KILLED' : 'SURVIVED', $exit);
}

$restored = true;
foreach ($touched as $path => $hash) {
    if (hash_file('sha256', $path) !== $hash) {
        $restored = false;
        fwrite(STDERR, 'NOT RESTORED: ' . $path . "\n");
    }
}
printf("[evidence] wp-auth-mutation: total=%d killed=%d survived=%d sources_restored=%s\n", count($mutants), $killed, count($survivors), $restored ? 'yes' : 'NO');
if ($survivors) {
    fwrite(STDERR, 'Surviving mutants: ' . implode(', ', $survivors) . "\n");
}
exit($survivors || !$restored ? 1 : 0);
