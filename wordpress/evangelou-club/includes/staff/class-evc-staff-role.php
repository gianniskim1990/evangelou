<?php
defined('ABSPATH') || exit;

/**
 * The ONE shared, restricted Club staff role (owner decision D2).
 *
 * The role carries only Club capabilities. It deliberately has no `read`
 * capability: WordPress login and REST cookie authentication do not need it
 * (proven by tests), and without it core wp-admin screens refuse the user.
 * Administrators receive `evc_manage_club` (emergency session revocation,
 * disabling the shared account); they never receive the staff capabilities.
 *
 * No user is created here. The shared account is created manually by the
 * owner/technician on staging/production only after explicit approval.
 */
final class EVC_Staff_Role {
    const ROLE = 'evc_club_staff';
    const CAP_LOOKUP = 'evc_lookup_member';
    const CAP_REDEEM = 'evc_redeem_benefit';
    const CAP_HISTORY = 'evc_view_redemption_history';
    const CAP_MANAGE = 'evc_manage_club';

    /** @return string[] The complete, exact capability set of the staff role. */
    public static function staff_capabilities(): array {
        return array(self::CAP_LOOKUP, self::CAP_REDEEM, self::CAP_HISTORY);
    }

    /**
     * Idempotent: creates the role if missing and forces its capability set
     * to EXACTLY the Club capabilities (anything else is removed).
     */
    public static function register(): void {
        $role = get_role(self::ROLE);
        if (!$role) {
            $role = add_role(self::ROLE, 'Evangelou Club staff', array_fill_keys(self::staff_capabilities(), true));
        }
        if (!$role) {
            return;
        }
        foreach (array_keys((array) $role->capabilities) as $cap) {
            if (!in_array($cap, self::staff_capabilities(), true)) {
                $role->remove_cap($cap);
            }
        }
        foreach (self::staff_capabilities() as $cap) {
            if (empty($role->capabilities[$cap])) {
                $role->add_cap($cap);
            }
        }
        $admin = get_role('administrator');
        if ($admin && empty($admin->capabilities[self::CAP_MANAGE])) {
            $admin->add_cap(self::CAP_MANAGE);
        }
    }

    /**
     * Deactivation: removes the role definition and the admin capability.
     * Users and their history are NOT deleted; a user still assigned the
     * role simply has no capabilities while the plugin is inactive.
     */
    public static function unregister(): void {
        remove_role(self::ROLE);
        $admin = get_role('administrator');
        if ($admin) {
            $admin->remove_cap(self::CAP_MANAGE);
        }
    }

    /**
     * DEFENSIVE LIFECYCLE test: the user carries the staff role (possibly
     * together with other roles or capabilities). Used for protections that
     * must also cover a misconfigured/suspicious staff-tagged account:
     * 12 h cookies, session policy marker, login throttling, admin guard,
     * emergency revocation and disabling. NEVER sufficient for access.
     */
    public static function has_staff_role($user): bool {
        return $user instanceof WP_User && $user->exists() && in_array(self::ROLE, (array) $user->roles, true);
    }

    /**
     * Dynamic capabilities that must never be true for the restricted Club
     * account. Secondary defence only (catches `user_has_cap` filters and
     * multisite super admins, which allcaps does not show); the primary
     * rule is the allowlist in is_restricted_staff_account().
     */
    const DYNAMIC_PRIVILEGE_PROBES = array(
        'manage_options', 'edit_users', 'promote_users', 'list_users', 'delete_users',
        'activate_plugins', 'install_plugins', 'edit_posts', 'upload_files', 'unfiltered_html',
        'manage_woocommerce', 'read',
    );

    /**
     * ACCESS test (fail closed): the user is EXCLUSIVELY the restricted
     * shared Club account. WordPress resolves privileges as (WP 7.1
     * WP_User::get_role_caps / has_cap):
     *   roles   = registered role names found in the user's raw caps meta
     *   allcaps = caps of EVERY role, overlaid with the user's direct caps
     *   has_cap = allcaps after the dynamic `user_has_cap` filter;
     *             multisite super admins get everything.
     * Therefore all of the following must hold:
     *  1. exactly one role, and it is the staff role;
     *  2. the raw per-user caps are exactly { evc_club_staff: true } (no
     *     direct grants or denials of anything);
     *  3. ALLOWLIST: every effective capability (allcaps entry that is
     *     truthy) is a Club capability or the role marker itself;
     *  4. not a super admin, and none of the dynamic privilege probes
     *     resolves to true at check time.
     */
    public static function is_restricted_staff_account($user): bool {
        return self::restricted_account_violation($user) === null;
    }

    const VIOLATION_NOT_STAFF = 'not_staff';
    const VIOLATION_EXTRA_ROLE = 'extra_role';
    const VIOLATION_DIRECT_CAPABILITY = 'direct_capability';
    const VIOLATION_CAPABILITY_NOT_ALLOWED = 'capability_not_allowed';
    const VIOLATION_SUPER_ADMIN = 'super_admin';
    const VIOLATION_DYNAMIC_PRIVILEGE = 'dynamic_privilege';

    /**
     * The FIRST rule the account breaks, or null when it is exclusively the
     * restricted Club account. Rules are evaluated in a fixed order so each
     * independent guard is observable in tests (internal only; never sent
     * to clients).
     */
    public static function restricted_account_violation($user): ?string {
        if (!self::has_staff_role($user)) {
            return self::VIOLATION_NOT_STAFF;
        }
        if (array_values((array) $user->roles) !== array(self::ROLE)) {
            return self::VIOLATION_EXTRA_ROLE;
        }
        if ((array) $user->caps !== array(self::ROLE => true)) {
            return self::VIOLATION_DIRECT_CAPABILITY;
        }
        $allowed = array_merge(self::staff_capabilities(), array(self::ROLE));
        foreach ((array) $user->allcaps as $cap => $granted) {
            if ($granted && !in_array((string) $cap, $allowed, true)) {
                return self::VIOLATION_CAPABILITY_NOT_ALLOWED;
            }
        }
        if (is_super_admin($user->ID)) {
            return self::VIOLATION_SUPER_ADMIN;
        }
        foreach (self::DYNAMIC_PRIVILEGE_PROBES as $cap) {
            if (user_can($user, $cap)) {
                return self::VIOLATION_DYNAMIC_PRIVILEGE;
            }
        }
        return null;
    }
}
