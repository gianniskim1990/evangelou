<?php

/**
 * Task 1D-G: real multi-process races against EVC_Sql_Provenance_Store on a
 * real MySQL/MariaDB server. Each worker is a separate PHP process with its
 * own connection, released by a shared time barrier (same approach as
 * ConcurrencyTest). In-memory simulations are NOT accepted as evidence here.
 * Assertions are on the committed database state, not only on outcomes.
 *
 * Tunables: EVC_CONCURRENCY_ROUNDS (default 25; halved here), EVC_CONCURRENCY_WORKERS (default 8).
 *
 * @group concurrency
 */
final class SqlProvenanceConcurrencyTest extends EVC_Db_Test_Case {
    private static function rounds(): int {
        $v = getenv('EVC_CONCURRENCY_ROUNDS');
        return max(2, (int) ceil(($v === false ? 25 : max(1, (int) $v)) / 2));
    }

    private static function workers(): int {
        $v = getenv('EVC_CONCURRENCY_WORKERS');
        return $v === false ? 8 : max(4, (int) $v);
    }

    private static function ref(string $label): string {
        return EVC_Provenance_Test_Fixture::ref($label);
    }

    /** @return array<int,array{outcome:string,reason:?string}> */
    private function run_workers(array $jobs): array {
        $start_at = microtime(true) + 1.5;
        $running = array();
        foreach ($jobs as $job) {
            $job['database'] = $this->database->name();
            $job['start_at'] = $start_at;
            $proc = proc_open(
                array(PHP_BINARY, dirname(__DIR__) . '/bin/provenance-worker.php', json_encode($job)),
                array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
                $pipes
            );
            $this->assertIsResource($proc, 'worker process started');
            $running[] = array($proc, $pipes);
        }
        $results = array();
        foreach ($running as $entry) {
            list($proc, $pipes) = $entry;
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), 'worker exit code; stderr: ' . $err);
            $decoded = json_decode(trim((string) $out), true);
            $this->assertIsArray($decoded, 'worker output: ' . $out . ' / ' . $err);
            $this->assertNotSame('worker_connect_failed', $decoded['outcome']);
            $results[] = $decoded;
        }
        return $results;
    }

    private static function tally(array $results): array {
        $counts = array();
        foreach ($results as $r) {
            $key = $r['outcome'] . ($r['reason'] === null ? '' : '/' . $r['reason']);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    private static function count_of(array $results, string $outcome): int {
        return count(array_filter($results, function ($r) use ($outcome) {
            return $r['outcome'] === $outcome;
        }));
    }

    private function store(): EVC_Sql_Provenance_Store {
        return EVC_Provenance_Test_Fixture::sql_store($this->database->config());
    }

    private function evidence(string $name, array $totals, string $extra): void {
        ksort($totals);
        fwrite(STDERR, sprintf("\n[evidence] provenance-concurrency/%s: rounds=%d workers=%d outcomes=%s %s\n", $name, self::rounds(), self::workers(), json_encode($totals), $extra));
    }

    private static function add(array &$totals, array $results): void {
        foreach (self::tally($results) as $k => $n) {
            $totals[$k] = ($totals[$k] ?? 0) + $n;
        }
    }

    private function assertChainsAreLinear(): void {
        $this->assertSame(0, $this->count_rows('(SELECT predecessor_movement_id FROM evc_pay_periods WHERE predecessor_movement_id IS NOT NULL GROUP BY predecessor_movement_id HAVING COUNT(*) > 1) d'), 'no forked chain');
        $this->assertSame(0, $this->count_rows('(SELECT wp_user_id FROM evc_pay_periods WHERE predecessor_movement_id IS NULL GROUP BY wp_user_id HAVING COUNT(*) > 1) d'), 'one initial period per member');
        $this->assertSame(0, $this->count_rows(
            'evc_pay_member_heads h LEFT JOIN evc_pay_periods p ON p.movement_id = h.latest_movement_id LEFT JOIN evc_pay_periods s ON s.predecessor_movement_id = h.latest_movement_id',
            'h.latest_movement_id IS NOT NULL AND (p.movement_id IS NULL OR s.movement_id IS NOT NULL)'
        ), 'head is the chain tip');
        $this->assertSame(0, $this->count_rows(
            'evc_pay_periods p LEFT JOIN evc_pay_member_heads h ON h.wp_user_id = p.wp_user_id',
            'h.wp_user_id IS NULL'
        ), 'every member with periods has a head');
    }

    public function test_competing_renewals_extend_the_chain_exactly_once(): void {
        $totals = array();
        for ($round = 0; $round < self::rounds(); $round++) {
            $user = 8000 + $round;
            EVC_Provenance_Test_Fixture::recorder($this->store())->record(EVC_Provenance_Test_Fixture::movement("base-$round", '2026-10-15 12:00:00', array('user' => $user)));
            $jobs = array();
            for ($w = 0; $w < self::workers(); $w++) {
                $jobs[] = array('op' => 'record', 'label' => "ren-$round-$w", 'at' => '2026-11-01 09:00:00', 'user' => $user);
            }
            $results = $this->run_workers($jobs);
            self::add($totals, $results);
            $this->assertSame(1, self::count_of($results, 'newly_recorded'), "round $round: " . json_encode(self::tally($results)));
            $this->assertSame(0, self::count_of($results, 'unavailable'));
            $this->assertSame(2, $this->count_rows('evc_pay_periods', 'wp_user_id = ?', array($user)));
            $this->assertSame(2, $this->count_rows('evc_pay_movements', 'wp_user_id = ?', array($user)));
        }
        $this->assertChainsAreLinear();
        $this->evidence('renewals', $totals, 'periods=' . $this->count_rows('evc_pay_periods'));
    }

    public function test_duplicate_callbacks_and_cross_source_aliases_fund_one_period(): void {
        $totals = array();
        for ($round = 0; $round < self::rounds(); $round++) {
            $jobs = array();
            for ($w = 0; $w < self::workers(); $w++) {
                // Same canonical movement; half carry only the order, half order + Viva transaction.
                $aliases = array(array('wc_order', "order:dup-$round"));
                if ($w % 2 === 1) {
                    $aliases[] = array('viva_transaction', "viva:dup-$round");
                }
                $jobs[] = array('op' => 'record', 'label' => "dup-$round", 'at' => '2026-10-15 12:00:00', 'user' => 8100 + $round, 'aliases' => $aliases);
            }
            $results = $this->run_workers($jobs);
            self::add($totals, $results);
            $this->assertSame(1, self::count_of($results, 'newly_recorded'), "round $round: " . json_encode(self::tally($results)));
            $this->assertSame(0, self::count_of($results, 'unavailable'));
            $this->assertSame(1, $this->count_rows('evc_pay_periods', 'wp_user_id = ?', array(8100 + $round)));
        }
        $this->assertChainsAreLinear();
        $this->evidence('duplicates', $totals, 'periods=' . $this->count_rows('evc_pay_periods'));
    }

    public function test_alias_ownership_race_has_exactly_one_owner(): void {
        $totals = array();
        for ($round = 0; $round < self::rounds(); $round++) {
            $jobs = array();
            for ($w = 0; $w < self::workers(); $w++) {
                // Different members and canonical ids claiming the SAME order anchor.
                $jobs[] = array('op' => 'record', 'label' => "claim-$round-$w", 'at' => '2026-10-15 12:00:00', 'user' => 9000 + $round * 100 + $w,
                    'aliases' => array(array('wc_order', "order:shared-$round"), array('viva_transaction', "viva:claim-$round-$w")));
            }
            $results = $this->run_workers($jobs);
            self::add($totals, $results);
            $this->assertSame(1, self::count_of($results, 'newly_recorded'), "round $round: " . json_encode(self::tally($results)));
            $this->assertSame(1, $this->count_rows('evc_pay_aliases', 'alias_namespace = ? AND alias_ref = ?', array('wc_order', self::ref("order:shared-$round"))));
        }
        $this->assertSame(self::rounds(), $this->count_rows('evc_pay_movements'), 'one movement per contested order');
        $this->assertSame(self::rounds(), $this->count_rows('evc_pay_periods'));
        $this->evidence('alias-ownership', $totals, 'movements=' . $this->count_rows('evc_pay_movements'));
    }

    public function test_concurrent_corrections_form_one_forward_only_history(): void {
        $totals = array();
        $kinds = array('refunded', 'partially_refunded', 'reversed', 'partially_refunded', 'voided', 'refunded', 'partially_refunded', 'reversed');
        for ($round = 0; $round < self::rounds(); $round++) {
            EVC_Provenance_Test_Fixture::recorder($this->store())->record(EVC_Provenance_Test_Fixture::movement("corr-$round", '2026-10-15 12:00:00', array('user' => 8200 + $round)));
            $jobs = array();
            for ($w = 0; $w < self::workers(); $w++) {
                $jobs[] = array('op' => 'correct', 'label' => "c-$round-$w", 'movement' => "corr-$round", 'kind' => $kinds[$w % count($kinds)]);
            }
            $results = $this->run_workers($jobs);
            self::add($totals, $results);
            $this->assertGreaterThanOrEqual(1, self::count_of($results, 'newly_recorded'));
            $this->assertSame(0, self::count_of($results, 'unavailable'));
            $history = $this->store()->corrections_for(self::ref("mv:corr-$round"));
            $this->assertSame(self::count_of($results, 'newly_recorded'), count($history), 'every success persisted, nothing else');
            $previous = null;
            foreach ($history as $c) {
                $this->assertTrue(EVC_Movement_Correction::allowed_after($previous, $c->kind()), "round $round: illegal stored transition " . (string) $previous . ' -> ' . $c->kind());
                $previous = $c->kind();
            }
            $this->assertLessThanOrEqual(2, count($history), 'at most partial -> final');
        }
        $this->evidence('corrections', $totals, 'corrections=' . $this->count_rows('evc_pay_corrections'));
    }

    public function test_refund_and_payment_race_never_leaves_a_clean_paid_period(): void {
        $totals = array();
        $branches = array();
        for ($round = 0; $round < self::rounds(); $round++) {
            $jobs = array();
            for ($w = 0; $w < self::workers(); $w++) {
                $jobs[] = $w % 2 === 0
                    ? array('op' => 'record', 'label' => "race-$round", 'at' => '2026-10-15 12:00:00', 'user' => 8300 + $round, 'delay_ms' => $round % 2 === 0 ? 0 : 60)
                    : array('op' => 'correct', 'label' => "refund-$round", 'movement' => "race-$round", 'kind' => 'refunded', 'delay_ms' => $round % 2 === 0 ? 60 : 0);
            }
            $results = $this->run_workers($jobs);
            self::add($totals, $results);
            $outcomes = array_map(function ($r) { return $r['outcome']; }, $results);
            $paid = in_array('newly_recorded', array_map(function ($r, $j) { return $j['op'] === 'record' ? $r['outcome'] : null; }, $results, $jobs), true);
            $quarantined = in_array('quarantined', $outcomes, true);
            $this->assertFalse($paid && $quarantined, "round $round: payment recorded although its refund was quarantined first");
            $recorded = $this->count_rows('evc_pay_movements', 'movement_id = ?', array(self::ref("mv:race-$round"))) === 1;
            $this->assertSame(1, $this->count_rows('evc_pay_corrections', 'movement_id = ?', array(self::ref("mv:race-$round"))), 'the refund is never lost');
            $this->assertSame($quarantined, !$recorded, "round $round: quarantined <=> payment blocked");
            $branches[$recorded ? 'payment_first' : 'refund_first'] = ($branches[$recorded ? 'payment_first' : 'refund_first'] ?? 0) + 1;
            $this->assertSame(0, self::count_of($results, 'unavailable'));
        }
        $this->assertChainsAreLinear();
        $this->assertCount(2, $branches, 'both orderings exercised: ' . json_encode($branches));
        $this->evidence('refund-payment', $totals, 'branches=' . json_encode($branches) . ' movements=' . $this->count_rows('evc_pay_movements') . ' unresolved=' . count($this->store()->unresolved_corrections()));
    }

    public function test_reconciliation_racing_a_new_correction_loses_nothing(): void {
        $totals = array();
        $branches = array();
        for ($round = 0; $round < self::rounds(); $round++) {
            EVC_Provenance_Test_Fixture::recorder($this->store())->correct(EVC_Provenance_Test_Fixture::correction("p-$round", "rec-$round", 'partially_refunded'));
            $jobs = array();
            for ($w = 0; $w < self::workers(); $w++) {
                $jobs[] = $w % 2 === 0
                    ? array('op' => 'reconcile', 'label' => "rec-$round", 'at' => '2026-10-15 12:00:00', 'user' => 8400 + $round, 'ack' => array("corr:p-$round"), 'delay_ms' => $round % 2 === 0 ? 0 : 60)
                    : array('op' => 'correct', 'label' => "f-$round", 'movement' => "rec-$round", 'kind' => 'refunded', 'delay_ms' => $round % 2 === 0 ? 60 : 0);
            }
            $results = $this->run_workers($jobs);
            self::add($totals, $results);
            $reconciled = false;
            $final_quarantined = false;
            foreach ($results as $i => $r) {
                if ($jobs[$i]['op'] === 'reconcile' && $r['outcome'] === 'newly_recorded') {
                    $reconciled = true;
                }
                if ($jobs[$i]['op'] === 'correct' && $r['outcome'] === 'quarantined') {
                    $final_quarantined = true;
                }
            }
            $this->assertFalse($reconciled && $final_quarantined, "round $round: reconciliation ignored a correction held before it");
            $ids = array_map(function ($c) { return $c->correction_id(); }, $this->store()->corrections_for(self::ref("mv:rec-$round")));
            $this->assertSame(array(self::ref("corr:p-$round"), self::ref("corr:f-$round")), $ids, 'the concurrent refund is never lost or reordered');
            $recorded = $this->count_rows('evc_pay_movements', 'movement_id = ?', array(self::ref("mv:rec-$round"))) === 1;
            $this->assertSame($reconciled, $recorded);
            $branches[$recorded ? 'reconcile_first' : 'correction_first'] = ($branches[$recorded ? 'reconcile_first' : 'correction_first'] ?? 0) + 1;
            $this->assertSame(0, self::count_of($results, 'unavailable'));
        }
        $this->assertCount(2, $branches, 'both orderings exercised: ' . json_encode($branches));
        $this->evidence('reconciliation', $totals, 'branches=' . json_encode($branches) . ' movements=' . $this->count_rows('evc_pay_movements') . ' unresolved=' . count($this->store()->unresolved_corrections()));
    }
}
