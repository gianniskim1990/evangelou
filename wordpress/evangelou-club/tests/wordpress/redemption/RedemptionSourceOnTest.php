<?php

/**
 * STAGE-05A: staff flag ON and EVC_CLUB_REDEMPTION_SOURCE = 'club_db_v1' (this process), but NO
 * commerce source, payment provenance or Club DB constants: the opt-in alone selects the real
 * backend, which still answers the old 503 and builds nothing before a redeem request.
 */
final class RedemptionSourceOnTest extends EVC_WP_Test_Case {
    /** @var string|null */
    private $error_log_file;
    /** @var string|false */
    private $previous_error_log = false;

    public function tear_down() {
        if ($this->error_log_file !== null) {
            ini_set('error_log', (string) $this->previous_error_log);
            @unlink($this->error_log_file);
            $this->error_log_file = null;
        }
        parent::tear_down();
    }

    private function capture_error_log(): void {
        $this->error_log_file = tempnam(sys_get_temp_dir(), 'evc-redeem-log-');
        $this->previous_error_log = ini_get('error_log');
        ini_set('error_log', $this->error_log_file);
    }

    private function captured(): string {
        return (string) file_get_contents($this->error_log_file);
    }

    private function registered_backend(): EVC_Redemption_Backend {
        $routes = rest_get_server()->get_routes();
        $key = '/' . EVC_Rest_Security::NAMESPACE_V1 . EVC_Rest_Redeem_Controller::ROUTE;
        $this->assertArrayHasKey($key, $routes);
        $controller = $routes[$key][0]['callback'][0];
        $property = new ReflectionProperty($controller, 'backend');
        $property->setAccessible(true);
        return $property->getValue($controller);
    }

    public function test_the_exact_constant_selects_the_club_backend(): void {
        $this->assertSame('club_db_v1', EVC_CLUB_REDEMPTION_SOURCE);
        $this->assertTrue(EVC_Club_Redemption_Feature::enabled());
        $this->assertTrue(EVC_Staff_Feature::enabled());
        $this->assertInstanceOf(EVC_Club_Redemption_Backend::class, $this->registered_backend());
    }

    public function test_registering_the_routes_builds_and_connects_nothing(): void {
        $this->capture_error_log();
        $GLOBALS['wp_rest_server'] = new Spy_REST_Server();
        do_action('rest_api_init', $GLOBALS['wp_rest_server']);
        $backend = $this->registered_backend();
        $this->assertInstanceOf(EVC_Club_Redemption_Backend::class, $backend);
        $this->assertNull($backend->last_reason(), 'service() was never called while registering');
        $this->assertStringNotContainsString('staff redemption', $this->captured());
    }

    public function test_without_the_other_pieces_redeem_answers_the_old_503(): void {
        $this->capture_error_log();
        $this->login_as($this->create_staff_user());
        $response = $this->rest('POST', $this->redeem_route(EVC_Member_Id::mint()), array('request_id' => self::uuid4(), 'coffee_code' => 'espresso'), $this->nonce());
        $this->assertEnvelope($response, 503, 'server_error');
        $this->assertSame(array('error' => array('code' => 'server_error', 'message' => EVC_Rest_Security::MESSAGES['server_error'])), $response->get_data());
        $backend = $this->registered_backend();
        $this->assertSame(EVC_Club_Redemption_Backend::REASON_COMMERCE, $backend->last_reason());
        $this->assertStringContainsString('Evangelou Club: staff redemption unavailable (commerce_source_not_configured).', $this->captured());
    }

    public function test_unauthorised_requests_never_reach_the_backend(): void {
        $this->assertEnvelope($this->rest('POST', $this->redeem_route(EVC_Member_Id::mint()), array('request_id' => self::uuid4(), 'coffee_code' => 'espresso')), 401, 'unauthorized');
        $this->assertNull($this->registered_backend()->last_reason());
    }

    /** STAGE-05C: the SQL QR token store exists as a definition only; no staff lookup / QR route is wired. */
    public function test_no_lookup_or_qr_route_is_registered(): void {
        foreach (array_keys(rest_get_server()->get_routes()) as $route) {
            if (strpos($route, '/' . EVC_Rest_Security::NAMESPACE_V1) === 0) {
                $this->assertDoesNotMatchRegularExpression('/lookup|qr/i', $route);
            }
        }
        $this->login_as($this->create_staff_user());
        $this->assertSame(404, $this->rest('POST', '/' . EVC_Rest_Security::NAMESPACE_V1 . '/members/lookup', array('method' => 'qr', 'qr_token' => EVC_Qr_Token::mint()), $this->nonce())->get_status());
    }
}
