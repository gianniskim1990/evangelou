<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 (owner decision D18: ONE shared staff account on the till tablet; no per-person accounts or PINs): the
 * administrator's actions on that account. Constructed ONLY by EVC_Admin_Tools_Plugin::wire() behind
 * EVC_CLUB_ADMIN_TOOLS_ENABLED (packager-enforced).
 *
 *  - create   the shared account when there is none: role evc_club_staff ONLY (nothing else is granted), no e-mail,
 *             a generated password shown once;
 *  - password a new generated password (shown once; never stored or logged by this plugin); all its sessions end;
 *  - disable / enable (EVC_Staff_Auth::set_disabled: disabling also ends every session);
 *  - sign out everywhere (EVC_Staff_Session::revoke_all_staff_sessions);
 *  - status: last sign-in time, disabled or not, open sessions. No IP address, no personal data.
 *
 * Every action re-checks the caller (an administrator with manage_options AND evc_manage_club, never a staff
 * account, single-site WordPress) and is audited WITHOUT secrets: time, action, outcome, the acting administrator's
 * user ID and the target account's user ID, in a bounded WordPress option (newest 100). More than one account with
 * the staff role is reported as a warning; nothing is ever deleted automatically.
 */
final class EVC_Staff_Account_Service {
    const AUDIT_OPTION = 'evc_club_staff_admin_audit';
    const AUDIT_MAX = 100;
    const LAST_LOGIN_META = 'evc_staff_last_login';
    const DEFAULT_LOGIN = 'club-tameio';
    const DISPLAY_NAME = 'Evangelou Club — ταμείο';
    const PASSWORD_LENGTH = 24;
    const ACTIONS = array('create', 'password', 'disable', 'enable', 'revoke');

    const OK = 'ok';
    const FORBIDDEN = 'forbidden';
    const EXISTS = 'exists';
    const INVALID_LOGIN = 'invalid_login';
    const LOGIN_TAKEN = 'login_taken';
    const ROLE_MISSING = 'role_missing';
    const NOT_STAFF = 'not_staff';
    const NOT_RESTRICTED = 'not_restricted';
    const FAILED = 'failed';

    /** @var callable(): int */
    private $now;

    /** @param callable(): int|null $now TEST seam (seconds) */
    public function __construct(?callable $now = null) {
        EVC_Admin_Tools_Feature::assert_constructible(__CLASS__);
        $this->now = $now ?? function (): int {
            return time();
        };
    }

    /** Administrators only: manage_options AND evc_manage_club, never a Club staff account, single-site WordPress. */
    public static function may_manage($user): bool {
        return $user instanceof WP_User && $user->exists() && user_can($user, 'manage_options') && user_can($user, EVC_Staff_Role::CAP_MANAGE)
            && !EVC_Staff_Role::has_staff_role($user) && !is_multisite();
    }

    /**
     * Every account that carries the staff role (normally exactly one, D18).
     * @return array<int,array{id:int,login:string,disabled:bool,restricted:bool,last_login:?int,sessions:int}>
     */
    public function accounts(): array {
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

    /** @return array{code:string,user_id?:int,password?:string} */
    public function create(string $login): array {
        if (!$this->allowed('create', 0)) {
            return array('code' => self::FORBIDDEN);
        }
        if ($this->accounts() !== array()) {
            return $this->done('create', 0, self::EXISTS);
        }
        $login = trim($login) === '' ? self::DEFAULT_LOGIN : trim($login);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{3,59}$/D', $login) !== 1 || sanitize_user($login, true) !== $login) {
            return $this->done('create', 0, self::INVALID_LOGIN);
        }
        if (username_exists($login) !== false) {
            return $this->done('create', 0, self::LOGIN_TAKEN);
        }
        if (get_role(EVC_Staff_Role::ROLE) === null) {
            return $this->done('create', 0, self::ROLE_MISSING);
        }
        $password = self::generate_password();
        $user_id = wp_insert_user(array(
            'user_login' => $login,
            'user_pass' => $password,
            'user_email' => '',
            'display_name' => self::DISPLAY_NAME,
            'role' => EVC_Staff_Role::ROLE,
            'show_admin_bar_front' => 'false',
        ));
        if (is_wp_error($user_id) || (int) $user_id < 1) {
            return $this->done('create', 0, self::FAILED);
        }
        $user_id = (int) $user_id;
        if (!EVC_Staff_Role::is_restricted_staff_account(get_userdata($user_id))) {
            // Something (a plugin, a filter) granted more than the staff role: keep it locked, never delete it.
            update_user_meta($user_id, EVC_Staff_Auth::DISABLED_META, '1');
            return $this->done('create', $user_id, self::NOT_RESTRICTED);
        }
        $this->done('create', $user_id, self::OK);
        return array('code' => self::OK, 'user_id' => $user_id, 'password' => $password);
    }

    /** A new generated password (shown once); every session of the account ends. @return array{code:string,password?:string} */
    public function set_password(int $user_id): array {
        if (!$this->allowed('password', $user_id)) {
            return array('code' => self::FORBIDDEN);
        }
        if (!$this->is_staff($user_id)) {
            return $this->done('password', $user_id, self::NOT_STAFF);
        }
        $password = self::generate_password();
        wp_set_password($password, $user_id);
        WP_Session_Tokens::get_instance($user_id)->destroy_all();
        EVC_Staff_Session::delete_all_activity($user_id);
        $this->done('password', $user_id, self::OK);
        return array('code' => self::OK, 'password' => $password);
    }

    /** @return array{code:string} */
    public function set_disabled(int $user_id, bool $disabled): array {
        $action = $disabled ? 'disable' : 'enable';
        if (!$this->allowed($action, $user_id)) {
            return array('code' => self::FORBIDDEN);
        }
        if (!$this->is_staff($user_id)) {
            return $this->done($action, $user_id, self::NOT_STAFF);
        }
        $result = EVC_Staff_Auth::set_disabled($user_id, $disabled);
        return $this->done($action, $user_id, $result === true ? self::OK : self::FAILED);
    }

    /** Signs every staff account out everywhere. @return array{code:string,count?:int} */
    public function revoke_all(): array {
        if (!$this->allowed('revoke', 0)) {
            return array('code' => self::FORBIDDEN);
        }
        $result = EVC_Staff_Session::revoke_all_staff_sessions();
        if (!is_int($result)) {
            return $this->done('revoke', 0, self::FAILED);
        }
        $this->done('revoke', 0, self::OK);
        return array('code' => self::OK, 'count' => $result);
    }

    /** wp_login: the last sign-in time of a staff account (a time only). */
    public function record_login($user_login, $user = null): void {
        if (EVC_Staff_Role::has_staff_role($user)) {
            update_user_meta((int) $user->ID, self::LAST_LOGIN_META, ($this->now)());
        }
    }

    /** @return array<int,array{at:int,action:string,outcome:string,actor:int,target:int}> newest first */
    public static function audit_entries(int $limit = 20): array {
        $all = get_option(self::AUDIT_OPTION, array());
        return array_slice(array_reverse(is_array($all) ? array_values($all) : array()), 0, max(0, $limit));
    }

    /** A strong generated password: PASSWORD_LENGTH characters with lower and upper case letters and digits. */
    public static function generate_password(): string {
        do {
            $password = wp_generate_password(self::PASSWORD_LENGTH, false, false);
        } while (!self::strong($password));
        return $password;
    }

    public static function strong(string $password): bool {
        return strlen($password) >= 20 && preg_match('/[a-z]/', $password) === 1 && preg_match('/[A-Z]/', $password) === 1 && preg_match('/[0-9]/', $password) === 1;
    }

    private function allowed(string $action, int $target): bool {
        if (self::may_manage(wp_get_current_user())) {
            return true;
        }
        $this->audit($action, $target, self::FORBIDDEN);
        return false;
    }

    private function is_staff(int $user_id): bool {
        return $user_id > 0 && EVC_Staff_Role::has_staff_role(get_userdata($user_id));
    }

    /** @return array{code:string} */
    private function done(string $action, int $target, string $code): array {
        $this->audit($action, $target, $code);
        return array('code' => $code);
    }

    /** Time, action, outcome and two user IDs. Never a password, a login name, an e-mail or an address. */
    private function audit(string $action, int $target, string $outcome): void {
        $all = get_option(self::AUDIT_OPTION, array());
        $all = is_array($all) ? array_values($all) : array();
        $all[] = array('at' => ($this->now)(), 'action' => $action, 'outcome' => $outcome, 'actor' => get_current_user_id(), 'target' => $target);
        update_option(self::AUDIT_OPTION, array_slice($all, -self::AUDIT_MAX), false);
    }
}
