<?php

/** TEST-ONLY provenance: stands in for the FUTURE trusted write path (none exists in production). */
final class EVC_Test_Recorded_Provenance implements EVC_Club_Payment_Provenance {
    /** @var EVC_Pmpro_Payment_Fact[] */
    private $facts;

    /** @param EVC_Pmpro_Payment_Fact[] $facts */
    public function __construct(array $facts) {
        $this->facts = $facts;
    }

    public function confirmed_payments(int $wp_user_id): array {
        return $this->facts;
    }
}

/**
 * STAGE-02: GET /me over the REAL WooCommerce 11.2.1, Paid Memberships Pro
 * 3.8.9 and PMPro WooCommerce Add On 1.11 (tests/bin/fetch-commerce-plugins.sh,
 * checksum-pinned), configured like the verified staging site
 * (tests/wordpress/support/commerce-site.php). Orders are created and moved
 * through statuses with WooCommerce's own API, so the add-on's own hooks
 * create, cancel and extend the PMPro rows exactly as they would on staging.
 *
 * Customer flag ON, staff flag OFF, source configured through the
 * wp-config constants. Nothing here is a real payment.
 */
final class CommerceMembershipSourceTest extends EVC_WP_Test_Case {
    const ME = '/evangelou-club-customer/v1/me';

    public function set_up() {
        parent::set_up();
        $this->assertTrue(defined('WC_VERSION') && defined('PMPRO_VERSION') && defined('PMPROWC_DIR'), 'run with EVC_TEST_COMMERCE_PLUGINS');
        evc_test_commerce_configure();
    }

    private function customer(): int {
        return (int) self::factory()->user->create(array('role' => 'customer', 'user_pass' => self::PASSWORD, 'user_email' => 'club.' . wp_generate_password(6, false, false) . '@example.org'));
    }

    private function order(int $user_id, string $status, string $method = 'bacs', int $product = EVC_TEST_CLUB_PRODUCT): WC_Order {
        $order = wc_create_order(array('customer_id' => $user_id));
        $order->add_product(wc_get_product($product), 1);
        $order->set_payment_method($method);
        $order->set_currency('EUR');
        $order->calculate_totals();
        $order->save();
        if ($status !== 'pending') {
            $order->update_status($status);
        }
        return wc_get_order($order->get_id());
    }

    /**
     * VERIFIED real behaviour: with PMPro 3.x installed, PMPro WooCommerce 1.11 finds PMPro's own
     * deprecated shim pmprommpu_addMembershipLevel() and issues the level through it; the shim
     * calls pmpro_changeMembershipLevel() with the add-on's level array (same result, plus a
     * deprecation notice in the log when WP_DEBUG is on).
     */
    private function expect_add_on_shim_notice(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
    }

    /** @return array{state:string,paid_until_utc:?string} */
    private function me(int $user_id): array {
        $this->login_as($user_id);
        $r = $this->rest('GET', self::ME, null, $this->nonce());
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        $data = $r->get_data();
        $json = wp_json_encode($data);
        foreach (array('wc_order', 'diagnostic', 'evidence', 'bacs', 'on-hold', 'level') as $needle) {
            $this->assertStringNotContainsString($needle, $json, "no internal field: $needle");
        }
        return $data['membership'];
    }

    private function pmpro_row(int $user_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, status, startdate, enddate FROM {$wpdb->pmpro_memberships_users} WHERE user_id = %d ORDER BY id DESC LIMIT 1", $user_id), ARRAY_A);
        return $row ?: null;
    }

    private function reader(?EVC_Club_Payment_Provenance $provenance = null): EVC_Wp_Commerce_Pmpro_Reader {
        return new EVC_Wp_Commerce_Pmpro_Reader(EVC_Commerce_Source_Config::from_constants(), $provenance ?? new EVC_Unrecorded_Club_Payment_Provenance());
    }

    // ---- environment ---------------------------------------------------------------------------

    public function test_the_exact_verified_plugin_versions_are_the_ones_under_test(): void {
        $this->assertSame(EVC_Commerce_Source_Config::WOOCOMMERCE_VERSION, WC_VERSION);
        $this->assertSame(EVC_Commerce_Source_Config::PMPRO_VERSION, PMPRO_VERSION);
        $this->assertSame(EVC_Commerce_Source_Config::PMPRO_WOOCOMMERCE_VERSION, get_file_data(PMPROWC_DIR . '/pmpro-woocommerce.php', array('Version' => 'Version'))['Version']);
        $this->assertSame(array(), $this->reader()->snapshot($this->customer())->source_conflicts, 'the staging configuration is accepted as is');
    }

    public function test_the_requested_order_storage_is_in_use(): void {
        $hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $this->assertSame((getenv('EVC_TEST_WC_ORDER_STORAGE') ?: 'hpos') === 'hpos', $hpos);
        $user = $this->customer();
        $this->order($user, 'on-hold');
        $this->assertSame('pending_payment', $this->me($user)['state'], 'the reader finds the order in this storage');
    }

    public function test_configured_constants_select_the_real_source(): void {
        $this->assertInstanceOf(EVC_Pmpro_Membership_Adapter::class, EVC_Customer_Plugin::membership_adapter());
        $this->assertFalse(EVC_Staff_Feature::enabled());
    }

    /**
     * STAGE-03B.1 (mutant C12): the configured source is not only constructed but is the one GET /me
     * reads. An awaiting bank transfer is something only the real WooCommerce source can know; the
     * always-unavailable default would answer 503 instead.
     */
    public function test_configured_source_is_the_one_answering_me(): void {
        $user = $this->customer();
        $this->order($user, 'on-hold');
        $this->login_as($user);
        $r = $this->rest('GET', self::ME, null, $this->nonce());
        $this->assertSame(200, $r->get_status(), 'GET /me must be answered by the configured real source, not the unavailable default');
        $this->assertSame('pending_payment', $r->get_data()['membership']['state']);
    }

    // ---- states ------------------------------------------------------------------------------

    public function test_no_club_order_is_none(): void {
        $this->assertSame(array('state' => 'none', 'paid_until_utc' => null), $this->me($this->customer()));
    }

    public function test_bank_transfer_awaiting_payment_is_pending_and_issues_no_membership(): void {
        foreach (array('on-hold', 'pending', 'processing') as $status) {
            $user = $this->customer();
            $this->order($user, $status);
            $this->assertNull($this->pmpro_row($user), "$status: PMPro WooCommerce issues no membership before completion");
            $this->assertSame(array('state' => 'pending_payment', 'paid_until_utc' => null), $this->me($user), $status);
        }
    }

    public function test_completed_order_issues_a_pmpro_row_but_without_club_provenance_is_never_paid(): void {
        $this->expect_add_on_shim_notice();
        $user = $this->customer();
        $order = $this->order($user, 'on-hold');
        $order->update_status('completed');
        $row = $this->pmpro_row($user);
        $this->assertNotNull($row, 'the add-on issued the level on completion');
        $this->assertSame('active', $row['status']);
        $this->assertTrue(pmpro_hasMembershipLevel(EVC_TEST_CLUB_LEVEL, $user));
        // A shop status is not evidence that money arrived: never "active".
        $this->assertSame(array('state' => 'unverified', 'paid_until_utc' => null), $this->me($user));
    }

    public function test_the_add_on_end_date_is_minute_rounded_php_month_arithmetic(): void {
        $this->expect_add_on_shim_notice();
        $user = $this->customer();
        $this->order($user, 'completed');
        $row = $this->pmpro_row($user);
        // pmprowoo_add_membership_from_order(): date("Y-m-d H:i:00", strtotime("+ 1 Month", current_time('timestamp'))).
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $row['enddate']);
        $start = new DateTimeImmutable($row['startdate'], new DateTimeZone('Europe/Athens'));
        $expected = date('Y-m-d H:i:00', strtotime('+ 1 Month', strtotime($row['startdate'])));
        $this->assertContains($row['enddate'], array($expected, date('Y-m-d H:i:00', strtotime('+ 1 Month', strtotime($row['startdate']) + 60))), 'start and end come from the same current_time() call');
        $calendar = EVC_Membership_Calendar::next_period(null, $start->setTimezone(new DateTimeZone('UTC')));
        $calendar_end_local = $calendar['end']->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s');
        // The approved Athens rule keeps the exact second; the add-on drops it. They agree only by chance.
        $this->assertSame($start->format('s') === '00' && $calendar_end_local === $row['enddate'], $calendar_end_local === $row['enddate']);
    }

    public function test_cancelled_or_refunded_after_completion_is_cancelled(): void {
        $this->expect_add_on_shim_notice();
        foreach (array('cancelled', 'refunded') as $status) {
            $user = $this->customer();
            $order = $this->order($user, 'completed');
            $order->update_status($status);
            $this->assertSame('cancelled', $this->pmpro_row($user)['status'], "$status: the add-on cancels the level");
            $this->assertSame(array('state' => 'cancelled', 'paid_until_utc' => null), $this->me($user), $status);
        }
    }

    public function test_failed_order_without_membership_is_none(): void {
        $user = $this->customer();
        $this->order($user, 'failed');
        $this->assertSame(array('state' => 'none', 'paid_until_utc' => null), $this->me($user));
    }

    public function test_expired_membership_and_a_renewal_awaiting_payment(): void {
        $this->expect_add_on_shim_notice();
        $user = $this->customer();
        $this->order($user, 'completed');
        // How PMPro 3.8.9 itself records expiry (PMPro_Recurring_Actions / expiration cron).
        pmpro_cancelMembershipLevel(EVC_TEST_CLUB_LEVEL, $user, 'expired');
        $this->assertSame('expired', $this->pmpro_row($user)['status']);
        $this->assertSame('expired', $this->me($user)['state']);
        $this->order($user, 'on-hold');
        $this->assertSame(array('state' => 'pending_payment', 'paid_until_utc' => null), $this->me($user), 'renewal by bank transfer awaiting payment');
    }

    public function test_another_customers_orders_never_count(): void {
        $this->expect_add_on_shim_notice();
        $other = $this->customer();
        $this->order($other, 'completed');
        $this->order($other, 'on-hold');
        $this->assertSame(array('state' => 'none', 'paid_until_utc' => null), $this->me($this->customer()));
    }

    // ---- fail closed on anything not verified -----------------------------------------------

    public function test_every_configuration_drift_makes_the_membership_unverified(): void {
        $drifts = array(
            'site timezone is a fixed UTC+3 offset' => function () {
                update_option('timezone_string', '');
                update_option('gmt_offset', 3);
            },
            'store currency' => function () {
                update_option('woocommerce_currency', 'USD');
            },
            'product not sold individually' => function () {
                $p = wc_get_product(EVC_TEST_CLUB_PRODUCT);
                $p->set_sold_individually(false);
                $p->save();
            },
            'price changed' => function () {
                $p = wc_get_product(EVC_TEST_CLUB_PRODUCT);
                $p->set_regular_price('19');
                $p->save();
            },
            'product autocompletes orders' => function () {
                update_post_meta(EVC_TEST_CLUB_PRODUCT, '_membership_product_autocomplete', 1);
            },
            'product not mapped to the level' => function () {
                update_option('_pmprowoo_product_levels', array());
            },
            'another product grants the level' => function () {
                update_option('_pmprowoo_product_levels', array(EVC_TEST_CLUB_PRODUCT => EVC_TEST_CLUB_LEVEL, 999 => EVC_TEST_CLUB_LEVEL));
            },
            'level lasts two months' => function () {
                global $wpdb;
                $wpdb->update($wpdb->pmpro_membership_levels, array('expiration_number' => 2), array('id' => EVC_TEST_CLUB_LEVEL));
            },
            'level bills recurring' => function () {
                global $wpdb;
                $wpdb->update($wpdb->pmpro_membership_levels, array('billing_amount' => 20, 'cycle_number' => 1), array('id' => EVC_TEST_CLUB_LEVEL));
            },
        );
        foreach ($drifts as $label => $drift) {
            evc_test_commerce_configure();
            update_option('gmt_offset', 0);
            delete_post_meta(EVC_TEST_CLUB_PRODUCT, '_membership_product_autocomplete');
            wp_cache_flush();
            $user = $this->customer();
            $this->order($user, 'on-hold');
            $this->assertSame('pending_payment', $this->me($user)['state'], "$label: baseline");
            $drift();
            wp_cache_flush();
            $GLOBALS['pmpro_levels'] = array(); // PMPro's own per-request level cache (a real request starts empty)
            $this->assertSame(array('state' => 'unverified', 'paid_until_utc' => null), $this->me($user), $label);
        }
    }

    public function test_a_club_order_that_is_not_a_single_bank_transfer_makes_the_membership_unverified(): void {
        $card = $this->customer();
        $this->order($card, 'on-hold', 'paypal');
        $this->assertSame('unverified', $this->me($card)['state'], 'only bank transfer is verified for initial testing');

        $custom = $this->customer();
        $order = $this->order($custom, 'on-hold');
        add_filter('wc_order_statuses', function ($s) {
            return $s + array('wc-awaiting-bank' => 'Awaiting bank');
        });
        $order->set_status('awaiting-bank');
        $order->save();
        $this->assertSame('unverified', $this->me($custom)['state'], 'a status added by another plugin is never guessed');
    }

    public function test_the_reader_is_read_only_and_reports_no_personal_data(): void {
        $this->expect_add_on_shim_notice();
        $user = $this->customer();
        $this->order($user, 'completed');
        $this->order($user, 'on-hold');
        $writes = array();
        $spy = function ($sql) use (&$writes) {
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $sql)) {
                $writes[] = $sql;
            }
            return $sql;
        };
        add_filter('query', $spy);
        $snapshot = $this->reader()->snapshot($user);
        remove_filter('query', $spy);
        $this->assertSame(array(), $writes, 'no write while reading');
        $dump = print_r($snapshot, true);
        $u = get_userdata($user);
        foreach (array($u->user_email, $u->user_login, 'bacs', 'Τραπεζική') as $needle) {
            $this->assertStringNotContainsString($needle, $dump);
        }
        foreach ($snapshot->payments as $p) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $p->payment_ref);
            $this->assertSame(EVC_Pmpro_Payment_Fact::STATUS_PENDING, $p->status, 'only the on-hold order is a fact; the completed one is not evidence');
            $this->assertNull($p->confirmed_at_utc);
        }
        $this->assertCount(1, $snapshot->payments);
    }

    // ---- the only path to "active": the Club's own recorded provenance (future write path) ---

    public function test_active_only_with_recorded_provenance_matching_the_pmpro_period_exactly(): void {
        $this->expect_add_on_shim_notice();
        $user = $this->customer();
        $order = $this->order($user, 'completed');
        $row = $this->pmpro_row($user);
        $config = EVC_Commerce_Source_Config::from_constants();
        $confirmed = (new DateTimeImmutable($row['startdate'], new DateTimeZone('Europe/Athens')))->setTimezone(new DateTimeZone('UTC'));
        $period = EVC_Membership_Calendar::next_period(null, $confirmed);
        // What the future write path must ALSO do: make PMPro's end the approved Athens period end.
        global $wpdb;
        $wpdb->update($wpdb->pmpro_memberships_users, array('enddate' => $period['end']->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s')), array('id' => $row['id']));
        $fact = new EVC_Pmpro_Payment_Fact(
            $config->ref('wc_order', (string) $order->get_id()),
            $config->ref('money', 'bacs-' . $order->get_id()),
            EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION,
            EVC_TEST_CLUB_LEVEL,
            $config->ref('pmpro_membership_row', (string) $row['id']),
            EVC_Pmpro_Payment_Fact::STATUS_CONFIRMED,
            EVC_Pmpro_Payment_Fact::ENV_LIVE,
            $confirmed,
            $period['start'],
            $period['end']
        );
        $adapter = new EVC_Pmpro_Membership_Adapter($this->reader(new EVC_Test_Recorded_Provenance(array($fact))), $config->mapper());
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->assertSame(
            array('state' => 'active', 'paid_until_utc' => $period['end']->format('Y-m-d\TH:i:sP')),
            EVC_Customer_Membership::describe($adapter->entitlement_for($user, $now), $now)
        );

        // The same record with PMPro's own (add-on) end date, or a period that is off by one second: never paid.
        $wpdb->update($wpdb->pmpro_memberships_users, array('enddate' => $period['end']->modify('+1 second')->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s')), array('id' => $row['id']));
        $this->assertSame('unverified', EVC_Customer_Membership::describe($adapter->entitlement_for($user, $now), $now)['state']);
    }

    public function test_source_config_accepts_only_exact_types(): void {
        $key = str_repeat('k', 32);
        $this->assertNotNull(EVC_Commerce_Source_Config::parse('pmpro_woocommerce_bacs_v1', 1, 24435, $key));
        foreach (array(
            array(true, 1, 24435, $key), array('pmpro_woocommerce_bacs_v2', 1, 24435, $key), array('pmpro_woocommerce_bacs_v1', '1', 24435, $key),
            array('pmpro_woocommerce_bacs_v1', 1, '24435', $key), array('pmpro_woocommerce_bacs_v1', 0, 24435, $key),
            array('pmpro_woocommerce_bacs_v1', 1, -5, $key), array('pmpro_woocommerce_bacs_v1', 1, 24435, str_repeat('k', 31)),
            array('pmpro_woocommerce_bacs_v1', 1, 24435, null),
        ) as $i => $args) {
            $this->assertNull(EVC_Commerce_Source_Config::parse(...$args), "case $i");
        }
    }
}
