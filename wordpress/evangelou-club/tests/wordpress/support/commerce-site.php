<?php
/**
 * TEST-ONLY (STAGE-02): turns the disposable WordPress test site into the
 * verified staging shape, with the REAL plugins:
 *   WooCommerce 11.2.1, Paid Memberships Pro 3.8.9, PMPro WooCommerce 1.11,
 *   level 1 (one month, no recurring billing), product 24435 (simple, €20,
 *   sold individually) mapped to level 1, BACS the only gateway, EUR,
 *   site timezone Europe/Athens,
 * and configures the STAGE-02 membership source through the same
 * wp-config constants a server would use. Never shipped (the packager only
 * copies includes/ and the entrypoint).
 */

const EVC_TEST_CLUB_LEVEL = 1;
const EVC_TEST_CLUB_PRODUCT = 24435;

function evc_test_commerce_site(string $dir): void {
    foreach (array('woocommerce/woocommerce.php', 'paid-memberships-pro/paid-memberships-pro.php', 'pmpro-woocommerce/pmpro-woocommerce.php') as $main) {
        if (!is_file($dir . '/' . $main)) {
            fwrite(STDERR, "Commerce plugin missing: $main\n");
            exit(1);
        }
    }
    // The server-side configuration under test (wp-config.php on a real site).
    define('EVC_CLUB_MEMBERSHIP_SOURCE', 'pmpro_woocommerce_bacs_v1');
    define('EVC_CLUB_PMPRO_LEVEL_ID', EVC_TEST_CLUB_LEVEL);
    define('EVC_CLUB_WC_PRODUCT_ID', EVC_TEST_CLUB_PRODUCT);
    define('EVC_CLUB_EVIDENCE_KEY', 'evc-test-only-evidence-key-0123456789abcdef');

    // Loaded like normal plugins, before the Club plugin (priority 10).
    tests_add_filter('muplugins_loaded', function () use ($dir) {
        require $dir . '/woocommerce/woocommerce.php';
        require $dir . '/paid-memberships-pro/paid-memberships-pro.php';
        require $dir . '/pmpro-woocommerce/pmpro-woocommerce.php';
    }, 5);

    // Same order as WooCommerce's own test bootstrap: install after the plugins are loaded.
    tests_add_filter('setup_theme', function () {
        WC_Install::install();
        $GLOBALS['wp_roles'] = null;
        wp_roles();
        pmpro_checkForUpgrades();
        // Staging's order storage is not confirmed: CI runs both. EVC_TEST_WC_ORDER_STORAGE=hpos|posts.
        $storage = getenv('EVC_TEST_WC_ORDER_STORAGE') ?: 'hpos';
        if (!in_array($storage, array('hpos', 'posts'), true)) {
            fwrite(STDERR, "EVC_TEST_WC_ORDER_STORAGE must be hpos or posts.\n");
            exit(1);
        }
        // Pinned without running option-change hooks (no Action Scheduler work in a test bootstrap).
        add_filter('pre_option_woocommerce_custom_orders_table_data_sync_enabled', function () {
            return 'no';
        });
        add_filter('pre_option_woocommerce_custom_orders_table_enabled', function () use ($storage) {
            return $storage === 'hpos' ? 'yes' : 'no';
        });
        if ($storage === 'hpos') {
            $sync = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class);
            if (!$sync->check_orders_table_exists()) {
                $sync->create_database_tables();
            }
        }
    });
    // Configure after WooCommerce registered its post types (init 5) and before PMPro
    // WooCommerce reads its product -> level option (pmprowoo_init, init 10).
    tests_add_filter('init', 'evc_test_commerce_configure', 6);
}

/** The staging configuration as verified on 2026-10-23 (fresh test database, fixed ids). */
function evc_test_commerce_configure(): void {
    global $wpdb, $pmpro_levels, $pmprowoo_product_levels;
    $pmpro_levels = array();
    update_option('timezone_string', 'Europe/Athens');
    update_option('woocommerce_currency', 'EUR');
    update_option('woocommerce_bacs_settings', array('enabled' => 'yes', 'title' => 'Τραπεζική μεταφορά'));

    $wpdb->replace($wpdb->pmpro_membership_levels, array(
        'id' => EVC_TEST_CLUB_LEVEL,
        'name' => 'Ευαγγέλου Club',
        'description' => '',
        'confirmation' => '',
        'initial_payment' => 20,
        'billing_amount' => 0,
        'cycle_number' => 0,
        'cycle_period' => '',
        'billing_limit' => 0,
        'trial_amount' => 0,
        'trial_limit' => 0,
        'allow_signups' => 0,
        'expiration_number' => 1,
        'expiration_period' => 'Month',
    ));

    if (!get_post(EVC_TEST_CLUB_PRODUCT)) {
        wp_insert_post(array('import_id' => EVC_TEST_CLUB_PRODUCT, 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'ΣΥΝΔΡΟΜΗ ΚΑΦΕ'));
    }
    $product = new WC_Product_Simple(EVC_TEST_CLUB_PRODUCT);
    $product->set_regular_price('20');
    $product->set_sold_individually(true);
    $product->set_virtual(true);
    $product->save();
    // PMPro WooCommerce 1.11 product settings (pmprowoo_process_product_meta()).
    update_post_meta(EVC_TEST_CLUB_PRODUCT, '_membership_product_level', EVC_TEST_CLUB_LEVEL);
    update_option('_pmprowoo_product_levels', array(EVC_TEST_CLUB_PRODUCT => EVC_TEST_CLUB_LEVEL));
    $pmprowoo_product_levels = array(EVC_TEST_CLUB_PRODUCT => EVC_TEST_CLUB_LEVEL);
}
