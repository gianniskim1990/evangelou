<?php
defined('ABSPATH') || exit;

/**
 * Session policy for the shared Club staff account, built on native
 * WordPress auth cookies + session tokens (no second password store).
 *
 * - Absolute lifetime: 12 h from login. The auth cookie AND the WordPress
 *   session token are issued with a 12 h expiration ("remember me" ignored),
 *   and every protected request re-checks login + 12 h itself.
 * - Inactivity: 30 min without a valid protected Club request. Activity is
 *   tracked PER SESSION (one usermeta row per session, keyed by a keyed
 *   hash of the session verifier), so tablets never refresh each other and
 *   concurrent writes never rewrite WordPress's shared session array.
 * - Writes are throttled to one per 60 s per session.
 * - Fail closed: a session without this policy's marker (e.g. created
 *   before the plugin was active), with malformed timestamps or malformed
 *   activity data is rejected and destroyed.
 * - Raw session tokens are never stored or logged by this class.
 */
final class EVC_Staff_Session {
    const ABSOLUTE_SECONDS = 43200; // 12 hours (owner-approved)
    const IDLE_SECONDS = 1800;      // 30 minutes (owner-approved)
    const TOUCH_INTERVAL_SECONDS = 60;
    const POLICY_FLAG = 'evc_policy';
    const POLICY_VERSION = 1;
    const ACTIVITY_META_PREFIX = 'evc_session_activity_';

    const OK = 'ok';
    const INVALID = 'session_invalid';
    const EXPIRED = 'session_expired';
    const IDLE = 'session_idle';

    public static function register_hooks(): void {
        add_filter('auth_cookie_expiration', array(__CLASS__, 'filter_cookie_expiration'), 99, 3);
        add_filter('attach_session_information', array(__CLASS__, 'filter_attach_session'), 10, 2);
        add_action('wp_logout', array(__CLASS__, 'on_logout'), 10, 1);
        add_action('admin_post_evc_revoke_staff_sessions', array(__CLASS__, 'handle_admin_revoke'));
    }

    /** 12 h for the staff role regardless of "remember me"; others unchanged. */
    public static function filter_cookie_expiration($length, $user_id, $remember) {
        return EVC_Staff_Role::has_staff_role(get_userdata((int) $user_id)) ? self::ABSOLUTE_SECONDS : $length;
    }

    /** Marks sessions created under this policy. */
    public static function filter_attach_session($session, $user_id) {
        if (EVC_Staff_Role::has_staff_role(get_userdata((int) $user_id))) {
            $session[self::POLICY_FLAG] = self::POLICY_VERSION;
        }
        return $session;
    }

    /**
     * Pure policy decision (deterministic; unit-testable at exact boundaries).
     *
     * @param mixed $session        Session array as stored by WordPress, or null.
     * @param mixed $last_activity  Last protected activity (int), null if none yet,
     *                              or any other value for malformed data.
     */
    public static function evaluate($session, $last_activity, int $now): string {
        if (!is_array($session) || !isset($session[self::POLICY_FLAG]) || $session[self::POLICY_FLAG] !== self::POLICY_VERSION) {
            return self::INVALID;
        }
        $login = isset($session['login']) ? $session['login'] : null;
        $expiration = isset($session['expiration']) ? $session['expiration'] : null;
        if (!is_int($login) || !is_int($expiration) || $login <= 0 || $expiration <= $login) {
            return self::INVALID;
        }
        if ($now >= $login + self::ABSOLUTE_SECONDS || $now >= $expiration) {
            return self::EXPIRED;
        }
        $last = $login;
        if ($last_activity !== null) {
            if (!is_int($last_activity) || $last_activity < $login || $last_activity > $now + 60) {
                return self::INVALID;
            }
            $last = max($login, $last_activity);
        }
        if ($now - $last >= self::IDLE_SECONDS) {
            return self::IDLE;
        }
        return self::OK;
    }

    /**
     * Enforces the policy for the CURRENT cookie session of $user_id. A
     * non-OK result destroys that session (and only that session). On
     * success, records activity at most once per TOUCH_INTERVAL_SECONDS.
     */
    public static function verify_current(int $user_id, ?int $now = null, bool $touch = true): string {
        $now = $now === null ? time() : $now;
        $token = wp_get_session_token();
        if (!is_string($token) || $token === '' || $user_id <= 0) {
            return self::INVALID;
        }
        $manager = WP_Session_Tokens::get_instance($user_id);
        $session = $manager->get($token);
        $key = self::activity_key($token);
        $activity = self::parse_activity(get_user_meta($user_id, $key, true));

        $status = self::evaluate($session, $activity, $now);
        if ($status !== self::OK) {
            $manager->destroy($token);
            delete_user_meta($user_id, $key);
            return $status;
        }
        // $touch = false (status checks, logout) NEVER extends inactivity, so
        // an unattended tablet cannot keep its session alive by itself.
        if ($touch && ($activity === null || $now - $activity >= self::TOUCH_INTERVAL_SECONDS)) {
            update_user_meta($user_id, $key, (string) $now);
        }
        return self::OK;
    }

    /**
     * Absolute end (Unix time) of the CURRENT cookie session, or null when
     * there is no valid policy session. Read-only: never touches activity.
     */
    public static function current_expiry(int $user_id): ?int {
        $token = wp_get_session_token();
        if (!is_string($token) || $token === '' || $user_id <= 0) {
            return null;
        }
        $session = WP_Session_Tokens::get_instance($user_id)->get($token);
        if (!is_array($session) || !isset($session['login'], $session['expiration']) || !is_int($session['login']) || !is_int($session['expiration'])) {
            return null;
        }
        return min($session['login'] + self::ABSOLUTE_SECONDS, $session['expiration']);
    }

    /** One-way keyed reference to a session, safe for audit records (32 hex). */
    public static function session_ref(string $token): string {
        return substr(hash_hmac('sha256', 'evc-session-ref|' . $token, wp_salt('auth')), 0, 32);
    }

    public static function activity_key(string $token): string {
        return self::ACTIVITY_META_PREFIX . substr(hash_hmac('sha256', 'evc-session-activity|' . $token, wp_salt('auth')), 0, 32);
    }

    /** @return int|null|false int timestamp, null when absent, false when malformed */
    private static function parse_activity($raw) {
        if ($raw === '' || $raw === null || $raw === false) {
            return null;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }
        return false;
    }

    /** Logout (core wp_logout already destroyed the token): drop its activity row. */
    public static function on_logout($user_id = 0): void {
        $user_id = (int) $user_id;
        $token = wp_get_session_token();
        if ($user_id > 0 && is_string($token) && $token !== '') {
            delete_user_meta($user_id, self::activity_key($token));
        }
        if ($user_id > 0) {
            self::prune_activity($user_id, time());
        }
    }

    /** Ends the current session of the current user (staff "logout" helper). */
    public static function revoke_current(): void {
        $user_id = get_current_user_id();
        $token = wp_get_session_token();
        if ($user_id > 0 && is_string($token) && $token !== '') {
            WP_Session_Tokens::get_instance($user_id)->destroy($token);
            delete_user_meta($user_id, self::activity_key($token));
        }
    }

    /**
     * Emergency revocation (lost tablet): destroys EVERY session of every
     * Club staff user. Only users with evc_manage_club (administrators) may
     * call it; the staff role can never revoke arbitrary sessions.
     *
     * @return int|WP_Error Number of staff users whose sessions were destroyed.
     */
    public static function revoke_all_staff_sessions() {
        if (!current_user_can(EVC_Staff_Role::CAP_MANAGE)) {
            return new WP_Error('forbidden', 'Not allowed.', array('status' => 403));
        }
        $ids = get_users(array('role' => EVC_Staff_Role::ROLE, 'fields' => 'ID'));
        foreach ($ids as $id) {
            WP_Session_Tokens::get_instance((int) $id)->destroy_all();
            self::delete_all_activity((int) $id);
        }
        return count($ids);
    }

    /** admin-post.php?action=evc_revoke_staff_sessions (nonce + capability). */
    public static function handle_admin_revoke(): void {
        check_admin_referer('evc_revoke_staff_sessions');
        $result = self::revoke_all_staff_sessions();
        if (is_wp_error($result)) {
            wp_die(esc_html__('Not allowed.', 'evangelou-club'), '', array('response' => 403));
        }
        wp_safe_redirect(add_query_arg('evc_revoked', (string) $result, wp_get_referer() ?: admin_url()));
        exit;
    }

    /** Rows older than the idle limit cannot belong to a live session. */
    public static function prune_activity(int $user_id, int $now): void {
        foreach (get_user_meta($user_id) as $key => $values) {
            if (strpos($key, self::ACTIVITY_META_PREFIX) !== 0) {
                continue;
            }
            $value = self::parse_activity(is_array($values) ? reset($values) : $values);
            if (!is_int($value) || $now - $value >= self::IDLE_SECONDS) {
                delete_user_meta($user_id, $key);
            }
        }
    }

    public static function delete_all_activity(int $user_id): void {
        foreach (array_keys(get_user_meta($user_id)) as $key) {
            if (strpos($key, self::ACTIVITY_META_PREFIX) === 0) {
                delete_user_meta($user_id, $key);
            }
        }
    }
}
