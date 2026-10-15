<?php
use PHPUnit\Framework\TestCase;

/**
 * UI-04H — pure route-aware envelope policy. Every positive UI-04G vector
 * (bodies produced by the REAL mappers) must pass ONLY when sealed for its
 * designated route + method, unchanged, with the recommended status, for an
 * authenticated request; every deviation fails closed to a v1 envelope.
 */
final class RestV2BoundaryTest extends TestCase {
    const SURFACE_ROUTE = array('redemption' => 'redeem', 'handoff' => 'handoff', 'status' => 'status', 'lookup' => 'lookup_qr', 'usual' => 'usual');
    const MEMBER = 'mem_0123456789abcdef0123456789abcdef';
    const RID = '00000000-0000-4000-8000-000000000001';

    /** @return array<int,array> */
    private function positives(): array {
        $data = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/contract/vectors.json'), true);
        return array_values(array_filter($data['vectors'], function ($v) {
            return $v['expect']['kind'] !== 'invalid';
        }));
    }

    private function vector(string $name): array {
        foreach ($this->positives() as $v) {
            if ($v['name'] === $name) {
                return $v;
            }
        }
        $this->fail($name);
    }

    private function request_route(string $id): string {
        $concrete = array(
            'lookup_qr' => '/lookup/qr',
            'lookup_phone' => '/lookup/phone',
            'redeem' => '/members/' . self::MEMBER . '/redemptions',
            'status' => '/members/' . self::MEMBER . '/redemptions/' . self::RID,
            'handoff' => '/members/' . self::MEMBER . '/redemptions/' . self::RID . '/handoff',
            'usual' => '/members/' . self::MEMBER . '/usual-coffee',
        );
        return '/' . EVC_Rest_V2_Routes::NAMESPACE_V2 . $concrete[$id];
    }

    /** A correctly sealed, authenticated request for route $id carrying $body. */
    private function sealed_input(string $id, array $body, array $overrides = array()): array {
        $route = EVC_Rest_V2_Routes::route($id);
        $status = EVC_Contract_Http::status($route['surface'], $body);
        return array_merge(array(
            'route' => $this->request_route($id),
            'method' => $route['method'],
            'matched_route' => EVC_Rest_V2_Routes::pattern($id),
            'status' => $status,
            'data' => $body,
            'sealed' => array('route_id' => $id, 'surface' => $route['surface'], 'body' => $body, 'status' => $status),
            'authenticated' => true,
            'transport_params' => false,
        ), $overrides);
    }

    private function assertEnvelope(string $code, int $status, array $decision, string $label = ''): void {
        $this->assertSame(array('action' => 'envelope', 'code' => $code, 'status' => $status), $decision, $label);
    }

    public function test_route_table_is_exact_and_namespaced(): void {
        $this->assertSame(array('lookup_qr', 'lookup_phone', 'redeem', 'status', 'handoff', 'usual'), array_keys(EVC_Rest_V2_Routes::ROUTES));
        $patterns = array();
        foreach (EVC_Rest_V2_Routes::ROUTES as $id => $r) {
            $patterns[] = $r['method'] . ' ' . EVC_Rest_V2_Routes::pattern($id);
            $this->assertSame($id, EVC_Rest_V2_Routes::designated(EVC_Rest_V2_Routes::pattern($id), $r['method'])['id']);
            $this->assertNull(EVC_Rest_V2_Routes::designated(EVC_Rest_V2_Routes::pattern($id), $r['method'] === 'GET' ? 'POST' : 'GET'));
            $this->assertSame(1, preg_match('#^' . EVC_Rest_V2_Routes::pattern($id) . '$#', $this->request_route($id)), $id);
        }
        $this->assertCount(6, array_unique($patterns));
        $this->assertTrue(EVC_Rest_V2_Routes::ROUTES['redeem']['lookup_proof']);
        $this->assertTrue(EVC_Rest_V2_Routes::ROUTES['usual']['lookup_proof']);
        $this->assertSame('request_id', EVC_Rest_V2_Routes::ROUTES['redeem']['idempotency']);
        $this->assertSame('handoff_request_id', EVC_Rest_V2_Routes::ROUTES['handoff']['idempotency']);
        $this->assertNull(EVC_Rest_V2_Routes::designated('/evangelou-club/v1/lookup/qr', 'POST'));
        $this->assertNull(EVC_Rest_V2_Routes::designated(null, 'POST'));
        // Path ids are strict: no other member/request id format reaches a controller.
        $this->assertSame(0, preg_match('#^' . EVC_Rest_V2_Routes::pattern('redeem') . '$#', '/evangelou-club/v2/members/MEM_X/redemptions'));
    }

    public function test_namespace_match_is_exact(): void {
        foreach (array('/evangelou-club/v2', '/evangelou-club/v2/', '/evangelou-club/v2/lookup/qr') as $r) {
            $this->assertTrue(EVC_Rest_V2_Routes::is_v2_route($r), $r);
        }
        foreach (array('/evangelou-club/v1/session', '/evangelou-club/v2x/lookup/qr', '/evangelou-club/v2-shadow/members', '/wp/v2/users', '/evangelou-club', 'evangelou-club/v2/lookup/qr', '') as $r) {
            $this->assertFalse(EVC_Rest_V2_Routes::is_v2_route($r), $r);
        }
    }

    public function test_every_real_vector_passes_only_when_sealed_on_its_route(): void {
        $count = 0;
        foreach ($this->positives() as $v) {
            $id = self::SURFACE_ROUTE[$v['surface']];
            $this->assertSame(array('action' => 'pass'), EVC_Rest_V2_Envelope_Policy::decide($this->sealed_input($id, $v['body'])), $v['name']);
            $this->assertSame($v['http_status'], $this->sealed_input($id, $v['body'])['status']);
            // The same JSON, unsealed (a matching key set alone), never passes.
            $unsealed = EVC_Rest_V2_Envelope_Policy::decide($this->sealed_input($id, $v['body'], array('sealed' => null)));
            $this->assertSame('envelope', $unsealed['action'], $v['name']);
            $this->assertNotSame(200, $unsealed['status']);
            $count++;
        }
        $this->assertGreaterThanOrEqual(41, $count);
    }

    public function test_sealed_body_on_another_route_method_or_namespace_fails_closed(): void {
        $body = $this->vector('redemption fresh commit')['body'];
        $in = $this->sealed_input('redeem', $body);
        $this->assertEnvelope('server_error', 500, EVC_Rest_V2_Envelope_Policy::decide(array_merge($in, array('matched_route' => EVC_Rest_V2_Routes::pattern('usual')))), 'other designated route');
        $this->assertEnvelope('server_error', 500, EVC_Rest_V2_Envelope_Policy::decide(array_merge($in, array('method' => 'GET'))), 'other method');
        $this->assertEnvelope('server_error', 500, EVC_Rest_V2_Envelope_Policy::decide(array_merge($in, array('matched_route' => '/evangelou-club/v2/harness/undesignated'))), 'undesignated v2 route');
        $this->assertEnvelope('server_error', 500, EVC_Rest_V2_Envelope_Policy::decide(array_merge($in, array('matched_route' => null))));
        $seal = $in['sealed'];
        $seal['route_id'] = 'usual';
        $this->assertEnvelope('server_error', 500, EVC_Rest_V2_Envelope_Policy::decide(array_merge($in, array('sealed' => $seal))), 'seal for another route');
        $seal = $in['sealed'];
        $seal['surface'] = 'lookup';
        $this->assertEnvelope('server_error', 500, EVC_Rest_V2_Envelope_Policy::decide(array_merge($in, array('sealed' => $seal))), 'seal for another surface');
        foreach (array('/evangelou-club/v1/members/' . self::MEMBER . '/benefits/free_coffee/redeem', '/evangelou-club/v2-shadow/members/' . self::MEMBER . '/redemptions', '/wp/v2/posts') as $route) {
            $this->assertEnvelope('server_error', 500, EVC_Rest_V2_Envelope_Policy::decide(array_merge($in, array('route' => $route))), $route);
            $this->assertSame(array('action' => 'ignore'), EVC_Rest_V2_Envelope_Policy::decide(array_merge($in, array('route' => $route, 'sealed' => null))), 'unsealed outside v2 is untouched (v1 unchanged)');
        }
    }

    public function test_body_of_another_surface_never_passes_even_when_sealed_as_such(): void {
        $lookup = $this->vector('lookup by QR found')['body'];
        $status = $this->vector('status recorded, no handoff confirmation')['body'];
        // "found" exists in both the lookup and status maps; the shape must still match the route's surface.
        $in = $this->sealed_input('status', $status);
        $in['data'] = $lookup;
        $in['sealed']['body'] = $lookup;
        $this->assertEnvelope('server_error', 500, EVC_Rest_V2_Envelope_Policy::decide($in));
        $this->assertFalse(EVC_Contract_Envelope_Rule::is_domain_body_for('status', $lookup));
        $this->assertFalse(EVC_Contract_Envelope_Rule::is_domain_body_for('redemption', array('outcome' => 'surprise') + $this->vector('redemption fresh commit')['body']));
        $this->assertFalse(EVC_Contract_Envelope_Rule::is_domain_body_for('nope', $lookup));
    }

    public function test_tampering_after_sealing_fails_closed(): void {
        $body = $this->vector('redemption exact replay, unconfirmed')['body'];
        $in = $this->sealed_input('redeem', $body);
        $tampered = $body;
        $tampered['instruction'] = 'prepare';
        $this->assertEnvelope('server_error', 500, EVC_Rest_V2_Envelope_Policy::decide(array_merge($in, array('data' => $tampered))), 'data changed');
        $extra = $body + array('debug' => 'SQLSTATE[HY000]');
        $this->assertEnvelope('server_error', 500, EVC_Rest_V2_Envelope_Policy::decide(array_merge($in, array('data' => $extra))), 'field added');
        $this->assertEnvelope('server_error', 500, EVC_Rest_V2_Envelope_Policy::decide(array_merge($in, array('status' => 201))), 'status changed');
        $no_data = $in;
        unset($no_data['data']);
        $this->assertEnvelope('server_error', 500, EVC_Rest_V2_Envelope_Policy::decide($no_data));
    }

    public function test_status_must_be_the_recommended_one(): void {
        $unknown = $this->vector('redemption unknown commit')['body'];
        $this->assertSame(503, $this->sealed_input('redeem', $unknown)['status']);
        $this->assertSame(array('action' => 'pass'), EVC_Rest_V2_Envelope_Policy::decide($this->sealed_input('redeem', $unknown)));
        $this->assertSame($unknown['request_id'], $unknown['retry_request_id'], 'outcome_unknown keeps the same-id retry');
        foreach (array(200, 201, 409, 500) as $wrong) {
            // A seal that itself claims a status the contract does not recommend.
            $in = $this->sealed_input('redeem', $unknown, array('status' => $wrong));
            $in['sealed']['status'] = $wrong;
            $this->assertEnvelope('server_error', 500, EVC_Rest_V2_Envelope_Policy::decide($in), (string) $wrong);
        }
        // record_integrity_failure's legitimate 500 passes; an unknown outcome (also "500") does not.
        $this->assertSame(array('action' => 'pass'), EVC_Rest_V2_Envelope_Policy::decide($this->sealed_input('redeem', $this->vector('redemption record integrity failure')['body'])));
        $bogus = array('outcome' => 'surprise') + $this->vector('redemption record integrity failure')['body'];
        $in = $this->sealed_input('redeem', $bogus);
        $this->assertSame(500, $in['status']);
        $this->assertEnvelope('server_error', 500, EVC_Rest_V2_Envelope_Policy::decide($in));
    }

    public function test_401_and_unauthenticated_never_carry_a_domain_body(): void {
        $body = $this->vector('redemption fresh commit')['body'];
        $in = $this->sealed_input('redeem', $body, array('status' => 401));
        $in['sealed']['status'] = 401;
        $this->assertEnvelope('unauthorized', 401, EVC_Rest_V2_Envelope_Policy::decide($in));
        $this->assertEnvelope('unauthorized', 401, EVC_Rest_V2_Envelope_Policy::decide($this->sealed_input('redeem', $body, array('authenticated' => false))));
        $this->assertEnvelope('unauthorized', 401, EVC_Rest_V2_Envelope_Policy::decide($this->sealed_input('redeem', $body, array('sealed' => null, 'status' => 401))));
    }

    public function test_output_shaping_parameters_are_refused(): void {
        $body = $this->vector('lookup by QR found')['body'];
        $this->assertEnvelope('invalid_request', 400, EVC_Rest_V2_Envelope_Policy::decide($this->sealed_input('lookup_qr', $body, array('transport_params' => true))));
        $this->assertSame(array('_envelope', '_jsonp', '_embed', '_fields'), EVC_Rest_V2_Envelope_Policy::TRANSPORT_PARAMS);
    }

    public function test_unsealed_responses_become_minimal_transport_envelopes(): void {
        $cases = array(
            array(403, array('code' => 'rest_cookie_invalid_nonce', 'message' => 'Cookie check failed', 'data' => array('status' => 403)), 'unauthorized', 401),
            array(401, array('code' => 'rest_not_logged_in'), 'unauthorized', 401),
            array(403, array('code' => 'forbidden', 'message' => 'Forbidden.'), 'forbidden', 403),
            array(429, array('error' => array('code' => 'rate_limited')), 'rate_limited', 429),
            array(400, array('code' => 'rest_invalid_param', 'message' => 'Invalid parameter(s): request_id', 'data' => array('params' => array('request_id' => 'x'))), 'invalid_request', 400),
            array(404, array('code' => 'rest_no_route'), 'invalid_request', 404),
            array(405, array('code' => 'rest_no_route'), 'invalid_request', 405),
            array(409, array('code' => 'term_exists'), 'invalid_request', 400),
            array(503, array('error' => array('code' => 'server_error')), 'server_error', 503),
            array(500, array('code' => 'db_error', 'message' => 'SQLSTATE[42S02]: wp_evc_members', 'data' => array('status' => 500)), 'server_error', 500),
            array(502, '<html>Bad Gateway</html>', 'server_error', 500),
            array(200, array('ok' => true), 'server_error', 500),
            array(302, null, 'server_error', 500),
        );
        foreach ($cases as $c) {
            $d = EVC_Rest_V2_Envelope_Policy::decide(array('route' => '/evangelou-club/v2/lookup/qr', 'method' => 'POST', 'matched_route' => EVC_Rest_V2_Routes::pattern('lookup_qr'), 'status' => $c[0], 'data' => $c[1], 'sealed' => null, 'authenticated' => true, 'transport_params' => false));
            $this->assertEnvelope($c[2], $c[3], $d, evc_v2_boundary_test_describe($c[1]));
        }
        // A forged domain-shaped 503 outcome_unknown body without a seal stays a transport failure.
        $unknown = $this->vector('redemption unknown commit')['body'];
        $this->assertEnvelope('server_error', 503, EVC_Rest_V2_Envelope_Policy::decide($this->sealed_input('redeem', $unknown, array('sealed' => null))));
        $this->assertEnvelope('server_error', 500, EVC_Rest_V2_Envelope_Policy::decide(array('route' => '/evangelou-club/v2/x')));
    }

    public function test_domain_failures_are_never_turned_into_successes(): void {
        foreach ($this->positives() as $v) {
            $id = self::SURFACE_ROUTE[$v['surface']];
            foreach (array(200, 201) as $success) {
                if ($v['http_status'] === $success) {
                    continue;
                }
                $in = $this->sealed_input($id, $v['body'], array('status' => $success));
                $d = EVC_Rest_V2_Envelope_Policy::decide($in);
                $this->assertSame('envelope', $d['action'], $v['name']);
                $this->assertGreaterThanOrEqual(400, $d['status'], $v['name']);
            }
        }
    }
}

/** @param mixed $v */
function evc_v2_boundary_test_describe($v): string {
    return is_string($v) ? $v : (string) json_encode($v);
}
