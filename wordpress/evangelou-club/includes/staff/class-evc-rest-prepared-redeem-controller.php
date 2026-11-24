<?php
defined('ABSPATH') || exit;
/**
 * Round 9 (owner decision D24), evangelou-club/v1, registered only inside
 * `if (EVC_Club_Redemption_Feature::enabled())` in EVC_Staff_Plugin::register_routes(), after the staff gate.
 * Shared Club staff account only (EVC_Staff_Auth, capability evc_redeem_benefit, as the v1 redeem route).
 *
 * GET  /coffee-catalogue
 *   200 {"catalogue_version":2,"version_label":"evc-coffee-v2-2026-11-20","catalogue":{<EVC_Prep_Catalogue doc>}}
 *   The approved D24 document the till draws its coffee steps from. Static: needs no Club DB. Whether the
 *   till may USE it is decided per member by the lookup reply's `prepared` (schema >= 4).
 *
 * POST /members/{member_id}/benefits/{benefit_type}/prepared-redeem
 *   body EXACTLY {"request_id":"<uuid v4>","catalogue_version":2,"coffee_code":"…",
 *                 "preparation":{"modifiers":[{"group","option"}…],"quick_notes":[…],"note":"…"|null}}
 *   200 the UI-04G domain body (EVC_Redemption_Response::payload, docs/rest-contract-v2.md §A): outcome,
 *       request_id, recording, fulfillment, instruction, entitlement_consumed, server_business_date,
 *       redemption, handoff (+ retry_request_id for outcome_unknown / server_error). ALWAYS HTTP 200: the body
 *       is the source of truth (the contract's rule), and a hosting proxy can never swap a 5xx body for an
 *       HTML error page, which would hide outcome_unknown and its retry id from the till.
 *   409 {"error":{"code":"prepared_unavailable"}}  migration 004 is not applied: use the v1 route (nothing
 *       was read or written, so the SAME request id may be sent to v1).
 *   503 {"error":{"code":"server_error"}}          any other missing piece (nothing read or written).
 *
 * Idempotency, request_id and outcome_unknown are exactly UI-03D.A's: the same id with the same order
 * replays (never "prepare" again); the same id with any other order, note, member or staff is
 * idempotency_key_reused; outcome_unknown / server_error carry retry_request_id = the SENT id, the only valid
 * recovery. Member id and benefit come ONLY from the URL, the staff id ONLY from the session, the time ONLY
 * from the server clock. The staff note is never logged.
 */
final class EVC_Rest_Prepared_Redeem_Controller {
    const ROUTE = '/members/(?P<member_id>[A-Za-z0-9_-]{1,64})/benefits/(?P<benefit_type>[a-z_]{1,32})/prepared-redeem';
    const CATALOGUE_ROUTE = '/coffee-catalogue';

    /** @var EVC_Club_Prepared_Redemption_Backend */
    private $backend;

    public function __construct(EVC_Club_Prepared_Redemption_Backend $backend) {
        $this->backend = $backend;
    }

    public function register_routes(bool $override = false): void {
        register_rest_route(EVC_Rest_Security::NAMESPACE_V1, self::ROUTE, array(
            'methods' => 'POST',
            'callback' => array($this, 'redeem'),
            'permission_callback' => array($this, 'permission'),
            'args' => array(),
        ), $override);
        register_rest_route(EVC_Rest_Security::NAMESPACE_V1, self::CATALOGUE_ROUTE, array(
            'methods' => 'GET',
            'callback' => array($this, 'catalogue'),
            'permission_callback' => array($this, 'permission'),
            'args' => array(),
        ), $override);
    }

    public function permission(WP_REST_Request $request) {
        return EVC_Staff_Auth::authorize($request, EVC_Staff_Role::CAP_REDEEM);
    }

    public function catalogue(WP_REST_Request $request) {
        $auth = EVC_Staff_Auth::authorize($request, EVC_Staff_Role::CAP_REDEEM);
        if (is_wp_error($auth)) {
            return $auth;
        }
        return new WP_REST_Response(array(
            'catalogue_version' => EVC_Club_Prep_Catalogue_Authority::CATALOGUE_VERSION,
            'version_label' => EVC_Club_Prep_Catalogue_Authority::VERSION_LABEL,
            'catalogue' => EVC_Club_Prep_Catalogue_Authority::document(),
        ), 200);
    }

    public function redeem(WP_REST_Request $request) {
        // Defense in depth: never trust that the permission callback ran.
        $auth = EVC_Staff_Auth::authorize($request, EVC_Staff_Role::CAP_REDEEM);
        if (is_wp_error($auth)) {
            return $auth;
        }
        try {
            $service = $this->backend->service();
            if ($service === null) {
                return $this->backend->last_reason() === EVC_Club_Prepared_Redemption_Backend::REASON_SCHEMA
                    ? EVC_Rest_Security::error_response('prepared_unavailable', 409)
                    : EVC_Rest_Security::error_response('server_error', 503);
            }
            $body = $request->get_json_params();
            // Path parameters come ONLY from the URL: get_param() would let a JSON body field override them.
            $url = $request->get_url_params();
            $token = wp_get_session_token();
            $actor = new EVC_Staff_Actor(get_current_user_id(), (is_string($token) && $token !== '') ? EVC_Staff_Session::session_ref($token) : null);
            $outcome = $service->redeem(
                isset($url['member_id']) ? (string) $url['member_id'] : '',
                isset($url['benefit_type']) ? (string) $url['benefit_type'] : '',
                $actor,
                $body
            );
            $sent = is_array($body) && array_key_exists('request_id', $body) ? $body['request_id'] : null;
            return new WP_REST_Response(EVC_Redemption_Response::payload($outcome, $sent, $this->backend->clock()), 200);
        } catch (Throwable $e) {
            return EVC_Rest_Security::error_response('server_error', 500);
        }
    }
}
