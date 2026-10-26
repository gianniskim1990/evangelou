<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * STAGE-03D: WHERE a WooCommerce «Completed» came from. A payment confirmation
 * is a human click in the WooCommerce admin: the order edit screen, a bulk
 * action or the order-list «complete» button (admin-ajax). `get_current_user_id()`
 * plus `edit_shop_orders` alone is NOT enough: a REST consumer key or
 * application password, the WooCommerce mobile app (REST), WP-CLI, WP-Cron and
 * automation plugins all run as a real shop user too. Those completions are
 * never a payment confirmation (the Club stays unverified for the order; the
 * attempt is audited with the user id and the channel).
 *
 * A pure classifier over facts the caller reads from the request, so it is unit
 * tested for every combination. Fail closed: anything not positively an admin
 * click is not attested.
 *
 * WooCommerce passes the third hook argument {from, to, note, manual}; `manual`
 * is true for the order edit screen, the bulk action and the list button, and
 * false/absent for REST and plain programmatic `update_status()`.
 */
final class EVC_Wc_Completion_Context {
    const CHANNEL_ADMIN = 'admin';
    const CHANNEL_ADMIN_AJAX = 'admin_ajax';
    const CHANNEL_REST = 'rest';
    const CHANNEL_CLI = 'cli';
    const CHANNEL_CRON = 'cron';
    const CHANNEL_AJAX = 'ajax';
    const CHANNEL_OTHER = 'other';
    /** Channels in which a human admin click can be recognised. */
    const ATTESTING = array(self::CHANNEL_ADMIN, self::CHANNEL_ADMIN_AJAX);

    /**
     * The channel of the running request, from what WordPress exposes. $rest_depth: running REST
     * dispatches counted by the caller (rest_do_request() does not define REST_REQUEST); $admin: is_admin().
     */
    public static function detect(int $rest_depth, bool $admin): string {
        $cli = defined('WP_CLI') && WP_CLI;
        $cron = function_exists('wp_doing_cron') && wp_doing_cron();
        $rest = $rest_depth > 0 || (defined('REST_REQUEST') && REST_REQUEST) || (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request());
        $ajax = function_exists('wp_doing_ajax') && wp_doing_ajax();
        return self::channel($cli, $cron, $rest, $ajax, $admin);
    }

    /** The channel from already-read facts (most specific non-admin channel first). */
    public static function channel(bool $cli, bool $cron, bool $rest, bool $ajax, bool $admin): string {
        if ($cli) {
            return self::CHANNEL_CLI;
        }
        if ($cron) {
            return self::CHANNEL_CRON;
        }
        if ($rest) {
            return self::CHANNEL_REST;
        }
        if ($ajax) {
            return $admin ? self::CHANNEL_ADMIN_AJAX : self::CHANNEL_AJAX;
        }
        return $admin ? self::CHANNEL_ADMIN : self::CHANNEL_OTHER;
    }

    /**
     * Why this completion is not a payment confirmation by channel; null when it is a manual
     * admin status change.
     *
     * @param mixed $transition the third `woocommerce_order_status_completed` argument
     */
    public static function problem(string $channel, $transition): ?string {
        if (!in_array($channel, self::ATTESTING, true)) {
            return 'channel_' . $channel;
        }
        if (!is_array($transition) || ($transition['manual'] ?? null) !== true) {
            return 'not_a_manual_status_change';
        }
        return null;
    }
}
