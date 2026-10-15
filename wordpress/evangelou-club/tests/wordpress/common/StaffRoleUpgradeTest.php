<?php

/**
 * UI-04I — versioned staff-role upgrade against REAL WordPress roles on the
 * disposable test database. The planner is pure and shipped inert; the
 * applier is TEST-ONLY (EVC_Test_Role_Upgrade). Proves: the shipped role and
 * activation are unchanged, upgrades/rollbacks are exact and idempotent,
 * partial upgrades are detected, every escalation fails closed, unrelated
 * roles are untouched, and — with the CURRENT shipped code — a role that was
 * upgraded without the matching code release makes the shared account fail
 * closed rather than gain access.
 */
final class StaffRoleUpgradeTest extends EVC_WP_Test_Case {
    const V1 = array('evc_lookup_member', 'evc_redeem_benefit', 'evc_view_redemption_history');
    const PROPOSED = array('evc_confirm_handoff', 'evc_view_redemption_status', 'evc_view_usual_coffee');
    const UNRELATED = 'evc_test_unrelated_role';

    public function set_up() {
        parent::set_up();
        add_role(self::UNRELATED, 'Unrelated (test)', array('read' => true, 'edit_posts' => true, 'third_party_cap' => true));
    }

    public function tear_down() {
        // Roles live in an option AND in memory: restore both explicitly.
        $role = get_role(EVC_Staff_Role::ROLE);
        if ($role) {
            foreach (array_merge(self::PROPOSED, array('manage_options', 'evc_review_fulfillment')) as $cap) {
                $role->remove_cap($cap);
            }
        }
        foreach (array('administrator', 'editor') as $name) {
            foreach (self::PROPOSED as $cap) {
                get_role($name)->remove_cap($cap);
            }
        }
        remove_role(self::UNRELATED);
        delete_option(EVC_Staff_Role_Versions::VERSION_OPTION);
        EVC_Staff_Role::register();
        parent::tear_down();
    }

    private function approval(): EVC_Staff_Role_Upgrade_Approval {
        return new EVC_Staff_Role_Upgrade_Approval(2, array_merge(self::V1, self::PROPOSED), 'D-CAP-TEST');
    }

    private function role_caps(): array {
        $caps = array_keys(array_filter((array) get_role(EVC_Staff_Role::ROLE)->capabilities));
        sort($caps);
        return $caps;
    }

    /** Plan, apply (test-only), reload as a new request, verify. */
    private function upgrade(int $target, ?EVC_Staff_Role_Upgrade_Approval $approval): EVC_Staff_Role_Upgrade_Plan {
        $plan = EVC_Staff_Role_Upgrade_Planner::plan(EVC_Test_Role_Upgrade::snapshot(), $target, $approval);
        $this->assertNotSame('refused', $plan->status(), wp_json_encode($plan->to_array()));
        EVC_Test_Role_Upgrade::apply($plan);
        EVC_Test_Role_Upgrade::reload_roles();
        $this->assertSame(array(), EVC_Staff_Role_Upgrade_Planner::verify(EVC_Test_Role_Upgrade::snapshot(), $plan));
        return $plan;
    }

    private function assertRefusedWithoutChanges(string $reason, int $target, ?EVC_Staff_Role_Upgrade_Approval $approval): void {
        $before = wp_json_encode(wp_roles()->roles);
        $plan = EVC_Staff_Role_Upgrade_Planner::plan(EVC_Test_Role_Upgrade::snapshot(), $target, $approval);
        $this->assertSame('refused', $plan->status());
        $this->assertSame($reason, $plan->reason());
        $this->assertSame(0, EVC_Test_Role_Upgrade::apply($plan), 'a refused plan writes nothing');
        EVC_Test_Role_Upgrade::reload_roles();
        $this->assertSame($before, wp_json_encode(wp_roles()->roles));
        $this->assertNull(get_option(EVC_Staff_Role_Versions::VERSION_OPTION, null));
    }

    // ---- current production behaviour is unchanged --------------------------------------------

    public function test_shipped_role_definition_and_activation_are_unchanged(): void {
        $this->assertSame(EVC_Staff_Role::ROLE, EVC_Staff_Role_Versions::ROLE);
        $shipped = EVC_Staff_Role::staff_capabilities();
        sort($shipped);
        $this->assertSame(self::V1, $shipped, 'the proposed capabilities are NOT in the shipped role');
        $this->assertSame(EVC_Staff_Role_Versions::caps(EVC_Staff_Role_Versions::SHIPPED), $shipped);
        EVC_Staff_Plugin::activate();
        $this->assertSame(self::V1, $this->role_caps());
        $this->assertNull(get_option(EVC_Staff_Role_Versions::VERSION_OPTION, null), 'activation records no upgrade version');
        foreach (self::PROPOSED as $cap) {
            $this->assertFalse(user_can(new WP_User($this->create_staff_user()), $cap), $cap);
            $this->assertArrayNotHasKey($cap, get_role('administrator')->capabilities);
        }
        $this->assertTrue(EVC_Staff_Role::is_restricted_staff_account(new WP_User($this->create_staff_user())));
    }

    // ---- upgrade of an existing restricted installation ---------------------------------------

    public function test_existing_installation_upgrade_requires_approval_and_is_exact(): void {
        $staff = $this->create_staff_user();
        $this->assertRefusedWithoutChanges('not_approved', 2, null);
        $plan = $this->upgrade(2, $this->approval());
        $this->assertSame('v1', $plan->to_array()['from_state']);
        $expected = array_merge(self::V1, self::PROPOSED);
        sort($expected);
        $this->assertSame($expected, $this->role_caps());
        $this->assertSame(2, (int) get_option(EVC_Staff_Role_Versions::VERSION_OPTION));
        // No user was created or changed; the single restricted role is still the only role.
        $this->assertSame(array(EVC_Staff_Role::ROLE), array_values((new WP_User($staff))->roles));
        $this->assertSame(array(EVC_Staff_Role::ROLE => true), get_user_meta($staff, $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities', true));
        foreach (array('administrator', 'editor', self::UNRELATED) as $other) {
            $this->assertSame(array(), array_intersect(array_keys(get_role($other)->capabilities), $expected), $other);
        }
        $this->assertSame(array('read' => true, 'edit_posts' => true, 'third_party_cap' => true), get_role(self::UNRELATED)->capabilities);
    }

    public function test_upgrade_persists_across_a_fresh_role_load_and_repeats_are_no_ops(): void {
        $this->upgrade(2, $this->approval());
        $stored = get_option('wp_user_roles') ?: get_option($GLOBALS['wpdb']->get_blog_prefix() . 'user_roles');
        $this->assertIsArray($stored);
        $persisted = array_keys(array_filter($stored[EVC_Staff_Role::ROLE]['capabilities']));
        sort($persisted);
        $this->assertContains('evc_confirm_handoff', $persisted, 'written to the roles option, not only memory');
        for ($i = 0; $i < 3; $i++) {
            $before = get_option($GLOBALS['wpdb']->get_blog_prefix() . 'user_roles');
            $again = EVC_Staff_Role_Upgrade_Planner::plan(EVC_Test_Role_Upgrade::snapshot(), 2, $this->approval());
            $this->assertSame('up_to_date', $again->status());
            $this->assertSame(0, EVC_Test_Role_Upgrade::apply($again));
            $this->assertSame($before, get_option($GLOBALS['wpdb']->get_blog_prefix() . 'user_roles'));
        }
    }

    public function test_new_installation_records_the_shipped_version_only(): void {
        EVC_Staff_Plugin::deactivate();
        $this->assertNull(get_role(EVC_Staff_Role::ROLE));
        $this->assertSame('role_missing', EVC_Staff_Role_Upgrade_Planner::plan(EVC_Test_Role_Upgrade::snapshot(), 1, null)->reason(), 'the upgrade never creates the role');
        EVC_Staff_Plugin::activate();
        $plan = $this->upgrade(1, null);
        $this->assertSame(array(), $plan->to_array()['add']);
        $this->assertSame(1, (int) get_option(EVC_Staff_Role_Versions::VERSION_OPTION));
        $this->assertSame(self::V1, $this->role_caps());
        $this->assertSame('not_approved', EVC_Staff_Role_Upgrade_Planner::plan(EVC_Test_Role_Upgrade::snapshot(), 2, null)->reason());
    }

    public function test_incomplete_upgrade_is_detected_and_resumed(): void {
        foreach (array(1, 2, 3) as $crash_after) {
            $plan = EVC_Staff_Role_Upgrade_Planner::plan(EVC_Test_Role_Upgrade::snapshot(), 2, $this->approval());
            $this->assertSame($crash_after, EVC_Test_Role_Upgrade::apply($plan, $crash_after));
            EVC_Test_Role_Upgrade::reload_roles();
            $this->assertNotSame(array(), EVC_Staff_Role_Upgrade_Planner::verify(EVC_Test_Role_Upgrade::snapshot(), $plan), "crash after $crash_after writes");
            $resume = EVC_Staff_Role_Upgrade_Planner::plan(EVC_Test_Role_Upgrade::snapshot(), 2, $this->approval());
            $this->assertTrue($resume->to_array()['partial']);
            $this->assertCount(3 - $crash_after, $resume->to_array()['add']);
            // While partial, the shipped code already refuses the account (fail closed).
            $this->assertSame('capability_not_allowed', EVC_Staff_Role::restricted_account_violation(new WP_User($this->create_staff_user())));
            // Recovery: either complete it...
            $this->upgrade(2, $this->approval());
            // ...or roll it back; both end in a verified state.
            $this->upgrade(1, null);
            $this->assertSame(self::V1, $this->role_caps());
        }
    }

    public function test_rollback_restores_the_shipped_role_and_access(): void {
        $staff = $this->create_staff_user();
        $this->upgrade(2, $this->approval());
        $rollback = $this->upgrade(1, null);
        $this->assertSame(self::PROPOSED, $rollback->to_array()['remove']);
        $this->assertSame(self::V1, $this->role_caps());
        $this->assertSame(1, (int) get_option(EVC_Staff_Role_Versions::VERSION_OPTION));
        $this->assertTrue(EVC_Staff_Role::is_restricted_staff_account(new WP_User($staff)));
    }

    // ---- coupling with the shipped allowlist, sessions and revocation --------------------------

    public function test_role_upgraded_without_the_matching_code_release_fails_closed(): void {
        $staff = $this->create_staff_user();
        $token = $this->login_as($staff);
        $this->assertNull(EVC_Staff_Auth::check_account(new WP_User($staff), EVC_Staff_Role::CAP_REDEEM, false), 'precondition: active session works');

        $this->upgrade(2, $this->approval());
        $user = new WP_User($staff);
        $this->assertTrue(user_can($user, 'evc_confirm_handoff'), 'the role now carries the capability');
        // The shipped allowlist (v1) rejects the account entirely: no route, old or new, is reachable.
        $this->assertSame('capability_not_allowed', EVC_Staff_Role::restricted_account_violation($user));
        foreach (array(EVC_Staff_Role::CAP_REDEEM, EVC_Staff_Role::CAP_LOOKUP, null) as $cap) {
            $error = EVC_Staff_Auth::check_account($user, $cap, false);
            $this->assertInstanceOf(WP_Error::class, $error);
            $this->assertSame('forbidden', $error->get_error_code());
        }
        // The active session itself is untouched (no silent privilege, no silent logout).
        $this->assertIsArray(WP_Session_Tokens::get_instance($staff)->get($token));

        // Emergency revocation still works on the upgraded role.
        wp_set_current_user($this->create_user_with_role('administrator'));
        $this->assertSame(1, EVC_Staff_Session::revoke_all_staff_sessions());
        $this->assertNull(WP_Session_Tokens::get_instance($staff)->get($token));

        // Rollback restores normal, fully checked access for a NEW session.
        $this->upgrade(1, null);
        $this->login_as($staff);
        $this->assertNull(EVC_Staff_Auth::check_account(new WP_User($staff), EVC_Staff_Role::CAP_REDEEM, false));
    }

    public function test_reactivation_after_an_upgrade_silently_restores_v1_and_is_detected(): void {
        $this->upgrade(2, $this->approval());
        // register() forces the exact shipped set (hazard the rollout plan must handle).
        EVC_Staff_Plugin::activate();
        $this->assertSame(self::V1, $this->role_caps());
        $plan = EVC_Staff_Role_Upgrade_Planner::plan(EVC_Test_Role_Upgrade::snapshot(), 2, $this->approval())->to_array();
        $this->assertTrue($plan['partial'], 'recorded version 2 no longer matches the role');
        $this->assertSame(self::PROPOSED, $plan['add']);
    }

    // ---- escalation attempts fail closed and change nothing -----------------------------------

    public function test_extra_capability_on_the_staff_role_fails_closed(): void {
        foreach (array('manage_options', 'evc_review_fulfillment') as $cap) {
            get_role(EVC_Staff_Role::ROLE)->add_cap($cap);
            $this->assertRefusedWithoutChanges('unexpected_role_capability', 2, $this->approval());
            $this->assertRefusedWithoutChanges('unexpected_role_capability', 1, null);
            get_role(EVC_Staff_Role::ROLE)->remove_cap($cap);
        }
    }

    public function test_administrator_escalation_attempts_fail_closed(): void {
        get_role('administrator')->add_cap('evc_confirm_handoff');
        $this->assertRefusedWithoutChanges('staff_capability_on_other_role', 2, $this->approval());
        get_role('administrator')->remove_cap('evc_confirm_handoff');

        $dual = $this->create_staff_user();
        (new WP_User($dual))->add_role('administrator');
        clean_user_cache($dual);
        $this->assertRefusedWithoutChanges('staff_account_violation', 2, $this->approval());
    }

    public function test_multiple_roles_and_direct_grants_fail_closed(): void {
        $multi = $this->create_staff_user();
        (new WP_User($multi))->add_role('subscriber');
        clean_user_cache($multi);
        $this->assertRefusedWithoutChanges('staff_account_violation', 2, $this->approval());
        (new WP_User($multi))->remove_role('subscriber');
        clean_user_cache($multi);

        $direct = $this->create_staff_user();
        (new WP_User($direct))->add_cap('evc_view_usual_coffee');
        clean_user_cache($direct);
        $this->assertRefusedWithoutChanges('staff_account_violation', 2, $this->approval());
        (new WP_User($direct))->remove_cap('evc_view_usual_coffee');
        clean_user_cache($direct);

        $editor = $this->create_user_with_role('editor');
        (new WP_User($editor))->add_cap('evc_confirm_handoff');
        clean_user_cache($editor);
        $this->assertRefusedWithoutChanges('unauthorized_direct_capability', 2, $this->approval());
    }

    public function test_unrelated_roles_and_users_are_never_touched(): void {
        $editor = $this->create_user_with_role('editor');
        $before_roles = wp_roles()->roles;
        unset($before_roles[EVC_Staff_Role::ROLE]);
        $before_editor = get_user_meta($editor, $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities', true);
        $this->upgrade(2, $this->approval());
        $this->upgrade(1, null);
        $after_roles = wp_roles()->roles;
        unset($after_roles[EVC_Staff_Role::ROLE]);
        $this->assertSame($before_roles, $after_roles);
        $this->assertSame($before_editor, get_user_meta($editor, $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities', true));
    }
}
