<?php

/**
 * STAGE-03B.1: advisory pre-issuance planning (EVC_Provenance_Recorder::plan)
 * against the REAL EVC_Sql_Provenance_Store on a disposable database. Proves
 * planning is read-only at the database level (a SELECT-only account can
 * plan; no row, head or lock row changes) and that record() still applies
 * its own checks afterwards. Synthetic data only.
 */
final class SqlProvenancePlanTest extends EVC_Db_Test_Case {
    const TABLES = array('evc_pay_movements', 'evc_pay_aliases', 'evc_pay_periods', 'evc_pay_member_heads', 'evc_pay_correction_heads', 'evc_pay_corrections');

    /** @var string[] users to drop */
    private $users = array();

    protected function tearDown(): void {
        foreach ($this->users as $user) {
            EVC_Test_Database::admin_pdo()->exec("DROP USER IF EXISTS '" . $user . "'@'%'");
        }
        parent::tearDown();
    }

    private static function ref(string $label): string {
        return EVC_Provenance_Test_Fixture::ref($label);
    }

    private static function prospective(string $label, string $at_athens, array $aliases = array()): EVC_Prospective_Payment {
        return new EVC_Prospective_Payment(
            self::ref('mv:' . $label),
            'live',
            EVC_Provenance_Test_Fixture::USER,
            EVC_Pmpro_Fixture::CLUB_LEVEL,
            EVC_Pmpro_Fixture::athens($at_athens),
            2000,
            'EUR',
            $aliases !== array() ? $aliases : array(
                new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:' . $label)),
                new EVC_Movement_Alias(EVC_Movement_Alias::NS_VIVA_TRANSACTION, self::ref('viva:' . $label)),
            ),
            EVC_Verified_Movement::AUTHORITY_GATEWAY_API,
            EVC_Payment_Evidence::KIND_WC_ORDER
        );
    }

    private function rec(?EVC_Provenance_Store $store = null): EVC_Provenance_Recorder {
        return EVC_Provenance_Test_Fixture::recorder($store ?? EVC_Provenance_Test_Fixture::sql_store($this->database->config()));
    }

    /** Full content of every provenance table (rows, heads, versions). */
    private function dump(): array {
        $pdo = EVC_Test_Database::admin_pdo();
        $out = array();
        foreach (self::TABLES as $table) {
            $rows = $pdo->query('SELECT * FROM `' . $this->database->name() . '`.`' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            sort($rows);
            $out[$table] = $rows;
        }
        return $out;
    }

    private static function local(DateTimeImmutable $instant): string {
        return $instant->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s');
    }

    public function test_planning_against_the_sql_store_changes_nothing(): void {
        $r = $this->rec();
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $r->record(EVC_Provenance_Test_Fixture::movement('oct', '2026-10-15 12:00:00'))->outcome());
        $before = $this->dump();

        $early = $r->plan(self::prospective('nov', '2026-11-10 09:00:00'));
        $this->assertSame(EVC_Period_Plan::PLANNED, $early->outcome(), (string) $early->reason());
        $this->assertSame(EVC_Funded_Period::KIND_EARLY, $early->kind());
        $this->assertSame('2026-11-15 12:00:00', self::local($early->start_utc()));
        $this->assertSame('2026-12-15 12:00:00', self::local($early->end_utc()));
        $this->assertSame(self::ref('mv:oct'), $early->predecessor_movement_id());

        // Every refusal path is read-only too.
        $same_order = array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:oct')));
        $this->assertSame('alias_conflict', $r->plan(self::prospective('dup', '2026-11-10 09:00:00', $same_order))->reason());
        $this->assertSame('same_instant_payment', $r->plan(self::prospective('twin', '2026-10-15 12:00:00'))->reason());
        $this->assertSame(EVC_Period_Plan::ALREADY_RECORDED, $r->plan(self::prospective('oct', '2026-10-15 12:00:00', $same_order))->outcome());
        // A new alias on an already recorded movement is NOT added by planning.
        $with_new_alias = array($same_order[0], new EVC_Movement_Alias(EVC_Movement_Alias::NS_PAYPAL_TRANSACTION, self::ref('paypal:new')));
        $this->assertSame(EVC_Period_Plan::ALREADY_RECORDED, $r->plan(self::prospective('oct', '2026-10-15 12:00:00', $with_new_alias))->outcome());

        $this->assertSame($before, $this->dump());
    }

    public function test_a_select_only_account_can_plan_but_not_record(): void {
        $this->rec()->record(EVC_Provenance_Test_Fixture::movement('oct', '2026-10-15 12:00:00'));
        $user = 'evc_ro_' . bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(16));
        $this->users[] = $user;
        $admin = EVC_Test_Database::admin_pdo();
        $admin->exec("CREATE USER '" . $user . "'@'%' IDENTIFIED BY '" . $password . "'");
        // SELECT only, on the provenance tables and the schema version the store checks first.
        foreach (array_merge(self::TABLES, array('evc_schema_migrations')) as $table) {
            $admin->exec('GRANT SELECT ON `' . $this->database->name() . '`.`' . $table . "` TO '" . $user . "'@'%'");
        }
        $config = new EVC_Db_Config(EVC_Test_Database::host(), EVC_Test_Database::port(), $this->database->name(), $user, $password);
        $read_only = $this->rec(EVC_Provenance_Test_Fixture::sql_store($config, 5, EVC_Club_Db::connect($config)));
        $before = $this->dump();

        $plan = $read_only->plan(self::prospective('dec', '2026-12-01 10:00:00'));
        $this->assertSame(EVC_Period_Plan::PLANNED, $plan->outcome(), (string) $plan->reason());
        $this->assertSame(EVC_Funded_Period::KIND_LATE, $plan->kind());
        // The same account cannot record: the write path really needs write grants.
        $this->assertSame(EVC_Record_Outcome::UNAVAILABLE, $read_only->record(EVC_Provenance_Test_Fixture::movement('dec', '2026-12-01 10:00:00', array('row' => self::ref('row2'))))->outcome());
        $this->assertSame($before, $this->dump());
    }

    public function test_record_revalidates_after_a_stale_plan(): void {
        $r = $this->rec();
        $r->record(EVC_Provenance_Test_Fixture::movement('oct', '2026-10-15 12:00:00'));
        $plan = $r->plan(self::prospective('dec', '2026-12-01 10:00:00'));
        $this->assertSame(EVC_Funded_Period::KIND_LATE, $plan->kind());

        // Another renewal lands between planning and recording.
        $r->record(EVC_Provenance_Test_Fixture::movement('nov', '2026-11-20 10:00:00', array('row' => self::ref('row2'))));
        $o = $r->record(EVC_Provenance_Test_Fixture::movement('dec', '2026-12-01 10:00:00', array('row' => self::ref('row3'))));
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $o->outcome());
        $this->assertSame(EVC_Funded_Period::KIND_EARLY, $o->period()->kind());
        $this->assertFalse($plan->matches_recorded($o->period()));
        $this->assertSame('2026-12-20 10:00:00', self::local($o->period()->start_utc()));
    }

    public function test_unreachable_database_makes_the_plan_unavailable(): void {
        $pdo = $this->database->faulty_pdo();
        $store = EVC_Provenance_Test_Fixture::sql_store($this->database->config(), 5, new EVC_Club_Db($pdo));
        $pdo->fail_prepare_matching = '/./';
        $plan = $this->rec($store)->plan(self::prospective('oct', '2026-10-15 12:00:00'));
        $this->assertSame(EVC_Period_Plan::UNAVAILABLE, $plan->outcome());
        $this->assertSame('store_unavailable', $plan->reason());
    }
}
