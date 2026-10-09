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

    public static function is_staff_user($user): bool {
        return $user instanceof WP_User && $user->exists() && in_array(self::ROLE, (array) $user->roles, true);
    }
}
