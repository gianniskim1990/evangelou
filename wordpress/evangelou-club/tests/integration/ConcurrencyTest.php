<?php

/**
 * Real multi-process races against a real MySQL/MariaDB server. Each worker
 * is a separate PHP process with its own connection, released by a shared
 * time barrier. Mocked tests are NOT accepted as concurrency evidence.
 *
 * Tunables: EVC_CONCURRENCY_ROUNDS (default 25), EVC_CONCURRENCY_WORKERS (default 8).
 *
 * @group concurrency
 */
final class ConcurrencyTest extends EVC_Db_Test_Case {
    const NOW = '2026-10-09T10:00:00Z';

    private static function rounds(): int {
        $v = getenv('EVC_CONCURRENCY_ROUNDS');
        return $v === false ? 25 : max(1, (int) $v);
    }

    private static function workers(): int {
        $v = getenv('EVC_CONCURRENCY_WORKERS');
        return $v === false ? 8 : max(2, (int) $v);
    }

    /** @return array<int,array> Decoded worker outputs. */
    private function run_workers(array $jobs): array {
        $start_at = microtime(true) + 1.2;
        $running = array();
        foreach ($jobs as $job) {
            $job['database'] = $this->database->name();
            $job['start_at'] = $start_at;
            $job['now'] = self::NOW;
            $proc = proc_open(
                array(PHP_BINARY, dirname(__DIR__) . '/bin/concurrency-worker.php', json_encode($job)),
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
            $code = proc_close($proc);
            $this->assertSame(0, $code, 'worker exit code; stderr: ' . $err);
            $decoded = json_decode(trim((string) $out), true);
            $this->assertIsArray($decoded, 'worker output: ' . $out . ' / ' . $err);
            $results[] = $decoded;
        }
        return $results;
    }

    private function tally(array $results): array {
        $counts = array();
        foreach ($results as $r) {
            $counts[$r['outcome']] = (isset($counts[$r['outcome']]) ? $counts[$r['outcome']] : 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    public function test_simultaneous_different_requests_yield_exactly_one_redemption(): void {
        $rounds = self::rounds();
        $workers = self::workers();
        $codes = self::catalog()->codes();
        $totals = array();

        for ($round = 0; $round < $rounds; $round++) {
            $wp_user_id = 6000 + $round;
            $member = $this->create_member($wp_user_id);
            $jobs = array();
            for ($w = 0; $w < $workers; $w++) {
                $jobs[] = array(
                    'member' => $member,
                    'wp_user_id' => $wp_user_id,
                    'coffee' => $codes[$w % count($codes)],
                    'request_id' => self::uuid4(),
                    'staff' => 700 + $w,
                );
            }
            $counts = $this->tally($this->run_workers($jobs));
            $this->assertSame(
                array('benefit_already_redeemed' => $workers - 1, 'redeemed' => 1),
                $counts,
                "round $round outcomes"
            );
            $member_id = (int) $this->db->fetch_one('SELECT id FROM evc_members WHERE member_public_id = ?', array($member))['id'];
            $this->assertSame(1, $this->count_rows('evc_redemptions', 'member_id = ?', array($member_id)), "round $round ledger rows");
            $this->assertSame(1, $this->count_rows('evc_audit_events', 'member_id = ? AND outcome = ?', array($member_id, 'redeemed')));
            foreach ($counts as $outcome => $n) {
                $totals[$outcome] = (isset($totals[$outcome]) ? $totals[$outcome] : 0) + $n;
            }
        }

        $this->assertSame($rounds, $this->count_rows('evc_redemptions'));
        $this->assertSame(0, $this->count_rows(
            '(SELECT member_id FROM evc_redemptions GROUP BY member_id, benefit_type, business_date HAVING COUNT(*) > 1) d'
        ), 'no duplicate (member, benefit, day) groups');
        fwrite(STDERR, sprintf(
            "\n[evidence] concurrency/different-requests: rounds=%d workers=%d attempts=%d outcomes=%s ledger_rows=%d\n",
            $rounds, $workers, $rounds * $workers, json_encode($totals), $this->count_rows('evc_redemptions')
        ));
    }

    public function test_simultaneous_retries_of_one_request_yield_one_row_and_replays(): void {
        $rounds = max(1, (int) ceil(self::rounds() / 2));
        $workers = self::workers();
        $totals = array();

        for ($round = 0; $round < $rounds; $round++) {
            $wp_user_id = 7000 + $round;
            $member = $this->create_member($wp_user_id);
            $request_id = self::uuid4();
            $jobs = array_fill(0, $workers, array(
                'member' => $member,
                'wp_user_id' => $wp_user_id,
                'coffee' => 'espresso',
                'request_id' => $request_id,
                'staff' => 701,
            ));
            $results = $this->run_workers($jobs);
            $counts = $this->tally($results);
            $this->assertSame(array('redeemed' => 1, 'replayed' => $workers - 1), $counts, "round $round outcomes");
            $first = $results[0]['redemption'];
            foreach ($results as $r) {
                $this->assertSame($first, $r['redemption'], 'every replay returns the same original result');
            }
            $this->assertSame(1, $this->count_rows('evc_redemptions', 'request_id = ?', array($request_id)));
            foreach ($counts as $outcome => $n) {
                $totals[$outcome] = (isset($totals[$outcome]) ? $totals[$outcome] : 0) + $n;
            }
        }

        $this->assertSame($rounds, $this->count_rows('evc_redemptions'));
        fwrite(STDERR, sprintf(
            "[evidence] concurrency/same-request: rounds=%d workers=%d attempts=%d outcomes=%s ledger_rows=%d\n",
            $rounds, $workers, $rounds * $workers, json_encode($totals), $this->count_rows('evc_redemptions')
        ));
    }
}
