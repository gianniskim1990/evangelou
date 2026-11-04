<?php

/**
 * STAGE-05C: the SQL QR token store (evc_qr_tokens, migration 001 unchanged) and the reviewed
 * UI-04C EVC_Qr_Token_Service / EVC_Member_Lookup_Service over it, against a REAL MySQL/MariaDB
 * server: digests only, compare-and-set rotation / revocation in one transaction, the unique keys
 * as the backstop, interleaved and truly concurrent writers, lost COMMIT, fail-closed reads, and
 * a QR that can never turn a frozen or expired membership into a coffee.
 *
 * Group qr-store: excluded from the engine mutation runner (run-mutations.php), whose mutants never
 * touch this store; its own mutants QS1-QS10 run it in run-provenance-sql-mutations.php.
 *
 * @group qr-store
 */
final class SqlQrTokenStoreTest extends EVC_Db_Test_Case {
    const NOW = '2026-11-03 10:00:00.000000';
    const LATER = '2026-11-03 10:05:00.000000';
    const STAFF = 7;
    const TABLET = '11111111111111111111111111111111';

    /** @var array[] */
    private $service_reports = array();
    /** @var string[] users to drop */
    private $users = array();

    protected function tearDown(): void {
        foreach ($this->users as $user) {
            EVC_Test_Database::admin_pdo()->exec("DROP USER IF EXISTS '" . $user . "'@'%'");
        }
        $this->users = array();
        parent::tearDown();
    }

    private function store(?EVC_Club_Db $db = null): EVC_Sql_Qr_Token_Store {
        return new EVC_Sql_Qr_Token_Store($db ?? $this->db);
    }

    private function member_id(string $public_id): int {
        return (int) $this->db->fetch_one('SELECT id FROM evc_members WHERE member_public_id = ?', array($public_id))['id'];
    }

    private function token_rows(?int $member_id = null): array {
        return $member_id === null
            ? $this->db->fetch_all('SELECT * FROM evc_qr_tokens ORDER BY id')
            : $this->db->fetch_all('SELECT * FROM evc_qr_tokens WHERE member_id = ? ORDER BY id', array($member_id));
    }

    private function active_count(int $member_id): int {
        return $this->count_rows('evc_qr_tokens', "member_id = ? AND status = 'active'", array($member_id));
    }

    private static function hash(): string {
        return EVC_Qr_Token::digest(EVC_Qr_Token::mint());
    }

    private function tokens_service(?EVC_Qr_Token_Store $store = null): EVC_Qr_Token_Service {
        $authorizer = new EVC_Fake_Club_Staff_Authorizer();
        $reports = &$this->service_reports;
        return new EVC_Qr_Token_Service($store ?? $this->store(), $authorizer, $this->clock, null, function (array $d) use (&$reports) {
            $reports[] = $d;
        });
    }

    private function lookup_service(?EVC_Qr_Token_Policy $policy = null): EVC_Member_Lookup_Service {
        $authorizer = new EVC_Fake_Club_Staff_Authorizer();
        $authorizer->grants[self::STAFF] = array(EVC_Member_Lookup_Service::CAPABILITY);
        $reports = &$this->service_reports;
        return new EVC_Member_Lookup_Service(
            $this->store(),
            new EVC_Fake_Member_Phone_Directory(),
            $authorizer,
            new EVC_Fake_Lookup_Rate_Limiter(),
            new EVC_Lookup_Key_Ring(array('k1' => str_repeat("\x02", 32)), 'k1'),
            new EVC_Lookup_Context_Policy(300),
            $policy ?? EVC_Qr_Token_Policy::without_expiry(),
            $this->clock,
            'member_view',
            null,
            function (array $d) use (&$reports) {
                $reports[] = $d;
            }
        );
    }

    private static function tablet(): EVC_Staff_Actor {
        return new EVC_Staff_Actor(self::STAFF, self::TABLET);
    }

    // ---- reads ------------------------------------------------------------------------------

    public function test_members_read_back_by_public_id_and_by_row_id_only_when_well_formed(): void {
        $public = $this->create_member(7001);
        $id = $this->member_id($public);
        $expected = array('id' => $id, 'member_public_id' => $public, 'wp_user_id' => 7001, 'status' => 'enabled');
        $this->assertSame($expected, $this->store()->find_member_by_public_id($public));
        $this->assertSame($expected, $this->store()->find_member_by_id($id));
        $this->assertNull($this->store()->find_member_by_public_id(EVC_Member_Id::mint()));
        $this->assertNull($this->store()->find_member_by_public_id('mem_1'), 'malformed id never reaches SQL');
        $this->assertNull($this->store()->find_member_by_id($id + 1000));
        $this->assertNull($this->store()->find_member_by_id(0));
        $this->db->execute("INSERT INTO evc_members (member_public_id, wp_user_id, status, created_at_utc, updated_at_utc) VALUES ('mem_NOT_HEX', 7002, 'enabled', ?, ?)", array(self::NOW, self::NOW));
        $bad = (int) $this->db->fetch_one("SELECT id FROM evc_members WHERE wp_user_id = 7002")['id'];
        $this->expectException(UnexpectedValueException::class);
        $this->store()->find_member_by_id($bad);
    }

    public function test_a_token_lookup_answers_status_and_times_never_the_digest(): void {
        $id = $this->member_id($this->create_member(7001));
        $hash = self::hash();
        $this->store()->rotate($id, null, $hash, self::NOW);
        $found = $this->store()->find_token_by_hash($hash);
        $this->assertSame(array('member_id' => $id, 'status' => 'active', 'issued_at_utc' => self::NOW, 'revoked_at_utc' => null), $found);
        $this->assertNull($this->store()->find_token_by_hash(self::hash()));
        $this->assertNull($this->store()->find_token_by_hash('evc_' . str_repeat('a', 48)), 'a plaintext token is never looked up as a digest');
        $this->assertNull($this->store()->find_token_by_hash(strtoupper($hash)));
        $this->assertSame($hash, $this->store()->active_token_hash($id));
        $this->assertNull($this->store()->active_token_hash($id + 1));
        $this->assertNull($this->store()->active_token_hash(0));
    }

    public function test_malformed_stored_token_rows_fail_closed(): void {
        $id = $this->member_id($this->create_member(7001));
        $cases = array(
            'revoked without a revocation time' => array('revoked', null),
            'active with a revocation time' => array('active', self::NOW),
        );
        foreach ($cases as $label => $case) {
            $hash = self::hash();
            $this->db->execute('INSERT INTO evc_qr_tokens (member_id, token_hash, status, issued_at_utc, revoked_at_utc) VALUES (?, ?, ?, ?, ?)', array($id, $hash, $case[0], self::NOW, $case[1]));
            try {
                $this->store()->find_token_by_hash($hash);
                $this->fail($label . ' accepted');
            } catch (UnexpectedValueException $e) {
                $this->assertSame('Stored Club QR token row is malformed.', $e->getMessage(), $label);
            }
            $this->db->execute("UPDATE evc_qr_tokens SET status = 'revoked', revoked_at_utc = ? WHERE token_hash = ?", array(self::NOW, $hash));
        }
    }

    // ---- writes -----------------------------------------------------------------------------

    public function test_first_issuance_then_rotation_keep_exactly_one_active_digest_and_never_plaintext(): void {
        $id = $this->member_id($this->create_member(7001));
        $first_token = EVC_Qr_Token::mint();
        $first = EVC_Qr_Token::digest($first_token);
        $this->store()->rotate($id, null, $first, self::NOW);
        $second_token = EVC_Qr_Token::mint();
        $second = EVC_Qr_Token::digest($second_token);
        $this->store()->rotate($id, $first, $second, self::LATER);
        $rows = $this->token_rows($id);
        $this->assertCount(2, $rows);
        $this->assertSame(array($first, 'revoked', self::NOW, self::LATER), array($rows[0]['token_hash'], $rows[0]['status'], $rows[0]['issued_at_utc'], $rows[0]['revoked_at_utc']));
        $this->assertSame(array($second, 'active', self::LATER, null), array($rows[1]['token_hash'], $rows[1]['status'], $rows[1]['issued_at_utc'], $rows[1]['revoked_at_utc']));
        $this->assertSame(1, $this->active_count($id));
        $this->assertSame($second, $this->store()->active_token_hash($id));
        $dump = json_encode($this->token_rows());
        $this->assertStringNotContainsString($first_token, $dump);
        $this->assertStringNotContainsString($second_token, $dump);
        $this->assertStringNotContainsString('evc_', $dump, 'only digests are stored');
    }

    public function test_a_stale_or_missing_expectation_changes_nothing(): void {
        $id = $this->member_id($this->create_member(7001));
        $first = self::hash();
        $this->store()->rotate($id, null, $first, self::NOW);
        $before = $this->token_rows();
        $cases = array(
            'stale expectation' => function () use ($id) {
                $this->store()->rotate($id, self::hash(), self::hash(), self::LATER);
            },
            'expects none while one is active' => function () use ($id) {
                $this->store()->rotate($id, null, self::hash(), self::LATER);
            },
            'revoke a stale digest' => function () use ($id) {
                $this->store()->revoke($id, self::hash(), self::LATER);
            },
        );
        foreach ($cases as $label => $run) {
            try {
                $run();
                $this->fail($label . ' accepted');
            } catch (EVC_Qr_Rotation_Conflict $e) {
                $this->assertSame($before, $this->token_rows(), $label);
                $this->assertFalse($this->db->in_transaction(), $label . ': rolled back, connection reusable');
            }
        }
        $other = $this->member_id($this->create_member(7002));
        foreach (array('rotate' => function () use ($other) {
            $this->store()->rotate($other, self::hash(), self::hash(), self::LATER);
        }, 'revoke' => function () use ($other) {
            $this->store()->revoke($other, self::hash(), self::LATER);
        }) as $label => $run) {
            try {
                $run();
                $this->fail($label . ' without an active token accepted');
            } catch (EVC_Qr_Rotation_Conflict $e) {
                $this->assertSame(array(), $this->token_rows($other), $label);
            }
        }
    }

    public function test_revocation_is_compare_and_set_and_leaves_no_active_token(): void {
        $id = $this->member_id($this->create_member(7001));
        $hash = self::hash();
        $this->store()->rotate($id, null, $hash, self::NOW);
        $this->store()->revoke($id, $hash, self::LATER);
        $this->assertSame(0, $this->active_count($id));
        $this->assertNull($this->store()->active_token_hash($id));
        $this->assertSame(array('member_id' => $id, 'status' => 'revoked', 'issued_at_utc' => self::NOW, 'revoked_at_utc' => self::LATER), $this->store()->find_token_by_hash($hash));
        $this->expectException(EVC_Qr_Rotation_Conflict::class);
        $this->store()->revoke($id, $hash, self::LATER);
    }

    public function test_a_digest_collision_rolls_back_the_revocation_too(): void {
        $a = $this->member_id($this->create_member(7001));
        $b = $this->member_id($this->create_member(7002));
        $taken = self::hash();
        $this->store()->rotate($a, null, $taken, self::NOW);
        $b_first = self::hash();
        $this->store()->rotate($b, null, $b_first, self::NOW);
        try {
            $this->store()->rotate($b, $b_first, $taken, self::LATER);
            $this->fail('a duplicate digest must be refused');
        } catch (EVC_Db_Duplicate_Key_Exception $e) {
            $this->assertSame('uq_qr_tokens_hash', $e->key_name());
        }
        $this->assertSame($b_first, $this->store()->active_token_hash($b), 'the old token is still active: one transaction');
        $this->assertSame('active', $this->store()->find_token_by_hash($b_first)['status']);
        $this->assertCount(1, $this->token_rows($b));
        $this->assertFalse($this->db->in_transaction());
    }

    public function test_the_database_alone_refuses_a_second_active_token(): void {
        $id = $this->member_id($this->create_member(7001));
        $this->store()->rotate($id, null, self::hash(), self::NOW);
        try {
            $this->db->execute("INSERT INTO evc_qr_tokens (member_id, token_hash, status, issued_at_utc) VALUES (?, ?, 'active', ?)", array($id, self::hash(), self::NOW));
            $this->fail('a second active token must be refused by uq_qr_tokens_one_active');
        } catch (EVC_Db_Duplicate_Key_Exception $e) {
            $this->assertSame('uq_qr_tokens_one_active', $e->key_name());
        }
        $this->assertSame(1, $this->active_count($id));
    }

    public function test_invalid_input_is_refused_before_sql(): void {
        $id = $this->member_id($this->create_member(7001));
        $calls = array(
            function () use ($id) {
                $this->store()->rotate(0, null, self::hash(), self::NOW);
            },
            function () use ($id) {
                $this->store()->rotate($id, 'nothex', self::hash(), self::NOW);
            },
            function () use ($id) {
                $this->store()->rotate($id, null, EVC_Qr_Token::mint(), self::NOW);
            },
            function () use ($id) {
                $this->store()->rotate($id, null, self::hash(), '2026-11-03 10:00:00');
            },
            function () use ($id) {
                $this->store()->revoke($id, EVC_Qr_Token::mint(), self::NOW);
            },
            function () use ($id) {
                $this->store()->revoke(-1, self::hash(), self::NOW);
            },
        );
        foreach ($calls as $i => $call) {
            try {
                $call();
                $this->fail("invalid input $i accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith('Invalid QR token', $e->getMessage());
            }
        }
        $this->assertSame(array(), $this->token_rows());
    }

    public function test_another_connection_rotating_in_between_makes_the_late_writer_lose(): void {
        $id = $this->member_id($this->create_member(7001));
        $first = self::hash();
        $this->store()->rotate($id, null, $first, self::NOW);
        $expected = $this->store()->active_token_hash($id);
        $winner = self::hash();
        $this->store($this->database->connect())->rotate($id, $first, $winner, self::LATER);
        try {
            $this->store()->rotate($id, $expected, self::hash(), self::LATER);
            $this->fail('the late writer must lose');
        } catch (EVC_Qr_Rotation_Conflict $e) {
            $this->assertSame($winner, $this->store()->active_token_hash($id));
        }
        // A first issuance raced by another connection's first issuance: the loser sees the winner's row.
        $other = $this->member_id($this->create_member(7002));
        $this->store($this->database->connect())->rotate($other, null, self::hash(), self::NOW);
        $this->expectException(EVC_Qr_Rotation_Conflict::class);
        $this->store()->rotate($other, null, self::hash(), self::NOW);
    }

    /**
     * A REAL lock wait: another connection holds the active row (FOR UPDATE) and has already replaced
     * it, uncommitted; a writer in a separate process is started and observed in LOCK WAIT; only then
     * does the holder commit. The waiter must re-read the committed state and lose.
     */
    public function test_a_writer_blocked_on_the_row_lock_sees_the_committed_winner_and_loses(): void {
        $id = $this->member_id($this->create_member(7001));
        $first = self::hash();
        $this->store()->rotate($id, null, $first, self::NOW);
        $holder = EVC_Test_Database::admin_pdo();
        $holder->exec('USE `' . $this->database->name() . '`');
        $holder->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $winner = self::hash();
        $holder->beginTransaction();
        $holder->prepare('SELECT token_hash FROM evc_qr_tokens WHERE active_member_id = ? FOR UPDATE')->execute(array($id));
        $holder->prepare("UPDATE evc_qr_tokens SET status = 'revoked', revoked_at_utc = ? WHERE active_member_id = ? AND token_hash = ?")->execute(array(self::LATER, $id, $first));
        $holder->prepare("INSERT INTO evc_qr_tokens (member_id, token_hash, status, issued_at_utc) VALUES (?, ?, 'active', ?)")->execute(array($id, $winner, self::LATER));
        $job = array('database' => $this->database->name(), 'op' => 'rotate', 'member_id' => $id, 'expected' => $first, 'new_hash' => self::hash(), 'start_at' => 0);
        $proc = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/bin/qr-token-worker.php', json_encode($job)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $this->assertIsResource($proc);
        $observer = EVC_Test_Database::admin_pdo();
        $waiting = false;
        $seen = array();
        $exit = null;
        for ($i = 0; $i < 400 && !$waiting; $i++) {
            usleep(50000);
            $seen = $observer->query('SELECT trx_state, trx_query FROM information_schema.INNODB_TRX')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($seen as $trx) {
                $waiting = $waiting || $trx['trx_state'] === 'LOCK WAIT';
            }
            $status = proc_get_status($proc);
            if (!$waiting && !$status['running']) {
                $exit = $status['exitcode']; // proc_close() would no longer know it
                break;
            }
        }
        $holder->commit();
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($proc);
        $this->assertSame(0, $exit ?? $closed, 'worker stderr: ' . $err . ' stdout: ' . $out);
        $this->assertTrue($waiting, 'the second writer really waited on the row lock; worker: ' . trim((string) $out) . ' ' . trim((string) $err) . '; transactions: ' . json_encode($seen));
        $this->assertSame('conflict', json_decode(trim((string) $out), true)['outcome'] ?? null, 'worker output: ' . $out);
        $this->assertSame(1, $this->active_count($id));
        $this->assertSame($winner, $this->store()->active_token_hash($id));
    }

    public function test_a_deadlock_is_a_concurrent_change_and_rolls_back(): void {
        $id = $this->member_id($this->create_member(7001));
        $first = self::hash();
        $this->store()->rotate($id, null, $first, self::NOW);
        $pdo = $this->database->faulty_pdo();
        $pdo->deadlock_prepare_matching = '/^INSERT INTO evc_qr_tokens/';
        $pdo->deadlocks_remaining = 1;
        try {
            $this->store(new EVC_Club_Db($pdo))->rotate($id, $first, self::hash(), self::LATER);
            $this->fail('expected a concurrent change');
        } catch (EVC_Qr_Rotation_Conflict $e) {
            $this->assertSame($first, $this->store()->active_token_hash($id), 'the revocation was rolled back');
            $this->assertCount(1, $this->token_rows($id));
        }
    }

    public function test_a_lost_commit_is_reported_unknown_either_way_for_rotation_and_revocation(): void {
        $id = $this->member_id($this->create_member(7001));
        $this->store()->rotate($id, null, self::hash(), self::NOW);
        foreach (array('fail_commit_before' => false, 'fail_commit_after' => true) as $fault => $committed) {
            // Rotation: revoke + insert in the lost transaction.
            $expected = $this->store()->active_token_hash($id);
            $this->assertNotNull($expected);
            $new = self::hash();
            $pdo = $this->database->faulty_pdo();
            $pdo->$fault = true;
            try {
                $this->store(new EVC_Club_Db($pdo))->rotate($id, $expected, $new, self::NOW);
                $this->fail('expected commit outcome unknown');
            } catch (EVC_Db_Commit_Unknown_Exception $e) {
                $this->assertSame(2013, $e->driver_code(), $fault);
            }
            $this->assertSame($committed ? $new : $expected, $this->store()->active_token_hash($id), $fault . ' rotate');
            $this->assertSame($committed ? 'revoked' : 'active', $this->store()->find_token_by_hash($expected)['status'], $fault . ' rotate: the old row');
            $this->assertSame(1, $this->active_count($id), $fault);
            // Revocation in the lost transaction.
            $active = $this->store()->active_token_hash($id);
            $pdo = $this->database->faulty_pdo();
            $pdo->$fault = true;
            try {
                $this->store(new EVC_Club_Db($pdo))->revoke($id, $active, self::LATER);
                $this->fail('expected commit outcome unknown');
            } catch (EVC_Db_Commit_Unknown_Exception $e) {
                $this->assertSame(2013, $e->driver_code(), $fault);
            }
            $this->assertSame($committed ? null : $active, $this->store()->active_token_hash($id), $fault . ' revoke');
            if ($committed) {
                $this->store()->rotate($id, null, self::hash(), self::LATER);
            }
        }
    }

    public function test_every_operation_works_with_the_least_privilege_grants_and_nothing_more(): void {
        $id = $this->member_id($this->create_member(7001));
        $user = 'evc_qr_' . bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(16));
        $this->users[] = $user;
        $admin = EVC_Test_Database::admin_pdo();
        $admin->exec("CREATE USER '" . $user . "'@'%' IDENTIFIED BY '" . $password . "'");
        foreach (EVC_Sql_Qr_Token_Store::RUNTIME_GRANTS as $table => $privileges) {
            $admin->exec('GRANT ' . $privileges . ' ON `' . $this->database->name() . '`.`' . $table . "` TO '" . $user . "'@'%'");
        }
        $runtime = EVC_Club_Db::connect(new EVC_Db_Config(EVC_Test_Database::host(), EVC_Test_Database::port(), $this->database->name(), $user, $password));
        $store = $this->store($runtime);
        $first = self::hash();
        $store->rotate($id, null, $first, self::NOW);
        $second = self::hash();
        $store->rotate($id, $first, $second, self::LATER);
        $this->assertSame($second, $store->active_token_hash($id));
        $this->assertSame('revoked', $store->find_token_by_hash($first)['status']);
        $this->assertSame(7001, $store->find_member_by_id($id)['wp_user_id']);
        $store->revoke($id, $second, self::LATER);
        $this->assertNull($store->active_token_hash($id));
        foreach (array(
            'DELETE FROM evc_qr_tokens',
            "UPDATE evc_qr_tokens SET token_hash = REPEAT('0', 64)",
            'UPDATE evc_qr_tokens SET member_id = member_id',
            "UPDATE evc_members SET status = 'disabled'",
            'SELECT COUNT(*) AS n FROM evc_redemptions',
        ) as $sql) {
            try {
                $runtime->fetch_all($sql);
                $this->fail('accepted: ' . $sql);
            } catch (EVC_Db_Exception $e) {
                $this->assertContains($e->driver_code(), array(1142, 1143), $sql);
            }
        }
        $this->assertCount(2, $this->token_rows($id));
    }

    // ---- the reviewed UI-04C services over the SQL store -----------------------------------

    public function test_the_member_gets_a_plaintext_token_once_and_rotation_retires_the_old_one(): void {
        $public = $this->create_member(7001);
        $first = $this->tokens_service()->issue(EVC_Qr_Issuance_Requester::member_self(7001), $public);
        $this->assertSame(EVC_Qr_Token_Result::ISSUED, $first->kind());
        $this->assertTrue(EVC_Qr_Token::is_valid_format($first->token()));
        $this->assertSame(EVC_Qr_Token::digest($first->token()), $this->store()->active_token_hash($this->member_id($public)));
        $lookup = $this->lookup_service();
        $found = $lookup->by_qr(self::tablet(), $first->token());
        $this->assertSame(EVC_Member_Lookup_Result::FOUND, $found->kind());
        $this->assertSame($public, $found->member()['member_public_id']);
        $this->assertSame(array('member_public_id', 'lookup_ref', 'lookup_ref_expires_at_utc'), array_keys($found->member()), 'identity only');
        $this->assertFalse($found->grants_entitlement());

        $second = $this->tokens_service()->issue(EVC_Qr_Issuance_Requester::member_self(7001), $public);
        $this->assertSame(EVC_Qr_Token_Result::ISSUED, $second->kind());
        $this->assertSame(EVC_Member_Lookup_Result::NOT_FOUND, $lookup->by_qr(self::tablet(), $first->token())->kind(), 'rotated');
        $this->assertSame(EVC_Member_Lookup_Result::FOUND, $lookup->by_qr(self::tablet(), $second->token())->kind());

        $this->assertSame(EVC_Qr_Token_Result::REVOKED, $this->tokens_service()->revoke(EVC_Qr_Issuance_Requester::member_self(7001), $public)->kind());
        $this->assertSame(EVC_Member_Lookup_Result::NOT_FOUND, $lookup->by_qr(self::tablet(), $second->token())->kind(), 'revoked');
        $this->assertSame(array(), $this->service_reports);
        $this->assertStringNotContainsString('evc_', json_encode($this->token_rows()));
    }

    public function test_a_first_issuance_never_replaces_a_token_another_device_just_received(): void {
        $public = $this->create_member(7001);
        $first_only = EVC_Club_Qr_Services::token_service($this->db, $this->clock, function () {
        }, true);
        $issued = $first_only->issue(EVC_Qr_Issuance_Requester::member_self(7001), $public);
        $this->assertSame(EVC_Qr_Token_Result::ISSUED, $issued->kind(), 'no token yet: a first one is issued');
        $active = $this->store()->active_token_hash($this->member_id($public));
        $again = $first_only->issue(EVC_Qr_Issuance_Requester::member_self(7001), $public);
        $this->assertSame(EVC_Qr_Token_Result::CONCURRENT_CHANGE, $again->kind());
        $this->assertNull($again->token());
        $this->assertSame($active, $this->store()->active_token_hash($this->member_id($public)), 'the existing QR is untouched');
        $replace = EVC_Club_Qr_Services::token_service($this->db, $this->clock, function () {
        }, false)->issue(EVC_Qr_Issuance_Requester::member_self(7001), $public);
        $this->assertSame(EVC_Qr_Token_Result::ISSUED, $replace->kind(), 'an explicit replacement rotates');
        $this->assertSame(EVC_Qr_Token::digest($replace->token()), $this->store()->active_token_hash($this->member_id($public)));
        $this->assertSame(array('id' => $this->member_id($public), 'member_public_id' => $public, 'wp_user_id' => 7001, 'status' => 'enabled'), $this->store()->find_member_by_wp_user(7001));
        $this->assertNull($this->store()->find_member_by_wp_user(7002));
        $this->assertNull($this->store()->find_member_by_wp_user(0));
    }

    public function test_the_qr_connection_gives_up_row_lock_waits_after_five_seconds(): void {
        EVC_Club_Qr_Services::prepare_connection($this->db);
        $this->assertSame('5', (string) $this->db->fetch_one('SELECT @@SESSION.innodb_lock_wait_timeout AS t')['t']);
    }

    public function test_another_customer_or_a_disabled_member_never_gets_a_token(): void {
        $public = $this->create_member(7001);
        $disabled = $this->create_member(7003, 'disabled');
        $this->assertSame(EVC_Qr_Token_Result::FORBIDDEN, $this->tokens_service()->issue(EVC_Qr_Issuance_Requester::member_self(7002), $public)->kind());
        $this->assertSame(EVC_Qr_Token_Result::FORBIDDEN, $this->tokens_service()->issue(EVC_Qr_Issuance_Requester::member_self(7002), EVC_Member_Id::mint())->kind(), 'no existence leak');
        $this->assertSame(EVC_Qr_Token_Result::MEMBER_DISABLED, $this->tokens_service()->issue(EVC_Qr_Issuance_Requester::member_self(7003), $disabled)->kind());
        $this->assertSame(array(), $this->token_rows());
    }

    public function test_a_concurrent_rotation_between_read_and_write_issues_no_token(): void {
        $public = $this->create_member(7001);
        $this->assertSame(EVC_Qr_Token_Result::ISSUED, $this->tokens_service()->issue(EVC_Qr_Issuance_Requester::member_self(7001), $public)->kind());
        $other = $this->database->connect();
        $racing = new EVC_Test_Racing_Qr_Store($this->store(), function () use ($other, $public) {
            $this->assertSame(EVC_Qr_Token_Result::ISSUED, $this->tokens_service(new EVC_Sql_Qr_Token_Store($other))->issue(EVC_Qr_Issuance_Requester::member_self(7001), $public)->kind());
        });
        $loser = $this->tokens_service($racing)->issue(EVC_Qr_Issuance_Requester::member_self(7001), $public);
        $this->assertSame(EVC_Qr_Token_Result::CONCURRENT_CHANGE, $loser->kind());
        $this->assertNull($loser->token());
        $this->assertSame(1, $this->active_count($this->member_id($public)));
    }

    public function test_a_disabled_member_or_an_expired_token_is_not_found_even_with_an_active_row(): void {
        $public = $this->create_member(7001);
        $token = $this->tokens_service()->issue(EVC_Qr_Issuance_Requester::member_self(7001), $public)->token();
        $this->clock = new EVC_Fixed_Clock('2026-10-16T10:00:00Z');
        $this->assertSame(EVC_Member_Lookup_Result::FOUND, $this->lookup_service(EVC_Qr_Token_Policy::max_age_days(30))->by_qr(self::tablet(), $token)->kind());
        $this->assertSame(EVC_Member_Lookup_Result::NOT_FOUND, $this->lookup_service(EVC_Qr_Token_Policy::max_age_days(7))->by_qr(self::tablet(), $token)->kind(), 'exactly 7 days old: expired');
        $this->db->execute("UPDATE evc_members SET status = 'disabled' WHERE member_public_id = ?", array($public));
        $this->assertSame(EVC_Member_Lookup_Result::NOT_FOUND, $this->lookup_service()->by_qr(self::tablet(), $token)->kind());
    }

    public function test_a_found_member_with_a_frozen_or_expired_card_still_gets_no_coffee(): void {
        $public = $this->create_member(7001);
        $token = $this->tokens_service()->issue(EVC_Qr_Issuance_Requester::member_self(7001), $public)->token();
        $cases = array(
            'cancelled (refund / cancellation freezes the card)' => EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_CANCELLED, true, self::DEFAULT_EXPIRY),
            'refunded' => EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_REFUNDED, true, self::DEFAULT_EXPIRY),
            'expired exactly at the end (exclusive)' => EVC_Mock_Membership_Adapter::active_until(self::DEFAULT_NOW),
            'unverified payment' => EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_ACTIVE, false, self::DEFAULT_EXPIRY),
        );
        foreach ($cases as $label => $entitlement) {
            $this->adapter->set(7001, $entitlement);
            $found = $this->lookup_service()->by_qr(self::tablet(), $token);
            $this->assertSame(EVC_Member_Lookup_Result::FOUND, $found->kind(), $label . ': identity only');
            $this->assertFalse($found->grants_entitlement());
            $result = $this->service()->redeem($this->request($found->member()['member_public_id']));
            $this->assertSame(EVC_Redemption_Result::MEMBERSHIP_INACTIVE, $result->outcome(), $label);
        }
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
        $this->adapter->set(7001, EVC_Mock_Membership_Adapter::active_until(self::DEFAULT_EXPIRY));
        $this->assertTrue($this->service()->redeem($this->request($public))->is_success());
        $again = $this->service()->redeem($this->request($this->lookup_service()->by_qr(self::tablet(), $token)->member()['member_public_id']));
        $this->assertSame(EVC_Redemption_Result::ALREADY_REDEEMED, $again->outcome(), 'one coffee per Athens day, however often the QR is scanned');
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
    }

    /**
     * Real separate processes and connections, released by a shared time barrier, all writing the
     * SAME member's token: exactly one active row, exactly one winner per round, every other worker
     * a concurrent change (never an extra token), for first issuance and for rotation.
     *
     * @group concurrency
     */
    public function test_simultaneous_writers_in_separate_processes_leave_exactly_one_active_token(): void {
        $rounds = max(1, (int) (getenv('EVC_CONCURRENCY_ROUNDS') ?: 10));
        $workers = max(2, (int) (getenv('EVC_CONCURRENCY_WORKERS') ?: 6));
        for ($round = 0; $round < $rounds; $round++) {
            $id = $this->member_id($this->create_member(9000 + $round));
            $expected = null;
            if ($round % 2 === 1) {
                $expected = self::hash();
                $this->store()->rotate($id, null, $expected, self::NOW);
            }
            $start_at = microtime(true) + 1.0;
            $running = array();
            $hashes = array();
            for ($w = 0; $w < $workers; $w++) {
                $hashes[$w] = self::hash();
                $job = array('database' => $this->database->name(), 'op' => 'rotate', 'member_id' => $id, 'expected' => $expected, 'new_hash' => $hashes[$w], 'start_at' => $start_at);
                $proc = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/bin/qr-token-worker.php', json_encode($job)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
                $this->assertIsResource($proc);
                $running[$w] = array($proc, $pipes);
            }
            $outcomes = array();
            foreach ($running as $w => $entry) {
                list($proc, $pipes) = $entry;
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($proc), 'worker stderr: ' . $err);
                $decoded = json_decode(trim((string) $out), true);
                $this->assertIsArray($decoded, 'worker output: ' . $out . ' / ' . $err);
                $outcomes[$w] = $decoded['outcome'];
            }
            $label = "round $round: " . implode(',', $outcomes);
            $winners = array_keys($outcomes, 'rotated', true);
            $this->assertCount(1, $winners, $label);
            foreach ($outcomes as $outcome) {
                $this->assertContains($outcome, array('rotated', 'conflict', 'duplicate'), $label);
            }
            $this->assertSame(1, $this->active_count($id), $label);
            $this->assertSame($hashes[$winners[0]], $this->store()->active_token_hash($id), $label);
            $this->assertCount($expected === null ? 1 : 2, $this->token_rows($id), $label . ': no token from a losing worker');
        }
    }

    /**
     * Revoke-versus-rotate in separate processes from the SAME expected digest (the unique key does
     * not cover revoke(): only the row lock, the digest compare and the guarded UPDATE decide).
     * Exactly one worker wins; the result is either one new active token (a rotation won) or none
     * (a revocation won), and no losing rotation ever leaves a row behind.
     *
     * @group concurrency
     */
    public function test_simultaneous_revoke_and_rotate_in_separate_processes_have_exactly_one_winner(): void {
        $rounds = max(1, (int) (getenv('EVC_CONCURRENCY_ROUNDS') ?: 10));
        $workers = max(2, (int) (getenv('EVC_CONCURRENCY_WORKERS') ?: 6));
        for ($round = 0; $round < $rounds; $round++) {
            $id = $this->member_id($this->create_member(9500 + $round));
            $expected = self::hash();
            $this->store()->rotate($id, null, $expected, self::NOW);
            $start_at = microtime(true) + 1.0;
            $running = array();
            $jobs = array();
            for ($w = 0; $w < $workers; $w++) {
                $jobs[$w] = array('database' => $this->database->name(), 'op' => $w % 2 === 0 ? 'revoke' : 'rotate', 'member_id' => $id, 'expected' => $expected, 'new_hash' => self::hash(), 'start_at' => $start_at);
                $proc = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/bin/qr-token-worker.php', json_encode($jobs[$w])), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
                $this->assertIsResource($proc);
                $running[$w] = array($proc, $pipes);
            }
            $outcomes = array();
            foreach ($running as $w => $entry) {
                list($proc, $pipes) = $entry;
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($proc), 'worker stderr: ' . $err);
                $outcomes[$w] = json_decode(trim((string) $out), true)['outcome'] ?? 'bad:' . $out;
            }
            $label = "round $round: " . implode(',', $outcomes);
            $winners = array_keys(array_filter($outcomes, function ($o) {
                return $o === 'rotated' || $o === 'revoked';
            }));
            $this->assertCount(1, $winners, $label);
            foreach ($outcomes as $outcome) {
                $this->assertContains($outcome, array('rotated', 'revoked', 'conflict', 'duplicate'), $label);
            }
            $winner = $winners[0];
            $this->assertSame('revoked', $this->store()->find_token_by_hash($expected)['status'], $label);
            if ($jobs[$winner]['op'] === 'rotate') {
                $this->assertSame($jobs[$winner]['new_hash'], $this->store()->active_token_hash($id), $label);
                $this->assertCount(2, $this->token_rows($id), $label);
            } else {
                $this->assertNull($this->store()->active_token_hash($id), $label);
                $this->assertCount(1, $this->token_rows($id), $label);
            }
        }
    }
}

/** TEST-ONLY: delegates to a real store and runs $before once, right before the first rotate(). */
final class EVC_Test_Racing_Qr_Store implements EVC_Qr_Token_Store {
    /** @var EVC_Qr_Token_Store */
    private $inner;
    /** @var callable|null */
    private $before;

    public function __construct(EVC_Qr_Token_Store $inner, callable $before) {
        $this->inner = $inner;
        $this->before = $before;
    }

    public function find_member_by_public_id(string $member_public_id): ?array {
        return $this->inner->find_member_by_public_id($member_public_id);
    }

    public function find_member_by_id(int $member_id): ?array {
        return $this->inner->find_member_by_id($member_id);
    }

    public function find_token_by_hash(string $token_hash): ?array {
        return $this->inner->find_token_by_hash($token_hash);
    }

    public function active_token_hash(int $member_id): ?string {
        return $this->inner->active_token_hash($member_id);
    }

    public function rotate(int $member_id, ?string $expected_active_hash, string $new_hash, string $now_utc): void {
        if ($this->before !== null) {
            $before = $this->before;
            $this->before = null;
            $before();
        }
        $this->inner->rotate($member_id, $expected_active_hash, $new_hash, $now_utc);
    }

    public function revoke(int $member_id, string $expected_active_hash, string $now_utc): void {
        $this->inner->revoke($member_id, $expected_active_hash, $now_utc);
    }
}
