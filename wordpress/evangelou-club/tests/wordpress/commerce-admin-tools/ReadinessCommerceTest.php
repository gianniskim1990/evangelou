<?php
/**
 * STAGE-08: the readiness checks over the REAL shop (WooCommerce 11.2.1 + PMPro 3.8.9 + PMPro WooCommerce 1.11, the
 * disposable Club database with its migrations): the configured shop reads OK, and a wrong price, a product that is
 * not sold individually, or bank transfer off each read "wrong" with the existing reader's own code.
 */
final class ReadinessCommerceTest extends EVC_WP_Test_Case {
    public function set_up() {
        parent::set_up();
        $this->assertTrue(defined('WC_VERSION') && defined('PMPRO_VERSION'), 'run with EVC_TEST_COMMERCE_PLUGINS');
        evc_test_commerce_configure();
        EVC_Staff_Role::register();
        wp_set_current_user((int) self::factory()->user->create(array('role' => 'administrator')));
        EVC_Admin_Tools_Plugin::boot(array());
    }

    /** @return array<string,array{status:string,detail:string}> */
    private static function rows(): array {
        $out = array();
        foreach (EVC_Admin_Tools_Plugin::parts()['readiness']->checks() as $row) {
            $out[$row['key']] = $row;
        }
        return $out;
    }

    public function test_the_configured_shop_reads_ok(): void {
        $rows = self::rows();
        $this->assertSame('ok', $rows['club_db']['status'], $rows['club_db']['detail']);
        $this->assertSame('ok', $rows['club_db_schema']['status'], $rows['club_db_schema']['detail']);
        $this->assertSame('ok', $rows['commerce_config']['status']);
        $this->assertStringContainsString('#' . EVC_TEST_CLUB_PRODUCT, $rows['commerce_config']['detail']);
        $this->assertSame('ok', $rows['commerce_environment']['status'], $rows['commerce_environment']['detail']);
        $this->assertSame('ok', $rows['commerce_site']['status'], $rows['commerce_site']['detail']);
        $this->assertSame('ok', $rows['bacs']['status']);
        $this->assertSame('ok', $rows['timezone']['status']);
        $this->assertSame('ok', $rows['flag_customer']['status']);
        $this->assertSame('ok', $rows['flag_onboarding']['status']);
        $html = EVC_Admin_Tools_Plugin::parts()['readiness_page']->html();
        foreach (array(EVC_CLUB_EVIDENCE_KEY, EVC_CLUB_LOOKUP_KEY, EVC_CLUB_DB_NAME) as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
        if (EVC_CLUB_DB_PASSWORD !== '') {
            $this->assertStringNotContainsString(EVC_CLUB_DB_PASSWORD, $html);
        }
    }

    public function test_a_wrong_price_a_shared_cart_or_no_bank_transfer_read_wrong(): void {
        $product = wc_get_product(EVC_TEST_CLUB_PRODUCT);
        $product->set_regular_price('25');
        $product->set_sold_individually(false);
        $product->save();
        update_option('woocommerce_bacs_settings', array('enabled' => 'no'));
        $rows = self::rows();
        $this->assertSame('wrong', $rows['commerce_environment']['status']);
        $this->assertStringContainsString('club_product_price_not_20_eur', $rows['commerce_environment']['detail']);
        $this->assertStringContainsString('club_product_not_sold_individually', $rows['commerce_environment']['detail']);
        $this->assertSame('wrong', $rows['bacs']['status']);
        update_option('timezone_string', 'UTC');
        $rows = self::rows();
        $this->assertSame('wrong', $rows['timezone']['status']);
        $this->assertSame('wrong', $rows['commerce_site']['status']);
        $this->assertStringContainsString('site_timezone_not_athens', $rows['commerce_site']['detail']);
    }
}
