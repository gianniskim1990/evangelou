<?php
/**
 * Mutation tests for the staff authorization layer, run against the REAL
 * WordPress integration suite. Each mutant applies ONE exact source edit and
 * runs the suite in the flag mode whose tests must catch it; the suite must
 * FAIL. Sources are restored in `finally` and verified by SHA-256.
 *
 * A mutant may name KILLER tests (6th element, "Class::method"; STAGE-03B.1): it
 * then counts as killed only if each of them FAILED AN ASSERTION in the JUnit
 * log, so a broken setup or an unrelated exception is reported as INVALID
 * instead of being counted as a kill (tests/mutation/lib-mutation-evidence.php).
 *
 * Usage (plugin dir, WordPress test DB env set): php tests/mutation/run-wp-mutations.php
 */
$root = dirname(__DIR__, 2);
// STAGE-03B.2: undo a mutant left behind by a KILLED earlier run before anything else.
require_once __DIR__ . '/lib-mutation-journal.php';
$evc_journal = evc_mutation_journal_path($root);
try {
    if (evc_mutation_journal_recover($evc_journal) === 'restored') {
        fwrite(STDERR, "Restored a source file left mutated by an interrupted run.\n");
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, 'ABORT: ' . $e->getMessage() . "\n");
    exit(1);
}
require_once __DIR__ . '/lib-mutation-evidence.php';
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
    // STAGE-03B.1: must be caught by the wiring ASSERTIONS, not by an "unavailable" exception elsewhere.
    array('C12-configured-source-not-used', 'commerce', 'includes/customer/class-evc-customer-plugin.php',
        "        if (\$config === null) {", "        if (true) {",
        array('CommerceMembershipSourceTest::test_configured_constants_select_the_real_source', 'CommerceMembershipSourceTest::test_configured_source_is_the_one_answering_me')),
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
    // STAGE-03B.2: order-meta intent store, member lock, read-only recovery inspector.
    array('IS1-duplicate-intent-rows-accepted', 'commerce', 'includes/customer/class-evc-wc-bacs-intent-store.php',
        '            if (count($rows) > 1) {', '            if (false) {'),
    array('IS2-second-reference-replaces-first-intent', 'commerce', 'includes/customer/class-evc-wc-bacs-intent-store.php',
        ": new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::CONFLICT, 'different_intent_stored');", ': $this->write($lock, $intent->order_id(), $intent, $current->raw);'),
    array('IS3-unverifiable-intent-overwritten', 'commerce', 'includes/customer/class-evc-wc-bacs-intent-store.php',
        "            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::CONFLICT, 'stored_intent_invalid:' . \$current->reason);", '            return $this->write($lock, $intent->order_id(), $intent, null);'),
    array('IS4-create-without-member-lock', 'commerce', 'includes/customer/class-evc-wc-bacs-intent-store.php',
        '        if ($lock->wp_user_id() !== $intent->customer_id() || !$lock->is_held()) {', '        if (false) {'),
    array('IS5-transition-without-member-lock', 'commerce', 'includes/customer/class-evc-wc-bacs-intent-store.php',
        '        if ($lock->wp_user_id() !== $expected->customer_id() || !$lock->is_held()) {', '        if (false) {'),
    array('IS6-transition-without-compare-and-set', 'commerce', 'includes/customer/class-evc-wc-bacs-intent-store.php',
        '        if ($current->outcome !== EVC_Bacs_Intent_Read::VALID || $current->raw !== $this->codec->encode($expected)) {', '        if ($current->outcome !== EVC_Bacs_Intent_Read::VALID) {'),
    array('IS7-write-reported-without-read-back', 'commerce', 'includes/customer/class-evc-wc-bacs-intent-store.php',
        '        if ($after->outcome === EVC_Bacs_Intent_Read::VALID && $after->raw === $value) {', '        if (true) {'),
    array('IS8-failed-meta-read-taken-as-missing', 'commerce', 'includes/customer/class-evc-wc-bacs-intent-store.php',
        "        if (\$wpdb->last_error !== '') {\n            throw new RuntimeException('Order metadata could not be read.');", "        if (false) {\n            throw new RuntimeException('Order metadata could not be read.');"),
    array('IS9-cached-meta-trusted', 'commerce', 'includes/customer/class-evc-wc-bacs-intent-store.php',
        '        $order->read_meta_data(true);', '        $order->read_meta_data(false);'),
    array('IS10-unknown-write-reported-as-failed', 'commerce', 'includes/customer/class-evc-wc-bacs-intent-store.php',
        "        return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::OUTCOME_UNKNOWN, \$after->outcome === EVC_Bacs_Intent_Read::UNAVAILABLE ? 'read_back_failed' : 'unexpected_value_after_write');", "        return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::WRITE_FAILED, 'not_persisted');"),
    array('IL1-busy-lock-reported-as-acquired', 'commerce', 'includes/customer/class-evc-member-lock.php',
        "        if (\$db->last_error !== '' || (string) \$got !== '1') {", "        if (\$db->last_error !== '') {"),
    array('IL2-lock-ownership-assumed', 'commerce', 'includes/customer/class-evc-member-lock.php',
        "            && \$row['owner'] !== null && (string) \$row['owner'] === (string) \$row['me'] && (string) \$row['me'] === \$this->connection_id;", '            && true;'),
    array('IL3-release-does-not-release', 'commerce', 'includes/customer/class-evc-member-lock.php',
        "        \$result = \$this->db->get_var(\$this->db->prepare('SELECT RELEASE_LOCK(%s)', \$this->name));", "        \$result = '1';"),
    array('II1-shared-staff-account-may-recover', 'commerce', 'includes/customer/class-evc-bacs-recovery-inspector.php',
        '            && !EVC_Staff_Role::has_staff_role($actor);', '            ;'),
    array('II2-recovery-ignores-recorded-movement', 'commerce', 'includes/customer/class-evc-bacs-recovery-inspector.php',
        '                $movement = $this->store->movement($i->movement_id());', '                $movement = null;'),
    // STAGE-03B.3a: internal issued -> recorded finalization.
    array('BF1-attestation-not-bound-to-intent', 'commerce', 'includes/customer/class-evc-bacs-payment-finalizer.php',
        '        if (!hash_equals($intent->movement_id(), $attested_movement)) {', '        if (false) {'),
    array('BF2-money-after-confirmation-accepted', 'commerce', 'includes/customer/class-evc-bacs-payment-finalizer.php',
        ' || $received_on > $today || $received_on > $confirmed) {', ' || $received_on > $today) {'),
    array('BF3-finalizer-actor-check-removed', 'commerce', 'includes/customer/class-evc-bacs-payment-finalizer.php',
        '        if (!$actor instanceof WP_User || !user_can($actor, EVC_Bacs_Payment_Confirmation::CAPABILITY) || EVC_Staff_Role::has_staff_role($actor)) {', '        if (!$actor instanceof WP_User) {'),
    array('BF4-attested-amount-not-checked', 'commerce', 'includes/customer/class-evc-bacs-payment-finalizer.php',
        "        if ((\$attestation['amount_minor'] ?? null) !== EVC_Bacs_Payment_Confirmation::AMOUNT_MINOR || (\$attestation['currency'] ?? null) !== EVC_Commerce_Source_Config::CURRENCY) {", '        if (false) {'),
    array('BF5-recorded-without-expected-period', 'commerce', 'includes/customer/class-evc-bacs-payment-finalizer.php',
        '        $outcome = $this->recorder->record_expecting($movement, $intent->as_plan());', '        $outcome = $this->recorder->record($movement);'),
    array('BF6-unknown-commit-not-read-back', 'commerce', 'includes/customer/class-evc-bacs-payment-finalizer.php',
        '        $period = $this->recorded_exactly($movement, $intent);', '        $period = $outcome->period();'),
    array('BF7-lock-not-rechecked-before-recording', 'commerce', 'includes/customer/class-evc-bacs-payment-finalizer.php',
        "        if (!\$lock->is_held()) {\n            return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::UNAVAILABLE, 'lock_lost');\n        }\n        \$movement = \$this->movement_for(\$intent);", '        $movement = $this->movement_for($intent);'),
    // BF8 (issued-only guard removed) dropped as EQUIVALENT: a record_movement decision already implies an
    // issued intent whose row equals the decision's row (EVC_Bacs_Recovery_Policy, RP16), and the remaining
    // row-ref clause still refuses every other step. The guard stays as defense in depth.
    array('BF9-recorded-payment-intent-left-behind', 'commerce', 'includes/customer/class-evc-bacs-payment-finalizer.php',
        '            return $this->finish_intent($lock, $intent, $facts->recorded_period, EVC_Bacs_Finalization_Result::ALREADY_RECORDED);', '            return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::ALREADY_RECORDED, null, $facts->recorded_period, true);'),
    array('BF10-failed-intent-transition-reported-done', 'commerce', 'includes/customer/class-evc-bacs-payment-finalizer.php',
        '        $done = in_array($write->outcome, array(EVC_Bacs_Intent_Write::WRITTEN, EVC_Bacs_Intent_Write::ALREADY), true);', '        $done = true;'),
    array('BF11-blocking-decision-ignored', 'commerce', 'includes/customer/class-evc-bacs-payment-finalizer.php',
        "        if (\$decision->outcome !== EVC_Bacs_Recovery_Decision::RESUME) {\n            return self::from_decision(\$decision);\n        }\n", ''),
    array('BF12-read-back-ignores-signed-period', 'commerce', 'includes/customer/class-evc-bacs-payment-finalizer.php',
        ' || !$intent->as_plan()->matches_recorded($period)', ''),
    // STAGE-03B.3b: issuance orchestrator (Option 2) and its request-scoped guard. Named killers (C12 rule):
    // each must FAIL AN ASSERTION, not error. Not added (documented in memory.md): dropping the start
    // date's single quotes (EQUIVALENT: pmpro_changeMembershipLevel strips single quotes anyway) and
    // PRIORITY = 10, which is NOT equivalent: at 10 the guard still runs after the add-on's extension
    // only because it is registered later (registration order), and it would run BEFORE any third-party
    // callback at 11-999 instead of after it; not killable with the shipped callbacks alone (a later
    // date change would still be caught by the fresh re-read, i.e. fail closed as issuance_mismatch).
    array('IG1-deactivate-filter-unscoped', 'commerce', 'includes/customer/class-evc-bacs-issuance-guard.php',
        '        if ($this->armed && $this->matched && $this->changing === array($this->user_id, $this->level_id)) {', '        if ($this->armed) {',
        array('BacsIssuerTest::test_the_guard_keeps_the_paid_row_only_for_the_armed_change')),
    array('IG2-deactivate-filter-left-on', 'commerce', 'includes/customer/class-evc-bacs-issuance-guard.php',
        "        remove_filter('pmpro_deactivate_old_levels', array(\$this, 'deactivate_old_levels'), self::PRIORITY);\n", '',
        array('BacsIssuerTest::test_disarming_removes_every_guard_callback')),
    array('IG3-customer-match-removed', 'commerce', 'includes/customer/class-evc-bacs-issuance-guard.php',
        '            || (int) $level[\'user_id\'] !== $this->user_id || (int) $level[\'membership_id\'] !== $this->level_id) {', '            || (int) $level[\'membership_id\'] !== $this->level_id) {',
        array('BacsIssuerTest::test_the_guard_changes_dates_only_for_the_armed_customer_and_level')),
    array('IG4-level-match-removed', 'commerce', 'includes/customer/class-evc-bacs-issuance-guard.php',
        '            || (int) $level[\'user_id\'] !== $this->user_id || (int) $level[\'membership_id\'] !== $this->level_id) {', '            || (int) $level[\'user_id\'] !== $this->user_id) {',
        array('BacsIssuerTest::test_the_guard_changes_dates_only_for_the_armed_customer_and_level')),
    array('IG5-priority-before-add-on-extension', 'commerce', 'includes/customer/class-evc-bacs-issuance-guard.php',
        '    const PRIORITY = 1000;', '    const PRIORITY = 9;',
        array('BacsIssuerTest::test_early_renewal_keeps_the_paid_row_active_until_the_new_row_exists')),
    array('IG6-start-quoted-with-wrong-quote', 'commerce', 'includes/customer/class-evc-bacs-issuance-guard.php',
        "        \$level['startdate'] = \"'\" . \$this->startdate . \"'\";", "        \$level['startdate'] = '\"' . \$this->startdate . '\"';",
        array('BacsIssuerTest::test_initial_purchase_is_issued_with_the_exact_athens_period_and_recorded')),
    array('IB1-guard-not-disarmed', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        "        } finally {\n            \$this->guard->disarm();\n        }", "        } finally {\n        }",
        array('BacsIssuerTest::test_the_guard_is_disarmed_even_when_completion_throws')),
    array('IB2-issued-before-read-back', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        "                \$stop = \$this->armed(\$lock, \$intent, \$order_id, function () use (\$order_id) {\n                    \$current = wc_get_order(\$order_id);\n                    if (\$current instanceof WC_Order) {\n                        \$current->update_status('completed');\n                    }\n                }, \$unguarded);\n                if (\$stop !== null) {\n                    return \$stop;\n                }\n",
        "                \$stop = \$this->armed(\$lock, \$intent, \$order_id, function () use (\$order_id) {\n                    \$current = wc_get_order(\$order_id);\n                    if (\$current instanceof WC_Order) {\n                        \$current->update_status('completed');\n                    }\n                }, \$unguarded);\n                if (\$stop !== null) {\n                    return \$stop;\n                }\n                global \$wpdb;\n                \$newest = \$wpdb->get_var(\$wpdb->prepare(\"SELECT MAX(id) FROM {\$wpdb->pmpro_memberships_users} WHERE user_id = %d AND status = 'active'\", \$intent->customer_id()));\n                \$this->intents->transition(\$lock, \$intent, \$intent->issued(\$this->config->ref('pmpro_membership_row', (string) \$newest)));\n",
        array('BacsIssuerTest::test_a_mismatching_issuance_is_never_confirmed')),
    array('IB3-lock-released-before-finalization', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        '                $done = $this->finalizer->finalize_holding(', "                \$lock->release();\n                \$done = \$this->finalizer->finalize_holding(",
        array('BacsIssuerTest::test_the_member_lock_is_held_through_the_club_commit')),
    array('IB4-reissue-without-guard', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        "                \$stop = \$this->armed(\$lock, \$intent, \$order_id, function () use (\$order_id) {\n                    // Called directly (no WooCommerce status change): name the order ourselves.\n                    \$this->guard->completing(\$order_id);\n                    pmprowoo_add_membership_from_order(\$order_id);\n                }, \$unguarded);",
        "                pmprowoo_add_membership_from_order(\$order_id);\n                \$stop = null;",
        array('BacsIssuerTest::test_a_failed_pmpro_insert_keeps_the_paid_membership_and_the_resume_reissues')),
    array('IB5-level-group-not-checked', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        "        return \$allow !== null && (int) \$allow === 0 && (int) \$others > 0 ? 'club_level_group_not_exclusive' : null;", '        return null;',
        array('BacsIssuerTest::test_the_club_level_must_not_share_a_single_selection_level_group')),
    array('IB6-unrepresentable-period-planned', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        "        if (EVC_Pmpro_Local_Time::exact(\$run_start, EVC_Membership_Calendar::LATEST) === null\n            || EVC_Pmpro_Local_Time::exact(\$plan->end_utc(), EVC_Membership_Calendar::EARLIEST) === null) {",
        '        if (false) {',
        array('BacsIssuerTest::test_a_confirmation_in_the_repeated_autumn_hour_is_refused_before_anything_is_written')),
    array('IB7-second-issuance-in-one-request', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        '                && $intent->state() === EVC_Bacs_Intent::STATE_PLANNED && !$acted) {', '                && $intent->state() === EVC_Bacs_Intent::STATE_PLANNED) {',
        array('BacsIssuerTest::test_a_failed_pmpro_insert_keeps_the_paid_membership_and_the_resume_reissues')),
    array('IB8-attestation-not-bound-on-resume', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        '            if (!hash_equals($intent->movement_id(), $attested_movement)) {', '            if (false) {',
        array('BacsIssuerTest::test_the_attestation_must_be_valid_and_match_the_attempt')),
    array('IB9-paid-row-dates-not-checked', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        "                || EVC_Pmpro_Local_Time::instant(\$row->enddate_local, EVC_Membership_Calendar::EARLIEST) != \$plan->start_utc()) {", '                ) {',
        array('BacsIssuerTest::test_an_early_renewal_needs_the_paid_row_exactly_as_recorded')),
    // STAGE-03B.3b review fixes (M1, M2, L1, L2 and the test gaps), each with named killers.
    array('IG7-guard-not-scoped-to-order', 'commerce', 'includes/customer/class-evc-bacs-issuance-guard.php',
        '        if (!$this->armed || $this->current_order_id !== $this->order_id || !is_array($level)', '        if (!$this->armed || !is_array($level)',
        array('BacsIssuerTest::test_the_guard_changes_dates_only_while_the_armed_order_is_processed', 'BacsIssuerTest::test_a_second_club_order_completed_in_the_same_request_never_gets_the_planned_dates')),
    array('IG8-order-recorded-after-add-on', 'commerce', 'includes/customer/class-evc-bacs-issuance-guard.php',
        '    const ORDER_PRIORITY = 1;', '    const ORDER_PRIORITY = 11;',
        array('BacsIssuerTest::test_initial_purchase_is_issued_with_the_exact_athens_period_and_recorded', 'BacsIssuerTest::test_disarming_removes_every_guard_callback')),
    array('IG9-no-shutdown-disarm', 'commerce', 'includes/customer/class-evc-bacs-issuance-guard.php',
        "        add_action('shutdown', array(\$this, 'disarm'), self::SHUTDOWN_PRIORITY, 0);\n", '',
        array('BacsIssuerTest::test_disarming_removes_every_guard_callback')),
    array('IB10-environment-not-checked-in-preflight', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        "        \$environment = self::environment_problem(\$config);\n        if (\$environment !== null) {\n            return \$environment;\n        }\n", '',
        array('BacsIssuerTest::test_nothing_is_written_unless_the_commerce_environment_is_the_pinned_one')),
    array('IB11-environment-not-rechecked-before-arming', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        "        \$environment = self::environment_problem(\$this->config);\n        if (\$environment !== null) {\n            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, \$environment);\n        }\n", '',
        array('BacsIssuerTest::test_the_commerce_environment_is_checked_again_immediately_before_issuing')),
    array('IB12-applied-count-ignored', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        '        $unguarded = $this->guard->applied() !== 1;', '        $unguarded = false;',
        array('BacsIssuerTest::test_an_issuance_not_replaced_exactly_once_is_never_confirmed')),
    array('IB13-reissue-order-not-named', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        "                    \$this->guard->completing(\$order_id);\n", '',
        array('BacsIssuerTest::test_a_failed_pmpro_insert_keeps_the_paid_membership_and_the_resume_reissues')),
    array('IB14-lock-customer-not-checked-before-arming', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        '        if ($lock->wp_user_id() !== $intent->customer_id()) {', '        if (false) {',
        array('BacsIssuerTest::test_the_lock_must_be_this_customers_and_held_before_arming')),
    array('IB15-lock-not-rechecked-before-arming', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        "        if (!\$lock->is_held()) {\n            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::UNAVAILABLE, 'lock_lost');\n        }\n        \$start", '        $start',
        array('BacsIssuerTest::test_the_lock_must_be_this_customers_and_held_before_arming')),
    array('IB16-lock-not-rechecked-per-step', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        "            if (!\$lock->is_held()) {\n                return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::UNAVAILABLE, 'lock_lost');", "            if (false) {\n                return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::UNAVAILABLE, 'lock_lost');",
        array('BacsIssuerTest::test_a_lock_lost_during_the_request_stops_before_the_next_step')),
    array('IB17-unexpected-error-escapes', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        "            \$this->guard->disarm();\n            \$result = new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::UNAVAILABLE, 'unexpected_error');", '            throw $e;',
        array('BacsIssuerTest::test_an_unexpected_error_fails_closed_and_is_audited_without_details')),
    array('IB18-site-timezone-not-checked', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        "        if ((string) get_option('timezone_string', '') !== EVC_Membership_Calendar::ZONE) {\n            return 'site_timezone_not_athens';", "        if (false) {\n            return 'site_timezone_not_athens';",
        array('BacsIssuerTest::test_the_site_must_run_on_athens_time')),
    array('IB19-level-group-read-error-ignored', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        "        if (\$wpdb->last_error !== '') {\n            return 'level_group_unreadable';\n        }\n        if (\$group === null) {", "        if (\$group === null) {",
        array('BacsIssuerTest::test_an_unreadable_level_group_stops_before_anything_is_written')),
    // STAGE-03D: WooCommerce «Completed» in the admin by shop staff = payment confirmation (named killers, C12 rule).
    // Actor and channel checks, add-on withholding and put-back, environment gate, tripwire, lock, idempotency, refund
    // correction, re-completion, exceptions and claims, recovery, constant gate, cron, reader checks, issuer `processing`.
    array('WC1-nobody-attests', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            return \'no_user\'; // REST API keys, cron, automation',
        '            return null;',
        array('WcCompletionTest::test_completion_by_nobody_the_customer_the_club_account_or_without_capability_is_not_a_confirmation')),
    array('WC2-customer-attests-own-order', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            return \'customer\';',
        '            return null;',
        array('WcCompletionTest::test_completion_by_nobody_the_customer_the_club_account_or_without_capability_is_not_a_confirmation')),
    array('WC3-shared-club-account-attests', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            return \'club_staff_account\';',
        '            return null;',
        array('WcCompletionTest::test_completion_by_nobody_the_customer_the_club_account_or_without_capability_is_not_a_confirmation')),
    array('WC4-capability-not-required', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            return \'no_capability\';',
        '            return null;',
        array('WcCompletionTest::test_completion_by_nobody_the_customer_the_club_account_or_without_capability_is_not_a_confirmation')),
    array('WC5-add-on-not-withheld', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            $this->hold_add_on($order_id);
            $from =',
        '            $from =',
        array('WcCompletionTest::test_the_add_on_is_withheld_for_a_club_order_and_put_back_for_the_next_one', 'WcCompletionTest::test_a_refused_completion_leaves_nothing_behind_and_a_corrected_order_is_confirmed_later', 'WcCompletionTest::test_initial_purchase_completed_by_shop_staff_is_recorded_with_the_exact_athens_period')),
    array('WC6-add-on-never-put-back', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            add_action(self::ADDON_HOOK, self::ADDON_FUNCTION, self::ADDON_PRIORITY, 1);',
        '            // never put back',
        array('WcCompletionTest::test_the_add_on_is_withheld_for_a_club_order_and_put_back_for_the_next_one')),
    array('WC7-add-on-put-back-before-its-priority', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '    const AFTER_ADDON_PRIORITY = 11;',
        '    const AFTER_ADDON_PRIORITY = 9;',
        array('WcCompletionTest::test_the_add_on_is_withheld_for_a_club_order_and_put_back_for_the_next_one', 'WcCompletionTest::test_initial_purchase_completed_by_shop_staff_is_recorded_with_the_exact_athens_period')),
    array('WC8-nothing-restored-at-shutdown', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        foreach (array_keys($this->held) as $order_id) {
            $this->release_add_on((int) $order_id);
        }',
        '',
        array('WcCompletionTest::test_the_add_on_is_put_back_at_shutdown_even_when_nothing_after_it_ran')),
    array('WC9-withheld-counter-not-counted', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            $this->withheld++; // counted for Club-product orders only',
        '',
        array('WcCompletionTest::test_the_add_on_is_withheld_for_a_club_order_and_put_back_for_the_next_one')),
    array('WC10-runs-without-the-members-lock', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        $lock = EVC_Member_Lock::acquire($this->db, $customer_id, $this->lock_wait);',
        '        $lock = EVC_Member_Lock::acquire($this->db, $customer_id, $this->lock_wait) ?? EVC_Member_Lock::acquire($this->db, $customer_id + 1000000, 0);',
        array('WcCompletionTest::test_a_busy_member_lock_defers_and_the_recovery_finishes_it')),
    array('WC11-recorded-payment-not-recognised', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            $done = $decision->outcome === EVC_Bacs_Recovery_Decision::ALREADY_COMPLETE;',
        '            $done = false;',
        array('WcCompletionTest::test_completing_an_already_recorded_order_again_never_adds_a_month')),
    array('WC12-movement-not-keyed-by-the-order', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        return $this->config->ref(self::MOVEMENT_NAMESPACE, (string) $order_id);',
        '        return $this->config->ref(self::MOVEMENT_NAMESPACE, $order_id . \':\' . microtime(true));',
        array('WcCompletionTest::test_initial_purchase_completed_by_shop_staff_is_recorded_with_the_exact_athens_period')),
    array('WC13-correction-not-recorded', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '                $outcome = self::recorder_for($store, $this->config)->correct($correction);',
        '                $outcome = EVC_Record_Outcome::recorded(null);',
        array('WcCompletionTest::test_cancelling_a_recorded_order_freezes_the_card', 'WcCompletionTest::test_a_full_refund_of_a_renewal_freezes_the_card_although_pmpro_keeps_the_row')),
    array('WC14-cancellation-not-a-void', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            $kind = $status === \'refunded\' ? EVC_Movement_Correction::KIND_REFUNDED : EVC_Movement_Correction::KIND_VOIDED;
        }
        // A later',
        '            $kind = $status === \'refunded\' ? EVC_Movement_Correction::KIND_REFUNDED : EVC_Movement_Correction::KIND_PARTIALLY_REFUNDED;
        }
        // A later',
        array('WcCompletionTest::test_cancelling_a_recorded_order_freezes_the_card')),
    array('WC15-partial-refund-taken-as-full', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        if ($remaining > 0.004) {',
        '        if (false) {',
        array('WcCompletionTest::test_a_partial_refund_is_only_flagged_for_a_manager')),
    array('WC16-recompletion-after-cancellation-accepted', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        if (in_array((string) $from_status, self::ENDED, true) || $this->markers->was_ended($order_id) || (float) $order->get_total_refunded() > 0) {',
        '        if (false) {',
        array('WcCompletionTest::test_completing_again_after_a_cancellation_or_refund_is_never_a_confirmation')),
    array('WC17-ended-flag-ignored', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        ' || $this->markers->was_ended($order_id) || (float)',
        ' || (float)',
        array('WcCompletionTest::test_completing_again_after_a_cancellation_or_refund_is_never_a_confirmation')),
    array('WC18-error-escapes-the-claimed-confirmation', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            $this->guard->disarm();
            if (!$claimed) {
                $this->claim($order_id, $customer, $actor, $completed_at); // best effort: the recovery re-reads everything
            }
            $r = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, \'unexpected_error\');',
        '            throw $e;',
        array('WcCompletionTest::test_an_exception_inside_the_confirmation_never_aborts_woocommerce', 'WcCompletionTest::test_an_exception_during_an_early_renewal_never_lets_the_add_on_add_a_month')),
    array('WC19-error-escapes-the-callback', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            $this->last = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, \'unexpected_error\');
            if ($this->is_club_order($order_id) === false) {',
        '            throw $e;
            if ($this->is_club_order($order_id) === false) {',
        array('WcCompletionTest::test_an_error_before_the_completion_is_claimed_never_escapes_into_woocommerce')),
    array('WC20-error-before-the-claim-leaves-no-marker', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            $this->flag_after_error($order_id, $transition);',
        '',
        array('WcCompletionTest::test_an_error_before_the_completion_is_claimed_never_escapes_into_woocommerce')),
    array('WC21-claim-not-written-before-the-pipeline', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '                $claimed = true;
                $claim = $this->claim($order_id, $customer, $actor, $completed_at);',
        '                $claimed = true;
                $claim = null;',
        array('WcCompletionTest::test_a_crash_after_the_issuance_is_finished_by_the_recovery_without_a_second_month')),
    array('WC22-add-on-withheld-only-when-environment-unknown', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        } catch (Throwable $e) {
            // Unknown: fail closed, the add-on stays withheld (the recovery re-checks the environment).',
        '        } catch (Throwable $e) {
            return; // unknown: not withheld',
        array('WcCompletionTest::test_an_exception_inside_the_confirmation_never_aborts_woocommerce')),
    array('WC23-late-renewal-before-expiry-not-deferred', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        return $previous !== null && $previous->pmpro_row_ref() === $active[0]->row_ref && $end !== null && $end <= $verified_at;',
        '        return false;',
        array('WcCompletionTest::test_a_late_renewal_before_pmpro_expired_the_old_row_waits_for_that_expiry')),
    array('WC24-retries-unbounded', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '                $value = $used < self::MAX_ATTEMPTS ?',
        '                $value = true ?',
        array('WcCompletionTest::test_automatic_retries_are_bounded')),
    array('WC25-issued-without-the-guard', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '                $stop = $this->armed($lock, $intent, $order_id, function () use ($order_id) {
                    $this->guard->completing($order_id);
                    pmprowoo_add_membership_from_order($order_id);
                }, $unguarded);',
        '                pmprowoo_add_membership_from_order($order_id);
                $stop = null;',
        array('WcCompletionTest::test_initial_purchase_completed_by_shop_staff_is_recorded_with_the_exact_athens_period')),
    array('WC26-guard-not-told-the-order', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '                    $this->guard->completing($order_id);
                    pmprowoo_add_membership_from_order($order_id);',
        '                    pmprowoo_add_membership_from_order($order_id);',
        array('WcCompletionTest::test_initial_purchase_completed_by_shop_staff_is_recorded_with_the_exact_athens_period')),
    array('WC27-constant-gate-open', 'commerce', 'includes/customer/class-evc-wc-completion-feature.php',
        '        return defined(self::CONSTANT) && self::is_enabled_value(constant(self::CONSTANT));',
        '        return true;',
        array('WcCompletionTest::test_with_the_constant_off_nothing_is_hooked_scheduled_or_written')),
    array('WC28-bootstrap-ignores-the-constant', 'commerce', 'includes/customer/class-evc-wc-completion-plugin.php',
        '        if (!EVC_Wc_Completion_Feature::enabled()) {
            return;
        }
',
        '',
        array('WcCompletionTest::test_with_the_constant_off_nothing_is_hooked_scheduled_or_written')),
    array('WC29-reader-ignores-the-order-total', 'commerce', 'includes/customer/class-evc-wp-commerce-pmpro-reader.php',
        '        if ((string) wc_format_decimal($order->get_total(), 2) !== EVC_Commerce_Source_Config::PRICE) {',
        '        if (false) {',
        array('CommerceMembershipSourceTest::test_a_club_order_whose_total_is_not_20_eur_is_a_source_conflict')),
    array('WC30-reader-ignores-an-ended-recorded-order', 'commerce', 'includes/customer/class-evc-wp-commerce-pmpro-reader.php',
        '                if (in_array($status, self::FAILED_STATUSES, true) && $settled[$ref] === EVC_Pmpro_Payment_Fact::STATUS_CONFIRMED) {',
        '                if (false) {',
        array('WcCompletionTest::test_a_correction_that_cannot_be_recorded_now_is_finished_by_the_recovery')),
    array('WC31-issuer-confirms-processing', 'commerce', 'includes/customer/class-evc-bacs-payment-issuer.php',
        '    const AWAITING = array(\'pending\', \'on-hold\');',
        '    const AWAITING = array(\'pending\', \'on-hold\', \'processing\');',
        array('BacsIssuerTest::test_orders_not_awaiting_payment_are_not_issued')),
    array('WC32-channel-not-required', 'commerce', 'includes/class-evc-wc-completion-context.php',
        '    public static function problem(string $channel, $transition): ?string {
        if (!in_array($channel, self::ATTESTING, true)) {
            return \'channel_\' . $channel;
        }
',
        '    public static function problem(string $channel, $transition): ?string {
',
        array('WcCompletionTest::test_only_a_manual_completion_in_the_woocommerce_admin_is_a_confirmation')),
    array('WC33-manual-flag-ignored', 'commerce', 'includes/class-evc-wc-completion-context.php',
        '($transition[\'manual\'] ?? null) !== true',
        'false',
        array('WcCompletionTest::test_only_a_manual_completion_in_the_woocommerce_admin_is_a_confirmation')),
    array('WC34-rest-not-detected', 'commerce', 'includes/class-evc-wc-completion-context.php',
        '        $rest = $rest_depth > 0 || (defined(\'REST_REQUEST\') && REST_REQUEST) || (function_exists(\'wp_is_serving_rest_request\') && wp_is_serving_rest_request());',
        '        $rest = false;',
        array('WcCompletionTest::test_only_a_manual_completion_in_the_woocommerce_admin_is_a_confirmation')),
    array('WC35-cron-not-detected', 'commerce', 'includes/class-evc-wc-completion-context.php',
        '        $cron = function_exists(\'wp_doing_cron\') && wp_doing_cron();',
        '        $cron = false;',
        array('WcCompletionTest::test_only_a_manual_completion_in_the_woocommerce_admin_is_a_confirmation')),
    array('WC36-admin-ajax-not-attested', 'commerce', 'includes/class-evc-wc-completion-context.php',
        '            return $admin ? self::CHANNEL_ADMIN_AJAX : self::CHANNEL_AJAX;',
        '            return self::CHANNEL_AJAX;',
        array('WcCompletionTest::test_only_a_manual_completion_in_the_woocommerce_admin_is_a_confirmation')),
    array('WC37-environment-gate-open', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            if (EVC_Bacs_Payment_Issuer::environment_problem($this->config) !== null) {
                return;
            }
        } catch',
        '            if (false) {
                return;
            }
        } catch',
        array('WcCompletionTest::test_outside_the_pinned_environment_the_club_does_not_take_over')),
    array('WC38-tripwire-off', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '                $this->tripwire($order_id, $customer);',
        '',
        array('WcCompletionTest::test_an_endless_active_club_row_after_the_add_on_is_flagged_for_a_manager')),
    array('WC39-tripwire-always-flags', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        if ($endless === 0) {
            return;
        }',
        '        if (false) {
            return;
        }',
        array('WcCompletionTest::test_initial_purchase_completed_by_shop_staff_is_recorded_with_the_exact_athens_period')),
    array('WC40-cancellation-hook-after-the-add-on', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '    const ENDED_PRIORITY = 5;',
        '    const ENDED_PRIORITY = 15;',
        array('WcCompletionTest::test_a_cancellation_is_recorded_even_when_a_later_cancellation_handler_fails')),
    array('WC41-refund-arithmetic', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        $remaining = (float) $order->get_total() - (float) $order->get_total_refunded();',
        '        $remaining = (float) $order->get_total() + (float) $order->get_total_refunded();',
        array('WcCompletionTest::test_a_full_refund_of_a_renewal_freezes_the_card_although_pmpro_keeps_the_row')),
    array('WC42-open-follow-ups-include-manager-flags', 'commerce', 'includes/customer/class-evc-wc-completion-markers.php',
        '            $wpdb->esc_like(self::OPEN_PREFIX) . \'%\',',
        '            \'%\',',
        array('WcCompletionTest::test_automatic_retries_are_bounded')),
    array('WC43-audit-without-the-channel', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            \'channel\' => $channel,
',
        '',
        array('WcCompletionTest::test_completion_by_nobody_the_customer_the_club_account_or_without_capability_is_not_a_confirmation', 'WcCompletionTest::test_only_a_manual_completion_in_the_woocommerce_admin_is_a_confirmation')),
    array('WC44-payment-method-not-checked', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        if ((string) $order->get_payment_method() !== EVC_Commerce_Source_Config::PAYMENT_METHOD) {',
        '        if (false) {',
        array('WcCompletionTest::test_a_refused_completion_leaves_nothing_behind_and_a_corrected_order_is_confirmed_later')),
    array('WC45-quantity-not-checked', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        ' || (int) $items[0]->get_quantity() !== 1) {',
        ') {',
        array('WcCompletionTest::test_a_refused_completion_leaves_nothing_behind_and_a_corrected_order_is_confirmed_later')),
    array('WC46-channel-problem-ignored', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        'self::actor_problem($actor, $customer) ?? $channel_problem;',
        'self::actor_problem($actor, $customer);',
        array('WcCompletionTest::test_only_a_manual_completion_in_the_woocommerce_admin_is_a_confirmation')),
    array('WC47-from-status-ignored', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        if (in_array((string) $from_status, self::ENDED, true) || ',
        '        if (',
        array('WcCompletionTest::test_a_completion_right_after_a_cancellation_the_club_did_not_see_is_not_a_confirmation')),
    array('WC48-partial-refund-not-considered-on-recompletion', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        ' || (float) $order->get_total_refunded() > 0) {',
        ') {',
        array('WcCompletionTest::test_a_partial_refund_followed_by_a_second_completion_is_flagged_and_adds_nothing')),
    array('WC53-recovery-confirms-an-ended-order', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        } elseif ($this->markers->was_ended($order_id)) {',
        '        } elseif (false) {',
        array('WcCompletionTest::test_a_claim_whose_order_ended_while_the_club_database_was_down_is_never_confirmed')),
    array('WC54-recovery-confirms-a-refunded-order', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        } elseif ((float) $order->get_total_refunded() > 0) {',
        '        } elseif (false) {',
        array('WcCompletionTest::test_a_partial_refund_of_a_deferred_completion_is_never_recorded_as_20_eur')),
    array('WC55-cancelled-claim-stays-open', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        if ($r->outcome === EVC_Wc_Completion_Result::NOT_RECORDED && EVC_Wc_Completion_Markers::open_attempts(',
        '        if (false && EVC_Wc_Completion_Markers::open_attempts(',
        array('WcCompletionTest::test_a_deferred_completion_cancelled_then_completed_again_is_never_confirmed')),
    array('WC56-ended-refusal-leaves-the-claim-open', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            $this->close_or_flag($r, $order_id); // an open claim of this order is never finished by the recovery
',
        '',
        array('WcCompletionTest::test_a_deferred_completion_cancelled_then_completed_again_is_never_confirmed')),
    array('WC57-recovery-closes-an-ended-claim-silently', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            if ($r->outcome === EVC_Wc_Completion_Result::NOT_RECORDED && $this->markers->read($order_id)->outcome !== EVC_Wc_Completion_Marker_Read::MISSING) {',
        '            if (false) {',
        array('WcCompletionTest::test_a_claim_whose_order_ended_while_the_club_database_was_down_is_never_confirmed')),
    array('WC58-stale-hold-not-released', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '                $this->release_stale();
',
        '',
        array('WcCompletionTest::test_a_later_callback_that_throws_never_leaves_the_add_on_removed_for_the_next_orders')),
    array('WC59-nested-completion-not-withheld', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        $shared = $registered === false && $this->held !== array();',
        '        $shared = false;',
        array('WcCompletionTest::test_a_completion_nested_inside_another_shares_the_withheld_add_on')),
    array('WC60-add-on-back-while-another-completion-holds-it', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        if ($this->held === array() && has_action(',
        '        if (has_action(',
        array('WcCompletionTest::test_a_completion_nested_inside_another_shares_the_withheld_add_on')),
    array('WC61-error-keeps-the-add-on-from-an-ordinary-order', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            if ($this->is_club_order($order_id) === false) {
                $this->release_add_on($order_id); // not a Club order: the add-on as shipped
            }
',
        '',
        array('WcCompletionTest::test_an_error_before_the_club_check_of_an_ordinary_order_gives_it_the_add_on_as_shipped')),
    array('WC62-every-completion-taken-as-outermost', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        return $running <= 1;',
        '        return true;',
        array('WcCompletionTest::test_a_completion_nested_inside_another_shares_the_withheld_add_on')),
    array('WC63-admin-entry-point-not-required', 'commerce', 'includes/class-evc-wc-completion-context.php',
        '        return \'not_a_woocommerce_admin_action\';
    }

    /** @return int[] */',
        '        return null;
    }

    /** @return int[] */',
        array('WcCompletionTest::test_only_a_manual_completion_in_the_woocommerce_admin_is_a_confirmation')),
    array('WC64-ajax-action-not-checked', 'commerce', 'includes/class-evc-wc-completion-context.php',
        '            $ok = ($request[\'action\'] ?? null) === self::AJAX_ACTION
',
        '            $ok = true
',
        array('WcCompletionTest::test_only_a_manual_completion_in_the_woocommerce_admin_is_a_confirmation')),
    array('WC65-another-orders-edit-screen-accepted', 'commerce', 'includes/class-evc-wc-completion-context.php',
        '        if (in_array(\'update-order_\' . $order_id, $verified, true) ||',
        '        if (preg_grep(\'/^update-order_/\', $verified) ||',
        array('WcCompletionTest::test_only_a_manual_completion_in_the_woocommerce_admin_is_a_confirmation')),
    array('WC66-bulk-order-ids-not-checked', 'commerce', 'includes/class-evc-wc-completion-context.php',
        '        if (in_array(\'bulk-orders\', $verified, true) && in_array($order_id, self::ids($request[\'id\'] ?? null), true)) {',
        '        if (in_array(\'bulk-orders\', $verified, true)) {',
        array('WcCompletionTest::test_only_a_manual_completion_in_the_woocommerce_admin_is_a_confirmation')),
    array('WC67-busy-lock-leaves-no-claim', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '                $r = $this->claim($order_id, $customer, $actor, $completed_at) ?? new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, \'member_locked\');',
        '                $r = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, \'member_locked\');',
        array('WcCompletionTest::test_a_busy_member_lock_defers_and_the_recovery_finishes_it')),
    array('WC68-claim-race-flagged-although-recorded', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            if ($read->outcome === EVC_Bacs_Intent_Read::VALID && $read->intent !== null && $read->intent->state() === EVC_Bacs_Intent::STATE_RECORDED',
        '            if (false && $read->intent !== null && $read->intent->state() === EVC_Bacs_Intent::STATE_RECORDED',
        array('WcCompletionTest::test_a_claim_that_loses_a_race_with_the_recorded_confirmation_is_not_a_false_alarm')),
    array('WC69-tripwire-read-error-fails-open', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            return $this->db->last_error === \'\' && $n !== null ? (int) $n : -1;',
        '            return $this->db->last_error === \'\' && $n !== null ? (int) $n : 0;',
        array('WcCompletionTest::test_a_tripwire_that_cannot_read_the_rows_flags_a_manager')),
    array('WC70-lock-not-rechecked-each-step', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            if (!$lock->is_held()) {
                return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, \'lock_lost\');
            }
            $facts = $inspector->facts',
        '            $facts = $inspector->facts',
        array('WcCompletionTest::test_a_member_lock_lost_after_the_issuance_stops_the_pipeline_and_the_recovery_finishes_it')),
    array('WC71-issued-without-the-lock', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '\'lock_not_for_intent_customer\');
        }
        if (!$lock->is_held()) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, \'lock_lost\');
        }',
        '\'lock_not_for_intent_customer\');
        }',
        array('WcCompletionTest::test_a_member_lock_lost_before_the_issuance_or_before_the_record_stops_there')),
    array('WC72-recorded-without-the-lock', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '\'intent_not_issued\');
        }
        if (!$lock->is_held()) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, \'lock_lost\');
        }',
        '\'intent_not_issued\');
        }',
        array('WcCompletionTest::test_a_member_lock_lost_before_the_issuance_or_before_the_record_stops_there')),
    array('WC73-rest-depth-not-reset-at-the-end-of-the-request', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '    public function forget(): void {
        $this->rest_depth = 0;
',
        '    public function forget(): void {
',
        array('WcCompletionTest::test_the_rest_depth_survives_nested_requests_and_an_exception_fails_closed_until_the_request_ends')),
    array('WC74-rest-depth-not-decremented', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        $this->rest_depth = max(0, $this->rest_depth - 1);',
        '        $this->rest_depth = max(0, $this->rest_depth);',
        array('WcCompletionTest::test_the_rest_depth_survives_nested_requests_and_an_exception_fails_closed_until_the_request_ends')),
    array('WC75-referer-not-recorded', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '            $this->referers[] = $action;',
        '            $action = \'\';',
        array('WcCompletionTest::test_only_a_manual_completion_in_the_woocommerce_admin_is_a_confirmation')),
    array('WC49-cron-not-scheduled', 'commerce_on', 'includes/customer/class-evc-wc-completion-plugin.php',
        '            add_action(\'init\', array(__CLASS__, \'schedule\'));',
        '',
        array('WcCompletionOnTest::test_the_constant_is_on_and_boot_hooks_schedules_and_runs_the_recovery')),
    array('WC50-recovery-not-run-by-cron', 'commerce_on', 'includes/customer/class-evc-wc-completion-plugin.php',
        '                self::$recovery->run();',
        '',
        array('WcCompletionOnTest::test_the_constant_is_on_and_boot_hooks_schedules_and_runs_the_recovery')),
    array('WC51-deactivation-keeps-the-schedule', 'commerce_on', 'includes/customer/class-evc-wc-completion-plugin.php',
        '        wp_clear_scheduled_hook(self::CRON_HOOK);
    }
}',
        '    }
}',
        array('WcCompletionOnTest::test_deactivation_clears_the_schedule')),
    array('WC52-lock-not-released', 'commerce', 'includes/customer/class-evc-wc-completion-confirmer.php',
        '        } finally {
            $lock->release();
        }',
        '        } finally {
        }',
        array('WcCompletionTest::test_an_exception_inside_the_confirmation_never_aborts_woocommerce'))
);

/** Output of the most recent suite run (printed only when the baseline fails). */
$evc_last_output = '';

/** A GitHub Actions error annotation (the job log is not always readable; annotations are). */
function evc_mutation_ci_error(string $message): void {
    if ((string) getenv('GITHUB_ACTIONS') === 'true') {
        echo '::error title=WP mutation::' . str_replace(array('%', "\r", "\n"), array('%25', '%0D', '%0A'), substr($message, 0, 4000)) . "\n";
    }
}

function evc_wp_suite(string $root, string $mode, ?string $junit = null, ?string $filter = null): int {
    global $evc_last_output;
    if ($mode === 'customer') {
        $suite = 'wp-customer';
        $env = array_merge(getenv(), array('EVC_TEST_STAFF_FLAG' => 'disabled', 'EVC_TEST_CUSTOMER_FLAG' => 'enabled'));
    } elseif ($mode === 'commerce') {
        $suite = 'wp-commerce';
        $env = array_merge(getenv(), array('EVC_TEST_STAFF_FLAG' => 'disabled', 'EVC_TEST_CUSTOMER_FLAG' => 'enabled'));
    } elseif ($mode === 'commerce_on') {
        // STAGE-03D: the same site with EVC_CLUB_WC_COMPLETION_ENABLED = true (separate process, own constant).
        $suite = 'wp-commerce-on';
        $env = array_merge(getenv(), array('EVC_TEST_STAFF_FLAG' => 'disabled', 'EVC_TEST_CUSTOMER_FLAG' => 'enabled', 'EVC_TEST_WC_COMPLETION_FLAG' => 'enabled'));
    } else {
        $suite = $mode === 'enabled' ? 'wp-common,wp-enabled' : 'wp-common,wp-disabled';
        $env = array_merge(getenv(), array('EVC_TEST_STAFF_FLAG' => $mode, 'EVC_TEST_CUSTOMER_FLAG' => 'disabled'));
    }
    $proc = proc_open(
        array_merge(array(PHP_BINARY, $root . '/vendor/bin/phpunit', '-c', $root . '/phpunit-wp.xml.dist', '--testsuite', $suite), $junit === null ? array() : array('--log-junit', $junit), $filter === null ? array() : array('--filter', $filter)),
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
    return in_array($m[1], array('commerce', 'commerce_on'), true) === $evc_commerce_only;
}));
if ($evc_commerce_only && (string) getenv('EVC_TEST_COMMERCE_PLUGINS') === '') {
    fwrite(STDERR, "ABORT: --mode=commerce needs EVC_TEST_COMMERCE_PLUGINS.\n");
    exit(1);
}

$touched = array();
foreach ($mutants as $m) {
    $touched[$root . '/' . $m[2]] = hash_file('sha256', $root . '/' . $m[2]);
}

foreach ($evc_commerce_only ? array('commerce', 'commerce_on') : array('disabled', 'enabled', 'customer') as $mode) {
    echo "Baseline ($mode)...\n";
    if (evc_wp_suite($root, $mode) !== 0) {
        fwrite(STDERR, "ABORT: baseline WordPress suite ($mode) is not green.\n");
        evc_mutation_ci_error("baseline ($mode) not green: " . substr($evc_last_output, -3500));
        fwrite(STDERR, substr($evc_last_output, -6000) . "\n");
        exit(1);
    }
}
echo "Baseline: PASS\n";

$survivors = array();
$invalid = array();
$killed = 0;
foreach ($mutants as $m) {
    list($id, $mode, $file, $search, $replace) = $m;
    $killers = $m[5] ?? array();
    $junit = $killers === array() ? null : tempnam(sys_get_temp_dir(), 'evc-junit-');
    $path = $root . '/' . $file;
    $original = file_get_contents($path);
    // Working copies may use CRLF (Windows); patterns are written with LF.
    $normalized = str_replace("\r\n", "\n", $original);
    $count = substr_count($normalized, $search);
    if ($count !== 1) {
        fwrite(STDERR, sprintf("ABORT: %s pattern found %d times in %s (expected 1).\n", $id, $count, $file));
        evc_mutation_ci_error(sprintf('%s pattern found %d times in %s', $id, $count, $file));
        exit(1);
    }
    try {
        $mutated = str_replace($search, $replace, $normalized);
        evc_mutation_journal_begin($evc_journal, $path, $original, $mutated);
        file_put_contents($path, $mutated);
        if ($junit !== null) {
            file_put_contents($junit, '');
        }
        // STAGE-03D: a mutant with named killers runs exactly those tests (same verdict rule, much faster).
        $exit = evc_wp_suite($root, $mode, $junit, $killers === array() ? null : evc_mutation_killer_filter($killers));
    } finally {
        file_put_contents($path, $original);
        evc_mutation_journal_end($evc_journal, $path);
    }
    $evidence = '';
    if ($exit === 0) {
        $survivors[] = $id;
        $status = 'SURVIVED';
    } elseif ($killers === array()) {
        $killed++;
        $status = 'KILLED';
    } else {
        $verdict = evc_mutation_killer_verdict((string) file_get_contents($junit), $killers);
        $evidence = '; ' . $verdict['detail'];
        if ($verdict['verdict'] === 'assertion') {
            $killed++;
            $status = 'KILLED';
        } else {
            $invalid[] = $id;
            $status = 'INVALID (' . $verdict['verdict'] . ')';
        }
    }
    if ($junit !== null) {
        @unlink($junit);
    }
    printf("%-42s [%s] %s (exit %d%s)\n", $id, $mode, $status, $exit, $evidence);
    if ($status !== 'KILLED') {
        evc_mutation_ci_error($id . ' [' . $mode . '] ' . $status . $evidence);
    }
}

$restored = true;
foreach ($touched as $path => $hash) {
    if (hash_file('sha256', $path) !== $hash) {
        $restored = false;
        fwrite(STDERR, 'NOT RESTORED: ' . $path . "\n");
    }
}
printf("[evidence] wp-auth-mutation%s: total=%d killed=%d survived=%d invalid=%d sources_restored=%s\n", $evc_commerce_only ? ' (commerce)' : '', count($mutants), $killed, count($survivors), count($invalid), $restored ? 'yes' : 'NO');
if ($survivors) {
    fwrite(STDERR, 'Surviving mutants: ' . implode(', ', $survivors) . "\n");
}
if ($invalid) {
    fwrite(STDERR, 'Not killed by their named assertions (broken setup or wrong reason): ' . implode(', ', $invalid) . "\n");
}
exit($survivors || $invalid || !$restored ? 1 : 0);
