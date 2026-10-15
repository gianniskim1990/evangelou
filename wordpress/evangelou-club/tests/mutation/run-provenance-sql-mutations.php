<?php
/**
 * Targeted mutation tests for the Task 1D-G SQL payment-provenance store
 * (migration 002 + EVC_Sql_Provenance_Store + the COMMIT-unknown boundary).
 *
 * Same discipline as run-mutations.php: each mutant applies ONE exact source
 * edit (pattern must occur exactly once), runs the provenance store suites
 * against a REAL disposable database and must make them FAIL. Originals are
 * restored in `finally` and verified by SHA-256 at the end.
 *
 * Runs the sequential SQL/contract suites (the multi-process race suite is
 * proven separately in every CI job); requires the EVC_TEST_DB_* environment.
 *
 * Usage (from the plugin directory): php tests/mutation/run-provenance-sql-mutations.php
 */
$root = dirname(__DIR__, 2);
$phpunit = array(PHP_BINARY, $root . '/vendor/bin/phpunit', '--configuration', $root . '/phpunit.xml.dist',
    '--filter', '/(SqlProvenanceStoreTest|SqlProvenanceStoreContractTest|InMemoryProvenanceStoreContractTest)/');

$sql = 'database/002_payment_provenance.sql';
$store = 'includes/class-evc-sql-provenance-store.php';

$mutants = array(
    // ---- schema backstops ---------------------------------------------------
    array('id' => 'S1-chain-may-fork-successor-unique-removed', 'file' => $sql,
        'search' => "  UNIQUE KEY uq_pay_period_successor (predecessor_movement_id),\n", 'replace' => ''),
    array('id' => 'S2-second-initial-period-allowed', 'file' => $sql,
        'search' => "  UNIQUE KEY uq_pay_period_one_initial (first_of_member),\n", 'replace' => ''),
    array('id' => 'S3-period-fk-ignores-pmpro-row', 'file' => $sql,
        'search' => 'FOREIGN KEY (movement_id, wp_user_id, level_id, pmpro_row_ref) REFERENCES evc_pay_movements (movement_id, wp_user_id, level_id, pmpro_row_ref)',
        'replace' => 'FOREIGN KEY (movement_id) REFERENCES evc_pay_movements (movement_id)'),
    array('id' => 'S4-second-order-anchor-allowed', 'file' => $sql,
        'search' => "  UNIQUE KEY uq_pay_alias_one_anchor (anchor_movement_id),\n", 'replace' => ''),
    array('id' => 'S5-correction-sequence-may-fork', 'file' => $sql,
        'search' => "  UNIQUE KEY uq_pay_corr_seq (movement_id, seq),\n", 'replace' => ''),
    array('id' => 'S6-predecessor-of-other-member-allowed', 'file' => $sql,
        'search' => 'FOREIGN KEY (predecessor_movement_id, wp_user_id) REFERENCES evc_pay_periods (movement_id, wp_user_id)',
        'replace' => 'FOREIGN KEY (predecessor_movement_id) REFERENCES evc_pay_periods (movement_id)'),
    // ---- store: locked validation inside the transaction ---------------------
    array('id' => 'S7-member-chain-cas-removed', 'file' => $store,
        'search' => "        if (\$latest !== \$w->expected_predecessor) {", 'replace' => '        if (false) {'),
    array('id' => 'S8-duplicate-movement-guard-removed', 'file' => $store,
        'search' => "        if (\$db->fetch_one('SELECT 1 AS x FROM evc_pay_movements WHERE movement_id = ?', array(\$id)) !== null) {",
        'replace' => '        if (false) {'),
    array('id' => 'S9-refund-first-guard-removed', 'file' => $store,
        'search' => "        if (self::correction_ids(\$db, \$id) !== array_values(\$w->expected_unresolved)) {", 'replace' => '        if (false) {'),
    array('id' => 'S10-calendar-not-rechecked', 'file' => $store,
        'search' => "        if (\$p->start_utc() != \$next['start'] || \$p->end_utc() != \$next['end'] || \$p->kind() !== \$kind) {",
        'replace' => '        if (false) {'),
    array('id' => 'S11-chronology-not-rechecked', 'file' => $store,
        'search' => "        if (\$max !== null && \$max['t'] !== null && self::instant((string) \$max['t']) >= \$m->verified_at_utc()) {",
        'replace' => '        if (false) {'),
    array('id' => 'S12-pmpro-row-not-validated', 'file' => $store,
        'search' => " || \$p->pmpro_row_ref() !== \$m->pmpro_row_ref()) {", 'replace' => ') {'),
    array('id' => 'S13-alias-ownership-bypassed', 'file' => $store,
        'search' => "            if (\$row !== null && (string) \$row['movement_id'] !== \$owner) {", 'replace' => '            if (false) {'),
    array('id' => 'S14-correction-cas-removed', 'file' => $store,
        'search' => "        if (\$prior !== \$w->expected_prior_correction) {", 'replace' => '        if (false) {'),
    array('id' => 'S15-correction-regression-accepted', 'file' => $store,
        'search' => "        if (!EVC_Movement_Correction::allowed_after(\$head['latest_kind'] === null ? null : (string) \$head['latest_kind'], \$c->kind())) {",
        'replace' => '        if (false) {'),
    array('id' => 'S16-recorded-state-not-checked', 'file' => $store,
        'search' => "        if (\$recorded !== \$w->expected_movement_recorded) {", 'replace' => '        if (false) {'),
    array('id' => 'S17-write-shape-not-validated', 'file' => $store,
        'search' => "        \$shape = self::shape_of(\$w);\n",
        'replace' => "        \$shape = \$w->new_movement !== null ? self::SHAPE_NEW_MOVEMENT : (\$w->correction !== null ? self::SHAPE_CORRECTION : self::SHAPE_ALIASES);\n"),
    array('id' => 'S18-write-outside-transaction-partial-persist', 'file' => $store,
        'search' => "                \$this->db->transactional(function (EVC_Club_Db \$db) use (\$w, \$shape) {\n                    \$this->apply(\$db, \$w, \$shape);\n                });\n",
        'replace' => "                \$this->apply(\$this->db, \$w, \$shape);\n"),
    // ---- COMMIT uncertainty ----------------------------------------------------
    array('id' => 'S19-unknown-commit-treated-as-rollback', 'file' => $store,
        'search' => "                \$this->recover(\$w, \$shape);\n                return;\n",
        'replace' => "                throw new EVC_Provenance_Store_Exception('assumed rolled back');\n"),
    array('id' => 'S20-inconclusive-recovery-reported-as-rollback', 'file' => $store,
        'search' => "            throw new EVC_Provenance_Commit_Unknown_Exception('Provenance commit outcome unknown; recovery inconclusive.');",
        'replace' => "            throw new EVC_Provenance_Store_Exception('assumed rolled back');"),
    array('id' => 'S21-recovery-without-locks', 'file' => $store,
        'search' => "            \$db->fetch_one('SELECT wp_user_id FROM evc_pay_member_heads WHERE wp_user_id = ? FOR UPDATE', array(\$m->wp_user_id()));\n",
        'replace' => ''),
    array('id' => 'S22-commit-failure-not-classified-unknown', 'file' => 'includes/class-evc-club-db.php',
        'search' => "            \$this->commit_or_unknown();\n", 'replace' => "            \$this->pdo->commit();\n"),
    array('id' => 'S23-deadlock-not-retried', 'file' => $store,
        'search' => "                if (\$attempt >= self::MAX_ATTEMPTS) {", 'replace' => '                if (true) {'),
);

/** Output of the most recent suite run (printed only when the baseline fails). */
$evc_last_output = '';

function evc_run_suite(array $command): int {
    global $evc_last_output;
    $proc = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($proc)) {
        return 255;
    }
    $evc_last_output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc);
}

$touched = array();
foreach ($mutants as $m) {
    $path = $root . '/' . $m['file'];
    if (!isset($touched[$path])) {
        $touched[$path] = hash_file('sha256', $path);
    }
}

echo "Baseline (unmutated) provenance store run...\n";
$baseline = evc_run_suite($phpunit);
if ($baseline !== 0) {
    fwrite(STDERR, "ABORT: baseline suite is not green (exit $baseline); mutation results would be meaningless.\n");
    fwrite(STDERR, substr($evc_last_output, -6000) . "\n");
    exit(1);
}
echo "Baseline: PASS\n";

$survivors = array();
$killed = 0;
foreach ($mutants as $m) {
    $path = $root . '/' . $m['file'];
    $original = file_get_contents($path);
    // Working copies may use CRLF (Windows); patterns are written with LF.
    $normalized = str_replace("\r\n", "\n", $original);
    $occurrences = substr_count($normalized, $m['search']);
    if ($occurrences !== 1) {
        fwrite(STDERR, sprintf("ABORT: %s pattern found %d times in %s (expected exactly 1).\n", $m['id'], $occurrences, $m['file']));
        exit(1);
    }
    try {
        file_put_contents($path, str_replace($m['search'], $m['replace'], $normalized));
        $exit = evc_run_suite($phpunit);
    } finally {
        file_put_contents($path, $original);
    }
    if ($exit !== 0) {
        $killed++;
    } else {
        $survivors[] = $m['id'];
    }
    printf("%-48s %s (phpunit exit %d)\n", $m['id'], $exit !== 0 ? 'KILLED' : 'SURVIVED', $exit);
}

$restored = true;
foreach ($touched as $path => $hash) {
    if (hash_file('sha256', $path) !== $hash) {
        $restored = false;
        fwrite(STDERR, 'NOT RESTORED: ' . $path . "\n");
    }
}

printf("[evidence] provenance-sql-mutation: total=%d killed=%d survived=%d sources_restored=%s\n", count($mutants), $killed, count($survivors), $restored ? 'yes' : 'NO');
if ($survivors || !$restored) {
    if ($survivors) {
        fwrite(STDERR, 'Surviving mutants: ' . implode(', ', $survivors) . "\n");
    }
    exit(1);
}
exit(0);
