<?php
use PHPUnit\Framework\TestCase;

/**
 * STAGE-03D review (L-A): the classes of the WooCommerce-completion path refuse to be constructed
 * while EVC_CLUB_WC_COMPLETION_ENABLED is off (second layer behind the packager's guard).
 */
final class WcCompletionConstructionTest extends TestCase {
    /** @runInSeparateProcess
     *  @preserveGlobalState disabled */
    public function test_off_refuses_and_only_the_boolean_constant_or_the_test_hook_allow(): void {
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        require_once dirname(__DIR__, 2) . '/includes/customer/class-evc-wc-completion-feature.php';
        require_once dirname(__DIR__, 2) . '/includes/customer/class-evc-bacs-issuance-guard.php';
        try {
            new EVC_Bacs_Issuance_Guard();
            $this->fail('constructed while the feature is off');
        } catch (LogicException $e) {
            $this->assertSame('EVC_Bacs_Issuance_Guard exists only behind EVC_CLUB_WC_COMPLETION_ENABLED.', $e->getMessage());
        }
        define('EVC_TEST_WC_COMPLETION_CONSTRUCTION', 'yes'); // only the boolean true counts
        try {
            new EVC_Bacs_Issuance_Guard();
            $this->fail('a non-boolean test hook allowed it');
        } catch (LogicException $e) {
            $this->assertStringContainsString('exists only behind', $e->getMessage());
        }
        define('EVC_CLUB_WC_COMPLETION_ENABLED', true);
        $this->assertInstanceOf(EVC_Bacs_Issuance_Guard::class, new EVC_Bacs_Issuance_Guard());
    }

    /**
     * STAGE-05B: EVC_Club_Member_Sync (Club member rows) is one of the guarded classes.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_the_member_sync_refuses_while_off(): void {
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        require_once dirname(__DIR__, 2) . '/includes/customer/class-evc-wc-completion-feature.php';
        require_once dirname(__DIR__, 2) . '/includes/customer/class-evc-commerce-source-config.php';
        require_once dirname(__DIR__, 2) . '/includes/customer/class-evc-club-member-sync.php';
        define('EVC_CLUB_MEMBERSHIP_SOURCE', 'pmpro_woocommerce_bacs_v1');
        define('EVC_CLUB_PMPRO_LEVEL_ID', 1);
        define('EVC_CLUB_WC_PRODUCT_ID', 24435);
        define('EVC_CLUB_EVIDENCE_KEY', 'evc-test-only-evidence-key-0123456789abcdef');
        $config = EVC_Commerce_Source_Config::from_constants();
        $this->assertInstanceOf(EVC_Commerce_Source_Config::class, $config);
        $opened = 0;
        $open = function () use (&$opened) {
            $opened++;
            throw new RuntimeException('never reached');
        };
        $now = function () {
            return new DateTimeImmutable('2030-01-01T00:00:00Z');
        };
        try {
            new EVC_Club_Member_Sync($config, $open, $now);
            $this->fail('constructed while the feature is off');
        } catch (LogicException $e) {
            $this->assertSame('EVC_Club_Member_Sync exists only behind EVC_CLUB_WC_COMPLETION_ENABLED.', $e->getMessage());
        }
        define('EVC_CLUB_WC_COMPLETION_ENABLED', true);
        $sync = new EVC_Club_Member_Sync($config, $open, $now);
        $this->assertSame(0, $opened, 'constructing opens no connection');
        $this->assertSame('2030-01-01T00:00:00+00:00', $sync->now()->format(DATE_ATOM));
    }
}
