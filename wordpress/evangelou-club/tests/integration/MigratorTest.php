<?php

final class MigratorTest extends EVC_Db_Test_Case {
    /** @var string[] Temporary migration directories to clean up. */
    private $temp_dirs = array();

    protected function migrate_on_setup(): bool {
        return false;
    }

    protected function tearDown(): void {
        foreach ($this->temp_dirs as $dir) {
            foreach (glob($dir . '/*') ?: array() as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        parent::tearDown();
    }

    private function temp_migrations(array $files): string {
        $dir = sys_get_temp_dir() . '/evc_mig_' . bin2hex(random_bytes(5));
        mkdir($dir);
        foreach ($files as $name => $sql) {
            file_put_contents($dir . '/' . $name, $sql);
        }
        $this->temp_dirs[] = $dir;
        return $dir;
    }

    private function tables(): array {
        $rows = $this->db->fetch_all(
            'SELECT TABLE_NAME AS t, ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
        );
        $out = array();
        foreach ($rows as $row) {
            $out[$row['t']] = $row['e'];
        }
        return $out;
    }

    public function test_fresh_database_migrates_and_records_version_and_checksum(): void {
        $migrator = new EVC_Migrator($this->db, EVC_Test_Database::migrations_dir());
        $this->assertSame(array(1), $migrator->migrate());

        $this->assertSame(array(
            'evc_audit_events' => 'InnoDB',
            'evc_members' => 'InnoDB',
            'evc_qr_tokens' => 'InnoDB',
            'evc_redemptions' => 'InnoDB',
            'evc_schema_migrations' => 'InnoDB',
        ), $this->tables());

        $applied = $migrator->applied();
        $this->assertSame(array(1), array_keys($applied));
        $this->assertSame('initial', $applied[1]['name']);
        $this->assertSame(
            EVC_Migrator::checksum_file(EVC_Test_Database::migrations_dir() . '/001_initial.sql'),
            $applied[1]['checksum']
        );
    }

    public function test_repeat_migration_is_a_no_op(): void {
        $migrator = new EVC_Migrator($this->db, EVC_Test_Database::migrations_dir());
        $migrator->migrate();
        $before = $this->tables();
        $this->assertSame(array(), $migrator->migrate());
        $this->assertSame(array(), (new EVC_Migrator($this->db, EVC_Test_Database::migrations_dir()))->migrate());
        $this->assertSame($before, $this->tables());
        $this->assertSame(1, $this->count_rows('evc_schema_migrations'));
    }

    public function test_required_unique_keys_and_foreign_keys_exist(): void {
        (new EVC_Migrator($this->db, EVC_Test_Database::migrations_dir()))->migrate();
        $unique = $this->db->fetch_all(
            "SELECT INDEX_NAME AS i, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evc_redemptions' AND NON_UNIQUE = 0
              GROUP BY INDEX_NAME ORDER BY INDEX_NAME"
        );
        $by_name = array();
        foreach ($unique as $row) {
            $by_name[$row['i']] = $row['cols'];
        }
        $this->assertSame('member_id,benefit_type,business_date', $by_name['uq_redemptions_one_per_day']);
        $this->assertSame('request_id', $by_name['uq_redemptions_request']);

        $fks = $this->db->fetch_all(
            "SELECT CONSTRAINT_NAME AS c FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY CONSTRAINT_NAME"
        );
        $this->assertSame(
            array('fk_audit_member', 'fk_audit_redemption', 'fk_qr_tokens_member', 'fk_redemptions_member'),
            array_column($fks, 'c')
        );
    }

    public function test_modified_applied_migration_is_detected_as_drift(): void {
        $dir = $this->temp_migrations(array('001_initial.sql' => "CREATE TABLE evc_tmp_a (id INT) ENGINE=InnoDB;\n"));
        (new EVC_Migrator($this->db, $dir))->migrate();
        file_put_contents($dir . '/001_initial.sql', "CREATE TABLE evc_tmp_a (id BIGINT) ENGINE=InnoDB;\n");

        $this->expectException(EVC_Migration_Exception::class);
        $this->expectExceptionMessage('modified after it ran');
        (new EVC_Migrator($this->db, $dir))->migrate();
    }

    public function test_missing_applied_migration_is_detected_as_drift(): void {
        $dir = $this->temp_migrations(array(
            '001_one.sql' => "CREATE TABLE evc_tmp_a (id INT) ENGINE=InnoDB;\n",
            '002_two.sql' => "CREATE TABLE evc_tmp_b (id INT) ENGINE=InnoDB;\n",
        ));
        (new EVC_Migrator($this->db, $dir))->migrate();
        unlink($dir . '/002_two.sql');

        $this->expectException(EVC_Migration_Exception::class);
        $this->expectExceptionMessage('missing from the code');
        (new EVC_Migrator($this->db, $dir))->migrate();
    }

    public function test_out_of_order_migration_is_rejected(): void {
        $dir = $this->temp_migrations(array(
            '001_one.sql' => "CREATE TABLE evc_tmp_a (id INT) ENGINE=InnoDB;\n",
            '003_three.sql' => "CREATE TABLE evc_tmp_c (id INT) ENGINE=InnoDB;\n",
        ));
        (new EVC_Migrator($this->db, $dir))->migrate();
        file_put_contents($dir . '/002_two.sql', "CREATE TABLE evc_tmp_b (id INT) ENGINE=InnoDB;\n");

        try {
            (new EVC_Migrator($this->db, $dir))->migrate();
            $this->fail('Out-of-order migration must be rejected.');
        } catch (EVC_Migration_Exception $e) {
            $this->assertStringContainsString('Out-of-order', $e->getMessage());
        }
        $this->assertArrayNotHasKey('evc_tmp_b', $this->tables());
    }

    public function test_pre_existing_incompatible_table_fails_instead_of_being_kept(): void {
        $this->db->exec_raw('CREATE TABLE evc_members (id INT NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB');
        try {
            (new EVC_Migrator($this->db, EVC_Test_Database::migrations_dir()))->migrate();
            $this->fail('Migration over an incompatible existing table must fail.');
        } catch (EVC_Migration_Exception $e) {
            $this->assertStringContainsString('Migration 001 failed', $e->getMessage());
            $this->assertStringContainsString('restore the pre-migration backup', $e->getMessage());
            $this->assertStringNotContainsString('already exists', $e->getMessage(), 'no raw SQL error text');
        }
        $this->assertSame(0, $this->count_rows('evc_schema_migrations'), 'failed migration is not recorded');
    }

    public function test_bookkeeping_table_with_wrong_shape_is_drift(): void {
        $this->db->exec_raw('CREATE TABLE evc_schema_migrations (foo INT) ENGINE=InnoDB');
        $this->expectException(EVC_Migration_Exception::class);
        $this->expectExceptionMessage('unexpected shape');
        (new EVC_Migrator($this->db, EVC_Test_Database::migrations_dir()))->migrate();
    }

    public function test_concurrent_migrator_is_blocked_by_lock(): void {
        $other = $this->database->connect();
        $row = $other->fetch_one('SELECT GET_LOCK(CONCAT(?, SHA1(DATABASE())), 0) AS acquired', array(EVC_Migrator::LOCK_PREFIX));
        $this->assertSame(1, (int) $row['acquired']);
        try {
            $migrator = new EVC_Migrator($this->db, $this->temp_migrations(array('001_one.sql' => "CREATE TABLE evc_tmp_a (id INT) ENGINE=InnoDB;\n")));
            $start = microtime(true);
            try {
                $migrator->migrate();
                $this->fail('Second migrator must not run while the lock is held.');
            } catch (EVC_Migration_Exception $e) {
                $this->assertStringContainsString('lock not acquired', $e->getMessage());
            }
            $this->assertGreaterThanOrEqual(EVC_Migrator::LOCK_TIMEOUT_SECONDS - 1, microtime(true) - $start);
            $this->assertArrayNotHasKey('evc_tmp_a', $this->tables());
        } finally {
            $other->fetch_one('SELECT RELEASE_LOCK(CONCAT(?, SHA1(DATABASE()))) AS r', array(EVC_Migrator::LOCK_PREFIX));
        }
    }
}
