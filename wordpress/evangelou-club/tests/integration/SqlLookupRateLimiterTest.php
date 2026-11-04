<?php

/**
 * STAGE-05C, owner decision D9: about 10 FAILED QR scans per 5 minutes per tablet, counted in
 * evc_lookup_failures (migration 003) on a REAL MySQL/MariaDB server, alone and under the reviewed
 * UI-04C EVC_Member_Lookup_Service with the SQL token store.
 *
 * @group qr-store
 */
final class SqlLookupRateLimiterTest extends EVC_Db_Test_Case {
    const STAFF = 7;
    const TABLET_1 = '11111111111111111111111111111111';
    const TABLET_2 = '22222222222222222222222222222222';

    /** @var string[] */
    private $users = array();

    protected function setUp(): void {
        parent::setUp();
        $this->clock = new EVC_Fixed_Clock('2026-11-04T10:00:10Z');
    }

    protected function tearDown(): void {
        foreach ($this->users as $user) {
            EVC_Test_Database::admin_pdo()->exec("DROP USER IF EXISTS '" . $user . "'@'%'");
        }
        $this->users = array();
        parent::tearDown();
    }

    private function limiter(?EVC_Club_Db $db = null): EVC_Sql_Lookup_Rate_Limiter {
        return new EVC_Sql_Lookup_Rate_Limiter($db ?? $this->db, $this->clock);
    }

    private static function keys(string $tablet = self::TABLET_1): array {
        return array('actor:' . self::STAFF, 'session:' . $tablet);
    }

    private function lookup(): EVC_Member_Lookup_Service {
        $authorizer = new EVC_Fake_Club_Staff_Authorizer();
        $authorizer->grants[self::STAFF] = array(EVC_Member_Lookup_Service::CAPABILITY);
        return new EVC_Member_Lookup_Service(
            new EVC_Sql_Qr_Token_Store($this->db),
            new EVC_Fake_Member_Phone_Directory(),
            $authorizer,
            $this->limiter(),
            new EVC_Lookup_Key_Ring(array('k1' => str_repeat("\x03", 32)), 'k1'),
            new EVC_Lookup_Context_Policy(300),
            EVC_Qr_Token_Policy::without_expiry(),
            $this->clock,
            'member_lookup'
        );
    }

    public function test_migration_003_creates_exactly_the_counter_table(): void {
        $this->assertSame(array(1, 2, 3), array_map('intval', array_column($this->db->fetch_all('SELECT version FROM evc_schema_migrations ORDER BY version'), 'version')));
        $columns = array_column($this->db->fetch_all("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evc_lookup_failures' ORDER BY ORDINAL_POSITION"), 'c');
        $this->assertSame(array('limiter_key', 'window_start_utc', 'failures', 'updated_at_utc'), $columns);
        $this->assertSame(0, $this->count_rows('evc_lookup_failures'));
    }

    public function test_ten_failures_in_the_window_block_only_that_tablet(): void {
        $l = $this->limiter();
        for ($i = 1; $i <= 9; $i++) {
            $this->assertTrue($l->allow(self::keys(), 'qr_lookup'), "before failure $i");
            $l->record_failure(self::keys(), 'qr_lookup');
        }
        $this->assertTrue($l->allow(self::keys(), 'qr_lookup'), '9 failures: still allowed');
        $l->record_failure(self::keys(), 'qr_lookup');
        $this->assertFalse($l->allow(self::keys(), 'qr_lookup'), '10 failures: blocked');
        $this->assertTrue($l->allow(self::keys(self::TABLET_2), 'qr_lookup'), 'another tablet of the shared account is not blocked');
        $this->assertTrue($l->allow(self::keys(), 'phone_lookup'), 'per action');
        $this->assertSame(1, $this->count_rows('evc_lookup_failures'), 'only the session key is stored, once');
        $this->assertSame('10', (string) $this->db->fetch_one('SELECT failures FROM evc_lookup_failures')['failures']);
    }

    public function test_the_block_ends_with_the_five_minute_window(): void {
        $l = $this->limiter();
        for ($i = 0; $i < 10; $i++) {
            $l->record_failure(self::keys(), 'qr_lookup');
        }
        $this->clock->set('2026-11-04T10:04:59Z');
        $this->assertFalse($l->allow(self::keys(), 'qr_lookup'), 'same window (10:00:00-10:05:00)');
        $this->clock->set('2026-11-04T10:05:00Z');
        $this->assertTrue($l->allow(self::keys(), 'qr_lookup'), 'next window');
    }

    public function test_without_a_session_key_it_fails_closed_and_stores_nothing_personal(): void {
        $l = $this->limiter();
        $this->assertFalse($l->allow(array('actor:' . self::STAFF), 'qr_lookup'));
        $this->assertFalse($l->allow(array('session:'), 'qr_lookup'));
        $l->record_failure(array('actor:' . self::STAFF), 'qr_lookup');
        $this->assertSame(0, $this->count_rows('evc_lookup_failures'));
        $l->record_failure(self::keys(), 'qr_lookup');
        $dump = json_encode($this->db->fetch_all('SELECT * FROM evc_lookup_failures'));
        $this->assertStringNotContainsString(self::TABLET_1, $dump);
        $this->assertStringNotContainsString('session', $dump);
        $this->assertSame(hash('sha256', 'evc-lookup-limit-v1|qr_lookup|session:' . self::TABLET_1), $this->db->fetch_one('SELECT limiter_key FROM evc_lookup_failures')['limiter_key']);
    }

    public function test_rows_older_than_a_day_are_pruned(): void {
        $this->db->execute("INSERT INTO evc_lookup_failures (limiter_key, window_start_utc, failures, updated_at_utc) VALUES (?, '2026-11-02 09:00:00.000000', 3, '2026-11-02 09:00:00.000000')", array(str_repeat('a', 64)));
        $this->db->execute("INSERT INTO evc_lookup_failures (limiter_key, window_start_utc, failures, updated_at_utc) VALUES (?, '2026-11-03 11:00:00.000000', 3, '2026-11-03 11:00:00.000000')", array(str_repeat('b', 64)));
        $this->limiter()->record_failure(self::keys(), 'qr_lookup');
        $keys = array_column($this->db->fetch_all('SELECT limiter_key FROM evc_lookup_failures ORDER BY limiter_key'), 'limiter_key');
        $this->assertNotContains(str_repeat('a', 64), $keys, 'older than one day: pruned');
        $this->assertContains(str_repeat('b', 64), $keys, 'within one day: kept');
    }

    public function test_a_read_failure_in_allow_propagates(): void {
        $pdo = $this->database->faulty_pdo();
        $pdo->fail_prepare_matching = '/evc_lookup_failures/';
        $this->expectException(EVC_Db_Exception::class);
        $this->limiter(new EVC_Club_Db($pdo))->allow(self::keys(), 'qr_lookup');
    }

    /**
     * STAGE-05C review (MAJOR 1): only the INSERT of a failure fails (missing grant, lock timeout,
     * broken table) while allow() still reads fine. Every failed scan must answer `unavailable`, never
     * `not_found`, so a tablet can never scan without limit while failures are not being counted.
     */
    public function test_a_failure_that_cannot_be_written_makes_every_failed_scan_unavailable(): void {
        $public = $this->create_member(7001);
        $id = (int) $this->db->fetch_one('SELECT id FROM evc_members WHERE member_public_id = ?', array($public))['id'];
        $token = EVC_Qr_Token::mint();
        (new EVC_Sql_Qr_Token_Store($this->db))->rotate($id, null, EVC_Qr_Token::digest($token), '2026-11-04 09:00:00.000000');
        $pdo = $this->database->faulty_pdo();
        $pdo->fail_prepare_matching = '/^INSERT INTO evc_lookup_failures/';
        $db = new EVC_Club_Db($pdo);
        $authorizer = new EVC_Fake_Club_Staff_Authorizer();
        $authorizer->grants[self::STAFF] = array(EVC_Member_Lookup_Service::CAPABILITY);
        $service = new EVC_Member_Lookup_Service(
            new EVC_Sql_Qr_Token_Store($db),
            new EVC_Fake_Member_Phone_Directory(),
            $authorizer,
            new EVC_Sql_Lookup_Rate_Limiter($db, $this->clock),
            new EVC_Lookup_Key_Ring(array('k1' => str_repeat("\x03", 32)), 'k1'),
            new EVC_Lookup_Context_Policy(300),
            EVC_Qr_Token_Policy::without_expiry(),
            $this->clock,
            'member_lookup'
        );
        $tablet = new EVC_Staff_Actor(self::STAFF, self::TABLET_1);
        for ($i = 0; $i < 30; $i++) {
            $this->assertSame(EVC_Member_Lookup_Result::UNAVAILABLE, $service->by_qr($tablet, $i % 2 === 0 ? EVC_Qr_Token::mint() : 'not a qr')->kind(), "failed scan $i");
        }
        $this->assertSame(0, $this->count_rows('evc_lookup_failures'));
        $this->assertSame(EVC_Member_Lookup_Result::FOUND, $service->by_qr($tablet, $token)->kind(), 'a valid QR still works (nothing to count)');
    }

    public function test_the_least_privilege_grants_are_enough(): void {
        $user = 'evc_rl_' . bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(16));
        $this->users[] = $user;
        $admin = EVC_Test_Database::admin_pdo();
        $admin->exec("CREATE USER '" . $user . "'@'%' IDENTIFIED BY '" . $password . "'");
        foreach (EVC_Sql_Lookup_Rate_Limiter::RUNTIME_GRANTS as $table => $privileges) {
            $admin->exec('GRANT ' . $privileges . ' ON `' . $this->database->name() . '`.`' . $table . "` TO '" . $user . "'@'%'");
        }
        $runtime = EVC_Club_Db::connect(new EVC_Db_Config(EVC_Test_Database::host(), EVC_Test_Database::port(), $this->database->name(), $user, $password));
        $l = $this->limiter($runtime);
        $l->record_failure(self::keys(), 'qr_lookup');
        $l->record_failure(self::keys(), 'qr_lookup');
        $this->assertTrue($l->allow(self::keys(), 'qr_lookup'));
        try {
            $runtime->fetch_all('SELECT COUNT(*) AS n FROM evc_qr_tokens');
            $this->fail('the limiter grants reach another table');
        } catch (EVC_Db_Exception $e) {
            $this->assertContains($e->driver_code(), array(1142, 1143));
        }
    }

    public function test_the_lookup_service_blocks_a_tablet_after_ten_failed_scans_even_for_a_valid_qr(): void {
        $public = $this->create_member(7001);
        $id = (int) $this->db->fetch_one('SELECT id FROM evc_members WHERE member_public_id = ?', array($public))['id'];
        $token = EVC_Qr_Token::mint();
        (new EVC_Sql_Qr_Token_Store($this->db))->rotate($id, null, EVC_Qr_Token::digest($token), '2026-11-04 09:00:00.000000');
        $tablet = new EVC_Staff_Actor(self::STAFF, self::TABLET_1);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(EVC_Member_Lookup_Result::FOUND, $this->lookup()->by_qr($tablet, $token)->kind(), 'successes are not counted');
        }
        $bad = array('not a qr', EVC_Qr_Token::mint());
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(EVC_Member_Lookup_Result::NOT_FOUND, $this->lookup()->by_qr($tablet, $bad[$i % 2])->kind());
        }
        $this->assertSame(EVC_Member_Lookup_Result::RATE_LIMITED, $this->lookup()->by_qr($tablet, $token)->kind(), 'blocked before any read');
        $this->assertSame(EVC_Member_Lookup_Result::FOUND, $this->lookup()->by_qr(new EVC_Staff_Actor(self::STAFF, self::TABLET_2), $token)->kind(), 'other tablet');
        $this->clock->set('2026-11-04T10:05:00Z');
        $this->assertSame(EVC_Member_Lookup_Result::FOUND, $this->lookup()->by_qr($tablet, $token)->kind(), 'after the window');
    }

    /** @group concurrency */
    public function test_simultaneous_failures_in_separate_processes_are_all_counted(): void {
        $workers = max(2, (int) (getenv('EVC_CONCURRENCY_WORKERS') ?: 6));
        $start_at = microtime(true) + 1.0;
        $running = array();
        for ($w = 0; $w < $workers; $w++) {
            $job = array('database' => $this->database->name(), 'op' => 'fail', 'session' => self::TABLET_1, 'start_at' => $start_at);
            $proc = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/bin/qr-token-worker.php', json_encode($job)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            $this->assertIsResource($proc);
            $running[] = array($proc, $pipes);
        }
        foreach ($running as $entry) {
            list($proc, $pipes) = $entry;
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), 'worker stderr: ' . $err);
            $this->assertSame('failed', json_decode(trim((string) $out), true)['outcome'] ?? null, $out);
        }
        $this->assertSame(1, $this->count_rows('evc_lookup_failures'));
        $this->assertSame((string) $workers, (string) $this->db->fetch_one('SELECT failures FROM evc_lookup_failures')['failures'], 'no lost increment');
    }
}
