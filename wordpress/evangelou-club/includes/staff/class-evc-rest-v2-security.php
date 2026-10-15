<?php
defined('ABSPATH') || exit;

/**
 * UI-04H — WordPress adapter for the FUTURE v2 envelope boundary.
 *
 * NOT ACTIVE: EVC_Staff_Plugin never hooks EVC_Rest_V2_Security and no shipped
 * code constructs EVC_Rest_V2_Domain_Response or registers a v2 route (the
 * packager rejects both). It is exercised only by the test-only harness.
 */

/**
 * The ONLY authorized source of a v2 domain body: created by a v2 controller
 * from a UI-04G mapper payload, sealed with its route id, surface, body and
 * the status EVC_Contract_Http recommends. Any later change to the data or
 * status (another filter, set_data/set_status) breaks the seal.
 */
final class EVC_Rest_V2_Domain_Response extends WP_REST_Response {
    /** @var array{route_id:string,surface:string,body:array,status:int} */
    private $evc_seal;

    public function __construct(string $route_id, array $body) {
        $route = EVC_Rest_V2_Routes::route($route_id);
        if ($route === null || !EVC_Contract_Envelope_Rule::is_domain_body_for($route['surface'], $body)) {
            throw new InvalidArgumentException('Not a v2 domain body for this route.');
        }
        $status = EVC_Contract_Http::status($route['surface'], $body);
        parent::__construct($body, $status);
        $this->evc_seal = array('route_id' => $route_id, 'surface' => $route['surface'], 'body' => $body, 'status' => $status);
    }

    /** @return array{route_id:string,surface:string,body:array,status:int} */
    public function evc_seal(): array {
        return $this->evc_seal;
    }
}

final class EVC_Rest_V2_Security {
    /** Runs BEFORE the v1 filter (99) so a sealed body outside v2 is neutralised first. */
    const PRIORITY = 98;

    /**
     * rest_post_dispatch callback (future wiring). Returns the response
     * unchanged when the policy passes or ignores it; otherwise a v1 envelope
     * with security headers. Never passes on core/WP_Error text.
     */
    public static function filter_post_dispatch($result, $server, $request) {
        if (!($request instanceof WP_REST_Request)) {
            return $result;
        }
        $response = rest_ensure_response($result);
        $sealed = $response instanceof EVC_Rest_V2_Domain_Response ? $response->evc_seal() : null;
        $query = (array) $request->get_query_params();
        $decision = EVC_Rest_V2_Envelope_Policy::decide(array(
            'route' => $request->get_route(),
            'method' => $request->get_method(),
            'matched_route' => $response->get_matched_route(),
            'status' => (int) $response->get_status(),
            'data' => $response->get_data(),
            'sealed' => $sealed,
            'authenticated' => get_current_user_id() > 0,
            'transport_params' => count(array_intersect(array_keys($query), EVC_Rest_V2_Envelope_Policy::TRANSPORT_PARAMS)) > 0,
        ));
        if ($decision['action'] === EVC_Rest_V2_Envelope_Policy::IGNORE) {
            return $result;
        }
        if ($decision['action'] === EVC_Rest_V2_Envelope_Policy::ENVELOPE) {
            $response = EVC_Rest_Security::error_response($decision['code'], $decision['status']);
        }
        foreach (EVC_Rest_Security::security_headers() as $name => $value) {
            $response->header($name, $value);
        }
        return $response;
    }
}
