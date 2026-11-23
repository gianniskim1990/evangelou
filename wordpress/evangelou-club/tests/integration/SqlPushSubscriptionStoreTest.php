<?php

/**
 * STAGE-08 round 12 (owner decision D26 part 2): migration 005 on top of 001-004 and the push subscription store against
 * a REAL MySQL / MariaDB server: ownership (one member never changes another's subscription), consent time, revocation,
 * the batch reader, the counters, the CHECK constraints, and the least-privilege grants (SELECT, INSERT, UPDATE; no DELETE).
 *
 * Group push-store: excluded from the engine mutation runner; its own mutants run in run-provenance-sql-mutations.php.
 *
 * @group push-store
 */
final class SqlPushSubscriptionStoreTest extends EVC_Db_Test_Case {
    const NOW = '2026-11-21 10:00:00.000000';
    const LATER = '2026-11-21 11:00:00.000000';
    const FCM = 'https://fcm.googleapis.com/fcm/send/abc:APA91-one';
    const FCM_TWO = 'https://fcm.googleapis.com/fcm/send/def:APA91-two';
    const APPLE = 'https://web.push.apple.com/QGuQyavXutnMH5NhCzQHvOpY8p2vY';
    const P256 = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    const P256_OTHER = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    const AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
    const AUTH_OTHER = 'AAAAAAAAAAAAAAAAAAAAAA';

    /** @var string[] */
    private $users = array();

    protected function tearDown(): void {
        foreach ($this->users as $user) {
            EVC_Test_Database::admin_pdo()->exec("DROP USER IF EXISTS '" . $user . "'@'%'");
        }
        $this->users = array();
        parent::tearDown();
    }

    private function store(?EVC_Club_Db $db = null): EVC_Sql_Push_Subscription_Store {
        return new EVC_Sql_Push_Subscription_Store($db ?? $this->db);
    }

    private function member(int $wp_user_id, string $status = 'enabled'): int {
        $public = $this->create_member($wp_user_id, $status);
        return (int) $this->db->fetch_one('SELECT id FROM evc_members WHERE member_public_id = ?', array($public))['id'];
    }

    private function row(string $endpoint): ?array {
        return $this->db->fetch_one('SELECT * FROM evc_push_subscriptions WHERE endpoint_hash = ?', array(hash('sha256', $endpoint)));
    }

    public function test_migration_005_adds_only_the_push_table_on_top_of_001_to_004(): void {
        $fresh = EVC_Test_Database::create();
        try {
            $db = $fresh->connect();
            $migrator = new EVC_Migrator($db, EVC_Test_Database::migrations_dir());
            $this->assertSame(array(1, 2, 3, 4), $migrator->migrate(4));
            $before = array_column($db->fetch_all("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"), 't');
            $this->assertNotContains('evc_push_subscriptions', $before);
            $this->assertSame(array(5), $migrator->migrate(5));
            $after = array_column($db->fetch_all("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"), 't');
            sort($before);
            $added = array_values(array_diff($after, $before));
            $this->assertSame(array('evc_push_subscriptions'), $added, 'add-only: one new table');
            $this->assertSame('push_subscriptions', $migrator->applied()[5]['name']);
            $cols = array();
            foreach ($db->fetch_all("SELECT COLUMN_NAME AS c, COLUMN_TYPE AS t, IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evc_push_subscriptions' ORDER BY ORDINAL_POSITION") as $c) {
                $cols[$c['c']] = strtolower($c['t']) . ($c['n'] === 'YES' ? ' null' : '');
            }
            $this->assertSame(array(
                'id' => 'bigint(20) unsigned', 'member_id' => 'bigint(20) unsigned', 'endpoint_hash' => 'char(64)', 'endpoint' => 'varchar(1024)',
                'p256dh' => 'varchar(100)', 'auth' => 'varchar(32)', 'push_service' => 'varchar(16)', 'agent_family' => 'varchar(16)',
                'consent_at_utc' => 'datetime(6)', 'created_at_utc' => 'datetime(6)', 'updated_at_utc' => 'datetime(6)',
                'last_success_at_utc' => 'datetime(6) null', 'success_count' => 'int(10) unsigned', 'failure_count' => 'int(10) unsigned',
                'last_failure_at_utc' => 'datetime(6) null', 'revoked_at_utc' => 'datetime(6) null', 'revoked_reason' => 'varchar(16) null',
            ), array_map(function ($t) {
                return str_replace(array('bigint unsigned', 'int unsigned'), array('bigint(20) unsigned', 'int(10) unsigned'), $t);
            }, $cols), 'bounded column sizes (MySQL 8 omits the display width of integers)');
            $unique = $db->fetch_all("SELECT INDEX_NAME AS i, GROUP_CONCAT(COLUMN_NAME) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evc_push_subscriptions' AND NON_UNIQUE = 0 GROUP BY INDEX_NAME ORDER BY INDEX_NAME");
            $this->assertSame(array(array('i' => 'PRIMARY', 'c' => 'id'), array('i' => 'uq_push_endpoint', 'c' => 'endpoint_hash')), $unique);
        } finally {
            $fresh->drop();
        }
    }

    public function test_a_subscription_is_stored_with_the_consent_time_and_coarse_labels_only(): void {
        $m = $this->member(9001);
        $this->assertSame('created', $this->store()->subscribe($m, self::FCM, self::P256, self::AUTH, 'fcm', 'chrome', self::NOW));
        $row = $this->row(self::FCM);
        $this->assertSame(self::FCM, $row['endpoint']);
        $this->assertSame((string) $m, (string) $row['member_id']);
        $this->assertSame(self::P256, $row['p256dh']);
        $this->assertSame(self::AUTH, $row['auth']);
        $this->assertSame('fcm', $row['push_service']);
        $this->assertSame('chrome', $row['agent_family']);
        $this->assertSame(self::NOW, $row['consent_at_utc']);
        $this->assertSame(self::NOW, $row['created_at_utc']);
        $this->assertNull($row['revoked_at_utc']);
        $this->assertSame('0', (string) $row['failure_count']);
    }

    public function test_the_same_member_refreshes_keys_and_keeps_the_first_consent(): void {
        $m = $this->member(9001);
        $this->store()->subscribe($m, self::FCM, self::P256, self::AUTH, 'fcm', 'chrome', self::NOW);
        $this->assertSame('refreshed', $this->store()->subscribe($m, self::FCM, self::P256_OTHER, self::AUTH_OTHER, 'fcm', 'samsung', self::LATER));
        $row = $this->row(self::FCM);
        $this->assertSame(self::P256_OTHER, $row['p256dh']);
        $this->assertSame(self::AUTH_OTHER, $row['auth']);
        $this->assertSame('samsung', $row['agent_family']);
        $this->assertSame(self::NOW, $row['consent_at_utc'], 'a re-sync is not a new consent');
        $this->assertSame(self::LATER, $row['updated_at_utc']);
        $this->assertSame(1, $this->count_rows('evc_push_subscriptions'));
    }

    public function test_member_a_cannot_take_or_stop_member_bs_active_subscription(): void {
        $a = $this->member(9001);
        $b = $this->member(9002);
        $this->store()->subscribe($b, self::FCM, self::P256, self::AUTH, 'fcm', 'chrome', self::NOW);
        $this->assertSame('taken', $this->store()->subscribe($a, self::FCM, self::P256_OTHER, self::AUTH_OTHER, 'fcm', 'chrome', self::LATER));
        $row = $this->row(self::FCM);
        $this->assertSame((string) $b, (string) $row['member_id']);
        $this->assertSame(self::P256, $row['p256dh'], 'B\'s keys untouched');
        $this->assertSame(self::NOW, $row['updated_at_utc']);
        $this->assertFalse($this->store()->unsubscribe($a, self::FCM, self::LATER), 'A cannot stop B\'s subscription');
        $this->assertNull($this->row(self::FCM)['revoked_at_utc']);
        $this->assertTrue($this->store()->unsubscribe($b, self::FCM, self::LATER));
        $this->assertSame('member', $this->row(self::FCM)['revoked_reason']);
    }

    public function test_a_revoked_row_comes_back_only_with_a_new_consent_for_whoever_subscribes(): void {
        $a = $this->member(9001);
        $b = $this->member(9002);
        $this->store()->subscribe($b, self::FCM, self::P256, self::AUTH, 'fcm', 'chrome', self::NOW);
        $this->store()->record_failure((int) $this->row(self::FCM)['id'], self::NOW);
        $this->assertTrue($this->store()->unsubscribe($b, self::FCM, self::NOW));
        $this->assertFalse($this->store()->unsubscribe($b, self::FCM, self::LATER), 'already stopped');
        // B signed out on a shared phone; A signs in on it and opts in: the browser keeps its endpoint.
        $this->assertSame('renewed', $this->store()->subscribe($a, self::FCM, self::P256_OTHER, self::AUTH_OTHER, 'fcm', 'chrome', self::LATER));
        $row = $this->row(self::FCM);
        $this->assertSame((string) $a, (string) $row['member_id']);
        $this->assertSame(self::LATER, $row['consent_at_utc']);
        $this->assertNull($row['revoked_at_utc']);
        $this->assertNull($row['revoked_reason']);
        $this->assertSame('0', (string) $row['failure_count']);
        $this->assertSame('0', (string) $row['success_count']);
        // The same member opting in again after stopping: a new consent time too.
        $this->store()->unsubscribe($a, self::FCM, self::LATER);
        $this->assertSame('renewed', $this->store()->subscribe($a, self::FCM, self::P256, self::AUTH, 'fcm', 'chrome', '2026-11-22 09:00:00.000000'));
        $this->assertSame('2026-11-22 09:00:00.000000', $this->row(self::FCM)['consent_at_utc']);
    }

    public function test_the_batch_reader_returns_only_active_rows_in_id_order_with_the_member(): void {
        $a = $this->member(9001);
        $b = $this->member(9002, 'disabled');
        $s = $this->store();
        $s->subscribe($a, self::FCM, self::P256, self::AUTH, 'fcm', 'chrome', self::NOW);
        $s->subscribe($b, self::APPLE, self::P256, self::AUTH, 'apple', 'safari', self::NOW);
        $s->subscribe($a, self::FCM_TWO, self::P256, self::AUTH, 'fcm', 'chrome', self::NOW);
        $s->unsubscribe($a, self::FCM_TWO, self::LATER);
        $rows = $s->batch(0, 10);
        $this->assertSame(array(self::FCM, self::APPLE), array_column($rows, 'endpoint'));
        $this->assertSame(array(9001, 9002), array_column($rows, 'wp_user_id'));
        $this->assertSame(array('enabled', 'disabled'), array_column($rows, 'member_status'));
        $this->assertSame(array('id', 'member_id', 'wp_user_id', 'member_status', 'endpoint', 'p256dh', 'auth'), array_keys($rows[0]));
        $this->assertSame(array(self::APPLE), array_column($s->batch($rows[0]['id'], 10), 'endpoint'), 'after the cursor');
        $this->assertCount(1, $s->batch(0, 1), 'the limit');
        $this->assertSame(array(self::FCM), array_column($s->batch(0, 10, $a), 'endpoint'), 'one member only');
        $this->assertSame(1, $s->count_active(), 'a disabled member is not counted');
        $this->assertSame(array($a => 9001), $s->subscribed_members(0, 10));
        $this->assertSame(array(), $s->subscribed_members($a, 10));
        // Round 18 (D30): every ENABLED member, with or without a subscription, ascending, after a member id.
        $quiet = $this->member(9050);
        $off = $this->member(9060, 'disabled');
        $this->assertSame(array($a => 9001, $quiet => 9050), $s->enabled_members(0, 1000), 'the disabled ones are not read');
        $this->assertCount(1, $s->enabled_members(0, 1), 'the limit');
        $this->assertArrayNotHasKey($off, $s->enabled_members(0, 1000));
        $this->assertSame(array($quiet => 9050), $s->enabled_members($a, 1000), 'after the member id');
        $this->assertSame(array('id' => $b, 'status' => 'disabled'), $s->member_for_wp_user(9002));
        $this->assertNull($s->member_for_wp_user(9999));
    }

    public function test_counters_and_the_gone_revocation(): void {
        $a = $this->member(9001);
        $s = $this->store();
        $s->subscribe($a, self::FCM, self::P256, self::AUTH, 'fcm', 'chrome', self::NOW);
        $id = (int) $this->row(self::FCM)['id'];
        $s->record_failure($id, self::NOW);
        $s->record_failure($id, self::NOW);
        $this->assertSame('2', (string) $this->row(self::FCM)['failure_count']);
        $s->record_success($id, self::LATER);
        $row = $this->row(self::FCM);
        $this->assertSame('1', (string) $row['success_count']);
        $this->assertSame('0', (string) $row['failure_count'], 'consecutive failures reset by a success');
        $this->assertSame(self::LATER, $row['last_success_at_utc']);
        $this->assertSame(self::NOW, $row['last_failure_at_utc']);
        $s->revoke_gone($id, self::LATER);
        $row = $this->row(self::FCM);
        $this->assertSame('gone', $row['revoked_reason']);
        $this->assertSame(self::LATER, $row['revoked_at_utc']);
        $this->assertSame(array(), $s->batch(0, 10), 'never sent to again');
        $s->record_success($id, '2026-11-23 00:00:00.000000');
        $this->assertSame('1', (string) $this->row(self::FCM)['success_count'], 'a revoked row is not touched');
    }

    public function test_bad_values_never_reach_the_table(): void {
        $a = $this->member(9001);
        foreach (array(
            array($a, 'https://evil.example/x/y', self::P256, self::AUTH, 'fcm', 'chrome'),
            array($a, self::FCM, self::P256, self::AUTH, 'apple', 'chrome'),
            array($a, self::FCM, self::P256, self::AUTH, 'fcm', 'netscape'),
            array($a, self::FCM, self::P256 . '+', self::AUTH, 'fcm', 'chrome'),
            array(0, self::FCM, self::P256, self::AUTH, 'fcm', 'chrome'),
        ) as $i => $c) {
            try {
                $this->store()->subscribe($c[0], $c[1], $c[2], $c[3], $c[4], $c[5], self::NOW);
                $this->fail('case ' . $i . ' accepted');
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, $this->count_rows('evc_push_subscriptions'));
        foreach (array(
            "INSERT INTO evc_push_subscriptions (member_id, endpoint_hash, endpoint, p256dh, auth, push_service, agent_family, consent_at_utc, created_at_utc, updated_at_utc) VALUES ($a, REPEAT('a', 64), 'http://x.example/abcdef', REPEAT('A', 87), REPEAT('A', 22), 'fcm', 'chrome', NOW(6), NOW(6), NOW(6))",
            "INSERT INTO evc_push_subscriptions (member_id, endpoint_hash, endpoint, p256dh, auth, push_service, agent_family, consent_at_utc, created_at_utc, updated_at_utc) VALUES ($a, REPEAT('b', 64), 'https://fcm.googleapis.com/x', REPEAT('A', 87), REPEAT('A', 22), 'gcm', 'chrome', NOW(6), NOW(6), NOW(6))",
            "INSERT INTO evc_push_subscriptions (member_id, endpoint_hash, endpoint, p256dh, auth, push_service, agent_family, consent_at_utc, created_at_utc, updated_at_utc, revoked_at_utc) VALUES ($a, REPEAT('c', 64), 'https://fcm.googleapis.com/x', REPEAT('A', 87), REPEAT('A', 22), 'fcm', 'chrome', NOW(6), NOW(6), NOW(6), NOW(6))",
            "INSERT INTO evc_push_subscriptions (member_id, endpoint_hash, endpoint, p256dh, auth, push_service, agent_family, consent_at_utc, created_at_utc, updated_at_utc) VALUES (999999, REPEAT('d', 64), 'https://fcm.googleapis.com/x', REPEAT('A', 87), REPEAT('A', 22), 'fcm', 'chrome', NOW(6), NOW(6), NOW(6))",
        ) as $sql) {
            try {
                $this->db->execute($sql);
                $this->fail('accepted: ' . $sql);
            } catch (EVC_Db_Exception $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_every_operation_works_with_select_insert_update_and_nothing_more(): void {
        $a = $this->member(9001);
        $user = 'evc_push_' . bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(16));
        $this->users[] = $user;
        $admin = EVC_Test_Database::admin_pdo();
        $admin->exec("CREATE USER '" . $user . "'@'%' IDENTIFIED BY '" . $password . "'");
        $admin->exec('GRANT ' . implode(', ', EVC_Sql_Push_Subscription_Store::PRIVILEGES) . ' ON `' . $this->database->name() . "`.`evc_push_subscriptions` TO '" . $user . "'@'%'");
        $admin->exec('GRANT SELECT ON `' . $this->database->name() . "`.`evc_members` TO '" . $user . "'@'%'");
        $this->assertSame(array('SELECT', 'INSERT', 'UPDATE'), EVC_Sql_Push_Subscription_Store::PRIVILEGES);
        $this->assertSame(EVC_Db_Grants::PUSH_PRIVILEGES, EVC_Sql_Push_Subscription_Store::PRIVILEGES);
        $runtime = EVC_Club_Db::connect(new EVC_Db_Config(EVC_Test_Database::host(), EVC_Test_Database::port(), $this->database->name(), $user, $password));
        $s = $this->store($runtime);
        $this->assertSame('created', $s->subscribe($a, self::FCM, self::P256, self::AUTH, 'fcm', 'chrome', self::NOW));
        $this->assertSame('refreshed', $s->subscribe($a, self::FCM, self::P256, self::AUTH, 'fcm', 'chrome', self::NOW));
        $id = $s->batch(0, 5)[0]['id'];
        $s->record_failure($id, self::NOW);
        $s->record_success($id, self::NOW);
        $this->assertSame(1, $s->count_active());
        $this->assertSame(array($a => 9001), $s->subscribed_members(0, 5));
        // Round 18 (D30): the in-app message audience reads evc_members only (SELECT).
        $this->assertSame(array($a => 9001), $s->enabled_members(0, 5));
        $this->assertTrue($s->unsubscribe($a, self::FCM, self::LATER));
        $this->assertSame('renewed', $s->subscribe($a, self::FCM, self::P256, self::AUTH, 'fcm', 'chrome', self::LATER));
        $s->revoke_gone($id, self::LATER);
        foreach (array('DELETE FROM evc_push_subscriptions', "UPDATE evc_members SET status = 'disabled'", 'SELECT COUNT(*) AS n FROM evc_redemptions') as $sql) {
            try {
                $runtime->fetch_all($sql);
                $this->fail('accepted: ' . $sql);
            } catch (EVC_Db_Exception $e) {
                $this->assertContains($e->driver_code(), array(1142, 1143), $sql);
            }
        }
    }
}
