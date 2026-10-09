<?php

final class FailureInjectionTest extends EVC_Db_Test_Case {
    public function test_audit_failure_rolls_back_the_redemption(): void {
        $member = $this->create_active_member(5001);
        $request_id = self::uuid4();
        $audit = new EVC_Failing_Audit_Log(new EVC_Db_Audit_Log(), array('redeemed'));

        $result = $this->service(null, $audit)->redeem($this->request($member, 'espresso', $request_id));

        $this->assertSame(EVC_Redemption_Result::SERVER_ERROR, $result->outcome());
        $this->assertFalse($this->db->in_transaction());
        $this->assertSame(0, $this->count_rows('evc_redemptions'), 'no redemption without its audit event');
        $this->assertSame(0, $this->count_rows('evc_audit_events'));
        $this->assertSame('RuntimeException', $this->reported[0]['exception']);

        // Same request succeeds once auditing works again.
        $this->assertSame(EVC_Redemption_Result::REDEEMED, $this->service()->redeem($this->request($member, 'espresso', $request_id))->outcome());
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
        $this->assertSame(1, $this->count_rows('evc_audit_events'));
    }

    public function test_failed_rejection_audit_does_not_turn_rejection_into_success(): void {
        $this->adapter->set(5002, EVC_Mock_Membership_Adapter::with('pending_payment', false, '2026-11-09T10:00:00Z'));
        $member = $this->create_member(5002);
        $audit = new EVC_Failing_Audit_Log(new EVC_Db_Audit_Log(), array('membership_inactive'));

        $result = $this->service(null, $audit)->redeem($this->request($member));

        $this->assertSame(EVC_Redemption_Result::MEMBERSHIP_INACTIVE, $result->outcome());
        $this->assertSame('audit_rejection', $this->reported[0]['stage']);
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
    }

    public function test_insert_failure_returns_server_error_without_partial_rows(): void {
        $member = $this->create_active_member(5003);
        $pdo = $this->database->faulty_pdo();
        $pdo->fail_prepare_matching = '/^INSERT INTO evc_audit_events/';

        $result = $this->service(new EVC_Club_Db($pdo))->redeem($this->request($member));

        $this->assertSame(EVC_Redemption_Result::SERVER_ERROR, $result->outcome());
        $this->assertSame(0, $this->count_rows('evc_redemptions'), 'ledger insert rolled back with the audit failure');
        $this->assertSame(array(array('stage' => 'redeem', 'exception' => 'EVC_Db_Exception', 'sql_state' => 'HY000', 'driver_code' => 2006)), $this->reported);
    }

    public function test_database_unavailable_during_lookup_fails_safely(): void {
        $member = $this->create_active_member(5004);
        $pdo = $this->database->faulty_pdo();
        $pdo->fail_prepare_matching = '/FROM evc_redemptions r JOIN evc_members/';

        $result = $this->service(new EVC_Club_Db($pdo))->redeem($this->request($member));

        $this->assertSame(EVC_Redemption_Result::SERVER_ERROR, $result->outcome());
        $this->assertSame(array(), $this->adapter->calls);
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
    }

    public function test_unreachable_database_never_reveals_connection_details(): void {
        $config = new EVC_Db_Config('127.0.0.1', 1, 'evc_test_unreachable', 'evc_probe_user', 'evc-probe-password');
        try {
            EVC_Club_Db::connect($config);
            $this->fail('Connecting to a closed port must fail.');
        } catch (EVC_Db_Exception $e) {
            $this->assertSame('Club database is unavailable.', $e->getMessage());
            $this->assertNull($e->getPrevious());
            $text = (string) $e;
            foreach (array('evc-probe-password', 'evc_probe_user', 'SQLSTATE') as $secret) {
                $this->assertStringNotContainsString($secret, $e->getMessage());
            }
            $this->assertStringNotContainsString('evc-probe-password', $text);
        }
    }

    public function test_transient_deadlock_is_retried_and_succeeds_once(): void {
        $member = $this->create_active_member(5005);
        $pdo = $this->database->faulty_pdo();
        $pdo->deadlock_prepare_matching = '/^INSERT INTO evc_redemptions/';
        $pdo->deadlocks_remaining = 2;

        $result = $this->service(new EVC_Club_Db($pdo))->redeem($this->request($member));

        $this->assertSame(EVC_Redemption_Result::REDEEMED, $result->outcome());
        $this->assertSame(0, $pdo->deadlocks_remaining);
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
        $this->assertSame(1, $this->count_rows('evc_audit_events'));
    }

    public function test_persistent_deadlock_gives_up_with_server_error(): void {
        $member = $this->create_active_member(5006);
        $pdo = $this->database->faulty_pdo();
        $pdo->deadlock_prepare_matching = '/^INSERT INTO evc_redemptions/';
        $pdo->deadlocks_remaining = -1;

        $result = $this->service(new EVC_Club_Db($pdo))->redeem($this->request($member));

        $this->assertSame(EVC_Redemption_Result::SERVER_ERROR, $result->outcome());
        $this->assertSame('EVC_Db_Retryable_Exception', $this->reported[0]['exception']);
        $this->assertSame(1213, $this->reported[0]['driver_code']);
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
    }

    public function test_duplicate_key_on_day_is_resolved_with_fresh_read_after_rollback(): void {
        // Another till already committed today's redemption: this service's
        // INSERT fails on uq_redemptions_one_per_day inside its transaction,
        // which is rolled back before the winner is read.
        $member = $this->create_active_member(5007);
        $this->clock->set('2026-10-09T08:00:00Z');
        $winner = $this->service($this->database->connect())->redeem($this->request($member, 'greek_coffee'));
        $this->clock->set('2026-10-09T09:00:00Z');

        $loser = $this->service()->redeem($this->request($member, 'espresso'));

        $this->assertSame(EVC_Redemption_Result::ALREADY_REDEEMED, $loser->outcome());
        $this->assertSame($winner->redemption()['redeemed_at_utc'], $loser->details()['redeemed_at_utc']);
        $this->assertFalse($this->db->in_transaction());
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
        $this->assertSame(1, $this->count_rows('evc_audit_events', 'outcome = ?', array('redeemed')));
    }

    public function test_broken_error_reporter_does_not_change_outcome(): void {
        $member = $this->create_active_member(5008);
        $service = new EVC_Redemption_Service(
            new EVC_Redemption_Store($this->db),
            new EVC_Failing_Audit_Log(new EVC_Db_Audit_Log(), array('redeemed')),
            $this->adapter,
            self::catalog(),
            $this->clock,
            function () {
                throw new RuntimeException('reporter down');
            }
        );
        $this->assertSame(EVC_Redemption_Result::SERVER_ERROR, $service->redeem($this->request($member))->outcome());
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
    }
}
