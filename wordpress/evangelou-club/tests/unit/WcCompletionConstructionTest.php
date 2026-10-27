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
}
