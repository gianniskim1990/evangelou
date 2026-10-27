<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * STAGE-03D: WHERE a WooCommerce «Completed» came from. A payment confirmation
 * is a person's click in one of the three WooCommerce admin entry points that
 * change an order's status, identified POSITIVELY for this very order:
 *
 *  - the order edit screen: nonce `update-order_<id>` (HPOS, wc-orders) or
 *    `update-post_<id>` (posts storage, post.php) verified by check_admin_referer();
 *  - the bulk action of the order list: nonce `bulk-orders` (HPOS, the order id
 *    in `id[]`) or `bulk-posts` (posts storage, the order id in `post[]`);
 *  - the order-list «complete» button: admin-ajax `action=woocommerce_mark_order_status`
 *    for this `order_id`, nonce `woocommerce-mark-order-status`;
 *
 * and WooCommerce's own `manual` flag (the third hook argument {from, to, note,
 * manual}) true. `get_current_user_id()` plus `edit_shop_orders` alone is NOT
 * enough: REST (consumer key, application password, the WooCommerce mobile
 * app), WP-CLI, WP-Cron, any other admin-ajax action (front-end plugin AJAX,
 * Action Scheduler's async runner with a staff cookie), wc-ajax and any
 * programmatic `update_status(..., true)` during an admin page load are never
 * a payment confirmation (the Club stays unverified for the order; the attempt
 * is audited with the user id and the channel).
 *
 * Pure functions over facts the caller reads from the request, unit tested for
 * every combination. Fail closed: anything not positively one of those entry
 * points is not attested.
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
    /** The order-list «complete» button (WC_AJAX::mark_order_status). */
    const AJAX_ACTION = 'woocommerce_mark_order_status';
    const AJAX_NONCE = 'woocommerce-mark-order-status';

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

    /**
     * Why the running request is not one of the WooCommerce admin entry points for THIS order;
     * null when it is. Only meaningful after problem() passed (an attesting channel). $verified: nonce actions check_admin_referer() verified in this request;
     * $request: the request parameters ($_REQUEST).
     *
     * @param string[] $verified
     * @param array<string,mixed> $request
     */
    public static function entry_problem(string $channel, int $order_id, array $verified, array $request): ?string {
        // The channel itself is problem()'s job (always checked first by the caller).
        if ($order_id < 1) {
            return 'not_a_woocommerce_admin_action';
        }
        if ($channel === self::CHANNEL_ADMIN_AJAX) {
            $ok = ($request['action'] ?? null) === self::AJAX_ACTION
                && in_array(self::AJAX_NONCE, $verified, true)
                && is_scalar($request['order_id'] ?? null) && (int) $request['order_id'] === $order_id;
            return $ok ? null : 'not_a_woocommerce_admin_action';
        }
        if (in_array('update-order_' . $order_id, $verified, true) || in_array('update-post_' . $order_id, $verified, true)) {
            return null; // the order edit screen (HPOS / posts storage)
        }
        if (in_array('bulk-orders', $verified, true) && in_array($order_id, self::ids($request['id'] ?? null), true)) {
            return null; // bulk action, HPOS order list
        }
        if (in_array('bulk-posts', $verified, true) && in_array($order_id, self::ids($request['post'] ?? null), true)) {
            return null; // bulk action, posts order list
        }
        return 'not_a_woocommerce_admin_action';
    }

    /** @return int[] */
    private static function ids($value): array {
        $values = is_array($value) ? $value : array($value);
        $ids = array();
        foreach ($values as $v) {
            if (is_scalar($v) && preg_match('/^\d{1,19}$/D', (string) $v)) {
                $ids[] = (int) $v;
            }
        }
        return $ids;
    }
}
