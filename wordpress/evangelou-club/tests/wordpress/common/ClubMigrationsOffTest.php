<?php

/**
 * STAGE-04A, EVC_CLUB_MIGRATIONS_ENABLED undefined (every suite except wp-migrations): no admin
 * page, no menu entry, no WP-CLI command, no REST route, nothing constructible, and boot() does
 * nothing at all (not even an attempted wiring).
 */
final class ClubMigrationsOffTest extends EVC_WP_Test_Case {
    public function test_nothing_exists_while_the_constant_is_off(): void {
        $this->assertFalse(defined('EVC_CLUB_MIGRATIONS_ENABLED'));
        $this->assertFalse(EVC_Club_Migrations_Feature::enabled());
        // The real plugins_loaded boot ran: only the inert gate itself is hooked.
        $this->assertNotFalse(has_action('plugins_loaded', array('EVC_Club_Migrations_Plugin', 'boot')));
        $this->assertSame(0, self::page_callbacks());

        $admin = $this->create_user_with_role('administrator');
        wp_set_current_user($admin);
        $log = tempnam(sys_get_temp_dir(), 'evc-mig-off-');
        $previous = ini_set('error_log', $log);
        try {
            EVC_Club_Migrations_Plugin::boot();
            EVC_Club_Migrations_Plugin::boot();
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $this->assertSame('', (string) file_get_contents($log), 'boot() must return before attempting any wiring');
        unlink($log);
        $this->assertSame(0, self::page_callbacks());

        global $submenu;
        foreach ((array) ($submenu['tools.php'] ?? array()) as $item) {
            $this->assertNotSame(EVC_Club_Migrations_Admin_Page::SLUG, $item[2] ?? null);
        }
        foreach (array_keys(rest_get_server()->get_routes()) as $route) {
            $this->assertStringNotContainsString('migration', $route);
        }
    }

    public function test_the_classes_refuse_construction_while_off(): void {
        try {
            EVC_Club_Migrations_Plugin::build(function () {
                return null;
            });
            $this->fail('build() must refuse while the constant is off');
        } catch (LogicException $e) {
            $this->assertStringContainsString('EVC_CLUB_MIGRATIONS_ENABLED', $e->getMessage());
        }
    }

    public function test_only_the_boolean_true_enables_it(): void {
        $this->assertTrue(EVC_Club_Migrations_Feature::is_enabled_value(true));
        foreach (array(1, '1', 'true', 'yes', array(true), 1.0, null, false, 0) as $value) {
            $this->assertFalse(EVC_Club_Migrations_Feature::is_enabled_value($value), var_export($value, true));
        }
    }

    private static function page_callbacks(): int {
        global $wp_filter;
        $count = 0;
        foreach (array('admin_menu', 'admin_init', 'admin_post_' . EVC_Club_Migrations_Admin_Page::NONCE_ACTION) as $hook) {
            if (!isset($wp_filter[$hook])) {
                continue;
            }
            foreach ($wp_filter[$hook]->callbacks as $callbacks) {
                foreach ($callbacks as $callback) {
                    $fn = $callback['function'];
                    if (is_array($fn) && (is_object($fn[0]) ? get_class($fn[0]) : (string) $fn[0]) === 'EVC_Club_Migrations_Admin_Page') {
                        $count++;
                    }
                }
            }
        }
        return $count;
    }
}
