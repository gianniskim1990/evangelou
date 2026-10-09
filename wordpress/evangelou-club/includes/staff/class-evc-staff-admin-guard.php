<?php
defined('ABSPATH') || exit;

/**
 * Keeps the shared Club staff account out of WordPress administration.
 * Only users with the evc_club_staff role are affected; administrators and
 * every other user are untouched. admin-ajax.php is never redirected.
 * The target /club-admin/ is the future plugin-served staff app (Option A);
 * it is NOT created in this task.
 */
final class EVC_Staff_Admin_Guard {
    const STAFF_PATH = '/club-admin/';

    public static function register_hooks(): void {
        add_filter('show_admin_bar', array(__CLASS__, 'filter_show_admin_bar'), 99, 1);
        add_action('admin_init', array(__CLASS__, 'maybe_redirect'), 1);
        add_filter('login_redirect', array(__CLASS__, 'filter_login_redirect'), 99, 3);
    }

    public static function filter_show_admin_bar($show) {
        return EVC_Staff_Role::is_staff_user(wp_get_current_user()) ? false : $show;
    }

    /** Pure decision: where (if anywhere) to send this user away from wp-admin. */
    public static function redirect_target($user, bool $doing_ajax): ?string {
        if ($doing_ajax || !EVC_Staff_Role::is_staff_user($user)) {
            return null;
        }
        return home_url(self::STAFF_PATH);
    }

    public static function maybe_redirect(): void {
        $target = self::redirect_target(wp_get_current_user(), wp_doing_ajax());
        if ($target !== null) {
            wp_safe_redirect($target);
            exit;
        }
    }

    public static function filter_login_redirect($redirect_to, $requested, $user) {
        return EVC_Staff_Role::is_staff_user($user) ? home_url(self::STAFF_PATH) : $redirect_to;
    }
}
