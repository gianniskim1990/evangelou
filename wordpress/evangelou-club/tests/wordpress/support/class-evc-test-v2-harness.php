<?php
/**
 * TEST-ONLY v2 REST harness (UI-04H). NEVER shipped: lives under tests/ (the
 * packager copies only an allowlist and rejects "class EVC_Test_"), and is
 * loaded only by the disposable wp-phpunit bootstrap or the CI-only HTTP
 * mu-plugin (tests/http/mu-plugins/evc-v2-harness.php).
 *
 * It registers the DESIGNATED v2 routes (EVC_Rest_V2_Routes) with test-only
 * controllers that follow the documented auth boundary, drive the REAL
 * domain services over in-memory fakes, map outcomes with the REAL UI-04G
 * mappers and return sealed EVC_Rest_V2_Domain_Response objects; and it
 * hooks the (otherwise inactive) EVC_Rest_V2_Security filter. Adversarial
 * routes (forged, WP_Error, undesignated, wrong namespace, v1) prove the
 * boundary fails closed.
 */
final class EVC_Test_V2_Harness {
    const MEMBER_A = 'mem_0123456789abcdef0123456789abcdef';
    const MEMBER_B = 'mem_fedcba9876543210fedcba9876543210';
    const QR_TOKEN = 'evc_abababababababababababababababababababababababab';
    const PHONE = '6900000002';
    const PURPOSE = 'member_view';
    const NOW = '2026-10-14T07:30:00Z';
    const OPTION = 'evc_test_v2_harness_world';
    const SHADOW_NAMESPACE = 'evangelou-club/v2-shadow';

    /** @var array|null in-memory world (fakes only) */
    public static $world;
    /** @var string grant_all | wordpress */
    public static $authorizer_mode = 'grant_all';
    /**
     * Transport gate capability for routes whose capability EVC_Staff_Role does
     * NOT grant (handoff/status/usual; UI-04H audit). A TEST-ONLY stand-in so
     * the domain path can be exercised; null = demand the real capability.
     * @var string|null
     */
    public static $pending_gate = 'evc_redeem_benefit';
    /** @var int|null transport rate-limit budget (null = unlimited) */
    public static $transport_budget;
    /** @var string|null one-shot fault: commit_unknown|handoff_unknown|store_down|tamper|throw|rate_limited */
    public static $fault;
    /** @var bool persist the world in a WP option (real HTTP: one PHP process per request) */
    public static $persistent = false;

    public static function reset(): void {
        $store = new EVC_In_Memory_Prepared_Redemption_Store();
        $store->add_member(self::MEMBER_A, 501);
        $store->add_member(self::MEMBER_B, 502);
        $membership = new EVC_Mock_Membership_Adapter();
        $membership->set(501, EVC_Mock_Membership_Adapter::active_until('2026-11-14T07:30:00Z'));
        $membership->set(502, EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_EXPIRED, true, '2026-10-01T00:00:00Z'));
        $qr = new EVC_Fake_Qr_Token_Store();
        $qr->add_member(self::MEMBER_A, 501);
        $qr->add_member(self::MEMBER_B, 502);
        $qr->rotate(1, null, hash('sha256', self::QR_TOKEN), '2026-10-01 09:00:00.000000');
        $phones = new EVC_Fake_Member_Phone_Directory();
        $phones->numbers['+30' . self::PHONE] = array(1);
        $catalogue = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/preparation/catalogue-illustrative.json'), true);
        self::$world = array(
            'store' => $store,
            'membership' => $membership,
            'qr' => $qr,
            'phones' => $phones,
            'limiter' => new EVC_Fake_Lookup_Rate_Limiter(),
            'clock' => new EVC_Fixed_Clock(self::NOW),
            'authority' => new EVC_Fake_Prep_Catalogue_Authority($catalogue),
        );
        self::$authorizer_mode = 'grant_all';
        self::$pending_gate = EVC_Staff_Role::CAP_REDEEM;
        self::$transport_budget = null;
        self::$fault = null;
    }

    /** Hooks the harness into the CURRENT WordPress process (tests / CI-only mu-plugin). */
    public static function install(): void {
        if (self::$world === null) {
            self::reset();
        }
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_filter('rest_post_dispatch', array('EVC_Rest_V2_Security', 'filter_post_dispatch'), EVC_Rest_V2_Security::PRIORITY, 3);
    }

    public static function uninstall(): void {
        remove_action('rest_api_init', array(__CLASS__, 'register_routes'));
        remove_filter('rest_post_dispatch', array('EVC_Rest_V2_Security', 'filter_post_dispatch'), EVC_Rest_V2_Security::PRIORITY);
    }

    public static function load(): void {
        $saved = get_option(self::OPTION);
        if (is_string($saved) && $saved !== '') {
            $state = unserialize(base64_decode($saved), array('allowed_classes' => array(
                'EVC_In_Memory_Prepared_Redemption_Store', 'EVC_Mock_Membership_Adapter', 'EVC_Fake_Qr_Token_Store', 'EVC_Fake_Member_Phone_Directory',
                'EVC_Fake_Lookup_Rate_Limiter', 'EVC_Fixed_Clock', 'EVC_Fake_Prep_Catalogue_Authority', 'EVC_Entitlement', 'DateTimeImmutable', 'DateTimeZone',
            )));
            if (is_array($state) && isset($state['world'])) {
                self::$world = $state['world'];
                self::$transport_budget = $state['budget'];
                return;
            }
        }
        self::reset();
    }

    public static function save(): void {
        if (self::$world === null) {
            return;
        }
        // Diagnostics (exceptions, call logs) are never persisted.
        self::$world['store']->failures = array();
        self::$world['store']->rejections = array();
        self::$world['membership']->calls = array();
        self::$world['limiter']->seen = array();
        update_option(self::OPTION, base64_encode(serialize(array('world' => self::$world, 'budget' => self::$transport_budget))), false);
    }

    public static function register_routes(): void {
        foreach (EVC_Rest_V2_Routes::ROUTES as $id => $r) {
            register_rest_route(EVC_Rest_V2_Routes::NAMESPACE_V2, $r['route'], array(
                'methods' => $r['method'],
                'callback' => function (WP_REST_Request $request) use ($id) {
                    return self::handle($id, $request);
                },
                'permission_callback' => function (WP_REST_Request $request) use ($id) {
                    return self::gate($id, $request);
                },
            ));
        }
        $open = function () {
            return true;
        };
        // ---- adversarial, NOT designated ---------------------------------------------------
        register_rest_route(EVC_Rest_V2_Routes::NAMESPACE_V2, '/harness/forged', array('methods' => 'GET', 'permission_callback' => $open, 'callback' => function () {
            // A plain response whose JSON key set matches a redemption body exactly.
            return new WP_REST_Response(self::forged_redeemed_body(), 201);
        }));
        register_rest_route(EVC_Rest_V2_Routes::NAMESPACE_V2, '/harness/forged-unknown', array('methods' => 'GET', 'permission_callback' => $open, 'callback' => function () {
            $body = self::forged_redeemed_body();
            $body['outcome'] = 'outcome_unknown';
            return new WP_REST_Response($body, 503);
        }));
        register_rest_route(EVC_Rest_V2_Routes::NAMESPACE_V2, '/harness/wp-error', array('methods' => 'GET', 'permission_callback' => $open, 'callback' => function () {
            return new WP_Error('evc_internal_failure', 'SQLSTATE[HY000] wp_evc_members secret=hunter2', array('status' => 500, 'trace' => '/var/www/x.php:12'));
        }));
        register_rest_route(EVC_Rest_V2_Routes::NAMESPACE_V2, '/harness/undesignated', array('methods' => 'GET', 'permission_callback' => $open, 'callback' => function () {
            return new EVC_Rest_V2_Domain_Response('lookup_qr', array('result' => 'not_found', 'member' => null));
        }));
        register_rest_route(self::SHADOW_NAMESPACE, '/members/' . EVC_Rest_V2_Routes::MEMBER . '/redemptions', array('methods' => 'POST', 'permission_callback' => $open, 'callback' => function () {
            return new EVC_Rest_V2_Domain_Response('lookup_qr', array('result' => 'not_found', 'member' => null));
        }));
        register_rest_route(EVC_Rest_Security::NAMESPACE_V1, '/harness/sealed', array('methods' => 'GET', 'permission_callback' => $open, 'callback' => function () {
            return new EVC_Rest_V2_Domain_Response('lookup_qr', array('result' => 'not_found', 'member' => null));
        }));
    }

    /** Documented auth boundary, steps 1-4 (transport envelope on failure). @return true|WP_Error */
    public static function gate(string $id, WP_REST_Request $request) {
        $route = EVC_Rest_V2_Routes::route($id);
        $capability = $route['capability'];
        if (!in_array($capability, EVC_Staff_Role::staff_capabilities(), true) && self::$pending_gate !== null) {
            $capability = self::$pending_gate;
        }
        return EVC_Staff_Auth::authorize($request, $capability);
    }

    /**
     * Step 5: per-session request budget, consumed ONCE per handled request
     * (never in the permission callback: core re-runs those for the Allow header).
     * @return true|WP_Error
     */
    private static function consume_budget() {
        if (self::$transport_budget === null) {
            return true;
        }
        if (self::$transport_budget <= 0) {
            return new WP_Error('rate_limited', 'Too many requests.', array('status' => 429));
        }
        self::$transport_budget--;
        return true;
    }

    private static function actor(): EVC_Staff_Actor {
        $token = wp_get_session_token();
        return new EVC_Staff_Actor(get_current_user_id(), (is_string($token) && $token !== '') ? EVC_Staff_Session::session_ref($token) : null);
    }

    private static function authorizer(): EVC_Club_Staff_Authorizer {
        if (self::$authorizer_mode === 'wordpress') {
            return new EVC_Test_V2_Wp_Authorizer();
        }
        $grant = new EVC_Fake_Club_Staff_Authorizer();
        $grant->grants[get_current_user_id()] = array(EVC_Member_Lookup_Service::CAPABILITY, EVC_Club_Staff_Authorizer::CONFIRM_HANDOFF, EVC_Club_Staff_Authorizer::VIEW_REDEMPTION_STATUS, EVC_Usual_Coffee_Service::CAPABILITY);
        return $grant;
    }

    private static function keys(): EVC_Lookup_Key_Ring {
        return new EVC_Lookup_Key_Ring(array('k1' => str_repeat("\x01", 32)), 'k1');
    }

    private static function proof_verifier(): EVC_Signed_Member_Context_Verifier {
        return new EVC_Signed_Member_Context_Verifier(self::keys(), new EVC_Lookup_Context_Policy(300), self::$world['clock'], self::PURPOSE);
    }

    private static function lookup_service(): EVC_Member_Lookup_Service {
        $w = self::$world;
        return new EVC_Member_Lookup_Service($w['qr'], $w['phones'], self::authorizer(), $w['limiter'], self::keys(), new EVC_Lookup_Context_Policy(300), EVC_Qr_Token_Policy::without_expiry(), $w['clock'], self::PURPOSE);
    }

    /** The controller: defense in depth (gate again), body/URL handling, real services, sealed reply. */
    public static function handle(string $id, WP_REST_Request $request) {
        $gate = self::gate($id, $request);
        if (is_wp_error($gate)) {
            return $gate;
        }
        $budget = self::consume_budget();
        if (is_wp_error($budget)) {
            return $budget;
        }
        try {
            $fault = self::$fault;
            self::$fault = null;
            $header_fault = $request->get_header('X-EVC-Harness-Fault');
            if ($fault === null && self::$persistent && is_string($header_fault) && $header_fault !== '') {
                $fault = $header_fault;
            }
            $w = self::$world;
            if ($fault === 'commit_unknown') {
                $w['store']->commit_unknown = 'committed';
            } elseif ($fault === 'handoff_unknown') {
                $w['store']->handoff_commit_unknown = 'committed';
            } elseif ($fault === 'store_down') {
                $w['store']->unavailable = true;
            } elseif ($fault === 'throw') {
                throw new RuntimeException('SQLSTATE[HY000] secret');
            } elseif ($fault === 'rate_limited') {
                return new WP_Error('rate_limited', 'Too many requests.', array('status' => 429));
            }
            $url = $request->get_url_params();
            $member = isset($url['member_id']) ? (string) $url['member_id'] : '';
            $json = $request->get_json_params();
            $body = is_array($json) ? $json : array();
            $actor = self::actor();
            switch ($id) {
                case 'lookup_qr':
                    $payload = EVC_Lookup_Response::payload(self::lookup_service()->by_qr($actor, isset($body['qr_token']) ? $body['qr_token'] : null));
                    break;
                case 'lookup_phone':
                    $payload = EVC_Lookup_Response::payload(self::lookup_service()->by_phone($actor, isset($body['phone']) ? $body['phone'] : null));
                    break;
                case 'redeem':
                    // Step 6: lookup proof for exactly this staff, session and member — before any write.
                    if (!self::proof_verifier()->verify($actor, $member, isset($body['lookup_ref']) ? $body['lookup_ref'] : null)) {
                        return EVC_Rest_Security::error_response('forbidden', 403);
                    }
                    unset($body['lookup_ref']);
                    $service = new EVC_Prepared_Redemption_Service($w['store'], $w['authority'], $w['membership'], $w['clock'], EVC_Prepared_Redemption_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS);
                    $outcome = $service->redeem($member, 'free_coffee', $actor, $body);
                    $payload = EVC_Redemption_Response::payload($outcome, isset($body['request_id']) ? $body['request_id'] : null, $w['clock']);
                    break;
                case 'status':
                    $reader = new EVC_Fulfillment_Read_Model($w['store'], self::authorizer(), $w['clock']);
                    $payload = EVC_Status_Response::payload($reader->redemption_status($actor, $member, (string) $url['request_id']));
                    break;
                case 'handoff':
                    // The URL is the authority for the redemption; a contradicting body field is malformed.
                    if (array_key_exists('redemption_request_id', $body) && $body['redemption_request_id'] !== $url['request_id']) {
                        $body = array();
                    } else {
                        $body['redemption_request_id'] = (string) $url['request_id'];
                    }
                    $service = new EVC_Handoff_Service($w['store'], self::authorizer(), new EVC_No_Late_Handoff_Policy(), $w['clock']);
                    $payload = EVC_Handoff_Response::payload($service->confirm($member, $actor, $body), isset($body['handoff_request_id']) ? $body['handoff_request_id'] : null);
                    break;
                case 'usual':
                    $service = new EVC_Usual_Coffee_Service(new EVC_Fake_Redemption_History($w['store']), $w['authority'], self::authorizer(), self::proof_verifier(), $w['clock'], EVC_Usual_Coffee_Policy::provisional(), EVC_Usual_Coffee_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS);
                    $payload = EVC_Usual_Coffee_Response::payload($service->suggest($actor, $member, isset($body['lookup_ref']) ? $body['lookup_ref'] : null));
                    break;
                default:
                    return EVC_Rest_Security::error_response('server_error', 500);
            }
            $w['store']->commit_unknown = null;
            $w['store']->handoff_commit_unknown = null;
            $w['store']->unavailable = false;
            $response = new EVC_Rest_V2_Domain_Response($id, $payload);
            if ($fault === 'tamper') {
                // Another component rewriting the body after sealing.
                $data = $response->get_data();
                if (isset($data['instruction'])) {
                    $data['instruction'] = 'prepare';
                } else {
                    $data['tampered'] = true;
                }
                $response->set_data($data);
            }
            return $response;
        } catch (Throwable $e) {
            return EVC_Rest_Security::error_response('server_error', 500);
        }
    }

    /** A real-looking redemption body built by hand (never from a mapper). */
    public static function forged_redeemed_body(): array {
        return array(
            'outcome' => 'redeemed',
            'request_id' => '00000000-0000-4000-8000-0000000000ff',
            'recording' => 'recorded_now',
            'fulfillment' => 'unconfirmed',
            'instruction' => 'prepare',
            'entitlement_consumed' => true,
            'server_business_date' => '2026-10-14',
            'redemption' => array('request_id' => '00000000-0000-4000-8000-0000000000ff', 'member_id' => self::MEMBER_A, 'business_date' => '2026-10-14', 'redeemed_at_utc' => '2026-10-14 07:30:00.000000'),
            'handoff' => null,
        );
    }
}

/** TEST-ONLY authorizer reading the REAL WordPress capabilities of the actor. */
final class EVC_Test_V2_Wp_Authorizer implements EVC_Club_Staff_Authorizer {
    public function allows(EVC_Staff_Actor $actor, string $capability): bool {
        $user = get_userdata($actor->wp_user_id());
        return $user instanceof WP_User && EVC_Staff_Role::is_restricted_staff_account($user) && user_can($user, $capability);
    }
}
