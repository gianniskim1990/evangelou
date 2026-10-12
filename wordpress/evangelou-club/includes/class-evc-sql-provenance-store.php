<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Transactional MySQL/MariaDB implementation of EVC_Provenance_Store
 * (Task 1D-G) on the SEPARATE Club database (migration 002).
 *
 * NOT WIRED: nothing in the plugin constructs this class. It is exercised
 * only by tests against disposable databases. No payment, PMPro or
 * WooCommerce code calls it and the redemption backend is unchanged.
 *
 * Every invariant I1-I8 is re-validated INSIDE one InnoDB transaction while
 * the relevant lock rows are held; the recorder's earlier reads are only
 * advisory. The session runs READ COMMITTED (EVC_Club_Db), where a locking
 * read of a missing row locks nothing, so lock rows are created first with
 * a no-op INSERT ... ON DUPLICATE KEY UPDATE and then read FOR UPDATE.
 *
 * Global lock order (no transaction ever takes them in another order):
 *   1. evc_pay_member_heads row of the member      (new movement only)
 *   2. evc_pay_correction_heads row of the movement (every write)
 *   3. inserts: movement, aliases (sorted by key), period, correction
 *   4. compare-and-set UPDATE of the head row(s), then COMMIT.
 *
 * Error classification:
 *   precondition / CAS / duplicate key / FK (1452)   -> EVC_Provenance_Conflict_Exception
 *   deadlock (1213) / lock wait (1205)               -> whole write retried (bounded), then store exception
 *   CHECK violation (3819 MySQL, 4025 MariaDB), other -> EVC_Provenance_Store_Exception
 *   failed COMMIT                                     -> read-back recovery on a FRESH connection under
 *                                                        the same lock rows: committed -> success; absent
 *                                                        -> store exception (definitely not committed);
 *                                                        inconclusive -> EVC_Provenance_Commit_Unknown_Exception
 * No retry or recovery path can create a second period: every attempt
 * re-runs the same locked validation (I1 PK, I4 compare-and-set).
 */
final class EVC_Sql_Provenance_Store implements EVC_Provenance_Store {
    const MIN_SCHEMA_VERSION = 2;
    const MAX_ATTEMPTS = 3;

    const SHAPE_NEW_MOVEMENT = 'new_movement';
    const SHAPE_ALIASES = 'aliases';
    const SHAPE_CORRECTION = 'correction';

    const ER_NO_REFERENCED_ROW = 1452;
    const ER_ROW_IS_REFERENCED = 1451;
    const ER_CHECK_MYSQL = 3819;
    const ER_CHECK_MARIADB = 4025;

    /**
     * Least-privilege grants for the RUNTIME database user of this store
     * (table => privileges). History tables get no UPDATE/DELETE, so I5 holds
     * even against a bug in this class; only the two lock-row tables are
     * updatable. Migrations use a separate DDL user. Proven by
     * SqlProvenanceStoreTest::test_least_privilege_runtime_user.
     */
    const RUNTIME_GRANTS = array(
        'evc_schema_migrations' => 'SELECT',
        'evc_pay_movements' => 'SELECT, INSERT',
        'evc_pay_aliases' => 'SELECT, INSERT',
        'evc_pay_periods' => 'SELECT, INSERT',
        'evc_pay_corrections' => 'SELECT, INSERT',
        'evc_pay_member_heads' => 'SELECT, INSERT, UPDATE',
        'evc_pay_correction_heads' => 'SELECT, INSERT, UPDATE',
    );

    const RECOVERY_COMMITTED = 'committed';
    const RECOVERY_ABSENT = 'absent';
    const RECOVERY_DIFFERENT = 'different';

    /** @var EVC_Club_Db */
    private $db;
    /** @var callable(): EVC_Club_Db opens a FRESH connection (unknown-commit recovery) */
    private $reconnect;
    /** @var string */
    private $environment;
    /** @var int */
    private $lock_wait_seconds;
    /** @var bool */
    private $ready = false;

    /**
     * @param callable $reconnect   function (): EVC_Club_Db, a NEW connection to the same database
     * @param string   $environment the only environment this database may hold (e.g. "live")
     */
    public function __construct(EVC_Club_Db $db, callable $reconnect, string $environment, int $lock_wait_seconds = 5) {
        if (!preg_match(EVC_Verified_Movement::ENVIRONMENT_PATTERN, $environment) || $lock_wait_seconds < 1 || $lock_wait_seconds > 60) {
            throw new InvalidArgumentException('Invalid SQL provenance store configuration.');
        }
        $this->db = $db;
        $this->reconnect = $reconnect;
        $this->environment = $environment;
        $this->lock_wait_seconds = $lock_wait_seconds;
    }

    // ---- reads (outside transactions; advisory for the recorder) -------------

    public function movement(string $movement_id): ?EVC_Verified_Movement {
        return $this->read(function (EVC_Club_Db $db) use ($movement_id) {
            return self::load_movement($db, $movement_id);
        });
    }

    public function movement_for_alias(string $alias_key): ?string {
        $parts = explode(':', $alias_key, 2);
        if (count($parts) !== 2) {
            return null;
        }
        return $this->read(function (EVC_Club_Db $db) use ($parts) {
            $row = $db->fetch_one('SELECT movement_id FROM evc_pay_aliases WHERE alias_namespace = ? AND alias_ref = ?', array($parts[0], $parts[1]));
            return $row === null ? null : (string) $row['movement_id'];
        });
    }

    public function member_movements(int $wp_user_id): array {
        return $this->read(function (EVC_Club_Db $db) use ($wp_user_id) {
            $out = array();
            foreach ($db->fetch_all('SELECT movement_id FROM evc_pay_movements WHERE wp_user_id = ? ORDER BY verified_at_utc, movement_id', array($wp_user_id)) as $row) {
                $out[] = self::load_movement($db, (string) $row['movement_id']);
            }
            return $out;
        });
    }

    public function latest_period(int $wp_user_id): ?EVC_Funded_Period {
        return $this->read(function (EVC_Club_Db $db) use ($wp_user_id) {
            $row = $db->fetch_one('SELECT latest_movement_id FROM evc_pay_member_heads WHERE wp_user_id = ?', array($wp_user_id));
            return ($row === null || $row['latest_movement_id'] === null) ? null : self::load_period($db, (string) $row['latest_movement_id']);
        });
    }

    public function period_for_movement(string $movement_id): ?EVC_Funded_Period {
        return $this->read(function (EVC_Club_Db $db) use ($movement_id) {
            return self::load_period($db, $movement_id);
        });
    }

    public function correction(string $correction_id): ?EVC_Movement_Correction {
        return $this->read(function (EVC_Club_Db $db) use ($correction_id) {
            $row = $db->fetch_one('SELECT * FROM evc_pay_corrections WHERE correction_id = ?', array($correction_id));
            return $row === null ? null : self::hydrate_correction($row);
        });
    }

    public function corrections_for(string $movement_id): array {
        return $this->read(function (EVC_Club_Db $db) use ($movement_id) {
            return self::load_corrections($db, $movement_id);
        });
    }

    public function unresolved_corrections(): array {
        return $this->read(function (EVC_Club_Db $db) {
            $out = array();
            $rows = $db->fetch_all(
                'SELECT h.movement_id FROM evc_pay_correction_heads h LEFT JOIN evc_pay_movements m ON m.movement_id = h.movement_id'
                . ' WHERE h.correction_count > 0 AND m.movement_id IS NULL ORDER BY h.movement_id'
            );
            foreach ($rows as $row) {
                $out[(string) $row['movement_id']] = self::load_corrections($db, (string) $row['movement_id']);
            }
            return $out;
        });
    }

    // ---- commit ---------------------------------------------------------------

    public function commit(EVC_Provenance_Write $w): void {
        $shape = self::shape_of($w);
        $this->ensure_ready();
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->db->transactional(function (EVC_Club_Db $db) use ($w, $shape) {
                    $this->apply($db, $w, $shape);
                });
                return;
            } catch (EVC_Provenance_Store_Exception $e) {
                throw $e; // conflict or invariant breach found inside the transaction (already rolled back)
            } catch (EVC_Db_Commit_Unknown_Exception $e) {
                $this->recover($w, $shape);
                return;
            } catch (EVC_Db_Retryable_Exception $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new EVC_Provenance_Store_Exception('Provenance write still deadlocked or lock-waiting after retries; nothing written.');
                }
            } catch (EVC_Db_Duplicate_Key_Exception $e) {
                throw new EVC_Provenance_Conflict_Exception('Duplicate key ' . (string) $e->key_name() . ' (concurrent writer).');
            } catch (EVC_Db_Exception $e) {
                throw self::classify($e);
            }
        }
    }

    /**
     * Validates that the mutable write object is exactly ONE permitted shape
     * with consistent object relationships; anything else is refused before
     * a transaction starts.
     */
    public static function shape_of(EVC_Provenance_Write $w): string {
        $bad = function (string $why) {
            return new EVC_Provenance_Store_Exception('Malformed provenance write: ' . $why . '.');
        };
        if (!is_array($w->new_aliases) || !is_array($w->expected_unresolved) || !is_bool($w->expected_movement_recorded)) {
            throw $bad('field types');
        }
        foreach ($w->new_aliases as $alias) {
            if (!$alias instanceof EVC_Movement_Alias) {
                throw $bad('alias type');
            }
        }
        foreach ($w->expected_unresolved as $id) {
            if (!is_string($id) || !preg_match(EVC_Verified_Movement::ID_PATTERN, $id)) {
                throw $bad('unresolved id');
            }
        }
        if (count(array_unique($w->expected_unresolved)) !== count($w->expected_unresolved)) {
            throw $bad('duplicate unresolved id');
        }
        foreach (array('expected_predecessor', 'alias_owner', 'expected_prior_correction') as $field) {
            if ($w->$field !== null && (!is_string($w->$field) || !preg_match(EVC_Verified_Movement::ID_PATTERN, $w->$field))) {
                throw $bad($field);
            }
        }

        if ($w->new_movement !== null) {
            $m = $w->new_movement;
            $p = $w->new_period;
            if (!$m instanceof EVC_Verified_Movement || !$p instanceof EVC_Funded_Period || $w->correction !== null
                || $w->expected_prior_correction !== null || $w->expected_movement_recorded !== false) {
                throw $bad('mixed new-movement write');
            }
            if ($w->alias_owner !== $m->movement_id() || self::alias_keys($w->new_aliases) !== array_keys($m->aliases())) {
                throw $bad('aliases must be exactly the movement aliases');
            }
            if ($p->movement_id() !== $m->movement_id() || $p->predecessor_movement_id() !== $w->expected_predecessor) {
                throw $bad('period identity');
            }
            if (count($m->order_anchors()) !== 1) {
                throw $bad('exactly one order anchor');
            }
            return self::SHAPE_NEW_MOVEMENT;
        }
        if ($w->new_period !== null || $w->expected_predecessor !== null || $w->expected_unresolved !== array()) {
            throw $bad('period without movement');
        }
        if ($w->correction !== null) {
            if (!$w->correction instanceof EVC_Movement_Correction || $w->alias_owner !== null || $w->new_aliases !== array()) {
                throw $bad('mixed correction write');
            }
            return self::SHAPE_CORRECTION;
        }
        if ($w->alias_owner === null || $w->new_aliases === array() || $w->expected_prior_correction !== null || $w->expected_movement_recorded !== false) {
            throw $bad('empty or mixed alias write');
        }
        return self::SHAPE_ALIASES;
    }

    private function apply(EVC_Club_Db $db, EVC_Provenance_Write $w, string $shape): void {
        if ($shape === self::SHAPE_NEW_MOVEMENT) {
            $this->apply_new_movement($db, $w);
        } elseif ($shape === self::SHAPE_ALIASES) {
            $this->apply_aliases($db, $w->alias_owner, $w->new_aliases);
        } else {
            $this->apply_correction($db, $w);
        }
    }

    private function apply_new_movement(EVC_Club_Db $db, EVC_Provenance_Write $w): void {
        $m = $w->new_movement;
        $p = $w->new_period;
        $id = $m->movement_id();
        $user = $m->wp_user_id();

        // 1. member chain head (lock row), I4 compare-and-set.
        $head = self::lock_member_head($db, $user);
        $latest = $head['latest_movement_id'] === null ? null : (string) $head['latest_movement_id'];
        if ($latest !== $w->expected_predecessor) {
            throw new EVC_Provenance_Conflict_Exception('I4 member chain changed (compare-and-set failed).');
        }
        // 2. movement correction head (lock row): I1 and I7 under lock.
        self::lock_correction_head($db, $id);
        if ($db->fetch_one('SELECT 1 AS x FROM evc_pay_movements WHERE movement_id = ?', array($id)) !== null) {
            throw new EVC_Provenance_Conflict_Exception('I1 movement already recorded.');
        }
        if (self::correction_ids($db, $id) !== array_values($w->expected_unresolved)) {
            throw new EVC_Provenance_Conflict_Exception('I7 unresolved corrections not acknowledged exactly.');
        }
        // Facts the store itself requires (never trust the caller's arithmetic).
        if ($m->state() !== EVC_Verified_Movement::STATE_CONFIRMED || $m->environment() !== $this->environment) {
            throw new EVC_Provenance_Conflict_Exception('Movement is not a confirmed fact of this environment.');
        }
        $previous = null;
        if ($latest !== null) {
            $previous = $db->fetch_one('SELECT end_utc FROM evc_pay_periods WHERE movement_id = ? AND wp_user_id = ?', array($latest, $user));
            if ($previous === null) {
                throw new EVC_Provenance_Store_Exception('Member head points to a missing period.');
            }
        }
        $max = $db->fetch_one('SELECT MAX(verified_at_utc) AS t FROM evc_pay_movements WHERE wp_user_id = ?', array($user));
        if ($max !== null && $max['t'] !== null && self::instant((string) $max['t']) >= $m->verified_at_utc()) {
            throw new EVC_Provenance_Conflict_Exception('I4 payment is not later than every recorded payment of the member.');
        }
        $next = EVC_Membership_Calendar::next_period($previous === null ? null : self::instant((string) $previous['end_utc']), $m->verified_at_utc());
        $kind = $latest === null ? EVC_Funded_Period::KIND_INITIAL : $next['renewal'];
        if ($p->start_utc() != $next['start'] || $p->end_utc() != $next['end'] || $p->kind() !== $kind) {
            throw new EVC_Provenance_Conflict_Exception('I4 period does not match the recomputed calendar period.');
        }
        if ($p->wp_user_id() !== $user || $p->level_id() !== $m->level_id() || $p->pmpro_row_ref() !== $m->pmpro_row_ref()) {
            throw new EVC_Provenance_Conflict_Exception('I3 period does not belong to the movement member, level and membership row.');
        }

        // 3. inserts.
        $db->execute(
            'INSERT INTO evc_pay_movements (movement_id, environment, wp_user_id, level_id, pmpro_row_ref, verified_at_utc, amount_minor, currency,'
            . ' authority, evidence_kind, state, recorded_at_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))',
            array($id, $m->environment(), $user, $m->level_id(), $m->pmpro_row_ref(), self::sql_time($m->verified_at_utc()),
                $m->amount_minor(), $m->currency(), $m->authority(), $m->evidence_kind(), $m->state())
        );
        self::insert_aliases($db, $id, $w->new_aliases);
        $db->execute(
            'INSERT INTO evc_pay_periods (movement_id, wp_user_id, level_id, pmpro_row_ref, start_utc, end_utc, kind, predecessor_movement_id)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            array($id, $user, $p->level_id(), $p->pmpro_row_ref(), self::sql_time($p->start_utc()), self::sql_time($p->end_utc()), $p->kind(), $latest)
        );
        // 4. advance the chain head (second compare-and-set on the version read under lock).
        $moved = $db->execute(
            'UPDATE evc_pay_member_heads SET latest_movement_id = ?, version = version + 1, updated_at_utc = UTC_TIMESTAMP(6) WHERE wp_user_id = ? AND version = ?',
            array($id, $user, (int) $head['version'])
        );
        if ($moved !== 1) {
            throw new EVC_Provenance_Conflict_Exception('I4 member head version changed.');
        }
    }

    /** @param EVC_Movement_Alias[] $aliases */
    private function apply_aliases(EVC_Club_Db $db, string $owner, array $aliases): void {
        if ($db->fetch_one('SELECT movement_id FROM evc_pay_correction_heads WHERE movement_id = ? FOR UPDATE', array($owner)) === null
            || $db->fetch_one('SELECT 1 AS x FROM evc_pay_movements WHERE movement_id = ?', array($owner)) === null) {
            throw new EVC_Provenance_Conflict_Exception('I2 aliases for an unknown movement.');
        }
        $new = array();
        foreach (self::sorted($aliases) as $alias) {
            $row = $db->fetch_one('SELECT movement_id FROM evc_pay_aliases WHERE alias_namespace = ? AND alias_ref = ?', array($alias->namespace_name(), $alias->ref()));
            if ($row !== null && (string) $row['movement_id'] !== $owner) {
                throw new EVC_Provenance_Conflict_Exception('I2 alias belongs to another movement.');
            }
            if ($row === null) {
                $new[] = $alias;
            }
        }
        self::insert_aliases($db, $owner, $new);
    }

    private function apply_correction(EVC_Club_Db $db, EVC_Provenance_Write $w): void {
        $c = $w->correction;
        $head = self::lock_correction_head($db, $c->movement_id());
        if ($db->fetch_one('SELECT 1 AS x FROM evc_pay_corrections WHERE correction_id = ?', array($c->correction_id())) !== null) {
            throw new EVC_Provenance_Conflict_Exception('I5 correction already recorded.');
        }
        $recorded = $db->fetch_one('SELECT 1 AS x FROM evc_pay_movements WHERE movement_id = ?', array($c->movement_id())) !== null;
        if ($recorded !== $w->expected_movement_recorded) {
            throw new EVC_Provenance_Conflict_Exception('I8 movement recorded state changed.');
        }
        $prior = $head['latest_correction_id'] === null ? null : (string) $head['latest_correction_id'];
        if ($prior !== $w->expected_prior_correction) {
            throw new EVC_Provenance_Conflict_Exception('I8 correction history changed (compare-and-set failed).');
        }
        if (!EVC_Movement_Correction::allowed_after($head['latest_kind'] === null ? null : (string) $head['latest_kind'], $c->kind())) {
            throw new EVC_Provenance_Conflict_Exception('I8 forbidden correction transition.');
        }
        $db->execute(
            'INSERT INTO evc_pay_corrections (correction_id, movement_id, seq, kind, authority, effective_at_utc, prior_correction_id, recorded_at_utc)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))',
            array($c->correction_id(), $c->movement_id(), (int) $head['correction_count'] + 1, $c->kind(), $c->authority(), self::sql_time($c->effective_at_utc()), $prior)
        );
        $moved = $db->execute(
            'UPDATE evc_pay_correction_heads SET latest_correction_id = ?, latest_kind = ?, correction_count = correction_count + 1,'
            . ' version = version + 1, updated_at_utc = UTC_TIMESTAMP(6) WHERE movement_id = ? AND version = ?',
            array($c->correction_id(), $c->kind(), $c->movement_id(), (int) $head['version'])
        );
        if ($moved !== 1) {
            throw new EVC_Provenance_Conflict_Exception('I8 correction head version changed.');
        }
    }

    // ---- unknown COMMIT recovery ----------------------------------------------

    /**
     * Called only when COMMIT itself failed. Opens a FRESH connection, takes
     * the same lock rows the original transaction held (so a still-open
     * original transaction is waited for, never overtaken) and compares the
     * stored state with the exact write. Writes nothing.
     */
    private function recover(EVC_Provenance_Write $w, string $shape): void {
        try {
            $fresh = ($this->reconnect)();
            if (!$fresh instanceof EVC_Club_Db) {
                throw new UnexpectedValueException('reconnect did not return a connection');
            }
            $this->prepare_session($fresh);
            $verdict = $fresh->transactional(function (EVC_Club_Db $db) use ($w, $shape) {
                return self::read_back($db, $w, $shape);
            });
        } catch (Throwable $e) {
            throw new EVC_Provenance_Commit_Unknown_Exception('Provenance commit outcome unknown; recovery inconclusive.');
        }
        $this->db = $fresh;
        if ($verdict === self::RECOVERY_COMMITTED) {
            return;
        }
        if ($verdict === self::RECOVERY_DIFFERENT) {
            throw new EVC_Provenance_Conflict_Exception('After a failed commit the stored facts differ from this write.');
        }
        throw new EVC_Provenance_Store_Exception('Commit failed and the write is definitely not stored; safe to retry.');
    }

    private static function read_back(EVC_Club_Db $db, EVC_Provenance_Write $w, string $shape): string {
        if ($shape === self::SHAPE_NEW_MOVEMENT) {
            $m = $w->new_movement;
            $db->fetch_one('SELECT wp_user_id FROM evc_pay_member_heads WHERE wp_user_id = ? FOR UPDATE', array($m->wp_user_id()));
            $db->fetch_one('SELECT movement_id FROM evc_pay_correction_heads WHERE movement_id = ? FOR UPDATE', array($m->movement_id()));
            $stored = self::load_movement($db, $m->movement_id());
            if ($stored === null) {
                return self::RECOVERY_ABSENT;
            }
            // Corrections may legitimately have been appended after our commit,
            // so the acknowledged list must be the START of the history.
            $held = self::correction_ids($db, $m->movement_id());
            $same = $stored->same_facts_as($m)
                && self::load_period($db, $m->movement_id()) == $w->new_period
                && self::alias_owners($db, $w->new_aliases) === array_fill(0, count($w->new_aliases), $m->movement_id())
                && array_slice($held, 0, count($w->expected_unresolved)) === array_values($w->expected_unresolved);
            return $same ? self::RECOVERY_COMMITTED : self::RECOVERY_DIFFERENT;
        }
        if ($shape === self::SHAPE_ALIASES) {
            $db->fetch_one('SELECT movement_id FROM evc_pay_correction_heads WHERE movement_id = ? FOR UPDATE', array($w->alias_owner));
            $owners = self::alias_owners($db, $w->new_aliases);
            foreach ($owners as $owner) {
                if ($owner !== null && $owner !== $w->alias_owner) {
                    return self::RECOVERY_DIFFERENT;
                }
            }
            return in_array(null, $owners, true) ? self::RECOVERY_ABSENT : self::RECOVERY_COMMITTED;
        }
        $c = $w->correction;
        $db->fetch_one('SELECT movement_id FROM evc_pay_correction_heads WHERE movement_id = ? FOR UPDATE', array($c->movement_id()));
        $row = $db->fetch_one('SELECT * FROM evc_pay_corrections WHERE correction_id = ?', array($c->correction_id()));
        if ($row === null) {
            return self::RECOVERY_ABSENT;
        }
        $stored_prior = $row['prior_correction_id'] === null ? null : (string) $row['prior_correction_id'];
        return self::hydrate_correction($row)->same_as($c) && $stored_prior === $w->expected_prior_correction
            ? self::RECOVERY_COMMITTED : self::RECOVERY_DIFFERENT;
    }

    // ---- helpers ----------------------------------------------------------------

    private function ensure_ready(): void {
        if ($this->ready) {
            return;
        }
        try {
            $this->prepare_session($this->db);
            $row = $this->db->fetch_one('SELECT MAX(version) AS v FROM evc_schema_migrations');
        } catch (EVC_Db_Exception $e) {
            throw new EVC_Provenance_Store_Exception('Club database unavailable or not migrated.');
        }
        if ($row === null || $row['v'] === null || (int) $row['v'] < self::MIN_SCHEMA_VERSION) {
            throw new EVC_Provenance_Store_Exception('Club database schema is older than migration 002.');
        }
        $this->ready = true;
    }

    private function prepare_session(EVC_Club_Db $db): void {
        $db->exec_raw('SET SESSION innodb_lock_wait_timeout = ' . $this->lock_wait_seconds);
    }

    /** @return mixed */
    private function read(callable $query) {
        $this->ensure_ready();
        try {
            return $query($this->db);
        } catch (EVC_Db_Exception $e) {
            throw new EVC_Provenance_Store_Exception('Club database read failed.');
        }
    }

    private static function classify(EVC_Db_Exception $e): EVC_Provenance_Store_Exception {
        $code = $e->driver_code();
        if ($code === self::ER_NO_REFERENCED_ROW || $code === self::ER_ROW_IS_REFERENCED) {
            return new EVC_Provenance_Conflict_Exception('Foreign key rejected the write (concurrent writer or broken identity).');
        }
        if ($code === self::ER_CHECK_MYSQL || $code === self::ER_CHECK_MARIADB) {
            return new EVC_Provenance_Store_Exception('CHECK constraint rejected the write (invariant breach).');
        }
        return new EVC_Provenance_Store_Exception('Club database write failed; nothing written.');
    }

    /** @return array<string,mixed> head row, locked FOR UPDATE */
    private static function lock_member_head(EVC_Club_Db $db, int $user): array {
        $db->execute(
            'INSERT INTO evc_pay_member_heads (wp_user_id, latest_movement_id, version, updated_at_utc) VALUES (?, NULL, 0, UTC_TIMESTAMP(6))'
            . ' ON DUPLICATE KEY UPDATE wp_user_id = wp_user_id',
            array($user)
        );
        $row = $db->fetch_one('SELECT latest_movement_id, version FROM evc_pay_member_heads WHERE wp_user_id = ? FOR UPDATE', array($user));
        if ($row === null) {
            throw new EVC_Provenance_Store_Exception('Member head row missing after creation.');
        }
        return $row;
    }

    /** @return array<string,mixed> head row, locked FOR UPDATE */
    private static function lock_correction_head(EVC_Club_Db $db, string $movement_id): array {
        $db->execute(
            'INSERT INTO evc_pay_correction_heads (movement_id, latest_correction_id, latest_kind, correction_count, version, created_at_utc, updated_at_utc)'
            . ' VALUES (?, NULL, NULL, 0, 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE movement_id = movement_id',
            array($movement_id)
        );
        $row = $db->fetch_one('SELECT latest_correction_id, latest_kind, correction_count, version FROM evc_pay_correction_heads WHERE movement_id = ? FOR UPDATE', array($movement_id));
        if ($row === null) {
            throw new EVC_Provenance_Store_Exception('Correction head row missing after creation.');
        }
        return $row;
    }

    /** @param EVC_Movement_Alias[] $aliases */
    private static function insert_aliases(EVC_Club_Db $db, string $movement_id, array $aliases): void {
        foreach (self::sorted($aliases) as $alias) {
            $db->execute(
                'INSERT INTO evc_pay_aliases (alias_namespace, alias_ref, movement_id, recorded_at_utc) VALUES (?, ?, ?, UTC_TIMESTAMP(6))',
                array($alias->namespace_name(), $alias->ref(), $movement_id)
            );
        }
    }

    /**
     * Fixed insert order so two writers claiming overlapping aliases wait
     * for each other instead of deadlocking.
     *
     * @param EVC_Movement_Alias[] $aliases
     * @return EVC_Movement_Alias[]
     */
    private static function sorted(array $aliases): array {
        $by_key = array();
        foreach ($aliases as $alias) {
            $by_key[$alias->key()] = $alias;
        }
        ksort($by_key, SORT_STRING);
        return array_values($by_key);
    }

    /** @param EVC_Movement_Alias[] $aliases */
    private static function alias_keys(array $aliases): array {
        $keys = array();
        foreach ($aliases as $alias) {
            $keys[] = $alias->key();
        }
        sort($keys, SORT_STRING);
        return $keys;
    }

    /**
     * @param EVC_Movement_Alias[] $aliases
     * @return array<int,string|null> owner of each alias (null = unknown)
     */
    private static function alias_owners(EVC_Club_Db $db, array $aliases): array {
        $out = array();
        foreach (array_values($aliases) as $alias) {
            $row = $db->fetch_one('SELECT movement_id FROM evc_pay_aliases WHERE alias_namespace = ? AND alias_ref = ?', array($alias->namespace_name(), $alias->ref()));
            $out[] = $row === null ? null : (string) $row['movement_id'];
        }
        return $out;
    }

    /** @return string[] oldest first */
    private static function correction_ids(EVC_Club_Db $db, string $movement_id): array {
        return array_map(function (array $row) {
            return (string) $row['correction_id'];
        }, $db->fetch_all('SELECT correction_id FROM evc_pay_corrections WHERE movement_id = ? ORDER BY seq', array($movement_id)));
    }

    private static function load_movement(EVC_Club_Db $db, string $movement_id): ?EVC_Verified_Movement {
        $row = $db->fetch_one('SELECT * FROM evc_pay_movements WHERE movement_id = ?', array($movement_id));
        if ($row === null) {
            return null;
        }
        $aliases = array();
        foreach ($db->fetch_all('SELECT alias_namespace, alias_ref FROM evc_pay_aliases WHERE movement_id = ?', array($movement_id)) as $a) {
            $aliases[] = new EVC_Movement_Alias((string) $a['alias_namespace'], (string) $a['alias_ref']);
        }
        return new EVC_Verified_Movement(
            (string) $row['movement_id'],
            (string) $row['environment'],
            (int) $row['wp_user_id'],
            (int) $row['level_id'],
            (string) $row['pmpro_row_ref'],
            self::instant((string) $row['verified_at_utc']),
            (int) $row['amount_minor'],
            (string) $row['currency'],
            $aliases,
            (string) $row['authority'],
            (string) $row['evidence_kind'],
            (string) $row['state']
        );
    }

    private static function load_period(EVC_Club_Db $db, string $movement_id): ?EVC_Funded_Period {
        $row = $db->fetch_one('SELECT * FROM evc_pay_periods WHERE movement_id = ?', array($movement_id));
        if ($row === null) {
            return null;
        }
        return new EVC_Funded_Period(
            (string) $row['movement_id'],
            (int) $row['wp_user_id'],
            (int) $row['level_id'],
            (string) $row['pmpro_row_ref'],
            self::instant((string) $row['start_utc']),
            self::instant((string) $row['end_utc']),
            (string) $row['kind'],
            $row['predecessor_movement_id'] === null ? null : (string) $row['predecessor_movement_id']
        );
    }

    /** @return EVC_Movement_Correction[] */
    private static function load_corrections(EVC_Club_Db $db, string $movement_id): array {
        $out = array();
        foreach ($db->fetch_all('SELECT * FROM evc_pay_corrections WHERE movement_id = ? ORDER BY seq', array($movement_id)) as $row) {
            $out[] = self::hydrate_correction($row);
        }
        return $out;
    }

    private static function hydrate_correction(array $row): EVC_Movement_Correction {
        return new EVC_Movement_Correction(
            (string) $row['correction_id'],
            (string) $row['movement_id'],
            (string) $row['kind'],
            (string) $row['authority'],
            self::instant((string) $row['effective_at_utc'])
        );
    }

    /** DATETIME(6) (session time zone +00:00) to a UTC instant. */
    private static function instant(string $value): DateTimeImmutable {
        $value = EVC_Redemption_Store::normalise_datetime($value);
        $t = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($t === false) {
            throw new EVC_Provenance_Store_Exception('Unreadable stored instant.');
        }
        return $t;
    }

    private static function sql_time(DateTimeImmutable $t): string {
        return EVC_Clock::to_utc($t)->format('Y-m-d H:i:s.u');
    }
}
