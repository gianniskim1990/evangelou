<?php

/**
 * Task 1C-C.R1: exclusive least-privilege policy for the shared Club account
 * (access) vs. staff-role lifecycle protections (defence), and safe targets
 * for account disabling. Real WordPress capability resolution throughout.
 */
final class StrictStaffPolicyTest extends EVC_WP_Test_Case {
    private function staff_with(callable $mutate): WP_User {
        $user = new WP_User($this->create_staff_user());
        $mutate($user);
        clean_user_cache($user->ID);
        return new WP_User($user->ID); // reload exactly as WordPress would
    }

    // ---- strict access policy ------------------------------------------

    public function test_properly_restricted_shared_account_qualifies(): void {
        $user = new WP_User($this->create_staff_user());
        $this->assertTrue(EVC_Staff_Role::has_staff_role($user));
        $this->assertTrue(EVC_Staff_Role::is_restricted_staff_account($user));
        $this->assertNull(EVC_Staff_Role::restricted_account_violation($user));
    }

    public function test_administrator_without_club_role_is_not_staff(): void {
        $admin = new WP_User($this->create_user_with_role('administrator'));
        $this->assertFalse(EVC_Staff_Role::has_staff_role($admin));
        $this->assertFalse(EVC_Staff_Role::is_restricted_staff_account($admin));
        $this->assertSame('not_staff', EVC_Staff_Role::restricted_account_violation($admin));
    }

    public function test_staff_plus_administrator_role_is_rejected(): void {
        $user = $this->staff_with(function (WP_User $u) {
            $u->add_role('administrator');
        });
        $this->assertSame(array(EVC_Staff_Role::ROLE, 'administrator'), array_values($user->roles));
        $this->assertTrue(EVC_Staff_Role::has_staff_role($user), 'still staff-tagged for lifecycle protections');
        $this->assertFalse(EVC_Staff_Role::is_restricted_staff_account($user));
        $this->assertSame('extra_role', EVC_Staff_Role::restricted_account_violation($user));
    }

    public function test_staff_plus_any_other_role_is_rejected(): void {
        foreach (array('subscriber', 'editor', 'author') as $role) {
            $user = $this->staff_with(function (WP_User $u) use ($role) {
                $u->add_role($role);
            });
            $this->assertFalse(EVC_Staff_Role::is_restricted_staff_account($user), $role);
            $this->assertSame('extra_role', EVC_Staff_Role::restricted_account_violation($user), $role);
        }
    }

    public function test_direct_user_capabilities_are_rejected_even_harmless_looking_ones(): void {
        foreach (array('manage_options', 'edit_users', 'read', 'some_plugin_cap') as $cap) {
            $user = $this->staff_with(function (WP_User $u) use ($cap) {
                $u->add_cap($cap);
            });
            $this->assertTrue(user_can($user, $cap), "precondition: $cap granted directly");
            $this->assertFalse(EVC_Staff_Role::is_restricted_staff_account($user), $cap);
            $this->assertSame('direct_capability', EVC_Staff_Role::restricted_account_violation($user), $cap);
        }
    }

    public function test_direct_denials_are_also_rejected_conservatively(): void {
        $user = $this->staff_with(function (WP_User $u) {
            $u->add_cap('edit_posts', false);
        });
        $this->assertFalse(EVC_Staff_Role::is_restricted_staff_account($user), 'raw caps must be exactly the role');
        $this->assertSame('direct_capability', EVC_Staff_Role::restricted_account_violation($user));
    }

    public function test_tampered_role_definition_is_rejected_by_the_allowlist(): void {
        $id = $this->create_staff_user();
        get_role(EVC_Staff_Role::ROLE)->add_cap('edit_posts');
        $user = new WP_User($id);
        $this->assertTrue(user_can($user, 'edit_posts'));
        $this->assertFalse(EVC_Staff_Role::is_restricted_staff_account($user));
        $this->assertSame('capability_not_allowed', EVC_Staff_Role::restricted_account_violation($user));
    }

    public function test_dynamic_capability_grants_are_rejected(): void {
        $user = new WP_User($this->create_staff_user());
        $grant = function ($allcaps, $caps, $args, $u) use ($user) {
            if ($u->ID === $user->ID) {
                $allcaps['manage_options'] = true;
            }
            return $allcaps;
        };
        add_filter('user_has_cap', $grant, 10, 4);
        $this->assertFalse(EVC_Staff_Role::is_restricted_staff_account($user), 'filter-granted privilege at check time');
        $this->assertSame('dynamic_privilege', EVC_Staff_Role::restricted_account_violation($user));
        remove_filter('user_has_cap', $grant, 10);
        $this->assertTrue(EVC_Staff_Role::is_restricted_staff_account($user));
    }

    public function test_nonexistent_or_anonymous_users_are_rejected(): void {
        $this->assertFalse(EVC_Staff_Role::is_restricted_staff_account(new WP_User(0)));
        $this->assertFalse(EVC_Staff_Role::is_restricted_staff_account(null));
        $this->assertFalse(EVC_Staff_Role::is_restricted_staff_account('evc_club_staff'));
    }

    // ---- lifecycle protections still cover suspicious staff-tagged users

    public function test_login_throttling_still_protects_a_rejected_staff_tagged_account(): void {
        $user = $this->staff_with(function (WP_User $u) {
            $u->add_role('administrator');
        });
        $this->assertFalse(EVC_Staff_Role::is_restricted_staff_account($user));
        for ($i = 0; $i < 5; $i++) {
            wp_authenticate($user->user_login, 'wrong-' . $i);
        }
        $locked = wp_authenticate($user->user_login, self::PASSWORD);
        $this->assertWPError($locked);
        $this->assertSame(EVC_Login_Throttle::ERROR_CODE, $locked->get_error_code());
    }

    public function test_session_policy_and_admin_guard_still_cover_a_rejected_staff_tagged_account(): void {
        $user = $this->staff_with(function (WP_User $u) {
            $u->add_cap('manage_options');
        });
        $this->assertSame(43200, apply_filters('auth_cookie_expiration', 14 * DAY_IN_SECONDS, $user->ID, true));
        $this->assertSame(home_url('/club-admin/'), EVC_Staff_Admin_Guard::redirect_target($user, false));
        $token = $this->login_as($user->ID);
        $session = WP_Session_Tokens::get_instance($user->ID)->get($token);
        $this->assertSame(EVC_Staff_Session::POLICY_VERSION, $session[EVC_Staff_Session::POLICY_FLAG]);
    }

    public function test_emergency_revocation_still_covers_staff_tagged_accounts(): void {
        $plain = $this->create_staff_user();
        $tagged = $this->staff_with(function (WP_User $u) {
            $u->add_role('editor');
        });
        $this->login_as($plain);
        $this->login_as($tagged->ID);
        wp_set_current_user($this->create_user_with_role('administrator'));
        $this->assertSame(2, EVC_Staff_Session::revoke_all_staff_sessions());
        $this->assertCount(0, WP_Session_Tokens::get_instance($plain)->get_all());
        $this->assertCount(0, WP_Session_Tokens::get_instance($tagged->ID)->get_all());
    }

    // ---- set_disabled target validation --------------------------------

    private function snapshot(int $user_id): array {
        return array(
            'disabled_meta' => get_user_meta($user_id, EVC_Staff_Auth::DISABLED_META, true),
            'sessions' => count(WP_Session_Tokens::get_instance($user_id)->get_all()),
        );
    }

    private function as_manager(): void {
        wp_set_current_user($this->create_user_with_role('administrator'));
    }

    public function test_manager_can_disable_and_re_enable_the_shared_account(): void {
        $staff = $this->create_staff_user();
        $this->login_as($staff);
        $this->login_as($staff);
        $this->as_manager();
        $this->assertTrue(EVC_Staff_Auth::set_disabled($staff, true));
        $this->assertTrue(EVC_Staff_Auth::is_disabled($staff));
        $this->assertCount(0, WP_Session_Tokens::get_instance($staff)->get_all(), 'existing sessions invalidated');
        $this->assertTrue(EVC_Staff_Auth::set_disabled($staff, false));
        $this->assertFalse(EVC_Staff_Auth::is_disabled($staff));
    }

    public function test_manager_cannot_disable_unrelated_users(): void {
        if (!get_role('customer')) {
            add_role('customer', 'Customer', array('read' => true)); // WooCommerce-like customer
        }
        $targets = array(
            'administrator' => $this->create_user_with_role('administrator'),
            'editor' => $this->create_user_with_role('editor'),
            'subscriber' => $this->create_user_with_role('subscriber'),
            'customer' => $this->create_user_with_role('customer'),
        );
        foreach ($targets as $label => $id) {
            $this->login_as($id);
        }
        $this->as_manager();
        foreach ($targets as $label => $id) {
            $before = $this->snapshot($id);
            $result = EVC_Staff_Auth::set_disabled($id, true);
            $this->assertWPError($result, $label);
            $this->assertSame('evc_invalid_target', $result->get_error_code(), $label);
            $this->assertSame($before, $this->snapshot($id), "$label: no meta or session changed");
        }
    }

    public function test_nonexistent_and_invalid_targets_change_nothing(): void {
        $staff = $this->create_staff_user();
        $this->login_as($staff);
        $before = $this->snapshot($staff);
        $this->as_manager();
        foreach (array(0, -5, 999999) as $id) {
            $result = EVC_Staff_Auth::set_disabled($id, true);
            $this->assertWPError($result, (string) $id);
            $this->assertSame('evc_invalid_target', $result->get_error_code());
        }
        $this->assertSame($before, $this->snapshot($staff), 'the real staff account is untouched');
    }

    public function test_non_managers_cannot_disable_staff(): void {
        $staff = $this->create_staff_user();
        $this->login_as($staff);
        $before = $this->snapshot($staff);
        foreach (array('editor', 'subscriber') as $role) {
            wp_set_current_user($this->create_user_with_role($role));
            $result = EVC_Staff_Auth::set_disabled($staff, true);
            $this->assertWPError($result, $role);
            $this->assertSame('forbidden', $result->get_error_code());
        }
        wp_set_current_user($staff);
        $this->assertWPError(EVC_Staff_Auth::set_disabled($staff, true), 'the shared account cannot manage itself');
        $this->assertSame($before, $this->snapshot($staff));
    }

    public function test_manager_can_lock_down_a_misconfigured_staff_tagged_account(): void {
        $tagged = $this->staff_with(function (WP_User $u) {
            $u->add_role('editor');
        });
        $this->login_as($tagged->ID);
        $this->as_manager();
        $this->assertTrue(EVC_Staff_Auth::set_disabled($tagged->ID, true));
        $this->assertCount(0, WP_Session_Tokens::get_instance($tagged->ID)->get_all());
    }
}
