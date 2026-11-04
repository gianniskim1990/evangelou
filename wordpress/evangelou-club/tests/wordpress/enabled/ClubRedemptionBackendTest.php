<?php

/**
 * STAGE-05A: EVC_Club_Redemption_Backend with the staff flag ON and EVC_CLUB_REDEMPTION_SOURCE
 * UNDEFINED (this process), i.e. the shipped default:
 *  - the real route keeps the always-unavailable backend and answers exactly the old 503;
 *  - the backend's own checks, through its TEST seams (environment / connect / clock / log): every
 *    missing or wrong piece -> null (503) with NO Club DB connection attempted; exact values only;
 *  - the approved v1 coffee catalogue (owner decision D3, 2026-11-03).
 * Real membership end to end (WooCommerce / PMPro, recorded provenance) is in
 * tests/wordpress/commerce-redeem (CI only).
 */
final class ClubRedemptionBackendTest extends EVC_WP_Test_Case {
    const KEY = 'evc-test-only-evidence-key-0123456789abcdef';

    /** @var EVC_Test_Database[] */
    private $databases = array();
    /** @var int */
    private $connects = 0;
    /** @var int */
    private $environment_reads = 0;
    /** @var string[] */
    private $log = array();

    public function tear_down() {
        foreach ($this->databases as $db) {
            $db->drop();
        }
        $this->databases = array();
        parent::tear_down();
    }

    private function database(bool $migrate = true, ?array $only = null): EVC_Test_Database {
        $db = EVC_Test_Database::create();
        $this->databases[] = $db;
        if ($migrate) {
            $dir = EVC_Test_Database::migrations_dir();
            if ($only !== null) {
                $dir = sys_get_temp_dir() . '/evc-migrations-' . bin2hex(random_bytes(4));
                mkdir($dir);
                foreach ($only as $file) {
                    copy(EVC_Test_Database::migrations_dir() . '/' . $file, $dir . '/' . $file);
                }
            }
            (new EVC_Migrator($db->connect(), $dir))->migrate(PHP_INT_MAX);
        }
        return $db;
    }

    /** A complete, valid configuration (what wp-config.php would hold when everything is set). */
    private function good_environment(?EVC_Db_Config $db = null): array {
        return array(
            'staff' => true,
            'redemption' => 'club_db_v1',
            'provenance' => 'club_db_v1',
            'commerce' => EVC_Commerce_Source_Config::parse('pmpro_woocommerce_bacs_v1', 1, 24435, self::KEY),
            'db' => $db ?? $this->database()->config(),
        );
    }

    private function backend(array $environment, ?EVC_Redemption_Catalogue_Source $catalogue = null, ?callable $connect = null): EVC_Club_Redemption_Backend {
        return new EVC_Club_Redemption_Backend(
            $catalogue ?? new EVC_Approved_Coffee_Catalogue_Source(),
            function () use ($environment) {
                $this->environment_reads++;
                return $environment;
            },
            function (EVC_Db_Config $config) use ($connect) {
                $this->connects++;
                return $connect === null ? EVC_Club_Db::connect($config) : $connect($config);
            },
            new EVC_Fixed_Clock('2026-10-09T10:00:00Z'),
            function (string $line) {
                $this->log[] = $line;
            }
        );
    }

    private function assertRefused(EVC_Club_Redemption_Backend $backend, string $reason, bool $connected = false, string $label = ''): void {
        $this->connects = 0;
        $this->assertNull($backend->service(), $label);
        $this->assertSame($reason, $backend->last_reason(), $label);
        $this->assertSame($connected ? 1 : 0, $this->connects, $label . ': Club DB connection attempts');
    }

    private static function catalogue_source(?EVC_Coffee_Catalog $catalogue): EVC_Redemption_Catalogue_Source {
        return new class($catalogue) implements EVC_Redemption_Catalogue_Source {
            private $c;

            public function __construct(?EVC_Coffee_Catalog $c) {
                $this->c = $c;
            }

            public function catalogue(): ?EVC_Coffee_Catalog {
                return $this->c;
            }
        };
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

    // ---- the shipped default: constant undefined ---------------------------------------------------

    public function test_without_the_constant_the_route_keeps_the_unavailable_backend_and_the_old_503(): void {
        $this->assertFalse(defined('EVC_CLUB_REDEMPTION_SOURCE'));
        $this->assertFalse(EVC_Club_Redemption_Feature::enabled());
        $this->assertInstanceOf(EVC_Unavailable_Redemption_Backend::class, $this->registered_backend());
        $this->login_as($this->create_staff_user());
        $response = $this->rest('POST', $this->redeem_route(EVC_Member_Id::mint()), array('request_id' => self::uuid4(), 'coffee_code' => 'espresso'), $this->nonce());
        $this->assertEnvelope($response, 503, 'server_error');
        $this->assertSame(array('error' => array('code' => 'server_error', 'message' => EVC_Rest_Security::MESSAGES['server_error'])), $response->get_data());
    }

    /** STAGE-05C: without EVC_CLUB_REDEMPTION_SOURCE the QR scan / revocation routes do not exist at all. */
    public function test_without_the_constant_no_qr_route_is_registered(): void {
        $routes = array_keys(rest_get_server()->get_routes());
        $this->assertNotContains('/' . EVC_Rest_Security::NAMESPACE_V1 . EVC_Rest_Lookup_Controller::LOOKUP_ROUTE, $routes);
        $this->assertNotContains('/' . EVC_Rest_Security::NAMESPACE_V1 . EVC_Rest_Lookup_Controller::REVOKE_ROUTE, $routes);
        $this->login_as($this->create_staff_user());
        $this->assertSame(404, $this->rest('POST', '/' . EVC_Rest_Security::NAMESPACE_V1 . '/members/lookup', array('method' => 'qr', 'qr_token' => EVC_Qr_Token::mint()), $this->nonce())->get_status());
    }

    public function test_only_the_exact_string_enables_the_constant(): void {
        $this->assertTrue(EVC_Club_Redemption_Feature::is_enabled_value('club_db_v1'));
        foreach (array(true, 1, '1', 'true', 'CLUB_DB_V1', 'Club_Db_V1', ' club_db_v1', 'club_db_v1 ', "club_db_v1\n", 'club_db_v2', 'pmpro_woocommerce_bacs_v1', '', null, false, array('club_db_v1')) as $value) {
            $this->assertFalse(EVC_Club_Redemption_Feature::is_enabled_value($value), var_export($value, true));
        }
    }

    // ---- the backend's checks --------------------------------------------------------------------

    public function test_constructing_the_backend_reads_and_connects_nothing(): void {
        $backend = $this->backend($this->good_environment());
        $this->assertSame(0, $this->environment_reads);
        $this->assertSame(0, $this->connects);
        $this->assertNull($backend->last_reason());
        $this->assertSame(array(), $this->log);
    }

    public function test_a_complete_configuration_builds_the_real_service_with_one_connection(): void {
        $backend = $this->backend($this->good_environment());
        $this->connects = 0;
        $this->assertInstanceOf(EVC_Redemption_Service::class, $backend->service());
        $this->assertNull($backend->last_reason());
        $this->assertSame(1, $this->connects);
        $this->assertSame(1, $this->environment_reads, 'read at request time, once');
        $this->assertSame(array(), $this->log);
    }

    public function test_staff_flag_must_be_exactly_true(): void {
        foreach (array('missing' => null, 'string true' => 'true', 'one' => 1, 'false' => false) as $label => $value) {
            $env = $this->good_environment();
            if ($value === null) {
                unset($env['staff']);
            } else {
                $env['staff'] = $value;
            }
            $this->assertRefused($this->backend($env), EVC_Club_Redemption_Backend::REASON_STAFF, false, $label);
        }
    }

    public function test_redemption_constant_must_be_exactly_club_db_v1(): void {
        foreach (array('missing' => null, 'true' => true, 'upper case' => 'CLUB_DB_V1', 'leading space' => ' club_db_v1', 'trailing space' => 'club_db_v1 ', 'other version' => 'club_db_v2', 'one' => 1) as $label => $value) {
            $env = $this->good_environment();
            if ($value === null) {
                unset($env['redemption']);
            } else {
                $env['redemption'] = $value;
            }
            $this->assertRefused($this->backend($env), EVC_Club_Redemption_Backend::REASON_REDEMPTION, false, $label);
        }
    }

    public function test_commerce_source_must_be_configured(): void {
        $env = $this->good_environment();
        $env['commerce'] = null;
        $this->assertRefused($this->backend($env), EVC_Club_Redemption_Backend::REASON_COMMERCE);
        $env['commerce'] = EVC_Commerce_Source_Config::parse('pmpro_woocommerce_bacs_v1', '1', 24435, self::KEY);
        $this->assertNull($env['commerce'], 'exact types only (string level id)');
        $this->assertRefused($this->backend($env), EVC_Club_Redemption_Backend::REASON_COMMERCE, false, 'string level id');
        unset($env['commerce']);
        $this->assertRefused($this->backend($env), EVC_Club_Redemption_Backend::REASON_COMMERCE, false, 'missing');
    }

    public function test_recorded_payment_provenance_is_required_and_never_falls_back(): void {
        foreach (array('missing' => null, 'true' => true, 'upper case' => 'CLUB_DB_V1', 'leading space' => ' club_db_v1', 'empty' => '', 'unrecorded' => 'unrecorded') as $label => $value) {
            $env = $this->good_environment();
            if ($value === null) {
                unset($env['provenance']);
            } else {
                $env['provenance'] = $value;
            }
            $this->assertRefused($this->backend($env), EVC_Club_Redemption_Backend::REASON_PROVENANCE, false, $label);
        }
    }

    public function test_club_db_constants_are_required(): void {
        $env = $this->good_environment();
        $env['db'] = null;
        $this->assertRefused($this->backend($env), EVC_Club_Redemption_Backend::REASON_DB_CONFIG);
        unset($env['db']);
        $this->assertRefused($this->backend($env), EVC_Club_Redemption_Backend::REASON_DB_CONFIG, false, 'missing');
    }

    public function test_a_null_or_empty_catalogue_is_unavailable(): void {
        $env = $this->good_environment();
        $this->assertRefused($this->backend($env, self::catalogue_source(null)), EVC_Club_Redemption_Backend::REASON_CATALOGUE, false, 'null');
        $this->assertRefused($this->backend($env, self::catalogue_source(new EVC_Coffee_Catalog(array()))), EVC_Club_Redemption_Backend::REASON_CATALOGUE, false, 'empty');
    }

    public function test_an_unreachable_club_db_is_unavailable_and_logs_no_secret(): void {
        $secret_password = 'pw-' . bin2hex(random_bytes(8));
        $secret_user = 'u' . bin2hex(random_bytes(4));
        $secret_db = 'evc_secret_' . bin2hex(random_bytes(4));
        $env = $this->good_environment(new EVC_Db_Config('127.0.0.1', 1, $secret_db, $secret_user, $secret_password));
        // The real connector: nothing listens on port 1.
        $this->assertRefused($this->backend($env), EVC_Club_Redemption_Backend::REASON_CONNECT, true, 'real connector');
        // A connector that throws with secrets in its message.
        $backend = $this->backend($env, null, function () use ($secret_password, $secret_user, $secret_db) {
            throw new RuntimeException("SQLSTATE[HY000] [1045] Access denied for user '$secret_user' to '$secret_db' using password $secret_password");
        });
        $this->assertRefused($backend, EVC_Club_Redemption_Backend::REASON_CONNECT, true, 'throwing connector');
        $this->assertNotEmpty($this->log);
        foreach ($this->log as $line) {
            foreach (array($secret_password, $secret_user, $secret_db, 'Access denied', '127.0.0.1', self::KEY) as $secret) {
                $this->assertStringNotContainsString($secret, $line);
            }
            $this->assertStringStartsWith('Evangelou Club: staff redemption unavailable (club_db_unavailable', $line);
        }
        $this->assertRefused($this->backend($env, null, function () {
            return new stdClass();
        }), EVC_Club_Redemption_Backend::REASON_CONNECT, true, 'not a connection');
    }

    public function test_the_club_db_must_hold_migration_002(): void {
        $this->assertRefused($this->backend($this->good_environment($this->database(false)->config())), EVC_Club_Redemption_Backend::REASON_SCHEMA, true, 'empty database');
        $this->assertRefused($this->backend($this->good_environment($this->database(true, array('001_initial.sql'))->config())), EVC_Club_Redemption_Backend::REASON_SCHEMA, true, 'only 001');
    }

    public function test_an_unreadable_environment_is_unavailable(): void {
        $backend = new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source(), function () {
            throw new InvalidArgumentException('Club DB port is out of range.');
        }, function () {
            $this->connects++;
        }, null, function (string $line) {
            $this->log[] = $line;
        });
        $this->assertRefused($backend, EVC_Club_Redemption_Backend::REASON_ENVIRONMENT);
        $this->assertRefused(new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source(), function () {
            return 'not an array';
        }, null, null, function () {
        }), EVC_Club_Redemption_Backend::REASON_ENVIRONMENT, false, 'not an array');
    }

    public function test_the_default_environment_reads_the_constants_of_this_site(): void {
        $env = EVC_Club_Redemption_Backend::constants();
        $this->assertSame(true, $env['staff']);
        $this->assertNull($env['redemption']);
        $this->assertNull($env['provenance']);
        $this->assertNull($env['commerce']);
        $this->assertNull($env['db']);
        $backend = new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source(), null, function () {
            $this->connects++;
        }, null, function () {
        });
        $this->assertRefused($backend, EVC_Club_Redemption_Backend::REASON_REDEMPTION);
    }

    // ---- through the route (no WooCommerce in this suite: the real reader says "unverified") ----------

    private function seeded_route(): array {
        $handle = $this->database();
        $member = EVC_Member_Id::mint();
        $handle->connect()->execute(
            'INSERT INTO evc_members (member_public_id, wp_user_id, status, created_at_utc, updated_at_utc) VALUES (?, ?, ?, ?, ?)',
            array($member, 4242, 'enabled', '2026-09-01 00:00:00.000000', '2026-09-01 00:00:00.000000')
        );
        (new EVC_Rest_Redeem_Controller($this->backend($this->good_environment($handle->config()))))->register_routes(true);
        $this->login_as($this->create_staff_user());
        return array($handle, $member);
    }

    public function test_the_route_reaches_the_real_membership_source_and_never_grants_without_it(): void {
        list($handle, $member) = $this->seeded_route();
        $response = $this->rest('POST', $this->redeem_route($member), array('request_id' => self::uuid4(), 'coffee_code' => 'other_coffee'), $this->nonce());
        $this->assertEnvelope($response, 409, 'membership_inactive');
        $this->assertSame(0, (int) $handle->connect()->fetch_one('SELECT COUNT(*) AS n FROM evc_redemptions')['n']);
        $audit = $handle->connect()->fetch_one("SELECT outcome, details_json FROM evc_audit_events WHERE event_type = 'redemption'");
        $this->assertSame('membership_inactive', $audit['outcome']);
        $this->assertSame('pmpro', json_decode($audit['details_json'], true)['source'], 'the PMPro adapter answered, not a mock');

        $this->assertEnvelope($this->rest('POST', $this->redeem_route(EVC_Member_Id::mint()), array('request_id' => self::uuid4(), 'coffee_code' => 'espresso'), $this->nonce()), 404, 'member_not_found');
        $this->assertEnvelope($this->rest('POST', $this->redeem_route($member), array('request_id' => self::uuid4(), 'coffee_code' => 'mocha_latte'), $this->nonce()), 400, 'invalid_coffee');
    }

    public function test_unauthorised_requests_never_build_the_backend(): void {
        list(, $member) = $this->seeded_route();
        wp_set_current_user(0);
        $_COOKIE = array();
        $this->environment_reads = 0;
        $this->connects = 0;
        $this->assertEnvelope($this->rest('POST', $this->redeem_route($member), array('request_id' => self::uuid4(), 'coffee_code' => 'espresso')), 401, 'unauthorized');
        $this->login_as($this->create_user_with_role('editor'));
        $this->assertEnvelope($this->rest('POST', $this->redeem_route($member), array('request_id' => self::uuid4(), 'coffee_code' => 'espresso'), $this->nonce()), 403, 'forbidden');
        $this->assertSame(0, $this->environment_reads);
        $this->assertSame(0, $this->connects);
    }

    // ---- the approved v1 coffee catalogue (owner decision D3) ------------------------------------

    public function test_the_approved_v1_catalogue_is_exactly_the_owner_list(): void {
        $this->assertSame('evc-coffee-v1-2026-11-03', EVC_Approved_Coffee_Catalogue_Source::VERSION);
        $catalogue = (new EVC_Approved_Coffee_Catalogue_Source())->catalogue();
        $this->assertInstanceOf(EVC_Coffee_Catalog::class, $catalogue);
        $expected = array(
            'espresso' => 'Espresso',
            'freddo_espresso' => 'Freddo Espresso',
            'cappuccino' => 'Cappuccino',
            'freddo_cappuccino' => 'Freddo Cappuccino',
            'greek_coffee' => 'Ελληνικός',
            'filter_coffee' => 'Φίλτρου',
            'other_coffee' => 'Άλλος καφές',
        );
        $this->assertSame(array_keys($expected), $catalogue->codes());
        foreach ($expected as $code => $label) {
            $this->assertSame($label, $catalogue->label($code), $code);
        }
        $this->assertTrue($catalogue->contains('other_coffee'), 'the catch-all: no coffee the shop serves is refused');
        foreach (array('mocha_latte', 'Espresso', 'other', '', 'other_coffee ', 'TEST Espresso') as $unknown) {
            $this->assertFalse($catalogue->contains($unknown), $unknown);
        }
    }
}
