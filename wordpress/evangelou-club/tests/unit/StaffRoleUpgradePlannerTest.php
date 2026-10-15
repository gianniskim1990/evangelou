<?php
use PHPUnit\Framework\TestCase;

/**
 * UI-04I — pure staff-role upgrade planner / dry-run validator. Nothing is
 * applied here; snapshots are plain arrays shaped like WordPress role data.
 */
final class StaffRoleUpgradePlannerTest extends TestCase {
    const ROLE = 'evc_club_staff';
    const V1 = array('evc_lookup_member', 'evc_redeem_benefit', 'evc_view_redemption_history');
    const PROPOSED = array('evc_confirm_handoff', 'evc_view_redemption_status', 'evc_view_usual_coffee');

    private static function caps(array $names): array {
        return array_fill_keys($names, true);
    }

    private function snapshot(array $role_caps, ?int $stored = null, array $overrides = array()): array {
        $role = self::caps($role_caps);
        return array_merge(array(
            'staff_role' => $role,
            'stored_version' => $stored,
            'roles' => array(
                'administrator' => self::caps(array('manage_options', 'edit_users', 'evc_manage_club')),
                'editor' => self::caps(array('edit_posts', 'read')),
                'shop_manager' => self::caps(array('manage_woocommerce', 'read')),
                self::ROLE => $role,
            ),
            'staff_users' => array(array('id' => 7, 'roles' => array(self::ROLE), 'caps' => array(self::ROLE => true), 'super_admin' => false)),
            'direct_club_grants' => array(),
        ), $overrides);
    }

    private function approval(): EVC_Staff_Role_Upgrade_Approval {
        return new EVC_Staff_Role_Upgrade_Approval(2, array_merge(self::V1, self::PROPOSED), 'D-CAP-TEST');
    }

    private function assertRefused(string $reason, EVC_Staff_Role_Upgrade_Plan $plan): void {
        $this->assertSame('refused', $plan->status(), json_encode($plan->to_array()));
        $this->assertSame($reason, $plan->reason());
        $this->assertSame(array(), $plan->to_array()['add'], 'a refusal never proposes a change');
        $this->assertSame(array(), $plan->to_array()['remove']);
        $this->assertNull($plan->to_array()['set_version']);
    }

    /** Applies a plan to a snapshot exactly like the future applier (caps first, version LAST). */
    private function apply(array $snapshot, EVC_Staff_Role_Upgrade_Plan $plan, ?int $stop_after_ops = null): array {
        $p = $plan->to_array();
        $ops = 0;
        foreach ($p['add'] as $cap) {
            if ($stop_after_ops !== null && $ops++ >= $stop_after_ops) {
                return $snapshot;
            }
            $snapshot['staff_role'][$cap] = true;
            $snapshot['roles'][self::ROLE] = $snapshot['staff_role'];
        }
        foreach ($p['remove'] as $cap) {
            if ($stop_after_ops !== null && $ops++ >= $stop_after_ops) {
                return $snapshot;
            }
            unset($snapshot['staff_role'][$cap]);
            $snapshot['roles'][self::ROLE] = $snapshot['staff_role'];
        }
        if ($p['set_version'] !== null && ($stop_after_ops === null || $ops < $stop_after_ops)) {
            $snapshot['stored_version'] = $p['set_version'];
        }
        return $snapshot;
    }

    // ---- definitions -------------------------------------------------------------------------

    public function test_versions_are_exact_and_the_shipped_version_is_unchanged(): void {
        $this->assertSame(1, EVC_Staff_Role_Versions::SHIPPED);
        $this->assertSame(self::V1, EVC_Staff_Role_Versions::caps(1));
        $v2 = array_merge(self::V1, self::PROPOSED);
        sort($v2);
        $this->assertSame($v2, EVC_Staff_Role_Versions::caps(2));
        $this->assertNull(EVC_Staff_Role_Versions::caps(3));
        $this->assertSame(array('evc_review_fulfillment', 'evc_manage_club'), EVC_Staff_Role_Versions::MANAGER_ONLY);
        $this->assertSame(array(), array_intersect(EVC_Staff_Role_Versions::MANAGER_ONLY, EVC_Staff_Role_Versions::staff_union()), 'manager capabilities are never staff');
    }

    public function test_approval_reference_and_capabilities_are_validated(): void {
        foreach (array('', 'd-cap', 'X', 'D CAP', str_repeat('A', 33)) as $bad) {
            try {
                new EVC_Staff_Role_Upgrade_Approval(2, self::V1, $bad);
                $this->fail($bad);
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid approval reference.', $e->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new EVC_Staff_Role_Upgrade_Approval(2, array(1, 2), 'D-CAP-1');
    }

    // ---- planning ----------------------------------------------------------------------------

    public function test_existing_v1_installation_without_approval_is_refused(): void {
        $this->assertRefused('not_approved', EVC_Staff_Role_Upgrade_Planner::plan($this->snapshot(self::V1), 2, null));
    }

    public function test_existing_v1_installation_with_exact_approval_plans_the_three_capabilities(): void {
        $plan = EVC_Staff_Role_Upgrade_Planner::plan($this->snapshot(self::V1), 2, $this->approval())->to_array();
        $this->assertSame('ready', $plan['status']);
        $this->assertSame('v1', $plan['from_state']);
        $this->assertNull($plan['stored_version']);
        $this->assertSame(array('evc_confirm_handoff', 'evc_view_redemption_status', 'evc_view_usual_coffee'), $plan['add']);
        $this->assertSame(array(), $plan['remove']);
        $this->assertSame(2, $plan['set_version']);
        $this->assertFalse($plan['partial']);
    }

    public function test_approval_must_match_the_version_exactly(): void {
        $snapshot = $this->snapshot(self::V1);
        $wrong = array(
            new EVC_Staff_Role_Upgrade_Approval(1, array_merge(self::V1, self::PROPOSED), 'D-CAP-1'),
            new EVC_Staff_Role_Upgrade_Approval(2, array_merge(self::V1, array('evc_confirm_handoff')), 'D-CAP-1'),
            new EVC_Staff_Role_Upgrade_Approval(2, array_merge(self::V1, self::PROPOSED, array('evc_review_fulfillment')), 'D-CAP-1'),
            new EVC_Staff_Role_Upgrade_Approval(3, array_merge(self::V1, self::PROPOSED), 'D-CAP-1'),
        );
        foreach ($wrong as $i => $approval) {
            $this->assertRefused('not_approved', EVC_Staff_Role_Upgrade_Planner::plan($snapshot, 2, $approval));
        }
        // Order of the approved list does not matter; content does.
        $shuffled = new EVC_Staff_Role_Upgrade_Approval(2, array_reverse(array_merge(self::V1, self::PROPOSED)), 'D-CAP-1');
        $this->assertSame('ready', EVC_Staff_Role_Upgrade_Planner::plan($snapshot, 2, $shuffled)->status());
    }

    public function test_new_installation_and_repeated_execution_are_idempotent(): void {
        // New install: activation creates the v1 role; no version recorded yet.
        $s = $this->snapshot(self::V1);
        $record = EVC_Staff_Role_Upgrade_Planner::plan($s, 1, null)->to_array();
        $this->assertSame('ready', $record['status'], 'only the version is recorded');
        $this->assertSame(array(), $record['add']);
        $this->assertSame(1, $record['set_version']);

        $plan = EVC_Staff_Role_Upgrade_Planner::plan($s, 2, $this->approval());
        $after = $this->apply($s, $plan);
        $this->assertSame(array(), EVC_Staff_Role_Upgrade_Planner::verify($after, $plan));
        for ($i = 0; $i < 3; $i++) {
            $again = EVC_Staff_Role_Upgrade_Planner::plan($after, 2, $this->approval());
            $this->assertSame('up_to_date', $again->status());
            $this->assertSame(array(), $again->to_array()['add']);
            $this->assertNull($again->to_array()['set_version']);
            $this->assertFalse($again->to_array()['partial']);
        }
        // Even without the approval, an already-applied v2 is just "up to date" (nothing to grant).
        $this->assertSame('up_to_date', EVC_Staff_Role_Upgrade_Planner::plan($after, 2, null)->status());
    }

    public function test_partial_upgrade_is_detected_and_completed_safely(): void {
        $s = $this->snapshot(self::V1);
        $plan = EVC_Staff_Role_Upgrade_Planner::plan($s, 2, $this->approval());
        foreach (array(0, 1, 2, 3) as $stop) {
            $crashed = $this->apply($s, $plan, $stop);
            $this->assertNotSame(array(), EVC_Staff_Role_Upgrade_Planner::verify($crashed, $plan), "crash after $stop ops is not verified");
            $resume = EVC_Staff_Role_Upgrade_Planner::plan($crashed, 2, $this->approval())->to_array();
            $this->assertSame('ready', $resume['status']);
            $this->assertSame(2, $resume['set_version']);
            $this->assertSame($stop !== 0, $resume['partial'], "stop $stop");
            $this->assertCount(3 - min($stop, 3), $resume['add']);
            $this->assertSame(array(), EVC_Staff_Role_Upgrade_Planner::verify($this->apply($crashed, EVC_Staff_Role_Upgrade_Planner::plan($crashed, 2, $this->approval())), EVC_Staff_Role_Upgrade_Planner::plan($crashed, 2, $this->approval())));
        }
        // All capabilities written but the version not yet recorded: partial, only the version remains.
        $caps_only = $this->snapshot(array_merge(self::V1, self::PROPOSED));
        $plan = EVC_Staff_Role_Upgrade_Planner::plan($caps_only, 2, null)->to_array();
        $this->assertTrue($plan['partial']);
        $this->assertSame(array(), $plan['add']);
        $this->assertSame(2, $plan['set_version']);
        // Completing a partial upgrade that still needs grants requires the approval again.
        $this->assertRefused('not_approved', EVC_Staff_Role_Upgrade_Planner::plan($this->snapshot(array_merge(self::V1, array('evc_confirm_handoff'))), 2, null));
        // A recorded version that disagrees with the role is partial too.
        $this->assertTrue(EVC_Staff_Role_Upgrade_Planner::plan($this->snapshot(self::V1, 2), 2, $this->approval())->to_array()['partial']);
    }

    public function test_rollback_removes_only_the_proposed_capabilities_without_approval(): void {
        $v2 = $this->snapshot(array_merge(self::V1, self::PROPOSED), 2);
        $plan = EVC_Staff_Role_Upgrade_Planner::plan($v2, 1, null);
        $p = $plan->to_array();
        $this->assertSame('ready', $p['status']);
        $this->assertSame('v2', $p['from_state']);
        $this->assertSame(array(), $p['add']);
        $this->assertSame(array('evc_confirm_handoff', 'evc_view_redemption_status', 'evc_view_usual_coffee'), $p['remove']);
        $this->assertSame(1, $p['set_version']);
        $after = $this->apply($v2, $plan);
        $this->assertSame(array(), EVC_Staff_Role_Upgrade_Planner::verify($after, $plan));
        $this->assertSame(self::caps(self::V1), $after['staff_role']);
    }

    public function test_damaged_role_is_repaired_without_approval_only_within_the_shipped_set(): void {
        $p = EVC_Staff_Role_Upgrade_Planner::plan($this->snapshot(array('evc_lookup_member')), 1, null)->to_array();
        $this->assertSame('ready', $p['status']);
        $this->assertSame('damaged', $p['from_state']);
        $this->assertTrue($p['partial']);
        $this->assertSame(array('evc_redeem_benefit', 'evc_view_redemption_history'), $p['add']);
        $this->assertRefused('not_approved', EVC_Staff_Role_Upgrade_Planner::plan($this->snapshot(array('evc_lookup_member')), 2, null));
    }

    // ---- fail-closed refusals ----------------------------------------------------------------

    public function test_unexpected_privilege_on_the_staff_role_fails_closed(): void {
        foreach (array('manage_options', 'read', 'edit_users', 'manage_woocommerce', 'pmpro_memberships_menu', 'evc_manage_club', 'evc_review_fulfillment', 'promote_users') as $cap) {
            $plan = EVC_Staff_Role_Upgrade_Planner::plan($this->snapshot(array_merge(self::V1, array($cap))), 2, $this->approval());
            $this->assertRefused('unexpected_role_capability', $plan);
            $this->assertSame(array('capabilities' => array($cap)), $plan->to_array()['violations'], 'never silently stripped');
            $this->assertRefused('unexpected_role_capability', EVC_Staff_Role_Upgrade_Planner::plan($this->snapshot(array_merge(self::V1, array($cap))), 1, null), 'not even a rollback');
        }
    }

    public function test_denials_on_the_role_fail_closed(): void {
        $s = $this->snapshot(self::V1);
        $s['staff_role']['evc_confirm_handoff'] = false;
        $s['roles'][self::ROLE] = $s['staff_role'];
        $this->assertRefused('role_capability_denial', EVC_Staff_Role_Upgrade_Planner::plan($s, 2, $this->approval()));
    }

    public function test_staff_capabilities_on_any_other_role_fail_closed(): void {
        foreach (array('administrator', 'editor', 'shop_manager') as $role) {
            foreach (array('evc_confirm_handoff', 'evc_redeem_benefit') as $cap) {
                $s = $this->snapshot(self::V1);
                $s['roles'][$role][$cap] = true;
                $plan = EVC_Staff_Role_Upgrade_Planner::plan($s, 2, $this->approval());
                $this->assertRefused('staff_capability_on_other_role', $plan);
                $this->assertSame($role, $plan->to_array()['violations']['role']);
            }
        }
        // The administrator's manager capability is expected and allowed.
        $this->assertSame('ready', EVC_Staff_Role_Upgrade_Planner::plan($this->snapshot(self::V1), 2, $this->approval())->status());
    }

    public function test_misconfigured_staff_accounts_fail_closed(): void {
        $cases = array(
            'extra administrator role' => array('id' => 8, 'roles' => array(self::ROLE, 'administrator'), 'caps' => array(self::ROLE => true, 'administrator' => true), 'super_admin' => false),
            'extra editor role' => array('id' => 8, 'roles' => array(self::ROLE, 'editor'), 'caps' => array(self::ROLE => true, 'editor' => true), 'super_admin' => false),
            'direct manage_options' => array('id' => 8, 'roles' => array(self::ROLE), 'caps' => array(self::ROLE => true, 'manage_options' => true), 'super_admin' => false),
            'direct proposed capability' => array('id' => 8, 'roles' => array(self::ROLE), 'caps' => array(self::ROLE => true, 'evc_confirm_handoff' => true), 'super_admin' => false),
            'direct denial' => array('id' => 8, 'roles' => array(self::ROLE), 'caps' => array(self::ROLE => true, 'read' => false), 'super_admin' => false),
            'extra role listed without its capability key' => array('id' => 8, 'roles' => array(self::ROLE, 'shop_manager'), 'caps' => array(self::ROLE => true), 'super_admin' => false),
            'super admin' => array('id' => 8, 'roles' => array(self::ROLE), 'caps' => array(self::ROLE => true), 'super_admin' => true),
            'super admin flag not a bool' => array('id' => 8, 'roles' => array(self::ROLE), 'caps' => array(self::ROLE => true), 'super_admin' => 0),
        );
        foreach ($cases as $label => $user) {
            $s = $this->snapshot(self::V1);
            $s['staff_users'][] = $user;
            $plan = EVC_Staff_Role_Upgrade_Planner::plan($s, 2, $this->approval());
            $this->assertRefused('staff_account_violation', $plan);
            $this->assertSame(array('user_ids' => array(8)), $plan->to_array()['violations'], $label);
            $this->assertRefused('staff_account_violation', EVC_Staff_Role_Upgrade_Planner::plan($s, 1, null), "$label: rollback refused too");
        }
    }

    public function test_direct_club_capabilities_on_any_user_fail_closed(): void {
        foreach (array('evc_confirm_handoff', 'evc_lookup_member', 'evc_review_fulfillment', 'evc_manage_club') as $cap) {
            $s = $this->snapshot(self::V1, null, array('direct_club_grants' => array(array('id' => 42, 'caps' => array($cap)))));
            $plan = EVC_Staff_Role_Upgrade_Planner::plan($s, 2, $this->approval());
            $this->assertRefused('unauthorized_direct_capability', $plan);
            $this->assertSame(array('user_ids' => array(42)), $plan->to_array()['violations']);
        }
        $s = $this->snapshot(self::V1, null, array('direct_club_grants' => array(array('id' => 42, 'caps' => array('evc_something_unrelated')))));
        $this->assertSame('ready', EVC_Staff_Role_Upgrade_Planner::plan($s, 2, $this->approval())->status());
    }

    public function test_missing_role_unknown_versions_and_malformed_snapshots_fail_closed(): void {
        $this->assertRefused('role_missing', EVC_Staff_Role_Upgrade_Planner::plan($this->snapshot(self::V1, null, array('staff_role' => null, 'roles' => array('editor' => array()))), 2, $this->approval()));
        $this->assertRefused('unknown_target_version', EVC_Staff_Role_Upgrade_Planner::plan($this->snapshot(self::V1), 3, $this->approval()));
        $this->assertRefused('unknown_target_version', EVC_Staff_Role_Upgrade_Planner::plan($this->snapshot(self::V1), 0, null));
        $this->assertRefused('unknown_stored_version', EVC_Staff_Role_Upgrade_Planner::plan($this->snapshot(self::V1, 3), 1, null));
        $bad = array(
            array(),
            array_diff_key($this->snapshot(self::V1), array('direct_club_grants' => 1)),
            $this->snapshot(self::V1, null, array('stored_version' => '2')),
            $this->snapshot(self::V1, null, array('roles' => array(self::ROLE => array('evc_lookup_member' => true)))),
            $this->snapshot(self::V1, null, array('staff_users' => array(array('id' => '7', 'roles' => array(self::ROLE), 'caps' => array(), 'super_admin' => false)))),
            $this->snapshot(self::V1, null, array('staff_users' => array(array('id' => 7, 'roles' => array(self::ROLE), 'caps' => array())))),
            $this->snapshot(self::V1, null, array('direct_club_grants' => array(array('id' => 1)))),
            $this->snapshot(self::V1, null, array('roles' => array('editor' => 'read'))),
        );
        foreach ($bad as $i => $s) {
            $this->assertRefused('invalid_snapshot', EVC_Staff_Role_Upgrade_Planner::plan($s, 2, $this->approval()), "case $i");
        }
    }

    // ---- verification --------------------------------------------------------------------------

    public function test_verify_detects_every_unsafe_or_incomplete_result(): void {
        $s = $this->snapshot(self::V1);
        $plan = EVC_Staff_Role_Upgrade_Planner::plan($s, 2, $this->approval());
        $good = $this->apply($s, $plan);
        $this->assertSame(array(), EVC_Staff_Role_Upgrade_Planner::verify($good, $plan));

        $unrelated = $good;
        $unrelated['roles']['editor']['delete_posts'] = true;
        $this->assertSame(array('unrelated_roles_changed'), EVC_Staff_Role_Upgrade_Planner::verify($unrelated, $plan));

        $admin = $good;
        $admin['roles']['administrator']['evc_confirm_handoff'] = true;
        $this->assertContains('unsafe_after_upgrade:staff_capability_on_other_role', EVC_Staff_Role_Upgrade_Planner::verify($admin, $plan));

        $extra = $good;
        $extra['staff_role']['manage_options'] = true;
        $extra['roles'][self::ROLE] = $extra['staff_role'];
        $this->assertContains('role_capabilities_mismatch', EVC_Staff_Role_Upgrade_Planner::verify($extra, $plan));
        $this->assertContains('unsafe_after_upgrade:unexpected_role_capability', EVC_Staff_Role_Upgrade_Planner::verify($extra, $plan));

        $denial = $good;
        $denial['staff_role']['read'] = false;
        $denial['roles'][self::ROLE] = $denial['staff_role'];
        $this->assertContains('role_capabilities_mismatch', EVC_Staff_Role_Upgrade_Planner::verify($denial, $plan));

        $no_version = $good;
        $no_version['stored_version'] = null;
        $this->assertSame(array('version_not_recorded'), EVC_Staff_Role_Upgrade_Planner::verify($no_version, $plan));

        $account = $good;
        $account['staff_users'][0]['roles'][] = 'administrator';
        $this->assertSame(array('unsafe_after_upgrade:staff_account_violation'), EVC_Staff_Role_Upgrade_Planner::verify($account, $plan));

        $direct = $good;
        $direct['direct_club_grants'][] = array('id' => 9, 'caps' => array('evc_view_usual_coffee'));
        $this->assertSame(array('unsafe_after_upgrade:unauthorized_direct_capability'), EVC_Staff_Role_Upgrade_Planner::verify($direct, $plan));

        $this->assertSame(array('plan_was_refused'), EVC_Staff_Role_Upgrade_Planner::verify($good, EVC_Staff_Role_Upgrade_Planner::plan($s, 2, null)));
        $this->assertSame(array('invalid_snapshot'), EVC_Staff_Role_Upgrade_Planner::verify(array(), $plan));
    }

    public function test_fingerprint_ignores_order_but_not_content(): void {
        $a = array('editor' => array('read' => true, 'edit_posts' => true), 'author' => array('read' => true), self::ROLE => array('x' => true));
        $b = array('author' => array('read' => true), 'editor' => array('edit_posts' => true, 'read' => true), self::ROLE => array('y' => true));
        $this->assertSame(EVC_Staff_Role_Upgrade_Planner::other_roles_fingerprint($a), EVC_Staff_Role_Upgrade_Planner::other_roles_fingerprint($b), 'staff role excluded, order irrelevant');
        $b['editor']['edit_posts'] = false;
        $this->assertNotSame(EVC_Staff_Role_Upgrade_Planner::other_roles_fingerprint($a), EVC_Staff_Role_Upgrade_Planner::other_roles_fingerprint($b));
        $c = $a;
        $c['subscriber'] = array();
        $this->assertNotSame(EVC_Staff_Role_Upgrade_Planner::other_roles_fingerprint($a), EVC_Staff_Role_Upgrade_Planner::other_roles_fingerprint($c), 'an added role is a change');
    }
}
