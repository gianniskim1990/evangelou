<?php

/**
 * Base class for REAL WordPress integration tests.
 *
 * login_as() uses WordPress's own machinery end to end: a real session token
 * (WP_Session_Tokens::create, which runs attach_session_information), a real
 * logged_in auth cookie (wp_generate_auth_cookie) validated by core
 * (wp_validate_auth_cookie fires auth_cookie_valid, which marks the request
 * as cookie-authenticated for the REST nonce check), and real wp_rest nonces.
 *
 * rest() mirrors WP_REST_Server::serve_request's order: check_authentication()
 * (core rest_cookie_check_errors: missing nonce -> user 0, invalid nonce ->
 * rest_cookie_invalid_nonce), dispatch() (permission_callback + callback),
 * then the rest_post_dispatch filters.
 */
abstract class EVC_WP_Test_Case extends WP_UnitTestCase {
    const PASSWORD = 'Correct-Horse-Battery-1';
    const ROUTE_TEMPLATE = '/evangelou-club/v1/members/%s/benefits/free_coffee/redeem';

    public function set_up() {
        parent::set_up();
        EVC_Staff_Role::register();
        $_COOKIE = array();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        unset($_SERVER['HTTP_X_WP_NONCE']);
        $GLOBALS['wp_rest_auth_cookie'] = false;
        wp_set_current_user(0);
        $GLOBALS['wp_rest_server'] = new Spy_REST_Server();
        do_action('rest_api_init', $GLOBALS['wp_rest_server']);
    }

    public function tear_down() {
        $_COOKIE = array();
        unset($_SERVER['HTTP_X_WP_NONCE']);
        $GLOBALS['wp_rest_auth_cookie'] = false;
        $GLOBALS['wp_rest_server'] = null;
        parent::tear_down();
    }

    protected function create_staff_user(): int {
        return (int) self::factory()->user->create(array(
            'role' => EVC_Staff_Role::ROLE,
            'user_login' => 'club_shared_' . wp_generate_password(6, false, false),
            'user_pass' => self::PASSWORD,
        ));
    }

    protected function create_user_with_role(string $role): int {
        return (int) self::factory()->user->create(array('role' => $role, 'user_pass' => self::PASSWORD));
    }

    /**
     * Logs $user_id in as a real cookie session. Returns the raw session token
     * (test-only; production code never exposes it).
     */
    protected function login_as(int $user_id): string {
        $expiration = time() + (int) apply_filters('auth_cookie_expiration', 2 * DAY_IN_SECONDS, $user_id, false);
        $token = WP_Session_Tokens::get_instance($user_id)->create($expiration);
        $this->use_session($user_id, $token, $expiration);
        return $token;
    }

    /** Switches the "current request" to an existing session token of $user_id. */
    protected function use_session(int $user_id, string $token, ?int $expiration = null): void {
        if ($expiration === null) {
            $session = WP_Session_Tokens::get_instance($user_id)->get($token);
            $expiration = is_array($session) ? (int) $session['expiration'] : time() + HOUR_IN_SECONDS;
        }
        $cookie = wp_generate_auth_cookie($user_id, $expiration, 'logged_in', $token);
        $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
        $GLOBALS['wp_rest_auth_cookie'] = false;
        $validated = wp_validate_auth_cookie($cookie, 'logged_in');
        wp_set_current_user($validated ? (int) $validated : 0);
    }

    protected function nonce(): string {
        return wp_create_nonce('wp_rest');
    }

    /**
     * @param array|string|null $body array => JSON body; string => raw body
     */
    protected function rest(string $method, string $route, $body = null, ?string $nonce = null): WP_REST_Response {
        $server = rest_get_server();
        $request = new WP_REST_Request($method, $route);
        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(is_string($body) ? $body : wp_json_encode($body));
        }
        if ($nonce !== null) {
            $request->set_header('X-WP-Nonce', $nonce);
            $_SERVER['HTTP_X_WP_NONCE'] = $nonce;
        } else {
            unset($_SERVER['HTTP_X_WP_NONCE']);
        }
        $auth = $server->check_authentication();
        $result = is_wp_error($auth) ? rest_convert_error_to_response($auth) : $server->dispatch($request);
        return apply_filters('rest_post_dispatch', rest_ensure_response($result), $server, $request);
    }

    protected function redeem_route(string $member_id): string {
        return sprintf(self::ROUTE_TEMPLATE, $member_id);
    }

    protected static function uuid4(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    protected function assertEnvelope(WP_REST_Response $response, int $status, string $code): void {
        $this->assertSame($status, $response->get_status(), wp_json_encode($response->get_data()));
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame($code, $data['error']['code']);
        $this->assertSame(EVC_Rest_Security::MESSAGES[$code], $data['error']['message']);
    }
}
