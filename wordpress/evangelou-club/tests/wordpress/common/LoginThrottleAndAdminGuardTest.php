<?php

final class LoginThrottleAndAdminGuardTest extends EVC_WP_Test_Case {
    private function login_name(int $id): string {
        return get_userdata($id)->user_login;
    }

    public function test_staff_account_locks_after_five_failures_from_one_ip(): void {
        $staff = $this->create_staff_user();
        $name = $this->login_name($staff);
        for ($i = 0; $i < 4; $i++) {
            $this->assertWPError(wp_authenticate($name, 'wrong-' . $i));
        }
        $this->assertInstanceOf(WP_User::class, wp_authenticate($name, self::PASSWORD), 'below the limit the right password works');

        for ($i = 0; $i < 5; $i++) {
            wp_authenticate($name, 'wrong-' . $i);
        }
        $locked = wp_authenticate($name, self::PASSWORD);
        $this->assertWPError($locked, 'locked even with the correct password');
        $this->assertSame(EVC_Login_Throttle::ERROR_CODE, $locked->get_error_code());

        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $this->assertInstanceOf(WP_User::class, wp_authenticate($name, self::PASSWORD), 'another IP is not locked by this IP');
    }

    public function test_lock_expires(): void {
        $staff = $this->create_staff_user();
        $name = $this->login_name($staff);
        for ($i = 0; $i < 5; $i++) {
            wp_authenticate($name, 'wrong');
        }
        $this->assertWPError(wp_authenticate($name, self::PASSWORD));
        $key = EVC_Login_Throttle::ip_key($staff, '203.0.113.10');
        set_transient($key, array('fails' => array(), 'locked_until' => time() - 1), 60);
        $this->assertInstanceOf(WP_User::class, wp_authenticate($name, self::PASSWORD));
    }

    public function test_account_wide_lock_after_distributed_failures(): void {
        $staff = $this->create_staff_user();
        for ($i = 0; $i < EVC_Login_Throttle::MAX_PER_ACCOUNT; $i++) {
            EVC_Login_Throttle::record_failure($staff, '192.0.2.' . $i, time());
        }
        $_SERVER['REMOTE_ADDR'] = '198.51.100.99';
        $this->assertWPError(wp_authenticate($this->login_name($staff), self::PASSWORD));
    }

    public function test_other_users_are_never_throttled(): void {
        $editor = $this->create_user_with_role('editor');
        $name = $this->login_name($editor);
        for ($i = 0; $i < 12; $i++) {
            wp_authenticate($name, 'wrong');
        }
        $this->assertInstanceOf(WP_User::class, wp_authenticate($name, self::PASSWORD));
    }

    public function test_untrusted_proxy_headers_are_ignored(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $this->assertSame('203.0.113.10', EVC_Login_Throttle::client_ip());
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = 'garbage';
        $this->assertSame('unknown', EVC_Login_Throttle::client_ip());
    }

    public function test_admin_guard_targets_only_the_staff_role(): void {
        $staff = get_userdata($this->create_staff_user());
        $admin = get_userdata($this->create_user_with_role('administrator'));
        $this->assertSame(home_url('/club-admin/'), EVC_Staff_Admin_Guard::redirect_target($staff, false));
        $this->assertNull(EVC_Staff_Admin_Guard::redirect_target($staff, true), 'admin-ajax.php is never redirected');
        $this->assertNull(EVC_Staff_Admin_Guard::redirect_target($admin, false));
        $this->assertNull(EVC_Staff_Admin_Guard::redirect_target(new WP_User(0), false));

        wp_set_current_user($staff->ID);
        $this->assertFalse(apply_filters('show_admin_bar', true));
        wp_set_current_user($admin->ID);
        $this->assertTrue(apply_filters('show_admin_bar', true), 'administrators keep their admin bar');

        $this->assertSame(home_url('/club-admin/'), apply_filters('login_redirect', admin_url(), '', $staff));
        $this->assertSame(admin_url(), apply_filters('login_redirect', admin_url(), '', $admin));
        $this->assertSame(1, has_action('admin_init', array('EVC_Staff_Admin_Guard', 'maybe_redirect')));
    }
}
