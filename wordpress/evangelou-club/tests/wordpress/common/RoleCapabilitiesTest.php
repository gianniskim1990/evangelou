<?php

final class RoleCapabilitiesTest extends EVC_WP_Test_Case {
    const FORBIDDEN_CAPS = array(
        'read', 'manage_options', 'edit_users', 'list_users', 'create_users', 'delete_users', 'promote_users',
        'install_plugins', 'activate_plugins', 'edit_plugins', 'update_plugins', 'delete_plugins',
        'switch_themes', 'edit_theme_options', 'edit_posts', 'edit_pages', 'upload_files', 'unfiltered_html',
        'manage_woocommerce', 'view_woocommerce_reports', 'pmpro_memberships_menu', 'pmpro_membershiplevels',
        'fcrm_manage_settings', 'fcrm_read_contacts', 'export', 'import', 'evc_manage_club',
    );

    public function test_feature_flag_parser_accepts_only_boolean_true(): void {
        $this->assertTrue(EVC_Staff_Feature::is_enabled_value(true));
        foreach (array(null, false, 0, 1, '1', 'true', 'TRUE', 'yes', 'on', 'false', '', array(true), 1.0) as $value) {
            $this->assertFalse(EVC_Staff_Feature::is_enabled_value($value), var_export($value, true));
        }
    }

    public function test_role_has_exactly_the_club_capabilities(): void {
        $role = get_role(EVC_Staff_Role::ROLE);
        $this->assertNotNull($role);
        $caps = array_keys(array_filter((array) $role->capabilities));
        sort($caps);
        $this->assertSame(array('evc_lookup_member', 'evc_redeem_benefit', 'evc_view_redemption_history'), $caps);
    }

    public function test_shared_staff_user_is_not_an_administrator_and_lacks_sensitive_caps(): void {
        $user = get_userdata($this->create_staff_user());
        $this->assertSame(array(EVC_Staff_Role::ROLE), array_values($user->roles));
        $this->assertFalse(is_super_admin($user->ID));
        foreach (self::FORBIDDEN_CAPS as $cap) {
            $this->assertFalse(user_can($user, $cap), "staff must not have $cap");
        }
        foreach (EVC_Staff_Role::staff_capabilities() as $cap) {
            $this->assertTrue(user_can($user, $cap), $cap);
        }
    }

    public function test_register_is_idempotent_and_strips_unexpected_caps(): void {
        get_role(EVC_Staff_Role::ROLE)->add_cap('manage_options');
        EVC_Staff_Role::register();
        EVC_Staff_Role::register();
        $role = get_role(EVC_Staff_Role::ROLE);
        $this->assertArrayNotHasKey('manage_options', $role->capabilities);
        $this->assertCount(3, array_filter($role->capabilities));
    }

    public function test_administrators_get_manage_but_never_staff_caps(): void {
        $admin = get_userdata($this->create_user_with_role('administrator'));
        $this->assertTrue(user_can($admin, EVC_Staff_Role::CAP_MANAGE));
        $this->assertFalse(EVC_Staff_Role::has_staff_role($admin));
        $this->assertArrayNotHasKey(EVC_Staff_Role::CAP_REDEEM, get_role('administrator')->capabilities);
    }

    public function test_deactivation_removes_role_and_admin_cap_but_keeps_the_user(): void {
        $staff = $this->create_staff_user();
        EVC_Staff_Plugin::deactivate();
        $this->assertNull(get_role(EVC_Staff_Role::ROLE));
        $this->assertArrayNotHasKey(EVC_Staff_Role::CAP_MANAGE, get_role('administrator')->capabilities);
        $user = new WP_User($staff);
        $this->assertTrue($user->exists(), 'deactivation never deletes the shared user');
        $this->assertFalse(user_can($user, EVC_Staff_Role::CAP_REDEEM), 'no capabilities while inactive');
        EVC_Staff_Plugin::activate();
        $this->assertTrue(user_can(new WP_User($staff), EVC_Staff_Role::CAP_REDEEM), 'reactivation restores the role');
    }

    public function test_staff_can_log_in_without_the_read_capability(): void {
        $id = $this->create_staff_user();
        $login = get_userdata($id)->user_login;
        $result = wp_authenticate($login, self::PASSWORD);
        $this->assertInstanceOf(WP_User::class, $result);
        $this->assertSame($id, (int) $result->ID);
        $this->assertFalse(user_can($result, 'read'), '`read` is not needed to authenticate');
    }
}
