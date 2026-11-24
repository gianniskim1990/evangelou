<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 round 18, OWNER DECISION D30 (2026-11-23): the signed-in member's own messages (EVC_Club_Messages).
 * evangelou-club-customer/v1 (registered by EVC_Push_Plugin behind EVC_CLUB_PUSH_ENABLED; otherwise 404):
 *
 *   GET /me/messages       200 {"result":"ok","messages":[{"id","title","summary","sent_at","unread"}…],"unread":N}
 *                          newest first, at most EVC_Club_Messages::LIST_LIMIT, only messages this member may see
 *   GET /me/messages/<id>  200 {"result":"ok","message":{"id","title","summary","sent_at","html"}}
 *                          the body sanitised again on the way out; opening it moves the member's read mark forward
 *                          404 message_not_found: no such message OR not one of this member's (the same answer)
 *   both                   404 not_a_member (no Club member row) · 403 member_disabled · 503 {"result":"unavailable"}
 *                          (Club DB / schema / flags) · 401 / 403 from the customer authorization
 *
 * Same customer authorization as every /me route (EVC_Customer_Auth: cookie session + own wp_rest nonce, customer
 * accounts only); the member is ALWAYS get_current_user_id(); responses are no-store (EVC_Customer_Rest_Security). The
 * id is digits only (route pattern + int cast); it never selects another member's message.
 */
final class EVC_Rest_Me_Messages_Controller {
    const ROUTE = '/me/messages';

    /** @var EVC_Push_Backend */
    private $backend;

    public function __construct(EVC_Push_Backend $backend) {
        EVC_Push_Feature::assert_constructible(__CLASS__);
        $this->backend = $backend;
    }

    public function register_routes(bool $override = false): void {
        register_rest_route(EVC_Customer_Rest_Security::NAMESPACE_V1, self::ROUTE, array(
            array('methods' => 'GET', 'callback' => array($this, 'index'), 'permission_callback' => array('EVC_Customer_Auth', 'authorize'), 'args' => array()),
        ), $override);
        register_rest_route(EVC_Customer_Rest_Security::NAMESPACE_V1, self::ROUTE . '/(?P<id>[1-9][0-9]{0,9})', array(
            array('methods' => 'GET', 'callback' => array($this, 'show'), 'permission_callback' => array('EVC_Customer_Auth', 'authorize'), 'args' => array()),
        ), $override);
    }

    /** @return WP_REST_Response|WP_Error */
    public function index(WP_REST_Request $request) {
        $auth = EVC_Customer_Auth::authorize($request);
        if ($auth instanceof WP_Error) {
            return $auth;
        }
        $user = get_current_user_id();
        $refused = $this->member_refusal($user);
        if ($refused !== null) {
            return $refused;
        }
        $read = EVC_Club_Messages::last_read($user);
        $list = array();
        $unread = 0;
        foreach (EVC_Club_Messages::visible_ids($user) as $id) {
            $entry = EVC_Club_Messages::entry($id);
            $entry['unread'] = $id > $read;
            $unread += $entry['unread'] ? 1 : 0;
            $list[] = $entry;
        }
        return new WP_REST_Response(array('result' => 'ok', 'messages' => $list, 'unread' => $unread), 200);
    }

    /** @return WP_REST_Response|WP_Error */
    public function show(WP_REST_Request $request) {
        $auth = EVC_Customer_Auth::authorize($request);
        if ($auth instanceof WP_Error) {
            return $auth;
        }
        $user = get_current_user_id();
        $refused = $this->member_refusal($user);
        if ($refused !== null) {
            return $refused;
        }
        $raw = (string) $request->get_param('id');
        $id = preg_match('/^[1-9][0-9]{0,9}$/D', $raw) === 1 ? (int) $raw : 0;
        if ($id <= 0 || $id > 2147483647 || !EVC_Club_Messages::visible_to($id, $user)) {
            return EVC_Rest_Security::error_response('message_not_found', 404);
        }
        EVC_Club_Messages::mark_read($user, $id);
        $message = EVC_Club_Messages::entry($id);
        $message['html'] = EVC_Club_Messages::html_of($id);
        return new WP_REST_Response(array('result' => 'ok', 'message' => $message), 200);
    }

    /** null = an enabled Club member; otherwise the answer. */
    private function member_refusal(int $user): ?WP_REST_Response {
        try {
            $state = $this->backend->member_state($user);
        } catch (Throwable $e) {
            $state = 'unavailable';
        }
        if ($state === 'ready') {
            return null;
        }
        if ($state === 'not_member') {
            return EVC_Rest_Security::error_response('not_a_member', 404);
        }
        if ($state === 'member_disabled') {
            return EVC_Rest_Security::error_response('member_disabled', 403);
        }
        return new WP_REST_Response(array('result' => 'unavailable'), 503);
    }
}
