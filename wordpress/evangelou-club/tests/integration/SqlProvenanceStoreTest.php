<?php

/**
 * Task 1D-G: EVC_Sql_Provenance_Store against a REAL disposable MySQL/MariaDB
 * database: schema constraints (incl. the pmpro_row_ref binding), permitted
 * write shapes, calendar/chronology re-check under lock, atomic rollback,
 * deadlock/lock-wait handling, COMMIT-unknown recovery, schema readiness and
 * least-privilege grants. Synthetic data only. Real multi-process races are
 * in SqlProvenanceConcurrencyTest.
 */
final class SqlProvenanceStoreTest extends EVC_Db_Test_Case {
    const TABLES = array('evc_pay_movements', 'evc_pay_aliases', 'evc_pay_periods', 'evc_pay_member_heads', 'evc_pay_correction_heads', 'evc_pay_corrections');

    /** @var string[] users to drop */
    private $users = array();
    /** @var PDO[] */
    private $blockers = array();

    protected function tearDown(): void {
        foreach ($this->blockers as $pdo) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $this->blockers = array();
        foreach ($this->users as $user) {
            EVC_Test_Database::admin_pdo()->exec("DROP USER IF EXISTS '" . $user . "'@'%'");
        }
        parent::tearDown();
    }

    private static function ref(string $label): string {
        return EVC_Provenance_Test_Fixture::ref($label);
    }

    private static function mv(string $label, string $at, array $o = array()): EVC_Verified_Movement {
        return EVC_Provenance_Test_Fixture::movement($label, $at, $o);
    }

    private static function corr(string $label, string $movement, string $kind): EVC_Movement_Correction {
        return EVC_Provenance_Test_Fixture::correction($label, $movement, $kind);
    }

    private function store(?EVC_Club_Db $db = null, int $lock_wait = 5, ?callable $reconnect = null): EVC_Sql_Provenance_Store {
        return EVC_Provenance_Test_Fixture::sql_store($this->database->config(), $lock_wait, $db, $reconnect);
    }

    private function rec(?EVC_Provenance_Store $store = null): EVC_Provenance_Recorder {
        return EVC_Provenance_Test_Fixture::recorder($store ?? $this->store());
    }

    private function faulty_store(EVC_Faulty_Pdo $pdo, int $lock_wait = 5, ?callable $reconnect = null): EVC_Sql_Provenance_Store {
        return $this->store(new EVC_Club_Db($pdo), $lock_wait, $reconnect);
    }

    /** @return array<string,int> */
    private function rows(): array {
        $out = array();
        foreach (self::TABLES as $table) {
            $out[$table] = $this->count_rows($table);
        }
        return $out;
    }

    private function assertOutcome(EVC_Record_Outcome $o, string $outcome, ?string $reason = null): void {
        $this->assertSame($outcome, $o->outcome(), 'reason: ' . (string) $o->reason());
        if ($reason !== null) {
            $this->assertSame($reason, $o->reason());
        }
    }

    /** @param int[] $codes */
    private function assertRejected(callable $sql, array $codes, string $label): void {
        try {
            $sql();
            $this->fail('accepted: ' . $label);
        } catch (EVC_Db_Exception $e) {
            $this->assertContains($e->driver_code(), $codes, $label);
        }
    }

    private function assertConflict(callable $write, string $message): void {
        try {
            $write();
            $this->fail('store accepted: ' . $message);
        } catch (EVC_Provenance_Conflict_Exception $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    /** A raw PDO that holds row locks in an open transaction (simulated in-flight writer). */
    private function blocker(string $lock_sql, array $params): PDO {
        $pdo = $this->database->faulty_pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare($lock_sql);
        $stmt->execute($params);
        $stmt->fetchAll();
        $this->blockers[] = $pdo;
        return $pdo;
    }

    // ---- schema (migration 002) ------------------------------------------------

    public function test_migration_002_is_recorded_and_creates_the_six_tables(): void {
        $applied = (new EVC_Migrator($this->db, EVC_Test_Database::migrations_dir()))->applied();
        $this->assertSame(array(1, 2), array_keys($applied));
        $this->assertSame('payment_provenance', $applied[2]['name']);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, $this->count_rows($table), $table);
        }
        $this->assertSame(0, $this->count_rows('evc_redemptions'), 'redemption ledger untouched');
    }

    public function test_schema_constraints_bind_identity_chain_and_history(): void {
        $this->rec()->record(self::mv('oct', '2026-10-15 12:00:00'));
        $db = $this->db;
        $movement = function (string $label, int $user, string $row = 'row1', string $at = '2026-10-15 09:00:00.000000', string $state = 'confirmed') use ($db) {
            $db->execute(
                'INSERT INTO evc_pay_movements VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))',
                array(self::ref('mv:' . $label), 'live', $user, 7, self::ref($row), $at, 2000, 'EUR', 'gateway_api', 'wc_order', $state)
            );
        };
        $period = function (string $label, int $user, string $row, ?string $pred, string $kind) use ($db) {
            $db->execute(
                'INSERT INTO evc_pay_periods (movement_id, wp_user_id, level_id, pmpro_row_ref, start_utc, end_utc, kind, predecessor_movement_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                array(self::ref('mv:' . $label), $user, 7, self::ref($row), '2026-12-01 00:00:00.000000', '2027-01-01 00:00:00.000000', $kind, $pred === null ? null : self::ref('mv:' . $pred))
            );
        };
        $fk = array(1452);
        $dup = array(1062);
        $check = array(3819, 4025);

        // Correction A: a period must belong to the EXACT movement row (pmpro_row_ref).
        $movement('x', 42, 'row1', '2026-11-20 09:00:00.000000');
        $this->assertRejected(function () use ($period) { $period('x', 42, 'row9', 'oct', 'late'); }, $fk, 'period with another pmpro_row_ref');
        $this->assertRejected(function () use ($db) {
            $db->execute('INSERT INTO evc_pay_periods (movement_id, wp_user_id, level_id, pmpro_row_ref, start_utc, end_utc, kind, predecessor_movement_id) VALUES (?, 42, 8, ?, ?, ?, ?, ?)',
                array(self::ref('mv:x'), self::ref('row1'), '2026-12-01 00:00:00', '2027-01-01 00:00:00', 'late', self::ref('mv:oct')));
        }, $fk, 'period with another level');
        $this->assertRejected(function () use ($period) { $period('x', 43, 'row1', 'oct', 'late'); }, $fk, 'period of another member');
        // I4: one initial period per member; no two successors; predecessor of the same member.
        $this->assertRejected(function () use ($period) { $period('x', 42, 'row1', null, 'initial'); }, $dup, 'second initial period');
        $period('x', 42, 'row1', 'oct', 'late');
        $movement('y', 42, 'row1', '2026-11-21 09:00:00.000000');
        $this->assertRejected(function () use ($period) { $period('y', 42, 'row1', 'oct', 'late'); }, $dup, 'two successors of one period');
        $movement('z', 43, 'row1', '2026-11-21 09:00:00.000000');
        $this->assertRejected(function () use ($period) { $period('z', 43, 'row1', 'x', 'late'); }, $fk, 'predecessor of another member');
        $this->assertRejected(function () use ($db) {
            $db->execute('INSERT INTO evc_pay_member_heads VALUES (43, ?, 0, UTC_TIMESTAMP(6))', array(self::ref('mv:oct')));
        }, $fk, 'member head pointing at another member period');
        // I2: one order anchor per movement; an alias belongs to one movement.
        $this->assertRejected(function () use ($db) {
            $db->execute('INSERT INTO evc_pay_aliases (alias_namespace, alias_ref, movement_id, recorded_at_utc) VALUES (?, ?, ?, UTC_TIMESTAMP(6))', array('pmpro_order', self::ref('po:oct'), self::ref('mv:oct')));
        }, $dup, 'second order anchor');
        $this->assertRejected(function () use ($db) {
            $db->execute('INSERT INTO evc_pay_aliases (alias_namespace, alias_ref, movement_id, recorded_at_utc) VALUES (?, ?, ?, UTC_TIMESTAMP(6))', array('wc_order', self::ref('order:oct'), self::ref('mv:x')));
        }, $dup, 'alias reused by another movement');
        // CHECK constraints.
        $this->assertRejected(function () use ($movement) { $movement('frac', 44, 'row1', '2026-10-15 09:00:00.500000'); }, $check, 'fractional verification instant');
        $this->assertRejected(function () use ($movement) { $movement('pend', 44, 'row1', '2026-10-15 09:00:00.000000', 'pending'); }, $check, 'non-confirmed movement');
        $this->assertRejected(function () use ($db) {
            $db->execute('INSERT INTO evc_pay_movements VALUES (?, ?, 44, 7, ?, ?, 2000, ?, ?, ?, ?, UTC_TIMESTAMP(6))', array('abc', 'live', self::ref('row1'), '2026-10-15 09:00:00', 'EUR', 'gateway_api', 'wc_order', 'confirmed'));
        }, $check, 'short movement id');
        // I5/I8 history shape.
        $this->rec()->correct(self::corr('r1', 'oct', 'partially_refunded'));
        $this->assertRejected(function () use ($db) {
            $db->execute('INSERT INTO evc_pay_corrections VALUES (?, ?, 1, ?, ?, ?, NULL, UTC_TIMESTAMP(6))', array(self::ref('corr:r2'), self::ref('mv:oct'), 'refunded', 'gateway_api', '2026-10-21 00:00:00'));
        }, $dup, 'two corrections with the same sequence');
        $this->assertRejected(function () use ($db) {
            $db->execute('INSERT INTO evc_pay_corrections VALUES (?, ?, 2, ?, ?, ?, NULL, UTC_TIMESTAMP(6))', array(self::ref('corr:r3'), self::ref('mv:oct'), 'refunded', 'gateway_api', '2026-10-21 00:00:00'));
        }, $check, 'sequence 2 without a prior correction');
        $this->rec()->correct(self::corr('q1', 'other', 'refunded'));
        $this->assertRejected(function () use ($db) {
            $db->execute('INSERT INTO evc_pay_corrections VALUES (?, ?, 2, ?, ?, ?, ?, UTC_TIMESTAMP(6))', array(self::ref('corr:r4'), self::ref('mv:oct'), 'refunded', 'gateway_api', '2026-10-21 00:00:00', self::ref('corr:q1')));
        }, $fk, 'prior correction of another movement');
        $this->assertRejected(function () use ($db) {
            $db->execute('INSERT INTO evc_pay_corrections VALUES (?, ?, 1, ?, ?, ?, NULL, UTC_TIMESTAMP(6))', array(self::ref('corr:r5'), self::ref('mv:nohead'), 'refunded', 'gateway_api', '2026-10-21 00:00:00'));
        }, $fk, 'correction without a head row');
        $this->assertRejected(function () use ($db) {
            $db->execute('UPDATE evc_pay_correction_heads SET correction_count = 0 WHERE movement_id = ?', array(self::ref('mv:oct')));
        }, $check, 'inconsistent correction head');
        $this->assertRejected(function () use ($db) {
            $db->execute('DELETE FROM evc_pay_movements WHERE movement_id = ?', array(self::ref('mv:oct')));
        }, array(1451), 'deleting a movement with a period');
    }

    // ---- write shapes, identity and calendar re-check ----------------------------

    public function test_malformed_or_mixed_write_shapes_are_refused_without_writes(): void {
        $store = $this->store();
        $this->rec($store)->record(self::mv('oct', '2026-10-15 12:00:00'));
        $before = $this->rows();
        $nov = self::mv('nov', '2026-11-01 09:00:00');
        $good = function () use ($store, $nov) {
            return EVC_Provenance_Test_Fixture::new_movement_write($nov, $store->latest_period(42));
        };
        $cases = array(
            'empty write' => function () { return new EVC_Provenance_Write(); },
            'movement + correction' => function () use ($good) { $w = $good(); $w->correction = self::corr('c', 'nov', 'refunded'); return $w; },
            'movement without its aliases' => function () use ($good) { $w = $good(); array_pop($w->new_aliases); return $w; },
            'movement with a foreign alias' => function () use ($good) { $w = $good(); $w->new_aliases[] = new EVC_Movement_Alias('paypal_transaction', self::ref('pp:x')); return $w; },
            'alias owner differs' => function () use ($good) { $w = $good(); $w->alias_owner = self::ref('mv:oct'); return $w; },
            'period of another movement' => function () use ($good, $store) { $w = $good(); $w->new_period = $store->period_for_movement(self::ref('mv:oct')); return $w; },
            'movement expecting a recorded movement' => function () use ($good) { $w = $good(); $w->expected_movement_recorded = true; return $w; },
            'period without movement' => function () use ($good) { $w = $good(); $w->new_movement = null; $w->alias_owner = null; $w->new_aliases = array(); return $w; },
            'aliases + correction' => function () { $w = EVC_Provenance_Write::aliases_for(self::ref('mv:oct'), array(new EVC_Movement_Alias('paypal_transaction', self::ref('pp:oct')))); $w->correction = self::corr('c', 'oct', 'refunded'); return $w; },
            'correction with unresolved list' => function () { $w = EVC_Provenance_Write::correction(self::corr('c', 'oct', 'refunded'), null, true); $w->expected_unresolved = array(self::ref('corr:x')); return $w; },
            'non-alias object' => function () { $w = EVC_Provenance_Write::aliases_for(self::ref('mv:oct'), array()); $w->new_aliases = array('wc_order:' . self::ref('x')); return $w; },
            'non-hex owner' => function () { return EVC_Provenance_Write::aliases_for('mv-oct', array(new EVC_Movement_Alias('paypal_transaction', self::ref('pp:oct')))); },
            'non-hex unresolved id' => function () use ($good) { $w = $good(); $w->expected_unresolved = array('r1'); return $w; },
            'non-bool recorded flag' => function () { $w = EVC_Provenance_Write::correction(self::corr('c', 'oct', 'refunded'), null, true); $w->expected_movement_recorded = 1; return $w; },
        );
        foreach ($cases as $label => $make) {
            try {
                $store->commit($make());
                $this->fail('store accepted malformed write: ' . $label);
            } catch (EVC_Provenance_Store_Exception $e) {
                $this->assertNotInstanceOf(EVC_Provenance_Conflict_Exception::class, $e, $label);
                $this->assertStringContainsString('Malformed provenance write', $e->getMessage(), $label);
            }
        }
        $this->assertSame($before, $this->rows());
        $store->commit($good());
        $this->assertSame(2, $this->count_rows('evc_pay_periods'));
    }

    public function test_store_recomputes_calendar_and_binds_member_level_and_pmpro_row(): void {
        $store = $this->store();
        $this->rec($store)->record(self::mv('oct', '2026-10-15 12:00:00'));
        $latest = $store->latest_period(42);
        $nov = self::mv('nov', '2026-11-01 09:00:00');
        $good = EVC_Provenance_Test_Fixture::new_movement_write($nov, $latest)->new_period;
        $tamper = function (array $o) use ($good) {
            return new EVC_Funded_Period(
                $good->movement_id(),
                $o['user'] ?? $good->wp_user_id(),
                $o['level'] ?? $good->level_id(),
                $o['row'] ?? $good->pmpro_row_ref(),
                $o['start'] ?? $good->start_utc(),
                $o['end'] ?? $good->end_utc(),
                $o['kind'] ?? $good->kind(),
                $good->predecessor_movement_id()
            );
        };
        $before = $this->rows();
        $cases = array(
            array(array('end' => $good->end_utc()->modify('+1 second')), 'recomputed calendar period'),
            array(array('start' => $good->start_utc()->modify('-1 day')), 'recomputed calendar period'),
            array(array('kind' => 'late'), 'recomputed calendar period'),
            array(array('row' => self::ref('row9')), 'membership row'),
            array(array('level' => 8), 'membership row'),
            array(array('user' => 43), 'membership row'),
        );
        foreach ($cases as list($change, $message)) {
            $w = EVC_Provenance_Write::new_movement($nov, $tamper($change));
            $this->assertConflict(function () use ($store, $w) { $store->commit($w); }, $message);
        }
        // Chronology: a payment not later than every recorded one, and a wrong predecessor.
        $this->assertConflict(function () use ($store, $latest) {
            $store->commit(EVC_Provenance_Test_Fixture::new_movement_write(self::mv('sep', '2026-09-15 12:00:00'), $latest));
        }, 'not later than every recorded payment');
        $this->assertConflict(function () use ($store, $latest) {
            $store->commit(EVC_Provenance_Test_Fixture::new_movement_write(self::mv('same', '2026-10-15 12:00:00'), $latest));
        }, 'not later than every recorded payment');
        $this->assertConflict(function () use ($store, $nov) {
            $store->commit(EVC_Provenance_Test_Fixture::new_movement_write($nov, null));
        }, 'compare-and-set');
        // Facts the store refuses on its own: other environment, unconfirmed movement.
        foreach (array(array('env' => 'sandbox'), array('state' => 'pending')) as $o) {
            $this->assertConflict(function () use ($store, $latest, $o) {
                $store->commit(EVC_Provenance_Test_Fixture::new_movement_write(self::mv('odd', '2026-11-02 09:00:00', $o), $latest));
            }, 'confirmed fact of this environment');
        }
        $this->assertSame($before, $this->rows());
        $store->commit(EVC_Provenance_Write::new_movement($nov, $good));
        $this->assertSame(self::ref('mv:nov'), $store->latest_period(42)->movement_id());
    }

    public function test_commit_time_I1_I2_I7_I8_guards(): void {
        $store = $this->store();
        $oct = self::mv('oct', '2026-10-15 12:00:00');
        $w = EVC_Provenance_Test_Fixture::new_movement_write($oct, null);
        $store->commit($w);
        $this->assertConflict(function () use ($store, $oct) {
            $store->commit(EVC_Provenance_Test_Fixture::new_movement_write($oct, $store->latest_period(42)));
        }, 'I1');
        $this->assertConflict(function () use ($store) {
            $store->commit(EVC_Provenance_Write::aliases_for(self::ref('mv:ghost'), array(new EVC_Movement_Alias('paypal_transaction', self::ref('pp:g')))));
        }, 'I2 aliases for an unknown movement');
        $store->commit(EVC_Provenance_Test_Fixture::new_movement_write(self::mv('nov', '2026-11-01 09:00:00'), $store->latest_period(42)));
        $this->assertConflict(function () use ($store) {
            $store->commit(EVC_Provenance_Write::aliases_for(self::ref('mv:nov'), array(new EVC_Movement_Alias('viva_transaction', self::ref('viva:oct')))));
        }, 'I2 alias belongs to another movement');
        // I7: an unresolved refund blocks an ordinary write of that movement.
        $store->commit(EVC_Provenance_Write::correction(self::corr('r1', 'dec', 'refunded'), null, false));
        $dec = self::mv('dec', '2026-12-01 09:00:00');
        $this->assertConflict(function () use ($store, $dec) {
            $store->commit(EVC_Provenance_Test_Fixture::new_movement_write($dec, $store->latest_period(42)));
        }, 'I7');
        $this->assertConflict(function () use ($store, $dec) {
            $store->commit(EVC_Provenance_Test_Fixture::new_movement_write($dec, $store->latest_period(42), array(self::ref('corr:other'))));
        }, 'I7');
        // I8 at commit time.
        $store->commit(EVC_Provenance_Write::correction(self::corr('f1', 'oct', 'refunded'), null, true));
        $this->assertConflict(function () use ($store) {
            $store->commit(EVC_Provenance_Write::correction(self::corr('p9', 'oct', 'partially_refunded'), self::ref('corr:f1'), true));
        }, 'forbidden correction transition');
        $this->assertConflict(function () use ($store) {
            $store->commit(EVC_Provenance_Write::correction(self::corr('v9', 'oct', 'voided'), null, true));
        }, 'compare-and-set');
        $this->assertConflict(function () use ($store) {
            $store->commit(EVC_Provenance_Write::correction(self::corr('v9', 'oct', 'voided'), self::ref('corr:f1'), false));
        }, 'recorded state');
        $this->assertConflict(function () use ($store) {
            $store->commit(EVC_Provenance_Write::correction(self::corr('f1', 'oct', 'refunded'), self::ref('corr:f1'), true));
        }, 'I5');
        $this->assertSame(array('refunded'), array_map(function ($c) { return $c->kind(); }, $store->corrections_for(self::ref('mv:oct'))));
        // Trusted reconciliation acknowledges exactly and attaches the held refund.
        $store->commit(EVC_Provenance_Test_Fixture::new_movement_write($dec, $store->latest_period(42), array(self::ref('corr:r1'))));
        $this->assertSame(array(), $store->unresolved_corrections());
    }

    // ---- atomicity, deadlock, lock wait --------------------------------------------

    public function test_failure_after_partial_statements_rolls_back_everything(): void {
        foreach (array('/^INSERT INTO evc_pay_periods/', '/^UPDATE evc_pay_member_heads/', '/^INSERT INTO evc_pay_aliases/') as $fault) {
            $pdo = $this->database->faulty_pdo();
            $pdo->fail_prepare_matching = $fault;
            $o = $this->rec($this->faulty_store($pdo))->record(self::mv('oct', '2026-10-15 12:00:00'));
            $this->assertOutcome($o, EVC_Record_Outcome::UNAVAILABLE, 'store_unavailable');
            $this->assertSame(array_fill_keys(self::TABLES, 0), $this->rows(), 'nothing persisted after fault ' . $fault);
        }
        $this->rec()->correct(self::corr('r1', 'oct', 'refunded'));
        $quarantine = $this->rows();
        $pdo = $this->database->faulty_pdo();
        $pdo->fail_prepare_matching = '/^INSERT INTO evc_pay_periods/';
        $o = $this->rec($this->faulty_store($pdo))->reconcile(new EVC_Movement_Reconciliation(self::mv('oct', '2026-10-15 12:00:00'), array(self::ref('corr:r1'))));
        $this->assertOutcome($o, EVC_Record_Outcome::UNAVAILABLE);
        $this->assertSame($quarantine, $this->rows(), 'quarantine intact, no partial reconciliation');
        $pdo = $this->database->faulty_pdo();
        $pdo->fail_prepare_matching = '/^UPDATE evc_pay_correction_heads/';
        $this->assertOutcome($this->rec($this->faulty_store($pdo))->correct(self::corr('x2', 'oct2', 'refunded')), EVC_Record_Outcome::UNAVAILABLE);
        $this->assertSame($quarantine, $this->rows(), 'no orphan correction or head');
    }

    public function test_deadlock_is_retried_a_bounded_number_of_times(): void {
        $pdo = $this->database->faulty_pdo();
        $pdo->deadlock_prepare_matching = '/^INSERT INTO evc_pay_movements/';
        $pdo->deadlocks_remaining = 2;
        $this->assertOutcome($this->rec($this->faulty_store($pdo))->record(self::mv('oct', '2026-10-15 12:00:00')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertSame(1, $this->count_rows('evc_pay_periods'));
        $this->assertSame(0, $pdo->deadlocks_remaining);

        $pdo = $this->database->faulty_pdo();
        $pdo->deadlock_prepare_matching = '/^INSERT INTO evc_pay_corrections/';
        $pdo->deadlocks_remaining = -1;
        $this->assertOutcome($this->rec($this->faulty_store($pdo))->correct(self::corr('r1', 'oct', 'refunded')), EVC_Record_Outcome::UNAVAILABLE, 'store_unavailable');
        $this->assertSame(0, $this->count_rows('evc_pay_corrections'));
        $this->assertSame(1, $this->count_rows('evc_pay_periods'));
    }

    public function test_real_lock_wait_timeout_is_bounded_and_writes_nothing(): void {
        $this->rec()->record(self::mv('oct', '2026-10-15 12:00:00'));
        $blocker = $this->blocker('SELECT * FROM evc_pay_member_heads WHERE wp_user_id = ? FOR UPDATE', array(42));
        $before = $this->rows();
        $started = microtime(true);
        $this->assertOutcome($this->rec($this->store(null, 1))->record(self::mv('nov', '2026-11-01 09:00:00')), EVC_Record_Outcome::UNAVAILABLE, 'store_unavailable');
        $this->assertGreaterThanOrEqual(2.5, microtime(true) - $started, 'three lock-wait attempts');
        $this->assertSame($before, $this->rows());
        $corr_blocker = $this->blocker('SELECT * FROM evc_pay_correction_heads WHERE movement_id = ? FOR UPDATE', array(self::ref('mv:oct')));
        $this->assertOutcome($this->rec($this->store(null, 1))->correct(self::corr('r1', 'oct', 'refunded')), EVC_Record_Outcome::UNAVAILABLE);
        $blocker->rollBack();
        $corr_blocker->rollBack();
        $this->assertOutcome($this->rec()->record(self::mv('nov', '2026-11-01 09:00:00')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertOutcome($this->rec()->correct(self::corr('r1', 'oct', 'refunded')), EVC_Record_Outcome::NEWLY_RECORDED);
    }

    // ---- COMMIT outcome unknown --------------------------------------------------------

    public function test_lost_commit_response_is_recovered_as_committed_for_every_write_shape(): void {
        $lost = function () {
            $pdo = $this->database->faulty_pdo();
            $pdo->fail_commit_after = true;
            return $this->rec($this->faulty_store($pdo));
        };
        $this->assertOutcome($lost()->record(self::mv('oct', '2026-10-15 12:00:00')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertOutcome($lost()->record(self::mv('nov', '2026-11-01 09:00:00')), EVC_Record_Outcome::NEWLY_RECORDED);
        $more = self::mv('oct', '2026-10-15 12:00:00', array('aliases' => array(
            new EVC_Movement_Alias('wc_order', self::ref('order:oct')),
            new EVC_Movement_Alias('paypal_transaction', self::ref('pp:oct')),
        )));
        $this->assertOutcome($lost()->record($more), EVC_Record_Outcome::ALREADY_RECORDED);
        $this->assertOutcome($lost()->correct(self::corr('p1', 'oct', 'partially_refunded')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertOutcome($lost()->correct(self::corr('q1', 'dec', 'refunded')), EVC_Record_Outcome::QUARANTINED);
        $this->assertOutcome($lost()->reconcile(new EVC_Movement_Reconciliation(self::mv('dec', '2026-12-01 09:00:00'), array(self::ref('corr:q1')))), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertSame(array(
            'evc_pay_movements' => 3, 'evc_pay_aliases' => 7, 'evc_pay_periods' => 3,
            'evc_pay_member_heads' => 1, 'evc_pay_correction_heads' => 3, 'evc_pay_corrections' => 2,
        ), $this->rows());
        // Retries after a recovered commit never add a second period.
        for ($i = 0; $i < 3; $i++) {
            $this->assertOutcome($this->rec()->record(self::mv('nov', '2026-11-01 09:00:00')), EVC_Record_Outcome::ALREADY_RECORDED);
            $this->assertOutcome($this->rec()->correct(self::corr('p1', 'oct', 'partially_refunded')), EVC_Record_Outcome::ALREADY_RECORDED);
        }
        $this->assertSame(3, $this->count_rows('evc_pay_periods'));
        $this->assertSame(self::ref('pp:oct'), substr((string) $this->db->fetch_one('SELECT alias_ref FROM evc_pay_aliases WHERE alias_namespace = ?', array('paypal_transaction'))['alias_ref'], 0, 64));
    }

    public function test_commit_failure_without_commit_is_reported_and_safely_retried(): void {
        $pdo = $this->database->faulty_pdo();
        $pdo->fail_commit_before = true;
        $this->assertOutcome($this->rec($this->faulty_store($pdo))->record(self::mv('oct', '2026-10-15 12:00:00')), EVC_Record_Outcome::UNAVAILABLE, 'store_unavailable');
        $this->assertSame(array_fill_keys(self::TABLES, 0), $this->rows());
        $this->assertOutcome($this->rec()->record(self::mv('oct', '2026-10-15 12:00:00')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertSame(1, $this->count_rows('evc_pay_periods'));
    }

    public function test_inconclusive_recovery_reports_unknown_never_rollback(): void {
        $this->rec()->record(self::mv('oct', '2026-10-15 12:00:00'));
        // The original transaction may still be open on the server: recovery
        // must wait for its locks, and give up as UNKNOWN when it cannot.
        $pdo = $this->database->faulty_pdo();
        $pdo->fail_commit_before = true;
        $reconnect = function () {
            $this->blocker('SELECT * FROM evc_pay_member_heads WHERE wp_user_id = ? FOR UPDATE', array(42));
            return $this->database->connect();
        };
        $before = $this->rows();
        $o = $this->rec($this->faulty_store($pdo, 1, $reconnect))->record(self::mv('nov', '2026-11-01 09:00:00'));
        $this->assertOutcome($o, EVC_Record_Outcome::UNAVAILABLE, 'commit_outcome_unknown');
        $this->assertSame($before, $this->rows());
        foreach ($this->blockers as $b) {
            $b->rollBack();
        }
        // Recovery impossible because the database cannot be reached.
        $pdo = $this->database->faulty_pdo();
        $pdo->fail_commit_before = true;
        $down = function () {
            throw new EVC_Db_Exception('Club database is unavailable.');
        };
        $this->assertOutcome($this->rec($this->faulty_store($pdo, 1, $down))->correct(self::corr('r1', 'oct', 'refunded')), EVC_Record_Outcome::UNAVAILABLE, 'commit_outcome_unknown');
        // The same write retried later is resolved idempotently, once.
        $this->assertOutcome($this->rec()->record(self::mv('nov', '2026-11-01 09:00:00')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertOutcome($this->rec()->record(self::mv('nov', '2026-11-01 09:00:00')), EVC_Record_Outcome::ALREADY_RECORDED);
        $this->assertSame(2, $this->count_rows('evc_pay_periods'));
    }

    public function test_recovery_reports_conflict_when_stored_facts_differ(): void {
        // COMMIT "fails" and recovery finds a DIFFERENT committed correction under the id.
        $this->rec()->record(self::mv('oct', '2026-10-15 12:00:00'));
        $pdo = $this->database->faulty_pdo();
        $pdo->fail_commit_before = true;
        $reconnect = function () {
            $this->rec()->correct(self::corr('r1', 'oct', 'reversed'));
            return $this->database->connect();
        };
        $o = $this->rec($this->faulty_store($pdo, 5, $reconnect))->correct(self::corr('r1', 'oct', 'refunded'));
        $this->assertOutcome($o, EVC_Record_Outcome::CONFLICT, 'concurrent_update');
        $this->assertSame('reversed', $this->store()->correction(self::ref('corr:r1'))->kind());
    }

    // ---- readiness, availability, drift -----------------------------------------------

    public function test_store_refuses_a_database_without_migration_002(): void {
        $only001 = sys_get_temp_dir() . '/evc_mig_' . bin2hex(random_bytes(4));
        mkdir($only001);
        copy(EVC_Test_Database::migrations_dir() . '/001_initial.sql', $only001 . '/001_initial.sql');
        $old = EVC_Test_Database::create();
        try {
            (new EVC_Migrator($old->connect(), $only001))->migrate();
            $store = EVC_Provenance_Test_Fixture::sql_store($old->config());
            $this->assertOutcome($this->rec($store)->record(self::mv('oct', '2026-10-15 12:00:00')), EVC_Record_Outcome::UNAVAILABLE);
            try {
                $store->movement(self::ref('mv:oct'));
                $this->fail('read from an unmigrated database');
            } catch (EVC_Provenance_Store_Exception $e) {
                $this->assertStringContainsString('older than migration 002', $e->getMessage());
            }
        } finally {
            $old->drop();
            unlink($only001 . '/001_initial.sql');
            rmdir($only001);
        }
    }

    public function test_modified_migration_002_is_detected_as_drift(): void {
        $copy = sys_get_temp_dir() . '/evc_mig_' . bin2hex(random_bytes(4));
        mkdir($copy);
        foreach (array('001_initial.sql', '002_payment_provenance.sql') as $file) {
            copy(EVC_Test_Database::migrations_dir() . '/' . $file, $copy . '/' . $file);
        }
        try {
            file_put_contents($copy . '/002_payment_provenance.sql', "\n-- edited after it ran\n", FILE_APPEND);
            $this->expectException(EVC_Migration_Exception::class);
            $this->expectExceptionMessage('Schema drift: applied migration 002 was modified after it ran.');
            (new EVC_Migrator($this->db, $copy))->migrate();
        } finally {
            foreach (glob($copy . '/*.sql') as $f) {
                unlink($f);
            }
            rmdir($copy);
        }
    }

    public function test_unavailable_database_never_reports_success(): void {
        $this->rec()->record(self::mv('oct', '2026-10-15 12:00:00'));
        $pdo = $this->database->faulty_pdo();
        $store = $this->faulty_store($pdo);
        $store->movement(self::ref('mv:oct')); // ready
        $pdo->fail_prepare_matching = '/./';
        $r = $this->rec($store);
        $this->assertOutcome($r->record(self::mv('nov', '2026-11-01 09:00:00')), EVC_Record_Outcome::UNAVAILABLE);
        $this->assertOutcome($r->correct(self::corr('r1', 'oct', 'refunded')), EVC_Record_Outcome::UNAVAILABLE);
        $this->assertOutcome($r->reconcile(new EVC_Movement_Reconciliation(self::mv('nov', '2026-11-01 09:00:00'), array(self::ref('corr:r1')))), EVC_Record_Outcome::UNAVAILABLE);
        $this->assertSame(1, $this->count_rows('evc_pay_periods'));
        $this->assertSame(0, $this->count_rows('evc_pay_corrections'));
    }

    // ---- least privilege and opaque identifiers ---------------------------------------

    public function test_least_privilege_runtime_user(): void {
        $user = 'evc_rt_' . bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(16));
        $this->users[] = $user;
        $admin = EVC_Test_Database::admin_pdo();
        $admin->exec("CREATE USER '" . $user . "'@'%' IDENTIFIED BY '" . $password . "'");
        foreach (EVC_Sql_Provenance_Store::RUNTIME_GRANTS as $table => $privileges) {
            $admin->exec('GRANT ' . $privileges . ' ON `' . $this->database->name() . '`.`' . $table . "` TO '" . $user . "'@'%'");
        }
        $config = new EVC_Db_Config(EVC_Test_Database::host(), EVC_Test_Database::port(), $this->database->name(), $user, $password);
        $runtime = EVC_Club_Db::connect($config);
        $r = EVC_Provenance_Test_Fixture::recorder(EVC_Provenance_Test_Fixture::sql_store($config, 5, $runtime));
        // Every store operation works with the minimal grants.
        $this->assertOutcome($r->record(self::mv('oct', '2026-10-15 12:00:00')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertOutcome($r->record(self::mv('nov', '2026-11-01 09:00:00')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertOutcome($r->correct(self::corr('r1', 'oct', 'partially_refunded')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertOutcome($r->correct(self::corr('q1', 'dec', 'refunded')), EVC_Record_Outcome::QUARANTINED);
        $this->assertOutcome($r->reconcile(new EVC_Movement_Reconciliation(self::mv('dec', '2026-12-01 09:00:00'), array(self::ref('corr:q1')))), EVC_Record_Outcome::NEWLY_RECORDED);
        // ...and the append-only history cannot be rewritten by the runtime user.
        $denied = array(1142, 1143);
        foreach (array(
            'UPDATE evc_pay_movements SET amount_minor = 1',
            'DELETE FROM evc_pay_corrections',
            'UPDATE evc_pay_periods SET end_utc = end_utc',
            'DELETE FROM evc_pay_aliases',
            'DELETE FROM evc_pay_correction_heads',
            'DELETE FROM evc_pay_member_heads',
            'DROP TABLE evc_pay_corrections',
            'SELECT COUNT(*) AS n FROM evc_redemptions',
        ) as $sql) {
            $this->assertRejected(function () use ($runtime, $sql) { $runtime->fetch_all($sql); }, $denied, $sql);
        }
        $this->assertSame(3, $this->count_rows('evc_pay_periods'));
        $this->assertSame(2, $this->count_rows('evc_pay_corrections'));
    }

    public function test_opaque_hmac_identifiers_round_trip_exactly(): void {
        $secret = str_repeat('k', 32);
        $order = EVC_Evidence_Ref::hmac($secret, 'wc_order', 'synthetic-order-1001');
        $txn = EVC_Evidence_Ref::hmac($secret, 'viva_transaction', 'synthetic-txn-77');
        $id = EVC_Evidence_Ref::hmac($secret, 'movement', 'synthetic-order-1001');
        $m = self::mv('h', '2026-10-15 12:00:00', array('id' => $id, 'aliases' => array(
            new EVC_Movement_Alias('wc_order', $order),
            new EVC_Movement_Alias('viva_transaction', $txn),
        )));
        $store = $this->store();
        $this->assertOutcome($this->rec($store)->record($m), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertSame($id, $store->movement_for_alias('wc_order:' . $order));
        $this->assertSame($id, $store->movement_for_alias('viva_transaction:' . $txn));
        $this->assertNull($store->movement_for_alias('wc_order:' . strtoupper($order)), 'binary collation: no case folding');
        $this->assertNull($store->movement_for_alias('wc_order:' . EVC_Evidence_Ref::hmac(str_repeat('j', 32), 'wc_order', 'synthetic-order-1001')), 'another key gives another reference');
        $this->assertTrue($store->movement($id)->same_facts_as($m));
        $raw = $this->db->fetch_all('SELECT alias_ref FROM evc_pay_aliases');
        foreach ($raw as $row) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', (string) $row['alias_ref']);
            $this->assertStringNotContainsString('synthetic', (string) $row['alias_ref']);
        }
    }
}
