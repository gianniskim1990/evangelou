<?php
/**
 * STAGE-08: with EVC_CLUB_ADMIN_TOOLS_ENABLED undefined (every suite but the admin-tools ones) nothing of the Club admin
 * pages exists: no menu, no login hook, and their classes refuse construction.
 */
final class AdminToolsOffTest extends EVC_WP_Test_Case {
    public function test_off_by_default_nothing_exists(): void {
        if (defined('EVC_CLUB_ADMIN_TOOLS_ENABLED')) {
            $this->markTestSkipped('an admin-tools suite defines the constant');
        }
        $this->assertFalse(EVC_Admin_Tools_Feature::enabled());
        $log = (string) tempnam(sys_get_temp_dir(), 'evc-log');
        ini_set('error_log', $log);
        try {
            EVC_Admin_Tools_Plugin::boot('');
            EVC_Admin_Tools_Plugin::boot(array('now' => 'time'));
        } finally {
            ini_restore('error_log');
        }
        $this->assertSame('', (string) file_get_contents($log), 'returned at the gate: not even a wiring attempt');
        @unlink($log);
        $this->assertNull(EVC_Admin_Tools_Plugin::parts());
        $this->assertFalse(has_action('wp_login', array('EVC_Staff_Account_Service', 'record_login')));
        foreach ($GLOBALS['wp_filter']['admin_menu']->callbacks ?? array() as $callbacks) {
            foreach ($callbacks as $cb) {
                $this->assertFalse(is_array($cb['function']) && is_object($cb['function'][0]) && in_array(get_class($cb['function'][0]), array('EVC_Staff_Account_Page', 'EVC_Readiness_Page'), true), 'no Club admin menu');
            }
        }
        $this->assertFalse(EVC_Admin_Tools_Feature::is_enabled_value(1));
        $this->assertFalse(EVC_Admin_Tools_Feature::is_enabled_value('true'));
        $this->assertTrue(EVC_Admin_Tools_Feature::is_enabled_value(true));
    }

    public function test_the_classes_refuse_construction_while_off(): void {
        if (defined('EVC_CLUB_ADMIN_TOOLS_ENABLED')) {
            $this->markTestSkipped('an admin-tools suite defines the constant');
        }
        $this->expectException(LogicException::class);
        new EVC_Staff_Account_Service();
    }
}
