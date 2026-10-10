<?php
use PHPUnit\Framework\TestCase;

/**
 * EVC_Pmpro_Entitlement_Mapper behaviour (Task 1D-B, scenarios A-Y).
 * All data is synthetic. Periods are written out by hand per test.
 */
final class PmproEntitlementMapperTest extends TestCase {
    private static function when(string $athens_local): DateTimeImmutable {
        return EVC_Pmpro_Fixture::athens($athens_local);
    }

    private static function map(EVC_Pmpro_Snapshot $s, DateTimeImmutable $at): EVC_Entitlement {
        return EVC_Pmpro_Fixture::mapper()->map($s, $at);
    }

    private static function local(?DateTimeImmutable $instant): ?string {
        return $instant === null ? null : $instant->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s');
    }

    private function assertIneligible(EVC_Entitlement $e, string $status, ?string $flag, DateTimeImmutable $at): void {
        $this->assertSame($status, $e->status(), 'status (flags: ' . implode(',', $e->diagnostic_flags()) . ')');
        $this->assertFalse($e->is_eligible_at($at), 'must not be eligible');
        $this->assertNull($e->payment_evidence(), 'no evidence on a non-eligible result');
        if ($flag !== null) {
            $this->assertContains($flag, $e->diagnostic_flags());
        }
        if ($status === EVC_Entitlement::STATUS_INDETERMINATE) {
            $this->assertFalse($e->payment_verified());
            $this->assertNull($e->expires_at_utc());
            $this->assertSame('inactive', $e->public_status($at));
        }
    }

    // ---- A / F: verified, period-bound payment ------------------------------

    public function test_A_active_with_linked_verified_payment(): void {
        $at = self::when('2026-10-20 08:00');
        $e = self::map(EVC_Pmpro_Fixture::single_paid_month(), $at);
        $this->assertSame('active', $e->status());
        $this->assertTrue($e->payment_verified());
        $this->assertTrue($e->is_eligible_at($at));
        $this->assertSame('active', $e->public_status($at));
        $this->assertSame('pmpro', $e->source());
        $this->assertSame('pmpro:7', $e->level_ref());
        $this->assertSame('2026-11-15 12:00:00', self::local($e->expires_at_utc()));
        $this->assertSame('2026-10-15 12:00:00', self::local($e->started_at_utc()));
        $ev = $e->payment_evidence();
        $this->assertSame(EVC_Pmpro_Fixture::ref('payment-record:oct'), $ev->reference());
        $this->assertSame('pmpro_order', $ev->kind());
        $this->assertSame('2026-10-15 12:00:00', self::local($ev->period_start_utc()));
        $this->assertSame('2026-11-15 12:00:00', self::local($ev->period_end_utc()));
        $this->assertSame(array(), $e->diagnostic_flags());
    }

    public function test_F_manual_payment_counts_only_once_explicitly_confirmed(): void {
        $s = EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row()),
            array(EVC_Pmpro_Fixture::paid('cash', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1', array('kind' => EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION)))
        );
        $e = self::map($s, self::when('2026-10-16 09:00'));
        $this->assertSame('active', $e->status());
        $this->assertSame('manual_confirmation', $e->payment_evidence()->kind());
    }

    // ---- B / C: PMPro "active" alone, or old payments, are not enough ------

    public function test_B_active_pmpro_row_without_payment_evidence(): void {
        $at = self::when('2026-10-20 08:00');
        $this->assertIneligible(self::map(EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array()), $at), 'indeterminate', 'no_payment_evidence', $at);
        // A payment for a different (non-Club) level is not evidence either.
        $other = EVC_Pmpro_Fixture::paid('other', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1', array('level_id' => EVC_Pmpro_Fixture::OTHER_LEVEL));
        $this->assertIneligible(self::map(EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array($other)), $at), 'indeterminate', 'no_payment_evidence', $at);
    }

    public function test_C_historical_payment_cannot_fund_a_later_unpaid_period(): void {
        $at = self::when('2026-10-20 08:00');
        // Paid Aug->Sep only; PMPro claims active until 15 Nov.
        $s = EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row()),
            array(EVC_Pmpro_Fixture::paid('aug', '2026-08-01 10:00', '2026-08-01 10:00', '2026-09-01 10:00'))
        );
        $this->assertIneligible(self::map($s, $at), 'indeterminate', 'pmpro_period_mismatch', $at);

        // Same, when the old payment belongs to an older PMPro row and the active row has none.
        $s = EVC_Pmpro_Fixture::snapshot(
            array(
                EVC_Pmpro_Fixture::row('row0', 'changed', '2026-09-01 10:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2026-08-01 10:00:00'),
                EVC_Pmpro_Fixture::row('row1', 'active', '2026-09-01 10:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2026-08-01 10:00:00'),
            ),
            array(EVC_Pmpro_Fixture::paid('aug', '2026-08-01 10:00', '2026-08-01 10:00', '2026-09-01 10:00', 'row0'))
        );
        $this->assertIneligible(self::map($s, self::when('2026-08-20 10:00')), 'indeterminate', 'pmpro_period_mismatch', self::when('2026-08-20 10:00'));
    }

    public function test_payment_without_recorded_provenance_is_never_inferred(): void {
        $at = self::when('2026-10-20 08:00');
        foreach (array('membership_row_ref', 'period_start_utc', 'period_end_utc') as $missing) {
            $p = EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1', array($missing => null));
            $e = self::map(EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array($p)), $at);
            $this->assertIneligible($e, 'indeterminate', 'payment_period_unlinked', $at);
        }
        // Linked to a row that does not exist / is not a Club row.
        $p = EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'unknown-row');
        $this->assertIneligible(self::map(EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array($p)), $at), 'indeterminate', 'payment_period_unlinked', $at);
    }

    // ---- D / E / G: pending money ----------------------------------------

    public function test_D_E_pending_cash_or_bank_transfer_grants_nothing(): void {
        $at = self::when('2026-10-20 08:00');
        foreach (array(EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION, EVC_Payment_Evidence::KIND_PMPRO_ORDER, EVC_Payment_Evidence::KIND_WC_ORDER) as $kind) {
            $pending = EVC_Pmpro_Fixture::payment('pend', 'pending', null, null, null, 'row1', array('kind' => $kind));
            $e = self::map(EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array($pending)), $at);
            $this->assertIneligible($e, 'pending_payment', 'no_payment_evidence', $at);
            $this->assertSame('inactive', $e->public_status($at));
            $this->assertSame('pending_payment', $e->denial_reason($at));
        }
    }

    public function test_pending_fact_claiming_a_confirmation_time_is_rejected(): void {
        $at = self::when('2026-10-20 08:00');
        $odd = EVC_Pmpro_Fixture::payment('pend', 'pending', self::when('2026-10-15 12:00'), null, null);
        $this->assertIneligible(self::map(EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array($odd)), $at), 'indeterminate', 'invalid_payment_fact', $at);
    }

    public function test_failed_payment_only(): void {
        $at = self::when('2026-10-20 08:00');
        $failed = EVC_Pmpro_Fixture::payment('f', 'failed', null, null, null);
        $this->assertIneligible(self::map(EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array($failed)), $at), 'payment_failed', null, $at);
    }

    public function test_G_pending_renewal_neither_extends_nor_invalidates(): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $s->payments[] = EVC_Pmpro_Fixture::payment('renewal', 'pending', null, null, null);
        $e = self::map($s, self::when('2026-11-10 09:00'));
        $this->assertSame('active', $e->status());
        $this->assertSame('2026-11-15 12:00:00', self::local($e->expires_at_utc()), 'not extended');
        $this->assertContains('renewal_pending', $e->diagnostic_flags());
        $after = self::when('2026-11-15 12:00');
        $this->assertIneligible(self::map($s, $after), 'expired', 'paid_period_ended', $after);
    }

    // ---- H / I: confirmed renewals ------------------------------------------

    private static function early_renewal(): EVC_Pmpro_Snapshot {
        return EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row('row1', 'changed', '2026-11-15 12:00:00'), EVC_Pmpro_Fixture::row('row2', 'active', '2026-12-15 12:00:00')),
            array(
                EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1'),
                EVC_Pmpro_Fixture::paid('nov', '2026-11-01 09:00', '2026-11-15 12:00', '2026-12-15 12:00', 'row2'),
            )
        );
    }

    public function test_H_confirmed_early_renewal_adds_a_month_to_the_paid_end(): void {
        $s = self::early_renewal();
        $before = self::map($s, self::when('2026-11-10 09:00'));
        $this->assertSame('active', $before->status());
        $this->assertSame('2026-12-15 12:00:00', self::local($before->expires_at_utc()), 'remaining paid time kept');
        $this->assertSame(EVC_Pmpro_Fixture::ref('payment-record:oct'), $before->payment_evidence()->reference(), 'still funded by October');
        $this->assertSame('2026-10-15 12:00:00', self::local($before->started_at_utc()), 'one continuous paid run');

        $after = self::map($s, self::when('2026-11-20 09:00'));
        $this->assertSame(EVC_Pmpro_Fixture::ref('payment-record:nov'), $after->payment_evidence()->reference());
        $this->assertSame('2026-11-15 12:00:00', self::local($after->payment_evidence()->period_start_utc()));
    }

    public function test_early_renewal_recorded_from_confirmation_time_is_rejected(): void {
        // Source restarted the period at payment time, losing remaining paid time (not D2).
        $s = EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row('row1', 'changed', '2026-11-15 12:00:00'), EVC_Pmpro_Fixture::row('row2', 'active', '2026-12-01 09:00:00')),
            array(
                EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1'),
                EVC_Pmpro_Fixture::paid('nov', '2026-11-01 09:00', '2026-11-01 09:00', '2026-12-01 09:00', 'row2'),
            )
        );
        $at = self::when('2026-11-10 09:00');
        $this->assertIneligible(self::map($s, $at), 'indeterminate', 'payment_period_mismatch', $at);
    }

    private static function late_renewal(string $start_local, string $end_local, string $row_end): EVC_Pmpro_Snapshot {
        return EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row('row1', 'expired', '2026-11-15 12:00:00'), EVC_Pmpro_Fixture::row('row2', 'active', $row_end, EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2026-11-20 10:00:00')),
            array(
                EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1'),
                EVC_Pmpro_Fixture::paid('nov', '2026-11-20 10:00', $start_local, $end_local, 'row2'),
            )
        );
    }

    public function test_I_confirmed_late_renewal_starts_at_payment_confirmation(): void {
        $s = self::late_renewal('2026-11-20 10:00', '2026-12-20 10:00', '2026-12-20 10:00:00');
        $e = self::map($s, self::when('2026-11-25 09:00'));
        $this->assertSame('active', $e->status());
        $this->assertSame('2026-12-20 10:00:00', self::local($e->expires_at_utc()));
        $this->assertSame('2026-11-20 10:00:00', self::local($e->started_at_utc()), 'new paid run');
    }

    public function test_late_renewal_recorded_from_order_creation_or_old_end_is_rejected(): void {
        $at = self::when('2026-11-25 09:00');
        // Order created 18 Nov, money confirmed 20 Nov: period must start at confirmation.
        $this->assertIneligible(self::map(self::late_renewal('2026-11-18 10:00', '2026-12-18 10:00', '2026-12-18 10:00:00'), $at), 'indeterminate', 'payment_period_mismatch', $at);
        // Stacked onto the expired end (the unpaid gap would be granted).
        $this->assertIneligible(self::map(self::late_renewal('2026-11-15 12:00', '2026-12-15 12:00', '2026-12-15 12:00:00'), $at), 'indeterminate', 'payment_period_mismatch', $at);
    }

    public function test_between_expiry_and_late_renewal_there_is_no_entitlement(): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $s->payments[] = EVC_Pmpro_Fixture::payment('renewal', 'pending', null, null, null);
        $at = self::when('2026-11-18 09:00');
        $this->assertIneligible(self::map($s, $at), 'expired', 'paid_period_ended', $at);
    }

    // ---- J / K: duplicates and conflicts ------------------------------------

    public function test_J_duplicate_notification_of_one_payment_counts_once(): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $dup = EVC_Pmpro_Fixture::paid('oct-webhook-retry', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1', array('dedupe_key' => EVC_Pmpro_Fixture::ref('money:oct')));
        $s->payments[] = $dup;
        $e = self::map($s, self::when('2026-10-20 09:00'));
        $this->assertSame('active', $e->status());
        $this->assertSame('2026-11-15 12:00:00', self::local($e->expires_at_utc()), 'not extended twice');
        $this->assertContains('duplicate_payment_event_collapsed', $e->diagnostic_flags());
    }

    public function test_K_inconsistent_repeated_notifications_fail_closed(): void {
        $at = self::when('2026-10-20 09:00');
        $variants = array(
            'confirmed at another time' => array('confirmed_at_utc' => self::when('2026-10-15 12:05')),
            'another period' => array('period_end_utc' => self::when('2026-12-15 12:00')),
            'another status' => array('status' => 'refunded'),
            'another row' => array('membership_row_ref' => EVC_Pmpro_Fixture::ref('row9')),
        );
        foreach ($variants as $name => $override) {
            $s = EVC_Pmpro_Fixture::single_paid_month();
            $s->memberships[] = EVC_Pmpro_Fixture::row('row9', 'changed', '2026-11-15 12:00:00');
            $s->payments[] = EVC_Pmpro_Fixture::paid('oct-copy', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1', array_merge(array('dedupe_key' => EVC_Pmpro_Fixture::ref('money:oct')), $override));
            $this->assertIneligible(self::map($s, $at), 'indeterminate', 'conflicting_payment_events', $at);
        }
    }

    public function test_payments_that_cannot_be_deduplicated_fail_closed(): void {
        $at = self::when('2026-10-20 09:00');
        foreach (array(null, '12345', 'ORDER-1') as $key) {
            $s = EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array(
                EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1', array('dedupe_key' => $key)),
            ));
            $this->assertIneligible(self::map($s, $at), 'indeterminate', 'payment_not_deduplicable', $at);
        }
    }

    public function test_two_distinct_payments_claiming_the_same_period_fail_closed(): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $s->payments[] = EVC_Pmpro_Fixture::paid('oct-second-charge', '2026-10-16 08:00', '2026-10-15 12:00', '2026-11-15 12:00');
        $at = self::when('2026-10-20 09:00');
        $this->assertIneligible(self::map($s, $at), 'indeterminate', 'payment_period_mismatch', $at);
    }

    public function test_two_distinct_payments_at_the_same_instant_are_ambiguous(): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $s->payments[] = EVC_Pmpro_Fixture::paid('other', '2026-10-15 12:00', '2026-11-15 12:00', '2026-12-15 12:00');
        $at = self::when('2026-10-20 09:00');
        $this->assertIneligible(self::map($s, $at), 'indeterminate', 'ambiguous_payment_order', $at);
    }

    public function test_reader_reported_cross_system_conflict_fails_closed(): void {
        $at = self::when('2026-10-20 09:00');
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $s->source_conflicts = array('wc_completed_pmpro_missing');
        $this->assertIneligible(self::map($s, $at), 'indeterminate', 'source_conflict', $at);
    }

    // ---- L / M: refunds and reversals ---------------------------------------

    public function test_L_full_refund_of_the_current_payment(): void {
        $s = EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array(
            EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1', array('status' => 'refunded')),
        ));
        $at = self::when('2026-10-20 09:00');
        $e = self::map($s, $at);
        $this->assertIneligible($e, 'refunded', 'payment_refunded', $at);
        $this->assertSame('inactive', $e->public_status($at));
    }

    public function test_refund_of_an_earlier_run_payment_in_the_current_run_blocks_everything(): void {
        // Early renewal stacked on a payment that was later refunded: whole run fails closed.
        $s = self::early_renewal();
        $s->payments[0]->status = 'refunded';
        $at = self::when('2026-11-20 09:00');
        $this->assertIneligible(self::map($s, $at), 'refunded', 'payment_refunded', $at);
    }

    public function test_refund_in_a_lapsed_earlier_run_does_not_affect_a_new_paid_run(): void {
        $s = self::late_renewal('2026-11-20 10:00', '2026-12-20 10:00', '2026-12-20 10:00:00');
        $s->payments[0]->status = 'refunded';
        $e = self::map($s, self::when('2026-11-25 09:00'));
        $this->assertSame('active', $e->status());
    }

    public function test_M_partial_refund_and_reversal_are_unresolved_policies(): void {
        $at = self::when('2026-10-20 09:00');
        foreach (array('partially_refunded' => 'partial_refund_policy_unresolved', 'reversed' => 'payment_reversed') as $status => $flag) {
            $s = EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array(
                EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1', array('status' => $status)),
            ));
            $this->assertIneligible(self::map($s, $at), 'indeterminate', $flag, $at);
        }
    }

    // ---- N: cancellation ----------------------------------------------------

    public function test_N_cancellation_with_unknown_effective_time(): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $s->memberships = array(EVC_Pmpro_Fixture::row('row1', 'active', '2026-11-15 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, true));
        $at = self::when('2026-10-20 09:00');
        $this->assertIneligible(self::map($s, $at), 'indeterminate', 'cancellation_effective_time_unknown', $at);
    }

    public function test_cancelled_or_expired_rows_without_an_active_row(): void {
        $at = self::when('2026-10-20 09:00');
        foreach (array('cancelled' => 'cancelled', 'admin_cancelled' => 'cancelled', 'expired' => 'expired', 'changed' => 'none', 'inactive' => 'none') as $raw => $status) {
            $s = EVC_Pmpro_Fixture::single_paid_month();
            $s->memberships = array(EVC_Pmpro_Fixture::row('row1', $raw));
            $this->assertIneligible(self::map($s, $at), $status, null, $at);
        }
    }

    // ---- O / P: levels --------------------------------------------------

    public function test_O_unsupported_level_is_never_eligible(): void {
        $at = self::when('2026-10-20 09:00');
        $s = EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row('row1', 'active', '2026-11-15 12:00:00', EVC_Pmpro_Fixture::OTHER_LEVEL)),
            array(EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1', array('level_id' => EVC_Pmpro_Fixture::OTHER_LEVEL)))
        );
        $this->assertIneligible(self::map($s, $at), 'none', 'non_club_level_only', $at);
        $this->assertIneligible(self::map(EVC_Pmpro_Fixture::snapshot(array(), array()), $at), 'none', 'no_club_membership', $at);
    }

    public function test_P_more_than_one_active_club_level(): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $s->memberships[] = EVC_Pmpro_Fixture::row('row2', 'active', '2026-11-15 12:00:00');
        $at = self::when('2026-10-20 09:00');
        $this->assertIneligible(self::map($s, $at), 'indeterminate', 'multiple_active_club_levels', $at);
    }

    public function test_an_extra_non_club_level_does_not_interfere(): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $s->memberships[] = EVC_Pmpro_Fixture::row('rowX', 'active', null, EVC_Pmpro_Fixture::OTHER_LEVEL);
        $this->assertSame('active', self::map($s, self::when('2026-10-20 09:00'))->status());
    }

    public function test_unknown_membership_status_fails_closed(): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $s->memberships[0]->status = 'pending';
        $at = self::when('2026-10-20 09:00');
        $this->assertIneligible(self::map($s, $at), 'indeterminate', 'unknown_membership_status', $at);
    }

    // ---- Q / R: end dates and the exact boundary ----------------------------

    /** @return array<string,array{?string,string}> */
    public static function bad_end_dates(): array {
        return array(
            'missing' => array(null, 'expiry_missing'),
            'empty' => array('', 'expiry_missing'),
            'zero date' => array('0000-00-00 00:00:00', 'expiry_missing'),
            'PMPro magic minimum' => array('1000-01-01 00:00:00', 'expiry_missing'),
            'impossible day' => array('2026-11-31 12:00:00', 'invalid_membership_dates'),
            'not a date' => array('next month', 'invalid_membership_dates'),
            'with offset' => array('2026-11-15T12:00:00+02:00', 'invalid_membership_dates'),
            'unix timestamp' => array('1794744000', 'invalid_membership_dates'),
        );
    }

    /** @dataProvider bad_end_dates */
    public function test_Q_missing_zero_magic_or_invalid_end_date(?string $end, string $flag): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $s->memberships = array(EVC_Pmpro_Fixture::row('row1', 'active', $end));
        $at = self::when('2026-10-20 09:00');
        $this->assertIneligible(self::map($s, $at), 'indeterminate', $flag, $at);
    }

    public function test_R_exact_expiry_boundary(): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $end = self::when('2026-11-15 12:00'); // 10:00Z
        $this->assertSame('active', self::map($s, $end->modify('-1 second'))->status());
        $this->assertTrue(self::map($s, $end->modify('-1 microsecond'))->is_eligible_at($end->modify('-1 microsecond')));
        $this->assertIneligible(self::map($s, $end), 'expired', 'paid_period_ended', $end);
        $this->assertIneligible(self::map($s, $end->modify('+1 second')), 'expired', 'paid_period_ended', $end->modify('+1 second'));
        // An "active" result evaluated later still expires on its own.
        $active = self::map($s, $end->modify('-1 second'));
        $this->assertFalse($active->is_eligible_at($end));
    }

    public function test_pmpro_cron_lag_does_not_extend_entitlement(): void {
        // PMPro still shows "active" for up to ~15 min after the end; we do not care.
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $at = self::when('2026-11-15 12:10');
        $this->assertSame('active', $s->memberships[0]->status);
        $this->assertIneligible(self::map($s, $at), 'expired', 'paid_period_ended', $at);
    }

    public function test_pmpro_end_date_disagreeing_with_paid_period_fails_closed(): void {
        $at = self::when('2026-10-20 09:00');
        foreach (array('2026-11-15 12:01:00', '2026-11-15 11:59:00', '2026-12-15 12:00:00') as $row_end) {
            $s = EVC_Pmpro_Fixture::single_paid_month();
            $s->memberships = array(EVC_Pmpro_Fixture::row('row1', 'active', $row_end));
            $this->assertIneligible(self::map($s, $at), 'indeterminate', 'pmpro_period_mismatch', $at);
        }
    }

    // ---- S / T / U: calendar rules through the mapper -----------------------

    public function test_S_month_end_clamp_is_required_from_the_source(): void {
        $at = self::when('2027-02-10 09:00');
        $ok = EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row('row1', 'active', '2027-02-28 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2027-01-31 12:00:00')),
            array(EVC_Pmpro_Fixture::paid('jan', '2027-01-31 12:00', '2027-01-31 12:00', '2027-02-28 12:00'))
        );
        $e = self::map($ok, $at);
        $this->assertSame('active', $e->status());
        $this->assertSame('2027-02-28 12:00:00', self::local($e->expires_at_utc()));

        // PHP "+1 month" overflow (3 March) recorded by a source is rejected.
        $overflow = EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row('row1', 'active', '2027-03-03 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2027-01-31 12:00:00')),
            array(EVC_Pmpro_Fixture::paid('jan', '2027-01-31 12:00', '2027-01-31 12:00', '2027-03-03 12:00'))
        );
        $this->assertIneligible(self::map($overflow, $at), 'indeterminate', 'payment_period_mismatch', $at);

        // A fixed 30-day period is rejected as well.
        $thirty = EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row('row1', 'active', '2027-03-02 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2027-01-31 12:00:00')),
            array(EVC_Pmpro_Fixture::paid('jan', '2027-01-31 12:00', '2027-01-31 12:00', '2027-03-02 12:00'))
        );
        $this->assertIneligible(self::map($thirty, $at), 'indeterminate', 'payment_period_mismatch', $at);
    }

    public function test_leap_year_clamp_through_the_mapper(): void {
        $s = EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row('row1', 'active', '2028-02-29 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2028-01-31 12:00:00')),
            array(EVC_Pmpro_Fixture::paid('jan', '2028-01-31 12:00', '2028-01-31 12:00', '2028-02-29 12:00'))
        );
        $this->assertSame('active', self::map($s, self::when('2028-02-29 11:59'))->status());
        $this->assertSame('expired', self::map($s, self::when('2028-02-29 12:00'))->status());
    }

    public function test_T_consecutive_early_renewals_chain_from_each_paid_end(): void {
        $s = EVC_Pmpro_Fixture::snapshot(
            array(
                EVC_Pmpro_Fixture::row('r1', 'changed', '2027-02-28 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2027-01-31 12:00:00'),
                EVC_Pmpro_Fixture::row('r2', 'changed', '2027-03-28 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2027-01-31 12:00:00'),
                EVC_Pmpro_Fixture::row('r3', 'active', '2027-04-28 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2027-01-31 12:00:00'),
            ),
            array(
                EVC_Pmpro_Fixture::paid('m1', '2027-01-31 12:00', '2027-01-31 12:00', '2027-02-28 12:00', 'r1'),
                EVC_Pmpro_Fixture::paid('m2', '2027-02-20 10:00', '2027-02-28 12:00', '2027-03-28 12:00', 'r2'),
                EVC_Pmpro_Fixture::paid('m3', '2027-03-20 10:00', '2027-03-28 12:00', '2027-04-28 12:00', 'r3'),
            )
        );
        $e = self::map($s, self::when('2027-04-01 09:00'));
        $this->assertSame('active', $e->status());
        $this->assertSame('2027-04-28 12:00:00', self::local($e->expires_at_utc()), 'documented 31st -> 28th drift');
        $this->assertSame('2027-01-31 12:00:00', self::local($e->started_at_utc()));
        $this->assertSame(EVC_Pmpro_Fixture::ref('payment-record:m3'), $e->payment_evidence()->reference());
        // Restoring the "31st" anniversary would be a different (unapproved) policy.
        $s->memberships[2] = EVC_Pmpro_Fixture::row('r3', 'active', '2027-04-30 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2027-01-31 12:00:00');
        $s->payments[2] = EVC_Pmpro_Fixture::paid('m3', '2027-03-20 10:00', '2027-03-31 12:00', '2027-04-30 12:00', 'r3');
        $this->assertSame('indeterminate', self::map($s, self::when('2027-04-01 09:00'))->status());
    }

    public function test_U_year_rollover(): void {
        $s = EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row('row1', 'active', '2027-01-31 23:30:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2026-12-31 23:30:00')),
            array(EVC_Pmpro_Fixture::paid('dec', '2026-12-31 23:30', '2026-12-31 23:30', '2027-01-31 23:30'))
        );
        $this->assertSame('active', self::map($s, self::when('2027-01-31 23:29'))->status());
        $this->assertSame('expired', self::map($s, self::when('2027-01-31 23:30'))->status());
    }

    // ---- V / W: DST through the mapper --------------------------------------

    public function test_V_period_across_the_autumn_change(): void {
        $s = EVC_Pmpro_Fixture::single_paid_month(); // +03:00 -> +02:00
        $this->assertSame('active', self::map($s, EVC_Pmpro_Fixture::utc('2026-11-15T09:59:59Z'))->status());
        $this->assertSame('expired', self::map($s, EVC_Pmpro_Fixture::utc('2026-11-15T10:00:00Z'))->status());
    }

    public function test_W_end_in_the_spring_gap_resolves_conservatively(): void {
        // 28 Feb 03:30 + 1 month = 28 Mar 03:30, which does not exist (jump at 01:00Z).
        $transition = EVC_Pmpro_Fixture::utc('2027-03-28T01:00:00Z');
        $s = EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row('row1', 'active', '2027-03-28 03:30:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2027-02-28 03:30:00')),
            array(EVC_Pmpro_Fixture::payment('feb', 'confirmed', EVC_Pmpro_Fixture::athens('2027-02-28 03:30'), EVC_Pmpro_Fixture::athens('2027-02-28 03:30'), $transition))
        );
        $this->assertSame('active', self::map($s, $transition->modify('-1 second'))->status());
        $this->assertSame('expired', self::map($s, $transition)->status());
        $this->assertSame('expired', self::map($s, EVC_Pmpro_Fixture::utc('2027-03-28T01:30:00Z'))->status(), 'no PHP-style extra 30 minutes');
        // A source recording the PHP-normalised end (04:30 = 01:30Z) is rejected.
        $s->payments[0]->period_end_utc = EVC_Pmpro_Fixture::utc('2027-03-28T01:30:00Z');
        $this->assertSame('indeterminate', self::map($s, $transition->modify('-1 hour'))->status());
    }

    public function test_W_end_in_the_autumn_repeat_uses_the_first_occurrence(): void {
        $first = EVC_Pmpro_Fixture::utc('2026-10-25T00:30:00Z');
        $s = EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row('row1', 'active', '2026-10-25 03:30:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2026-09-25 03:30:00')),
            array(EVC_Pmpro_Fixture::payment('sep', 'confirmed', EVC_Pmpro_Fixture::athens('2026-09-25 03:30'), EVC_Pmpro_Fixture::athens('2026-09-25 03:30'), $first))
        );
        $this->assertSame('active', self::map($s, $first->modify('-1 second'))->status());
        $this->assertSame('expired', self::map($s, $first)->status());
        $this->assertSame('expired', self::map($s, EVC_Pmpro_Fixture::utc('2026-10-25T01:15:00Z'))->status(), 'second 03:30 is never used');
    }

    // ---- X: source, version, timezone, user ----------------------------------

    /** @return array<string,array{array<string,mixed>,string,string}> */
    public static function unsupported_sources(): array {
        return array(
            'unknown contract version' => array(array('contract_version' => '2'), 'indeterminate', 'unsupported_contract'),
            'PMPro not available' => array(array('source_available' => false), 'indeterminate', 'source_unavailable'),
            'PMPro version unknown' => array(array('pmpro_version' => null), 'indeterminate', 'unsupported_source_version'),
            'PMPro version too old' => array(array('pmpro_version' => '2.12.10'), 'indeterminate', 'unsupported_source_version'),
            'PMPro version too new' => array(array('pmpro_version' => '4.0'), 'indeterminate', 'unsupported_source_version'),
            'PMPro version garbage' => array(array('pmpro_version' => '3.x-dev'), 'indeterminate', 'unsupported_source_version'),
            'timezone empty (WordPress offset mode)' => array(array('site_timezone' => ''), 'indeterminate', 'non_athens_timezone'),
            'timezone fixed offset UTC+3' => array(array('site_timezone' => 'UTC+3'), 'indeterminate', 'non_athens_timezone'),
            'timezone fixed offset +03:00' => array(array('site_timezone' => '+03:00'), 'indeterminate', 'non_athens_timezone'),
            'timezone UTC' => array(array('site_timezone' => 'UTC'), 'indeterminate', 'non_athens_timezone'),
            'timezone other named zone' => array(array('site_timezone' => 'Europe/Istanbul'), 'indeterminate', 'non_athens_timezone'),
            'timezone unknown' => array(array('site_timezone' => null), 'indeterminate', 'non_athens_timezone'),
            'unknown WordPress user' => array(array('user_exists' => false), 'none', 'unknown_user'),
            'tampered snapshot' => array(array('memberships' => array('not a row')), 'indeterminate', 'invalid_snapshot'),
        );
    }

    /**
     * @dataProvider unsupported_sources
     * @param array<string,mixed> $override
     */
    public function test_X_unsupported_or_missing_source_data(array $override, string $status, string $flag): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        foreach ($override as $property => $value) {
            $s->$property = $value;
        }
        $at = self::when('2026-10-20 09:00');
        $this->assertIneligible(self::map($s, $at), $status, $flag, $at);
    }

    public function test_X_sandbox_or_unknown_environment_payment(): void {
        $at = self::when('2026-10-20 09:00');
        foreach (array('sandbox', 'test', '', 'LIVE') as $env) {
            $s = EVC_Pmpro_Fixture::single_paid_month();
            $s->payments[0]->environment = $env;
            $this->assertIneligible(self::map($s, $at), 'indeterminate', 'non_live_payment', $at);
        }
    }

    public function test_X_malformed_payment_facts(): void {
        $at = self::when('2026-10-20 09:00');
        $cases = array(
            array('payment_ref' => '1001'),
            array('kind' => 'fluentcrm_tag'),
            array('status' => 'completed'),
            array('confirmed_at_utc' => null),
        );
        foreach ($cases as $override) {
            $s = EVC_Pmpro_Fixture::single_paid_month();
            foreach ($override as $property => $value) {
                $s->payments[0]->$property = $value;
            }
            $this->assertIneligible(self::map($s, $at), 'indeterminate', 'invalid_payment_fact', $at);
        }
    }

    public function test_X_payment_confirmed_after_the_evaluation_instant(): void {
        $at = self::when('2026-10-15 11:59');
        $this->assertIneligible(self::map(EVC_Pmpro_Fixture::single_paid_month(), $at), 'indeterminate', 'future_dated_payment', $at);
    }

    // ---- Y: exceptions --------------------------------------------------------

    public function test_Y_unexpected_error_inside_the_mapper_is_indeterminate(): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $s->payments[0]->confirmed_at_utc = new class('2026-10-15T09:00:00Z') extends DateTimeImmutable {
            #[\ReturnTypeWillChange]
            public function format($format) {
                throw new RuntimeException('boom');
            }
        };
        $at = self::when('2026-10-20 09:00');
        $this->assertIneligible(self::map($s, $at), 'indeterminate', 'mapper_error', $at);
    }

    public function test_Y_reader_failure_is_rethrown_without_detail(): void {
        $adapter = new EVC_Pmpro_Membership_Adapter(new EVC_Fake_Pmpro_Reader(new RuntimeException('SQLSTATE[HY000] secret detail')), EVC_Pmpro_Fixture::mapper());
        try {
            $adapter->entitlement_for(42, self::when('2026-10-20 09:00'));
            $this->fail('reader failure must not produce an entitlement');
        } catch (EVC_Membership_Source_Exception $e) {
            $this->assertSame('Membership source unavailable.', $e->getMessage());
            $this->assertNull($e->getPrevious(), 'underlying detail is not chained');
        }
    }

    public function test_adapter_passes_the_user_and_instant_through(): void {
        $reader = new EVC_Fake_Pmpro_Reader(EVC_Pmpro_Fixture::single_paid_month());
        $adapter = new EVC_Pmpro_Membership_Adapter($reader, EVC_Pmpro_Fixture::mapper());
        $this->assertInstanceOf(EVC_Membership_Adapter::class, $adapter);
        $this->assertSame('active', $adapter->entitlement_for(42, self::when('2026-10-20 09:00'))->status());
        $this->assertSame('expired', $adapter->entitlement_for(42, self::when('2026-11-15 12:00'))->status());
        $this->assertSame(array(42, 42), $reader->calls);
    }

    public function test_mapper_config_has_no_permissive_defaults(): void {
        foreach (array(array(array(), '3.0', '4.0'), array(array(0), '3.0', '4.0'), array(array('7'), '3.0', '4.0'), array(array(7), '4.0', '3.0'), array(array(7), 'x', '4.0')) as $args) {
            try {
                new EVC_Pmpro_Mapper_Config(...$args);
                $this->fail('accepted ' . json_encode($args));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_mapper_never_reads_fluentcrm_or_newsletter_state(): void {
        // There is no such input in the contract: a snapshot without paid
        // evidence is never eligible whatever else is true about the person.
        $at = self::when('2026-10-20 09:00');
        $s = EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array());
        $this->assertFalse(self::map($s, $at)->is_eligible_at($at));
        $this->assertFalse(property_exists(EVC_Pmpro_Snapshot::class, 'tags'));
    }
}
