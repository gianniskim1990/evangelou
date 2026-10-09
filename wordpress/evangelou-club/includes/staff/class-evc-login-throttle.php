<?php
defined('ABSPATH') || exit;

/**
 * Narrow brute-force protection for the shared Club staff account ONLY.
 * Other users are untouched (compatible with core and security plugins,
 * which may add their own throttling on top).
 *
 * - Per (account, client IP): 5 failures in 15 min -> that IP is locked
 *   out of the account for 15 min, even with the correct password.
 * - Per account (all IPs): 30 failures in 15 min -> account login locked
 *   for 15 min (limits distributed guessing; accepted DoS trade-off).
 * - Client IP = REMOTE_ADDR only. Proxy headers are NOT trusted; behind a
 *   CDN/reverse proxy all clients share one bucket until the technician
 *   confirms a trustworthy client-IP source (documented blocker).
 * - State lives in transients (non-atomic: limits are approximate under
 *   heavy concurrency, which is acceptable for throttling).
 *
 * This must be verified on staging together with Really Simple Security
 * before the shared account is enabled.
 */
final class EVC_Login_Throttle {
    const MAX_PER_IP = 5;
    const MAX_PER_ACCOUNT = 30;
    const WINDOW_SECONDS = 900;
    const LOCK_SECONDS = 900;
    const ERROR_CODE = 'evc_login_locked';

    public static function register_hooks(): void {
        add_filter('authenticate', array(__CLASS__, 'filter_authenticate'), 99, 3);
        add_action('wp_login_failed', array(__CLASS__, 'on_login_failed'), 10, 1);
        add_action('wp_login', array(__CLASS__, 'on_login_success'), 10, 2);
    }

    /** Runs after core's password check: a locked account fails even with the right password. */
    public static function filter_authenticate($user, $username, $password) {
        $staff = self::staff_user_for($username);
        if (!$staff) {
            return $user;
        }
        if (self::is_locked((int) $staff->ID, self::client_ip(), time())) {
            return new WP_Error(self::ERROR_CODE, __('Too many failed login attempts. Please try again later.', 'evangelou-club'));
        }
        return $user;
    }

    public static function on_login_failed($username): void {
        $staff = self::staff_user_for($username);
        if ($staff) {
            self::record_failure((int) $staff->ID, self::client_ip(), time());
        }
    }

    public static function on_login_success($user_login, $user = null): void {
        if (EVC_Staff_Role::is_staff_user($user)) {
            delete_transient(self::ip_key((int) $user->ID, self::client_ip()));
        }
    }

    public static function is_locked(int $user_id, string $ip, int $now): bool {
        foreach (array(self::ip_key($user_id, $ip), self::account_key($user_id)) as $key) {
            $state = self::state($key);
            if ($state['locked_until'] > $now) {
                return true;
            }
        }
        return false;
    }

    public static function record_failure(int $user_id, string $ip, int $now): void {
        self::bump(self::ip_key($user_id, $ip), self::MAX_PER_IP, $now);
        self::bump(self::account_key($user_id), self::MAX_PER_ACCOUNT, $now);
    }

    private static function bump(string $key, int $max, int $now): void {
        $state = self::state($key);
        $fails = array_values(array_filter($state['fails'], function ($t) use ($now) {
            return is_int($t) && $t > $now - EVC_Login_Throttle::WINDOW_SECONDS;
        }));
        $fails[] = $now;
        $locked_until = $state['locked_until'];
        if (count($fails) >= $max) {
            $locked_until = $now + self::LOCK_SECONDS;
            $fails = array();
        }
        set_transient($key, array('fails' => $fails, 'locked_until' => $locked_until), self::WINDOW_SECONDS + self::LOCK_SECONDS);
    }

    /** @return array{fails:int[],locked_until:int} */
    private static function state(string $key): array {
        $raw = get_transient($key);
        $fails = (is_array($raw) && isset($raw['fails']) && is_array($raw['fails'])) ? $raw['fails'] : array();
        $locked = (is_array($raw) && isset($raw['locked_until']) && is_int($raw['locked_until'])) ? $raw['locked_until'] : 0;
        return array('fails' => $fails, 'locked_until' => $locked);
    }

    private static function staff_user_for($username) {
        if (!is_string($username) || $username === '') {
            return null;
        }
        $user = get_user_by('login', $username);
        if (!$user && is_email($username)) {
            $user = get_user_by('email', $username);
        }
        return EVC_Staff_Role::is_staff_user($user) ? $user : null;
    }

    public static function client_ip(): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }

    public static function ip_key(int $user_id, string $ip): string {
        return 'evc_lt_ip_' . substr(hash_hmac('sha256', $user_id . '|' . $ip, wp_salt('auth')), 0, 32);
    }

    public static function account_key(int $user_id): string {
        return 'evc_lt_acct_' . $user_id;
    }
}
