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
    // STAGE-03: the Club's recorded payments live in a DISPOSABLE Club database on the loopback
    // test server (migrations 001 + 002 applied here only; never on any real database).
    define('EVC_CLUB_PAYMENT_PROVENANCE', 'club_db_v1');
    define('EVC_CLUB_DB_HOST', (string) (getenv('EVC_TEST_DB_HOST') ?: '127.0.0.1'));
    define('EVC_CLUB_DB_PORT', (int) (getenv('EVC_TEST_DB_PORT') ?: 3306));
    define('EVC_CLUB_DB_NAME', 'evc_test_commerce_club');
    define('EVC_CLUB_DB_USER', (string) (getenv('EVC_TEST_DB_USER') ?: 'root'));
    define('EVC_CLUB_DB_PASSWORD', (string) getenv('EVC_TEST_DB_PASSWORD'));

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
    // A fresh disposable Club database for this run (loopback test server only).
    tests_add_filter('setup_theme', 'evc_test_commerce_club_db', 20);
    // Configure after WooCommerce registered its post types (init 5) and before PMPro
    // WooCommerce reads its product -> level option (pmprowoo_init, init 10).
    tests_add_filter('init', 'evc_test_commerce_configure', 6);
}

/** (Re)creates the disposable Club database and applies the plugin's own migrations (001, 002). */
function evc_test_commerce_club_db(): void {
    $config = EVC_Db_Config::from_constants();
    if (!in_array(EVC_CLUB_DB_HOST, array('127.0.0.1', 'localhost', '::1'), true) || strpos($config->database(), 'evc_test_') !== 0) {
        fwrite(STDERR, "Refusing a non-loopback or non-test Club database.\n");
        exit(1);
    }
    $admin = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', EVC_CLUB_DB_HOST, EVC_CLUB_DB_PORT), EVC_CLUB_DB_USER, EVC_CLUB_DB_PASSWORD, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $admin->exec('DROP DATABASE IF EXISTS `' . $config->database() . '`');
    $admin->exec('CREATE DATABASE `' . $config->database() . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    (new EVC_Migrator(EVC_Club_Db::connect($config), dirname(__DIR__, 3) . '/database'))->migrate();
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
