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
        'if (!EVC_Staff_Role::is_restricted_staff_account($user) || ($capability !== null && !user_can($user, $capability))) {', 'if (false) {'),
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
        'if (EVC_Staff_Session::verify_current((int) $user->ID, null, $touch) !== EVC_Staff_Session::OK) {', 'if (false) {'),
    // Task 1C-C.R1: exclusive least-privilege account policy + safe disable targets.
    array('W15-authorize-uses-lifecycle-role-check', 'enabled', $staff . 'class-evc-staff-auth.php',
        'if (!EVC_Staff_Role::is_restricted_staff_account($user) || ($capability !== null && !user_can($user, $capability))) {',
        'if (!EVC_Staff_Role::has_staff_role($user) || ($capability !== null && !user_can($user, $capability))) {'),
    array('W16-extra-role-guard-removed', 'enabled', $staff . 'class-evc-staff-role.php',
        'if (array_values((array) $user->roles) !== array(self::ROLE)) {', 'if (false) {'),
    array('W17-direct-capability-guard-removed', 'enabled', $staff . 'class-evc-staff-role.php',
        'if ((array) $user->caps !== array(self::ROLE => true)) {', 'if (false) {'),
    array('W18-capability-allowlist-removed', 'enabled', $staff . 'class-evc-staff-role.php',
        'if ($granted && !in_array((string) $cap, $allowed, true)) {', 'if (false) {'),
    array('W19-dynamic-privilege-probe-removed', 'enabled', $staff . 'class-evc-staff-role.php',
        'if (user_can($user, $cap)) {', 'if (false) {'),
    array('W20-disable-target-validation-removed', 'enabled', $staff . 'class-evc-staff-auth.php',
        'if (!($target instanceof WP_User) || !EVC_Staff_Role::has_staff_role($target)) {', 'if (false) {'),
    array('W21-disable-target-accepts-any-user', 'enabled', $staff . 'class-evc-staff-auth.php',
        'if (!($target instanceof WP_User) || !EVC_Staff_Role::has_staff_role($target)) {', 'if (!($target instanceof WP_User)) {'),
    array('W22-throttle-limited-to-strict-accounts', 'enabled', $staff . 'class-evc-login-throttle.php',
        'return EVC_Staff_Role::has_staff_role($user) ? $user : null;', 'return EVC_Staff_Role::is_restricted_staff_account($user) ? $user : null;'),
    // Task 1C-D: protected /club-admin/ shell, assets and session endpoints.
    array('W23-shell-feature-gate-removed', 'disabled', $staff . 'class-evc-staff-shell.php',
        "if (!EVC_Staff_Feature::enabled()) {\n            return self::error_page(404", "if (false) {\n            return self::error_page(404"),
    array('W24-shell-account-check-skipped', 'enabled', $staff . 'class-evc-staff-shell.php',
        '$error = EVC_Staff_Auth::check_account($user, null, true);', '$error = null;'),
    array('W25-shell-anonymous-not-redirected', 'enabled', $staff . 'class-evc-staff-shell.php',
        'if (!$user->exists()) {', 'if (false) {'),
    array('W26-nonce-leaked-on-denied-pages', 'enabled', $staff . 'class-evc-staff-shell.php',
        "'<title>Ευαγγέλου Club</title></head><body><p>'", "'<title>Ευαγγέλου Club</title></head><body><p>' . wp_create_nonce('wp_rest')"),
    array('W27-status-endpoint-extends-inactivity', 'enabled', $staff . 'class-evc-staff-auth.php',
        'return self::authorize_rest($request, null, false);', 'return self::authorize_rest($request, null, true);'),
    array('W28-asset-name-validation-removed', 'enabled', $staff . 'class-evc-staff-assets.php',
        'if (!is_string($relative) || !preg_match(self::ASSET_PATTERN, $relative)) {', 'if (!is_string($relative)) {'),
    array('W29-asset-existence-check-removed', 'enabled', $staff . 'class-evc-staff-assets.php',
        'if ($real === false || $base === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {', 'if (false) {'),
    array('W30-session-end-leaves-session-alive', 'enabled', $staff . 'class-evc-rest-session-controller.php',
        "        EVC_Staff_Session::revoke_current();\n", ''),
    array('W31-rewrite-flushed-on-every-request', 'enabled', $staff . 'class-evc-staff-shell.php',
        "        if (\$current === \$state) {\n            return;\n        }\n", ''),
    // UI-04H v2 boundary WordPress adapter (inactive in production; exercised by the test-only harness).
    array('W32-v2-output-shaping-params-not-read', 'enabled', 'includes/staff/class-evc-rest-v2-security.php',
        "'transport_params' => count(array_intersect(array_keys(\$query), EVC_Rest_V2_Envelope_Policy::TRANSPORT_PARAMS)) > 0,",
        "'transport_params' => false,"),
    array('W33-v2-envelope-decision-not-applied', 'enabled', 'includes/staff/class-evc-rest-v2-security.php',
        "            \$response = EVC_Rest_Security::error_response(\$decision['code'], \$decision['status']);\n",
        ""),
    array('W34-v2-any-response-treated-as-sealed', 'enabled', 'includes/staff/class-evc-rest-v2-security.php',
        "\$sealed = \$response instanceof EVC_Rest_V2_Domain_Response ? \$response->evc_seal() : null;",
        "\$sealed = \$response instanceof EVC_Rest_V2_Domain_Response ? \$response->evc_seal() : (is_array(\$response->get_data()) && \$response->get_matched_route() ? array('route_id' => (string) (EVC_Rest_V2_Routes::designated(\$response->get_matched_route(), \$request->get_method())['id'] ?? ''), 'surface' => (string) (EVC_Rest_V2_Routes::designated(\$response->get_matched_route(), \$request->get_method())['surface'] ?? ''), 'body' => \$response->get_data(), 'status' => (int) \$response->get_status()) : null);"),
    array('W35-domain-response-accepts-any-body', 'enabled', 'includes/staff/class-evc-rest-v2-security.php',
        "        if (\$route === null || !EVC_Contract_Envelope_Rule::is_domain_body_for(\$route['surface'], \$body)) {",
        "        if (\$route === null) {"),
    // STAGE-01 customer surface (mode "customer": customer flag ON, staff flag OFF).
    array('W36-customer-account-check-skipped', 'customer', 'includes/customer/class-evc-customer-auth.php',
        "        \$error = self::check_customer(\$user);", "        \$error = null;"),
    array('W37-customer-nonce-check-removed', 'customer', 'includes/customer/class-evc-customer-auth.php',
        "if (!is_string(\$nonce) || \$nonce === '' || !wp_verify_nonce(\$nonce, 'wp_rest')) {", "if (false) {"),
    array('W38-me-user-taken-from-request', 'customer', 'includes/customer/class-evc-rest-me-controller.php',
        "\$entitlement = \$this->adapter->entitlement_for((int) \$user->ID, \$at);", "\$entitlement = \$this->adapter->entitlement_for((int) (\$request->get_param('user_id') ?: \$user->ID), \$at);"),
    array('W39-unavailable-source-grants-membership', 'customer', 'includes/customer/class-evc-unavailable-membership-adapter.php',
        "        throw new EVC_Membership_Source_Exception('Membership source not configured.');", "        return new EVC_Entitlement(EVC_Entitlement::STATUS_ACTIVE, true, \$at_utc->modify('+1 month'), null, 'pmpro');"),
    array('W40-customer-flag-accepts-truthy-values', 'customer', 'includes/customer/class-evc-customer-feature.php',
        "        return \$value === true;", "        return (bool) \$value;"),
    array('W41-member-page-account-check-skipped', 'customer', 'includes/customer/class-evc-customer-shell.php',
        "        if (EVC_Customer_Auth::check_customer(\$user) instanceof WP_Error) {", "        if (false) {"),
    array('W42-member-page-flag-gate-removed', 'disabled', 'includes/customer/class-evc-customer-shell.php',
        "        if (!EVC_Customer_Feature::enabled()) {\n            return self::error_page(404", "        if (false) {\n            return self::error_page(404"),
    array('W43-privileged-capabilities-accepted', 'customer', 'includes/customer/class-evc-customer-auth.php',
        "            if (user_can(\$user, \$capability)) {", "            if (false) {"),
    // STAGE-02 real commerce source (mode "commerce": REAL WooCommerce 11.2.1 + PMPro 3.8.9 + PMPro
    // WooCommerce 1.11 from EVC_TEST_COMMERCE_PLUGINS; customer flag ON). Run with --mode=commerce.
    // STAGE-03: the customer bound now lives in the (HPOS) SQL; a broken bound must surface as a conflict.
    array('C1-other-customers-orders-counted', 'commerce', 'includes/customer/class-evc-wp-commerce-pmpro-reader.php',
        "' o ON o.id = oi.order_id AND o.customer_id = %d';", "' o ON o.id = oi.order_id AND %d > 0';"),
    array('C2-non-bank-transfer-order-accepted', 'commerce', 'includes/customer/class-evc-wp-commerce-pmpro-reader.php',
        "            return 'club_order_not_bank_transfer';", "            return null;"),
    array('C3-unknown-order-status-guessed', 'commerce', 'includes/customer/class-evc-wp-commerce-pmpro-reader.php',
        "            return 'club_order_unknown_status';", "            return null;"),
    array('C4-processing-not-awaiting', 'commerce', 'includes/customer/class-evc-wp-commerce-pmpro-reader.php',
        "    const PENDING_STATUSES = array('pending', 'on-hold', 'processing');", "    const PENDING_STATUSES = array('pending', 'on-hold');"),
    array('C5-on-hold-ignored-like-completed', 'commerce', 'includes/customer/class-evc-wp-commerce-pmpro-reader.php',
        "    const NOT_EVIDENCE_STATUSES = array('completed');", "    const NOT_EVIDENCE_STATUSES = array('completed', 'on-hold');"),
    array('C6-site-timezone-assumed', 'commerce', 'includes/customer/class-evc-wp-commerce-pmpro-reader.php',
        "        \$timezone = (string) get_option('timezone_string', '');", "        \$timezone = 'Europe/Athens';"),
    array('C7-second-product-granting-the-level-accepted', 'commerce', 'includes/customer/class-evc-wp-commerce-pmpro-reader.php',
        "                    \$c[] = 'club_level_granted_by_another_product';", ""),
    array('C8-not-sold-individually-accepted', 'commerce', 'includes/customer/class-evc-wp-commerce-pmpro-reader.php',
        "            \$c[] = 'club_product_not_sold_individually';", ""),
    array('C9-recurring-level-accepted', 'commerce', 'includes/customer/class-evc-wp-commerce-pmpro-reader.php',
        "            \$c[] = 'club_level_has_recurring_billing';", ""),
    array('C10-autocomplete-accepted', 'commerce', 'includes/customer/class-evc-wp-commerce-pmpro-reader.php',
        "            \$c[] = 'club_product_autocompletes_orders';", ""),
    array('C11-source-config-coerces-types', 'commerce', 'includes/customer/class-evc-commerce-source-config.php',
        "        if (!is_int(\$level_id) || \$level_id < 1 || !is_int(\$product_id) || \$product_id < 1) {",
        "        if ((int) \$level_id < 1 || (int) \$product_id < 1) {"),
    array('C12-configured-source-not-used', 'commerce', 'includes/customer/class-evc-customer-plugin.php',
        "        if (\$config === null) {", "        if (true) {"),
    // STAGE-03 trusted BACS confirmation boundary and provenance read adapter.
    array('BC1-capability-not-required', 'commerce', 'includes/customer/class-evc-bacs-payment-confirmation.php',
        "!user_can(\$actor, self::CAPABILITY) || ", ""),
    array('BC2-shared-staff-account-accepted', 'commerce', 'includes/customer/class-evc-bacs-payment-confirmation.php',
        " || EVC_Staff_Role::has_staff_role(\$actor)) {", ") {"),
    array('BC3-own-order-accepted', 'commerce', 'includes/customer/class-evc-bacs-payment-confirmation.php',
        "        if (\$customer_id === \$actor_user_id) {", "        if (false) {"),
    array('BC4-order-status-not-required', 'commerce', 'includes/customer/class-evc-bacs-payment-confirmation.php',
        "        if ((string) \$order->get_status() !== 'completed') {", "        if (false) {"),
    array('BC5-movement-identified-by-order-not-transfer', 'commerce', 'includes/customer/class-evc-bacs-payment-confirmation.php',
        "\$movement_id = \$this->config->ref('bacs_transfer', \$reference);", "\$movement_id = \$this->config->ref('bacs_transfer', (string) \$order->get_id());"),
    array('BC6-retry-uses-a-new-instant', 'commerce', 'includes/customer/class-evc-bacs-payment-confirmation.php',
        "\$existing !== null ? \$existing->verified_at_utc() : \$this->whole_second_now(),", "\$this->whole_second_now(),"),
    array('BC7-statement-amount-not-checked', 'commerce', 'includes/customer/class-evc-bacs-payment-confirmation.php',
        "        if ((\$input['amount_minor'] ?? null) !== self::AMOUNT_MINOR || (\$input['currency'] ?? null) !== EVC_Commerce_Source_Config::CURRENCY) {", "        if (false) {"),
    array('BC8-value-date-window-not-checked', 'commerce', 'includes/customer/class-evc-bacs-payment-confirmation.php',
        "|| \$received_on < \$created->setTimezone(\$zone)->format('Y-m-d') || \$received_on > \$today) {", ") {"),
    array('RA1-recorded-refund-ignored', 'commerce', 'includes/customer/class-evc-store-club-payment-provenance.php',
        "        if (\$history === array()) {\n            return EVC_Pmpro_Payment_Fact::STATUS_CONFIRMED;", "        if (true) {\n            return EVC_Pmpro_Payment_Fact::STATUS_CONFIRMED;"),
);

/** Output of the most recent suite run (printed only when the baseline fails). */
$evc_last_output = '';

function evc_wp_suite(string $root, string $mode): int {
    global $evc_last_output;
    if ($mode === 'customer') {
        $suite = 'wp-customer';
        $env = array_merge(getenv(), array('EVC_TEST_STAFF_FLAG' => 'disabled', 'EVC_TEST_CUSTOMER_FLAG' => 'enabled'));
    } elseif ($mode === 'commerce') {
        $suite = 'wp-commerce';
        $env = array_merge(getenv(), array('EVC_TEST_STAFF_FLAG' => 'disabled', 'EVC_TEST_CUSTOMER_FLAG' => 'enabled'));
    } else {
        $suite = $mode === 'enabled' ? 'wp-common,wp-enabled' : 'wp-common,wp-disabled';
        $env = array_merge(getenv(), array('EVC_TEST_STAFF_FLAG' => $mode, 'EVC_TEST_CUSTOMER_FLAG' => 'disabled'));
    }
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
    $evc_last_output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc);
}

// Default: the staff and customer modes (unchanged CI step). --mode=commerce runs ONLY the STAGE-02
// commerce mutants, which need the real plugins (EVC_TEST_COMMERCE_PLUGINS) and their own CI job.
$evc_commerce_only = in_array('--mode=commerce', $argv, true);
$mutants = array_values(array_filter($mutants, function ($m) use ($evc_commerce_only) {
    return ($m[1] === 'commerce') === $evc_commerce_only;
}));
if ($evc_commerce_only && (string) getenv('EVC_TEST_COMMERCE_PLUGINS') === '') {
    fwrite(STDERR, "ABORT: --mode=commerce needs EVC_TEST_COMMERCE_PLUGINS.\n");
    exit(1);
}

$touched = array();
foreach ($mutants as $m) {
    $touched[$root . '/' . $m[2]] = hash_file('sha256', $root . '/' . $m[2]);
}

foreach ($evc_commerce_only ? array('commerce') : array('disabled', 'enabled', 'customer') as $mode) {
    echo "Baseline ($mode)...\n";
    if (evc_wp_suite($root, $mode) !== 0) {
        fwrite(STDERR, "ABORT: baseline WordPress suite ($mode) is not green.\n");
        fwrite(STDERR, substr($evc_last_output, -6000) . "\n");
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
    // Working copies may use CRLF (Windows); patterns are written with LF.
    $normalized = str_replace("\r\n", "\n", $original);
    $count = substr_count($normalized, $search);
    if ($count !== 1) {
        fwrite(STDERR, sprintf("ABORT: %s pattern found %d times in %s (expected 1).\n", $id, $count, $file));
        exit(1);
    }
    try {
        file_put_contents($path, str_replace($search, $replace, $normalized));
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
printf("[evidence] wp-auth-mutation%s: total=%d killed=%d survived=%d sources_restored=%s\n", $evc_commerce_only ? ' (commerce)' : '', count($mutants), $killed, count($survivors), $restored ? 'yes' : 'NO');
if ($survivors) {
    fwrite(STDERR, 'Surviving mutants: ' . implode(', ', $survivors) . "\n");
}
exit($survivors || !$restored ? 1 : 0);
