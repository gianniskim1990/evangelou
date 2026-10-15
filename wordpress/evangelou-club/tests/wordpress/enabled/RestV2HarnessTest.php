<?php

/**
 * UI-04H — the future v2 REST boundary through the REAL WordPress REST
 * lifecycle (core cookie/nonce authentication, permission callbacks,
 * dispatch, rest_post_dispatch filters incl. the v1 filter and core's Allow
 * header) on a disposable test database. Routes exist ONLY while the
 * TEST-ONLY EVC_Test_V2_Harness is installed by this test.
 */
final class RestV2HarnessTest extends EVC_WP_Test_Case {
    const A = EVC_Test_V2_Harness::MEMBER_A;
    const B = EVC_Test_V2_Harness::MEMBER_B;

    /** @var int */
    private $staff;

    public function set_up() {
        parent::set_up();
        EVC_Test_V2_Harness::reset();
        EVC_Test_V2_Harness::install();
        $GLOBALS['wp_rest_server'] = new Spy_REST_Server();
        do_action('rest_api_init', $GLOBALS['wp_rest_server']);
        $this->staff = $this->create_staff_user();
    }

    public function tear_down() {
        EVC_Test_V2_Harness::uninstall();
        EVC_Test_V2_Harness::$world = null;
        parent::tear_down();
    }

    // ---- helpers -------------------------------------------------------------------------------

    /**
     * Same lifecycle as EVC_WP_Test_Case::rest() plus query parameters.
     * @param array|null $body
     */
    private function v2(string $method, string $path, $body = null, ?string $nonce = null, array $query = array(), string $namespace = EVC_Rest_V2_Routes::NAMESPACE_V2): WP_REST_Response {
        $server = rest_get_server();
        $request = new WP_REST_Request($method, '/' . $namespace . $path);
        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($body));
        }
        if ($query) {
            $request->set_query_params($query);
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

    private function as_staff(): string {
        $this->login_as($this->staff);
        return $this->nonce();
    }

    private function assertDomain(WP_REST_Response $r, int $status, string $surface, string $key, string $value): array {
        $data = $r->get_data();
        $this->assertSame($status, $r->get_status(), wp_json_encode($data));
        $this->assertInstanceOf(EVC_Rest_V2_Domain_Response::class, $r, 'only the sealed source may carry a domain body');
        $this->assertArrayNotHasKey('error', $data);
        $this->assertTrue(EVC_Contract_Envelope_Rule::is_domain_body_for($surface, $data), wp_json_encode($data));
        $this->assertSame($value, $data[$key]);
        $this->assertSame('no-store, private, max-age=0', $r->get_headers()['Cache-Control']);
        return $data;
    }

    private function assertTransport(WP_REST_Response $r, int $status, string $code, string $label = ""): void {
        $this->assertSame($status, $r->get_status(), $label . " " . wp_json_encode($r->get_data()));
        $this->assertEnvelope($r, $status, $code);
        $this->assertNotInstanceOf(EVC_Rest_V2_Domain_Response::class, $r);
        $this->assertSame(array('error'), array_keys($r->get_data()), 'minimal envelope only');
        $this->assertArrayNotHasKey('details', $r->get_data()['error']);
    }

    private function lookup_ref(string $nonce): string {
        $found = $this->assertDomain($this->v2('POST', '/lookup/qr', array('qr_token' => EVC_Test_V2_Harness::QR_TOKEN), $nonce), 200, 'lookup', 'result', 'found');
        $this->assertSame(array('member_public_id', 'lookup_ref', 'lookup_ref_expires_at_utc'), array_keys($found['member']));
        $this->assertSame(self::A, $found['member']['member_public_id']);
        return $found['member']['lookup_ref'];
    }

    private function order(string $request_id, string $ref): array {
        return array(
            'request_id' => $request_id,
            'catalogue_version' => 1,
            'coffee_code' => 'espresso',
            'preparation' => array('modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'none')), 'quick_notes' => array()),
            'lookup_ref' => $ref,
        );
    }

    private function redeem(string $request_id, string $nonce, string $ref, string $member = self::A): WP_REST_Response {
        return $this->v2('POST', '/members/' . $member . '/redemptions', $this->order($request_id, $ref), $nonce);
    }

    // ---- registration and isolation -----------------------------------------------------------

    public function test_v2_routes_exist_only_while_the_test_harness_is_installed(): void {
        $routes = rest_get_server()->get_routes();
        foreach (array_keys(EVC_Rest_V2_Routes::ROUTES) as $id) {
            $this->assertArrayHasKey(EVC_Rest_V2_Routes::pattern($id), $routes, $id);
        }
        EVC_Test_V2_Harness::uninstall();
        $GLOBALS['wp_rest_server'] = new Spy_REST_Server();
        do_action('rest_api_init', $GLOBALS['wp_rest_server']);
        $this->assertNotContains(EVC_Rest_V2_Routes::NAMESPACE_V2, rest_get_server()->get_namespaces(), 'production wiring registers no v2 route');
        $this->assertFalse(has_filter('rest_post_dispatch', array('EVC_Rest_V2_Security', 'filter_post_dispatch')), 'the v2 filter is not hooked by the plugin');
        $this->assertSame(99, has_filter('rest_post_dispatch', array('EVC_Rest_Security', 'filter_post_dispatch')), 'v1 filter unchanged');
    }

    public function test_sealed_response_only_accepts_a_domain_body_of_its_own_route(): void {
        $lookup = array('result' => 'not_found', 'member' => null);
        $sealed = new EVC_Rest_V2_Domain_Response('lookup_qr', $lookup);
        $this->assertSame(404, $sealed->get_status());
        $this->assertSame(array('route_id' => 'lookup_qr', 'surface' => 'lookup', 'body' => $lookup, 'status' => 404), $sealed->evc_seal());
        foreach (array(
            array('redeem', $lookup),
            array('lookup_qr', array('result' => 'not_found', 'member' => null, 'debug' => 1)),
            array('lookup_qr', array('error' => array('code' => 'forbidden'))),
            array('status', array('result' => 'surprise', 'data' => null)),
            array('nope', $lookup),
        ) as $case) {
            try {
                new EVC_Rest_V2_Domain_Response($case[0], $case[1]);
                $this->fail('accepted ' . $case[0] . ' ' . wp_json_encode($case[1]));
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Not a v2 domain body for this route.', $e->getMessage());
            }
        }
    }

    // ---- valid domain replies -----------------------------------------------------------------

    public function test_redeem_replay_handoff_and_status_through_real_wordpress(): void {
        $nonce = $this->as_staff();
        $ref = $this->lookup_ref($nonce);
        $rid = self::uuid4();

        $fresh = $this->assertDomain($this->redeem($rid, $nonce, $ref), 201, 'redemption', 'outcome', 'redeemed');
        $this->assertSame('prepare', $fresh['instruction']);
        $this->assertSame($rid, $fresh['request_id']);
        $this->assertSame(self::A, $fresh['redemption']['member_id']);

        // Duplicate redemption response: an exact replay never prepares.
        $dup = $this->assertDomain($this->redeem($rid, $nonce, $ref), 200, 'redemption', 'outcome', 'replayed');
        $this->assertSame('verify_handoff', $dup['instruction']);

        $second = $this->assertDomain($this->redeem(self::uuid4(), $nonce, $ref), 409, 'redemption', 'outcome', 'benefit_already_redeemed');
        $this->assertFalse($second['entitlement_consumed']);

        $hid = self::uuid4();
        $handoff = $this->assertDomain($this->v2('POST', "/members/" . self::A . "/redemptions/$rid/handoff", array('handoff_request_id' => $hid, 'claim' => 'handed_now'), $nonce), 201, 'handoff', 'outcome', 'handoff_confirmed');
        $this->assertSame($hid, $handoff['handoff']['handoff_request_id']);
        $this->assertSame($rid, $handoff['handoff']['redemption_request_id']);
        $this->assertDomain($this->v2('POST', "/members/" . self::A . "/redemptions/$rid/handoff", array('handoff_request_id' => $hid, 'claim' => 'handed_now'), $nonce), 200, 'handoff', 'outcome', 'handoff_replayed');

        $after = $this->assertDomain($this->redeem($rid, $nonce, $ref), 200, 'redemption', 'outcome', 'replayed');
        $this->assertSame('do_not_prepare', $after['instruction']);
        $this->assertSame('confirmed', $after['fulfillment']);

        $status = $this->assertDomain($this->v2('GET', "/members/" . self::A . "/redemptions/$rid", null, $nonce), 200, 'status', 'result', 'found');
        $this->assertSame('confirmed', $status['data']['fulfillment']);
        $this->assertDomain($this->v2('GET', "/members/" . self::B . "/redemptions/$rid", null, $nonce), 404, 'status', 'result', 'not_found');
    }

    public function test_earlier_handoff_claim_never_gets_a_time(): void {
        $nonce = $this->as_staff();
        $rid = self::uuid4();
        $this->redeem($rid, $nonce, $this->lookup_ref($nonce));
        $h = $this->assertDomain($this->v2('POST', "/members/" . self::A . "/redemptions/$rid/handoff", array('handoff_request_id' => self::uuid4(), 'claim' => 'already_handed_earlier'), $nonce), 201, 'handoff', 'outcome', 'handoff_confirmed');
        $this->assertNull($h['handoff']['handoff_at_utc']);
        $this->assertSame('not_recorded', $h['handoff']['handoff_time']);
    }

    public function test_outcome_unknown_is_503_with_the_same_id_retry_and_recovers_by_replay(): void {
        $nonce = $this->as_staff();
        $ref = $this->lookup_ref($nonce);
        $rid = self::uuid4();
        EVC_Test_V2_Harness::$fault = 'commit_unknown';
        $u = $this->assertDomain($this->redeem($rid, $nonce, $ref), 503, 'redemption', 'outcome', 'outcome_unknown');
        $this->assertSame($rid, $u['retry_request_id']);
        $this->assertSame($rid, $u['request_id']);
        $this->assertNull($u['entitlement_consumed'], 'not a definite failure');
        // The commit did happen: the same-id retry is an exact replay and never prepares again.
        $this->assertSame('verify_handoff', $this->assertDomain($this->redeem($rid, $nonce, $ref), 200, 'redemption', 'outcome', 'replayed')['instruction']);

        EVC_Test_V2_Harness::$fault = 'handoff_unknown';
        $hid = self::uuid4();
        $h = $this->assertDomain($this->v2('POST', "/members/" . self::A . "/redemptions/$rid/handoff", array('handoff_request_id' => $hid, 'claim' => 'handed_now'), $nonce), 503, 'handoff', 'outcome', 'outcome_unknown');
        $this->assertSame($hid, $h['retry_handoff_request_id']);
    }

    public function test_server_side_validation_failures(): void {
        $nonce = $this->as_staff();
        $ref = $this->lookup_ref($nonce);
        $bad = $this->order(self::uuid4(), $ref);
        unset($bad['coffee_code']);
        $this->assertDomain($this->v2('POST', '/members/' . self::A . '/redemptions', $bad, $nonce), 400, 'redemption', 'outcome', 'invalid_request');
        $mocha = $this->order(self::uuid4(), $ref);
        $mocha['coffee_code'] = 'mocha';
        $this->assertDomain($this->v2('POST', '/members/' . self::A . '/redemptions', $mocha, $nonce), 400, 'redemption', 'outcome', 'invalid_coffee');
        $this->assertDomain($this->v2('POST', '/lookup/phone', array('phone' => '12345'), $nonce), 400, 'lookup', 'result', 'invalid_phone');
        $rid = self::uuid4();
        $this->redeem($rid, $nonce, $ref);
        // A body contradicting the URL's redemption id is malformed, never redirected.
        $this->assertDomain($this->v2('POST', "/members/" . self::A . "/redemptions/$rid/handoff", array('handoff_request_id' => self::uuid4(), 'redemption_request_id' => self::uuid4(), 'claim' => 'handed_now'), $nonce), 400, 'handoff', 'outcome', 'invalid_request');
        // Route-level validation (path format) never reaches a controller.
        $this->assertTransport($this->v2('GET', '/members/' . self::A . '/redemptions/not-a-uuid', null, $nonce), 404, 'invalid_request');
        $this->assertTransport($this->v2('GET', '/members/MEM_X/redemptions/' . $rid, null, $nonce), 404, 'invalid_request');
    }

    // ---- lookup proof -------------------------------------------------------------------------

    public function test_redemption_requires_a_lookup_proof_for_this_member_and_session(): void {
        $nonce = $this->as_staff();
        $ref = $this->lookup_ref($nonce);
        $rid = self::uuid4();
        $this->assertTransport($this->v2('POST', '/members/' . self::A . '/redemptions', array_diff_key($this->order($rid, $ref), array('lookup_ref' => 1)), $nonce), 403, 'forbidden');
        $this->assertTransport($this->redeem($rid, $nonce, 'lkp1.k1.forged.' . str_repeat('A', 43)), 403, 'forbidden');
        $this->assertTransport($this->redeem($rid, $nonce, $ref, self::B), 403, 'forbidden', 'proof for member A used for member B');
        // Another session of the same shared account: the proof is session-bound.
        $nonce2 = $this->as_staff();
        $this->assertTransport($this->redeem($rid, $nonce2, $ref), 403, 'forbidden');
        // Nothing was recorded by any refused attempt.
        $this->assertDomain($this->v2('GET', "/members/" . self::A . "/redemptions/$rid", null, $nonce2), 404, 'status', 'result', 'not_found');
    }

    public function test_usual_coffee_is_a_domain_reply_and_needs_the_proof(): void {
        $nonce = $this->as_staff();
        $ref = $this->lookup_ref($nonce);
        $none = $this->assertDomain($this->v2('POST', '/members/' . self::A . '/usual-coffee', array('lookup_ref' => $ref), $nonce), 200, 'usual', 'result', 'no_suggestion');
        $this->assertFalse($none['confirmed_order']);
        $this->assertDomain($this->v2('POST', '/members/' . self::A . '/usual-coffee', array(), $nonce), 403, 'usual', 'result', 'forbidden');
        $this->assertDomain($this->v2('POST', '/members/' . self::B . '/usual-coffee', array('lookup_ref' => $ref), $nonce), 403, 'usual', 'result', 'forbidden');
    }

    // ---- authentication and authorization stay transport envelopes ---------------------------

    public function test_every_designated_route_refuses_missing_authentication_with_401(): void {
        $calls = array(
            array('POST', '/lookup/qr'), array('POST', '/lookup/phone'), array('POST', '/members/' . self::A . '/redemptions'),
            array('GET', '/members/' . self::A . '/redemptions/' . self::uuid4()), array('POST', '/members/' . self::A . '/redemptions/' . self::uuid4() . '/handoff'),
            array('POST', '/members/' . self::A . '/usual-coffee'),
        );
        foreach ($calls as $c) {
            $this->assertTransport($this->v2($c[0], $c[1], $c[0] === 'POST' ? array() : null, null), 401, 'unauthorized');
        }
        $this->login_as($this->staff);
        foreach ($calls as $c) {
            $this->assertTransport($this->v2($c[0], $c[1], $c[0] === 'POST' ? array() : null, null), 401, 'unauthorized', 'cookie without nonce');
            $this->assertTransport($this->v2($c[0], $c[1], $c[0] === 'POST' ? array() : null, 'not-a-valid-nonce'), 401, 'unauthorized');
        }
    }

    public function test_wrong_capability_and_privileged_accounts_are_403(): void {
        foreach (array('subscriber', 'editor', 'administrator') as $role) {
            $this->login_as($this->create_user_with_role($role));
            $this->assertTransport($this->v2('POST', '/lookup/qr', array('qr_token' => EVC_Test_V2_Harness::QR_TOKEN), $this->nonce()), 403, 'forbidden');
        }
        $dual = $this->create_staff_user();
        (new WP_User($dual))->add_role('administrator');
        clean_user_cache($dual);
        $this->login_as($dual);
        $this->assertTransport($this->v2('POST', '/lookup/qr', array('qr_token' => EVC_Test_V2_Harness::QR_TOKEN), $this->nonce()), 403, 'forbidden');
        update_user_meta($this->staff, EVC_Staff_Auth::DISABLED_META, '1');
        $this->login_as($this->staff);
        $this->assertTransport($this->v2('POST', '/lookup/qr', array('qr_token' => EVC_Test_V2_Harness::QR_TOKEN), $this->nonce()), 403, 'forbidden');
    }

    public function test_revoked_and_expired_sessions_are_401(): void {
        $nonce = $this->as_staff();
        $this->lookup_ref($nonce);
        WP_Session_Tokens::get_instance($this->staff)->destroy_all();
        $this->assertTransport($this->v2('POST', '/lookup/qr', array('qr_token' => EVC_Test_V2_Harness::QR_TOKEN), $nonce), 401, 'unauthorized');

        $token = $this->login_as($this->staff);
        $nonce = $this->nonce();
        $manager = WP_Session_Tokens::get_instance($this->staff);
        $session = $manager->get($token);
        $session['login'] = time() - EVC_Staff_Session::ABSOLUTE_SECONDS;
        $session['expiration'] = time() + 3600;
        $manager->update($token, $session);
        $this->assertTransport($this->v2('POST', '/lookup/qr', array('qr_token' => EVC_Test_V2_Harness::QR_TOKEN), $nonce), 401, 'unauthorized');
        $this->assertNull($manager->get($token), 'expired session destroyed server-side');
    }

    public function test_rate_limits_transport_and_domain(): void {
        $nonce = $this->as_staff();
        EVC_Test_V2_Harness::$transport_budget = 1;
        $this->lookup_ref($nonce);
        $this->assertTransport($this->v2('POST', '/lookup/qr', array('qr_token' => EVC_Test_V2_Harness::QR_TOKEN), $nonce), 429, 'rate_limited');
        $this->assertSame(0, EVC_Test_V2_Harness::$transport_budget, 'consumed once per request (Allow header re-runs permission callbacks)');
        EVC_Test_V2_Harness::$transport_budget = null;
        EVC_Test_V2_Harness::$world['limiter']->max_attempts = 0;
        $this->assertDomain($this->v2('POST', '/lookup/qr', array('qr_token' => EVC_Test_V2_Harness::QR_TOKEN), $nonce), 429, 'lookup', 'result', 'rate_limited');
        EVC_Test_V2_Harness::$world['limiter']->unavailable = true;
        $this->assertDomain($this->v2('POST', '/lookup/qr', array('qr_token' => EVC_Test_V2_Harness::QR_TOKEN), $nonce), 503, 'lookup', 'result', 'unavailable');
    }

    // ---- fail-closed envelope boundary ----------------------------------------------------------

    public function test_unexpected_errors_never_leak_and_never_pass(): void {
        $nonce = $this->as_staff();
        $err = $this->v2('GET', '/harness/wp-error', null, $nonce);
        $this->assertTransport($err, 500, 'server_error');
        $json = wp_json_encode($err->get_data());
        foreach (array('SQLSTATE', 'hunter2', 'wp_evc_members', 'evc_internal_failure', 'trace') as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        EVC_Test_V2_Harness::$fault = 'throw';
        $this->assertTransport($this->v2('POST', '/lookup/qr', array('qr_token' => EVC_Test_V2_Harness::QR_TOKEN), $nonce), 500, 'server_error');
        $ref = $this->lookup_ref($nonce);
        EVC_Test_V2_Harness::$fault = 'store_down';
        $down = $this->assertDomain($this->redeem(self::uuid4(), $nonce, $ref), 503, 'redemption', 'outcome', 'server_error');
        $this->assertSame($down['request_id'], $down['retry_request_id']);
    }

    public function test_a_matching_key_set_alone_never_passes(): void {
        $nonce = $this->as_staff();
        $forged = $this->v2('GET', '/harness/forged', null, $nonce);
        $this->assertTransport($forged, 500, 'server_error');
        $this->assertStringNotContainsString('prepare', wp_json_encode($forged->get_data()));
        $this->assertTransport($this->v2('GET', '/harness/forged-unknown', null, $nonce), 503, 'server_error');
        $this->assertTransport($this->v2('GET', '/harness/undesignated', null, $nonce), 500, 'server_error');
    }

    public function test_wrong_namespace_never_gains_the_exemption(): void {
        $nonce = $this->as_staff();
        $this->assertTransport($this->v2('POST', '/members/' . self::A . '/redemptions', array(), $nonce, array(), EVC_Test_V2_Harness::SHADOW_NAMESPACE), 500, 'server_error');
        $v1 = $this->v2('GET', '/harness/sealed', null, $nonce, array(), EVC_Rest_Security::NAMESPACE_V1);
        $this->assertTransport($v1, 500, 'server_error');
    }

    public function test_unknown_v2_route_and_method_are_transport_errors(): void {
        $nonce = $this->as_staff();
        $this->assertTransport($this->v2('GET', '/nothing/here', null, $nonce), 404, 'invalid_request');
        $this->assertTransport($this->v2('DELETE', '/lookup/qr', null, $nonce), 404, 'invalid_request');
    }

    public function test_tampering_after_sealing_is_blocked(): void {
        $nonce = $this->as_staff();
        $ref = $this->lookup_ref($nonce);
        $rid = self::uuid4();
        $this->redeem($rid, $nonce, $ref);
        EVC_Test_V2_Harness::$fault = 'tamper';
        $t = $this->redeem($rid, $nonce, $ref);
        $this->assertTransport($t, 500, 'server_error');
        $this->assertStringNotContainsString('prepare', wp_json_encode($t->get_data()));
        // A later filter rewriting a sealed body is caught the same way.
        $rewrite = function ($response) {
            if ($response instanceof EVC_Rest_V2_Domain_Response) {
                $response->set_status(200);
            }
            return $response;
        };
        add_filter('rest_post_dispatch', $rewrite, 50);
        $this->assertTransport($this->redeem(self::uuid4(), $nonce, $ref), 500, 'server_error', 'a 409 rewritten to 200');
        remove_filter('rest_post_dispatch', $rewrite, 50);
    }

    public function test_output_shaping_parameters_are_refused(): void {
        $nonce = $this->as_staff();
        foreach (array('_envelope', '_fields', '_embed', '_jsonp') as $param) {
            $this->assertTransport($this->v2('POST', '/lookup/qr', array('qr_token' => EVC_Test_V2_Harness::QR_TOKEN), $nonce, array($param => $param === '_fields' ? 'result' : '1')), 400, 'invalid_request');
        }
    }

    // ---- UI-04H capability audit (regression: documents the CURRENT mismatch) ---------------

    public function test_capability_mismatch_staff_role_cannot_reach_handoff_status_or_usual_coffee(): void {
        $granted = EVC_Staff_Role::staff_capabilities();
        foreach (array('handoff' => 'evc_confirm_handoff', 'status' => 'evc_view_redemption_status', 'usual' => 'evc_view_usual_coffee') as $id => $cap) {
            $this->assertSame($cap, EVC_Rest_V2_Routes::ROUTES[$id]['capability']);
            $this->assertNotContains($cap, $granted, "$cap is not granted by EVC_Staff_Role");
        }
        $this->assertSame(EVC_Club_Staff_Authorizer::CONFIRM_HANDOFF, EVC_Rest_V2_Routes::ROUTES['handoff']['capability']);
        $this->assertSame(EVC_Club_Staff_Authorizer::VIEW_REDEMPTION_STATUS, EVC_Rest_V2_Routes::ROUTES['status']['capability']);
        $this->assertSame(EVC_Usual_Coffee_Service::CAPABILITY, EVC_Rest_V2_Routes::ROUTES['usual']['capability']);
        $this->assertContains(EVC_Rest_V2_Routes::ROUTES['redeem']['capability'], $granted);
        $this->assertContains(EVC_Rest_V2_Routes::ROUTES['lookup_qr']['capability'], $granted);

        $nonce = $this->as_staff();
        $ref = $this->lookup_ref($nonce);
        $rid = self::uuid4();
        $this->redeem($rid, $nonce, $ref);
        // 1. Transport gate with the REAL capability: EVC_Staff_Auth refuses any capability outside the staff set.
        EVC_Test_V2_Harness::$pending_gate = null;
        $this->assertTransport($this->v2('POST', "/members/" . self::A . "/redemptions/$rid/handoff", array('handoff_request_id' => self::uuid4(), 'claim' => 'handed_now'), $nonce), 403, 'forbidden');
        $this->assertTransport($this->v2('GET', "/members/" . self::A . "/redemptions/$rid", null, $nonce), 403, 'forbidden');
        $this->assertTransport($this->v2('POST', '/members/' . self::A . '/usual-coffee', array('lookup_ref' => $ref), $nonce), 403, 'forbidden');
        // 2. Even past the gate, the domain services consult the REAL WordPress capabilities and refuse.
        EVC_Test_V2_Harness::$pending_gate = EVC_Staff_Role::CAP_REDEEM;
        EVC_Test_V2_Harness::$authorizer_mode = 'wordpress';
        $this->assertDomain($this->v2('POST', "/members/" . self::A . "/redemptions/$rid/handoff", array('handoff_request_id' => self::uuid4(), 'claim' => 'handed_now'), $nonce), 403, 'handoff', 'outcome', 'forbidden');
        $this->assertDomain($this->v2('GET', "/members/" . self::A . "/redemptions/$rid", null, $nonce), 403, 'status', 'result', 'forbidden');
        $this->assertDomain($this->v2('POST', '/members/' . self::A . '/usual-coffee', array('lookup_ref' => $ref), $nonce), 403, 'usual', 'result', 'forbidden');
        // Lookup's capability IS granted: the same real authorizer allows it.
        $this->lookup_ref($nonce);
    }
}
