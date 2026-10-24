<?php
defined('ABSPATH') || exit;
/**
 * GET evangelou-club-customer/v1/me (STAGE-01): the signed-in customer's own
 * account name and membership state. Read-only; registered only while
 * EVC_CLUB_CUSTOMER_ENABLED === true.
 *
 *   401/403/404 envelope   not signed in, bad nonce, not a customer, flag off
 *   503 {"result":"unavailable"}
 *                          the membership source threw or is not configured
 *                          (production today: EVC_Unavailable_Membership_Adapter)
 *   200 {"result":"ok","contract":"customer-me-v1", "account":{...},
 *        "membership":{"state","paid_until_utc"}, "club":{...}}
 *
 * The user is ALWAYS get_current_user_id() from the server session; the
 * route declares no arguments and reads nothing from the request. The state
 * comes from EVC_Customer_Membership over the adapter's EVC_Entitlement, so
 * only a verified, current paid period is ever "active". The Club database,
 * QR issuance and today's coffee are NOT connected: "club" says so.
 */
final class EVC_Rest_Me_Controller {
    const ROUTE = '/me';

    /** @var EVC_Membership_Adapter */
    private $adapter;
    /** @var EVC_Clock_Source */
    private $clock;

    public function __construct(EVC_Membership_Adapter $adapter, EVC_Clock_Source $clock) {
        $this->adapter = $adapter;
        $this->clock = $clock;
    }

    public function register_routes(bool $override = false): void {
        register_rest_route(EVC_Customer_Rest_Security::NAMESPACE_V1, self::ROUTE, array(
            'methods' => 'GET',
            'callback' => array($this, 'me'),
            'permission_callback' => array('EVC_Customer_Auth', 'authorize'),
            'args' => array(),
        ), $override);
    }

    /** @return WP_REST_Response|WP_Error */
    public function me(WP_REST_Request $request) {
        $auth = EVC_Customer_Auth::authorize($request);
        if ($auth instanceof WP_Error) {
            return $auth;
        }
        $user = wp_get_current_user();
        $at = $this->clock->now();
        try {
            $entitlement = $this->adapter->entitlement_for((int) $user->ID, $at);
        } catch (Throwable $e) {
            return new WP_REST_Response(array('result' => 'unavailable'), 503);
        }
        return new WP_REST_Response(array(
            'result' => 'ok',
            'contract' => EVC_Customer_Membership::CONTRACT,
            'account' => array(
                'display_name' => self::display_name($user),
                'email' => (string) $user->user_email,
            ),
            'membership' => EVC_Customer_Membership::describe($entitlement, $at),
            'club' => array(
                'coffee_today' => 'not_connected',
                'card' => 'sample_only',
            ),
        ), 200);
    }

    /** First + last name as the customer entered them, else the WordPress display name. */
    private static function display_name(WP_User $user): string {
        $full = trim((string) get_user_meta((int) $user->ID, 'first_name', true) . ' ' . (string) get_user_meta((int) $user->ID, 'last_name', true));
        $name = $full !== '' ? $full : trim((string) $user->display_name);
        return function_exists('mb_substr') ? mb_substr($name, 0, 80) : substr($name, 0, 80);
    }
}
