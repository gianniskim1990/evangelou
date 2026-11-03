<?php

/**
 * STAGE-05B: which WordPress accounts may ever get a Club member row
 * (EVC_Wp_Club_Account_Directory; UI-04F rule: staff, administrators and every
 * privileged account never do). Real WordPress roles and capabilities.
 */
final class ClubAccountDirectoryTest extends EVC_WP_Test_Case {
    private function kind(int $user_id): string {
        return (new EVC_Wp_Club_Account_Directory())->account_kind($user_id);
    }

    public function test_plain_customer_and_subscriber_accounts_are_customers(): void {
        if (!get_role('customer')) {
            add_role('customer', 'Customer', array('read' => true)); // WooCommerce's role, as it creates it
        }
        $this->assertSame(EVC_Wp_Account_Directory::CUSTOMER, $this->kind($this->create_user_with_role('customer')));
        $this->assertSame(EVC_Wp_Account_Directory::CUSTOMER, $this->kind($this->create_user_with_role('subscriber')));
        $both = $this->create_user_with_role('subscriber');
        get_userdata($both)->add_role('customer');
        $this->assertSame(EVC_Wp_Account_Directory::CUSTOMER, $this->kind($both));
    }

    public function test_missing_users_are_missing(): void {
        $this->assertSame(EVC_Wp_Account_Directory::MISSING, $this->kind(0));
        $this->assertSame(EVC_Wp_Account_Directory::MISSING, $this->kind(-3));
        $this->assertSame(EVC_Wp_Account_Directory::MISSING, $this->kind(987654321));
    }

    public function test_staff_administrators_and_editors_are_restricted(): void {
        foreach (array('administrator', 'editor', 'author', 'contributor') as $role) {
            $this->assertSame(EVC_Wp_Account_Directory::RESTRICTED, $this->kind($this->create_user_with_role($role)), $role);
        }
        $this->assertSame(EVC_Wp_Account_Directory::RESTRICTED, $this->kind($this->create_staff_user()), 'the shared Club staff account');
    }

    public function test_an_extra_role_or_a_privileged_capability_makes_a_customer_restricted(): void {
        $extra = $this->create_user_with_role('subscriber');
        get_userdata($extra)->add_role('editor');
        $this->assertSame(EVC_Wp_Account_Directory::RESTRICTED, $this->kind($extra));

        add_role('evc_test_unknown_role', 'Unknown', array('read' => true));
        $this->assertSame(EVC_Wp_Account_Directory::RESTRICTED, $this->kind($this->create_user_with_role('evc_test_unknown_role')), 'only the two customer roles');
        remove_role('evc_test_unknown_role');

        foreach (EVC_Wp_Club_Account_Directory::PRIVILEGED_CAPABILITIES as $cap) {
            $u = $this->create_user_with_role('subscriber');
            get_userdata($u)->add_cap($cap);
            $this->assertSame(EVC_Wp_Account_Directory::RESTRICTED, $this->kind($u), $cap);
        }

        $none = $this->create_user_with_role('subscriber');
        get_userdata($none)->set_role('');
        $this->assertSame(EVC_Wp_Account_Directory::RESTRICTED, $this->kind($none), 'no role at all');
    }

    public function test_a_super_admin_is_restricted(): void {
        // Single site: is_super_admin() means "can delete users" (not one of the listed capabilities).
        $u = $this->create_user_with_role('subscriber');
        get_userdata($u)->add_cap('delete_users');
        $this->assertFalse(is_multisite());
        $this->assertTrue(is_super_admin($u));
        $this->assertSame(EVC_Wp_Account_Directory::RESTRICTED, $this->kind($u));
    }
}
