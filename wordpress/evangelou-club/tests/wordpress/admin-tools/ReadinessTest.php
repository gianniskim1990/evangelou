<?php
/**
 * STAGE-08: the read-only «Ετοιμότητα» checks (no commerce plugins in this suite; see ReadinessCommerceTest for the
 * real shop). Each status, no side effects, no secrets.
 */
final class ReadinessTest extends EVC_WP_Test_Case {
    /** @var EVC_Test_Database|null */
    private $club;
    /** @var int */
    private $admin;

    public function set_up() {
        parent::set_up();
        EVC_Staff_Role::register();
        $this->admin = (int) self::factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($this->admin);
    }

    public function tear_down() {
        if ($this->club !== null) {
            $this->club->drop();
            $this->club = null;
        }
        parent::tear_down();
    }

    /** @param array<string,mixed> $seams */
    private function readiness(array $seams = array()): EVC_Readiness {
        EVC_Admin_Tools_Plugin::boot($seams);
        return EVC_Admin_Tools_Plugin::parts()['readiness'];
    }

    /** @return array<string,array{section:string,key:string,label:string,status:string,detail:string}> */
    private static function by_key(EVC_Readiness $r): array {
        $out = array();
        foreach ($r->checks() as $row) {
            $out[$row['key']] = $row;
        }
        return $out;
    }

    private function migrated_club(bool $migrate = true): EVC_Test_Database {
        $this->club = EVC_Test_Database::create();
        if ($migrate) {
            (new EVC_Migrator($this->club->connect(), EVC_Test_Database::migrations_dir()))->migrate(2);
        }
        return $this->club;
    }

    public function test_the_club_database_missing_unreachable_or_at_the_target_schema(): void {
        $rows = self::by_key($this->readiness(array('db_config' => function () {
            return null;
        })));
        $this->assertSame('missing', $rows['club_db']['status']);
        $this->assertArrayNotHasKey('club_db_schema', $rows);
        $rows = self::by_key($this->readiness(array('db_config' => function () {
            return new EVC_Db_Config('127.0.0.1', 1, 'evc_nowhere', 'nobody', 'secret-password-xyz');
        }, 'connect' => function () {
            throw new EVC_Db_Exception('Club database is unavailable.');
        })));
        $this->assertSame('wrong', $rows['club_db']['status']);
        $this->assertStringContainsString('EVC_Db_Exception', $rows['club_db']['detail']);
        $this->assertStringContainsString('1044', $rows['club_db']['detail'], 'points at the known technician blocker');
        $club = $this->migrated_club(false);
        $rows = self::by_key($this->readiness(array('db_config' => function () use ($club) {
            return $club->config();
        })));
        $this->assertSame('ok', $rows['club_db']['status']);
        $this->assertSame('missing', $rows['club_db_schema']['status'], 'an empty database');
        (new EVC_Migrator($club->connect(), EVC_Test_Database::migrations_dir()))->migrate(1);
        $rows = self::by_key($this->readiness(array('db_config' => function () use ($club) {
            return $club->config();
        })));
        $this->assertSame('wrong', $rows['club_db_schema']['status'], 'version 1 of target 2');
        (new EVC_Migrator($club->connect(), EVC_Test_Database::migrations_dir()))->migrate(2);
        $rows = self::by_key($this->readiness(array('db_config' => function () use ($club) {
            return $club->config();
        })));
        $this->assertSame('ok', $rows['club_db_schema']['status']);
        $this->assertStringContainsString('2', $rows['club_db_schema']['detail']);
        $this->assertContains($rows['club_db_server']['status'], array('ok', 'manual'));
    }

    public function test_an_unreadable_migrations_ledger_is_not_read_as_nothing_applied(): void {
        $club = $this->migrated_club(false);
        // A ledger table that exists but cannot be read as one (no version column): never «no migration applied».
        $club->connect()->exec_raw('CREATE TABLE evc_schema_migrations (id INT PRIMARY KEY)');
        $rows = self::by_key($this->readiness(array('db_config' => function () use ($club) {
            return $club->config();
        })));
        $this->assertSame('ok', $rows['club_db']['status']);
        $this->assertSame('unreadable', $rows['club_db_schema']['status']);
        $this->assertStringContainsString('Βάση δεδομένων', $rows['club_db_schema']['detail'], 'points at the migrations page checks');
        $this->assertStringNotContainsString('καμία μετάβαση', $rows['club_db_schema']['detail']);
        $this->assertSame('Δεν διαβάζεται', EVC_Readiness::STATUS_LABELS['unreadable']);
        $html = EVC_Admin_Tools_Plugin::parts()['readiness_page']->html();
        $this->assertStringContainsString('data-check="club_db_schema" data-status="unreadable"', $html);
        $this->assertStringContainsString('Δεν διαβάζεται: 1', $html);
    }

    public function test_the_payment_provenance_reads_ok_only_for_the_exact_value(): void {
        $none = function () {
            return null;
        };
        $rows = self::by_key($this->readiness(array('db_config' => $none)));
        $this->assertSame('missing', $rows['payment_provenance']['status'], 'not defined in this suite');
        $this->assertSame('shop', $rows['payment_provenance']['section']);
        foreach (array(array('club_db_v1', 'ok'), array('club_db_v2', 'wrong'), array(' club_db_v1', 'wrong'), array('CLUB_DB_V1', 'wrong'), array(true, 'wrong'), array(1, 'wrong'), array('', 'wrong'), array(null, 'wrong')) as $case) {
            $rows = self::by_key($this->readiness(array('db_config' => $none, 'constants' => array('EVC_CLUB_PAYMENT_PROVENANCE' => $case[0]))));
            $this->assertSame($case[1], $rows['payment_provenance']['status'], var_export($case[0], true));
            $this->assertSame($case[1], EVC_Readiness::provenance_status(true, $case[0]));
            if (is_string($case[0]) && $case[0] !== '' && $case[1] !== 'ok') {
                $this->assertStringNotContainsString($case[0], $rows['payment_provenance']['detail'], 'the value is never shown');
            }
        }
        $this->assertSame('missing', EVC_Readiness::provenance_status(false, 'club_db_v1'));
        // The same exact rule as the routes it gates.
        $this->assertSame(EVC_Customer_Qr_Backend::PROVENANCE, EVC_Readiness::PROVENANCE_VALUE);
    }

    public function test_the_lookup_key_reads_ok_only_when_valid_and_is_never_shown(): void {
        $none = function () {
            return null;
        };
        $rows = self::by_key($this->readiness(array('db_config' => $none)));
        $this->assertSame('missing', $rows['lookup_key']['status'], 'not defined in this suite');
        $valid = 'evc-test-readiness-lookup-key-0123456789';
        $short = 'evc-short-key-0123456789abcdef1';
        $this->assertSame(32, strlen($short) + 1);
        foreach (array(array($valid, 'ok'), array(str_repeat('k', 32), 'ok'), array($short, 'wrong'), array('', 'wrong'), array(12345678901234567890, 'wrong'), array(array(), 'wrong'), array(null, 'wrong')) as $case) {
            $rows = self::by_key($this->readiness(array('db_config' => $none, 'constants' => array('EVC_CLUB_LOOKUP_KEY' => $case[0]))));
            $this->assertSame($case[1], $rows['lookup_key']['status'], is_string($case[0]) ? 'length ' . strlen($case[0]) : gettype($case[0]));
            $this->assertSame($case[1], EVC_Readiness::lookup_key_status(true, $case[0]));
            $this->assertSame(EVC_Club_Qr_Services::is_lookup_secret($case[0]), $case[1] === 'ok', 'the lookup backend\'s own rule');
            if (is_string($case[0]) && $case[0] !== '') {
                $html = EVC_Admin_Tools_Plugin::parts()['readiness_page']->html();
                $this->assertStringNotContainsString($case[0], $html, 'the key is never shown');
                if (strlen($case[0]) !== EVC_Lookup_Key_Ring::MIN_SECRET_BYTES) {
                    $this->assertStringNotContainsString(strlen($case[0]) . ' bytes', $html, 'nor its length (only the 32-byte minimum)');
                }
            }
        }
        $this->assertSame('missing', EVC_Readiness::lookup_key_status(false, $valid));
    }

    public function test_the_readiness_checks_hold_no_till_account_service(): void {
        $readiness = $this->readiness(array('db_config' => function () {
            return null;
        }));
        foreach ((new ReflectionObject($readiness))->getProperties() as $property) {
            $property->setAccessible(true);
            $this->assertNotInstanceOf(EVC_Staff_Account_Service::class, $property->getValue($readiness), $property->getName());
        }
        foreach ((new ReflectionMethod(EVC_Readiness::class, '__construct'))->getParameters() as $parameter) {
            $type = $parameter->getType();
            $this->assertNotSame(EVC_Staff_Account_Service::class, $type instanceof ReflectionNamedType ? $type->getName() : '', $parameter->getName());
        }
    }

    public function test_the_flags_report_on_off_or_wrong_with_each_feature_s_own_parser(): void {
        $rows = self::by_key($this->readiness(array('db_config' => function () {
            return null;
        })));
        $this->assertSame('ok', $rows['flag_admin_tools']['status']);
        foreach (array('flag_customer', 'flag_staff', 'flag_redemption', 'flag_onboarding', 'flag_fluentcrm', 'flag_wc_completion', 'flag_migrations') as $key) {
            $this->assertSame('off', $rows[$key]['status'], $key);
        }
    }

    public function test_the_shop_terms_and_crm_without_configuration(): void {
        $rows = self::by_key($this->readiness(array('db_config' => function () {
            return null;
        })));
        $this->assertSame('missing', $rows['commerce_config']['status']);
        $this->assertSame('missing', $rows['bacs']['status'], 'no WooCommerce in this suite');
        $this->assertSame('missing', $rows['terms_url']['status'], 'D13: the technician sets it');
        $this->assertSame('off', $rows['crm_tags']['status']);
        foreach (array('crm_registration', 'crm_funnels', 'crm_woocommerce') as $key) {
            $this->assertSame('manual', $rows[$key]['status'], $key);
            $this->assertNotSame('', $rows[$key]['detail'], 'with instructions');
        }
    }

    public function test_the_server_checks_show_names_never_values(): void {
        update_option('timezone_string', 'UTC');
        update_option('active_plugins', array('wp-rocket/wp-rocket.php', 'some/other.php'));
        $rows = self::by_key($this->readiness(array('db_config' => function () {
            return null;
        }, 'server' => array('REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.77, 10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.77'))));
        $this->assertSame('manual', $rows['remote_addr']['status']);
        $this->assertStringContainsString('X_FORWARDED_FOR', $rows['remote_addr']['detail']);
        $this->assertStringContainsString('CF_CONNECTING_IP', $rows['remote_addr']['detail']);
        $this->assertStringNotContainsString('198.51.100', $rows['remote_addr']['detail']);
        $this->assertStringNotContainsString('203.0.113', $rows['remote_addr']['detail']);
        $this->assertSame('manual', $rows['page_cache']['status']);
        $this->assertStringContainsString('WP Rocket', $rows['page_cache']['detail']);
        $this->assertSame('wrong', $rows['timezone']['status']);
        update_option('timezone_string', 'Europe/Athens');
        update_option('active_plugins', array('some/other.php'));
        $rows = self::by_key($this->readiness(array('db_config' => function () {
            return null;
        }, 'server' => array('REMOTE_ADDR' => '203.0.113.9'))));
        $this->assertSame('manual', $rows['remote_addr']['status'], 'always confirmed by hand');
        $this->assertStringNotContainsString('X_FORWARDED_FOR', $rows['remote_addr']['detail']);
        $this->assertSame('ok', $rows['page_cache']['status']);
        $this->assertSame('ok', $rows['timezone']['status']);
        $this->assertContains($rows['php_version']['status'], array('ok', 'manual'));
        $this->assertStringContainsString(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $rows['php_version']['detail']);
        $this->assertContains($rows['wp_db_server']['status'], array('ok', 'manual'));
    }

    public function test_the_database_server_family_against_the_matrix(): void {
        $this->assertSame('mysql 8.0', EVC_Readiness::db_family('8.0.36'));
        $this->assertSame('mysql 8.4', EVC_Readiness::db_family('8.4.3-0ubuntu'));
        $this->assertSame('mariadb 10.11', EVC_Readiness::db_family('5.5.5-10.11.6-MariaDB-0+deb12u1'));
        $this->assertSame('mariadb 10.11', EVC_Readiness::db_family('10.11.6-MariaDB'));
        $this->assertSame('mariadb 11.4', EVC_Readiness::db_family('11.4.2-MariaDB'));
        $this->assertSame('', EVC_Readiness::db_family('unknown'));
        $this->assertNotContains('mariadb 11.4', EVC_Readiness::SUPPORTED_DB);
    }

    public function test_the_shared_account_status(): void {
        $readiness = $this->readiness(array('db_config' => function () {
            return null;
        }));
        $this->assertSame('missing', self::by_key($readiness)['staff_account']['status']);
        $id = EVC_Admin_Tools_Plugin::parts()['service']->create('till')['user_id'];
        $this->assertSame('ok', self::by_key($readiness)['staff_account']['status']);
        update_user_meta($id, EVC_Staff_Auth::DISABLED_META, '1');
        $this->assertSame('manual', self::by_key($readiness)['staff_account']['status']);
    }

    public function test_the_page_changes_nothing_and_shows_no_secret(): void {
        global $wpdb;
        $club = $this->migrated_club();
        $readiness = $this->readiness(array('db_config' => function () use ($club) {
            return $club->config();
        }, 'server' => array('REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_REAL_IP' => '198.51.100.5')));
        $writes = array();
        $watch = function ($sql) use (&$writes) {
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE)\b/i', $sql) === 1) {
                $writes[] = $sql;
            }
            return $sql;
        };
        $options = $wpdb->get_var("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT(option_name, option_value))), 0)) FROM {$wpdb->options}");
        $club_before = $club->connect()->fetch_all('SELECT version, checksum FROM evc_schema_migrations ORDER BY version');
        add_filter('query', $watch);
        try {
            $page = EVC_Admin_Tools_Plugin::parts()['readiness_page'];
            $html = $page->html();
            ob_start();
            $page->render();
            $rendered = (string) ob_get_clean();
        } finally {
            remove_filter('query', $watch);
        }
        $this->assertSame(array(), $writes, 'no write on the WordPress database');
        $this->assertSame($options, $wpdb->get_var("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT(option_name, option_value))), 0)) FROM {$wpdb->options}"), 'options unchanged');
        $this->assertSame($club_before, $club->connect()->fetch_all('SELECT version, checksum FROM evc_schema_migrations ORDER BY version'), 'Club database unchanged');
        $this->assertSame($html, $rendered);
        $this->assertStringNotContainsString('<form', $html, 'no action on this page');
        foreach (array($club->config()->password(), $club->config()->database(), $club->config()->user() === '' ? 'no-user-xyz' : 'user=' . $club->config()->user(), '198.51.100.5', '203.0.113.9', DB_PASSWORD === '' ? 'no-password-xyz' : DB_PASSWORD) as $secret) {
            if ($secret !== '') {
                $this->assertStringNotContainsString($secret, $html);
            }
        }
        foreach (array('club_db', 'club_db_schema', 'flag_admin_tools', 'commerce_config', 'payment_provenance', 'lookup_key', 'bacs', 'terms_url', 'crm_tags', 'remote_addr', 'page_cache', 'timezone', 'php_version', 'wp_db_server', 'staff_account') as $key) {
            $this->assertStringContainsString('data-check="' . $key . '"', $html, $key);
        }
        wp_set_current_user((int) self::factory()->user->create(array('role' => 'editor')));
        $this->expectException(WPDieException::class);
        $page->render();
    }
}
