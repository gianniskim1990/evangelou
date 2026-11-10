<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 round 2: the READ-ONLY view of the shared till account(s) (D18). Used by the till-account service and by
 * the readiness checks, so EVC_Readiness never holds the service that can create an account, set a password, disable
 * or sign out (packager: this file is a read-only file, like the readiness files).
 *
 * Static and stateless: nothing to construct, nothing hooked. Reads only: the users with the staff role, their session
 * tokens (count and newest sign-in time), the last-login meta, the disabled flag and the restricted-account rule.
 */
final class EVC_Staff_Account_Reader {
    const LAST_LOGIN_META = 'evc_staff_last_login';

    private function __construct() {
    }

    /**
     * Every account that carries the staff role (normally exactly one, D18).
     * @return array<int,array{id:int,login:string,disabled:bool,restricted:bool,last_login:?int,sessions:int}>
     */
    public static function accounts(): array {
        $out = array();
        foreach (get_users(array('role' => EVC_Staff_Role::ROLE, 'orderby' => 'ID', 'order' => 'ASC')) as $user) {
            $id = (int) $user->ID;
            $sessions = WP_Session_Tokens::get_instance($id)->get_all();
            $last = (int) get_user_meta($id, self::LAST_LOGIN_META, true);
            foreach ($sessions as $session) {
                $last = max($last, isset($session['login']) ? (int) $session['login'] : 0);
            }
            $out[] = array(
                'id' => $id,
                'login' => (string) $user->user_login,
                'disabled' => EVC_Staff_Auth::is_disabled($id),
                'restricted' => EVC_Staff_Role::is_restricted_staff_account($user),
                'last_login' => $last > 0 ? $last : null,
                'sessions' => count($sessions),
            );
        }
        return $out;
    }
}
