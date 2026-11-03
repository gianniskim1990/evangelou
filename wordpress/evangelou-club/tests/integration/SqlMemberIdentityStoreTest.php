<?php

/**
 * STAGE-05B: the SQL member identity store (evc_members, migration 001 unchanged) and the
 * reviewed UI-04F EVC_Member_Sync_Service over it, against a REAL MySQL/MariaDB server:
 * one row per WordPress user by the database's unique keys (never check-then-insert),
 * duplicate / interleaved / truly concurrent creation, lost COMMIT, fail-closed reads.
 */
final class SqlMemberIdentityStoreTest extends EVC_Db_Test_Case {
    const NOW = '2026-11-03 10:00:00.000000';

    private function store(?EVC_Club_Db $db = null): EVC_Sql_Member_Identity_Store {
        return new EVC_Sql_Member_Identity_Store($db ?? $this->db);
    }

    private function rows(int $wp_user_id): array {
        return $this->db->fetch_all('SELECT id, member_public_id, wp_user_id, status, created_at_utc, updated_at_utc FROM evc_members WHERE wp_user_id = ?', array($wp_user_id));
    }

    private function sync_service(EVC_Member_Identity_Store $store, ?EVC_Fake_Wp_Account_Directory $accounts = null): EVC_Member_Sync_Service {
        if ($accounts === null) {
            $accounts = new EVC_Fake_Wp_Account_Directory();
            $accounts->kinds = array(7001 => EVC_Wp_Account_Directory::CUSTOMER, 7002 => EVC_Wp_Account_Directory::CUSTOMER);
        }
        $reported = &$this->reported;
        return new EVC_Member_Sync_Service($store, $accounts, $this->adapter, $this->clock, null, function (array $d) use (&$reported) {
            $reported[] = $d;
        });
    }

    private static function signal(int $wp_user_id): EVC_Member_Sync_Signal {
        return EVC_Member_Sync_Signal::of('woocommerce', 'payment_changed', $wp_user_id);
    }

    // ---- the store ------------------------------------------------------------------------------

    public function test_create_stores_exactly_the_001_columns_and_reads_back_both_ways(): void {
        $id = EVC_Member_Id::mint();
        $row_id = $this->store()->create($id, 7001, self::NOW);
        $this->assertGreaterThan(0, $row_id);
        $expected = array('id' => $row_id, 'member_public_id' => $id, 'wp_user_id' => 7001, 'status' => 'enabled', 'status_version' => 1, 'status_reason' => null);
        $this->assertSame($expected, $this->store()->find_by_wp_user(7001));
        $this->assertSame($expected, $this->store()->find_by_public_id($id));
        $rows = $this->rows(7001);
        $this->assertCount(1, $rows);
        $this->assertSame(self::NOW, $rows[0]['created_at_utc']);
        $this->assertSame(self::NOW, $rows[0]['updated_at_utc']);
        $this->assertNull($this->store()->find_by_wp_user(7002));
        $this->assertNull($this->store()->find_by_public_id(EVC_Member_Id::mint()));
        $this->assertNull($this->store()->find_by_public_id('not-an-id'), 'a malformed id never reaches SQL');
        $this->assertNull($this->store()->find_by_wp_user(0));
    }

    public function test_a_second_identity_for_the_same_wordpress_user_is_refused_by_the_unique_key(): void {
        $first = EVC_Member_Id::mint();
        $this->store()->create($first, 7001, self::NOW);
        try {
            $this->store()->create(EVC_Member_Id::mint(), 7001, self::NOW);
            $this->fail('a second identity for the same WordPress user must be refused');
        } catch (EVC_Db_Duplicate_Key_Exception $e) {
            $this->assertSame('uq_members_wp_user', $e->key_name());
        }
        $this->assertCount(1, $this->rows(7001));
        $this->assertSame($first, $this->store()->find_by_wp_user(7001)['member_public_id']);
        $this->assertFalse($this->db->in_transaction(), 'rolled back, connection reusable');
    }

    public function test_a_public_id_collision_is_refused_by_the_unique_key(): void {
        $id = EVC_Member_Id::mint();
        $this->store()->create($id, 7001, self::NOW);
        try {
            $this->store()->create($id, 7002, self::NOW);
            $this->fail('a duplicate public id must be refused');
        } catch (EVC_Db_Duplicate_Key_Exception $e) {
            $this->assertSame('uq_members_public_id', $e->key_name());
        }
        $this->assertSame(array(), $this->rows(7002));
    }

    public function test_invalid_input_is_refused_before_sql(): void {
        foreach (array(array('mem_XYZ', 7001, self::NOW), array(EVC_Member_Id::mint(), 0, self::NOW), array(EVC_Member_Id::mint(), 7001, '2026-11-03 10:00:00')) as $args) {
            try {
                $this->store()->create($args[0], $args[1], $args[2]);
                $this->fail('invalid input accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid member identity.', $e->getMessage());
            }
        }
        $this->assertSame('0', (string) $this->db->fetch_one('SELECT COUNT(*) AS n FROM evc_members')['n']);
    }

    public function test_a_malformed_stored_row_fails_closed(): void {
        $this->db->execute("INSERT INTO evc_members (member_public_id, wp_user_id, status, created_at_utc, updated_at_utc) VALUES ('mem_NOT_HEX', 7001, 'enabled', ?, ?)", array(self::NOW, self::NOW));
        $this->expectException(UnexpectedValueException::class);
        $this->store()->find_by_wp_user(7001);
    }

    public function test_status_changes_are_not_available_on_schema_001(): void {
        $id = EVC_Member_Id::mint();
        $row = $this->store()->create($id, 7001, self::NOW);
        try {
            $this->store()->set_status($row, 1, 'disabled', 'member_request', 9, self::NOW);
            $this->fail('set_status must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('migration 001', $e->getMessage());
        }
        $this->assertSame('enabled', $this->store()->find_by_public_id($id)['status']);
    }

    public function test_a_lost_commit_before_the_server_committed_leaves_no_row_and_reports_unknown(): void {
        $pdo = $this->database->faulty_pdo();
        $pdo->fail_commit_before = true;
        try {
            $this->store(new EVC_Club_Db($pdo))->create(EVC_Member_Id::mint(), 7001, self::NOW);
            $this->fail('expected commit outcome unknown');
        } catch (EVC_Db_Commit_Unknown_Exception $e) {
            $this->assertSame(2013, $e->driver_code());
        }
        $this->assertSame(array(), $this->rows(7001));
    }

    // ---- EVC_Member_Sync_Service over the SQL store -------------------------------------------

    public function test_sync_creates_one_identity_for_a_paid_customer_and_then_only_reads_it(): void {
        $this->adapter->set(7001, EVC_Mock_Membership_Adapter::active_until('2026-12-03T10:00:00Z'));
        $first = $this->sync_service($this->store())->sync(self::signal(7001));
        $this->assertSame(EVC_Member_Sync_Result::PROVISIONED, $first->kind());
        $this->assertTrue(EVC_Member_Id::is_valid_format($first->member_public_id()));
        $this->assertFalse($first->grants_entitlement());
        foreach (array(self::signal(7001), EVC_Member_Sync_Signal::of('scheduled_reconcile', 'periodic_check', 7001)) as $again) {
            $r = $this->sync_service($this->store())->sync($again);
            $this->assertSame(EVC_Member_Sync_Result::ALREADY_MEMBER, $r->kind());
            $this->assertSame($first->member_public_id(), $r->member_public_id());
        }
        $rows = $this->rows(7001);
        $this->assertCount(1, $rows);
        $this->assertSame('enabled', $rows[0]['status']);
        $this->assertSame('2026-10-09 10:00:00.000000', $rows[0]['created_at_utc'], 'the injected clock');
    }

    public function test_sync_never_creates_a_row_without_a_verified_eligible_membership(): void {
        $this->adapter->set(7001, EVC_Mock_Membership_Adapter::with('pending_payment', false, '2026-12-03T10:00:00Z'));
        $this->adapter->set(7002, EVC_Mock_Membership_Adapter::with('active', false, '2026-12-03T10:00:00Z'));
        foreach (array(7001, 7002) as $u) {
            $this->assertSame(EVC_Member_Sync_Result::NOT_ELIGIBLE, $this->sync_service($this->store())->sync(self::signal($u))->kind());
        }
        $accounts = new EVC_Fake_Wp_Account_Directory();
        $accounts->kinds = array(7003 => EVC_Wp_Account_Directory::RESTRICTED);
        $this->adapter->set(7003, EVC_Mock_Membership_Adapter::active_until('2026-12-03T10:00:00Z'));
        $this->assertSame(EVC_Member_Sync_Result::REFUSED_ACCOUNT, $this->sync_service($this->store(), $accounts)->sync(self::signal(7003))->kind());
        $this->assertSame('0', (string) $this->db->fetch_one('SELECT COUNT(*) AS n FROM evc_members')['n']);
    }

    public function test_an_interleaved_creation_by_another_connection_ends_with_one_row(): void {
        $this->adapter->set(7001, EVC_Mock_Membership_Adapter::active_until('2026-12-03T10:00:00Z'));
        $other = $this->database->connect();
        $winner = EVC_Member_Id::mint();
        $racing = new EVC_Test_Racing_Member_Store($this->store(), function () use ($other, $winner) {
            (new EVC_Sql_Member_Identity_Store($other))->create($winner, 7001, self::NOW);
        });
        $r = $this->sync_service($racing)->sync(self::signal(7001));
        $this->assertSame(EVC_Member_Sync_Result::ALREADY_MEMBER, $r->kind(), 'the duplicate key decides; the loser re-reads the winner');
        $this->assertSame($winner, $r->member_public_id());
        $this->assertCount(1, $this->rows(7001));
    }

    public function test_a_lost_commit_is_recovered_by_the_next_sync_without_a_second_row(): void {
        $this->adapter->set(7001, EVC_Mock_Membership_Adapter::active_until('2026-12-03T10:00:00Z'));
        foreach (array('fail_commit_after' => 1, 'fail_commit_before' => 0) as $fault => $rows_after) {
            $user = 7001 + (1 - $rows_after);
            $this->adapter->set($user, EVC_Mock_Membership_Adapter::active_until('2026-12-03T10:00:00Z'));
            $pdo = $this->database->faulty_pdo();
            $pdo->$fault = true;
            $r = $this->sync_service($this->store(new EVC_Club_Db($pdo)))->sync(self::signal($user));
            $this->assertSame(EVC_Member_Sync_Result::OUTCOME_UNKNOWN, $r->kind(), $fault);
            $this->assertCount($rows_after, $this->rows($user), $fault);
            $retry = $this->sync_service($this->store())->sync(self::signal($user));
            $this->assertSame($rows_after === 1 ? EVC_Member_Sync_Result::ALREADY_MEMBER : EVC_Member_Sync_Result::PROVISIONED, $retry->kind(), $fault);
            $this->assertCount(1, $this->rows($user), $fault);
        }
    }

    /**
     * Real separate processes and connections, released by a shared time barrier, all syncing the
     * SAME paid customer: exactly one row, exactly one "provisioned", every winner reports that id.
     *
     * @group concurrency
     */
    public function test_simultaneous_syncs_in_separate_processes_create_exactly_one_row(): void {
        $rounds = max(1, (int) (getenv('EVC_CONCURRENCY_ROUNDS') ?: 10));
        $workers = max(2, (int) (getenv('EVC_CONCURRENCY_WORKERS') ?: 6));
        for ($round = 0; $round < $rounds; $round++) {
            $wp_user_id = 8000 + $round;
            $start_at = microtime(true) + 1.0;
            $running = array();
            for ($w = 0; $w < $workers; $w++) {
                $job = array('database' => $this->database->name(), 'wp_user_id' => $wp_user_id, 'start_at' => $start_at);
                $proc = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/bin/member-sync-worker.php', json_encode($job)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
                $this->assertIsResource($proc);
                $running[] = array($proc, $pipes);
            }
            $kinds = array();
            $ids = array();
            foreach ($running as $entry) {
                list($proc, $pipes) = $entry;
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($proc), 'worker stderr: ' . $err);
                $decoded = json_decode(trim((string) $out), true);
                $this->assertIsArray($decoded, 'worker output: ' . $out . ' / ' . $err);
                $kinds[] = $decoded['kind'];
                $ids[] = $decoded['member_public_id'];
            }
            $rows = $this->rows($wp_user_id);
            $this->assertCount(1, $rows, "round $round");
            $this->assertSame(1, count(array_keys($kinds, EVC_Member_Sync_Result::PROVISIONED, true)), "round $round: " . implode(',', $kinds));
            $this->assertSame($workers - 1, count(array_keys($kinds, EVC_Member_Sync_Result::ALREADY_MEMBER, true)), "round $round: " . implode(',', $kinds));
            $this->assertSame(array($rows[0]['member_public_id']), array_values(array_unique($ids)), "round $round");
        }
    }
}

/** TEST-ONLY: delegates to a real store and runs $before once, right before the first create(). */
final class EVC_Test_Racing_Member_Store implements EVC_Member_Identity_Store {
    /** @var EVC_Member_Identity_Store */
    private $inner;
    /** @var callable|null */
    private $before;

    public function __construct(EVC_Member_Identity_Store $inner, callable $before) {
        $this->inner = $inner;
        $this->before = $before;
    }

    public function find_by_wp_user(int $wp_user_id): ?array {
        return $this->inner->find_by_wp_user($wp_user_id);
    }

    public function find_by_public_id(string $member_public_id): ?array {
        return $this->inner->find_by_public_id($member_public_id);
    }

    public function create(string $member_public_id, int $wp_user_id, string $now_utc): int {
        if ($this->before !== null) {
            $before = $this->before;
            $this->before = null;
            $before();
        }
        return $this->inner->create($member_public_id, $wp_user_id, $now_utc);
    }

    public function set_status(int $member_id, int $expected_version, string $status, string $reason, int $actor_wp_user_id, string $now_utc): void {
        $this->inner->set_status($member_id, $expected_version, $status, $reason, $actor_wp_user_id, $now_utc);
    }
}
