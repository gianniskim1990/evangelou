<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Deterministic, pure decision: normalised PMPro/WooCommerce facts
 * (EVC_Pmpro_Snapshot) + an evaluation instant -> EVC_Entitlement.
 * Task 1D-B. NOT wired to production redemption.
 *
 * Only "active" is ever eligible, and only when ALL of these hold:
 *  1. the snapshot contract, PMPro version and named site timezone
 *     (Europe/Athens) are supported, the user exists and the reader reports
 *     no cross-system conflict;
 *  2. exactly one ACTIVE row on an approved Club level, with valid start and
 *     end dates (start < end) and no cancellation of unknown effect; every
 *     row reference identifies exactly one row;
 *  3. every Club payment fact is live, well-formed and de-duplicable; one
 *     underlying payment reference always carries the same de-duplication key
 *     and identical facts, and repeated notifications of one money movement
 *     are identical (else conflict); each payment funds a row of the SAME
 *     level as the payment;
 *  4. replaying the CONFIRMED payments in confirmation order with the
 *     owner-approved rules (EVC_Membership_Calendar: D1 exact Athens time,
 *     D2 early/late renewal, D5 clamped calendar month) reproduces EXACTLY
 *     the paid period each payment's recorded provenance claims, and the
 *     last period ends exactly where the active PMPro row ends;
 *  5. no payment in the current continuous paid run was refunded, partly
 *     refunded or reversed, and all of them are for the active row's level;
 *  6. the active row's PMPro start lies inside the current continuous paid
 *     run (run start <= row start < row end) and has been reached. PMPro may
 *     keep an older start across early renewals, so the row start is NOT
 *     required to equal the latest renewal, but it may never claim time
 *     before the paid run began;
 *  7. evaluation instant < end of the last paid period (exclusive).
 *
 * Consequences: an old payment cannot justify a later unpaid period (the
 * replayed chain would end before the PMPro end date -> mismatch); a pending
 * renewal neither extends nor invalidates a paid period; a confirmed renewal
 * only counts with its own recorded provenance. PMPro's "active" status and
 * its expiry cron are never trusted on their own.
 *
 * Everything else returns a NON-eligible entitlement (indeterminate, none,
 * pending_payment, payment_failed, refunded, cancelled or expired). Any
 * unexpected error inside the mapper returns indeterminate. Diagnostic flags
 * are internal and never part of a public response.
 */
final class EVC_Pmpro_Entitlement_Mapper {
    const SOURCE = 'pmpro';
    const BUSINESS_TIMEZONE = 'Europe/Athens';
    const REF_PATTERN = '/^[0-9a-f]{64}$/D';
    const LOCAL_DATETIME_PATTERN = '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/D';
    /** Dates before this are PMPro "zero"/"magic" placeholders, not real ends. */
    const MIN_REAL_YEAR = 2000;

    const MEMBERSHIP_STATUSES = array('active', 'expired', 'cancelled', 'admin_cancelled', 'changed', 'admin_changed', 'inactive');

    /** @var EVC_Pmpro_Mapper_Config */
    private $config;

    public function __construct(EVC_Pmpro_Mapper_Config $config) {
        $this->config = $config;
    }

    public function map(EVC_Pmpro_Snapshot $snapshot, DateTimeImmutable $at): EVC_Entitlement {
        try {
            return $this->decide($snapshot, EVC_Clock::to_utc($at));
        } catch (Throwable $e) {
            return EVC_Entitlement::indeterminate(self::SOURCE, array('mapper_error'));
        }
    }

    private function decide(EVC_Pmpro_Snapshot $s, DateTimeImmutable $at): EVC_Entitlement {
        if ($s->contract_version !== EVC_Pmpro_Snapshot::CONTRACT_VERSION) {
            return $this->indeterminate('unsupported_contract');
        }
        if (!$s->source_available) {
            return $this->indeterminate('source_unavailable');
        }
        if (!$this->config->supports_pmpro_version($s->pmpro_version)) {
            return $this->indeterminate('unsupported_source_version');
        }
        if ($s->site_timezone !== self::BUSINESS_TIMEZONE) {
            return $this->indeterminate('non_athens_timezone');
        }
        if (!$s->user_exists) {
            return EVC_Entitlement::none(self::SOURCE, array('unknown_user'));
        }
        if (!is_array($s->memberships) || !is_array($s->payments) || !is_array($s->source_conflicts)) {
            return $this->indeterminate('invalid_snapshot');
        }
        foreach ($s->memberships as $row) {
            if (!$row instanceof EVC_Pmpro_Membership_Row || !is_string($row->row_ref) || !is_int($row->level_id) || !is_string($row->status)) {
                return $this->indeterminate('invalid_snapshot');
            }
        }
        foreach ($s->payments as $p) {
            if (!$p instanceof EVC_Pmpro_Payment_Fact || !is_string($p->payment_ref) || !is_int($p->level_id) || !is_string($p->status)
                || !is_string($p->kind) || !is_string($p->environment)) {
                return $this->indeterminate('invalid_snapshot');
            }
        }
        if ($s->source_conflicts !== array()) {
            return $this->indeterminate('source_conflict');
        }

        // ---- Identity: one row per row reference, one payment per payment reference
        $rows_by_ref = array();
        foreach ($s->memberships as $row) {
            if (isset($rows_by_ref[$row->row_ref])) {
                return $this->indeterminate('membership_row_identity_conflict');
            }
            $rows_by_ref[$row->row_ref] = $row;
        }
        $payments_by_ref = array();
        foreach ($s->payments as $p) {
            if (isset($payments_by_ref[$p->payment_ref])) {
                $first = $payments_by_ref[$p->payment_ref];
                if ($first->dedupe_key !== $p->dedupe_key || !$this->same_payment($first, $p)) {
                    return $this->indeterminate('payment_identity_conflict');
                }
                continue;
            }
            $payments_by_ref[$p->payment_ref] = $p;
        }

        // ---- Membership rows -------------------------------------------
        $club_rows = array();
        foreach ($s->memberships as $row) {
            if (!preg_match(self::REF_PATTERN, $row->row_ref)) {
                return $this->indeterminate('invalid_membership_row');
            }
            if (!in_array($row->status, self::MEMBERSHIP_STATUSES, true)) {
                return $this->indeterminate('unknown_membership_status');
            }
            if ($this->config->is_club_level($row->level_id)) {
                $club_rows[] = $row;
            }
        }
        if ($club_rows === array()) {
            return EVC_Entitlement::none(self::SOURCE, array($s->memberships === array() ? 'no_club_membership' : 'non_club_level_only'));
        }
        $active = array_values(array_filter($club_rows, function (EVC_Pmpro_Membership_Row $r) {
            return $r->status === 'active';
        }));
        if (count($active) > 1) {
            return $this->indeterminate('multiple_active_club_levels');
        }
        if ($active === array()) {
            $statuses = array_map(function (EVC_Pmpro_Membership_Row $r) {
                return $r->status;
            }, $club_rows);
            if (array_intersect($statuses, array('cancelled', 'admin_cancelled'))) {
                return new EVC_Entitlement(EVC_Entitlement::STATUS_CANCELLED, false, null, null, self::SOURCE, null, null, array('membership_cancelled'));
            }
            if (in_array('expired', $statuses, true)) {
                return new EVC_Entitlement(EVC_Entitlement::STATUS_EXPIRED, false, null, null, self::SOURCE, null, null, array('membership_expired'));
            }
            return EVC_Entitlement::none(self::SOURCE, array('no_active_club_membership'));
        }
        $row = $active[0];
        if ($row->cancellation_pending) {
            return $this->indeterminate('cancellation_effective_time_unknown');
        }
        $row_end = $this->local_instant($row->enddate_local, 'expiry_missing', EVC_Membership_Calendar::EARLIEST);
        if (is_string($row_end)) {
            return $this->indeterminate($row_end);
        }
        $row_start = $this->local_instant($row->startdate_local, 'start_missing', EVC_Membership_Calendar::LATEST);
        if (is_string($row_start)) {
            return $this->indeterminate($row_start);
        }
        if ($row_start >= $row_end) {
            return $this->indeterminate('membership_start_after_end');
        }
        $level_ref = 'pmpro:' . $row->level_id;
        $club_rows_by_ref = array();
        foreach ($club_rows as $club_row) {
            $club_rows_by_ref[$club_row->row_ref] = $club_row;
        }

        // ---- Payment facts (approved Club levels only) -------------------
        $confirmed = array();
        $pending = false;
        $failed = false;
        foreach ($s->payments as $p) {
            if (!$this->config->is_club_level($p->level_id)) {
                continue;
            }
            if (!preg_match(self::REF_PATTERN, $p->payment_ref)
                || !in_array($p->kind, EVC_Payment_Evidence::kinds(), true)
                || !in_array($p->status, EVC_Pmpro_Payment_Fact::statuses(), true)) {
                return $this->indeterminate('invalid_payment_fact');
            }
            if ($p->environment !== EVC_Pmpro_Payment_Fact::ENV_LIVE) {
                return $this->indeterminate('non_live_payment');
            }
            if ($p->was_confirmed()) {
                if ($p->confirmed_at_utc === null) {
                    return $this->indeterminate('invalid_payment_fact');
                }
                if (EVC_Clock::to_utc($p->confirmed_at_utc) > $at) {
                    return $this->indeterminate('future_dated_payment');
                }
                if ($p->dedupe_key === null || !preg_match(self::REF_PATTERN, $p->dedupe_key)) {
                    return $this->indeterminate('payment_not_deduplicable');
                }
                $confirmed[] = $p;
            } else {
                if ($p->confirmed_at_utc !== null) {
                    return $this->indeterminate('invalid_payment_fact');
                }
                if ($p->status === EVC_Pmpro_Payment_Fact::STATUS_PENDING) {
                    $pending = true;
                } else {
                    $failed = true;
                }
            }
        }
        if ($confirmed === array()) {
            if ($pending) {
                return new EVC_Entitlement(EVC_Entitlement::STATUS_PENDING_PAYMENT, false, null, $level_ref, self::SOURCE, null, null, array('no_payment_evidence', 'renewal_pending'));
            }
            if ($failed) {
                return new EVC_Entitlement(EVC_Entitlement::STATUS_PAYMENT_FAILED, false, null, $level_ref, self::SOURCE, null, null, array('no_payment_evidence'));
            }
            return $this->indeterminate('no_payment_evidence');
        }

        // ---- De-duplicate repeated notifications of the same payment -----
        $flags = array();
        $by_key = array();
        foreach ($confirmed as $p) {
            $by_key[$p->dedupe_key][] = $p;
        }
        $unique = array();
        foreach ($by_key as $group) {
            foreach ($group as $other) {
                if (!$this->same_payment($group[0], $other)) {
                    return $this->indeterminate('conflicting_payment_events');
                }
            }
            if (count($group) > 1) {
                $flags[] = 'duplicate_payment_event_collapsed';
            }
            $unique[] = $group[0];
        }
        usort($unique, function (EVC_Pmpro_Payment_Fact $a, EVC_Pmpro_Payment_Fact $b) {
            return EVC_Clock::to_utc($a->confirmed_at_utc) <=> EVC_Clock::to_utc($b->confirmed_at_utc);
        });
        for ($i = 1; $i < count($unique); $i++) {
            if (EVC_Clock::to_utc($unique[$i]->confirmed_at_utc) == EVC_Clock::to_utc($unique[$i - 1]->confirmed_at_utc)) {
                return $this->indeterminate('ambiguous_payment_order');
            }
        }

        // ---- Replay the approved period rules and check provenance -------
        $periods = array();
        $run_start_index = 0;
        $previous_end = null;
        foreach ($unique as $p) {
            if ($p->membership_row_ref === null || $p->period_start_utc === null || $p->period_end_utc === null
                || !isset($club_rows_by_ref[$p->membership_row_ref])) {
                return $this->indeterminate('payment_period_unlinked');
            }
            if ($club_rows_by_ref[$p->membership_row_ref]->level_id !== $p->level_id) {
                return $this->indeterminate('payment_level_mismatch');
            }
            $expected = EVC_Membership_Calendar::next_period($previous_end, $p->confirmed_at_utc);
            if ($expected['start'] != EVC_Clock::to_utc($p->period_start_utc) || $expected['end'] != EVC_Clock::to_utc($p->period_end_utc)) {
                return $this->indeterminate('payment_period_mismatch');
            }
            if ($expected['renewal'] === 'late') {
                $run_start_index = count($periods);
            }
            $periods[] = array('payment' => $p, 'start' => $expected['start'], 'end' => $expected['end']);
            $previous_end = $expected['end'];
        }
        $current_run = array_slice($periods, $run_start_index);

        foreach ($current_run as $period) {
            if ($period['payment']->status === EVC_Pmpro_Payment_Fact::STATUS_PARTIALLY_REFUNDED) {
                return $this->indeterminate('partial_refund_policy_unresolved');
            }
            if ($period['payment']->status === EVC_Pmpro_Payment_Fact::STATUS_REVERSED) {
                return $this->indeterminate('payment_reversed');
            }
            if ($period['payment']->level_id !== $row->level_id) {
                return $this->indeterminate('level_change_within_paid_run');
            }
        }
        foreach ($current_run as $period) {
            if ($period['payment']->status === EVC_Pmpro_Payment_Fact::STATUS_REFUNDED) {
                return new EVC_Entitlement(EVC_Entitlement::STATUS_REFUNDED, false, null, $level_ref, self::SOURCE, null, null, array('payment_refunded'));
            }
        }

        $last = $periods[count($periods) - 1];
        if ($last['end'] != $row_end || $last['payment']->membership_row_ref !== $row->row_ref) {
            return $this->indeterminate('pmpro_period_mismatch');
        }
        if ($pending) {
            $flags[] = 'renewal_pending';
        }
        $run_start = $current_run[0]['start'];
        if ($row_start < $run_start) {
            return $this->indeterminate('membership_start_before_paid_run');
        }

        if ($at >= $last['end']) {
            $flags[] = 'paid_period_ended';
            return new EVC_Entitlement(EVC_Entitlement::STATUS_EXPIRED, false, $last['end'], $level_ref, self::SOURCE, $run_start, null, $flags);
        }
        if ($at < $row_start) {
            return $this->indeterminate('membership_not_started');
        }
        foreach ($current_run as $period) {
            if ($period['start'] <= $at && $at < $period['end']) {
                $p = $period['payment'];
                $evidence = new EVC_Payment_Evidence($p->kind, $p->payment_ref, EVC_Clock::to_utc($p->confirmed_at_utc), $period['start'], $period['end']);
                return new EVC_Entitlement(EVC_Entitlement::STATUS_ACTIVE, true, $last['end'], $level_ref, self::SOURCE, $run_start, $evidence, $flags);
            }
        }
        return $this->indeterminate('no_covering_period');
    }

    /**
     * PMPro stores site-local wall-clock strings. Returns the instant, or a
     * diagnostic flag when the value is missing, a placeholder or invalid.
     * Ends resolve EARLIEST and starts LATEST (see EVC_Membership_Calendar).
     *
     * @return DateTimeImmutable|string
     */
    private function local_instant(?string $value, string $missing_flag, string $mode) {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return $missing_flag;
        }
        if (!preg_match(self::LOCAL_DATETIME_PATTERN, $value, $m)) {
            return 'invalid_membership_dates';
        }
        if ((int) $m[1] < self::MIN_REAL_YEAR) {
            return $missing_flag;
        }
        try {
            $resolved = EVC_Membership_Calendar::resolve_local_time((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6], 0, $mode);
        } catch (Throwable $e) {
            return 'invalid_membership_dates';
        }
        return $resolved['instant'];
    }

    private function same_payment(EVC_Pmpro_Payment_Fact $a, EVC_Pmpro_Payment_Fact $b): bool {
        return $a->kind === $b->kind
            && $a->level_id === $b->level_id
            && $a->membership_row_ref === $b->membership_row_ref
            && $a->status === $b->status
            && $a->environment === $b->environment
            && self::same_instant($a->confirmed_at_utc, $b->confirmed_at_utc)
            && self::same_instant($a->period_start_utc, $b->period_start_utc)
            && self::same_instant($a->period_end_utc, $b->period_end_utc);
    }

    private static function same_instant(?DateTimeImmutable $a, ?DateTimeImmutable $b): bool {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return EVC_Clock::to_utc($a) == EVC_Clock::to_utc($b);
    }

    private function indeterminate(string $flag): EVC_Entitlement {
        return EVC_Entitlement::indeterminate(self::SOURCE, array($flag));
    }
}
