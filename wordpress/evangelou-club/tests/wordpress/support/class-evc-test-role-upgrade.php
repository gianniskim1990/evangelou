<?php
/**
 * TEST-ONLY (UI-04I; never shipped — the packager rejects "class EVC_Test_").
 *
 * Reads a planner snapshot from the REAL WordPress role/user data of the
 * disposable test site and simulates the FUTURE applier (capabilities first,
 * recorded version LAST, optionally "crashing" after N writes). No shipped
 * code applies a plan; this exists only so the dry-run planner and its
 * verification can be exercised against real WordPress persistence.
 */
final class EVC_Test_Role_Upgrade {
    public static function snapshot(): array {
        $roles = array();
        foreach (wp_roles()->roles as $name => $def) {
            $roles[$name] = (array) $def['capabilities'];
        }
        $stored = get_option(EVC_Staff_Role_Versions::VERSION_OPTION, null);
        $staff_users = array();
        $direct = array();
        $meta_key = $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities';
        foreach (get_users(array('fields' => 'ID')) as $id) {
            $id = (int) $id;
            $raw = get_user_meta($id, $meta_key, true);
            $raw = is_array($raw) ? $raw : array();
            $user = new WP_User($id);
            if (in_array(EVC_Staff_Role_Versions::ROLE, (array) $user->roles, true)) {
                $staff_users[] = array('id' => $id, 'roles' => array_values((array) $user->roles), 'caps' => $raw, 'super_admin' => is_super_admin($id));
            }
            $club = array();
            foreach (array_keys($raw) as $cap) {
                if (strpos((string) $cap, 'evc_') === 0 && !isset($roles[$cap])) {
                    $club[] = (string) $cap;
                }
            }
            if ($club) {
                $direct[] = array('id' => $id, 'caps' => $club);
            }
        }
        return array(
            'staff_role' => isset($roles[EVC_Staff_Role_Versions::ROLE]) ? $roles[EVC_Staff_Role_Versions::ROLE] : null,
            'stored_version' => is_numeric($stored) ? (int) $stored : null,
            'roles' => $roles,
            'staff_users' => $staff_users,
            'direct_club_grants' => $direct,
        );
    }

    /** @return int number of writes performed */
    public static function apply(EVC_Staff_Role_Upgrade_Plan $plan, ?int $crash_after = null): int {
        $p = $plan->to_array();
        if ($p['status'] !== EVC_Staff_Role_Upgrade_Plan::READY) {
            return 0;
        }
        $role = get_role(EVC_Staff_Role_Versions::ROLE);
        $writes = 0;
        foreach ($p['add'] as $cap) {
            if ($crash_after !== null && $writes >= $crash_after) {
                return $writes;
            }
            $role->add_cap($cap);
            $writes++;
        }
        foreach ($p['remove'] as $cap) {
            if ($crash_after !== null && $writes >= $crash_after) {
                return $writes;
            }
            $role->remove_cap($cap);
            $writes++;
        }
        if ($p['set_version'] !== null && ($crash_after === null || $writes < $crash_after)) {
            update_option(EVC_Staff_Role_Versions::VERSION_OPTION, $p['set_version'], false);
            $writes++;
        }
        return $writes;
    }

    /** Reloads roles from the database, as a fresh PHP request would. */
    public static function reload_roles(): void {
        wp_roles()->for_site();
    }
}
